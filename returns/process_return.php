<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";
require_once "../assets/includes/sync_helper.php";

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$user_id     = (int) ($_SESSION["user_id"] ?? 0);

$message = "";
$error   = "";

$sale       = null;
$sale_items = [];


/*
|--------------------------------------------------------------------------
| BASIC SESSION VALIDATION
|--------------------------------------------------------------------------
*/

if ($business_id <= 0 || $user_id <= 0) {
    exit("Invalid session.");
}


/*
|--------------------------------------------------------------------------
| STEP 1: FIND SALE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["find_sale"])) {

    $receipt_number = trim($_POST["receipt_number"] ?? "");

    if ($receipt_number === "") {

        $error = "Please enter a receipt number.";

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                business_id,
                customer_id,
                user_id,
                receipt_number,
                subtotal,
                discount,
                tax,
                total_amount,
                sale_status,
                sale_date
            FROM sales
            WHERE business_id = :business_id
              AND receipt_number = :receipt_number
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id"    => $business_id,
            ":receipt_number" => $receipt_number
        ]);

        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {

            $error = "Receipt not found.";

        } elseif (strtolower((string) $sale["sale_status"]) === "voided") {

            $error = "This sale has been voided and cannot be returned.";
            $sale = null;

        } elseif (strtolower((string) $sale["sale_status"]) === "refunded") {

            $error = "This sale has already been fully refunded.";
            $sale = null;
        }
    }
}


/*
|--------------------------------------------------------------------------
| STEP 2: PROCESS RETURN
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["process_return"])
) {

    $sale_id = (int) ($_POST["sale_id"] ?? 0);

    $reason = trim($_POST["reason"] ?? "");

    $return_quantities = $_POST["return_quantity"] ?? [];


    if ($sale_id <= 0) {

        $error = "Invalid sale.";

    } elseif (
        !is_array($return_quantities)
        || empty($return_quantities)
    ) {

        $error = "Please enter a return quantity for at least one product.";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | START ATOMIC TRANSACTION
            |--------------------------------------------------------------------------
            |
            | Everything below must succeed:
            |
            | return
            | return_items
            | stock update
            | stock movement
            | inventory activity
            | sale status
            | sync queue
            |
            | Otherwise everything is rolled back.
            |
            */

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | GET ORIGINAL SALE
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    business_id,
                    customer_id,
                    receipt_number,
                    subtotal,
                    discount,
                    tax,
                    total_amount,
                    sale_status,
                    sale_date
                FROM sales
                WHERE id = :sale_id
                  AND business_id = :business_id
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                ":sale_id"     => $sale_id,
                ":business_id" => $business_id
            ]);

            $sale = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$sale) {
                throw new Exception("Sale not found.");
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK SALE STATUS
            |--------------------------------------------------------------------------
            */

            $sale_status = strtolower((string) $sale["sale_status"]);

            if ($sale_status === "voided") {

                throw new Exception(
                    "This sale has been voided and cannot be returned."
                );
            }


            if ($sale_status === "refunded") {

                throw new Exception(
                    "This sale has already been fully refunded."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | GET SALE ITEMS
            |--------------------------------------------------------------------------
            |
            | sale_items does NOT contain business_id.
            |
            */

            $stmt = $pdo->prepare("
                SELECT
                    si.id AS sale_item_id,
                    si.product_id,
                    si.quantity AS original_quantity,
                    si.unit_price,
                    si.total,
                    p.name AS product_name
                FROM sale_items si
                INNER JOIN products p
                    ON p.id = si.product_id
                WHERE si.sale_id = :sale_id
                ORDER BY si.id ASC
                FOR UPDATE
            ");

            $stmt->execute([
                ":sale_id" => $sale_id
            ]);

            $all_sale_items = $stmt->fetchAll(PDO::FETCH_ASSOC);


            if (empty($all_sale_items)) {

                throw new Exception(
                    "No products found in this sale."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | GET ALREADY RETURNED QUANTITIES
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    ri.sale_item_id,
                    COALESCE(SUM(ri.quantity), 0) AS already_returned
                FROM return_items ri
                INNER JOIN returns r
                    ON r.id = ri.return_id
                WHERE r.sale_id = :sale_id
                  AND r.business_id = :business_id
                  AND LOWER(COALESCE(r.status, '')) = 'completed'
                GROUP BY ri.sale_item_id
            ");

            $stmt->execute([
                ":sale_id"     => $sale_id,
                ":business_id" => $business_id
            ]);

            $returned_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


            /*
            |--------------------------------------------------------------------------
            | BUILD RETURNED QUANTITY LOOKUP
            |--------------------------------------------------------------------------
            */

            $returned_lookup = [];

            foreach ($returned_rows as $row) {

                $returned_lookup[
                    (int) $row["sale_item_id"]
                ] = (float) $row["already_returned"];
            }


            /*
            |--------------------------------------------------------------------------
            | ATTACH ALREADY RETURNED QUANTITY
            |--------------------------------------------------------------------------
            */

            foreach ($all_sale_items as &$item) {

                $item_id = (int) $item["sale_item_id"];

                $item["already_returned"] =
                    $returned_lookup[$item_id] ?? 0;
            }

            unset($item);


            /*
            |--------------------------------------------------------------------------
            | BUILD ITEM LOOKUP
            |--------------------------------------------------------------------------
            */

            $item_lookup = [];

            foreach ($all_sale_items as $item) {

                $item_lookup[
                    (int) $item["sale_item_id"]
                ] = $item;
            }


            /*
            |--------------------------------------------------------------------------
            | PREPARE RETURN ITEMS
            |--------------------------------------------------------------------------
            */

            $items_to_return = [];

            $refund_amount = 0.00;

            $validation_errors = [];


            foreach ($return_quantities as $sale_item_id => $quantity) {

                $sale_item_id = (int) $sale_item_id;

                $quantity = trim((string) $quantity);


                /*
                |--------------------------------------------------------------------------
                | IGNORE EMPTY / ZERO
                |--------------------------------------------------------------------------
                */

                if ($quantity === "") {
                    continue;
                }

                if (!is_numeric($quantity)) {

                    $validation_errors[] =
                        "Invalid quantity entered.";

                    continue;
                }

                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | CHECK SALE ITEM EXISTS
                |--------------------------------------------------------------------------
                */

                if (!isset($item_lookup[$sale_item_id])) {

                    $validation_errors[] =
                        "Invalid sale item.";

                    continue;
                }


                $item = $item_lookup[$sale_item_id];


                $original_quantity =
                    (float) $item["original_quantity"];

                $already_returned =
                    (float) $item["already_returned"];


                $available_quantity =
                    $original_quantity - $already_returned;


                if ($available_quantity < 0) {
                    $available_quantity = 0;
                }


                /*
                |--------------------------------------------------------------------------
                | ITEM ALREADY FULLY RETURNED
                |--------------------------------------------------------------------------
                */

                if ($available_quantity <= 0.000001) {

                    $validation_errors[] = sprintf(
                        "%s has already been fully returned. Sold: %s, Already returned: %s.",
                        $item["product_name"],
                        rtrim(
                            rtrim(
                                number_format(
                                    $original_quantity,
                                    3,
                                    ".",
                                    ""
                                ),
                                "0"
                            ),
                            "."
                        ),
                        rtrim(
                            rtrim(
                                number_format(
                                    $already_returned,
                                    3,
                                    ".",
                                    ""
                                ),
                                "0"
                            ),
                            "."
                        )
                    );

                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | REQUESTED QUANTITY EXCEEDS AVAILABLE
                |--------------------------------------------------------------------------
                */

                if (
                    $quantity >
                    ($available_quantity + 0.000001)
                ) {

                    $validation_errors[] = sprintf(
                        "%s: You are trying to return %s, but only %s is still available.",
                        $item["product_name"],
                        rtrim(
                            rtrim(
                                number_format(
                                    $quantity,
                                    3,
                                    ".",
                                    ""
                                ),
                                "0"
                            ),
                            "."
                        ),
                        rtrim(
                            rtrim(
                                number_format(
                                    $available_quantity,
                                    3,
                                    ".",
                                    ""
                                ),
                                "0"
                            ),
                            "."
                        )
                    );

                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | CALCULATE REFUND
                |--------------------------------------------------------------------------
                */

                $unit_price =
                    (float) $item["unit_price"];

                $item_refund =
                    round(
                        $quantity * $unit_price,
                        2
                    );


                /*
                |--------------------------------------------------------------------------
                | ADD TO RETURN LIST
                |--------------------------------------------------------------------------
                */

                $items_to_return[] = [
                    "sale_item_id"       => $sale_item_id,
                    "product_id"         => (int) $item["product_id"],
                    "product_name"       => $item["product_name"],
                    "quantity"           => $quantity,
                    "unit_price"         => $unit_price,
                    "total"              => $item_refund,
                    "original_quantity"  => $original_quantity,
                    "already_returned"   => $already_returned,
                    "available_quantity" => $available_quantity
                ];


                $refund_amount += $item_refund;
            }


            /*
            |--------------------------------------------------------------------------
            | STOP ON VALIDATION ERRORS
            |--------------------------------------------------------------------------
            */

            if (!empty($validation_errors)) {

                throw new Exception(
                    "Return could not be processed:\n\n• "
                    . implode(
                        "\n• ",
                        $validation_errors
                    )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | MAKE SURE SOMETHING WAS SELECTED
            |--------------------------------------------------------------------------
            */

            if (empty($items_to_return)) {

                throw new Exception(
                    "Please enter a valid return quantity for at least one product."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REFUND MUST BE GREATER THAN ZERO
            |--------------------------------------------------------------------------
            */

            $refund_amount =
                round($refund_amount, 2);

            if ($refund_amount <= 0) {

                throw new Exception(
                    "The refund amount must be greater than zero."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | GENERATE UNIQUE RETURN NUMBER
            |--------------------------------------------------------------------------
            */

            do {

                $return_number =
                    "RET-"
                    . date("Ymd-His")
                    . "-"
                    . random_int(100, 999);


                $check = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM returns
                    WHERE business_id = :business_id
                      AND return_number = :return_number
                ");

                $check->execute([
                    ":business_id"   => $business_id,
                    ":return_number" => $return_number
                ]);

                $exists =
                    (int) $check->fetchColumn();

            } while ($exists > 0);


            /*
            |--------------------------------------------------------------------------
            | CREATE RETURN
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO returns (
                    business_id,
                    sale_id,
                    user_id,
                    return_number,
                    reason,
                    refund_amount,
                    status
                )
                VALUES (
                    :business_id,
                    :sale_id,
                    :user_id,
                    :return_number,
                    :reason,
                    :refund_amount,
                    'completed'
                )
            ");

            $stmt->execute([
                ":business_id"   => $business_id,
                ":sale_id"       => $sale_id,
                ":user_id"       => $user_id,
                ":return_number" => $return_number,
                ":reason"        =>
                    $reason !== ""
                        ? $reason
                        : null,
                ":refund_amount" =>
                    $refund_amount
            ]);


            $return_id =
                (int) $pdo->lastInsertId();


            if ($return_id <= 0) {

                throw new Exception(
                    "Failed to create return record."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | PREPARE RETURN ITEM INSERT
            |--------------------------------------------------------------------------
            */

            $stmt_return_item = $pdo->prepare("
                INSERT INTO return_items (
                    return_id,
                    sale_item_id,
                    product_id,
                    quantity,
                    unit_price,
                    total
                )
                VALUES (
                    :return_id,
                    :sale_item_id,
                    :product_id,
                    :quantity,
                    :unit_price,
                    :total
                )
            ");


            /*
            |--------------------------------------------------------------------------
            | PREPARE STOCK UPDATE
            |--------------------------------------------------------------------------
            */

            $stmt_stock = $pdo->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity + :quantity
                WHERE id = :product_id
                  AND business_id = :business_id
            ");


            /*
            |--------------------------------------------------------------------------
            | PREPARE STOCK MOVEMENT
            |--------------------------------------------------------------------------
            */

            $stmt_movement = $pdo->prepare("
                INSERT INTO stock_movements (
                    business_id,
                    product_id,
                    user_id,
                    movement_type,
                    quantity,
                    reference_id,
                    notes
                )
                VALUES (
                    :business_id,
                    :product_id,
                    :user_id,
                    'return',
                    :quantity,
                    :reference_id,
                    :notes
                )
            ");


            /*
            |--------------------------------------------------------------------------
            | PREPARE INVENTORY ACTIVITY LOG
            |--------------------------------------------------------------------------
            */

            $stmt_activity = $pdo->prepare("
                INSERT INTO inventory_activity_log (
                    business_id,
                    user_id,
                    product_id,
                    product_name,
                    activity_type,
                    previous_stock,
                    new_stock,
                    quantity_change,
                    reason,
                    notes
                )
                VALUES (
                    :business_id,
                    :user_id,
                    :product_id,
                    :product_name,
                    :activity_type,
                    :previous_stock,
                    :new_stock,
                    :quantity_change,
                    :reason,
                    :notes
                )
            ");


            /*
            |--------------------------------------------------------------------------
            | PROCESS EACH RETURN ITEM
            |--------------------------------------------------------------------------
            */

            $processed_items = [];


            foreach ($items_to_return as $item) {

                /*
                |--------------------------------------------------------------------------
                | FINAL SAFETY CHECK
                |--------------------------------------------------------------------------
                */

                $verify = $pdo->prepare("
                    SELECT
                        si.quantity AS original_quantity,
                        COALESCE(
                            (
                                SELECT SUM(ri.quantity)
                                FROM return_items ri
                                INNER JOIN returns r
                                    ON r.id = ri.return_id
                                WHERE ri.sale_item_id = si.id
                                  AND r.sale_id = si.sale_id
                                  AND r.business_id = :business_id
                                  AND LOWER(COALESCE(r.status, '')) = 'completed'
                            ),
                            0
                        ) AS already_returned
                    FROM sale_items si
                    WHERE si.id = :sale_item_id
                      AND si.sale_id = :sale_id
                    LIMIT 1
                ");

                $verify->execute([
                    ":business_id"  => $business_id,
                    ":sale_item_id" => $item["sale_item_id"],
                    ":sale_id"      => $sale_id
                ]);


                $verify_item =
                    $verify->fetch(PDO::FETCH_ASSOC);


                if (!$verify_item) {

                    throw new Exception(
                        "Sale item could not be verified."
                    );
                }


                $verify_original =
                    (float) $verify_item["original_quantity"];

                $verify_returned =
                    (float) $verify_item["already_returned"];

                $verify_available =
                    max(
                        0,
                        $verify_original - $verify_returned
                    );


                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                |
                | The newly-created return has not yet inserted its
                | return_item, so this is still the available quantity
                | before this item is inserted.
                |
                */

                if (
                    $item["quantity"] >
                    ($verify_available + 0.000001)
                ) {

                    throw new Exception(
                        $item["product_name"]
                        . ": This item has already been returned "
                        . "or the remaining quantity is no longer available."
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | INSERT RETURN ITEM
                |--------------------------------------------------------------------------
                */

                $stmt_return_item->execute([
                    ":return_id"    => $return_id,
                    ":sale_item_id" => $item["sale_item_id"],
                    ":product_id"   => $item["product_id"],
                    ":quantity"     => $item["quantity"],
                    ":unit_price"   => $item["unit_price"],
                    ":total"        => $item["total"]
                ]);


                $return_item_id =
                    (int) $pdo->lastInsertId();


                if ($return_item_id <= 0) {

                    throw new Exception(
                        "Failed to create return item for "
                        . $item["product_name"]
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | LOCK PRODUCT AND READ CURRENT STOCK
                |--------------------------------------------------------------------------
                */

                $stmt_current_stock = $pdo->prepare("
                    SELECT
                        id,
                        stock_quantity
                    FROM products
                    WHERE id = :product_id
                      AND business_id = :business_id
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt_current_stock->execute([
                    ":product_id"  => $item["product_id"],
                    ":business_id" => $business_id
                ]);


                $product_row =
                    $stmt_current_stock->fetch(PDO::FETCH_ASSOC);


                if (!$product_row) {

                    throw new Exception(
                        "Product not found while processing return: "
                        . $item["product_name"]
                    );
                }


                $previous_stock =
                    (float) $product_row["stock_quantity"];

                $new_stock =
                    $previous_stock
                    + (float) $item["quantity"];


                /*
                |--------------------------------------------------------------------------
                | ADD STOCK BACK
                |--------------------------------------------------------------------------
                */

                $stmt_stock->execute([
                    ":quantity"    => $item["quantity"],
                    ":product_id"  => $item["product_id"],
                    ":business_id" => $business_id
                ]);


                if ($stmt_stock->rowCount() !== 1) {

                    throw new Exception(
                        "Failed to update stock for "
                        . $item["product_name"]
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | RECORD STOCK MOVEMENT
                |--------------------------------------------------------------------------
                */

                $movement_notes =
                    "Customer return "
                    . $return_number
                    . " for receipt "
                    . $sale["receipt_number"];


                $stmt_movement->execute([
                    ":business_id"  => $business_id,
                    ":product_id"   => $item["product_id"],
                    ":user_id"      => $user_id,
                    ":quantity"     => $item["quantity"],
                    ":reference_id" => $return_id,
                    ":notes"        => $movement_notes
                ]);


                $stock_movement_id =
                    (int) $pdo->lastInsertId();


                if ($stock_movement_id <= 0) {

                    throw new Exception(
                        "Failed to record stock movement for "
                        . $item["product_name"]
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | RECORD INVENTORY ACTIVITY
                |--------------------------------------------------------------------------
                */

                $activity_reason =
                    $reason !== ""
                        ? $reason
                        : "Customer return";


                $stmt_activity->execute([
                    ":business_id"     => $business_id,
                    ":user_id"         => $user_id,
                    ":product_id"      => $item["product_id"],
                    ":product_name"    => $item["product_name"],
                    ":activity_type"   => "return",
                    ":previous_stock"  => $previous_stock,
                    ":new_stock"       => $new_stock,
                    ":quantity_change" => $item["quantity"],
                    ":reason"          => $activity_reason,
                    ":notes"           => $movement_notes
                ]);


                $activity_log_id =
                    (int) $pdo->lastInsertId();


                if ($activity_log_id <= 0) {

                    throw new Exception(
                        "Failed to record inventory activity for "
                        . $item["product_name"]
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | KEEP PROCESSED ITEM DATA
                |--------------------------------------------------------------------------
                */

                $processed_items[] = [
                    "id" => $return_item_id,
                    "return_id" => $return_id,
                    "sale_item_id" =>
                        (int) $item["sale_item_id"],
                    "product_id" =>
                        (int) $item["product_id"],
                    "product_name" =>
                        $item["product_name"],
                    "quantity" =>
                        (float) $item["quantity"],
                    "unit_price" =>
                        (float) $item["unit_price"],
                    "total" =>
                        (float) $item["total"],
                    "previous_stock" =>
                        $previous_stock,
                    "new_stock" =>
                        $new_stock,
                    "stock_movement_id" =>
                        $stock_movement_id,
                    "activity_log_id" =>
                        $activity_log_id
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | DETERMINE IF ENTIRE SALE HAS BEEN RETURNED
            |--------------------------------------------------------------------------
            */

            $fully_returned = true;


            foreach ($all_sale_items as $item) {

                $sold =
                    (float) $item["original_quantity"];

                $returned =
                    (float) $item["already_returned"];


                foreach ($items_to_return as $return_item) {

                    if (
                        (int) $return_item["sale_item_id"]
                        ===
                        (int) $item["sale_item_id"]
                    ) {

                        $returned +=
                            (float) $return_item["quantity"];
                    }
                }


                if (
                    $returned <
                    ($sold - 0.000001)
                ) {

                    $fully_returned = false;

                    break;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | MARK SALE AS FULLY REFUNDED
            |--------------------------------------------------------------------------
            */

            if ($fully_returned) {

                $stmt = $pdo->prepare("
                    UPDATE sales
                    SET sale_status = 'refunded'
                    WHERE id = :sale_id
                      AND business_id = :business_id
                ");

                $stmt->execute([
                    ":sale_id"     => $sale_id,
                    ":business_id" => $business_id
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | BUILD RETURN SYNC PAYLOAD
            |--------------------------------------------------------------------------
            */

            $sync_items = [];


            foreach ($processed_items as $processed_item) {

                $sync_items[] = [
                    "id" =>
                        (int) $processed_item["id"],

                    "return_id" =>
                        (int) $processed_item["return_id"],

                    "sale_item_id" =>
                        (int) $processed_item["sale_item_id"],

                    "product_id" =>
                        (int) $processed_item["product_id"],

                    "product_name" =>
                        $processed_item["product_name"],

                    "quantity" =>
                        (float) $processed_item["quantity"],

                    "unit_price" =>
                        (float) $processed_item["unit_price"],

                    "total" =>
                        (float) $processed_item["total"],

                    "previous_stock" =>
                        (float) $processed_item["previous_stock"],

                    "new_stock" =>
                        (float) $processed_item["new_stock"],

                    "stock_movement_id" =>
                        (int) $processed_item["stock_movement_id"],

                    "activity_log_id" =>
                        (int) $processed_item["activity_log_id"]
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | QUEUE RETURN FOR CLOUD SYNCHRONIZATION
            |--------------------------------------------------------------------------
            */

            $syncPayload = [
                "return" => [
                    "id" =>
                        $return_id,

                    "business_id" =>
                        $business_id,

                    "sale_id" =>
                        $sale_id,

                    "user_id" =>
                        $user_id,

                    "return_number" =>
                        $return_number,

                    "reason" =>
                        $reason !== ""
                            ? $reason
                            : null,

                    "refund_amount" =>
                        $refund_amount,

                    "status" =>
                        "completed",

                    "fully_returned" =>
                        $fully_returned,

                    "created_at" =>
                        date("Y-m-d H:i:s")
                ],

                "items" =>
                    $sync_items,

                "sale" => [
                    "id" =>
                        $sale_id,

                    "receipt_number" =>
                        $sale["receipt_number"],

                    "fully_returned" =>
                        $fully_returned
                ]
            ];


            /*
            |--------------------------------------------------------------------------
            | ADD RETURN TO SYNC QUEUE
            |--------------------------------------------------------------------------
            */

            $syncQueued = addToSyncQueue(
                $pdo,
                "return",
                $return_id,
                "create",
                $syncPayload
            );


            if (!$syncQueued) {

                throw new Exception(
                    "Return was prepared successfully, but the synchronization event could not be created. The transaction was cancelled."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT EVERYTHING
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | REDIRECT TO RETURN DETAILS
            |--------------------------------------------------------------------------
            */

            header(
                "Location: view_return.php?id="
                . $return_id
                . "&success="
                . urlencode(
                    "Return processed successfully."
                )
            );

            exit;


        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK EVERYTHING
            |--------------------------------------------------------------------------
            */

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }


            $error =
                $e->getMessage();


            /*
            |--------------------------------------------------------------------------
            | RELOAD SALE AFTER ERROR
            |--------------------------------------------------------------------------
            */

            if ($sale_id > 0) {

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        business_id,
                        customer_id,
                        user_id,
                        receipt_number,
                        subtotal,
                        discount,
                        tax,
                        total_amount,
                        sale_status,
                        sale_date
                    FROM sales
                    WHERE id = :sale_id
                      AND business_id = :business_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ":sale_id"     => $sale_id,
                    ":business_id" => $business_id
                ]);

                $sale =
                    $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| STEP 3: LOAD SALE ITEMS FOR DISPLAY
|--------------------------------------------------------------------------
*/

if ($sale) {

    $stmt = $pdo->prepare("
        SELECT
            si.id,
            si.product_id,
            si.quantity AS original_quantity,
            si.unit_price,
            si.total,
            p.name AS product_name,

            COALESCE(
                (
                    SELECT SUM(ri.quantity)
                    FROM return_items ri
                    INNER JOIN returns r
                        ON r.id = ri.return_id
                    WHERE ri.sale_item_id = si.id
                      AND r.sale_id = si.sale_id
                      AND r.business_id = :business_id
                      AND LOWER(COALESCE(r.status, '')) = 'completed'
                ),
                0
            ) AS returned_quantity

        FROM sale_items si

        INNER JOIN products p
            ON p.id = si.product_id

        WHERE si.sale_id = :sale_id

        ORDER BY si.id ASC
    ");

    $stmt->execute([
        ":sale_id"     => $sale["id"],
        ":business_id" => $business_id
    ]);

    $sale_items =
        $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Process Return - BizFlow</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>

.return-form {
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    margin-bottom: 25px;
}

.return-search {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.return-search input {
    flex: 1;
    min-width: 250px;
    padding: 11px 13px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 14px;
}

.return-search button {
    border: none;
    cursor: pointer;
}

.return-info {
    background: #f9fafb;
    padding: 18px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.return-info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.return-info-item {
    font-size: 14px;
}

.return-info-label {
    display: block;
    color: #777;
    font-size: 12px;
    margin-bottom: 4px;
}

.return-table {
    width: 100%;
    border-collapse: collapse;
}

.return-table th,
.return-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
}

.return-table th {
    background: #f9fafb;
    font-size: 13px;
}

.quantity-input {
    width: 90px;
    padding: 8px;
    border: 1px solid #d1d5db;
    border-radius: 5px;
}

.quantity-input:disabled {
    background: #f3f4f6;
    cursor: not-allowed;
}

.return-summary {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
}

.refund-box {
    background: #f9fafb;
    padding: 18px 25px;
    border-radius: 8px;
    min-width: 250px;
}

.refund-box strong {
    font-size: 22px;
}

.reason-box {
    margin-top: 20px;
}

.reason-box label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
    font-size: 14px;
}

.reason-box textarea {
    width: 100%;
    min-height: 90px;
    padding: 10px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    resize: vertical;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    white-space: pre-line;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    padding: 12px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.return-disabled {
    color: #9ca3af;
    font-size: 13px;
}

.available-qty {
    font-weight: bold;
    color: #1d4ed8;
}

.already-returned-qty {
    color: #d97706;
}

@media (max-width: 800px) {

    .return-info-grid {
        grid-template-columns: 1fr;
    }

    .return-table {
        min-width: 800px;
    }
}

</style>

</head>

<body>

<div class="app">

<?php include "../assets/includes/sidebar.php"; ?>

<main class="main">

<?php include "../assets/includes/topbar.php"; ?>

<section class="content">

<div class="page-title">

<div>

<h1>Process Return</h1>

<p>
Find a receipt and process a customer return.
</p>

</div>

</div>


<?php if ($error !== ""): ?>

<div class="alert-error">
<?= nl2br(htmlspecialchars($error)) ?>
</div>

<?php endif; ?>


<?php if ($message !== ""): ?>

<div class="alert-success">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<div class="return-form">

<h3 style="margin-bottom:15px;">
Find Original Sale
</h3>

<form method="POST">

<div class="return-search">

<input
    type="text"
    name="receipt_number"
    placeholder="Enter receipt number e.g. REC-20260608-5716"
    value="<?= htmlspecialchars(
        $_POST["receipt_number"] ?? ""
    ) ?>"
    required
>

<button
    type="submit"
    name="find_sale"
    class="btn-primary"
>
Find Receipt
</button>

</div>

</form>

</div>


<?php if ($sale): ?>

<div class="return-form">

<div class="return-info">

<div class="return-info-grid">

<div class="return-info-item">

<span class="return-info-label">
Receipt Number
</span>

<strong>
<?= htmlspecialchars(
    $sale["receipt_number"]
) ?>
</strong>

</div>


<div class="return-info-item">

<span class="return-info-label">
Sale Date
</span>

<strong>

<?= date(
    "d M Y H:i",
    strtotime(
        $sale["sale_date"]
    )
) ?>

</strong>

</div>


<div class="return-info-item">

<span class="return-info-label">
Sale Total
</span>

<strong>

KSh
<?= number_format(
    (float)
    $sale["total_amount"],
    2
) ?>

</strong>

</div>

</div>

</div>


<form method="POST">

<input
    type="hidden"
    name="sale_id"
    value="<?= (int) $sale["id"] ?>"
>


<div style="overflow-x:auto;">

<table class="return-table">

<thead>

<tr>

<th>Product</th>

<th>Sold</th>

<th>Already Returned</th>

<th>Available</th>

<th>Unit Price</th>

<th>Return Qty</th>

<th>Refund</th>

</tr>

</thead>

<tbody>

<?php foreach ($sale_items as $item): ?>

<?php

$sold =
    (float)
    $item["original_quantity"];

$already_returned =
    (float)
    $item["returned_quantity"];

$available =
    max(
        0,
        $sold - $already_returned
    );

?>

<tr>

<td>

<strong>
<?= htmlspecialchars(
    $item["product_name"]
) ?>
</strong>

</td>


<td>

<?= number_format(
    $sold,
    3
) ?>

</td>


<td>

<?php if ($already_returned > 0): ?>

<span class="already-returned-qty">

<?= number_format(
    $already_returned,
    3
) ?>

</span>

<?php else: ?>

0.000

<?php endif; ?>

</td>


<td>

<?php if ($available > 0): ?>

<span class="available-qty">

<?= number_format(
    $available,
    3
) ?>

</span>

<?php else: ?>

<span style="color:#dc2626;">
0
</span>

<?php endif; ?>

</td>


<td>

KSh
<?= number_format(
    (float)
    $item["unit_price"],
    2
) ?>

</td>


<td>

<?php if ($available > 0): ?>

<input
    type="number"
    name="return_quantity[<?= (int) $item["id"] ?>]"
    class="quantity-input return-quantity"

    data-price="<?= htmlspecialchars(
        (string)
        $item["unit_price"]
    ) ?>"

    data-available="<?= htmlspecialchars(
        (string)
        $available
    ) ?>"

    min="0"

    max="<?= htmlspecialchars(
        (string)
        $available
    ) ?>"

    step="0.001"

    value="0"

    placeholder="0"
>

<?php else: ?>

<span class="return-disabled">
✅ Fully Returned
</span>

<?php endif; ?>

</td>


<td>

<span class="item-refund">
KSh 0.00
</span>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>


<div class="reason-box">

<label for="reason">
Return Reason
</label>

<textarea
    name="reason"
    id="reason"
    placeholder="Example: Damaged product, wrong size, customer changed mind..."
></textarea>

</div>


<div class="return-summary">

<div class="refund-box">

<div
    style="
        color:#777;
        font-size:13px;
        margin-bottom:6px;
    "
>
Total Refund
</div>

<strong id="refund-total">
KSh 0.00
</strong>

</div>

</div>


<div
    style="
        margin-top:20px;
        display:flex;
        justify-content:flex-end;
        gap:10px;
    "
>

<a
    href="returns.php"
    class="btn-edit"
>
Cancel
</a>


<button
    type="submit"
    name="process_return"
    class="btn-primary"
    id="process-return-button"
    disabled
    style="opacity:0.5;"
>
Process Return
</button>

</div>

</form>

</div>

<?php endif; ?>

</section>

</main>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const quantityInputs =
            document.querySelectorAll(
                ".return-quantity"
            );

        const refundTotal =
            document.getElementById(
                "refund-total"
            );

        const processButton =
            document.getElementById(
                "process-return-button"
            );


        function calculateRefund() {

            let total = 0;


            quantityInputs.forEach(
                function (input) {

                    let quantity =
                        parseFloat(
                            input.value
                        ) || 0;


                    const max =
                        parseFloat(
                            input.dataset.available
                        );


                    if (
                        !isNaN(max)
                        &&
                        quantity > max
                    ) {

                        quantity = max;

                        input.value = max;
                    }


                    if (quantity < 0) {

                        quantity = 0;

                        input.value = 0;
                    }


                    const price =
                        parseFloat(
                            input.dataset.price
                        ) || 0;


                    const refund =
                        quantity * price;


                    total += refund;


                    const row =
                        input.closest("tr");


                    const refundElement =
                        row.querySelector(
                            ".item-refund"
                        );


                    if (refundElement) {

                        refundElement.textContent =
                            "KSh "
                            +
                            refund.toLocaleString(
                                "en-KE",
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            );
                    }

                }
            );


            refundTotal.textContent =
                "KSh "
                +
                total.toLocaleString(
                    "en-KE",
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );


            if (processButton) {

                const disabled =
                    total <= 0;


                processButton.disabled =
                    disabled;


                processButton.style.opacity =
                    disabled
                        ? "0.5"
                        : "1";


                processButton.style.cursor =
                    disabled
                        ? "not-allowed"
                        : "pointer";
            }

        }


        quantityInputs.forEach(
            function (input) {

                input.addEventListener(
                    "input",
                    calculateRefund
                );

                input.addEventListener(
                    "change",
                    calculateRefund
                );

            }
        );


        calculateRefund();

    }
);

</script>

</body>

</html>
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";
require_once "../assets/includes/sync_helper.php";

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$user_id     = (int) ($_SESSION["user_id"] ?? 0);

if ($business_id <= 0 || $user_id <= 0) {
    die("Invalid business or user session.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: pos.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| RECEIVE POS DATA
|--------------------------------------------------------------------------
*/

$receipt_number = trim((string) ($_POST['receipt_number'] ?? ''));

$cart_data = json_decode(
    $_POST['cart_data'] ?? '[]',
    true
);

$payment_method = $_POST['payment_method'] ?? 'cash';

$cash_amount = (float) ($_POST['cash_amount'] ?? 0);

$bank_amount = (float) ($_POST['bank_amount'] ?? 0);

$discount_type = $_POST['discount_type'] ?? 'none';

$discount_value = (float) ($_POST['discount_value'] ?? 0);

$discount_reason = trim(
    (string) ($_POST['discount_reason'] ?? '')
);

$discount_amount = (float) ($_POST['discount_amount'] ?? 0);


/*
|--------------------------------------------------------------------------
| VALIDATE CART
|--------------------------------------------------------------------------
*/

if (!is_array($cart_data) || empty($cart_data)) {

    header(
        "Location: pos.php?error=" .
        urlencode("Cart is empty")
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| PROCESS SALE
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | CALCULATE SUBTOTAL
    |--------------------------------------------------------------------------
    */

    $subtotal = 0.0;

    foreach ($cart_data as $item) {

        $itemPrice = (float) ($item['price'] ?? 0);

        $itemQuantity = (float) ($item['quantity'] ?? 0);

        if ($itemPrice < 0 || $itemQuantity <= 0) {
            throw new Exception(
                "Invalid product quantity or price."
            );
        }

        $subtotal +=
            $itemPrice *
            $itemQuantity;
    }


    /*
    |--------------------------------------------------------------------------
    | CALCULATE TOTAL
    |--------------------------------------------------------------------------
    */

    $total_amount =
        $subtotal -
        $discount_amount;


    if ($total_amount < 0) {
        $total_amount = 0;
    }


    /*
    |--------------------------------------------------------------------------
    | CALCULATE CHANGE
    |--------------------------------------------------------------------------
    */

    $change_given = 0.0;

    if (
        $payment_method === 'cash' ||
        $payment_method === 'mixed'
    ) {

        $total_paid =
            $cash_amount +
            $bank_amount;

        $change_given =
            $total_paid -
            $total_amount;

        if ($change_given < 0) {
            $change_given = 0;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SAVE SALE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("

        INSERT INTO sales
        (
            business_id,
            user_id,
            receipt_number,
            subtotal,
            discount_type,
            discount_value,
            discount_amount,
            discount_reason,
            total_amount,
            payment_method,
            cash_amount,
            bank_amount,
            change_given,
            sale_status,
            payment_status
        )

        VALUES
        (
            :business_id,
            :user_id,
            :receipt_number,
            :subtotal,
            :discount_type,
            :discount_value,
            :discount_amount,
            :discount_reason,
            :total_amount,
            :payment_method,
            :cash_amount,
            :bank_amount,
            :change_given,
            'completed',
            'paid'
        )

    ");

    $stmt->execute([

        ":business_id" =>
            $business_id,

        ":user_id" =>
            $user_id,

        ":receipt_number" =>
            $receipt_number,

        ":subtotal" =>
            $subtotal,

        ":discount_type" =>
            $discount_type,

        ":discount_value" =>
            $discount_value,

        ":discount_amount" =>
            $discount_amount,

        ":discount_reason" =>
            $discount_reason,

        ":total_amount" =>
            $total_amount,

        ":payment_method" =>
            $payment_method,

        ":cash_amount" =>
            $cash_amount,

        ":bank_amount" =>
            $bank_amount,

        ":change_given" =>
            $change_given

    ]);


    $sale_id = (int) $pdo->lastInsertId();


    if ($sale_id <= 0) {

        throw new Exception(
            "Sale creation failed."
        );

    }


    /*
    |--------------------------------------------------------------------------
    | SAVE PAYMENT RECORD
    |--------------------------------------------------------------------------
    */

    if ($payment_method === "cash") {

        if ($cash_amount > 0) {

            $stmt = $pdo->prepare("

                INSERT INTO payments
                (
                    business_id,
                    sale_id,
                    payment_method,
                    amount,
                    status,
                    provider
                )

                VALUES
                (
                    :business_id,
                    :sale_id,
                    'cash',
                    :amount,
                    'completed',
                    'Manual'
                )

            ");

            $stmt->execute([

                ":business_id" =>
                    $business_id,

                ":sale_id" =>
                    $sale_id,

                ":amount" =>
                    $cash_amount

            ]);
        }
    }


    elseif ($payment_method === "bank") {

        if ($bank_amount > 0) {

            $stmt = $pdo->prepare("

                INSERT INTO payments
                (
                    business_id,
                    sale_id,
                    payment_method,
                    amount,
                    status,
                    provider
                )

                VALUES
                (
                    :business_id,
                    :sale_id,
                    'bank',
                    :amount,
                    'completed',
                    'Manual'
                )

            ");

            $stmt->execute([

                ":business_id" =>
                    $business_id,

                ":sale_id" =>
                    $sale_id,

                ":amount" =>
                    $bank_amount

            ]);
        }
    }


    elseif ($payment_method === "mixed") {

        /*
        |--------------------------------------------------------------------------
        | CASH PART
        |--------------------------------------------------------------------------
        */

        if ($cash_amount > 0) {

            $stmt = $pdo->prepare("

                INSERT INTO payments
                (
                    business_id,
                    sale_id,
                    payment_method,
                    amount,
                    status,
                    provider
                )

                VALUES
                (
                    :business_id,
                    :sale_id,
                    'cash',
                    :amount,
                    'completed',
                    'Manual'
                )

            ");

            $stmt->execute([

                ":business_id" =>
                    $business_id,

                ":sale_id" =>
                    $sale_id,

                ":amount" =>
                    $cash_amount

            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | BANK PART
        |--------------------------------------------------------------------------
        */

        if ($bank_amount > 0) {

            $stmt = $pdo->prepare("

                INSERT INTO payments
                (
                    business_id,
                    sale_id,
                    payment_method,
                    amount,
                    status,
                    provider
                )

                VALUES
                (
                    :business_id,
                    :sale_id,
                    'bank',
                    :amount,
                    'completed',
                    'Manual'
                )

            ");

            $stmt->execute([

                ":business_id" =>
                    $business_id,

                ":sale_id" =>
                    $sale_id,

                ":amount" =>
                    $bank_amount

            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SAVE SALE ITEMS AND REDUCE STOCK
    |--------------------------------------------------------------------------
    */

    foreach ($cart_data as $item) {

        /*
        |--------------------------------------------------------------------------
        | VALIDATE PRODUCT ID
        |--------------------------------------------------------------------------
        */

        $product_id = (int) ($item['id'] ?? 0);

        if ($product_id <= 0) {

            throw new Exception(
                "Invalid product in cart."
            );
        }


        $quantity = (float) ($item['quantity'] ?? 0);

        $unit_price = (float) ($item['price'] ?? 0);


        if ($quantity <= 0) {

            throw new Exception(
                "Invalid quantity for product."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | LOCK AND GET PRODUCT
        |--------------------------------------------------------------------------
        |
        | FOR UPDATE prevents two POS transactions from simultaneously
        | selling the same remaining stock.
        |
        */

        $stmt = $pdo->prepare("

            SELECT
                id,
                name,
                buying_price,
                selling_price,
                stock_quantity

            FROM products

            WHERE id = :id
            AND business_id = :business_id

            FOR UPDATE

        ");

        $stmt->execute([

            ":id" =>
                $product_id,

            ":business_id" =>
                $business_id

        ]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$product) {

            throw new Exception(
                "Product not found: " .
                ($item['name'] ?? 'Unknown product')
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK STOCK
        |--------------------------------------------------------------------------
        */

        if (
            (float) $product['stock_quantity'] <
            $quantity
        ) {

            throw new Exception(
                "Not enough stock for " .
                $product['name']
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CALCULATE ITEM TOTAL
        |--------------------------------------------------------------------------
        */

        $item_total =
            $unit_price *
            $quantity;


        /*
        |--------------------------------------------------------------------------
        | SAVE SALE ITEM
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("

            INSERT INTO sale_items
            (
                sale_id,
                product_id,
                quantity,
                unit_price,
                buying_price,
                total,
                sale_type
            )

            VALUES
            (
                :sale_id,
                :product_id,
                :quantity,
                :unit_price,
                :buying_price,
                :total,
                :sale_type
            )

        ");

        $stmt->execute([

            ":sale_id" =>
                $sale_id,

            ":product_id" =>
                $product_id,

            ":quantity" =>
                $quantity,

            ":unit_price" =>
                $unit_price,

            ":buying_price" =>
                (float) $product['buying_price'],

            ":total" =>
                $item_total,

            ":sale_type" =>
                $item['sale_type'] ?? "retail"

        ]);


        /*
        |--------------------------------------------------------------------------
        | REDUCE STOCK
        |--------------------------------------------------------------------------
        */

        $new_stock =
            (float) $product['stock_quantity'] -
            $quantity;


        $stmt = $pdo->prepare("

            UPDATE products

            SET stock_quantity = :stock

            WHERE id = :id
            AND business_id = :business_id

        ");

        $stmt->execute([

            ":stock" =>
                $new_stock,

            ":id" =>
                $product_id,

            ":business_id" =>
                $business_id

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT LOCAL SALE
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | BUILD AUTHORITATIVE SYNC PAYLOAD
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | We DO NOT use the original browser $cart_data here.
    |
    | The browser cart may contain "id" rather than "product_id".
    |
    | Instead, we read the actual sale_items records that were successfully
    | written to the database.
    |
    */

    try {

        /*
        |--------------------------------------------------------------------------
        | LOAD SAVED SALE ITEMS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("

            SELECT
                id,
                product_id,
                quantity,
                unit_price,
                buying_price,
                total,
                sale_type

            FROM sale_items

            WHERE sale_id = :sale_id

            ORDER BY id ASC

        ");

        $stmt->execute([
            ":sale_id" =>
                $sale_id
        ]);

        $savedSaleItems =
            $stmt->fetchAll(PDO::FETCH_ASSOC);


        if (!$savedSaleItems) {

            throw new Exception(
                "Sale was saved but no sale items were found for synchronization."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CREATE CLEAN SYNC ITEMS
        |--------------------------------------------------------------------------
        */

        $syncItems = [];


        foreach ($savedSaleItems as $savedItem) {

            $savedProductId =
                (int) $savedItem['product_id'];


            if ($savedProductId <= 0) {

                throw new Exception(
                    "Sale item " .
                    $savedItem['id'] .
                    " has an invalid product ID."
                );
            }


            $syncItems[] = [

                'id' =>
                    (int) $savedItem['id'],

                'product_id' =>
                    $savedProductId,

                'quantity' =>
                    (float) $savedItem['quantity'],

                'price' =>
                    (float) $savedItem['unit_price'],

                'unit_price' =>
                    (float) $savedItem['unit_price'],

                'buying_price' =>
                    (float) $savedItem['buying_price'],

                'total' =>
                    (float) $savedItem['total'],

                'sale_type' =>
                    $savedItem['sale_type'] ?? 'retail'

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | BUILD SALE PAYLOAD
        |--------------------------------------------------------------------------
        */

        $salePayload = [

            'sale' => [

                'id' =>
                    $sale_id,

                'business_id' =>
                    $business_id,

                'user_id' =>
                    $user_id,

                'receipt_number' =>
                    $receipt_number,

                'subtotal' =>
                    $subtotal,

                'discount_type' =>
                    $discount_type,

                'discount_value' =>
                    $discount_value,

                'discount_amount' =>
                    $discount_amount,

                'discount_reason' =>
                    $discount_reason,

                'total_amount' =>
                    $total_amount,

                'payment_method' =>
                    $payment_method,

                'cash_amount' =>
                    $cash_amount,

                'bank_amount' =>
                    $bank_amount,

                'change_given' =>
                    $change_given,

                'sale_status' =>
                    'completed',

                'payment_status' =>
                    'paid'

            ],

            'items' =>
                $syncItems

        ];


        /*
        |--------------------------------------------------------------------------
        | ADD SALE TO SYNCHRONIZATION QUEUE
        |--------------------------------------------------------------------------
        */

        $queued = addToSyncQueue(

            $pdo,

            'sale',

            $sale_id,

            'create',

            $salePayload

        );


        if (!$queued) {

            throw new Exception(
                "Sale completed locally, but could not be added to synchronization queue."
            );
        }

    } catch (Throwable $syncError) {

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | The local sale is already committed.
        |
        | Therefore synchronization failure MUST NOT roll back or cancel
        | the customer's completed sale.
        |
        */

        error_log(
            "BizFlow Sync Queue Error: " .
            $syncError->getMessage()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CALCULATE RECEIPT CHANGE
    |--------------------------------------------------------------------------
    */

    $change = 0.0;


    if ($payment_method === "cash") {

        $change =
            $cash_amount -
            $total_amount;

        if ($change < 0) {
            $change = 0;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REDIRECT TO RECEIPT
    |--------------------------------------------------------------------------
    */

    header(

        "Location: receipt.php?sale_id=" .
        $sale_id .
        "&change=" .
        urlencode((string) $change)

    );

    exit;


}


/*
|--------------------------------------------------------------------------
| ERROR HANDLING
|--------------------------------------------------------------------------
*/

catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        "BizFlow Sale Error: " .
        $e->getMessage()
    );


    die(
        "ERROR: " .
        $e->getMessage()
    );
}
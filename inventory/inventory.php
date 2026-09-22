<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
/** @var PDO $pdo */
require_once "../config/permissions.php";

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$admin_id    = (int) ($_SESSION["user_id"] ?? 0);

/*
|--------------------------------------------------------------------------
| ADMIN / OWNER ACCESS
|--------------------------------------------------------------------------
| Use the same permission helper as the sidebar so the sidebar and page
| cannot disagree about whether reconciliation should be displayed.
*/
$user_role = strtolower(trim(
    $_SESSION["role"] ?? $_SESSION["user_role"] ?? ""
));

$is_admin = false;

if (function_exists("isAdmin")) {
    $is_admin = (bool) isAdmin();
}

/*
 * Fallback for installations where isAdmin() is not available.
 */
if (!$is_admin) {
    $is_admin = in_array($user_role, [
        "admin",
        "administrator",
        "owner"
    ], true);
}

/*
|--------------------------------------------------------------------------
| DATE RANGE
|--------------------------------------------------------------------------
*/
$recon_from = $_GET["recon_from"] ?? date("Y-m-01");
$recon_to   = $_GET["recon_to"]   ?? date("Y-m-d");

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recon_from)) {
    $recon_from = date("Y-m-01");
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recon_to)) {
    $recon_to = date("Y-m-d");
}

if ($recon_from > $recon_to) {
    [$recon_from, $recon_to] = [$recon_to, $recon_from];
}

$recon_start_datetime = $recon_from . " 00:00:00";
$recon_end_datetime   = $recon_to . " 23:59:59";

$message = "";
$error   = "";

/*
|--------------------------------------------------------------------------
| CREATE PHYSICAL CASH RECONCILIATION TABLE
|--------------------------------------------------------------------------
| This lets the feature work without requiring a separate SQL import.
*/
if ($is_admin) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_cash_reconciliations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                business_id INT NOT NULL,
                from_date DATE NOT NULL,
                to_date DATE NOT NULL,
                expected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
                physical_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
                difference DECIMAL(15,2) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cash_recon_period
                    (business_id, from_date, to_date),
                KEY idx_cash_recon_business (business_id),
                KEY idx_cash_recon_dates (from_date, to_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        $error = "Could not prepare the physical cash reconciliation table.";
    }
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function money($value): string
{
    return "KSh " . number_format((float)$value, 2);
}

function qty($value): string
{
    return rtrim(
        rtrim(number_format((float)$value, 3), "0"),
        "."
    );
}

/*
|--------------------------------------------------------------------------
| POST: SAVE PHYSICAL CASH COUNT
|--------------------------------------------------------------------------
*/
if ($is_admin && $_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if ($action === "save_cash_reconciliation") {

        $post_from = trim($_POST["recon_from"] ?? "");
        $post_to   = trim($_POST["recon_to"] ?? "");
        $physical  = trim($_POST["physical_cash"] ?? "");
        $notes     = trim($_POST["cash_notes"] ?? "");

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_from) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_to)
        ) {
            $error = "Please select valid reconciliation dates.";
        } elseif ($post_from > $post_to) {
            $error = "The From Date cannot be after the To Date.";
        } elseif ($physical === "" || !is_numeric($physical) || (float)$physical < 0) {
            $error = "Please enter a valid physical cash amount.";
        } else {

            $from_dt = $post_from . " 00:00:00";
            $to_dt   = $post_to . " 23:59:59";

            /*
            |----------------------------------------------------------------------
            | Expected physical CASH
            |----------------------------------------------------------------------
            | Prefer payment records whose payment_method is Cash. If the
            | payments table has no payment_method column, fall back to all
            | completed payments so the page remains compatible with older DBs.
            */
            $expected_cash = 0;

            try {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(p.amount), 0) AS total_cash
                    FROM payments p
                    WHERE p.business_id = :business_id
                      AND p.payment_date >= :from_date
                      AND p.payment_date <= :to_date
                      AND LOWER(COALESCE(p.status, '')) = 'completed'
                      AND LOWER(COALESCE(p.payment_method, '')) = 'cash'
                ");

                $stmt->execute([
                    ":business_id" => $business_id,
                    ":from_date"   => $from_dt,
                    ":to_date"     => $to_dt
                ]);

                $expected_cash = (float)($stmt->fetchColumn() ?? 0);

            } catch (PDOException $e) {

                try {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM(p.amount), 0) AS total_cash
                        FROM payments p
                        INNER JOIN sales s ON p.sale_id = s.id
                        WHERE s.business_id = :business_id
                          AND p.payment_date >= :from_date
                          AND p.payment_date <= :to_date
                          AND (
                              p.status IS NULL
                              OR LOWER(p.status) IN
                              ('completed','complete','paid','successful','success')
                          )
                    ");

                    $stmt->execute([
                        ":business_id" => $business_id,
                        ":from_date"   => $from_dt,
                        ":to_date"     => $to_dt
                    ]);

                    $expected_cash = (float)($stmt->fetchColumn() ?? 0);

                } catch (PDOException $e2) {
                    $expected_cash = 0;
                    $error = "Could not calculate expected cash from payments.";
                }
            }

            /*
            |----------------------------------------------------------------------
            | Returns
            |--------------------------------------------------------------------------
            | Deduct completed returns from expected cash.
            */
            $return_cash = 0;

            try {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(
                        SUM(
                            ri.quantity * COALESCE(p.selling_price, 0)
                        ), 0
                    )
                    FROM return_items ri
                    INNER JOIN returns r ON ri.return_id = r.id
                    INNER JOIN products p ON ri.product_id = p.id
                    WHERE r.created_at >= :from_date
                      AND r.created_at <= :to_date
                      AND p.business_id = :business_id
                      AND (
                          r.status IS NULL
                          OR LOWER(r.status) IN
                          ('completed','complete','approved','processed')
                      )
                ");

                $stmt->execute([
                    ":business_id" => $business_id,
                    ":from_date"   => $from_dt,
                    ":to_date"     => $to_dt
                ]);

                $return_cash = (float)($stmt->fetchColumn() ?? 0);

            } catch (PDOException $e) {
                $return_cash = 0;
            }

            $expected_cash = max(0, $expected_cash - $return_cash);
            $physical_cash = (float)$physical;
            $difference    = $physical_cash - $expected_cash;

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_cash_reconciliations
                        (
                            business_id,
                            from_date,
                            to_date,
                            expected_cash,
                            physical_cash,
                            difference,
                            notes,
                            created_by
                        )
                    VALUES
                        (
                            :business_id,
                            :from_date,
                            :to_date,
                            :expected_cash,
                            :physical_cash,
                            :difference,
                            :notes,
                            :created_by
                        )
                    ON DUPLICATE KEY UPDATE
                        expected_cash = VALUES(expected_cash),
                        physical_cash = VALUES(physical_cash),
                        difference    = VALUES(difference),
                        notes         = VALUES(notes),
                        created_by    = VALUES(created_by)
                ");

                $stmt->execute([
                    ":business_id"  => $business_id,
                    ":from_date"    => $post_from,
                    ":to_date"      => $post_to,
                    ":expected_cash"=> $expected_cash,
                    ":physical_cash"=> $physical_cash,
                    ":difference"   => $difference,
                    ":notes"        => $notes !== "" ? $notes : null,
                    ":created_by"   => $admin_id
                ]);

                header(
                    "Location: inventory.php?recon_from=" .
                    urlencode($post_from) .
                    "&recon_to=" .
                    urlencode($post_to) .
                    "&cash_saved=1"
                );
                exit;

            } catch (PDOException $e) {
                $error = "Could not save the physical cash reconciliation.";
            }
        }
    }
}

if (isset($_GET["cash_saved"])) {
    $message = "Physical cash reconciliation saved successfully.";
}

/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.business_id = :business_id
      AND p.is_active = 1
    ORDER BY p.stock_quantity ASC
");
$stmt->execute([":business_id" => $business_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CURRENT INVENTORY FINANCIAL VALUES
|--------------------------------------------------------------------------
*/
$total_stock_buying_value  = 0;
$total_stock_selling_value = 0;
$total_estimated_profit    = 0;

/*
|==========================================================================
| WHOLESALE & RETAIL PROFIT ANALYSIS
|==========================================================================
*/
$total_retail_profit_potential = 0;
$total_wholesale_profit_potential = 0;
$total_retail_value = 0;
$total_wholesale_value = 0;
$products_with_wholesale = 0;

foreach ($products as &$product) {

    $stock = (float)($product["stock_quantity"] ?? 0);
    $buying_price = (float)($product["buying_price"] ?? 0);
    $selling_price = (float)($product["selling_price"] ?? 0);
    $wholesale_price = (float)($product["wholesale_price"] ?? 0);

    // Existing calculations
    $product["stock_buying_value"]  = $stock * $buying_price;
    $product["stock_selling_value"] = $stock * $selling_price;
    $product["estimated_profit"] =
        $product["stock_selling_value"] -
        $product["stock_buying_value"];

    // Retail profit per unit
    $retail_profit_per_unit = $selling_price - $buying_price;
    $product["retail_profit_per_unit"] = $retail_profit_per_unit;

    // Retail profit potential from current stock
    $retail_profit_potential = $stock * $retail_profit_per_unit;
    $product["retail_profit_potential"] = $retail_profit_potential;

    // Wholesale profit per unit (only if wholesale price exists)
    if ($wholesale_price > 0) {
        $wholesale_profit_per_unit = $wholesale_price - $buying_price;
        $wholesale_profit_potential = $stock * $wholesale_profit_per_unit;
        $wholesale_value = $stock * $wholesale_price;
        $products_with_wholesale++;
    } else {
        $wholesale_profit_per_unit = 0;
        $wholesale_profit_potential = 0;
        $wholesale_value = 0;
    }

    $product["wholesale_profit_per_unit"] = $wholesale_profit_per_unit;
    $product["wholesale_profit_potential"] = $wholesale_profit_potential;
    $product["wholesale_value"] = $wholesale_value;
    $product["retail_value"] = $stock * $selling_price;

    // Add to totals
    $total_retail_profit_potential += $retail_profit_potential;
    $total_wholesale_profit_potential += $wholesale_profit_potential;
    $total_wholesale_value += $wholesale_value;
    $total_retail_value += $product["retail_value"];

    // Existing totals
    $total_stock_buying_value  += $product["stock_buying_value"];
    $total_stock_selling_value += $product["stock_selling_value"];
    $total_estimated_profit    += $product["estimated_profit"];
}
unset($product);

$total_profit_margin = $total_stock_selling_value > 0
    ? ($total_estimated_profit / $total_stock_selling_value) * 100
    : 0;

// Retail and wholesale profit margins
$retail_profit_margin = $total_retail_value > 0
    ? ($total_retail_profit_potential / $total_retail_value) * 100
    : 0;

$wholesale_profit_margin = $total_wholesale_value > 0
    ? ($total_wholesale_profit_potential / $total_wholesale_value) * 100
    : 0;

/*
|--------------------------------------------------------------------------
| BASIC SUMMARY
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_products,
        COUNT(DISTINCT category_id) AS total_categories,
        SUM(
            CASE
                WHEN stock_quantity <= reorder_level
                 AND stock_quantity > 0
                THEN 1 ELSE 0
            END
        ) AS low_stock,
        SUM(
            CASE
                WHEN stock_quantity <= 0
                THEN 1 ELSE 0
            END
        ) AS out_of_stock
    FROM products
    WHERE business_id = :business_id
      AND is_active = 1
");
$stmt->execute([":business_id" => $business_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RECONCILIATION DEFAULTS
|--------------------------------------------------------------------------
*/
$recon_summary = [
    "total_stock_sold"      => 0,
    "sales_value"           => 0,
    "cost_of_stock_sold"    => 0,
    "gross_profit"          => 0,
    "remaining_stock_value" => 0,
    "actual_collected"      => 0,
    "return_value"          => 0,
    "net_sales"             => 0,
    "expected_actual_diff"  => 0
];

$recon_products = [];
$sales_by_product = [];
$returns_by_product = [];
$adjustments_by_product = [];

if ($is_admin) {

    /*
    |--------------------------------------------------------------------------
    | COMPLETED SALES BY PRODUCT
    |--------------------------------------------------------------------------
    | Stock sold comes directly from sale_items, never from
    | starting_stock - current_stock.
    |
    | The supplied schema uses sales.sale_date and sales.sale_status,
    | and sale_items.unit_price / buying_price.
    */
    try {
        $stmt = $pdo->prepare("
            SELECT
                si.product_id,
                SUM(si.quantity) AS quantity_sold,
                SUM(COALESCE(si.total, si.quantity * si.unit_price)) AS sales_value,
                SUM(si.quantity * si.buying_price) AS cost_value
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            INNER JOIN products p ON si.product_id = p.id
            WHERE s.business_id = :business_id
              AND s.sale_date >= :from_date
              AND s.sale_date <= :to_date
              AND LOWER(COALESCE(s.sale_status, '')) = 'completed'
            GROUP BY si.product_id
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date"   => $recon_start_datetime,
            ":to_date"     => $recon_end_datetime
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row["product_id"];

            $sales_by_product[$id] = [
                "quantity"   => (float)($row["quantity_sold"] ?? 0),
                "sales_value"=> (float)($row["sales_value"] ?? 0),
                "cost_value" => (float)($row["cost_value"] ?? 0)
            ];
        }
    } catch (PDOException $e) {
        $sales_by_product = [];
    }

    /*
    |--------------------------------------------------------------------------
    | COMPLETED RETURNS BY PRODUCT
    |--------------------------------------------------------------------------
    | Product quantities and refund values come from return_items / returns.
    | The supplied schema stores the return status/date on returns.
    */
    try {
        $stmt = $pdo->prepare("
            SELECT
                ri.product_id,
                SUM(ri.quantity) AS quantity_returned,
                SUM(ri.total) AS return_value
            FROM return_items ri
            INNER JOIN returns r ON ri.return_id = r.id
            INNER JOIN products p ON ri.product_id = p.id
            WHERE r.business_id = :business_id
              AND r.created_at >= :from_date
              AND r.created_at <= :to_date
              AND LOWER(COALESCE(r.status, '')) IN
                  ('completed','complete','approved','processed')
            GROUP BY ri.product_id
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date"   => $recon_start_datetime,
            ":to_date"     => $recon_end_datetime
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row["product_id"];

            $returns_by_product[$id] = [
                "quantity"    => (float)($row["quantity_returned"] ?? 0),
                "return_value"=> (float)($row["return_value"] ?? 0)
            ];
        }
    } catch (PDOException $e) {
        $returns_by_product = [];
    }

    /*
    |--------------------------------------------------------------------------
    | STOCK ADJUSTMENTS
    |--------------------------------------------------------------------------
    | "Stock Added" and "Stock Removed" are read from the adjustment notes,
    | preserving the existing BizFlow stock-adjustment convention.
    */
    try {
        $stmt = $pdo->prepare("
            SELECT
                sm.product_id,
                sm.quantity,
                sm.notes,
                sm.created_at
            FROM stock_movements sm
            INNER JOIN products p ON sm.product_id = p.id
            WHERE sm.business_id = :business_id
              AND sm.movement_type = 'adjustment'
              AND sm.created_at >= :from_date
              AND sm.created_at <= :to_date
            ORDER BY sm.created_at ASC
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date"   => $recon_start_datetime,
            ":to_date"     => $recon_end_datetime
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row["product_id"];

            if (!isset($adjustments_by_product[$id])) {
                $adjustments_by_product[$id] = [
                    "added"   => 0,
                    "removed" => 0
                ];
            }

            $q = abs((float)($row["quantity"] ?? 0));
            $note = strtolower(trim($row["notes"] ?? ""));

            if (strpos($note, "stock added") === 0) {
                $adjustments_by_product[$id]["added"] += $q;
            } elseif (strpos($note, "stock removed") === 0) {
                $adjustments_by_product[$id]["removed"] += $q;
            }
        }
    } catch (PDOException $e) {
        $adjustments_by_product = [];
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUAL COLLECTED FROM SALES
    |--------------------------------------------------------------------------
    | Actual Collected = cash_amount - change_given + bank_amount
    | This gives the true money collected for each sale.
    */
    $actual_collected = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(cash_amount - change_given + bank_amount), 0) AS total_collected
            FROM sales s
            WHERE s.business_id = :business_id
              AND s.sale_status = 'completed'
              AND s.sale_date >= :from_date
              AND s.sale_date <= :to_date
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date"   => $recon_start_datetime,
            ":to_date"     => $recon_end_datetime
        ]);

        $actual_collected = (float)($stmt->fetchColumn() ?? 0);

    } catch (PDOException $e) {
        $actual_collected = 0;
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUAL REFUNDS / RETURNS MONEY
    |--------------------------------------------------------------------------
    | returns.refund_amount is the actual refund amount recorded for the
    | completed return. Do not estimate refunds from current product prices.
    */
    $total_return_value = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(r.refund_amount), 0)
            FROM returns r
            WHERE r.business_id = :business_id
              AND r.created_at >= :from_date
              AND r.created_at <= :to_date
              AND LOWER(COALESCE(r.status, '')) IN
                  ('completed','complete','approved','processed')
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date"   => $recon_start_datetime,
            ":to_date"     => $recon_end_datetime
        ]);

        $total_return_value = (float)($stmt->fetchColumn() ?? 0);
    } catch (PDOException $e) {
        /*
         * If refund_amount cannot be queried, fall back to the sum of the
         * completed return_items totals so the page remains useful.
         */
        foreach ($returns_by_product as $return_row) {
            $total_return_value += (float)($return_row["return_value"] ?? 0);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCT RECONCILIATION
    |--------------------------------------------------------------------------
    | Starting stock is reconstructed from the current stock using the
    | actual period transactions:
    |
    | Starting = Current - Added + Removed + Sold - Returned
    |
    | Stock sold itself is ALWAYS taken from completed sale_items.
    */
    foreach ($products as &$product) {

        $id = (int)$product["id"];

        $current_stock = (float)($product["stock_quantity"] ?? 0);
        $selling_price = (float)($product["selling_price"] ?? 0);
        $buying_price  = (float)($product["buying_price"] ?? 0);

        $stock_sold = (float)(
            $sales_by_product[$id]["quantity"] ?? 0
        );

        $sales_value = (float)(
            $sales_by_product[$id]["sales_value"]
            ?? ($stock_sold * $selling_price)
        );

        /*
         * Prefer the buying price captured on each sale_item. This prevents
         * later product-price edits from changing historical gross profit.
         */
        $cost_of_stock_sold = (float)(
            $sales_by_product[$id]["cost_value"]
            ?? ($stock_sold * $buying_price)
        );

        $stock_returned = (float)(
            $returns_by_product[$id]["quantity"] ?? 0
        );

        $return_value = (float)(
            $returns_by_product[$id]["return_value"] ?? 0
        );

        $stock_added = (float)(
            $adjustments_by_product[$id]["added"] ?? 0
        );

        $stock_removed = (float)(
            $adjustments_by_product[$id]["removed"] ?? 0
        );

        /*
         * This is the stock quantity at the beginning of the selected
         * period. It is not used to calculate stock sold.
         */
        $starting_stock =
            $current_stock
            - $stock_added
            + $stock_removed
            + $stock_sold
            - $stock_returned;

        if ($starting_stock < 0 && $starting_stock > -0.0001) {
            $starting_stock = 0;
        }

        $remaining_stock_value =
            $current_stock * $selling_price;

        $gross_profit =
            $sales_value - $cost_of_stock_sold;

        $recon_summary["total_stock_sold"] += $stock_sold;
        $recon_summary["sales_value"] += $sales_value;
        $recon_summary["cost_of_stock_sold"] += $cost_of_stock_sold;
        $recon_summary["gross_profit"] += $gross_profit;
        $recon_summary["remaining_stock_value"] += $remaining_stock_value;

        $product["reconciliation"] = [
            "starting_stock"         => $starting_stock,
            "stock_added"            => $stock_added,
            "stock_removed"          => $stock_removed,
            "stock_sold"             => $stock_sold,
            "stock_returned"         => $stock_returned,
            "remaining_stock"        => $current_stock,
            "sales_value"            => $sales_value,
            "return_value"           => $return_value,
            "remaining_stock_value"  => $remaining_stock_value,
            "cost_of_stock_sold"     => $cost_of_stock_sold,
            "gross_profit"           => $gross_profit
        ];

        $recon_products[] = $product;
    }
    unset($product);

    // Net Sales = Sales Value - Returns Value
    $net_sales = $recon_summary["sales_value"] - $total_return_value;

    $recon_summary["net_sales"] = $net_sales;
    $recon_summary["actual_collected"] = $actual_collected;
    $recon_summary["return_value"] = $total_return_value;

    /*
     * Expected vs Actual Difference = Actual Collected - Net Sales
     * Positive means more money was collected than net sales.
     * Negative means less money was collected than net sales.
     */
    $recon_summary["expected_actual_diff"] =
        $actual_collected - $net_sales;
}

/*
|--------------------------------------------------------------------------
| LOAD SAVED PHYSICAL CASH RECONCILIATION
|--------------------------------------------------------------------------
*/
$saved_cash_recon = null;
$cash_expected_for_form = $recon_summary["net_sales"];

if ($is_admin) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                cr.*,
                u.full_name AS created_by_name
            FROM inventory_cash_reconciliations cr
            LEFT JOIN users u ON cr.created_by = u.id
            WHERE cr.business_id = :business_id
              AND cr.from_date = :from_date
              AND cr.to_date = :to_date
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":from_date" => $recon_from,
            ":to_date" => $recon_to
        ]);

        $saved_cash_recon = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($saved_cash_recon) {
            $cash_expected_for_form =
                (float)$saved_cash_recon["expected_cash"];
        }

    } catch (PDOException $e) {
        $saved_cash_recon = null;
    }
}

/*
|--------------------------------------------------------------------------
| CASH RECONCILIATION HISTORY
|--------------------------------------------------------------------------
*/
$cash_recon_history = [];

if ($is_admin) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                cr.*,
                u.full_name AS created_by_name
            FROM inventory_cash_reconciliations cr
            LEFT JOIN users u ON cr.created_by = u.id
            WHERE cr.business_id = :business_id
            ORDER BY cr.from_date DESC, cr.id DESC
            LIMIT 24
        ");

        $stmt->execute([":business_id" => $business_id]);
        $cash_recon_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $cash_recon_history = [];
    }
}

/*
|--------------------------------------------------------------------------
| STOCK ADJUSTMENT HISTORY WITH FINANCIAL VALUES
|--------------------------------------------------------------------------
*/
$stock_history = [];

if ($is_admin) {
    $stmt = $pdo->prepare("
        SELECT
            sm.id,
            sm.product_id,
            sm.user_id,
            sm.movement_type,
            sm.quantity,
            sm.reference_id,
            sm.notes,
            sm.created_at,
            p.name AS product_name,
            p.unit AS product_unit,
            p.buying_price,
            p.selling_price,
            u.full_name AS user_name
        FROM stock_movements sm
        INNER JOIN products p ON sm.product_id = p.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE sm.business_id = :business_id
          AND sm.movement_type = 'adjustment'
        ORDER BY sm.created_at DESC
        LIMIT 100
    ");

    $stmt->execute([":business_id" => $business_id]);
    $stock_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory - BizFlow</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.inventory-financial-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin:20px 0 25px}
.inventory-financial-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px}
.inventory-financial-title{color:#6b7280;font-size:14px;margin-bottom:8px}
.inventory-financial-value{font-size:24px;font-weight:700}
.inventory-buying-value{color:#374151}.inventory-selling-value{color:#2563eb}.inventory-profit-value{color:#15803d}.inventory-margin-value{color:#7c3aed}
.inventory-wholesale-value{color:#d97706}.inventory-retail-value{color:#2563eb}
.inventory-search{margin:20px 0 15px;display:flex;justify-content:flex-end}
.inventory-search input{width:350px;max-width:100%;padding:12px 15px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;outline:none}
.inventory-search input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.inventory-financial{text-align:right;white-space:nowrap}.profit-positive{color:#15803d;font-weight:700}.profit-negative{color:#dc2626;font-weight:700}
.inventory-total-row{background:#f9fafb;font-weight:700;border-top:2px solid #d1d5db}

.stock-history-section,.recon-section{margin:25px 0;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;scroll-margin-top:90px}
.stock-history-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.stock-history-header h2,.recon-section h2{margin-top:0}
.stock-history-header p{margin:4px 0 0;color:#6b7280;font-size:13px}
.history-table,.recon-table{width:100%;border-collapse:collapse}
.history-table th,.history-table td{padding:10px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:13px;vertical-align:middle}
.history-table th{background:#f9fafb;color:#4b5563}
.history-date{white-space:nowrap;color:#374151;font-weight:500}
.history-added{color:#15803d;font-weight:700}.history-removed{color:#dc2626;font-weight:700}.history-adjustment{color:#7c3aed;font-weight:700}
.history-notes{color:#6b7280;max-width:300px;word-break:break-word}
.history-badge{display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700}
.history-badge.added{background:#dcfce7;color:#166534}.history-badge.removed{background:#fee2e2;color:#991b1b}.history-badge.adjustment{background:#ede9fe;color:#6d28d9}
.history-empty{text-align:center;padding:30px;color:#6b7280}

.recon-section h2{font-size:18px;color:#111827;border-bottom:2px solid #e5e7eb;padding-bottom:10px}
.recon-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:15px;margin:15px 0}
.recon-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px}
.recon-card .label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase}.recon-card .value{font-size:18px;font-weight:700;margin-top:4px;word-break:break-word}.recon-card .sub{font-size:11px;color:#6b7280;margin-top:3px}
.recon-filter{display:flex;flex-wrap:wrap;align-items:end;gap:12px;background:#f9fafb;padding:14px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:18px}
.recon-filter-group{display:flex;flex-direction:column;gap:5px}.recon-filter-group label{font-size:12px;color:#4b5563;font-weight:600}
.recon-filter-group input{padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff}
.recon-filter button,.cash-save-btn{padding:10px 16px;border:none;border-radius:6px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer}
.recon-filter button:hover,.cash-save-btn:hover{background:#1d4ed8}
.recon-reset{display:inline-flex;align-items:center;padding:9px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px}
.recon-table-wrap{overflow-x:auto;margin-top:18px}.recon-table{font-size:12px;min-width:1250px}
.recon-table th{background:#f3f4f6;padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap}
.recon-table td{padding:9px 10px;border-bottom:1px solid #f3f4f6;white-space:nowrap}.recon-table .text-right{text-align:right}.recon-table .total-row{background:#f3f4f6;font-weight:700}
.recon-positive{color:#15803d;font-weight:700}.recon-negative{color:#dc2626;font-weight:700}
.value-green{color:#16a34a}.value-red{color:#dc2626}.value-blue{color:#2563eb}.value-orange{color:#d97706}.value-purple{color:#7c3aed}.value-dark{color:#111827}
.recon-info,.recon-danger-info{margin-top:14px;padding:11px 14px;border-radius:7px;font-size:12px;line-height:1.5}
.recon-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}.recon-danger-info{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}

.cash-reconciliation{margin-top:20px;border:2px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:18px}
.cash-reconciliation h3{margin:0 0 6px;font-size:17px}.cash-reconciliation p{color:#6b7280;font-size:12px;margin:0 0 15px}
.cash-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.cash-box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:13px}.cash-box .label{font-size:11px;text-transform:uppercase;color:#6b7280;font-weight:700}.cash-box .value{font-size:20px;font-weight:700;margin-top:4px}
.cash-input-wrap{margin-top:15px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cash-input-wrap label{font-size:12px;font-weight:700;color:#374151}.cash-input-wrap input,.cash-input-wrap textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:6px;margin-top:5px}.cash-input-wrap textarea{min-height:70px;resize:vertical}
.cash-status{margin-top:12px;padding:10px;border-radius:7px;font-size:13px;font-weight:700}
.cash-status.shortage{background:#fee2e2;color:#991b1b}.cash-status.surplus{background:#dcfce7;color:#166534}.cash-status.balanced{background:#e0f2fe;color:#075985}
.cash-history{margin-top:22px;overflow-x:auto}.cash-history table{width:100%;border-collapse:collapse;font-size:12px}.cash-history th,.cash-history td{padding:9px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}.cash-history th{background:#f3f4f6}
.alert-success{padding:11px 14px;background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:7px;margin:15px 0}.alert-error{padding:11px 14px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:7px;margin:15px 0}
.financial-mini{font-size:11px;color:#6b7280;margin-top:3px}.financial-added{color:#15803d;font-weight:700}.financial-removed{color:#dc2626;font-weight:700}

/* Wholesale/Retail Profit Analysis Styles */
.profit-analysis-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin: 25px 0 20px;
}
.profit-analysis-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 18px 20px;
}
.profit-analysis-card .label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.profit-analysis-card .value {
    font-size: 22px;
    font-weight: 700;
    margin-top: 4px;
}
.profit-analysis-card .sub {
    font-size: 12px;
    color: #6b7280;
    margin-top: 3px;
}
.profit-analysis-card .wholesale-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #ede9fe;
    color: #5b21b6;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    margin-top: 4px;
}
.profit-analysis-card .retail-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    margin-top: 4px;
}
.color-wholesale { color: #7c3aed; }
.color-retail { color: #2563eb; }

.wholesale-col {
    color: #7c3aed;
    font-weight: 600;
}
.wholesale-col-na {
    color: #9ca3af;
    font-style: italic;
}

@media(max-width:1100px){
    .inventory-financial-cards{grid-template-columns:repeat(2,1fr)}
    .cash-grid{grid-template-columns:1fr 1fr}
    .profit-analysis-cards{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:700px){
    .inventory-financial-cards{grid-template-columns:1fr}
    .profit-analysis-cards{grid-template-columns:1fr}
    .inventory-search{justify-content:stretch}
    .inventory-search input{width:100%}
    .stock-history-section,.recon-section{padding:12px;overflow-x:auto}
    .history-table{min-width:1050px}
    .recon-filter{align-items:stretch;flex-direction:column}
    .recon-filter-group input{width:100%;box-sizing:border-box}
    .recon-filter button,.recon-reset{width:100%;justify-content:center;box-sizing:border-box}
    .cash-grid,.cash-input-wrap{grid-template-columns:1fr}
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
        <h1>Inventory Management</h1>
        <p>Manage your products, stock and inventory levels.</p>
    </div>

    <a href="adjust_stock.php" class="btn-primary">+ Adjust Stock</a>
</div>

<?php if ($message): ?>
<div class="alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="cards" style="grid-template-columns:repeat(4,1fr);">

    <div class="card">
        <div class="card-title">Total Products</div>
        <div class="card-value"><?= (int)($summary["total_products"] ?? 0) ?></div>
    </div>

    <div class="card">
        <div class="card-title">Categories</div>
        <div class="card-value"><?= (int)($summary["total_categories"] ?? 0) ?></div>
    </div>

    <div class="card" style="border-color:#f59e0b;">
        <div class="card-title">Low Stock</div>
        <div class="card-value" style="color:#f59e0b;">
            <?= (int)($summary["low_stock"] ?? 0) ?>
        </div>
    </div>

    <div class="card" style="border-color:#ef4444;">
        <div class="card-title">Out of Stock</div>
        <div class="card-value" style="color:#ef4444;">
            <?= (int)($summary["out_of_stock"] ?? 0) ?>
        </div>
    </div>

</div>

<?php if ($is_admin): ?>

<!--
|==========================================================================
| WHOLESALE & RETAIL PROFIT ANALYSIS
|==========================================================================
-->
<div style="margin: 15px 0 5px;">
    <h2 style="font-size:18px; color:#111827; margin:0 0 5px;">🏪 Retail vs 📦 Wholesale Profit Analysis</h2>
    <p style="color:#6b7280; font-size:13px; margin:0;">
        Expected profit potential from selling current stock at retail vs wholesale prices.
        <?php if ($products_with_wholesale > 0): ?>
            <strong><?= $products_with_wholesale ?></strong> product(s) have wholesale pricing.
        <?php else: ?>
            No products currently have wholesale pricing configured.
        <?php endif; ?>
    </p>
</div>

<div class="profit-analysis-cards">

    <div class="profit-analysis-card" style="border-color: #2563eb;">
        <div class="label">🏪 Total Retail Value</div>
        <div class="value color-retail"><?= money($total_retail_value) ?></div>
        <div class="sub">If all sold at retail price</div>
        <span class="retail-badge">Retail</span>
    </div>

    <div class="profit-analysis-card" style="border-color: #7c3aed;">
        <div class="label">📦 Total Wholesale Value</div>
        <div class="value color-wholesale"><?= money($total_wholesale_value) ?></div>
        <div class="sub">If all sold at wholesale price</div>
        <span class="wholesale-badge">Wholesale</span>
    </div>

    <div class="profit-analysis-card" style="border-color: #16a34a;">
        <div class="label">📈 Retail Profit Potential</div>
        <div class="value profit-positive"><?= money($total_retail_profit_potential) ?></div>
        <div class="sub"><?= $total_retail_value > 0 ? number_format($retail_profit_margin, 1) . '% margin' : 'No stock' ?></div>
        <span class="retail-badge">Retail</span>
    </div>

    <div class="profit-analysis-card" style="border-color: #d97706;">
        <div class="label">📦 Wholesale Profit Potential</div>
        <div class="value inventory-wholesale-value"><?= money($total_wholesale_profit_potential) ?></div>
        <div class="sub"><?= $total_wholesale_value > 0 ? number_format($wholesale_profit_margin, 1) . '% margin' : 'No wholesale price' ?></div>
        <span class="wholesale-badge">Wholesale</span>
    </div>

</div>

<!-- Profit Difference Summary -->
<?php if ($products_with_wholesale > 0): ?>
<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px;">
    <strong>📊 Profit Difference:</strong>
    Retail profit is <strong><?= money(abs($total_retail_profit_potential - $total_wholesale_profit_potential)) ?></strong>
    <?= $total_retail_profit_potential > $total_wholesale_profit_potential ? 'higher' : 'lower' ?>
    than wholesale profit potential.
    <?php if ($total_retail_profit_potential > $total_wholesale_profit_potential): ?>
        🏪 Retail offers better profit margin.
    <?php elseif ($total_wholesale_profit_potential > $total_retail_profit_potential): ?>
        📦 Wholesale offers better profit margin.
    <?php else: ?>
        ⚖️ Both channels offer equal profit potential.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="recon-section" id="reconciliation">

    <h2>📊 Stock & Money Reconciliation</h2>

    <p style="color:#6b7280;font-size:13px;">
        Check stock sold, returns, inventory value, sales money and the
        physical cash counted for a selected period.
    </p>

    <form method="GET" class="recon-filter">

        <input type="hidden" name="section" value="reconciliation">

        <div class="recon-filter-group">
            <label for="recon_from">From Date</label>
            <input type="date" id="recon_from" name="recon_from"
                   value="<?= htmlspecialchars($recon_from) ?>" required>
        </div>

        <div class="recon-filter-group">
            <label for="recon_to">To Date</label>
            <input type="date" id="recon_to" name="recon_to"
                   value="<?= htmlspecialchars($recon_to) ?>" required>
        </div>

        <button type="submit">🔎 Reconcile</button>

        <a href="inventory.php" class="recon-reset">Reset</a>

    </form>

    <div class="recon-grid">

        <div class="recon-card">
            <div class="label">📦 Total Stock Sold</div>
            <div class="value value-blue"><?= qty($recon_summary["total_stock_sold"]) ?></div>
            <div class="sub">Completed sale items</div>
        </div>

        <div class="recon-card">
            <div class="label">💰 Sales Value</div>
            <div class="value value-orange"><?= money($recon_summary["sales_value"]) ?></div>
            <div class="sub">Selling value of stock sold</div>
        </div>

        <div class="recon-card">
            <div class="label">💸 Cost of Stock Sold</div>
            <div class="value value-red"><?= money($recon_summary["cost_of_stock_sold"]) ?></div>
            <div class="sub">Buying price × quantity sold</div>
        </div>

        <div class="recon-card">
            <div class="label">📈 Gross Profit</div>
            <div class="value <?= $recon_summary["gross_profit"] >= 0 ? "value-green" : "value-red" ?>">
                <?= money($recon_summary["gross_profit"]) ?>
            </div>
            <div class="sub">Sales value - cost</div>
        </div>

        <div class="recon-card">
            <div class="label">📦 Remaining Stock Value</div>
            <div class="value value-purple"><?= money($recon_summary["remaining_stock_value"]) ?></div>
            <div class="sub">Current stock × selling price</div>
        </div>

        <div class="recon-card">
            <div class="label">💵 Actual Collected</div>
            <div class="value value-blue"><?= money($recon_summary["actual_collected"]) ?></div>
            <div class="sub">Cash + bank - change</div>
        </div>

        <div class="recon-card">
            <div class="label">↩️ Returns Value</div>
            <div class="value value-red"><?= money($recon_summary["return_value"]) ?></div>
            <div class="sub">Completed returned stock</div>
        </div>

        <div class="recon-card">
            <div class="label">📊 Net Sales</div>
            <div class="value value-green"><?= money($recon_summary["net_sales"]) ?></div>
            <div class="sub">Sales value - returns</div>
        </div>

        <div class="recon-card">
            <div class="label">⚖️ Expected vs Actual Difference</div>
            <div class="value <?= $recon_summary["expected_actual_diff"] >= 0 ? "value-green" : "value-red" ?>">
                <?= money($recon_summary["expected_actual_diff"]) ?>
            </div>
            <div class="sub">Actual collected - net sales</div>
        </div>

    </div>

    <!-- PHYSICAL CASH -->
    <div class="cash-reconciliation">

        <h3>💵 Physical Cash Reconciliation</h3>

        <p>
            Enter the actual physical cash counted by the administrator.
            BizFlow saves the result for this exact date range.
        </p>

        <div class="cash-grid">

            <div class="cash-box">
                <div class="label">Expected Cash</div>
                <div class="value value-blue">
                    <?= money($cash_expected_for_form) ?>
                </div>
                <div class="financial-mini">
                    Net sales for this period
                </div>
            </div>

            <div class="cash-box">
                <div class="label">Physical Cash Counted</div>
                <div class="value value-purple">
                    <?= $saved_cash_recon
                        ? money($saved_cash_recon["physical_cash"])
                        : "Not entered"
                    ?>
                </div>
                <div class="financial-mini">
                    Cash physically counted by admin
                </div>
            </div>

            <div class="cash-box">
                <div class="label">Difference</div>
                <div class="value
                    <?php
                    if ($saved_cash_recon) {
                        echo (float)$saved_cash_recon["difference"] < 0
                            ? "value-red"
                            : "value-green";
                    }
                    ?>
                ">
                    <?= $saved_cash_recon
                        ? money($saved_cash_recon["difference"])
                        : "Not reconciled"
                    ?>
                </div>
                <div class="financial-mini">
                    Physical cash - expected cash
                </div>
            </div>

        </div>

        <?php if ($saved_cash_recon): ?>
            <?php
            $saved_diff = (float)$saved_cash_recon["difference"];
            $status_class = $saved_diff < -0.009
                ? "shortage"
                : ($saved_diff > 0.009 ? "surplus" : "balanced");

            $status_text = $saved_diff < -0.009
                ? "⚠️ SHORTAGE: Physical cash is below expected cash by " . money(abs($saved_diff))
                : ($saved_diff > 0.009
                    ? "✅ SURPLUS: Physical cash is above expected cash by " . money($saved_diff)
                    : "✅ BALANCED: Physical cash matches expected cash.");
            ?>
            <div class="cash-status <?= $status_class ?>">
                <?= htmlspecialchars($status_text) ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="margin-top:15px;">

            <input type="hidden" name="action" value="save_cash_reconciliation">
            <input type="hidden" name="recon_from" value="<?= htmlspecialchars($recon_from) ?>">
            <input type="hidden" name="recon_to" value="<?= htmlspecialchars($recon_to) ?>">

            <div class="cash-input-wrap">

                <div>
                    <label for="physical_cash">Actual Physical Cash Counted (KSh)</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        id="physical_cash"
                        name="physical_cash"
                        required
                        value="<?= $saved_cash_recon
                            ? htmlspecialchars($saved_cash_recon["physical_cash"])
                            : ""
                        ?>"
                        placeholder="e.g. 450000.00"
                    >
                </div>

                <div>
                    <label for="cash_notes">Admin Notes</label>
                    <textarea
                        id="cash_notes"
                        name="cash_notes"
                        placeholder="Optional explanation for shortage, surplus or cash count..."
                    ><?= htmlspecialchars($saved_cash_recon["notes"] ?? "") ?></textarea>
                </div>

            </div>

            <button type="submit" class="cash-save-btn" style="margin-top:12px;">
                💾 Save Physical Cash Count
            </button>

        </form>

        <div class="recon-info">
            <strong>How it works:</strong>
            Expected Cash is the net sales for the selected period.
            After the administrator physically counts the cash, enter that
            amount above. BizFlow stores the count and calculates the
            shortage or surplus automatically.
        </div>

    </div>

    <!-- CASH HISTORY -->
    <div class="cash-history">

        <h3>📅 Previous Physical Cash Reconciliations</h3>

        <?php if ($cash_recon_history): ?>

        <table>

            <thead>
                <tr>
                    <th>Period</th>
                    <th>Expected Cash</th>
                    <th>Physical Cash</th>
                    <th>Difference</th>
                    <th>Status</th>
                    <th>Recorded By</th>
                    <th>Saved At</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($cash_recon_history as $cash): ?>

                <?php
                $d = (float)$cash["difference"];
                $cash_status = $d < -0.009
                    ? "SHORTAGE"
                    : ($d > 0.009 ? "SURPLUS" : "BALANCED");

                $cash_class = $d < -0.009
                    ? "recon-negative"
                    : "recon-positive";
                ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($cash["from_date"]) ?>
                        →
                        <?= htmlspecialchars($cash["to_date"]) ?>
                    </td>

                    <td><?= money($cash["expected_cash"]) ?></td>

                    <td><?= money($cash["physical_cash"]) ?></td>

                    <td class="<?= $cash_class ?>">
                        <?= money($d) ?>
                    </td>

                    <td class="<?= $cash_class ?>">
                        <?= $cash_status ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($cash["created_by_name"] ?? "Unknown User") ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            date("d/m/Y H:i", strtotime($cash["created_at"]))
                        ) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <?php else: ?>

            <div class="history-empty">
                No physical cash reconciliations have been saved yet.
            </div>

        <?php endif; ?>

    </div>

    <div class="recon-info">

        <strong>Period:</strong>
        <?= htmlspecialchars($recon_from) ?>
        to
        <?= htmlspecialchars($recon_to) ?>

        <br>

        <strong>Stock Sold:</strong>
        calculated from completed sales.

        <br>

        <strong>Returns:</strong>
        calculated from completed returns.

        <br>

        <strong>Important:</strong>
        The physical cash section is intended for money actually counted
        as cash. If your payment table contains M-Pesa/card transactions,
        those should not be treated as physical cash.

    </div>

    <div class="recon-table-wrap">

        <table class="recon-table">

            <thead>
                <tr>
                    <th>Product</th>
                    <th>Starting Stock</th>
                    <th>Stock Added</th>
                    <th>Stock Removed</th>
                    <th>Stock Sold</th>
                    <th>Stock Returned</th>
                    <th>Remaining</th>
                    <th>Sales Value</th>
                    <th>Remaining Value</th>
                    <th>Cost Sold</th>
                    <th>Gross Profit</th>
                    <th>Expected vs Actual</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($recon_products): ?>

                <?php foreach ($recon_products as $rp): ?>

                    <?php
                    $rec = $rp["reconciliation"];
                    $allocated_actual = 0;

                    if ($recon_summary["sales_value"] > 0) {
                        $allocated_actual =
                            ($rec["sales_value"] / $recon_summary["sales_value"])
                            * $recon_summary["actual_collected"];
                    }

                    $product_difference =
                        $allocated_actual - $rec["sales_value"];
                    ?>

                    <tr>

                        <td>
                            <strong><?= htmlspecialchars($rp["name"]) ?></strong>
                            <br>
                            <small style="color:#9ca3af;">
                                <?= htmlspecialchars($rp["unit"] ?? "") ?>
                            </small>
                        </td>

                        <td><?= qty($rec["starting_stock"]) ?></td>

                        <td class="recon-positive">+<?= qty($rec["stock_added"]) ?></td>

                        <td class="recon-negative">-<?= qty($rec["stock_removed"]) ?></td>

                        <td class="value-blue"><?= qty($rec["stock_sold"]) ?></td>

                        <td class="value-orange"><?= qty($rec["stock_returned"]) ?></td>

                        <td><strong><?= qty($rec["remaining_stock"]) ?></strong></td>

                        <td class="text-right"><?= money($rec["sales_value"]) ?></td>

                        <td class="text-right value-purple"><?= money($rec["remaining_stock_value"]) ?></td>

                        <td class="text-right value-red"><?= money($rec["cost_of_stock_sold"]) ?></td>

                        <td class="text-right">
                            <span class="<?= $rec["gross_profit"] >= 0 ? "recon-positive" : "recon-negative" ?>">
                                <?= money($rec["gross_profit"]) ?>
                            </span>
                        </td>

                        <td class="text-right">
                            <span class="<?= $product_difference >= 0 ? "recon-positive" : "recon-negative" ?>">
                                <?= money($product_difference) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <tr class="total-row">

                    <td>TOTAL</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>

                    <td><?= qty($recon_summary["total_stock_sold"]) ?></td>

                    <td>-</td>
                    <td>-</td>

                    <td class="text-right"><?= money($recon_summary["sales_value"]) ?></td>

                    <td class="text-right"><?= money($recon_summary["remaining_stock_value"]) ?></td>

                    <td class="text-right"><?= money($recon_summary["cost_of_stock_sold"]) ?></td>

                    <td class="text-right">
                        <span class="<?= $recon_summary["gross_profit"] >= 0 ? "recon-positive" : "recon-negative" ?>">
                            <?= money($recon_summary["gross_profit"]) ?>
                        </span>
                    </td>

                    <td class="text-right">
                        <span class="<?= $recon_summary["expected_actual_diff"] >= 0 ? "recon-positive" : "recon-negative" ?>">
                            <?= money($recon_summary["expected_actual_diff"]) ?>
                        </span>
                    </td>

                </tr>

            <?php else: ?>

                <tr>
                    <td colspan="12" style="text-align:center;padding:30px;color:#6b7280;">
                        No reconciliation data found for the selected date range.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <div class="recon-danger-info">
        <strong>⚠️ Important:</strong>
        The product-level Expected vs Actual column is only an allocation
        of transaction-level money. The <strong>Physical Cash Reconciliation</strong>
        above is the authoritative place to record the administrator's
        actual cash count.
    </div>

</div>

<?php endif; ?>

<?php if ($is_admin): ?>

<!--
|==========================================================================
| EXISTING INVENTORY FINANCIAL CARDS (PRESERVED)
|==========================================================================
-->
<div class="inventory-financial-cards">

    <div class="inventory-financial-card">
        <div class="inventory-financial-title">💰 Total Stock Buying Value</div>
        <div class="inventory-financial-value inventory-buying-value">
            <?= money($total_stock_buying_value) ?>
        </div>
    </div>

    <div class="inventory-financial-card">
        <div class="inventory-financial-title">🏷️ Potential Selling Value</div>
        <div class="inventory-financial-value inventory-selling-value">
            <?= money($total_stock_selling_value) ?>
        </div>
    </div>

    <div class="inventory-financial-card">
        <div class="inventory-financial-title">📈 Estimated Gross Profit</div>
        <div class="inventory-financial-value inventory-profit-value">
            <?= money($total_estimated_profit) ?>
        </div>
    </div>

    <div class="inventory-financial-card">
        <div class="inventory-financial-title">📊 Estimated Profit Margin</div>
        <div class="inventory-financial-value inventory-margin-value">
            <?= number_format($total_profit_margin, 2) ?>%
        </div>
    </div>

</div>

<?php endif; ?>

<div class="inventory-search">
    <input
        type="text"
        id="inventorySearch"
        placeholder="🔍 Search product or category..."
        autocomplete="off"
    >
</div>

<div class="table-container">

<table class="data-table">

<thead>
<tr>
    <th>Product</th>
    <th>Category</th>
    <th>Stock</th>

    <?php if ($is_admin): ?>
        <th class="inventory-financial">Buying Price</th>
        <th class="inventory-financial">Selling Price</th>
        <th class="inventory-financial">Wholesale Price</th>
        <th class="inventory-financial">Stock Value</th>
        <th class="inventory-financial">Potential Sales</th>
        <th class="inventory-financial">Est. Profit</th>
        <th class="inventory-financial">Retail Profit</th>
        <th class="inventory-financial">Wholesale Profit</th>
    <?php endif; ?>

    <th>Reorder Level</th>
    <th>Status</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($products as $product): ?>

<?php $stock = (float)$product["stock_quantity"]; ?>

<tr>

<td><?= htmlspecialchars($product["name"]) ?></td>

<td><?= htmlspecialchars($product["category_name"] ?? "Uncategorized") ?></td>

<td>
    <?= qty($stock) ?>
    <?= htmlspecialchars($product["unit"] ?? "") ?>
</td>

<?php if ($is_admin): ?>

<td class="inventory-financial"><?= money($product["buying_price"]) ?></td>

<td class="inventory-financial"><?= money($product["selling_price"]) ?></td>

<!-- WHOLESALE PRICE COLUMN -->
<td class="inventory-financial">
    <?php if ((float)$product["wholesale_price"] > 0): ?>
        <?= money($product["wholesale_price"]) ?>
        <?php if ((float)$product["wholesale_min_qty"] > 0): ?>
            <br><small style="color:#6b7280; font-size:10px;">
                Min: <?= qty($product["wholesale_min_qty"]) ?>
            </small>
        <?php endif; ?>
    <?php else: ?>
        <span style="color:#9ca3af;">N/A</span>
    <?php endif; ?>
</td>

<td class="inventory-financial"><?= money($product["stock_buying_value"]) ?></td>

<td class="inventory-financial"><?= money($product["stock_selling_value"]) ?></td>

<td class="inventory-financial">
    <span class="<?= $product["estimated_profit"] >= 0 ? "profit-positive" : "profit-negative" ?>">
        <?= money($product["estimated_profit"]) ?>
    </span>
</td>

<!-- RETAIL PROFIT COLUMN -->
<td class="inventory-financial">
    <?php
    $retail_profit_per_unit = $product["retail_profit_per_unit"] ?? 0;
    $retail_profit_potential = $product["retail_profit_potential"] ?? 0;
    ?>
    <span class="profit-positive">
        <?= money($retail_profit_per_unit) ?>/unit
    </span>
    <br>
    <small style="color:#6b7280; font-size:10px;">
        Potential: <?= money($retail_profit_potential) ?>
    </small>
</td>

<!-- WHOLESALE PROFIT COLUMN -->
<td class="inventory-financial">
    <?php
    $wholesale_price = (float)$product["wholesale_price"];
    $wholesale_profit_per_unit = $product["wholesale_profit_per_unit"] ?? 0;
    $wholesale_profit_potential = $product["wholesale_profit_potential"] ?? 0;
    ?>
    <?php if ($wholesale_price > 0): ?>
        <span class="profit-positive">
            <?= money($wholesale_profit_per_unit) ?>/unit
        </span>
        <br>
        <small style="color:#6b7280; font-size:10px;">
            Potential: <?= money($wholesale_profit_potential) ?>
        </small>
    <?php else: ?>
        <span style="color:#9ca3af;">N/A</span>
    <?php endif; ?>
</td>

<?php endif; ?>

<td>
    <?= qty($product["reorder_level"]) ?>
    <?= htmlspecialchars($product["unit"] ?? "") ?>
</td>

<td>

<?php if ($stock <= 0): ?>

<span class="badge badge-danger">Out of Stock</span>

<?php elseif ($stock <= (float)$product["reorder_level"]): ?>

<span class="badge badge-warning">Low Stock</span>

<?php else: ?>

<span class="badge badge-success">In Stock</span>

<?php endif; ?>

</td>

<td>
    <a href="adjust_stock.php?id=<?= (int)$product["id"] ?>" class="btn-edit">
        Adjust
    </a>
</td>

</tr>

<?php endforeach; ?>

<?php if ($is_admin): ?>

<tr class="inventory-total-row">

<td colspan="3">TOTAL INVENTORY</td>

<td class="inventory-financial">-</td>
<td class="inventory-financial">-</td>
<td class="inventory-financial">-</td>

<td class="inventory-financial"><?= money($total_stock_buying_value) ?></td>

<td class="inventory-financial"><?= money($total_stock_selling_value) ?></td>

<td class="inventory-financial">
    <span class="profit-positive"><?= money($total_estimated_profit) ?></span>
</td>

<!-- TOTAL RETAIL PROFIT -->
<td class="inventory-financial">
    <span class="profit-positive"><?= money($total_retail_profit_potential) ?></span>
</td>

<!-- TOTAL WHOLESALE PROFIT -->
<td class="inventory-financial">
    <span class="profit-positive"><?= money($total_wholesale_profit_potential) ?></span>
</td>

<td colspan="3">-</td>

</tr>

<?php endif; ?>

</tbody>
</table>
</div>

<?php if ($is_admin): ?>

<div class="stock-history-section">

<div class="stock-history-header">

    <div>
        <h2>📋 Stock Adjustment History</h2>
        <p>
            Every manual stock addition or reduction is shown with its
            current buying and selling values.
        </p>
    </div>

</div>

<?php if ($stock_history): ?>

<div style="overflow-x:auto;">

<table class="history-table">

<thead>
<tr>
    <th>Date & Time</th>
    <th>Product</th>
    <th>Action</th>
    <th>Quantity</th>
    <th>Buying Price</th>
    <th>Buying Value</th>
    <th>Selling Price</th>
    <th>Potential Sales</th>
    <th>Potential Profit</th>
    <th>Admin / User</th>
    <th>Notes</th>
</tr>
</thead>

<tbody>

<?php foreach ($stock_history as $movement): ?>

<?php

$history_note = trim($movement["notes"] ?? "");

if (stripos($history_note, "Stock Added") === 0) {
    $action_label = "Stock Added";
    $action_class = "added";
    $quantity_prefix = "+";
    $quantity_class = "history-added";
    $is_added = true;
} elseif (stripos($history_note, "Stock Removed") === 0) {
    $action_label = "Stock Removed";
    $action_class = "removed";
    $quantity_prefix = "-";
    $quantity_class = "history-removed";
    $is_added = false;
} else {
    $action_label = "Adjustment";
    $action_class = "adjustment";
    $quantity_prefix = "";
    $quantity_class = "history-adjustment";
    $is_added = true;
}

$movement_qty = abs((float)($movement["quantity"] ?? 0));
$buying_price = (float)($movement["buying_price"] ?? 0);
$selling_price = (float)($movement["selling_price"] ?? 0);

$buying_value = $movement_qty * $buying_price;
$selling_value = $movement_qty * $selling_price;

$potential_profit = $selling_value - $buying_value;

$movement_date = !empty($movement["created_at"])
    ? date("d/m/Y H:i:s", strtotime($movement["created_at"]))
    : "";

$user_name = !empty($movement["user_name"])
    ? $movement["user_name"]
    : "Unknown User";

$display_notes = $history_note;

foreach (["Stock Added | ", "Stock Removed | "] as $prefix) {
    if (stripos($display_notes, $prefix) === 0) {
        $display_notes = substr($display_notes, strlen($prefix));
        break;
    }
}

if ($display_notes === "Stock Added" || $display_notes === "Stock Removed") {
    $display_notes = "";
}

?>

<tr>

<td class="history-date">
    <?= htmlspecialchars($movement_date) ?>
</td>

<td>
    <strong><?= htmlspecialchars($movement["product_name"]) ?></strong>
</td>

<td>
    <span class="history-badge <?= $action_class ?>">
        <?= htmlspecialchars($action_label) ?>
    </span>
</td>

<td>
    <span class="<?= $quantity_class ?>">
        <?= $quantity_prefix . qty($movement_qty) ?>
        <?= htmlspecialchars($movement["product_unit"] ?? "") ?>
    </span>
</td>

<td><?= money($buying_price) ?></td>

<td>
    <span class="<?= $is_added ? "financial-added" : "financial-removed" ?>">
        <?= money($buying_value) ?>
    </span>
</td>

<td><?= money($selling_price) ?></td>

<td>
    <span class="<?= $is_added ? "financial-added" : "financial-removed" ?>">
        <?= money($selling_value) ?>
    </span>
</td>

<td>
    <span class="<?= $potential_profit >= 0 ? "profit-positive" : "profit-negative" ?>">
        <?= money($potential_profit) ?>
    </span>
</td>

<td><?= htmlspecialchars($user_name) ?></td>

<td class="history-notes">

<?php if ($display_notes !== ""): ?>

    <?= htmlspecialchars($display_notes) ?>

<?php else: ?>

    <span style="color:#9ca3af;">No note</span>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<?php else: ?>

<div class="history-empty">
    No stock adjustments have been recorded yet.
</div>

<?php endif; ?>

</div>

<?php endif; ?>

</section>
</main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("inventorySearch");
    const table = document.querySelector(".data-table");

    if (searchInput && table) {

        searchInput.addEventListener("input", function () {

            const value = this.value.toLowerCase().trim();

            table.querySelectorAll("tbody tr").forEach(function (row) {

                if (row.classList.contains("inventory-total-row")) {
                    return;
                }

                const product =
                    row.cells[0]
                    ? row.cells[0].textContent.toLowerCase()
                    : "";

                const category =
                    row.cells[1]
                    ? row.cells[1].textContent.toLowerCase()
                    : "";

                row.style.display =
                    product.includes(value) ||
                    category.includes(value)
                    ? ""
                    : "none";
            });
        });
    }

    const fromDate = document.getElementById("recon_from");
    const toDate = document.getElementById("recon_to");

    if (fromDate && toDate) {

        fromDate.addEventListener("change", function () {
            toDate.min = this.value;
        });

        toDate.addEventListener("change", function () {
            fromDate.max = this.value;
        });

        if (fromDate.value) {
            toDate.min = fromDate.value;
        }

        if (toDate.value) {
            fromDate.max = toDate.value;
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    const requestedSection = urlParams.get("section");

    if (requestedSection === "reconciliation") {
        const reconciliationSection =
            document.getElementById("reconciliation");

        if (reconciliationSection) {
            setTimeout(function () {
                reconciliationSection.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });
            }, 100);
        }
    }
});
</script>

</body>
</html>
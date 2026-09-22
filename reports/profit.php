```php
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("profit");

$business_id = (int) ($_SESSION["business_id"] ?? 0);

if ($business_id <= 0) {
    die("Invalid business account.");
}


/*
|--------------------------------------------------------------------------
| REPORT PERIOD
|--------------------------------------------------------------------------
*/

$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date   = $_GET['to'] ?? date('Y-m-d');


/*
|--------------------------------------------------------------------------
| VALIDATE DATES
|--------------------------------------------------------------------------
*/

$from_object = DateTime::createFromFormat('Y-m-d', $from_date);
$to_object   = DateTime::createFromFormat('Y-m-d', $to_date);

if (
    !$from_object ||
    !$to_object ||
    $from_object->format('Y-m-d') !== $from_date ||
    $to_object->format('Y-m-d') !== $to_date
) {
    $from_date = date('Y-m-01');
    $to_date   = date('Y-m-d');
}

if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}


/*
|--------------------------------------------------------------------------
| PERIOD LABEL
|--------------------------------------------------------------------------
*/

if ($from_date === $to_date) {

    $period_label = date(
        'd M Y',
        strtotime($from_date)
    );

} elseif (
    date('Y-m', strtotime($from_date))
    ===
    date('Y-m', strtotime($to_date))
) {

    $period_label =
        date('d M Y', strtotime($from_date))
        . " - "
        . date('d M Y', strtotime($to_date));

} else {

    $period_label =
        date('d M Y', strtotime($from_date))
        . " - "
        . date('d M Y', strtotime($to_date));
}


/*
|--------------------------------------------------------------------------
| REPORT DATETIME RANGE
|--------------------------------------------------------------------------
*/

$report_from_datetime =
    $from_date . ' 00:00:00';

$report_to_datetime =
    date(
        'Y-m-d',
        strtotime($to_date . ' +1 day')
    ) . ' 00:00:00';


/*
|--------------------------------------------------------------------------
| TOTAL SALES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE business_id = :business_id
      AND sale_status = 'completed'
      AND sale_date >= :from_datetime
      AND sale_date < :to_datetime
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

$total_sales = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| COMPLETED RETURNS / REFUNDS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(r.refund_amount), 0) AS total
    FROM returns r
    WHERE r.business_id = :business_id
      AND r.status = 'completed'
      AND r.created_at >= :from_datetime
      AND r.created_at < :to_datetime
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

$total_returns = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| NET SALES
|--------------------------------------------------------------------------
*/

$net_sales = $total_sales - $total_returns;


/*
|--------------------------------------------------------------------------
| WHOLESALE vs RETAIL SALES BREAKDOWN
|--------------------------------------------------------------------------
*/

// Get sales by sale_type
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(si.unit_price * si.quantity), 0) AS sales_amount,
        COALESCE(SUM(si.buying_price * si.quantity), 0) AS cost_amount,
        COUNT(DISTINCT si.sale_id) AS transaction_count,
        SUM(si.quantity) AS quantity_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.business_id = :business_id
      AND s.sale_status = 'completed'
      AND s.sale_date >= :from_datetime
      AND s.sale_date < :to_datetime
      AND LOWER(COALESCE(si.sale_type, 'retail')) = :sale_type
");

// Retail Sales
$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime,
    ':sale_type' => 'retail'
]);

$retail_sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

$retail_sales =
    (float) ($retail_sales_data['sales_amount'] ?? 0);

$retail_cost =
    (float) ($retail_sales_data['cost_amount'] ?? 0);

$retail_transactions =
    (int) ($retail_sales_data['transaction_count'] ?? 0);

$retail_quantity =
    (float) ($retail_sales_data['quantity_sold'] ?? 0);

$retail_profit =
    $retail_sales - $retail_cost;


// Wholesale Sales
$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime,
    ':sale_type' => 'wholesale'
]);

$wholesale_sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

$wholesale_sales =
    (float) ($wholesale_sales_data['sales_amount'] ?? 0);

$wholesale_cost =
    (float) ($wholesale_sales_data['cost_amount'] ?? 0);

$wholesale_transactions =
    (int) ($wholesale_sales_data['transaction_count'] ?? 0);

$wholesale_quantity =
    (float) ($wholesale_sales_data['quantity_sold'] ?? 0);

$wholesale_profit =
    $wholesale_sales - $wholesale_cost;


// Combined sales
$combined_sales =
    $retail_sales + $wholesale_sales;


// Calculate margins
$retail_margin =
    $retail_sales > 0
        ? ($retail_profit / $retail_sales) * 100
        : 0;

$wholesale_margin =
    $wholesale_sales > 0
        ? ($wholesale_profit / $wholesale_sales) * 100
        : 0;


// Calculate average prices
$retail_avg_price =
    $retail_quantity > 0
        ? $retail_sales / $retail_quantity
        : 0;

$wholesale_avg_price =
    $wholesale_quantity > 0
        ? $wholesale_sales / $wholesale_quantity
        : 0;


/*
|--------------------------------------------------------------------------
| PRODUCT PROFITABILITY BY SALE TYPE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.unit,
        p.buying_price,
        p.selling_price,
        p.wholesale_price,
        p.wholesale_min_qty,

        COALESCE(
            SUM(si.quantity),
            0
        ) AS total_quantity,

        COALESCE(
            SUM(si.unit_price * si.quantity),
            0
        ) AS total_sales,

        COALESCE(
            SUM(si.buying_price * si.quantity),
            0
        ) AS total_cost,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(
                        COALESCE(si.sale_type, 'retail')
                    ) = 'retail'
                    THEN si.quantity
                    ELSE 0
                END
            ),
            0
        ) AS retail_quantity,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(
                        COALESCE(si.sale_type, 'retail')
                    ) = 'retail'
                    THEN si.unit_price * si.quantity
                    ELSE 0
                END
            ),
            0
        ) AS retail_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(
                        COALESCE(si.sale_type, 'retail')
                    ) = 'wholesale'
                    THEN si.quantity
                    ELSE 0
                END
            ),
            0
        ) AS wholesale_quantity,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(
                        COALESCE(si.sale_type, 'retail')
                    ) = 'wholesale'
                    THEN si.unit_price * si.quantity
                    ELSE 0
                END
            ),
            0
        ) AS wholesale_sales

    FROM sale_items si

    INNER JOIN sales s
        ON si.sale_id = s.id

    INNER JOIN products p
        ON si.product_id = p.id

    WHERE s.business_id = :business_id
      AND s.sale_status = 'completed'
      AND s.sale_date >= :from_datetime
      AND s.sale_date < :to_datetime

    GROUP BY
        p.id,
        p.name,
        p.unit,
        p.buying_price,
        p.selling_price,
        p.wholesale_price,
        p.wholesale_min_qty

    ORDER BY total_sales DESC
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

$product_profit_rows =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


// Calculate profits for each product
foreach ($product_profit_rows as &$product) {

    $product['retail_profit'] =
        $product['retail_sales']
        -
        (
            $product['retail_quantity']
            *
            $product['buying_price']
        );

    $product['wholesale_profit'] =
        $product['wholesale_sales']
        -
        (
            $product['wholesale_quantity']
            *
            $product['buying_price']
        );

    $product['total_profit'] =
        $product['total_sales']
        -
        $product['total_cost'];

    $product['retail_margin'] =
        $product['retail_sales'] > 0
            ? (
                $product['retail_profit']
                /
                $product['retail_sales']
            ) * 100
            : 0;

    $product['wholesale_margin'] =
        $product['wholesale_sales'] > 0
            ? (
                $product['wholesale_profit']
                /
                $product['wholesale_sales']
            ) * 100
            : 0;
}

unset($product);


// Sort by total profit
usort(
    $product_profit_rows,
    function ($a, $b) {
        return $b['total_profit'] <=> $a['total_profit'];
    }
);


/*
|--------------------------------------------------------------------------
| COST OF GOODS SOLD
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(si.buying_price * si.quantity),
            0
        ) AS total

    FROM sale_items si

    INNER JOIN sales s
        ON si.sale_id = s.id

    WHERE s.business_id = :business_id
      AND s.sale_status = 'completed'
      AND s.sale_date >= :from_datetime
      AND s.sale_date < :to_datetime
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

$gross_cogs =
    (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| COST OF GOODS RETURNED
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                ri.quantity * si.buying_price
            ),
            0
        ) AS total

    FROM return_items ri

    INNER JOIN returns r
        ON ri.return_id = r.id

    INNER JOIN sale_items si
        ON ri.sale_item_id = si.id

    WHERE r.business_id = :business_id
      AND r.status = 'completed'
      AND r.created_at >= :from_datetime
      AND r.created_at < :to_datetime
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

$returned_cogs =
    (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| NET COGS
|--------------------------------------------------------------------------
*/

$total_cogs =
    $gross_cogs - $returned_cogs;

if ($total_cogs < 0) {
    $total_cogs = 0;
}


/*
|--------------------------------------------------------------------------
| EXPENSES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(amount), 0) AS total

    FROM expenses

    WHERE business_id = :business_id
      AND expense_date >= :from_date
      AND expense_date < :to_date
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_date' => $report_from_datetime,
    ':to_date' => $report_to_datetime
]);

$total_expenses =
    (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PROFIT CALCULATIONS
|--------------------------------------------------------------------------
*/

$gross_profit =
    $net_sales - $total_cogs;

$net_profit =
    $gross_profit - $total_expenses;


/*
|--------------------------------------------------------------------------
| PROFIT MARGINS
|--------------------------------------------------------------------------
*/

$gross_margin = 0;

$net_margin = 0;

if ($net_sales > 0) {

    $gross_margin =
        ($gross_profit / $net_sales) * 100;

    $net_margin =
        ($net_profit / $net_sales) * 100;
}


/*
|--------------------------------------------------------------------------
| PROFIT STATUS
|--------------------------------------------------------------------------
*/

if ($net_profit > 0) {

    $profit_status = "Profit";
    $profit_class = "status-profit";

} elseif ($net_profit < 0) {

    $profit_status = "Loss";
    $profit_class = "status-loss";

} else {

    $profit_status = "Break-even";
    $profit_class = "status-even";
}


/*
|--------------------------------------------------------------------------
| EXPENSE BREAKDOWN
|--------------------------------------------------------------------------
*/

$expense_breakdown = [];

try {

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(
                NULLIF(TRIM(category), ''),
                'Other'
            ) AS category,

            COALESCE(
                SUM(amount),
                0
            ) AS total

        FROM expenses

        WHERE business_id = :business_id
          AND expense_date >= :from_date
          AND expense_date < :to_date

        GROUP BY
            COALESCE(
                NULLIF(TRIM(category), ''),
                'Other'
            )

        ORDER BY total DESC
    ");

    $stmt->execute([
        ':business_id' => $business_id,
        ':from_date' => $report_from_datetime,
        ':to_date' => $report_to_datetime
    ]);

    $expense_breakdown =
        $stmt->fetchAll();

} catch (PDOException $e) {

    $expense_breakdown = [];
}


/*
|--------------------------------------------------------------------------
| PRODUCT RETURN DATA
|--------------------------------------------------------------------------
*/

$product_returns = [];

$stmt = $pdo->prepare("
    SELECT

        ri.product_id,

        COALESCE(
            SUM(ri.quantity),
            0
        ) AS returned_quantity,

        COALESCE(
            SUM(ri.total),
            0
        ) AS returned_sales,

        COALESCE(
            SUM(
                ri.quantity * si.buying_price
            ),
            0
        ) AS returned_cost

    FROM return_items ri

    INNER JOIN returns r
        ON ri.return_id = r.id

    INNER JOIN sale_items si
        ON ri.sale_item_id = si.id

    WHERE r.business_id = :business_id

      AND r.status = 'completed'

      AND r.created_at >= :from_datetime

      AND r.created_at < :to_datetime

    GROUP BY
        ri.product_id
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $report_from_datetime,
    ':to_datetime' => $report_to_datetime
]);

foreach ($stmt->fetchAll() as $return_row) {

    $product_returns[
        (int) $return_row['product_id']
    ] = $return_row;
}


/*
|--------------------------------------------------------------------------
| LAST 60 DAYS DAILY PERFORMANCE
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Sales totals and COGS are intentionally calculated in separate
| queries. Joining sales directly to sale_items while summing
| sales.total_amount can duplicate a sale total when a sale contains
| multiple line items.
|
|--------------------------------------------------------------------------
*/

$daily_start = date(
    'Y-m-d',
    strtotime('-59 days')
);

$daily_end = date('Y-m-d');

$daily_from_datetime =
    $daily_start . ' 00:00:00';

$daily_to_datetime =
    date(
        'Y-m-d',
        strtotime($daily_end . ' +1 day')
    ) . ' 00:00:00';


/*
|--------------------------------------------------------------------------
| DAILY DATA ARRAY
|--------------------------------------------------------------------------
*/

$daily_by_date = [];


/*
|--------------------------------------------------------------------------
| DAILY SALES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        DATE(s.sale_date) AS sale_day,

        COALESCE(
            SUM(s.total_amount),
            0
        ) AS sales

    FROM sales s

    WHERE s.business_id = :business_id

      AND s.sale_status = 'completed'

      AND s.sale_date >= :from_datetime

      AND s.sale_date < :to_datetime

    GROUP BY DATE(s.sale_date)

    ORDER BY sale_day ASC
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $daily_from_datetime,
    ':to_datetime' => $daily_to_datetime
]);

$daily_sales_from_database =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($daily_sales_from_database as $row) {

    $date =
        $row['sale_day'];

    $daily_by_date[$date] = [

        'sales' =>
            (float) $row['sales'],

        'returns' => 0.00,

        'cogs' => 0.00
    ];
}


/*
|--------------------------------------------------------------------------
| DAILY COGS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        DATE(s.sale_date) AS sale_day,

        COALESCE(
            SUM(
                si.buying_price * si.quantity
            ),
            0
        ) AS cogs

    FROM sales s

    INNER JOIN sale_items si
        ON si.sale_id = s.id

    WHERE s.business_id = :business_id

      AND s.sale_status = 'completed'

      AND s.sale_date >= :from_datetime

      AND s.sale_date < :to_datetime

    GROUP BY DATE(s.sale_date)

    ORDER BY sale_day ASC
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $daily_from_datetime,
    ':to_datetime' => $daily_to_datetime
]);

$daily_cogs_from_database =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($daily_cogs_from_database as $row) {

    $date =
        $row['sale_day'];

    if (!isset($daily_by_date[$date])) {

        $daily_by_date[$date] = [

            'sales' => 0.00,

            'returns' => 0.00,

            'cogs' =>
                (float) $row['cogs']
        ];

    } else {

        $daily_by_date[$date]['cogs'] =
            (float) $row['cogs'];
    }
}


/*
|--------------------------------------------------------------------------
| DAILY RETURNS
|--------------------------------------------------------------------------
|
| Returns are assigned to the date the return was created.
|
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        DATE(r.created_at) AS return_day,

        COALESCE(
            SUM(r.refund_amount),
            0
        ) AS returns

    FROM returns r

    WHERE r.business_id = :business_id

      AND r.status = 'completed'

      AND r.created_at >= :from_datetime

      AND r.created_at < :to_datetime

    GROUP BY DATE(r.created_at)

    ORDER BY return_day ASC
");

$stmt->execute([
    ':business_id' => $business_id,
    ':from_datetime' => $daily_from_datetime,
    ':to_datetime' => $daily_to_datetime
]);

$daily_returns_from_database =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($daily_returns_from_database as $row) {

    $date =
        $row['return_day'];

    if (!isset($daily_by_date[$date])) {

        $daily_by_date[$date] = [

            'sales' => 0.00,

            'returns' =>
                (float) $row['returns'],

            'cogs' => 0.00
        ];

    } else {

        $daily_by_date[$date]['returns'] =
            (float) $row['returns'];
    }
}


/*
|--------------------------------------------------------------------------
| GENERATE ALL 60 DAYS
|--------------------------------------------------------------------------
*/

$daily_sales = [];

$start =
    new DateTime($daily_start);

$end =
    new DateTime($daily_end);

$end->modify('+1 day');

$period =
    new DatePeriod(
        $start,
        new DateInterval('P1D'),
        $end
    );


foreach ($period as $date) {

    $date_key =
        $date->format('Y-m-d');


    $day_sales =
        $daily_by_date[$date_key]['sales']
        ?? 0;


    $day_returns =
        $daily_by_date[$date_key]['returns']
        ?? 0;


    $day_cogs =
        $daily_by_date[$date_key]['cogs']
        ?? 0;


    /*
    |--------------------------------------------------------------------------
    | DAILY NET SALES
    |--------------------------------------------------------------------------
    |
    | Net Sales = Gross Sales - Returns
    |
    |--------------------------------------------------------------------------
    */

    $day_net_sales =
        $day_sales - $day_returns;


    /*
    |--------------------------------------------------------------------------
    | DAILY GROSS PROFIT
    |--------------------------------------------------------------------------
    |
    | Gross Profit = Daily Net Sales - Daily COGS
    |
    |--------------------------------------------------------------------------
    */

    $day_profit =
        $day_net_sales - $day_cogs;


    $daily_sales[] = [

        'date' =>
            $date_key,

        'sales' =>
            $day_sales,

        'returns' =>
            $day_returns,

        'net_sales' =>
            $day_net_sales,

        'cogs' =>
            $day_cogs,

        'profit' =>
            $day_profit
    ];
}


/*
|--------------------------------------------------------------------------
| DAILY TOTALS
|--------------------------------------------------------------------------
*/

$daily_sales_total = 0.00;

$daily_returns_total = 0.00;

$daily_net_sales_total = 0.00;

$daily_cogs_total = 0.00;

$daily_profit_total = 0.00;


foreach ($daily_sales as $day) {

    $daily_sales_total +=
        (float) $day['sales'];

    $daily_returns_total +=
        (float) $day['returns'];

    $daily_net_sales_total +=
        (float) $day['net_sales'];

    $daily_cogs_total +=
        (float) $day['cogs'];

    $daily_profit_total +=
        (float) $day['profit'];
}


/*
|--------------------------------------------------------------------------
| TOP PROFITABLE PRODUCT
|--------------------------------------------------------------------------
*/

$top_product = null;

if (!empty($product_profit_rows)) {

    foreach ($product_profit_rows as $product) {

        if ($product['total_profit'] > 0) {

            $top_product = $product;

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| MOST EXPENSIVE EXPENSE CATEGORY
|--------------------------------------------------------------------------
*/

$top_expense_category = null;

if (!empty($expense_breakdown)) {

    $top_expense_category =
        $expense_breakdown[0];
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

    <title>
        Profit & Loss Report - BizFlow
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        /*
        |--------------------------------------------------------------------------
        | REPORT FILTER
        |--------------------------------------------------------------------------
        */

        .report-filter {

            margin-top: 20px;

            padding: 20px;

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

        }

        .report-filter form {

            display: flex;

            flex-wrap: wrap;

            align-items: end;

            gap: 15px;

        }

        .filter-group {

            display: flex;

            flex-direction: column;

            gap: 6px;

        }

        .filter-group label {

            font-size: 13px;

            font-weight: 600;

        }

        .filter-group input {

            padding: 9px 11px;

            border: 1px solid #d1d5db;

            border-radius: 7px;

        }

        .filter-button {

            padding: 10px 18px;

            border: none;

            border-radius: 7px;

            cursor: pointer;

            font-weight: 600;

        }


        /*
        |--------------------------------------------------------------------------
        | REPORT STATUS
        |--------------------------------------------------------------------------
        */

        .report-status {

            margin-top: 20px;

            padding: 15px 18px;

            border-radius: 10px;

            font-weight: 600;

        }

        .status-profit {

            background: #ecfdf5;

            color: #047857;

            border: 1px solid #a7f3d0;

        }

        .status-loss {

            background: #fef2f2;

            color: #b91c1c;

            border: 1px solid #fecaca;

        }

        .status-even {

            background: #fffbeb;

            color: #92400e;

            border: 1px solid #fde68a;

        }


        /*
        |--------------------------------------------------------------------------
        | REPORT CARDS
        |--------------------------------------------------------------------------
        */

        .report-cards {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-top: 25px;

        }

        .report-card {

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

            padding: 20px;

        }

        .report-card-title {

            color: #6b7280;

            font-size: 14px;

            margin-bottom: 8px;

        }

        .report-card-value {

            font-size: 24px;

            font-weight: 700;

        }

        .report-card-sub {

            margin-top: 6px;

            color: #6b7280;

            font-size: 13px;

        }


        /*
        |--------------------------------------------------------------------------
        | WHOLESALE/RETAIL CARDS
        |--------------------------------------------------------------------------
        */

        .comparison-cards {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 18px;

            margin-top: 25px;

        }

        .comparison-card {

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

            padding: 20px;

        }

        .comparison-card.retail {

            border-left: 4px solid #2563eb;

        }

        .comparison-card.wholesale {

            border-left: 4px solid #8b5cf6;

        }

        .comparison-card .card-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 12px;

        }

        .comparison-card .card-header h3 {

            margin: 0;

            font-size: 16px;

        }

        .comparison-card .card-header .badge {

            padding: 4px 12px;

            border-radius: 12px;

            font-size: 12px;

            font-weight: 600;

        }

        .badge-retail {

            background: #dbeafe;

            color: #1e40af;

        }

        .badge-wholesale {

            background: #ede9fe;

            color: #5b21b6;

        }

        .comparison-stats {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 10px;

        }

        .comparison-stats .stat-item {

            padding: 8px 10px;

            background: #f9fafb;

            border-radius: 6px;

        }

        .comparison-stats .stat-item .label {

            font-size: 11px;

            color: #6b7280;

            font-weight: 600;

            text-transform: uppercase;

        }

        .comparison-stats .stat-item .value {

            font-size: 16px;

            font-weight: 700;

            margin-top: 2px;

        }

        .profit-positive {
            color: #15803d;
            font-weight: 700;
        }

        .profit-negative {
            color: #b91c1c;
            font-weight: 700;
        }


        /*
        |--------------------------------------------------------------------------
        | SECTION
        |--------------------------------------------------------------------------
        */

        .report-section {

            margin-top: 35px;

        }

        .report-section h2 {

            margin-bottom: 5px;

        }

        .report-section-description {

            color: #6b7280;

            margin-top: 0;

            margin-bottom: 15px;

        }


        /*
        |--------------------------------------------------------------------------
        | INSIGHTS
        |--------------------------------------------------------------------------
        */

        .insight-grid {

            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 15px;

        }

        .insight-box {

            padding: 18px;

            border: 1px solid #e5e7eb;

            border-radius: 10px;

            background: #ffffff;

        }

        .insight-box strong {

            display: block;

            margin-bottom: 7px;

        }


        /*
        |--------------------------------------------------------------------------
        | PRODUCT PROFIT TABLE
        |--------------------------------------------------------------------------
        */

        .margin-value {

            font-weight: 600;

        }


        /*
        |--------------------------------------------------------------------------
        | DAILY SALES
        |--------------------------------------------------------------------------
        */

        .daily-sales-container {

            margin-top: 35px;

        }

        .daily-sales-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 12px;

        }

        .daily-sales-header h2 {

            margin: 0;

        }

        .daily-sales-header p {

            margin: 3px 0 0;

            color: #6b7280;

        }

        .sales-zero {

            color: #9ca3af;

        }

        .sales-positive {

            font-weight: 600;

        }

        .sales-date {

            white-space: nowrap;

        }

        .daily-net-sales-container {

            margin-top: 35px;

        }

        .daily-net-sales-header {

            margin-bottom: 12px;

        }

        .daily-net-sales-header h2 {

            margin: 0 0 3px;

        }

        .daily-net-sales-header p {

            margin: 0;

            color: #6b7280;

        }

        .returns-value {

            color: #b91c1c;

            font-weight: 600;

        }

        .net-sales-value {

            font-weight: 700;

        }

        .daily-summary-box {

            margin-top: 15px;

            padding: 15px 18px;

            background: #f9fafb;

            border: 1px solid #e5e7eb;

            border-radius: 10px;

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1000px) {

            .report-cards {

                grid-template-columns:
                    repeat(2, minmax(0, 1fr));

            }

            .insight-grid {

                grid-template-columns:
                    1fr;

            }

            .comparison-cards {

                grid-template-columns: 1fr;

            }

        }

        @media (max-width: 700px) {

            .report-cards {

                grid-template-columns: 1fr;

            }

            .report-filter form {

                display: block;

            }

            .filter-group {

                margin-bottom: 12px;

            }

            .filter-button {

                width: 100%;

            }

            .daily-sales-header {

                display: block;

            }

            .data-table {

                font-size: 13px;

            }

        }

    </style>

</head>


<body>

<div class="app">


    <?php include '../assets/includes/sidebar.php'; ?>


    <main class="main">


        <?php include '../assets/includes/topbar.php'; ?>


        <section class="content">


            <!-- =====================================================
                 PAGE TITLE
            ====================================================== -->

            <div class="page-title">

                <h1>
                    Profit & Loss Report
                </h1>

                <p>
                    <?= htmlspecialchars($period_label) ?>
                </p>

            </div>


            <!-- =====================================================
                 DATE FILTER
            ====================================================== -->

            <div class="report-filter">

                <form method="GET">

                    <div class="filter-group">

                        <label for="from">
                            From
                        </label>

                        <input
                            type="date"
                            id="from"
                            name="from"
                            value="<?= htmlspecialchars($from_date) ?>"
                        >

                    </div>


                    <div class="filter-group">

                        <label for="to">
                            To
                        </label>

                        <input
                            type="date"
                            id="to"
                            name="to"
                            value="<?= htmlspecialchars($to_date) ?>"
                        >

                    </div>


                    <button
                        type="submit"
                        class="filter-button"
                    >
                        Generate Report
                    </button>

                </form>

            </div>


            <!-- =====================================================
                 REPORT STATUS
            ====================================================== -->

            <div class="report-status <?= $profit_class ?>">

                <?php if ($net_profit > 0): ?>

                    ✅ Business made a profit of
                    <strong>
                        KSh <?= number_format($net_profit, 2) ?>
                    </strong>

                <?php elseif ($net_profit < 0): ?>

                    🔴 Business recorded a loss of
                    <strong>
                        KSh <?= number_format(abs($net_profit), 2) ?>
                    </strong>

                <?php else: ?>

                    ⚠️ Business is currently at
                    <strong>break-even</strong>.

                <?php endif; ?>

            </div>


            <!-- =====================================================
                 MAIN PROFIT CARDS
            ====================================================== -->

            <div class="report-cards">


                <!-- SALES -->

                <div class="report-card">

                    <div class="report-card-title">
                        Gross Sales
                    </div>

                    <div class="report-card-value">

                        KSh <?= number_format(
                            $total_sales,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Before returns

                    </div>

                </div>


                <!-- RETURNS -->

                <div class="report-card">

                    <div class="report-card-title">
                        Returns / Refunds
                    </div>

                    <div class="report-card-value">

                        KSh <?= number_format(
                            $total_returns,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Completed returns

                    </div>

                </div>


                <!-- NET SALES -->

                <div class="report-card">

                    <div class="report-card-title">
                        Net Sales
                    </div>

                    <div class="report-card-value">

                        KSh <?= number_format(
                            $net_sales,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Sales after returns

                    </div>

                </div>


                <!-- COGS -->

                <div class="report-card">

                    <div class="report-card-title">
                        Cost of Goods Sold
                    </div>

                    <div class="report-card-value">

                        KSh <?= number_format(
                            $total_cogs,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Cost of goods actually sold

                    </div>

                </div>


                <!-- GROSS PROFIT -->

                <div class="report-card">

                    <div class="report-card-title">
                        Gross Profit
                    </div>

                    <div
                        class="report-card-value profit-positive"
                    >

                        KSh <?= number_format(
                            $gross_profit,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Margin:
                        <?= number_format(
                            $gross_margin,
                            2
                        ) ?>%

                    </div>

                </div>


                <!-- EXPENSES -->

                <div class="report-card">

                    <div class="report-card-title">
                        Operating Expenses
                    </div>

                    <div class="report-card-value">

                        KSh <?= number_format(
                            $total_expenses,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        Expenses recorded

                    </div>

                </div>


                <!-- NET PROFIT -->

                <div class="report-card">

                    <div class="report-card-title">
                        Net Profit
                    </div>

                    <div
                        class="report-card-value
                        <?= $net_profit < 0
                            ? 'profit-negative'
                            : 'profit-positive' ?>"
                    >

                        KSh <?= number_format(
                            $net_profit,
                            2
                        ) ?>

                    </div>

                    <div class="report-card-sub">

                        After COGS and expenses

                    </div>

                </div>


                <!-- NET MARGIN -->

                <div class="report-card">

                    <div class="report-card-title">
                        Net Profit Margin
                    </div>

                    <div class="report-card-value">

                        <?= number_format(
                            $net_margin,
                            2
                        ) ?>%

                    </div>

                    <div class="report-card-sub">

                        Profit retained from sales

                    </div>

                </div>


            </div>


            <!-- =====================================================
                 RETAIL vs WHOLESALE BREAKDOWN
            ====================================================== -->

            <h2 style="margin-top:35px; margin-bottom:5px;">
                🏪 Retail vs 📦 Wholesale Breakdown
            </h2>

            <p style="color:#6b7280; margin-top:0; margin-bottom:15px;">
                Compare sales performance between retail and wholesale channels.
            </p>

            <div class="comparison-cards">


                <!-- RETAIL CARD -->

                <div class="comparison-card retail">

                    <div class="card-header">

                        <h3>
                            🏪 Retail
                        </h3>

                        <span class="badge badge-retail">
                            Retail
                        </span>

                    </div>


                    <div class="comparison-stats">

                        <div class="stat-item">

                            <div class="label">
                                Sales
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $retail_sales,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Cost
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $retail_cost,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Gross Profit
                            </div>

                            <div
                                class="value
                                <?= $retail_profit >= 0
                                    ? 'profit-positive'
                                    : 'profit-negative' ?>"
                            >
                                KSh <?= number_format(
                                    $retail_profit,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Margin
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $retail_margin,
                                    2
                                ) ?>%
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Transactions
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $retail_transactions
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Quantity Sold
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $retail_quantity,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div
                            class="stat-item"
                            style="grid-column: span 2;"
                        >

                            <div class="label">
                                Average Price/Unit
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $retail_avg_price,
                                    2
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>


                <!-- WHOLESALE CARD -->

                <div class="comparison-card wholesale">

                    <div class="card-header">

                        <h3>
                            📦 Wholesale
                        </h3>

                        <span class="badge badge-wholesale">
                            Wholesale
                        </span>

                    </div>


                    <div class="comparison-stats">

                        <div class="stat-item">

                            <div class="label">
                                Sales
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $wholesale_sales,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Cost
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $wholesale_cost,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Gross Profit
                            </div>

                            <div
                                class="value
                                <?= $wholesale_profit >= 0
                                    ? 'profit-positive'
                                    : 'profit-negative' ?>"
                            >
                                KSh <?= number_format(
                                    $wholesale_profit,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Margin
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $wholesale_margin,
                                    2
                                ) ?>%
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Transactions
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $wholesale_transactions
                                ) ?>
                            </div>

                        </div>


                        <div class="stat-item">

                            <div class="label">
                                Quantity Sold
                            </div>

                            <div class="value">
                                <?= number_format(
                                    $wholesale_quantity,
                                    2
                                ) ?>
                            </div>

                        </div>


                        <div
                            class="stat-item"
                            style="grid-column: span 2;"
                        >

                            <div class="label">
                                Average Price/Unit
                            </div>

                            <div class="value">
                                KSh <?= number_format(
                                    $wholesale_avg_price,
                                    2
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Comparison Summary -->

            <div
                style="
                    background:#f9fafb;
                    border:1px solid #e5e7eb;
                    border-radius:10px;
                    padding:16px 20px;
                    margin-top:10px;
                "
            >

                <strong>
                    📊 Comparison Summary:
                </strong>

                <?php if (
                    $retail_sales > 0
                    &&
                    $wholesale_sales > 0
                ): ?>

                    Retail sales are
                    <strong>
                        <?= number_format(
                            (
                                $retail_sales
                                /
                                (
                                    $retail_sales
                                    +
                                    $wholesale_sales
                                )
                            ) * 100,
                            1
                        ) ?>%
                    </strong>
                    of total sales.

                    Wholesale sales are
                    <strong>
                        <?= number_format(
                            (
                                $wholesale_sales
                                /
                                (
                                    $retail_sales
                                    +
                                    $wholesale_sales
                                )
                            ) * 100,
                            1
                        ) ?>%
                    </strong>
                    of total sales.

                    <?php if (
                        $retail_profit
                        >
                        $wholesale_profit
                    ): ?>

                        Retail contributes more profit
                        (KSh
                        <?= number_format(
                            $retail_profit
                            -
                            $wholesale_profit,
                            2
                        ) ?>
                        more than wholesale).

                    <?php elseif (
                        $wholesale_profit
                        >
                        $retail_profit
                    ): ?>

                        Wholesale contributes more profit
                        (KSh
                        <?= number_format(
                            $wholesale_profit
                            -
                            $retail_profit,
                            2
                        ) ?>
                        more than retail).

                    <?php else: ?>

                        Both channels contribute equally
                        to profit.

                    <?php endif; ?>

                <?php elseif ($retail_sales > 0): ?>

                    All sales are retail.

                <?php elseif ($wholesale_sales > 0): ?>

                    All sales are wholesale.

                <?php else: ?>

                    No sales recorded in this period.

                <?php endif; ?>

            </div>


            <!-- =====================================================
                 BUSINESS INSIGHTS
            ====================================================== -->

            <div class="report-section">

                <h2>
                    Business Insights
                </h2>

                <p class="report-section-description">
                    Quick interpretation of the business performance
                    for the selected period.
                </p>


                <div class="insight-grid">


                    <!-- PROFITABILITY -->

                    <div class="insight-box">

                        <strong>

                            <?= $net_profit >= 0
                                ? '✅ Profitability'
                                : '🔴 Loss Alert' ?>

                        </strong>


                        <?php if ($net_profit > 0): ?>

                            The business generated

                            <strong>
                                KSh <?= number_format(
                                    $net_profit,
                                    2
                                ) ?>
                            </strong>

                            after the recorded expenses.

                        <?php elseif ($net_profit < 0): ?>

                            The business spent more than
                            it generated during this period.

                            The loss is

                            <strong>
                                KSh <?= number_format(
                                    abs($net_profit),
                                    2
                                ) ?>
                            </strong>.

                        <?php else: ?>

                            Sales exactly covered COGS
                            and recorded expenses.

                        <?php endif; ?>

                    </div>


                    <!-- GROSS MARGIN -->

                    <div class="insight-box">

                        <strong>
                            📊 Gross Margin
                        </strong>

                        The business retained

                        <strong>
                            <?= number_format(
                                $gross_margin,
                                2
                            ) ?>%
                        </strong>

                        of net sales after paying
                        the cost of the stock sold.

                    </div>


                    <!-- EXPENSES -->

                    <div class="insight-box">

                        <strong>
                            💸 Expenses
                        </strong>

                        Total operating expenses were

                        <strong>
                            KSh <?= number_format(
                                $total_expenses,
                                2
                            ) ?>
                        </strong>.

                        <?php if ($top_expense_category): ?>

                            The largest recorded expense
                            category was

                            <strong>
                                <?= htmlspecialchars(
                                    $top_expense_category['category']
                                ) ?>
                            </strong>.

                        <?php endif; ?>

                    </div>


                    <!-- TOP PRODUCT -->

                    <div class="insight-box">

                        <strong>
                            🏆 Top Profit Product
                        </strong>

                        <?php if ($top_product): ?>

                            <strong>
                                <?= htmlspecialchars(
                                    $top_product['name']
                                ) ?>
                            </strong>

                            generated approximately

                            <strong>
                                KSh <?= number_format(
                                    $top_product['total_profit'],
                                    2
                                ) ?>
                            </strong>

                            gross profit.

                        <?php else: ?>

                            No profitable product
                            was recorded in this period.

                        <?php endif; ?>

                    </div>


                    <!-- RETURNS -->

                    <div class="insight-box">

                        <strong>
                            🔄 Returns
                        </strong>

                        Completed returns reduced
                        reported sales by

                        <strong>
                            KSh <?= number_format(
                                $total_returns,
                                2
                            ) ?>
                        </strong>.

                    </div>


                    <!-- NET MARGIN -->

                    <div class="insight-box">

                        <strong>
                            💰 Net Margin
                        </strong>

                        For every KSh 100 of net sales,
                        the business retained approximately

                        <strong>
                            KSh <?= number_format(
                                $net_margin,
                                2
                            ) ?>
                        </strong>

                        after COGS and recorded expenses.

                    </div>


                </div>

            </div>


            <!-- =====================================================
                 EXPENSE BREAKDOWN
            ====================================================== -->

            <div class="report-section">

                <h2>
                    Expenses Breakdown
                </h2>

                <p class="report-section-description">

                    Where the business money was spent
                    during the selected period.

                </p>


                <?php if (!empty($expense_breakdown)): ?>

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    Expense Category
                                </th>

                                <th>
                                    Amount
                                </th>

                                <th>
                                    % of Expenses
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $expense_breakdown
                            as $expense
                        ): ?>

                            <?php

                            $expense_total =
                                (float) $expense['total'];

                            $expense_percentage = 0;

                            if ($total_expenses > 0) {

                                $expense_percentage =
                                    (
                                        $expense_total
                                        /
                                        $total_expenses
                                    ) * 100;
                            }

                            ?>

                            <tr>

                                <td>

                                    <?= htmlspecialchars(
                                        $expense['category']
                                    ) ?>

                                </td>

                                <td>

                                    KSh <?= number_format(
                                        $expense_total,
                                        2
                                    ) ?>

                                </td>

                                <td>

                                    <?= number_format(
                                        $expense_percentage,
                                        2
                                    ) ?>%

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="insight-box">

                        No expense breakdown is available
                        for this period.

                    </div>

                <?php endif; ?>

            </div>


            <!-- =====================================================
                 PRODUCT PROFITABILITY
            ====================================================== -->

            <div class="report-section">

                <h2>
                    Product Profitability
                </h2>

                <p class="report-section-description">

                    Shows which products generated the most gross profit,
                    broken down by retail and wholesale.

                </p>


                <?php if (!empty($product_profit_rows)): ?>

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    Product
                                </th>

                                <th>
                                    Unit
                                </th>

                                <th>
                                    Qty Sold
                                </th>

                                <th>
                                    Net Sales
                                </th>

                                <th>
                                    Cost
                                </th>

                                <th>
                                    Total Profit
                                </th>

                                <th>
                                    Retail Profit
                                </th>

                                <th>
                                    Wholesale Profit
                                </th>

                                <th>
                                    Retail Margin
                                </th>

                                <th>
                                    Wholesale Margin
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $product_profit_rows
                            as $product
                        ): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $product['name']
                                        ) ?>
                                    </strong>

                                    <?php if (
                                        $product['wholesale_price'] > 0
                                    ): ?>

                                        <br>

                                        <small
                                            style="color:#6b7280;"
                                        >
                                            Wholesale:
                                            KSh <?= number_format(
                                                $product['wholesale_price'],
                                                2
                                            ) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $product['unit'] ?? ''
                                    ) ?>

                                </td>


                                <td>

                                    <?= number_format(
                                        $product['total_quantity'],
                                        2
                                    ) ?>

                                </td>


                                <td>

                                    KSh <?= number_format(
                                        $product['total_sales'],
                                        2
                                    ) ?>

                                </td>


                                <td>

                                    KSh <?= number_format(
                                        $product['total_cost'],
                                        2
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="<?= $product['total_profit'] < 0
                                            ? 'profit-negative'
                                            : 'profit-positive' ?>"
                                    >

                                        KSh <?= number_format(
                                            $product['total_profit'],
                                            2
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <span
                                        class="<?= $product['retail_profit'] < 0
                                            ? 'profit-negative'
                                            : 'profit-positive' ?>"
                                    >

                                        KSh <?= number_format(
                                            $product['retail_profit'],
                                            2
                                        ) ?>

                                    </span>

                                    <br>

                                    <small
                                        style="color:#6b7280;"
                                    >

                                        <?= number_format(
                                            $product['retail_quantity'],
                                            2
                                        ) ?>

                                        units

                                    </small>

                                </td>


                                <td>

                                    <span
                                        class="<?= $product['wholesale_profit'] < 0
                                            ? 'profit-negative'
                                            : 'profit-positive' ?>"
                                    >

                                        KSh <?= number_format(
                                            $product['wholesale_profit'],
                                            2
                                        ) ?>

                                    </span>

                                    <br>

                                    <small
                                        style="color:#6b7280;"
                                    >

                                        <?= number_format(
                                            $product['wholesale_quantity'],
                                            2
                                        ) ?>

                                        units

                                    </small>

                                </td>


                                <td class="margin-value">

                                    <?= number_format(
                                        $product['retail_margin'],
                                        2
                                    ) ?>%

                                </td>


                                <td class="margin-value">

                                    <?= number_format(
                                        $product['wholesale_margin'],
                                        2
                                    ) ?>%

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="insight-box">

                        No completed sales were recorded
                        for this period.

                    </div>

                <?php endif; ?>

            </div>


            <!-- =====================================================
                 LAST 60 DAYS PERFORMANCE
            ====================================================== -->

            <div class="daily-sales-container">


                <div class="daily-sales-header">

                    <div>

                        <h2>
                            Last 60 Days Performance
                        </h2>

                        <p>

                            <?= date(
                                'd/m/Y',
                                strtotime($daily_start)
                            ) ?>

                            -

                            <?= date(
                                'd/m/Y',
                                strtotime($daily_end)
                            ) ?>

                        </p>

                    </div>

                </div>


                <table class="data-table">

                    <thead>

                        <tr>

                            <th>
                                Date
                            </th>

                            <th>
                                Sales
                            </th>

                            <th>
                                COGS
                            </th>

                            <th>
                                Gross Profit
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach (
                        $daily_sales as $day
                    ): ?>

                        <?php

                        $day_sales =
                            (float) $day['sales'];

                        $day_cogs =
                            (float) $day['cogs'];

                        $day_profit =
                            (float) $day['profit'];

                        $is_zero =
                            ($day_sales <= 0);

                        ?>

                        <tr>


                            <td class="sales-date">

                                <?= date(
                                    'd/m/Y',
                                    strtotime($day['date'])
                                ) ?>

                            </td>


                            <td
                                class="<?= $is_zero
                                    ? 'sales-zero'
                                    : 'sales-positive' ?>"
                            >

                                KSh <?= number_format(
                                    $day_sales,
                                    2
                                ) ?>

                            </td>


                            <td>

                                KSh <?= number_format(
                                    $day_cogs,
                                    2
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="<?= $day_profit < 0
                                        ? 'profit-negative'
                                        : 'profit-positive' ?>"
                                >

                                    KSh <?= number_format(
                                        $day_profit,
                                        2
                                    ) ?>

                                </span>

                            </td>


                        </tr>

                    <?php endforeach; ?>

                    </tbody>


                    <tfoot>

                        <tr style="font-weight:700;">

                            <td>
                                Total
                            </td>

                            <td>
                                KSh <?= number_format(
                                    $daily_sales_total,
                                    2
                                ) ?>
                            </td>

                            <td>
                                KSh <?= number_format(
                                    $daily_cogs_total,
                                    2
                                ) ?>
                            </td>

                            <td>
                                <span
                                    class="<?= $daily_profit_total < 0
                                        ? 'profit-negative'
                                        : 'profit-positive' ?>"
                                >
                                    KSh <?= number_format(
                                        $daily_profit_total,
                                        2
                                    ) ?>
                                </span>
                            </td>

                        </tr>

                    </tfoot>

                </table>


            </div>


            <!-- =====================================================
                 DAILY NET SALES AFTER RETURNS
            ====================================================== -->

            <div class="daily-net-sales-container">


                <div class="daily-net-sales-header">

                    <h2>
                        Daily Net Sales After Returns
                    </h2>

                    <p>
                        Daily gross sales less completed returns/refunds.
                    </p>

                </div>


                <table class="data-table">

                    <thead>

                        <tr>

                            <th>
                                Date
                            </th>

                            <th>
                                Sales
                            </th>

                            <th>
                                Returns
                            </th>

                            <th>
                                Net Sales
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach (
                        $daily_sales as $day
                    ): ?>

                        <?php

                        $day_sales =
                            (float) $day['sales'];

                        $day_returns =
                            (float) $day['returns'];

                        $day_net_sales =
                            (float) $day['net_sales'];

                        ?>

                        <tr>


                            <td class="sales-date">

                                <?= date(
                                    'd/m/Y',
                                    strtotime($day['date'])
                                ) ?>

                            </td>


                            <td
                                class="<?= $day_sales <= 0
                                    ? 'sales-zero'
                                    : 'sales-positive' ?>"
                            >

                                KSh <?= number_format(
                                    $day_sales,
                                    2
                                ) ?>

                            </td>


                            <td class="returns-value">

                                KSh <?= number_format(
                                    $day_returns,
                                    2
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="<?= $day_net_sales < 0
                                        ? 'profit-negative'
                                        : 'net-sales-value' ?>"
                                >

                                    KSh <?= number_format(
                                        $day_net_sales,
                                        2
                                    ) ?>

                                </span>

                            </td>


                        </tr>

                    <?php endforeach; ?>

                    </tbody>


                    <tfoot>

                        <tr style="font-weight:700;">

                            <td>
                                Total
                            </td>

                            <td>
                                KSh <?= number_format(
                                    $daily_sales_total,
                                    2
                                ) ?>
                            </td>

                            <td class="returns-value">
                                KSh <?= number_format(
                                    $daily_returns_total,
                                    2
                                ) ?>
                            </td>

                            <td>

                                <span
                                    class="<?= $daily_net_sales_total < 0
                                        ? 'profit-negative'
                                        : 'net-sales-value' ?>"
                                >

                                    KSh <?= number_format(
                                        $daily_net_sales_total,
                                        2
                                    ) ?>

                                </span>

                            </td>

                        </tr>

                    </tfoot>

                </table>


                <div class="daily-summary-box">

                    <strong>
                        Daily Net Sales Formula:
                    </strong>

                    Gross Sales
                    −
                    Completed Returns
                    =
                    <strong>
                        Net Sales
                    </strong>

                    <br>

                    <small style="color:#6b7280;">

                        Returns are assigned to the date on which
                        the completed return was recorded.

                    </small>

                </div>


            </div>


        </section>


    </main>


</div>

</body>

</html>

```php
<?php

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

require_once "config/database.php";


/*
|--------------------------------------------------------------------------
| SESSION INFORMATION
|--------------------------------------------------------------------------
| FIX: These variables are now defined before ANY database query uses them.
|--------------------------------------------------------------------------
*/

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$current_user_id = (int) ($_SESSION["user_id"] ?? 0);

if ($business_id <= 0 || $current_user_id <= 0) {
    die("Invalid session. Business or user information is missing.");
}

$today = date("Y-m-d");


// ============================================================
// GET USER ROLE
// ============================================================

$stmt = $pdo->prepare("
    SELECT r.name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
");

$stmt->execute([
    ':user_id' => $current_user_id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$user_role = strtolower(
    trim($user['name'] ?? 'cashier')
);


// ============================================================
// GET ALL CASHIERS FOR FILTER
// ============================================================

$stmt = $pdo->prepare("
    SELECT id, full_name, username
    FROM users
    WHERE business_id = :business_id
    AND role_id IN (
        SELECT id
        FROM roles
        WHERE name IN ('cashier', 'admin', 'owner', 'manager')
    )
    ORDER BY full_name ASC
");

$stmt->execute([
    ':business_id' => $business_id
]);

$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Selected user filter (0 = all users)

$selected_user = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : 0;


// ============================================================
// TODAY'S SALES - ALL USERS
// ============================================================

$sql_sales = "
    SELECT
        COALESCE(SUM(total_amount), 0) AS total,
        COUNT(*) AS count
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND sale_date >= :today_start
    AND sale_date < :tomorrow_start
";

$params_sales = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_sales .= " AND user_id = :user_id";
    $params_sales[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_sales);
$stmt->execute($params_sales);

$sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

$today_gross_sales = (float) $sales_data['total'];
$today_sales_count = (int) $sales_data['count'];


// ============================================================
// TODAY'S RETURNS AGAINST TODAY'S SALES
// ============================================================

$sql_returns_against = "
    SELECT
        COALESCE(SUM(r.refund_amount), 0) AS total
    FROM returns r
    INNER JOIN sales s ON r.sale_id = s.id
    WHERE r.business_id = :business_id
    AND r.status = 'completed'
    AND r.created_at >= :today_start
    AND r.created_at < :tomorrow_start
    AND s.sale_status = 'completed'
";

$params_returns_against = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_returns_against .= " AND r.user_id = :user_id";
    $params_returns_against[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_returns_against);
$stmt->execute($params_returns_against);

$today_sales_returns = (float) $stmt->fetch()['total'];


// ============================================================
// NET TODAY'S SALES
// ============================================================

$today_sales = max(
    0,
    $today_gross_sales - $today_sales_returns
);


// ============================================================
// TODAY'S TRANSACTIONS
// ============================================================

$sql_transactions = "
    SELECT COUNT(*) AS count
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND sale_date >= :today_start
    AND sale_date < :tomorrow_start
";

$params_transactions = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_transactions .= " AND user_id = :user_id";
    $params_transactions[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_transactions);
$stmt->execute($params_transactions);

$transactions = (int) $stmt->fetch()['count'];


// ============================================================
// ACTIVE PRODUCTS
// ============================================================

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count
    FROM products
    WHERE business_id = :business_id
    AND is_active = 1
");

$stmt->execute([
    ':business_id' => $business_id
]);

$products = (int) $stmt->fetch()['count'];


// ============================================================
// TODAY'S EXPENSES
// ============================================================

$sql_expenses = "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM expenses
    WHERE business_id = :business_id
    AND expense_date >= :today_start
    AND expense_date < :tomorrow_start
";

$params_expenses = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_expenses .= " AND user_id = :user_id";
    $params_expenses[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_expenses);
$stmt->execute($params_expenses);

$today_expenses = (float) $stmt->fetch()['total'];


// ============================================================
// TODAY'S BANK SALES
// ============================================================

$sql_bank = "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN payment_method IN ('bank', 'mixed')
                    THEN bank_amount
                    ELSE 0
                END
            ),
            0
        ) AS bank_sales
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND sale_date >= :today_start
    AND sale_date < :tomorrow_start
";

$params_bank = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_bank .= " AND user_id = :user_id";
    $params_bank[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_bank);
$stmt->execute($params_bank);

$today_bank_sales = (float) $stmt->fetch()['bank_sales'];


// ============================================================
// TODAY'S CASH RECEIVED
// ============================================================

$sql_cash = "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN payment_method IN ('cash', 'mixed')
                    THEN cash_amount
                    ELSE 0
                END
            ),
            0
        ) AS cash_received,

        COALESCE(SUM(change_given), 0) AS change_given

    FROM sales

    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND sale_date >= :today_start
    AND sale_date < :tomorrow_start
";

$params_cash = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_cash .= " AND user_id = :user_id";
    $params_cash[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_cash);
$stmt->execute($params_cash);

$cash_data = $stmt->fetch(PDO::FETCH_ASSOC);

$today_cash_received = (float) (
    $cash_data['cash_received'] ?? 0
);

$today_change_given = (float) (
    $cash_data['change_given'] ?? 0
);

$today_cash_sales =
    $today_cash_received - $today_change_given;


// ============================================================
// TODAY'S RETURNS
// ============================================================

$sql_returns = "
    SELECT
        COALESCE(SUM(r.refund_amount), 0) AS total,
        COUNT(*) AS count
    FROM returns r
    INNER JOIN sales s ON r.sale_id = s.id
    WHERE r.business_id = :business_id
    AND r.status = 'completed'
    AND r.created_at >= :today_start
    AND r.created_at < :tomorrow_start
    AND s.sale_status = 'completed'
";

$params_returns = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_returns .= " AND r.user_id = :user_id";
    $params_returns[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_returns);
$stmt->execute($params_returns);

$returns_data = $stmt->fetch(PDO::FETCH_ASSOC);

$today_return_amount = (float) (
    $returns_data['total'] ?? 0
);

$today_return_count = (int) (
    $returns_data['count'] ?? 0
);


// ============================================================
// CASH AT HAND
// ============================================================

$cash_at_hand =
    $today_cash_sales
    - $today_expenses
    - $today_return_amount;

if ($cash_at_hand < 0) {
    $cash_at_hand = 0;
}


// ============================================================
// TODAY'S GROSS PROFIT
// ============================================================

$sql_profit = "
    SELECT
        COALESCE(
            SUM(
                si.quantity *
                (p.selling_price - p.buying_price)
            ),
            0
        ) AS profit

    FROM sale_items si

    INNER JOIN sales s
        ON si.sale_id = s.id

    INNER JOIN products p
        ON si.product_id = p.id

    WHERE s.business_id = :business_id
    AND s.sale_status = 'completed'
    AND s.sale_date >= :today_start
    AND s.sale_date < :tomorrow_start
";

$params_profit = [
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
];

if ($selected_user > 0) {
    $sql_profit .= " AND s.user_id = :user_id";
    $params_profit[':user_id'] = $selected_user;
}

$stmt = $pdo->prepare($sql_profit);
$stmt->execute($params_profit);

$today_profit = (float) $stmt->fetch()['profit'];


// ============================================================
// LOW STOCK PRODUCTS
// ============================================================

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        stock_quantity,
        reorder_level,
        unit
    FROM products
    WHERE business_id = :business_id
    AND is_active = 1
    AND stock_quantity <= reorder_level
    ORDER BY stock_quantity ASC
    LIMIT 5
");

$stmt->execute([
    ':business_id' => $business_id
]);

$low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// RECENT SALES - ALL USERS (with user name)
// ============================================================

$sql_recent = "
    SELECT
        s.id,
        s.total_amount,
        s.sale_date,
        s.user_id,
        u.full_name AS user_name,
        s.receipt_number,
        s.payment_method

    FROM sales s

    LEFT JOIN users u
        ON s.user_id = u.id

    WHERE s.business_id = :business_id
    AND s.sale_status = 'completed'
";

$params_recent = [
    ':business_id' => $business_id
];

if ($selected_user > 0) {
    $sql_recent .= " AND s.user_id = :user_id";
    $params_recent[':user_id'] = $selected_user;
}

$sql_recent .= "
    ORDER BY s.sale_date DESC
    LIMIT 10
";

$stmt = $pdo->prepare($sql_recent);
$stmt->execute($params_recent);

$recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// USER PERFORMANCE TODAY
// ============================================================

$sql_user_performance = "
    SELECT
        u.id,
        u.full_name,

        COUNT(s.id) AS total_transactions,

        COALESCE(
            SUM(s.total_amount),
            0
        ) AS total_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method IN ('cash', 'mixed')
                    THEN s.cash_amount
                    ELSE 0
                END
            ),
            0
        ) AS cash_received,

        COALESCE(
            SUM(s.change_given),
            0
        ) AS change_given,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method IN ('bank', 'mixed')
                    THEN s.bank_amount
                    ELSE 0
                END
            ),
            0
        ) AS bank_sales

    FROM users u

    LEFT JOIN sales s
        ON u.id = s.user_id
        AND s.business_id = :business_id
        AND s.sale_status = 'completed'
        AND s.sale_date >= :today_start
        AND s.sale_date < :tomorrow_start

    WHERE u.business_id = :business_id

    GROUP BY
        u.id,
        u.full_name

    ORDER BY total_sales DESC
";

$stmt = $pdo->prepare($sql_user_performance);

$stmt->execute([
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
]);

$user_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// TODAY'S RETURNS DETAIL
// ============================================================

$sql_returns_detail = "
    SELECT
        r.id,
        r.refund_amount,
        r.created_at,
        u.full_name AS user_name

    FROM returns r

    LEFT JOIN users u
        ON r.user_id = u.id

    WHERE r.business_id = :business_id
    AND r.status = 'completed'
    AND r.created_at >= :today_start
    AND r.created_at < :tomorrow_start

    ORDER BY r.id DESC

    LIMIT 5
";

$stmt = $pdo->prepare($sql_returns_detail);

$stmt->execute([
    ':business_id' => $business_id,
    ':today_start' => $today . ' 00:00:00',
    ':tomorrow_start' => date(
        'Y-m-d',
        strtotime($today . ' +1 day')
    ) . ' 00:00:00'
]);

$today_returns_detail = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// INVENTORY FINANCIAL CALCULATIONS
// ============================================================

$inventory_buying_value = 0;
$inventory_retail_value = 0;
$inventory_wholesale_value = 0;

$total_stock_units = 0;
$total_stock_products = 0;

$retail_profit = 0;
$wholesale_profit = 0;

$retail_margin = 0;
$wholesale_margin = 0;

$inventory_products = [];


$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        stock_quantity,
        buying_price,
        selling_price,
        wholesale_price,
        unit

    FROM products

    WHERE business_id = :business_id
    AND is_active = 1
    AND stock_quantity > 0

    ORDER BY name ASC
");

$stmt->execute([
    ':business_id' => $business_id
]);

$stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);


foreach ($stock_products as $product) {

    $stock = (float) (
        $product['stock_quantity'] ?? 0
    );

    $buying_price = (float) (
        $product['buying_price'] ?? 0
    );

    $selling_price = (float) (
        $product['selling_price'] ?? 0
    );

    $wholesale_price = (float) (
        $product['wholesale_price'] ?? 0
    );

    if ($stock <= 0) {
        continue;
    }


    $buying_value =
        $stock * $buying_price;

    $retail_value =
        $stock * $selling_price;

    $wholesale_value =
        $stock * $wholesale_price;


    $product_retail_profit =
        $retail_value - $buying_value;

    $product_wholesale_profit =
        $wholesale_value - $buying_value;


    $inventory_buying_value +=
        $buying_value;

    $inventory_retail_value +=
        $retail_value;

    $inventory_wholesale_value +=
        $wholesale_value;


    $total_stock_units += $stock;

    $total_stock_products++;


    $inventory_products[] = [

        'name' =>
            $product['name'],

        'stock' =>
            $stock,

        'unit' =>
            $product['unit'] ?? '',

        'buying_price' =>
            $buying_price,

        'selling_price' =>
            $selling_price,

        'wholesale_price' =>
            $wholesale_price,

        'buying_value' =>
            $buying_value,

        'retail_value' =>
            $retail_value,

        'wholesale_value' =>
            $wholesale_value,

        'retail_profit' =>
            $product_retail_profit,

        'wholesale_profit' =>
            $product_wholesale_profit
    ];
}


$retail_profit =
    $inventory_retail_value
    - $inventory_buying_value;


$wholesale_profit =
    $inventory_wholesale_value
    - $inventory_buying_value;


if ($inventory_retail_value > 0) {

    $retail_margin =
        ($retail_profit / $inventory_retail_value)
        * 100;
}


if ($inventory_wholesale_value > 0) {

    $wholesale_margin =
        ($wholesale_profit / $inventory_wholesale_value)
        * 100;
}


// ============================================================
// HELPER FUNCTION
// ============================================================

function money($amount) {

    return "KSh " .
        number_format(
            (float) $amount,
            2
        );
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

<title>Dashboard - BizFlow</title>

<link
    rel="stylesheet"
    href="assets/css/style.css"
>


<style>

.filter-bar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    padding: 12px 16px;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
}

.filter-bar label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}

.filter-bar select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    background: white;
}

.filter-bar .btn-clear {
    padding: 6px 16px;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
}

.filter-bar .btn-clear:hover {
    background: #e5e7eb;
}

.filter-bar .filter-info {
    font-size: 13px;
    color: #6b7280;
    margin-left: auto;
}

.user-table-container {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-top: 20px;
    overflow-x: auto;
}

.user-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.user-table th {
    background: #f3f4f6;
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.user-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f0f0f0;
}

.user-table .right {
    text-align: right;
}

.payment-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.payment-cash {
    background: #dcfce7;
    color: #166534;
}

.payment-bank {
    background: #dbeafe;
    color: #1d4ed8;
}

.payment-mixed {
    background: #fef3c7;
    color: #92400e;
}

.recon-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.recon-resolved {
    background: #d1fae5;
    color: #065f46;
}

.recon-pending {
    background: #fef3c7;
    color: #92400e;
}

.recon-shortage {
    background: #fee2e2;
    color: #991b1b;
}

.recon-excess {
    background: #dbeafe;
    color: #1e40af;
}

.recon-balanced {
    background: #d1fae5;
    color: #065f46;
}

@media (max-width: 700px) {

    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-bar .filter-info {
        margin-left: 0;
    }

}

</style>

</head>


<body>

<div class="app">

<?php include "assets/includes/sidebar.php"; ?>

<main class="main">

<?php include "assets/includes/topbar.php"; ?>

<section class="content">


<!-- ============================================================
     DASHBOARD HEADER
============================================================ -->

<div class="page-title">

    <div>

        <h1>📊 Admin Dashboard</h1>

        <p>
            Here's what's happening with your business today.
        </p>

    </div>

</div>


<!-- ============================================================
     FILTER BAR - User Filter
============================================================ -->

<form method="GET" action="dashboard.php">

<div class="filter-bar">

    <label for="userFilter">
        👤 Filter by User:
    </label>


    <select
        id="userFilter"
        name="user_id"
        onchange="this.form.submit()"
    >

        <option value="0">
            All Users
        </option>


        <?php foreach ($all_users as $user): ?>

            <option
                value="<?= (int) $user['id'] ?>"
                <?= $selected_user == (int) $user['id']
                    ? 'selected'
                    : '' ?>
            >

                <?= htmlspecialchars(
                    $user['full_name']
                    ?? $user['username']
                ) ?>

            </option>

        <?php endforeach; ?>

    </select>


    <a
        href="dashboard.php"
        class="btn-clear"
    >
        Clear Filter
    </a>


    <span class="filter-info">

        <?php if ($selected_user > 0): ?>

            Showing data for selected user

        <?php else: ?>

            Showing data for all users

        <?php endif; ?>

    </span>

</div>

</form>


<!-- ============================================================
     TODAY'S STATISTICS
============================================================ -->

<div class="cards">


    <div class="card">

        <div class="card-title">
            Today's Sales
        </div>

        <div class="card-value">
            KSh <?= number_format($today_sales, 2) ?>
        </div>

        <div style="font-size:12px; color:#6b7280;">
            <?= $today_sales_count ?>
            transactions
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Transactions
        </div>

        <div class="card-value">
            <?= $transactions ?>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Active Products
        </div>

        <div class="card-value">
            <?= $products ?>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Today's Expenses
        </div>

        <div class="card-value">
            KSh <?= number_format($today_expenses, 2) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Today's Gross Profit
        </div>

        <div class="card-value">
            KSh <?= number_format($today_profit, 2) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Bank Transfer
        </div>

        <div
            class="card-value"
            style="color: #7c3aed;"
        >
            KSh <?= number_format($today_bank_sales, 2) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            Cash at Hand
        </div>

        <div
            class="card-value"
            style="color: #16a34a;"
        >
            KSh <?= number_format($cash_at_hand, 2) ?>
        </div>

    </div>


    <div
        class="card"
        style="border-color: #f59e0b;"
    >

        <div class="card-title">
            Today's Returns
        </div>

        <div
            class="card-value"
            style="color: #f59e0b;"
        >
            <?= $today_return_count ?>
        </div>

    </div>


</div>


<!-- ============================================================
     USER PERFORMANCE
============================================================ -->

<h2
    class="section-title"
    style="margin-top: 30px;"
>
    👤 User Performance Today
</h2>


<div class="user-table-container">

<table class="user-table">

<thead>

<tr>

    <th>
        User
    </th>

    <th class="right">
        Transactions
    </th>

    <th class="right">
        Total Sales
    </th>

    <th class="right">
        Cash Received
    </th>

    <th class="right">
        Bank Sales
    </th>

    <th class="right">
        Change Given
    </th>

    <th class="right">
        Net Cash
    </th>

</tr>

</thead>


<tbody>


<?php if (count($user_performance) > 0): ?>


    <?php foreach ($user_performance as $user): ?>


        <?php

        $net_cash =
            $user['cash_received']
            - $user['change_given'];

        ?>


        <tr>

            <td>

                <strong>
                    <?= htmlspecialchars(
                        $user['full_name']
                        ?? 'Unknown'
                    ) ?>
                </strong>

            </td>


            <td class="right">

                <?= $user['total_transactions'] ?>

            </td>


            <td class="right">

                KSh <?= number_format(
                    $user['total_sales'],
                    2
                ) ?>

            </td>


            <td class="right">

                KSh <?= number_format(
                    $user['cash_received'],
                    2
                ) ?>

            </td>


            <td class="right">

                KSh <?= number_format(
                    $user['bank_sales'],
                    2
                ) ?>

            </td>


            <td class="right">

                KSh <?= number_format(
                    $user['change_given'],
                    2
                ) ?>

            </td>


            <td
                class="right
                <?= $net_cash >= 0
                    ? 'profit-positive'
                    : 'profit-negative' ?>"
            >

                KSh <?= number_format(
                    $net_cash,
                    2
                ) ?>

            </td>

        </tr>


    <?php endforeach; ?>


<?php else: ?>


    <tr>

        <td
            colspan="7"
            style="
                text-align:center;
                padding:30px;
                color:#6b7280;
            "
        >

            No user activity recorded today.

        </td>

    </tr>


<?php endif; ?>


</tbody>

</table>

</div>


<!-- ============================================================
     RECENT SALES
============================================================ -->

<h2
    class="section-title"
    style="margin-top: 30px;"
>
    🧾 Recent Sales
</h2>


<div
    class="table-container"
    style="
        background:white;
        border-radius:10px;
        border:1px solid #e5e7eb;
        overflow:hidden;
    "
>


<?php if (count($recent_sales) > 0): ?>


<table
    class="data-table"
    style="
        width:100%;
        border-collapse:collapse;
    "
>


<thead>

<tr style="background:#f9fafb;">

    <th style="padding:10px 14px; text-align:left;">
        Receipt
    </th>

    <th style="padding:10px 14px; text-align:left;">
        User
    </th>

    <th style="padding:10px 14px; text-align:right;">
        Amount
    </th>

    <th style="padding:10px 14px; text-align:left;">
        Payment
    </th>

    <th style="padding:10px 14px; text-align:left;">
        Date
    </th>

    <th style="padding:10px 14px; text-align:center;">
        Action
    </th>

</tr>

</thead>


<tbody>


<?php foreach ($recent_sales as $sale): ?>


<tr style="border-bottom:1px solid #f3f4f6;">


    <td style="padding:10px 14px;">

        <strong>

            <?= htmlspecialchars(
                $sale["receipt_number"]
                ?? '#' . $sale["id"]
            ) ?>

        </strong>

    </td>


    <td style="padding:10px 14px;">

        <?= htmlspecialchars(
            $sale["user_name"]
            ?? 'Unknown'
        ) ?>

    </td>


    <td
        style="
            padding:10px 14px;
            text-align:right;
        "
    >

        KSh <?= number_format(
            $sale["total_amount"],
            2
        ) ?>

    </td>


    <td style="padding:10px 14px;">

        <?php

        $method = strtolower(
            $sale["payment_method"]
            ?? "cash"
        );

        ?>


        <?php if ($method === "bank"): ?>

            <span class="payment-badge payment-bank">
                🏦 Bank
            </span>

        <?php elseif ($method === "mixed"): ?>

            <span class="payment-badge payment-mixed">
                🔄 Mixed
            </span>

        <?php else: ?>

            <span class="payment-badge payment-cash">
                💵 Cash
            </span>

        <?php endif; ?>

    </td>


    <td style="padding:10px 14px;">

        <?= date(
            "d M Y H:i",
            strtotime($sale["sale_date"])
        ) ?>

    </td>


    <td
        style="
            padding:10px 14px;
            text-align:center;
        "
    >

        <a
            href="sales/receipt.php?sale_id=<?= (int)$sale["id"] ?>"
            style="
                color:#2563eb;
                text-decoration:none;
                font-weight:600;
            "
        >
            View Receipt
        </a>

    </td>


</tr>


<?php endforeach; ?>


</tbody>

</table>


<?php else: ?>


<div
    style="
        padding:30px;
        text-align:center;
        color:#6b7280;
    "
>

    No sales recorded yet.

</div>


<?php endif; ?>


</div>


<!-- ============================================================
     QUICK ACTIONS
============================================================ -->

<h2
    class="section-title"
    style="margin-top: 30px;"
>
    Quick Actions
</h2>


<div class="quick-actions">

    <a
        href="/BIZFLOW/sales/pos.php"
        class="action-btn"
    >
        + New Sale
    </a>

    <a
        href="/BIZFLOW/products/view_products.php"
        class="action-btn"
    >
        📦 View Products
    </a>

    <a
        href="/BIZFLOW/products/add.php"
        class="action-btn"
    >
        + Add Product
    </a>

    <a
        href="/BIZFLOW/products/manage_categories.php"
        class="action-btn"
    >
        🗂️ Categories
    </a>

    <a
        href="/BIZFLOW/inventory/inventory.php"
        class="action-btn"
    >
        📊 Inventory
    </a>

    <a
        href="/BIZFLOW/expenses/add_expense.php"
        class="action-btn"
    >
        + Record Expense
    </a>

    <a
        href="/BIZFLOW/users/users.php"
        class="action-btn"
    >
        👥 Users
    </a>

    <a
        href="/BIZFLOW/reports/daily_report.php"
        class="action-btn"
    >
        📊 Daily Report
    </a>

</div>


<!-- ============================================================
     LOW STOCK
============================================================ -->

<div
    style="
        margin-top:30px;
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:25px;
    "
>


<div
    class="table-container"
    style="
        background:white;
        border-radius:10px;
        border:1px solid #e5e7eb;
        overflow:hidden;
        padding:20px;
    "
>


<div
    style="
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:15px;
    "
>

    <h3 style="margin:0;">
        Low Stock
    </h3>

    <a
        href="inventory/inventory.php"
        class="btn-secondary"
    >
        View Inventory
    </a>

</div>


<?php if (count($low_stock_products) > 0): ?>


<table
    class="data-table"
    style="
        width:100%;
        border-collapse:collapse;
    "
>


<thead>

<tr>

    <th
        style="
            padding:8px 10px;
            text-align:left;
        "
    >
        Product
    </th>

    <th
        style="
            padding:8px 10px;
            text-align:right;
        "
    >
        Stock
    </th>

    <th
        style="
            padding:8px 10px;
            text-align:right;
        "
    >
        Reorder
    </th>

</tr>

</thead>


<tbody>


<?php foreach ($low_stock_products as $product): ?>


<tr>


    <td style="padding:8px 10px;">

        <?= htmlspecialchars(
            $product["name"]
        ) ?>

    </td>


    <td
        style="
            padding:8px 10px;
            text-align:right;
        "
    >

        <span class="badge badge-danger">

            <?= number_format(
                $product["stock_quantity"],
                3
            ) ?>

            <?= htmlspecialchars(
                $product["unit"]
            ) ?>

        </span>

    </td>


    <td
        style="
            padding:8px 10px;
            text-align:right;
        "
    >

        <?= number_format(
            $product["reorder_level"],
            3
        ) ?>

        <?= htmlspecialchars(
            $product["unit"]
        ) ?>

    </td>


</tr>


<?php endforeach; ?>


</tbody>

</table>


<?php else: ?>


<p>
    ✅ No products are currently below
    their reorder level.
</p>


<?php endif; ?>


</div>

</div>


</section>

</main>

</div>

</body>

</html>


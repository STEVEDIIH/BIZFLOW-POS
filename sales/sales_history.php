<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");

$payment_method = strtolower(
    trim($_GET["payment_method"] ?? "")
);


/*
|--------------------------------------------------------------------------
| Build sales query
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT

        s.id,
        s.receipt_number,
        s.subtotal,
        s.discount,
        s.tax,
        s.total_amount,
        s.cash_amount,
        s.bank_amount,
        s.payment_method,
        s.sale_status,
        s.sale_date,

        COALESCE(
            (
                SELECT SUM(r.refund_amount)

                FROM returns r

                WHERE r.sale_id = s.id

                AND r.status = 'completed'

            ),
            0
        ) AS refund_amount

    FROM sales s

    WHERE s.business_id = :business_id

    AND s.user_id = :user_id

";


$params = [

    ":business_id" => $business_id,

    ":user_id" => $user_id

];


/*
|--------------------------------------------------------------------------
| Search by receipt
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "

        AND s.receipt_number LIKE :search

    ";

    $params[":search"] =
        "%" . $search . "%";
}


/*
|--------------------------------------------------------------------------
| Payment method filter
|--------------------------------------------------------------------------
*/

if ($payment_method !== "") {

    $sql .= "

        AND s.payment_method = :payment_method

    ";

    $params[":payment_method"] =
        $payment_method;
}


/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$sql .= "

    ORDER BY s.sale_date DESC

";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary_sql = "

    SELECT

        COUNT(*) AS total_transactions,

        COALESCE(
            SUM(s.total_amount),
            0
        ) AS original_sales,

        COALESCE(
            SUM(s.cash_amount),
            0
        ) AS original_cash,

        COALESCE(
            SUM(s.bank_amount),
            0
        ) AS original_bank

    FROM sales s

    WHERE s.business_id = :business_id

    AND s.user_id = :user_id

    AND s.sale_status = 'completed'

";


$summary_stmt = $pdo->prepare($summary_sql);

$summary_stmt->execute([

    ":business_id" => $business_id,

    ":user_id" => $user_id

]);


$summary =
    $summary_stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get total completed refunds
|--------------------------------------------------------------------------
*/

$refund_sql = "

    SELECT

        COALESCE(
            SUM(r.refund_amount),
            0
        ) AS total_refunds

    FROM returns r

    INNER JOIN sales s
        ON r.sale_id = s.id

    WHERE r.business_id = :business_id

    AND s.user_id = :user_id

    AND r.status = 'completed'

";


$refund_stmt = $pdo->prepare($refund_sql);

$refund_stmt->execute([

    ":business_id" => $business_id,

    ":user_id" => $user_id

]);


$total_refunds =
    (float)
    $refund_stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Summary values
|--------------------------------------------------------------------------
*/

$total_transactions =
    (int)
    ($summary["total_transactions"] ?? 0);


$original_sales =
    (float)
    ($summary["original_sales"] ?? 0);


$original_cash =
    (float)
    ($summary["original_cash"] ?? 0);


$original_bank =
    (float)
    ($summary["original_bank"] ?? 0);


/*
|--------------------------------------------------------------------------
| Net sales
|--------------------------------------------------------------------------
*/

$net_sales =
    $original_sales -
    $total_refunds;


/*
|--------------------------------------------------------------------------
| Net cash / Bank
|--------------------------------------------------------------------------
*/

$net_cash = $original_cash;

$net_bank = $original_bank;


/*
|--------------------------------------------------------------------------
| Calculate actual refund allocation
|--------------------------------------------------------------------------
*/

foreach ($sales as $sale) {

    if ($sale["sale_status"] !== "completed") {
        continue;
    }

    $sale_total =
        (float) $sale["total_amount"];

    $sale_cash =
        (float) $sale["cash_amount"];

    $sale_bank =
        (float) $sale["bank_amount"];

    $sale_refund =
        (float) $sale["refund_amount"];


    if (
        $sale_refund <= 0 ||
        $sale_total <= 0
    ) {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Allocate refund according to original payment proportions
    |--------------------------------------------------------------------------
    */

    $cash_ratio =
        $sale_cash / $sale_total;

    $bank_ratio =
        $sale_bank / $sale_total;


    $refund_cash =
        $sale_refund *
        $cash_ratio;


    $refund_bank =
        $sale_refund *
        $bank_ratio;


    $net_cash -= $refund_cash;

    $net_bank -= $refund_bank;

}


/*
|--------------------------------------------------------------------------
| Prevent negative display values caused by rounding
|--------------------------------------------------------------------------
*/

$net_cash =
    max(0, $net_cash);


$net_bank =
    max(0, $net_bank);


$net_sales =
    max(0, $net_sales);

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Sales History - BizFlow</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>

.history-summary {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(190px, 1fr)
        );

    gap: 15px;

    margin-bottom: 25px;

}


.summary-box {

    background: white;

    border: 1px solid #e5e7eb;

    border-radius: 10px;

    padding: 20px;

}


.summary-title {

    color: #6b7280;

    font-size: 14px;

    margin-bottom: 8px;

}


.summary-value {

    font-size: 23px;

    font-weight: 700;

}


.cash-value {

    color: #15803d;

}


.bank-value {

    color: #166534;

}


.refund-value {

    color: #dc2626;

}


.net-value {

    color: #1d4ed8;

}


.filter-box {

    background: white;

    border: 1px solid #e5e7eb;

    border-radius: 10px;

    padding: 20px;

    margin-bottom: 25px;

}


.filter-form {

    display: grid;

    grid-template-columns:
        2fr 1fr auto auto;

    gap: 10px;

    align-items: end;

}


.filter-group {

    display: flex;

    flex-direction: column;

    gap: 6px;

}


.filter-group label {

    font-size: 13px;

    color: #6b7280;

}


.filter-group input,
.filter-group select {

    padding: 10px;

    border: 1px solid #d1d5db;

    border-radius: 6px;

    font-size: 14px;

}


.btn-filter {

    padding: 10px 18px;

    border: none;

    border-radius: 6px;

    background: #111827;

    color: white;

    cursor: pointer;

}


.btn-clear {

    padding: 10px 18px;

    border-radius: 6px;

    background: white;

    color: #374151;

    border: 1px solid #d1d5db;

    text-decoration: none;

    text-align: center;

}


.history-table-container {

    background: white;

    border: 1px solid #e5e7eb;

    border-radius: 10px;

    padding: 20px;

    overflow-x: auto;

}


.history-table {

    width: 100%;

    border-collapse: collapse;

}


.history-table th,
.history-table td {

    padding: 12px;

    border-bottom: 1px solid #e5e7eb;

    text-align: left;

    white-space: nowrap;

}


.history-table th {

    color: #6b7280;

    font-size: 13px;

}


.payment-badge {

    display: inline-block;

    padding: 4px 9px;

    border-radius: 20px;

    font-size: 12px;

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


.status-completed {

    color: #15803d;

    font-weight: 600;

}


.status-refunded {

    color: #d97706;

    font-weight: 600;

}


.status-partial {

    color: #b45309;

    font-weight: 600;

}


.status-voided {

    color: #dc2626;

    font-weight: 600;

}


.refund-cell {

    color: #dc2626;

    font-weight: 600;

}


.net-cell {

    color: #1d4ed8;

    font-weight: 700;

}


.view-receipt {

    text-decoration: none;

    font-size: 13px;

    font-weight: 600;

}


@media (max-width: 700px) {

    .filter-form {

        grid-template-columns: 1fr;

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


<!-- =========================================================
     HEADER
========================================================= -->

<div class="page-title">

<div>

<h1>
Sales History
</h1>

<p>
View sales processed by you, including customer returns.
</p>

</div>


<a
    href="../cashier/dashboard.php"
    class="btn-secondary"
>
← Cashier Dashboard
</a>

</div>


<!-- =========================================================
     SUMMARY
========================================================= -->

<div class="history-summary">


<div class="summary-box">

<div class="summary-title">
Transactions
</div>

<div class="summary-value">

<?= $total_transactions ?>

</div>

</div>


<div class="summary-box">

<div class="summary-title">
Original Sales
</div>

<div class="summary-value">

KSh
<?= number_format(
    $original_sales,
    2
) ?>

</div>

</div>


<div class="summary-box">

<div class="summary-title">
Refunds
</div>

<div class="summary-value refund-value">

KSh
<?= number_format(
    $total_refunds,
    2
) ?>

</div>

</div>


<div class="summary-box">

<div class="summary-title">
Net Sales
</div>

<div class="summary-value net-value">

KSh
<?= number_format(
    $net_sales,
    2
) ?>

</div>

</div>


<div class="summary-box">

<div class="summary-title">
Net Cash
</div>

<div class="summary-value cash-value">

KSh
<?= number_format(
    $net_cash,
    2
) ?>

</div>

</div>


<div class="summary-box">

<div class="summary-title">
Net Bank Transfer
</div>

<div class="summary-value bank-value">

KSh
<?= number_format(
    $net_bank,
    2
) ?>

</div>

</div>


</div>


<!-- =========================================================
     FILTERS
========================================================= -->

<div class="filter-box">


<form
    method="GET"
    class="filter-form"
>


<div class="filter-group">

<label>
Receipt Number
</label>

<input
    type="text"
    name="search"
    placeholder="Search receipt..."
    value="<?= htmlspecialchars($search) ?>"
>

</div>


<div class="filter-group">

<label>
Payment Method
</label>

<select
    name="payment_method"
>

<option value="">
All Payments
</option>


<option
    value="cash"
    <?= $payment_method === "cash"
        ? "selected"
        : "" ?>
>
Cash
</option>


<option
    value="bank"
    <?= $payment_method === "bank"
        ? "selected"
        : "" ?>
>
Bank Transfer
</option>


<option
    value="mixed"
    <?= $payment_method === "mixed"
        ? "selected"
        : "" ?>
>
Cash + Bank Transfer
</option>

</select>

</div>


<button
    type="submit"
    class="btn-filter"
>
Search
</button>


<a
    href="sales_history.php"
    class="btn-clear"
>
Clear
</a>


</form>

</div>


<!-- =========================================================
     SALES TABLE
========================================================= -->

<div class="history-table-container">


<table class="history-table">


<thead>

<tr>

<th>
Receipt
</th>

<th>
Original Total
</th>

<th>
Refund
</th>

<th>
Net Sale
</th>

<th>
Cash
</th>

<th>
Bank Transfer
</th>

<th>
Payment
</th>

<th>
Status
</th>

<th>
Date
</th>

<th>
Action
</th>

</tr>

</thead>


<tbody>


<?php if (!empty($sales)): ?>


<?php foreach ($sales as $sale): ?>


<?php

$original_total =
    (float)
    $sale["total_amount"];


$refund =
    (float)
    $sale["refund_amount"];


$net_sale =
    max(
        0,
        $original_total -
        $refund
    );


$cash =
    (float)
    $sale["cash_amount"];


$bank =
    (float)
    $sale["bank_amount"];


/*
|--------------------------------------------------------------------------
| Determine displayed status
|--------------------------------------------------------------------------
*/

$status =
    $sale["sale_status"];


if (
    $status === "completed" &&
    $refund > 0
) {

    if (
        $refund >=
        $original_total
    ) {

        $display_status =
            "refunded";

    } else {

        $display_status =
            "partial";

    }

} else {

    $display_status =
        $status;

}


/*
|--------------------------------------------------------------------------
| Payment method
|--------------------------------------------------------------------------
*/

$method =
    strtolower(
        $sale["payment_method"]
        ?? "cash"
    );

?>


<tr>


<!-- RECEIPT -->

<td>

<strong>

<?= htmlspecialchars(
    $sale["receipt_number"]
) ?>

</strong>

</td>


<!-- ORIGINAL TOTAL -->

<td>

KSh
<?= number_format(
    $original_total,
    2
) ?>

</td>


<!-- REFUND -->

<td class="refund-cell">

KSh
<?= number_format(
    $refund,
    2
) ?>

</td>


<!-- NET SALE -->

<td class="net-cell">

KSh
<?= number_format(
    $net_sale,
    2
) ?>

</td>


<!-- CASH -->

<td>

KSh
<?= number_format(
    $cash,
    2
) ?>

</td>


<!-- BANK TRANSFER -->

<td>

KSh
<?= number_format(
    $bank,
    2
) ?>

</td>


<!-- PAYMENT -->

<td>


<?php if (
    $method === "bank"
): ?>

<span
    class="
        payment-badge
        payment-bank
    "
>
Bank Transfer
</span>


<?php elseif (
    $method === "mixed"
): ?>

<span
    class="
        payment-badge
        payment-mixed
    "
>
Cash + Bank
</span>


<?php else: ?>

<span
    class="
        payment-badge
        payment-cash
    "
>
Cash
</span>

<?php endif; ?>


</td>


<!-- STATUS -->

<td>


<?php if (
    $display_status ===
    "completed"
): ?>

<span
    class="
        status-completed
    "
>
Completed
</span>


<?php elseif (
    $display_status ===
    "partial"
): ?>

<span
    class="
        status-partial
    "
>
Partially Returned
</span>


<?php elseif (
    $display_status ===
    "refunded"
): ?>

<span
    class="
        status-refunded
    "
>
Fully Refunded
</span>


<?php else: ?>

<span
    class="
        status-voided
    "
>
Voided
</span>

<?php endif; ?>


</td>


<!-- DATE -->

<td>

<?= date(
    "d M Y H:i",
    strtotime(
        $sale["sale_date"]
    )
) ?>

</td>


<!-- ACTION -->

<td>

<a
    href="receipt.php?sale_id=<?= (int)$sale["id"] ?>"
    class="view-receipt"
>
View Receipt
</a>

</td>


</tr>


<?php endforeach; ?>


<?php else: ?>


<tr>

<td
    colspan="10"
    style="
        text-align:center;
        padding:40px;
        color:#6b7280;
    "
>

No sales found.

</td>

</tr>


<?php endif; ?>


</tbody>

</table>

</div>


</section>

</main>

</div>


</body>

</html>
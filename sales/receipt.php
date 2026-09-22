
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$sale_id = (int) ($_GET["sale_id"] ?? 0);
$change = (float) ($_GET["change"] ?? 0);

// Check sale ID
if ($sale_id <= 0) {
    $sale_id = (int) ($_POST["sale_id"] ?? 0);
}

if ($sale_id <= 0) {
    die(
        "Invalid sale. Sale ID: " .
        $sale_id .
        " | GET: " .
        print_r($_GET, true)
    );
}


/*
|--------------------------------------------------------------------------
| GET SALE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        s.*,
        u.full_name AS cashier_name,
        b.business_name
    FROM sales s
    INNER JOIN users u
        ON s.user_id = u.id
    INNER JOIN businesses b
        ON s.business_id = b.id
    WHERE s.id = :sale_id
    AND s.business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ":sale_id" => $sale_id,
    ":business_id" => $business_id
]);

$sale = $stmt->fetch();

if (!$sale) {
    die(
        "Sale not found. Sale ID: " .
        $sale_id .
        " | Business ID: " .
        $business_id
    );
}


/*
|--------------------------------------------------------------------------
| GET SALE ITEMS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        si.quantity,
        si.unit_price,
        si.total,
        p.name,
        p.unit
    FROM sale_items si
    INNER JOIN products p
        ON si.product_id = p.id
    WHERE si.sale_id = :sale_id
    ORDER BY si.id ASC
");

$stmt->execute([
    ":sale_id" => $sale_id
]);

$items = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| PAYMENT INFORMATION
|--------------------------------------------------------------------------
*/

$payment_method = strtolower($sale["payment_method"] ?? "cash");

$cash_amount = (float) ($sale["cash_amount"] ?? 0);
$bank_amount = (float) ($sale["bank_amount"] ?? 0);

$total_amount = (float) $sale["total_amount"];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
Receipt <?= htmlspecialchars($sale["receipt_number"]) ?>
</title>

<link rel="stylesheet" href="../assets/css/style.css">

<style>

/* =========================================================
   RESET
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}


/* =========================================================
   SCREEN PREVIEW
========================================================= */

html,
body {
    margin: 0;
    padding: 0;
}

body {
    background: #f3f4f6;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 20px;
    font-family: "Courier New", Courier, monospace;
}

.receipt-wrapper {
    width: 100%;
    max-width: 440px;
    background: #ffffff;
    padding: 25px 22px;
    border-radius: 14px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
}


/* =========================================================
   HEADER
========================================================= */

.receipt-header {
    text-align: center;
    border-bottom: 2px dashed #e5e7eb;
    padding-bottom: 12px;
    margin-bottom: 12px;
}

.receipt-header .logo {
    font-size: 25px;
    font-weight: 900;
    letter-spacing: 2px;
    color: #111827;
}

.receipt-header .logo span {
    color: #2563eb;
}

.receipt-header .subtitle {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

.receipt-header .thank-you {
    font-size: 12px;
    font-weight: bold;
    color: #16a34a;
    margin-top: 6px;
}


/* =========================================================
   RECEIPT INFORMATION
========================================================= */

.receipt-info {
    font-size: 12px;
    margin-bottom: 10px;
    padding-bottom: 9px;
    border-bottom: 1px dashed #e5e7eb;
}

.receipt-info .row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 0;
}

.receipt-info .label {
    color: #6b7280;
}

.receipt-info .value {
    font-weight: 600;
    color: #111827;
    text-align: right;
}


/* =========================================================
   ITEMS TABLE
========================================================= */

.receipt-items {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 10px;
    font-size: 12px;
    table-layout: fixed;
}

.receipt-items th:nth-child(1) {
    width: 43%;
}

.receipt-items th:nth-child(2) {
    width: 14%;
}

.receipt-items th:nth-child(3) {
    width: 21%;
}

.receipt-items th:nth-child(4) {
    width: 22%;
}

.receipt-items thead th {
    text-align: left;
    padding: 4px 2px 5px;
    border-bottom: 1px solid #d1d5db;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 10px;
}

.receipt-items thead th:nth-child(2) {
    text-align: center;
}

.receipt-items thead th:nth-child(3),
.receipt-items thead th:nth-child(4) {
    text-align: right;
}

.receipt-items tbody td {
    padding: 4px 2px;
    border-bottom: 1px dotted #e5e7eb;
    vertical-align: top;
    overflow-wrap: anywhere;
}

.receipt-items tbody td:nth-child(2) {
    text-align: center;
}

.receipt-items tbody td:nth-child(3),
.receipt-items tbody td:nth-child(4) {
    text-align: right;
    white-space: nowrap;
}

.receipt-items tbody td:last-child {
    font-weight: 600;
}

.receipt-items tbody tr:last-child td {
    border-bottom: none;
}

.receipt-items .product-name {
    font-weight: 500;
    overflow-wrap: anywhere;
}

.receipt-items .product-meta {
    font-size: 9px;
    color: #6b7280;
    margin-top: 1px;
}


/* =========================================================
   TOTALS
========================================================= */

.receipt-totals {
    border-top: 2px dashed #d1d5db;
    padding-top: 8px;
    margin-top: 3px;
}

.receipt-totals .row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 0;
    font-size: 12px;
}

.receipt-totals .label {
    color: #6b7280;
}

.receipt-totals .value {
    text-align: right;
}

.receipt-totals .discount .value {
    color: #16a34a;
}

.receipt-totals .grand-total {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 7px 0 3px;
    border-top: 2px solid #111827;
    margin-top: 5px;
    font-size: 18px;
    font-weight: 900;
}

.receipt-totals .grand-total .label,
.receipt-totals .grand-total .value {
    color: #111827;
}


/* =========================================================
   PAYMENT
========================================================= */

.payment-box {
    border-top: 1px dashed #d1d5db;
    padding-top: 8px;
    margin-top: 8px;
}

.payment-title {
    font-size: 10px;
    text-transform: uppercase;
    color: #6b7280;
    font-weight: 600;
    margin-bottom: 4px;
}

.payment-box .row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 2px 0;
    font-size: 11px;
}

.payment-box .label {
    color: #6b7280;
}

.payment-box .value {
    font-weight: 600;
    text-align: right;
}

.payment-box .change .value {
    color: #16a34a;
    font-size: 13px;
}


/* =========================================================
   FOOTER
========================================================= */

.receipt-footer {
    text-align: center;
    border-top: 1px dashed #e5e7eb;
    padding-top: 9px;
    margin-top: 9px;
}

.receipt-footer .message {
    font-size: 12px;
    font-weight: 700;
}

.receipt-footer .sub-message {
    font-size: 10px;
    color: #6b7280;
    margin-top: 2px;
}

.receipt-footer .powered {
    font-size: 9px;
    color: #9ca3af;
    margin-top: 5px;
}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.receipt-actions {
    display: flex;
    gap: 8px;
    margin-top: 15px;
}

.receipt-actions button,
.receipt-actions a {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 7px;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
}

.btn-print {
    background: #111827;
    color: white;
}

.btn-new-sale {
    background: #22c55e;
    color: white;
}


/* =========================================================
   STATUS
========================================================= */

.status-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 600;
}

.status-badge.completed {
    background: #d1fae5;
    color: #065f46;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 480px) {

    body {
        padding: 10px;
    }

    .receipt-wrapper {
        padding: 18px 12px;
    }

    .receipt-items {
        font-size: 11px;
    }

}


/* =========================================================
   80MM THERMAL PRINTER
========================================================= */

@media print {

    /*
     * 80mm thermal paper.
     *
     * The actual printable area is normally around 72mm.
     * This prevents the receipt from touching the printer edges.
     */

    @page {
        size: 80mm auto;
        margin: 0;
    }


    html {
        width: 80mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }


    body {
        width: 80mm !important;
        max-width: 80mm !important;
        min-width: 80mm !important;

        margin: 0 !important;
        padding: 0 !important;

        display: block !important;

        background: #ffffff !important;

        font-family: "Courier New", Courier, monospace;

        font-size: 9px;

        color: #000000;

        /*
         * Important:
         * Do not give the body a fixed height.
         * The receipt height must be determined by its content.
         */
        min-height: 0 !important;
        height: auto !important;
    }


    /*
     * Printable content.
     *
     * 80mm paper
     * minus 4mm left
     * minus 4mm right
     * = approximately 72mm usable width.
     */

    .receipt-wrapper {

        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;

        margin: 0 auto !important;

        padding: 2mm 0 2mm 0 !important;

        background: #ffffff !important;

        border-radius: 0 !important;

        box-shadow: none !important;

        overflow: visible !important;
    }


    /* =====================================================
       HEADER
    ===================================================== */

    .receipt-header {

        padding-bottom: 3mm;

        margin-bottom: 3mm;

        border-bottom: 1px dashed #000000;
    }

    .receipt-header .logo {

        font-size: 18px;

        letter-spacing: 1px;

        color: #000000;
    }

    .receipt-header .logo span {

        color: #000000;
    }

    .receipt-header .subtitle {

        font-size: 8px;

        color: #000000;

        margin-top: 1px;

        overflow-wrap: anywhere;
    }

    .receipt-header .thank-you {

        font-size: 8px;

        color: #000000;

        margin-top: 2px;
    }


    /* =====================================================
       SALE INFORMATION
    ===================================================== */

    .receipt-info {

        font-size: 8px;

        margin-bottom: 3mm;

        padding-bottom: 2mm;

        border-bottom: 1px dashed #000000;
    }

    .receipt-info .row {

        padding: 1px 0;

        gap: 5px;
    }

    .receipt-info .label,
    .receipt-info .value {

        color: #000000;
    }


    /* =====================================================
       ITEMS
    ===================================================== */

    .receipt-items {

        width: 72mm !important;

        max-width: 72mm !important;

        margin-bottom: 2mm;

        font-size: 8px;

        table-layout: fixed;
    }

    .receipt-items th:nth-child(1) {
        width: 43%;
    }

    .receipt-items th:nth-child(2) {
        width: 14%;
    }

    .receipt-items th:nth-child(3) {
        width: 21%;
    }

    .receipt-items th:nth-child(4) {
        width: 22%;
    }

    .receipt-items thead th {

        padding: 1mm 1px 1.5mm;

        font-size: 7px;

        color: #000000;

        border-bottom: 1px solid #000000;
    }

    .receipt-items tbody td {

        padding: 1mm 1px;

        border-bottom: 1px dotted #777777;

        color: #000000;

        vertical-align: top;

        overflow-wrap: anywhere;
    }

    .receipt-items tbody td:nth-child(2) {

        text-align: center;

        white-space: nowrap;
    }

    .receipt-items tbody td:nth-child(3),
    .receipt-items tbody td:nth-child(4) {

        text-align: right;

        white-space: nowrap;
    }

    .receipt-items .product-name {

        font-size: 8px;

        color: #000000;

        overflow-wrap: anywhere;
    }

    .receipt-items .product-meta {

        font-size: 6px;

        color: #000000;

        margin-top: 0;
    }


    /* =====================================================
       TOTALS
    ===================================================== */

    .receipt-totals {

        border-top: 1px dashed #000000;

        padding-top: 2mm;

        margin-top: 1mm;
    }

    .receipt-totals .row {

        font-size: 8px;

        padding: 1px 0;
    }

    .receipt-totals .label,
    .receipt-totals .value {

        color: #000000;
    }

    .receipt-totals .discount .value {

        color: #000000;
    }

    .receipt-totals .grand-total {

        padding: 2mm 0 1mm;

        margin-top: 1mm;

        border-top: 1px solid #000000;

        font-size: 13px;
    }


    /* =====================================================
       PAYMENT
    ===================================================== */

    .payment-box {

        border-top: 1px dashed #000000;

        padding-top: 2mm;

        margin-top: 2mm;
    }

    .payment-title {

        font-size: 7px;

        color: #000000;

        margin-bottom: 1mm;
    }

    .payment-box .row {

        font-size: 8px;

        padding: 1px 0;
    }

    .payment-box .label,
    .payment-box .value {

        color: #000000;
    }

    .payment-box .change .value {

        color: #000000;

        font-size: 10px;
    }


    /* =====================================================
       FOOTER
    ===================================================== */

    .receipt-footer {

        border-top: 1px dashed #000000;

        padding-top: 2mm;

        margin-top: 2mm;
    }

    .receipt-footer .message {

        font-size: 9px;

        color: #000000;
    }

    .receipt-footer .sub-message {

        font-size: 7px;

        color: #000000;

        margin-top: 1px;
    }

    .receipt-footer .powered {

        font-size: 6px;

        color: #000000;

        margin-top: 2px;
    }


    /* =====================================================
       HIDE SCREEN CONTROLS
    ===================================================== */

    .receipt-actions {

        display: none !important;
    }


    /* =====================================================
       STATUS
    ===================================================== */

    .status-badge {

        padding: 0;

        border: none;

        border-radius: 0;

        background: none !important;

        color: #000000 !important;

        font-size: 8px;
    }


    /* =====================================================
       PRINTER FRIENDLY
    ===================================================== */

    *,
    *::before,
    *::after {

        box-shadow: none !important;

        text-shadow: none !important;
    }


    /*
     * Avoid splitting the receipt into strange pieces.
     */

    .receipt-header,
    .receipt-info,
    .receipt-items,
    .receipt-totals,
    .payment-box,
    .receipt-footer {

        break-inside: avoid;

        page-break-inside: avoid;
    }

}


/* =========================================================
   PRINT CLEANUP
========================================================= */

@media print {

    /*
     * Remove unnecessary webpage elements.
     */


    /*
     * =====================================================
     * EXTRA BOLD THERMAL PRINTER MODE
     * =====================================================
     *
     * Make every printable receipt character heavy enough
     * for an 80mm thermal printer to reproduce clearly.
     */

    .receipt-wrapper,
    .receipt-wrapper *,
    .receipt-wrapper *::before,
    .receipt-wrapper *::after {
        font-weight: 900 !important;
        color: #000000 !important;
        text-shadow: none !important;
    }

    /* Prevent very small text from disappearing on thermal paper. */
    .receipt-header .subtitle {
        font-size: 9px !important;
    }

    .receipt-header .thank-you {
        font-size: 9px !important;
    }

    .receipt-items .product-meta {
        font-size: 7px !important;
    }

    .receipt-footer .sub-message {
        font-size: 8px !important;
    }

    .receipt-footer .powered {
        font-size: 7px !important;
    }

    .status-badge {
        font-size: 8px !important;
        font-weight: 900 !important;
    }



    header,
    nav,
    footer:not(.receipt-footer),
    aside {

        display: none !important;
    }

}
</style>

</head>


<body>

<div class="receipt-wrapper">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="receipt-header">

        <div class="logo">
            Biz<span>Flow</span>
        </div>

        <div class="subtitle">
            <?= htmlspecialchars($sale["business_name"]) ?>
        </div>

        <div class="thank-you">
            Thank You For Your Business!
        </div>

    </div>


    <!-- =====================================================
         SALE INFORMATION
    ====================================================== -->

    <div class="receipt-info">

        <div class="row">

            <span class="label">
                Receipt
            </span>

            <span class="value">
                <?= htmlspecialchars($sale["receipt_number"]) ?>
            </span>

        </div>


        <div class="row">

            <span class="label">
                Date
            </span>

            <span class="value">
                <?= date(
                    "d M Y H:i",
                    strtotime($sale["sale_date"])
                ) ?>
            </span>

        </div>


        <div class="row">

            <span class="label">
                Cashier
            </span>

            <span class="value">
                <?= htmlspecialchars($sale["cashier_name"]) ?>
            </span>

        </div>


        <div class="row">

            <span class="label">
                Status
            </span>

            <span class="value">

                <span class="status-badge completed">

                    <?= ucfirst(
                        $sale["sale_status"] ?? "completed"
                    ) ?>

                </span>

            </span>

        </div>

    </div>


    <!-- =====================================================
         ITEMS
    ====================================================== -->

    <table class="receipt-items">

        <thead>

            <tr>

                <th>
                    Product
                </th>

                <th>
                    Qty
                </th>

                <th>
                    Price
                </th>

                <th>
                    Total
                </th>

            </tr>

        </thead>


        <tbody>

        <?php foreach ($items as $item): ?>

            <tr>

                <td>

                    <div class="product-name">

                        <?= htmlspecialchars(
                            $item["name"]
                        ) ?>

                    </div>

                    <?php if (!empty($item["unit"])): ?>

                        <div class="product-meta">

                            <?= htmlspecialchars(
                                $item["unit"]
                            ) ?>

                        </div>

                    <?php endif; ?>

                </td>


                <td>

                    <?= rtrim(
                        rtrim(
                            number_format(
                                (float) $item["quantity"],
                                3
                            ),
                            "0"
                        ),
                        "."
                    ) ?>

                </td>


                <td>

                    <?= number_format(
                        (float) $item["unit_price"],
                        2
                    ) ?>

                </td>


                <td>

                    <?= number_format(
                        (float) $item["total"],
                        2
                    ) ?>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>


    <!-- =====================================================
         TOTALS
    ====================================================== -->

    <div class="receipt-totals">


        <div class="row">

            <span class="label">
                Subtotal
            </span>

            <span class="value">
                KSh <?= number_format(
                    (float) $sale["subtotal"],
                    2
                ) ?>
            </span>

        </div>


        <?php if (
            (float) $sale["discount_amount"] > 0
        ): ?>

            <div class="row discount">

                <span class="label">
                    Discount
                </span>

                <span class="value">
                    - KSh <?= number_format(
                        (float) $sale["discount_amount"],
                        2
                    ) ?>
                </span>

            </div>


            <?php if (
                !empty($sale["discount_reason"])
            ): ?>

                <div
                    class="row"
                    style="font-size:9px;"
                >

                    <span class="label">
                        Reason
                    </span>

                    <span class="value">
                        <?= htmlspecialchars(
                            $sale["discount_reason"]
                        ) ?>
                    </span>

                </div>

            <?php endif; ?>

        <?php endif; ?>


        <?php if (
            (float) $sale["tax"] > 0
        ): ?>

            <div class="row">

                <span class="label">
                    Tax
                </span>

                <span class="value">
                    KSh <?= number_format(
                        (float) $sale["tax"],
                        2
                    ) ?>
                </span>

            </div>

        <?php endif; ?>


        <div class="grand-total">

            <span class="label">
                TOTAL
            </span>

            <span class="value">
                KSh <?= number_format(
                    $total_amount,
                    2
                ) ?>
            </span>

        </div>

    </div>


    <!-- =====================================================
         PAYMENT
    ====================================================== -->

    <div class="payment-box">

        <div class="payment-title">
            Payment
        </div>


        <div class="row">

            <span class="label">
                Method
            </span>

            <span class="value">

                <?php

                if ($payment_method === "cash") {

                    echo "Cash";

                } elseif ($payment_method === "bank") {

                    echo "Bank Transfer";

                } else {

                    echo "Cash + Bank";

                }

                ?>

            </span>

        </div>


        <?php if ($cash_amount > 0): ?>

            <div class="row">

                <span class="label">
                    Cash
                </span>

                <span class="value">
                    KSh <?= number_format(
                        $cash_amount,
                        2
                    ) ?>
                </span>

            </div>

        <?php endif; ?>


        <?php if ($bank_amount > 0): ?>

            <div class="row">

                <span class="label">
                    Bank
                </span>

                <span class="value">
                    KSh <?= number_format(
                        $bank_amount,
                        2
                    ) ?>
                </span>

            </div>

        <?php endif; ?>


        <?php if ($change > 0): ?>

            <div class="row change">

                <span class="label">
                    Change
                </span>

                <span class="value">
                    KSh <?= number_format(
                        $change,
                        2
                    ) ?>
                </span>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <div class="receipt-footer">

        <div class="message">
            Thank You For Shopping!
        </div>

        <div class="sub-message">
            We hope to see you again soon.
        </div>

        <div class="powered">
            Powered by BizFlow POS
        </div>

    </div>


    <!-- =====================================================
         ACTIONS
    ====================================================== -->

    <div class="receipt-actions">

        <button
            type="button"
            class="btn-print"
            onclick="window.print()"
        >
            Print Receipt
        </button>


        <a
            href="pos.php"
            class="btn-new-sale"
        >
            New Sale
        </a>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| AUTO PRINT
|--------------------------------------------------------------------------
*/

window.onload = function () {

    setTimeout(function () {

        window.print();

    }, 500);

};

</script>


</body>

</html>


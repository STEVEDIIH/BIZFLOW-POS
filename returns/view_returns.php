```php
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];

$return_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($return_id <= 0) {
    header("Location: returns.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Get return information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.return_number,
        r.sale_id,
        r.user_id,
        r.reason,
        r.refund_amount,
        r.status,
        r.created_at,

        s.receipt_number,
        s.sale_date,
        s.total_amount AS original_sale_total,

        u.full_name AS processed_by

    FROM returns r

    LEFT JOIN sales s
        ON r.sale_id = s.id

    LEFT JOIN users u
        ON r.user_id = u.id

    WHERE r.id = :return_id
      AND r.business_id = :business_id

    LIMIT 1
");

$stmt->execute([
    ':return_id' => $return_id,
    ':business_id' => $business_id
]);

$return = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$return) {
    die("Return not found.");
}


/*
|--------------------------------------------------------------------------
| Get returned products
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ri.id,
        ri.product_id,
        ri.sale_item_id,
        ri.quantity,
        ri.unit_price,
        ri.total,

        p.name AS product_name

    FROM return_items ri

    LEFT JOIN products p
        ON ri.product_id = p.id

    WHERE ri.return_id = :return_id

    ORDER BY ri.id ASC
");

$stmt->execute([
    ':return_id' => $return_id
]);

$return_items = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/

$success = $_GET['success'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Return <?= htmlspecialchars($return['return_number']) ?> - BizFlow
    </title>

    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body>

<div class="app">

    <?php include "../assets/includes/sidebar.php"; ?>

    <main class="main">

        <?php include "../assets/includes/topbar.php"; ?>

        <section class="content">

            <!-- HEADER -->

            <div class="page-title">

                <div>

                    <h1>
                        Return Details
                    </h1>

                    <p>
                        <?= htmlspecialchars(
                            $return['return_number']
                        ) ?>
                    </p>

                </div>

                <div class="action-bar">

                    <a
                        href="returns.php"
                        class="btn-secondary"
                    >
                        ← Back to Returns
                    </a>

                    <a
                        href="process_return.php"
                        class="btn-primary"
                    >
                        + New Return
                    </a>

                </div>

            </div>


            <?php if ($success): ?>

                <div class="alert alert-success">

                    <?= htmlspecialchars($success) ?>

                </div>

            <?php endif; ?>


            <!-- SUMMARY -->

            <div class="cards">

                <div class="card">

                    <div class="card-title">
                        Return Number
                    </div>

                    <div class="card-value"
                         style="font-size:20px;">

                        <?= htmlspecialchars(
                            $return['return_number']
                        ) ?>

                    </div>

                </div>


                <div class="card">

                    <div class="card-title">
                        Refund Amount
                    </div>

                    <div class="card-value">

                        KSh
                        <?= number_format(
                            (float)$return['refund_amount'],
                            2
                        ) ?>

                    </div>

                </div>


                <div class="card">

                    <div class="card-title">
                        Original Receipt
                    </div>

                    <div class="card-value"
                         style="font-size:18px;">

                        <?= htmlspecialchars(
                            $return['receipt_number'] ?? '-'
                        ) ?>

                    </div>

                </div>


                <div class="card">

                    <div class="card-title">
                        Status
                    </div>

                    <div style="margin-top:10px;">

                        <?php if ($return['status'] === 'completed'): ?>

                            <span class="badge badge-success">
                                Completed
                            </span>

                        <?php else: ?>

                            <span class="badge badge-danger">
                                Cancelled
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- RETURN INFORMATION -->

            <div
                class="table-container"
                style="margin-top:25px;"
            >

                <h2 style="margin-bottom:20px;">
                    Return Information
                </h2>

                <div class="form-row">

                    <div>

                        <strong>
                            Original Receipt
                        </strong>

                        <p style="margin-top:5px;">

                            <?= htmlspecialchars(
                                $return['receipt_number'] ?? '-'
                            ) ?>

                        </p>

                    </div>


                    <div>

                        <strong>
                            Original Sale Date
                        </strong>

                        <p style="margin-top:5px;">

                            <?= date(
                                'd M Y H:i',
                                strtotime($return['sale_date'])
                            ) ?>

                        </p>

                    </div>


                    <div>

                        <strong>
                            Processed By
                        </strong>

                        <p style="margin-top:5px;">

                            <?= htmlspecialchars(
                                $return['processed_by'] ?? 'Unknown'
                            ) ?>

                        </p>

                    </div>


                    <div>

                        <strong>
                            Return Date
                        </strong>

                        <p style="margin-top:5px;">

                            <?= date(
                                'd M Y H:i',
                                strtotime($return['created_at'])
                            ) ?>

                        </p>

                    </div>

                </div>


                <div
                    style="
                        margin-top:25px;
                        padding:15px;
                        background:#f5f6fa;
                        border-radius:8px;
                    "
                >

                    <strong>
                        Reason
                    </strong>

                    <p style="margin-top:5px;">

                        <?= htmlspecialchars(
                            $return['reason'] ?? 'No reason provided'
                        ) ?>

                    </p>

                </div>

            </div>


            <!-- RETURNED PRODUCTS -->

            <div
                class="table-container"
                style="margin-top:25px;"
            >

                <h2 style="margin-bottom:20px;">
                    Returned Products
                </h2>

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>Product</th>

                            <th>Quantity</th>

                            <th>Unit Price</th>

                            <th>Total Refund</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (count($return_items) > 0): ?>

                        <?php
                        $counter = 1;
                        ?>

                        <?php foreach ($return_items as $item): ?>

                            <tr>

                                <td>
                                    <?= $counter++ ?>
                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $item['product_name'] ?? 'Unknown Product'
                                    ) ?>

                                </td>

                                <td>

                                    <?= rtrim(
                                        rtrim(
                                            number_format(
                                                (float)$item['quantity'],
                                                3,
                                                '.',
                                                ''
                                            ),
                                            '0'
                                        ),
                                        '.'
                                    ) ?>

                                </td>

                                <td>

                                    KSh
                                    <?= number_format(
                                        (float)$item['unit_price'],
                                        2
                                    ) ?>

                                </td>

                                <td>

                                    <strong>

                                        KSh
                                        <?= number_format(
                                            (float)$item['total'],
                                            2
                                        ) ?>

                                    </strong>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="5"
                                style="text-align:center;"
                            >

                                No returned products found.

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>


                <!-- TOTAL -->

                <div
                    style="
                        display:flex;
                        justify-content:flex-end;
                        margin-top:20px;
                    "
                >

                    <div
                        style="
                            min-width:250px;
                            padding:18px;
                            background:#f5f6fa;
                            border-radius:8px;
                        "
                    >

                        <div
                            style="
                                display:flex;
                                justify-content:space-between;
                                font-size:18px;
                            "
                        >

                            <strong>
                                Total Refund:
                            </strong>

                            <strong>

                                KSh
                                <?= number_format(
                                    (float)$return['refund_amount'],
                                    2
                                ) ?>

                            </strong>

                        </div>

                    </div>

                </div>

            </div>


            <!-- STOCK NOTICE -->

            <div
                class="alert alert-success"
                style="margin-top:25px;"
            >

                <strong>
                    Inventory Updated
                </strong>

                <p style="margin-top:5px;">

                    The returned quantity has been recorded as a
                    <strong>return stock movement</strong> and added
                    back to inventory.

                </p>

            </div>

        </section>

    </main>

</div>

</body>

</html>
```

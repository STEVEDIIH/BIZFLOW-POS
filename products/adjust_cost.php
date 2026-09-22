<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

// Check if permissions file exists
$permissions_available = file_exists("../config/permissions.php");
if ($permissions_available) {
    require_once "../config/permissions.php";
}

$business_id = $_SESSION["business_id"];
$user_id = $_SESSION["user_id"];
$product_id = (int)($_GET["id"] ?? 0);

$error = "";
$success = "";

// Check permission
if ($permissions_available && function_exists('hasPermission')) {
    if (!hasPermission("adjust_cost")) {
        header("Location: view_products.php?error=" . urlencode("You don't have permission to adjust costs."));
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        p.*,
        c.name AS category_name,
        sm.name AS selling_method_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN selling_methods sm ON p.selling_method_id = sm.id
    WHERE p.id = :id
    AND p.business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ":id" => $product_id,
    ":business_id" => $business_id
]);

$product = $stmt->fetch();

if (!$product) {
    header("Location: view_products.php?error=" . urlencode("Product not found."));
    exit;
}

// Calculate current metrics
$current_buying = (float)$product["buying_price"];
$current_selling = (float)$product["selling_price"];
$current_gross_profit = $current_selling - $current_buying;
$current_margin = $current_selling > 0 ? ($current_gross_profit / $current_selling) * 100 : 0;

/*
|--------------------------------------------------------------------------
| Get Cost Adjustment History
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare("
    SELECT 
        ca.*,
        u.full_name AS adjusted_by_name
    FROM cost_adjustments ca
    LEFT JOIN users u ON ca.adjusted_by = u.id
    WHERE ca.product_id = :product_id
    ORDER BY ca.adjusted_at DESC
    LIMIT 50
");

$historyStmt->execute([":product_id" => $product_id]);
$history = $historyStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Process Form
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $new_buying_price = $_POST["new_buying_price"] ?? "";
    $new_selling_price = $_POST["new_selling_price"] ?? "";
    $reason = trim($_POST["reason"] ?? "");
    $keep_margin = isset($_POST["keep_margin"]) && $_POST["keep_margin"] == "1";
    $allow_loss = isset($_POST["allow_loss"]);

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($new_buying_price === "" || !is_numeric($new_buying_price)) {
        $error = "Please enter a valid buying price.";
    } elseif ((float)$new_buying_price < 0) {
        $error = "Buying price cannot be negative.";
    } elseif ($reason === "") {
        $error = "Please provide a reason for the cost change.";
    }

    // If keep margin is checked, calculate selling price automatically
    if ($error === "" && $keep_margin && $current_margin > 0) {
        $new_selling_price = (float)$new_buying_price / (1 - ($current_margin / 100));
        $new_selling_price = round($new_selling_price, 2);
    }

    if ($error === "") {
        if ($new_selling_price === "" || !is_numeric($new_selling_price)) {
            $error = "Please enter a valid selling price.";
        } elseif ((float)$new_selling_price < 0) {
            $error = "Selling price cannot be negative.";
        } elseif (!$allow_loss && (float)$new_selling_price < (float)$new_buying_price) {
            $error = "Selling price cannot be lower than buying price. Check 'Allow Loss' to override.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Product and Save History
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        $old_buying = (float)$product["buying_price"];
        $old_selling = (float)$product["selling_price"];
        $new_buying = (float)$new_buying_price;
        $new_selling = (float)$new_selling_price;

        try {

            $pdo->beginTransaction();

            // Update product
            $stmt = $pdo->prepare("
                UPDATE products
                SET buying_price = :buying_price,
                    selling_price = :selling_price,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                AND business_id = :business_id
            ");

            $stmt->execute([
                ":buying_price" => $new_buying,
                ":selling_price" => $new_selling,
                ":id" => $product_id,
                ":business_id" => $business_id
            ]);

            // Insert cost adjustment history
            $stmt = $pdo->prepare("
                INSERT INTO cost_adjustments (
                    product_id,
                    old_buying_price,
                    new_buying_price,
                    old_selling_price,
                    new_selling_price,
                    reason,
                    adjusted_by
                )
                VALUES (
                    :product_id,
                    :old_buying_price,
                    :new_buying_price,
                    :old_selling_price,
                    :new_selling_price,
                    :reason,
                    :adjusted_by
                )
            ");

            $stmt->execute([
                ":product_id" => $product_id,
                ":old_buying_price" => $old_buying,
                ":new_buying_price" => $new_buying,
                ":old_selling_price" => $old_selling,
                ":new_selling_price" => $new_selling,
                ":reason" => $reason,
                ":adjusted_by" => $user_id
            ]);

            $pdo->commit();

            $success = "Cost adjusted successfully!";

            // Refresh product data
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    c.name AS category_name,
                    sm.name AS selling_method_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN selling_methods sm ON p.selling_method_id = sm.id
                WHERE p.id = :id
                AND p.business_id = :business_id
                LIMIT 1
            ");

            $stmt->execute([
                ":id" => $product_id,
                ":business_id" => $business_id
            ]);

            $product = $stmt->fetch();

            // Refresh history
            $historyStmt = $pdo->prepare("
                SELECT 
                    ca.*,
                    u.full_name AS adjusted_by_name
                FROM cost_adjustments ca
                LEFT JOIN users u ON ca.adjusted_by = u.id
                WHERE ca.product_id = :product_id
                ORDER BY ca.adjusted_at DESC
                LIMIT 50
            ");

            $historyStmt->execute([":product_id" => $product_id]);
            $history = $historyStmt->fetchAll();

            // Update current metrics
            $current_buying = (float)$product["buying_price"];
            $current_selling = (float)$product["selling_price"];
            $current_gross_profit = $current_selling - $current_buying;
            $current_margin = $current_selling > 0 ? ($current_gross_profit / $current_selling) * 100 : 0;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Unable to update cost. Please try again. Error: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Adjust Cost - BizFlow</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-item {
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .summary-item .label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .summary-item .value {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-top: 2px;
        }

        .summary-item .value.positive {
            color: #16a34a;
        }

        .summary-item .value.negative {
            color: #dc2626;
        }

        .profit-preview {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .profit-preview .label {
            font-size: 13px;
            color: #166534;
        }

        .profit-preview .value {
            font-size: 22px;
            font-weight: 700;
            color: #16a34a;
        }

        .profit-preview .value.negative {
            color: #dc2626;
        }

        .history-table {
            margin-top: 30px;
        }

        .history-table .badge {
            font-size: 11px;
            padding: 2px 8px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            font-size: 14px;
            cursor: pointer;
            color: #374151;
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
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

            <!-- HEADER -->
            <div class="page-title">

                <div>

                    <h1>Adjust Product Cost</h1>

                    <p>
                        Update the buying and selling prices with full history tracking.
                    </p>

                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">

                    <a href="view_products.php"
                       class="btn-secondary">

                        ← Back to Products
                    </a>

                </div>

            </div>

            <!-- MESSAGES -->
            <?php if ($error !== ""): ?>

                <div class="alert alert-error">

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>

            <?php if ($success !== ""): ?>

                <div class="alert alert-success">

                    <?= htmlspecialchars($success) ?>

                    <br>
                    <small>
                        <a href="view_products.php" style="color:#065f46; font-weight:bold;">
                            View all products →
                        </a>
                    </small>

                </div>

            <?php endif; ?>

            <!-- PRODUCT SUMMARY -->
            <div style="margin-bottom:25px;">

                <h3 style="margin-bottom:15px;">Product Summary</h3>

                <div class="summary-grid">

                    <div class="summary-item">
                        <div class="label">Product Name</div>
                        <div class="value" style="font-size:16px;">
                            <?= htmlspecialchars($product["name"]) ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">SKU</div>
                        <div class="value" style="font-size:16px;">
                            <?= htmlspecialchars($product["sku"] ?? "N/A") ?>
                        </div>
                    </div>

                    <?php if (!empty($product["barcode"])): ?>
                    <div class="summary-item">
                        <div class="label">Barcode</div>
                        <div class="value" style="font-size:16px;">
                            <?= htmlspecialchars($product["barcode"]) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="summary-item">
                        <div class="label">Category</div>
                        <div class="value" style="font-size:16px;">
                            <?= htmlspecialchars($product["category_name"] ?? "Uncategorized") ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">Current Stock</div>
                        <div class="value" style="font-size:16px;">
                            <?= number_format((float)$product["stock_quantity"], 2) ?>
                            <?= htmlspecialchars($product["unit"] ?? "") ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">Current Buying Price</div>
                        <div class="value" style="font-size:16px;">
                            KSh <?= number_format($current_buying, 2) ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">Current Selling Price</div>
                        <div class="value" style="font-size:16px;">
                            KSh <?= number_format($current_selling, 2) ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">Current Gross Profit</div>
                        <div class="value <?= $current_gross_profit >= 0 ? 'positive' : 'negative' ?>">
                            KSh <?= number_format($current_gross_profit, 2) ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="label">Current Profit Margin</div>
                        <div class="value <?= $current_margin >= 0 ? 'positive' : 'negative' ?>">
                            <?= number_format($current_margin, 2) ?>%
                        </div>
                    </div>

                </div>

            </div>

            <!-- FORM -->
            <div class="form-container">

                <h3 style="margin-bottom:20px;">Update Pricing</h3>

                <form method="POST" id="costForm">

                    <div class="form-row">

                        <div class="form-group">

                            <label for="new_buying_price">
                                New Buying Price (KSh) *
                            </label>

                            <input
                                type="number"
                                id="new_buying_price"
                                name="new_buying_price"
                                value="<?= htmlspecialchars($current_buying) ?>"
                                min="0"
                                step="0.01"
                                required
                                oninput="calculatePreview()"
                            >

                        </div>

                        <div class="form-group">

                            <label for="new_selling_price">
                                New Selling Price (KSh)
                            </label>

                            <input
                                type="number"
                                id="new_selling_price"
                                name="new_selling_price"
                                value="<?= htmlspecialchars($current_selling) ?>"
                                min="0"
                                step="0.01"
                                oninput="calculatePreview()"
                            >

                            <small>
                                Leave blank to automatically calculate based on current margin.
                            </small>

                        </div>

                    </div>

                    <div class="checkbox-group">

                        <input
                            type="checkbox"
                            id="keep_margin"
                            name="keep_margin"
                            value="1"
                            checked
                            onchange="toggleSellingPrice()"
                        >

                        <label for="keep_margin">
                            Automatically maintain current profit margin when buying price changes
                        </label>

                    </div>

                    <div class="checkbox-group">

                        <input
                            type="checkbox"
                            id="allow_loss"
                            name="allow_loss"
                            value="1"
                        >

                        <label for="allow_loss">
                            Allow selling price to be lower than buying price (Loss)
                        </label>

                    </div>

                    <div class="profit-preview" id="profitPreview">

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">

                            <div>
                                <div class="label">Gross Profit</div>
                                <div class="value" id="previewProfit">
                                    KSh <?= number_format($current_gross_profit, 2) ?>
                                </div>
                            </div>

                            <div>
                                <div class="label">Profit Margin</div>
                                <div class="value" id="previewMargin">
                                    <?= number_format($current_margin, 2) ?>%
                                </div>
                            </div>

                        </div>

                    </div>

                    <div class="form-group">

                        <label for="reason">
                            Reason for Cost Change *
                        </label>

                        <input
                            type="text"
                            id="reason"
                            name="reason"
                            placeholder="e.g. Supplier price increase, New supplier, Bulk discount"
                            required
                        >

                    </div>

                    <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">

                        <button type="submit" class="btn-primary">
                            Update Cost
                        </button>

                        <a href="view_products.php" class="btn-secondary">
                            Cancel
                        </a>

                    </div>

                </form>

            </div>

            <!-- HISTORY -->
            <?php if (count($history) > 0): ?>

                <div class="history-table">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin:30px 0 15px; flex-wrap:wrap; gap:10px;">

                        <h3>Cost Adjustment History</h3>

                        <span style="font-size:13px; color:#6b7280;">
                            Last 50 adjustments
                        </span>

                    </div>

                    <div class="table-container">

                        <table class="data-table">

                            <thead>

                                <tr>

                                    <th>Date</th>

                                    <th>Old Buying</th>

                                    <th>New Buying</th>

                                    <th>Old Selling</th>

                                    <th>New Selling</th>

                                    <th>Reason</th>

                                    <th>Adjusted By</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($history as $record): ?>

                                    <tr>

                                        <td style="white-space:nowrap;">
                                            <?= date("d/m/Y H:i", strtotime($record["adjusted_at"])) ?>
                                        </td>

                                        <td>
                                            KSh <?= number_format((float)$record["old_buying_price"], 2) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                KSh <?= number_format((float)$record["new_buying_price"], 2) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            KSh <?= number_format((float)$record["old_selling_price"], 2) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                KSh <?= number_format((float)$record["new_selling_price"], 2) ?>
                                            </strong>
                                        </td>

                                        <td style="max-width:150px;">
                                            <?= htmlspecialchars($record["reason"]) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($record["adjusted_by_name"] ?? "Unknown") ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script>
    // DOM elements
    const buyingInput = document.getElementById('new_buying_price');
    const sellingInput = document.getElementById('new_selling_price');
    const keepMarginCheck = document.getElementById('keep_margin');
    const allowLossCheck = document.getElementById('allow_loss');
    const previewProfit = document.getElementById('previewProfit');
    const previewMargin = document.getElementById('previewMargin');

    // Store current values from PHP
    const currentBuying = <?= $current_buying ?>;
    const currentSelling = <?= $current_selling ?>;
    const currentMargin = <?= $current_margin ?>;

    /**
     * Toggle selling price input based on "Keep Margin" checkbox
     */
    function toggleSellingPrice() {
        if (keepMarginCheck.checked) {
            sellingInput.disabled = true;
            sellingInput.placeholder = 'Auto-calculated';
            // Auto-calculate selling price
            calculatePreview();
        } else {
            sellingInput.disabled = false;
            sellingInput.placeholder = 'Enter selling price';
        }
    }

    /**
     * Calculate and update preview
     */
    function calculatePreview() {
        const buying = parseFloat(buyingInput.value) || 0;
        let selling = parseFloat(sellingInput.value) || 0;

        // If keep margin is checked, auto-calculate selling price
        if (keepMarginCheck.checked && currentMargin > 0) {
            selling = buying / (1 - (currentMargin / 100));
            selling = Math.round(selling * 100) / 100;
            sellingInput.value = selling.toFixed(2);
        }

        // If selling is 0, use buying price
        if (selling === 0) {
            selling = buying;
        }

        const profit = selling - buying;
        const margin = selling > 0 ? (profit / selling) * 100 : 0;

        // Update preview
        previewProfit.textContent = 'KSh ' + profit.toFixed(2);
        previewProfit.className = 'value ' + (profit >= 0 ? 'positive' : 'negative');

        previewMargin.textContent = margin.toFixed(2) + '%';
        previewMargin.className = 'value ' + (margin >= 0 ? 'positive' : 'negative');

        // Update profit preview box color
        const previewBox = document.getElementById('profitPreview');
        if (profit < 0) {
            previewBox.style.background = '#fef2f2';
            previewBox.style.borderColor = '#fca5a5';
        } else {
            previewBox.style.background = '#f0fdf4';
            previewBox.style.borderColor = '#bbf7d0';
        }
    }

    /**
     * Initialize
     */
    document.addEventListener('DOMContentLoaded', function() {
        toggleSellingPrice();
        calculatePreview();

        // Auto-calculate when buying changes
        buyingInput.addEventListener('input', calculatePreview);

        // Auto-calculate when selling changes (if not keeping margin)
        sellingInput.addEventListener('input', function() {
            if (!keepMarginCheck.checked) {
                calculatePreview();
            }
        });

        // Recalculate when allow loss changes
        allowLossCheck.addEventListener('change', calculatePreview);
    });

    // Make functions globally accessible
    window.toggleSellingPrice = toggleSellingPrice;
    window.calculatePreview = calculatePreview;
</script>

</body>

</html>
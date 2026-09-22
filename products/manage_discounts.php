<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

// Check if user is admin
if ($_SESSION['role_name'] !== 'Admin') {
    header("Location: view_products.php?error=" . urlencode("Access denied. Admin only."));
    exit;
}

$business_id = $_SESSION["business_id"];

$error = "";
$success = "";

/*
|--------------------------------------------------------------------------
| Handle Actions
|--------------------------------------------------------------------------
*/

// Add/Update Discount
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    
    $product_id = $_POST['product_id'] ?? 0;
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = $_POST['discount_value'] ?? 0;
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $discount_id = $_POST['discount_id'] ?? 0;
    
    if ($product_id <= 0) {
        $error = "Please select a product.";
    } elseif ($discount_value <= 0) {
        $error = "Please enter a valid discount value.";
    } else {
        try {
            if ($discount_id > 0) {
                // Update existing discount
                $stmt = $pdo->prepare("
                    UPDATE product_discounts 
                    SET discount_type = :discount_type,
                        discount_value = :discount_value,
                        start_date = :start_date,
                        end_date = :end_date,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :discount_id
                    AND product_id IN (SELECT id FROM products WHERE business_id = :business_id)
                ");
                
                $stmt->execute([
                    ':discount_type' => $discount_type,
                    ':discount_value' => $discount_value,
                    ':start_date' => $start_date ?: null,
                    ':end_date' => $end_date ?: null,
                    ':discount_id' => $discount_id,
                    ':business_id' => $business_id
                ]);
                
                $success = "Discount updated successfully!";
            } else {
                // Check if product already has a discount
                $stmt = $pdo->prepare("
                    SELECT id FROM product_discounts 
                    WHERE product_id = :product_id
                ");
                $stmt->execute([':product_id' => $product_id]);
                
                if ($stmt->fetch()) {
                    $error = "This product already has a discount. Please edit the existing discount.";
                } else {
                    // Insert new discount
                    $stmt = $pdo->prepare("
                        INSERT INTO product_discounts (
                            product_id,
                            discount_type,
                            discount_value,
                            start_date,
                            end_date
                        ) VALUES (
                            :product_id,
                            :discount_type,
                            :discount_value,
                            :start_date,
                            :end_date
                        )
                    ");
                    
                    $stmt->execute([
                        ':product_id' => $product_id,
                        ':discount_type' => $discount_type,
                        ':discount_value' => $discount_value,
                        ':start_date' => $start_date ?: null,
                        ':end_date' => $end_date ?: null
                    ]);
                    
                    $success = "Discount added successfully!";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Delete Discount
if (isset($_GET['delete'])) {
    $discount_id = (int)$_GET['delete'];
    
    try {
        $stmt = $pdo->prepare("
            DELETE pd FROM product_discounts pd
            INNER JOIN products p ON pd.product_id = p.id
            WHERE pd.id = :discount_id
            AND p.business_id = :business_id
        ");
        
        $stmt->execute([
            ':discount_id' => $discount_id,
            ':business_id' => $business_id
        ]);
        
        $success = "Discount removed successfully!";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Get All Products
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        p.selling_price,
        p.unit
    FROM products p
    WHERE p.business_id = :business_id
    AND p.is_active = 1
    ORDER BY p.name ASC
");

$stmt->execute([
    ":business_id" => $business_id
]);

$products = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Get All Discounts with Product Names
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        pd.*,
        p.name AS product_name,
        p.selling_price,
        p.unit
    FROM product_discounts pd
    INNER JOIN products p ON pd.product_id = p.id
    WHERE p.business_id = :business_id
    ORDER BY p.name ASC
");

$stmt->execute([
    ":business_id" => $business_id
]);

$discounts = $stmt->fetchAll();

// Get a single discount for editing
$edit_discount = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($discounts as $discount) {
        if ($discount['id'] == $edit_id) {
            $edit_discount = $discount;
            break;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Discounts - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .discount-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .discount-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .discount-card .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .discount-card .header h3 {
            margin: 0;
            font-size: 18px;
        }

        .discount-card .badge-active {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .discount-card .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .discount-card .discount-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 10px 0;
        }

        .discount-card .discount-info .label {
            font-size: 12px;
            color: #6b7280;
        }

        .discount-card .discount-info .value {
            font-weight: bold;
            font-size: 16px;
        }

        .discount-card .discount-info .value.discount {
            color: #16a34a;
        }

        .discount-card .actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
            border: none;
            cursor: pointer;
        }

        .btn-edit-sm {
            background: #3b82f6;
            color: white;
        }

        .btn-edit-sm:hover {
            background: #2563eb;
        }

        .btn-delete-sm {
            background: #ef4444;
            color: white;
        }

        .btn-delete-sm:hover {
            background: #dc2626;
        }

        .btn-primary-sm {
            background: #111827;
            color: white;
        }

        .btn-primary-sm:hover {
            background: #374151;
        }

        .form-container {
            max-width: 100%;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .discount-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
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

            <!-- PAGE HEADER -->
            <div class="page-title">

                <div>
                    <h1>Manage Product Discounts</h1>

                    <p>
                        Apply discounts to specific products. Cashiers will see the discounted price automatically.
                    </p>
                </div>

                <a href="view_products.php" class="btn-secondary">
                    ← Back to Products
                </a>

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

                </div>

            <?php endif; ?>

            <!-- DISCOUNT GRID -->
            <div class="discount-grid">

                <!-- DISCOUNT FORM -->
                <div class="discount-card">

                    <div class="header">

                        <h3>
                            <?= $edit_discount ? 'Edit Discount' : 'Add New Discount' ?>
                        </h3>

                        <?php if ($edit_discount): ?>

                            <a href="manage_discounts.php" class="btn-sm btn-primary-sm">
                                + Add New
                            </a>

                        <?php endif; ?>

                    </div>

                    <form method="POST" class="form-container">

                        <input type="hidden" name="action" value="save">

                        <?php if ($edit_discount): ?>

                            <input type="hidden" name="discount_id" value="<?= $edit_discount['id'] ?>">

                        <?php endif; ?>

                        <div class="form-group">

                            <label for="product_id">
                                Select Product *
                            </label>

                            <select
                                id="product_id"
                                name="product_id"
                                required
                                <?= $edit_discount ? 'disabled' : '' ?>
                                style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
                            >

                                <option value="">
                                    -- Select Product --
                                </option>

                                <?php foreach ($products as $product): ?>

                                    <option
                                        value="<?= $product['id'] ?>"
                                        <?= ($edit_discount && $edit_discount['product_id'] == $product['id']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($product['name']) ?>
                                        (KSh <?= number_format($product['selling_price'], 2) ?> / <?= htmlspecialchars($product['unit']) ?>)
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <?php if ($edit_discount): ?>

                                <small style="color:#6b7280;">
                                    Product cannot be changed. Delete and re-add if needed.
                                </small>

                            <?php endif; ?>

                        </div>

                        <div class="form-row">

                            <div class="form-group">

                                <label for="discount_type">
                                    Discount Type *
                                </label>

                                <select
                                    id="discount_type"
                                    name="discount_type"
                                    required
                                    style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
                                >

                                    <option value="percentage" <?= ($edit_discount && $edit_discount['discount_type'] == 'percentage') ? 'selected' : '' ?>>
                                        Percentage (%)
                                    </option>

                                    <option value="fixed" <?= ($edit_discount && $edit_discount['discount_type'] == 'fixed') ? 'selected' : '' ?>>
                                        Fixed Amount (KSh)
                                    </option>

                                </select>

                            </div>

                            <div class="form-group">

                                <label for="discount_value">
                                    Discount Value *
                                </label>

                                <input
                                    type="number"
                                    id="discount_value"
                                    name="discount_value"
                                    value="<?= $edit_discount ? htmlspecialchars($edit_discount['discount_value']) : '' ?>"
                                    min="0"
                                    step="0.01"
                                    placeholder="e.g. 10 or 50"
                                    required
                                    style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
                                >

                                <small style="color:#6b7280;">
                                    For percentage: enter 10 for 10%. For fixed: enter amount in KSh.
                                </small>

                            </div>

                        </div>

                        <div class="form-row">

                            <div class="form-group">

                                <label for="start_date">
                                    Start Date (Optional)
                                </label>

                                <input
                                    type="datetime-local"
                                    id="start_date"
                                    name="start_date"
                                    value="<?= $edit_discount && $edit_discount['start_date'] ? date('Y-m-d\TH:i', strtotime($edit_discount['start_date'])) : '' ?>"
                                    style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
                                >

                                <small style="color:#6b7280;">
                                    Leave empty to start immediately.
                                </small>

                            </div>

                            <div class="form-group">

                                <label for="end_date">
                                    End Date (Optional)
                                </label>

                                <input
                                    type="datetime-local"
                                    id="end_date"
                                    name="end_date"
                                    value="<?= $edit_discount && $edit_discount['end_date'] ? date('Y-m-d\TH:i', strtotime($edit_discount['end_date'])) : '' ?>"
                                    style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"
                                >

                                <small style="color:#6b7280;">
                                    Leave empty for no end date.
                                </small>

                            </div>

                        </div>

                        <button type="submit" class="btn-primary" style="width:100%; margin-top:10px;">

                            <?= $edit_discount ? 'Update Discount' : 'Add Discount' ?>

                        </button>

                    </form>

                </div>

                <!-- DISCOUNT LIST -->
                <div class="discount-card">

                    <div class="header">

                        <h3>Current Discounts</h3>

                        <span style="font-size:13px; color:#6b7280;">
                            <?= count($discounts) ?> active
                        </span>

                    </div>

                    <?php if (count($discounts) > 0): ?>

                        <?php foreach ($discounts as $discount): ?>

                            <div style="
                                padding:12px;
                                border:1px solid #e5e7eb;
                                border-radius:6px;
                                margin-bottom:10px;
                                background:#f9fafb;
                            ">

                                <div style="display:flex; justify-content:space-between; align-items:start;">

                                    <div>

                                        <div style="font-weight:bold; font-size:16px;">
                                            <?= htmlspecialchars($discount['product_name']) ?>
                                        </div>

                                        <div style="font-size:13px; color:#6b7280;">
                                            Original: KSh <?= number_format($discount['selling_price'], 2) ?>
                                            <?= htmlspecialchars($discount['unit']) ?>
                                        </div>

                                        <div class="discount-info">

                                            <div>
                                                <div class="label">Discount</div>
                                                <div class="value discount">
                                                    <?php if ($discount['discount_type'] == 'percentage'): ?>
                                                        <?= $discount['discount_value'] ?>% OFF
                                                    <?php else: ?>
                                                        KSh <?= number_format($discount['discount_value'], 2) ?> OFF
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div>
                                                <div class="label">Discounted Price</div>
                                                <div class="value" style="color:#16a34a;">
                                                    <?php
                                                    $discounted = $discount['selling_price'];
                                                    if ($discount['discount_type'] == 'percentage') {
                                                        $discounted = $discount['selling_price'] - ($discount['selling_price'] * $discount['discount_value'] / 100);
                                                    } else {
                                                        $discounted = max(0, $discount['selling_price'] - $discount['discount_value']);
                                                    }
                                                    ?>
                                                    KSh <?= number_format($discounted, 2) ?>
                                                </div>
                                            </div>

                                        </div>

                                        <?php if ($discount['start_date'] || $discount['end_date']): ?>

                                            <div style="font-size:12px; color:#6b7280; margin-top:5px;">

                                                <?php if ($discount['start_date']): ?>
                                                    Starts: <?= date('d/m/Y H:i', strtotime($discount['start_date'])) ?>
                                                <?php endif; ?>

                                                <?php if ($discount['end_date']): ?>
                                                    <?= $discount['start_date'] ? ' | ' : '' ?>
                                                    Ends: <?= date('d/m/Y H:i', strtotime($discount['end_date'])) ?>
                                                <?php endif; ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <div style="text-align:right;">

                                        <?php
                                        $is_active = true;
                                        if ($discount['start_date'] && strtotime($discount['start_date']) > time()) {
                                            $is_active = false;
                                        }
                                        if ($discount['end_date'] && strtotime($discount['end_date']) < time()) {
                                            $is_active = false;
                                        }
                                        ?>

                                        <span class="<?= $is_active ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $is_active ? 'Active' : 'Inactive' ?>
                                        </span>

                                        <div class="actions">

                                            <a href="manage_discounts.php?edit=<?= $discount['id'] ?>" class="btn-sm btn-edit-sm">
                                                Edit
                                            </a>

                                            <a href="manage_discounts.php?delete=<?= $discount['id'] ?>" class="btn-sm btn-delete-sm" onclick="return confirm('Remove this discount?')">
                                                Remove
                                            </a>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div style="text-align:center; padding:30px 0; color:#6b7280;">

                            <div style="font-size:40px; margin-bottom:10px;">
                                🏷️
                            </div>

                            <p>
                                No discounts have been added yet.
                            </p>

                            <p style="font-size:13px;">
                                Add a discount using the form on the left.
                            </p>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </section>

    </main>

</div>

</body>

</html>
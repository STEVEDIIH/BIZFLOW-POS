<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

// Check if user can see buying prices
$user_role = $_SESSION['role'] ?? 'cashier';
$can_see_buying_price = in_array($user_role, ['owner', 'admin', 'manager']);

$business_id = $_SESSION["business_id"];

/*
|--------------------------------------------------------------------------
| Success Messages
|--------------------------------------------------------------------------
*/

$success_message = "";
$error_message = "";

if (isset($_GET["added"]) && $_GET["added"] == 1) {
    $success_message = "Product added successfully!";
}

if (isset($_GET["updated"]) && $_GET["updated"] == 1) {
    $success_message = "Product updated successfully!";
}

if (isset($_GET["cost_updated"]) && $_GET["cost_updated"] == 1) {
    $success_message = "Product cost adjusted successfully!";
}

if (isset($_GET["stock_updated"]) && $_GET["stock_updated"] == 1) {
    $success_message = "Stock adjusted successfully!";
}

if (isset($_GET["activated"]) && $_GET["activated"] == 1) {
    $success_message = "Product activated successfully!";
}

if (isset($_GET["deactivated"]) && $_GET["deactivated"] == 1) {
    $success_message = "Product deactivated successfully!";
}

if (isset($_GET["error"])) {
    $error_message = htmlspecialchars($_GET["error"]);
}

/*
|--------------------------------------------------------------------------
| Search & Filter
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");
$category_id = $_GET["category_id"] ?? "";
$status_filter = $_GET["status"] ?? "";

/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->prepare("
    SELECT id, name
    FROM categories
    WHERE business_id = :business_id
    ORDER BY name ASC
");

$categoryStmt->execute([
    ":business_id" => $business_id
]);

$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Get Products
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT 
        p.*,
        c.name AS category_name,
        sm.name AS selling_method_name
    FROM products p
    LEFT JOIN categories c 
        ON p.category_id = c.id
    LEFT JOIN selling_methods sm
        ON p.selling_method_id = sm.id
    WHERE p.business_id = :business_id
";

$params = [
    ":business_id" => $business_id
];

if ($search !== "") {
    $sql .= "
        AND (
            p.name LIKE :search
            OR p.sku LIKE :search
            OR p.barcode LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";
}

if ($category_id !== "") {
    $sql .= " AND p.category_id = :category_id";
    $params[":category_id"] = $category_id;
}

if ($status_filter !== "") {
    if ($status_filter === "active") {
        $sql .= " AND p.is_active = 1";
    } elseif ($status_filter === "inactive") {
        $sql .= " AND p.is_active = 0";
    }
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Products - BizFlow</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        /* ===== TABLE HEADER ===== */
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-header h1 {
            font-size: 24px;
            margin: 0;
        }

        .table-header .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ===== SEARCH & FILTER BAR ===== */
        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: #f9fafb;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        .filter-bar .search-group {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .filter-bar .search-group input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .filter-bar .search-group input:focus {
            outline: none;
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.1);
        }

        .filter-bar .search-group .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 16px;
        }

        .filter-bar .filter-group {
            min-width: 150px;
        }

        .filter-bar .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: border-color 0.2s;
        }

        .filter-bar .filter-group select:focus {
            outline: none;
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.1);
        }

        .filter-bar .btn-clear {
            padding: 10px 18px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .filter-bar .btn-clear:hover {
            background: #e5e7eb;
        }

        .filter-bar .btn-filter {
            padding: 10px 20px;
            background: #111827;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .filter-bar .btn-filter:hover {
            background: #374151;
        }

        /* ===== PRODUCT STATS ===== */
        .product-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .product-stats .stat-item {
            font-size: 14px;
            color: #6b7280;
        }

        .product-stats .stat-item strong {
            color: #111827;
        }

        /* ===== TABLE STYLES ===== */
        .table-wrapper {
            overflow-x: auto;
            background: white;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .product-table thead {
            background: #f9fafb;
        }

        .product-table thead th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .product-table thead th:last-child {
            text-align: center;
        }

        .product-table tbody tr {
            transition: background 0.15s;
        }

        .product-table tbody tr:hover {
            background: #f9fafb;
        }

        .product-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        .product-table tbody tr:last-child td {
            border-bottom: none;
        }

        .product-table .product-name {
            font-weight: 600;
            color: #111827;
        }

        .product-table .product-sku {
            font-size: 12px;
            color: #6b7280;
            font-family: monospace;
        }

        .product-table .product-meta {
            font-size: 12px;
            color: #6b7280;
        }

        .product-table .price {
            font-weight: 600;
        }

        .product-table .price-retail {
            color: #111827;
        }

        .product-table .price-buying {
            color: #6b7280;
        }

        /* ===== BADGES ===== */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-out-of-stock {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-low-stock {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-in-stock {
            background: #d1fae5;
            color: #065f46;
        }

        /* ===== ACTION BUTTONS ===== */
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
            border: none;
            cursor: pointer;
            font-family: inherit;
            line-height: 1.4;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .action-btn:active {
            transform: translateY(0);
        }

        .action-btn .icon {
            font-size: 14px;
            line-height: 1;
        }

        .action-btn .text {
            font-size: 12px;
        }

        /* Button Colors */
        .btn-view {
            background: #6b7280;
            color: white;
        }

        .btn-view:hover {
            background: #4b5563;
        }

        .btn-edit {
            background: #3b82f6;
            color: white;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .btn-stock {
            background: #f59e0b;
            color: white;
        }

        .btn-stock:hover {
            background: #d97706;
        }

        .btn-cost {
            background: #8b5cf6;
            color: white;
        }

        .btn-cost:hover {
            background: #7c3aed;
        }

        .btn-activate {
            background: #22c55e;
            color: white;
        }

        .btn-activate:hover {
            background: #16a34a;
        }

        .btn-deactivate {
            background: #ef4444;
            color: white;
        }

        .btn-deactivate:hover {
            background: #dc2626;
        }

        .btn-disabled {
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .icon {
            font-size: 56px;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            color: #111827;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #6b7280;
            max-width: 400px;
            margin: 0 auto 20px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
                padding: 15px;
            }

            .filter-bar .search-group {
                min-width: 100%;
            }

            .filter-bar .filter-group {
                min-width: 100%;
            }

            .filter-bar .btn-filter,
            .filter-bar .btn-clear {
                width: 100%;
                text-align: center;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .table-header .header-actions {
                width: 100%;
            }

            .table-header .header-actions a {
                flex: 1;
                text-align: center;
            }

            .product-table {
                font-size: 13px;
            }

            .product-table thead th,
            .product-table tbody td {
                padding: 8px 10px;
            }

            .action-buttons {
                flex-direction: column;
                align-items: stretch;
                gap: 4px;
            }

            .action-btn {
                justify-content: center;
                padding: 6px 10px;
                width: 100%;
            }

            .product-stats {
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .product-table .product-sku {
                display: none;
            }

            .product-table .product-meta {
                display: none;
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
            <div class="table-header">

                <div>
                    <h1>Products</h1>
                    <p style="color:#6b7280; margin-top:4px;">
                        Manage your products, prices and stock.
                    </p>
                </div>

                <div class="header-actions">

                    <a href="add.php" class="btn-primary">
                        + Add Product
                    </a>

                    <a href="manage_categories.php" class="btn-secondary">
                        Categories
                    </a>

                    <a href="manage_discounts.php" class="btn-secondary">
                        🏷️ Discounts
                    </a>

                </div>

            </div>

            <!-- SUCCESS MESSAGES -->
            <?php if ($success_message !== ""): ?>

                <div class="alert alert-success" style="margin-bottom:20px;">

                    <?= htmlspecialchars($success_message) ?>

                </div>

            <?php endif; ?>

            <!-- ERROR MESSAGES -->
            <?php if ($error_message !== ""): ?>

                <div class="alert alert-error" style="margin-bottom:20px;">

                    <?= htmlspecialchars($error_message) ?>

                </div>

            <?php endif; ?>

            <!-- SEARCH & FILTER BAR -->
            <div class="filter-bar">

                <div class="search-group">
                    <span class="search-icon">🔍</span>
                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search products by name, SKU or barcode..."
                        value="<?= htmlspecialchars($search) ?>"
                        onkeyup="if(event.key==='Enter'){this.form.submit();}"
                    >
                </div>

                <div class="filter-group">
                    <select id="categoryFilter" name="category_id" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= $category["id"] ?>"
                                <?= ($category_id == $category["id"]) ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($category["name"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="statusFilter" name="status" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?= ($status_filter === "active") ? "selected" : "" ?>>Active</option>
                        <option value="inactive" <?= ($status_filter === "inactive") ? "selected" : "" ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn-filter" form="filterForm">Search</button>

                <a href="view_products.php" class="btn-clear">Clear</a>

            </div>

            <!-- HIDDEN FORM FOR FILTERS -->
            <form id="filterForm" method="GET" style="display:none;">
                <input type="hidden" name="search" id="hiddenSearch" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="category_id" id="hiddenCategory" value="<?= htmlspecialchars($category_id) ?>">
                <input type="hidden" name="status" id="hiddenStatus" value="<?= htmlspecialchars($status_filter) ?>">
            </form>

            <!-- PRODUCT STATS -->
            <div class="product-stats">
                <span class="stat-item">
                    <strong><?= count($products) ?></strong>
                    <?= count($products) == 1 ? "product" : "products" ?> found
                </span>
                <?php if ($search !== "" || $category_id !== "" || $status_filter !== ""): ?>
                    <span class="stat-item" style="color:#6b7280;">
                        <a href="view_products.php" style="color:#3b82f6; text-decoration:none;">
                            Clear all filters →
                        </a>
                    </span>
                <?php endif; ?>
            </div>

            <!-- PRODUCT TABLE -->
            <div class="table-wrapper">

                <?php if (count($products) > 0): ?>

                    <table class="product-table">

                        <thead>

                            <tr>

                                <th>Product</th>

                                <th>Category</th>

                                <!-- Buying Price Column - Only show to authorized users -->
                                <?php if ($can_see_buying_price): ?>
                                    <th style="text-align:right;">Buying</th>
                                <?php endif; ?>

                                <th style="text-align:right;">Selling</th>

                                <th style="text-align:center;">Stock</th>

                                <th style="text-align:center;">Status</th>

                                <th style="text-align:center;">Actions</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($products as $product): ?>

                            <tr>

                                <!-- PRODUCT NAME -->
                                <td>

                                    <div class="product-name">
                                        <?= htmlspecialchars($product["name"]) ?>
                                    </div>

                                    <?php if (!empty($product["sku"])): ?>
                                        <div class="product-sku">
                                            SKU: <?= htmlspecialchars($product["sku"]) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($product["selling_method_name"])): ?>
                                        <div class="product-meta">
                                            <?= htmlspecialchars($product["selling_method_name"]) ?>
                                            <?php if (!empty($product["unit"])): ?>
                                                • <?= htmlspecialchars($product["unit"]) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                </td>

                                <!-- CATEGORY -->
                                <td>

                                    <?php if (!empty($product["category_name"])): ?>

                                        <?= htmlspecialchars($product["category_name"]) ?>

                                    <?php else: ?>

                                        <span style="color:#9ca3af; font-size:12px;">
                                            Uncategorized
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- BUYING PRICE - Only show to authorized users -->
                                <?php if ($can_see_buying_price): ?>
                                    <td style="text-align:right;">
                                        <span class="price price-buying">
                                            KSh <?= number_format(
                                                (float)$product["buying_price"],
                                                2
                                            ) ?>
                                        </span>
                                    </td>
                                <?php endif; ?>

                                <!-- SELLING PRICE -->
                                <td style="text-align:right;">
                                    <span class="price price-retail">
                                        KSh <?= number_format(
                                            (float)$product["selling_price"],
                                            2
                                        ) ?>
                                    </span>
                                </td>

                                <!-- STOCK -->
                                <td style="text-align:center;">

                                    <?php
                                    $stock = (float)$product["stock_quantity"];
                                    $reorder = (float)$product["reorder_level"];
                                    ?>

                                    <?php if ($stock <= 0): ?>

                                        <span class="badge badge-out-of-stock">
                                            Out of Stock
                                        </span>

                                    <?php elseif ($stock <= $reorder): ?>

                                        <span class="badge badge-low-stock">
                                            <?= number_format($stock, 2) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="badge badge-in-stock">
                                            <?= number_format($stock, 2) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- STATUS -->
                                <td style="text-align:center;">

                                    <?php if (isset($product["is_active"]) && !$product["is_active"]): ?>

                                        <span class="badge badge-danger">
                                            Inactive
                                        </span>

                                    <?php else: ?>

                                        <span class="badge badge-success">
                                            Active
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- ACTIONS -->
                                <td style="text-align:center;">

                                    <div class="action-buttons">

                                        <!-- Edit -->
                                        <a
                                            href="edit.php?id=<?= $product["id"] ?>"
                                            class="action-btn btn-edit"
                                            title="Edit Product"
                                        >
                                            <span class="icon">✏️</span>
                                            <span class="text">Edit</span>
                                        </a>

                                        <!-- Stock -->
                                        <a
                                            href="../inventory/adjust_stock.php?id=<?= $product["id"] ?>"
                                            class="action-btn btn-stock"
                                            title="Adjust Stock"
                                        >
                                            <span class="icon">📦</span>
                                            <span class="text">Stock</span>
                                        </a>

                                        <!-- Cost - Only show to authorized users -->
                                        <?php if ($can_see_buying_price): ?>
                                            <a
                                                href="adjust_cost.php?id=<?= $product["id"] ?>"
                                                class="action-btn btn-cost"
                                                title="Adjust Cost"
                                            >
                                                <span class="icon">💰</span>
                                                <span class="text">Cost</span>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Activate / Deactivate -->
                                        <?php if (isset($product["is_active"]) && !$product["is_active"]): ?>

                                            <a
                                                href="activate.php?id=<?= $product["id"] ?>"
                                                class="action-btn btn-activate"
                                                onclick="return confirm('Activate this product?')"
                                                title="Activate Product"
                                            >
                                                <span class="icon">✅</span>
                                                <span class="text">Activate</span>
                                            </a>

                                        <?php else: ?>

                                            <a
                                                href="deactivate.php?id=<?= $product["id"] ?>"
                                                class="action-btn btn-deactivate"
                                                onclick="return confirm('Deactivate this product?')"
                                                title="Deactivate Product"
                                            >
                                                <span class="icon">⛔</span>
                                                <span class="text">Deactivate</span>
                                            </a>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">

                        <div class="icon">📦</div>

                        <h3>No Products Found</h3>

                        <p>
                            <?php if ($search !== "" || $category_id !== "" || $status_filter !== ""): ?>

                                Try adjusting your search or filter criteria.

                            <?php else: ?>

                                You haven't added any products yet. Start by adding your first product.

                            <?php endif; ?>
                        </p>

                        <?php if ($search !== "" || $category_id !== "" || $status_filter !== ""): ?>

                            <a href="view_products.php" class="btn-secondary">
                                Clear Filters
                            </a>

                        <?php else: ?>

                            <a href="add.php" class="btn-primary">
                                + Add Product
                            </a>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script>
    // Real-time search with debounce
    let searchTimeout;

    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            document.getElementById('hiddenSearch').value = document.getElementById('searchInput').value;
            document.getElementById('filterForm').submit();
        }, 500);
    });

    // Category filter change
    document.getElementById('categoryFilter').addEventListener('change', function() {
        document.getElementById('hiddenCategory').value = this.value;
        document.getElementById('filterForm').submit();
    });

    // Status filter change
    document.getElementById('statusFilter').addEventListener('change', function() {
        document.getElementById('hiddenStatus').value = this.value;
        document.getElementById('filterForm').submit();
    });
</script>

</body>

</html>
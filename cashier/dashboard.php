<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| TIMEZONE
|--------------------------------------------------------------------------
| BizFlow operates using Kenya time.
| This ensures the dashboard date matches the actual local date in Kenya.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| SESSION DATA
|--------------------------------------------------------------------------
*/

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$user_id     = (int) ($_SESSION["user_id"] ?? 0);

if ($business_id <= 0 || $user_id <= 0) {
    header("Location: ../index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SET MYSQL SESSION TIMEZONE TO NAIROBI
|--------------------------------------------------------------------------
| Africa/Nairobi is UTC+3.
| This is important because MySQL CURDATE() may otherwise use the
| server's timezone instead of Kenya's timezone.
|--------------------------------------------------------------------------
*/

try {
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    // Continue using PHP timezone even if MySQL timezone cannot be changed.
}

/*
|--------------------------------------------------------------------------
| GET USER ROLE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT r.name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
    LIMIT 1
");

$stmt->execute([
    ':user_id' => $user_id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$user_role = strtolower(trim($user['name'] ?? 'cashier'));

/*
|--------------------------------------------------------------------------
| ADMIN / OWNER / MANAGER CHECK
|--------------------------------------------------------------------------
*/

$is_admin = in_array(
    $user_role,
    ['admin', 'owner', 'manager'],
    true
);

/*
|--------------------------------------------------------------------------
| CURRENT NAIROBI DATE
|--------------------------------------------------------------------------
*/

$today_date = date('Y-m-d');
$display_date = date('d M Y');
$display_day = date('l');

/*
|--------------------------------------------------------------------------
| LOW STOCK PRODUCTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count
    FROM products
    WHERE business_id = :business_id
      AND is_active = 1
      AND stock_quantity <= reorder_level
      AND stock_quantity > 0
");

$stmt->execute([
    ':business_id' => $business_id
]);

$low_stock_count = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| TOTAL PRODUCTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count
    FROM products
    WHERE business_id = :business_id
      AND is_active = 1
");

$stmt->execute([
    ':business_id' => $business_id
]);

$total_products = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| TODAY'S SALES FOR THIS CASHIER
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We no longer use CURDATE() here.
|
| Instead we calculate the exact Nairobi start and end of today
| and compare sale_date against those values.
|
| This prevents the UTC/Kenya date mismatch.
|--------------------------------------------------------------------------
*/

$start_of_today = $today_date . ' 00:00:00';
$end_of_today   = $today_date . ' 23:59:59';

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count
    FROM sales
    WHERE business_id = :business_id
      AND user_id = :user_id
      AND sale_status = 'completed'
      AND sale_date >= :start_of_today
      AND sale_date <= :end_of_today
");

$stmt->execute([
    ':business_id'   => $business_id,
    ':user_id'       => $user_id,
    ':start_of_today' => $start_of_today,
    ':end_of_today'   => $end_of_today
]);

$today_transactions = (int) $stmt->fetchColumn();

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
        <?= $is_admin ? 'Admin Dashboard' : 'Cashier Dashboard' ?> - BizFlow
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .stat-card .icon {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .stat-card .label {
            color: #6b7280;
            font-size: 14px;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-top: 4px;
        }

        .stat-card .sub {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        .stat-card .sub .warning {
            color: #f59e0b;
        }

        .stat-card .sub .danger {
            color: #ef4444;
        }

        /* Quick Actions */

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .action-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            text-decoration: none;
            color: #111827;
            transition: 0.2s;
            text-align: center;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border-color: #111827;
        }

        .action-card .action-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .action-card .action-title {
            font-weight: 600;
            font-size: 15px;
        }

        .action-card .action-description {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .dashboard-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .dashboard-section h2 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 18px;
        }

        /* Role badge */

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            background: #fef3c7;
            color: #92400e;
        }

        .role-admin {
            background: #dbeafe;
            color: #1e40af;
        }

        @media (max-width: 700px) {

            .dashboard-grid {
                grid-template-columns: 1fr 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 480px) {

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
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

                    <h1>

                        <?= $is_admin
                            ? '📊 Admin Dashboard'
                            : '👤 Cashier Dashboard'
                        ?>

                        <span
                            class="role-badge <?= $is_admin ? 'role-admin' : '' ?>"
                        >
                            <?= htmlspecialchars(ucfirst($user_role)) ?>
                        </span>

                    </h1>

                    <p>

                        Welcome,
                        <?= htmlspecialchars($_SESSION["full_name"] ?? "User") ?>.

                        <?= $is_admin
                            ? 'Here\'s your business overview.'
                            : 'Start by making a sale.'
                        ?>

                    </p>

                </div>

            </div>


            <!-- ============================================================
                 STATISTICS
            ============================================================ -->

            <div class="dashboard-grid">


                <!-- TODAY'S TRANSACTIONS -->

                <div class="stat-card">

                    <div class="icon">
                        🧾
                    </div>

                    <div class="label">
                        Today's Sales
                    </div>

                    <div class="value">
                        <?= $today_transactions ?>
                    </div>

                    <div class="sub">
                        Transactions you've completed today
                    </div>

                </div>


                <!-- TOTAL PRODUCTS -->

                <div class="stat-card">

                    <div class="icon">
                        📦
                    </div>

                    <div class="label">
                        Products
                    </div>

                    <div class="value">
                        <?= $total_products ?>
                    </div>

                    <div class="sub">
                        Active products in inventory
                    </div>

                </div>


                <!-- LOW STOCK -->

                <div
                    class="stat-card"
                    style="
                        border-color:
                        <?= $low_stock_count > 0
                            ? '#f59e0b'
                            : '#e5e7eb'
                        ?>;
                    "
                >

                    <div class="icon">
                        ⚠️
                    </div>

                    <div class="label">
                        Low Stock
                    </div>

                    <div
                        class="value"
                        style="
                            color:
                            <?= $low_stock_count > 0
                                ? '#f59e0b'
                                : '#16a34a'
                            ?>;
                        "
                    >

                        <?= $low_stock_count ?>

                    </div>

                    <div class="sub">

                        <?php if ($low_stock_count > 0): ?>

                            <span class="warning">
                                Products need restocking
                            </span>

                        <?php else: ?>

                            <span style="color: #16a34a;">
                                ✅ All products well stocked
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- TODAY'S DATE -->

                <div class="stat-card">

                    <div class="icon">
                        📅
                    </div>

                    <div class="label">
                        Today
                    </div>

                    <div
                        class="value"
                        style="font-size: 22px;"
                    >
                        <?= htmlspecialchars($display_date) ?>
                    </div>

                    <div class="sub">
                        <?= htmlspecialchars($display_day) ?>
                    </div>

                </div>

            </div>


            <!-- ============================================================
                 QUICK ACTIONS
            ============================================================ -->

            <div class="dashboard-section">

                <h2>
                    ⚡ Quick Actions
                </h2>

                <div class="quick-actions-grid">


                    <!-- NEW SALE -->

                    <a
                        href="../sales/pos.php"
                        class="action-card"
                        style="
                            border-color: #22c55e;
                            border-width: 2px;
                        "
                    >

                        <div class="action-icon">
                            🛒
                        </div>

                        <div class="action-title">
                            New Sale
                        </div>

                        <div class="action-description">
                            Start a new customer sale
                        </div>

                    </a>


                    <!-- PRODUCTS -->

                    <a
                        href="../products/view_products.php"
                        class="action-card"
                    >

                        <div class="action-icon">
                            📦
                        </div>

                        <div class="action-title">
                            View Products
                        </div>

                        <div class="action-description">
                            Browse all products
                        </div>

                    </a>


                    <!-- INVENTORY -->

                    <a
                        href="../inventory/inventory.php"
                        class="action-card"
                    >

                        <div class="action-icon">
                            📊
                        </div>

                        <div class="action-title">
                            Inventory
                        </div>

                        <div class="action-description">
                            Check stock levels
                        </div>

                    </a>


                    <!-- RETURNS -->

                    <a
                        href="../returns/process_return.php"
                        class="action-card"
                    >

                        <div class="action-icon">
                            ↩️
                        </div>

                        <div class="action-title">
                            Process Return
                        </div>

                        <div class="action-description">
                            Handle a customer return
                        </div>

                    </a>

                </div>

            </div>


            <!-- ============================================================
                 ADMIN TOOLS
            ============================================================ -->

            <?php if ($is_admin): ?>

                <div class="dashboard-section">

                    <h2>
                        📊 Admin Tools
                    </h2>

                    <div class="quick-actions-grid">


                        <!-- SALES HISTORY -->

                        <a
                            href="../sales/sales_history.php"
                            class="action-card"
                            style="
                                border-color: #3b82f6;
                                border-width: 2px;
                            "
                        >

                            <div class="action-icon">
                                🧾
                            </div>

                            <div class="action-title">
                                Sales History
                            </div>

                            <div class="action-description">
                                View all sales reports
                            </div>

                        </a>


                        <!-- EXPENSES -->

                        <a
                            href="../expenses/expenses.php"
                            class="action-card"
                        >

                            <div class="action-icon">
                                💸
                            </div>

                            <div class="action-title">
                                Expenses
                            </div>

                            <div class="action-description">
                                Manage business expenses
                            </div>

                        </a>


                        <!-- USERS -->

                        <a
                            href="../users/users.php"
                            class="action-card"
                        >

                            <div class="action-icon">
                                👥
                            </div>

                            <div class="action-title">
                                Manage Users
                            </div>

                            <div class="action-description">
                                Add or manage cashiers
                            </div>

                        </a>


                        <!-- ADD PRODUCT -->

                        <a
                            href="../products/add.php"
                            class="action-card"
                        >

                            <div class="action-icon">
                                ➕
                            </div>

                            <div class="action-title">
                                Add Product
                            </div>

                            <div class="action-description">
                                Add new product to inventory
                            </div>

                        </a>

                    </div>

                </div>

            <?php endif; ?>


            <!-- ============================================================
                 CASHIER TIPS
            ============================================================ -->

            <div
                style="
                    background: #f0fdf4;
                    border: 1px solid #86efac;
                    border-radius: 10px;
                    padding: 16px 20px;
                    margin-top: 10px;
                "
            >

                <div
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 12px;
                    "
                >

                    <span style="font-size: 20px;">
                        💡
                    </span>

                    <div>

                        <strong style="color: #166534;">
                            Cashier Tips
                        </strong>

                        <ul
                            style="
                                margin: 6px 0 0 0;
                                padding-left: 20px;
                                color: #065f46;
                                font-size: 14px;
                            "
                        >

                            <li>
                                Click
                                <strong>"New Sale"</strong>
                                to start serving a customer
                            </li>

                            <li>
                                Check
                                <strong>"Inventory"</strong>
                                to see current stock levels
                            </li>

                            <li>
                                Use
                                <strong>"View Products"</strong>
                                to search and browse products
                            </li>

                            <?php if ($low_stock_count > 0): ?>

                                <li style="color: #f59e0b;">

                                    ⚠️
                                    <strong>
                                        <?= $low_stock_count ?>
                                    </strong>

                                    products are low on stock.
                                    Notify your manager.

                                </li>

                            <?php endif; ?>

                        </ul>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

</body>

</html>
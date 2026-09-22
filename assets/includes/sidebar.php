<?php

/*
|--------------------------------------------------------------------------
| BizFlow Sidebar
|--------------------------------------------------------------------------
*/

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['PHP_SELF'];


/*
|--------------------------------------------------------------------------
| Load Permissions
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/../../config/permissions.php";


/*
|--------------------------------------------------------------------------
| Current Role
|--------------------------------------------------------------------------
*/

$user_role = currentUserRole();


/*
|--------------------------------------------------------------------------
| Current Inventory Section
|--------------------------------------------------------------------------
*/

$current_section = $_GET['section'] ?? '';

?>

<aside class="sidebar">

    <div class="logo">
        BizFlow
        <span>Business Management & POS</span>
    </div>


    <!-- =========================================================
         MAIN
    ========================================================== -->

    <div class="nav-title">
        Main
    </div>


    <!-- DASHBOARD -->

    <?php if (hasPermission("view_dashboard")): ?>

        <?php if (isAdmin()): ?>

            <a
                href="/BIZFLOW/dashboard.php"
                class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>"
            >
                Dashboard
            </a>

        <?php elseif (isCashier()): ?>

            <a
                href="/BIZFLOW/cashier/dashboard.php"
                class="nav-link <?= $current_path === '/BIZFLOW/cashier/dashboard.php' ? 'active' : '' ?>"
            >
                Dashboard
            </a>

        <?php endif; ?>

    <?php endif; ?>


    <!-- POS / SALES -->

    <?php if (hasPermission("use_pos")): ?>

        <a
            href="/BIZFLOW/sales/pos.php"
            class="nav-link <?= strpos($current_path, '/sales/') !== false ? 'active' : '' ?>"
        >
            POS / Sales
        </a>

    <?php endif; ?>


    <!-- PRODUCTS -->

    <?php if (hasPermission("view_products")): ?>

        <a
            href="/BIZFLOW/products/view_products.php"
            class="nav-link <?= strpos($current_path, '/products/') !== false ? 'active' : '' ?>"
        >
            Products
        </a>

    <?php endif; ?>


    <!-- DISCOUNTS (Admin only) -->

    <?php if (isAdmin() && hasPermission("manage_discounts")): ?>

        <a
            href="/BIZFLOW/products/manage_discounts.php"
            class="nav-link <?= strpos($current_path, 'manage_discounts') !== false ? 'active' : '' ?>"
        >
            🏷️ Discounts
        </a>

    <?php endif; ?>


    <!-- =========================================================
         INVENTORY
    ========================================================== -->

    <?php if (hasPermission("view_inventory")): ?>


        <!-- INVENTORY -->

        <a
            href="/BIZFLOW/inventory/inventory.php"
            class="nav-link <?= (
                $current_path === '/BIZFLOW/inventory/inventory.php'
                && $current_section !== 'reconciliation'
            ) ? 'active' : '' ?>"
        >
            Inventory
        </a>


        <!-- =====================================================
             STOCK & MONEY RECONCILIATION
             ADMIN / OWNER ONLY
        ====================================================== -->

        <?php if (isAdmin()): ?>

            <a
                href="/BIZFLOW/inventory/inventory.php?section=reconciliation"
                class="nav-link <?= (
                    $current_path === '/BIZFLOW/inventory/inventory.php'
                    && $current_section === 'reconciliation'
                ) ? 'active' : '' ?>"
            >
                📊 Stock & Money Reconciliation
            </a>

        <?php endif; ?>


    <?php endif; ?>


    <!-- =========================================================
         BUSINESS
    ========================================================== -->

    <div class="nav-title">
        Business
    </div>


    <!-- SUPPLIERS -->

    <?php if (hasPermission("view_suppliers")): ?>

        <a
            href="/BIZFLOW/suppliers/suppliers.php"
            class="nav-link <?= strpos($current_path, '/suppliers/') !== false ? 'active' : '' ?>"
        >
            Suppliers
        </a>

    <?php endif; ?>


    <!-- EXPENSES -->

    <?php if (hasPermission("view_expenses")): ?>

        <a
            href="/BIZFLOW/expenses/expenses.php"
            class="nav-link <?= strpos($current_path, '/expenses/') !== false ? 'active' : '' ?>"
        >
            Expenses
        </a>

    <?php endif; ?>


    <!-- RETURNS -->

    <?php if (hasPermission("process_returns")): ?>

        <a
            href="/BIZFLOW/returns/returns.php"
            class="nav-link <?= strpos($current_path, '/returns/') !== false ? 'active' : '' ?>"
        >
            Returns
        </a>

    <?php endif; ?>


    <!-- =========================================================
         CASHIER
         SALES HISTORY REMOVED
    ========================================================== -->

    <?php if (isCashier()): ?>

        <div class="nav-title">
            Cashier
        </div>

        <!-- Cashier quick actions -->
        <!-- Sales History intentionally removed -->

    <?php endif; ?>


    <!-- =========================================================
         MANAGEMENT
    ========================================================== -->

    <?php if (isAdmin()): ?>

        <div class="nav-title">
            Management
        </div>


        <!-- SALES HISTORY - ADMIN ONLY -->

        <?php if (hasPermission("view_sales_history")): ?>

            <a
                href="/BIZFLOW/sales/sales_history.php"
                class="nav-link <?= $current_page === 'sales_history.php' ? 'active' : '' ?>"
            >
                Sales History
            </a>

        <?php endif; ?>


        <!-- REPORTS -->

        <?php if (hasPermission("view_reports")): ?>


            <!-- PROFIT REPORT -->

            <a
                href="/BIZFLOW/reports/profit.php"
                class="nav-link <?= strpos($current_path, '/reports/profit.php') !== false ? 'active' : '' ?>"
            >
                Profit Report
            </a>


            <!-- PERIODIC REPORT -->

            <a
                href="/BIZFLOW/reports/periodic_report.php"
                class="nav-link <?= strpos($current_path, '/reports/periodic_report.php') !== false ? 'active' : '' ?>"
            >
                📈 Periodic Report
            </a>


            <!-- DAILY REPORT -->

            <a
                href="/BIZFLOW/reports/daily_report.php"
                class="nav-link <?= strpos($current_path, '/reports/daily_report.php') !== false ? 'active' : '' ?>"
            >
                📊 Daily Report
            </a>


        <?php endif; ?>


        <!-- USERS -->

        <?php if (hasPermission("manage_users")): ?>

            <a
                href="/BIZFLOW/users/users.php"
                class="nav-link <?= strpos($current_path, '/users/') !== false ? 'active' : '' ?>"
            >
                Users
            </a>

        <?php endif; ?>


        <!-- SETTINGS -->

        <?php if (hasPermission("manage_settings")): ?>

            <a
                href="/BIZFLOW/settings.php"
                class="nav-link <?= $current_page === 'settings.php' ? 'active' : '' ?>"
            >
                Settings
            </a>

        <?php endif; ?>


    <?php endif; ?>


</aside>
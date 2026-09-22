<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];

// Get user role - Case insensitive check
$stmt = $pdo->prepare("
    SELECT r.name 
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
");
$stmt->execute([':user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user['name'] ?? 'cashier';

// Check both cases
$admin_roles = ['admin', 'administrator', 'owner', 'manager', 'Admin', 'ADMIN', 'Administrator', 'OWNER', 'MANAGER'];
$is_admin = in_array($user_role, $admin_roles);

$access_denied = false;
if (!$is_admin) {
    $access_denied = true;
}

// Get date range from GET
$period_type = $_GET['period_type'] ?? 'monthly';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Set default periods based on selection
if ($period_type === 'quarterly') {
    $start_date = date('Y-m-01', strtotime('-3 months'));
    $end_date = date('Y-m-d');
} elseif ($period_type === 'semi_annual') {
    $start_date = date('Y-m-01', strtotime('-6 months'));
    $end_date = date('Y-m-d');
} elseif ($period_type === 'annual') {
    $start_date = date('Y-m-01', strtotime('-12 months'));
    $end_date = date('Y-m-d');
} elseif ($period_type === 'custom') {
    $start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
}

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01', strtotime('-3 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}

function money($amount)
{
    return "KSh " . number_format((float)$amount, 2);
}

function getMonthName($month_num)
{
    $months = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'
    ];
    return $months[$month_num] ?? $month_num;
}

function differenceLabel($difference) {
    if ($difference === null) return "Not Counted";
    if ($difference < 0) return "Shortage / Loss";
    if ($difference > 0) return "Surplus / Excess";
    return "Balanced";
}

// ============================================================
// GET MONTHLY DATA - FIXED SQL
// ============================================================

$sql = "
    SELECT 
        DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
        COUNT(DISTINCT s.id) AS transactions,
        COALESCE(SUM(s.total_amount), 0) AS total_sales,
        COALESCE(SUM(CASE 
            WHEN s.payment_method = 'cash' OR s.payment_method = 'mixed' 
            THEN s.cash_amount - s.change_given
            ELSE 0 
        END), 0) AS cash_sales,
        COALESCE(SUM(CASE 
            WHEN s.payment_method = 'bank' OR s.payment_method = 'mixed' 
            THEN s.bank_amount 
            ELSE 0 
        END), 0) AS bank_sales,
        COUNT(DISTINCT s.user_id) AS active_cashiers
    FROM sales s
    WHERE s.business_id = :business_id
    AND s.sale_status = 'completed'
    AND s.sale_date BETWEEN :start_date AND :end_date
    GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
    ORDER BY month ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// GET EXPENSES BY MONTH (Separate query)
// ============================================================

$sql_expenses = "
    SELECT 
        DATE_FORMAT(expense_date, '%Y-%m') AS month,
        COALESCE(SUM(amount), 0) AS total_expenses
    FROM expenses
    WHERE business_id = :business_id
    AND expense_date BETWEEN :start_date AND :end_date
    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
";

$stmt = $pdo->prepare($sql_expenses);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$expenses_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create expenses lookup array
$expenses_lookup = [];
foreach ($expenses_data as $exp) {
    $expenses_lookup[$exp['month']] = (float) $exp['total_expenses'];
}

// ============================================================
// GET RETURNS BY MONTH (Separate query)
// ============================================================

$sql_returns = "
    SELECT 
        DATE_FORMAT(r.created_at, '%Y-%m') AS month,
        COALESCE(SUM(r.refund_amount), 0) AS total_returns
    FROM returns r
    WHERE r.business_id = :business_id
    AND r.status = 'completed'
    AND r.created_at BETWEEN :start_date AND :end_date
    GROUP BY DATE_FORMAT(r.created_at, '%Y-%m')
";

$stmt = $pdo->prepare($sql_returns);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$returns_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create returns lookup array
$returns_lookup = [];
foreach ($returns_data as $ret) {
    $returns_lookup[$ret['month']] = (float) $ret['total_returns'];
}

// ============================================================
// GET RECONCILIATION DATA BY MONTH
// ============================================================

$sql_recon_monthly = "
    SELECT 
        DATE_FORMAT(r.reconciliation_date, '%Y-%m') AS month,
        COALESCE(SUM(r.actual_cash), 0) AS total_money_counted,
        COALESCE(SUM(r.system_cash), 0) AS system_expected_cash,
        COALESCE(SUM(r.difference), 0) AS total_difference,
        COUNT(*) AS recon_days
    FROM daily_reconciliations r
    WHERE r.business_id = :business_id
    AND r.reconciliation_date BETWEEN :start_date AND :end_date
    GROUP BY DATE_FORMAT(r.reconciliation_date, '%Y-%m')
";

$stmt = $pdo->prepare($sql_recon_monthly);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$recon_monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create reconciliation lookup array
$recon_lookup = [];
foreach ($recon_monthly_data as $rec) {
    $recon_lookup[$rec['month']] = [
        'total_counted' => (float) $rec['total_money_counted'],
        'system_expected' => (float) $rec['system_expected_cash'],
        'difference' => (float) $rec['total_difference'],
        'days' => (int) $rec['recon_days']
    ];
}

// ============================================================
// MERGE DATA
// ============================================================

foreach ($monthly_data as &$month) {
    $month_key = $month['month'];
    $month['expenses'] = $expenses_lookup[$month_key] ?? 0;
    $month['refunds'] = $returns_lookup[$month_key] ?? 0;
    $month['net_sales'] = $month['total_sales'] - $month['refunds'];
    $month['profit'] = $month['total_sales'] - $month['refunds'] - $month['expenses'];
    
    // NEW: Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
    $month['system_expected_money'] = $month['cash_sales'] + $month['bank_sales'] - $month['expenses'] - $month['refunds'];
    
    // Get reconciliation data for this month
    if (isset($recon_lookup[$month_key])) {
        $month['total_money_counted'] = $recon_lookup[$month_key]['total_counted'];
        $month['recon_difference'] = $recon_lookup[$month_key]['difference'];
        $month['recon_days'] = $recon_lookup[$month_key]['days'];
    } else {
        $month['total_money_counted'] = null;
        $month['recon_difference'] = null;
        $month['recon_days'] = 0;
    }
    
    // Legacy cash_at_hand for backward compatibility
    $month['cash_at_hand'] = max(0, $month['cash_sales'] - $month['expenses'] - $month['refunds']);
}

// ============================================================
// GET TOTALS
// ============================================================

$total_sales = 0;
$total_cash = 0;
$total_bank = 0;
$total_expenses = 0;
$total_refunds = 0;
$total_profit = 0;
$total_transactions = 0;
$total_cashiers = 0;
$total_expected_money = 0;
$total_counted_money = 0;
$total_difference = 0;
$total_recon_days = 0;

foreach ($monthly_data as $month) {
    $total_sales += $month['total_sales'];
    $total_cash += $month['cash_sales'];
    $total_bank += $month['bank_sales'];
    $total_expenses += $month['expenses'];
    $total_refunds += $month['refunds'];
    $total_profit += $month['profit'];
    $total_transactions += $month['transactions'];
    $total_cashiers = max($total_cashiers, $month['active_cashiers']);
    $total_expected_money += $month['system_expected_money'];
    
    if ($month['total_money_counted'] !== null) {
        $total_counted_money += $month['total_money_counted'];
        $total_difference += $month['recon_difference'];
        $total_recon_days += $month['recon_days'];
    }
}

$net_sales_total = $total_sales - $total_refunds;
$profit_margin = $total_sales > 0 ? ($total_profit / $total_sales) * 100 : 0;
$recon_margin = $total_expected_money > 0 ? (($total_counted_money - $total_expected_money) / $total_expected_money) * 100 : 0;

// ============================================================
// GET CASHIER SUMMARY
// ============================================================

$sql_cashiers = "
    SELECT 
        u.id,
        u.full_name,
        COUNT(s.id) AS transactions,
        COALESCE(SUM(s.total_amount), 0) AS total_sales,
        COALESCE(SUM(CASE 
            WHEN s.payment_method = 'cash' OR s.payment_method = 'mixed' 
            THEN s.cash_amount - s.change_given
            ELSE 0 
        END), 0) AS cash_sales,
        COALESCE(SUM(CASE 
            WHEN s.payment_method = 'bank' OR s.payment_method = 'mixed' 
            THEN s.bank_amount 
            ELSE 0 
        END), 0) AS bank_sales
    FROM users u
    LEFT JOIN sales s ON u.id = s.user_id 
        AND s.business_id = :business_id 
        AND s.sale_status = 'completed'
        AND s.sale_date BETWEEN :start_date AND :end_date
    WHERE u.business_id = :business_id
    AND u.role_id IN (SELECT id FROM roles WHERE name = 'cashier')
    GROUP BY u.id, u.full_name
    ORDER BY total_sales DESC
";

$stmt = $pdo->prepare($sql_cashiers);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$cashier_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expenses and refunds for each cashier separately
foreach ($cashier_summary as &$cashier) {
    // Get expenses for this cashier
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE business_id = :business_id
        AND user_id = :cashier_id
        AND expense_date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':business_id' => $business_id,
        ':cashier_id' => $cashier['id'],
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $cashier['expenses'] = (float) $stmt->fetchColumn();
    
    // Get refunds for this cashier
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(refund_amount), 0) AS total
        FROM returns
        WHERE business_id = :business_id
        AND user_id = :cashier_id
        AND status = 'completed'
        AND created_at BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':business_id' => $business_id,
        ':cashier_id' => $cashier['id'],
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $cashier['refunds'] = (float) $stmt->fetchColumn();
    
    $cashier['profit'] = $cashier['total_sales'] - $cashier['refunds'] - $cashier['expenses'];
    $cashier['cash_at_hand'] = max(0, $cashier['cash_sales'] - $cashier['expenses'] - $cashier['refunds']);
    $cashier['system_expected_money'] = $cashier['cash_sales'] + $cashier['bank_sales'] - $cashier['expenses'] - $cashier['refunds'];
}

// ============================================================
// GET RECONCILIATION SUMMARY
// ============================================================

$sql_recon = "
    SELECT 
        DATE(r.reconciliation_date) AS date,
        u.full_name AS cashier_name,
        r.system_cash,
        r.actual_cash,
        r.difference,
        r.status,
        r.resolution,
        r.collected_cash,
        r.collected_bank
    FROM daily_reconciliations r
    LEFT JOIN users u ON r.cashier_id = u.id
    WHERE r.business_id = :business_id
    AND r.reconciliation_date BETWEEN :start_date AND :end_date
    ORDER BY r.reconciliation_date DESC
";

$stmt = $pdo->prepare($sql_recon);
$stmt->execute([
    ':business_id' => $business_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$reconciliation_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_shortages = 0;
$total_excess = 0;
$shortage_days = 0;
$excess_days = 0;
$balanced_days = 0;

foreach ($reconciliation_data as $rec) {
    $diff = (float) $rec['difference'];
    if ($diff < 0) {
        $total_shortages += abs($diff);
        $shortage_days++;
    } elseif ($diff > 0) {
        $total_excess += $diff;
        $excess_days++;
    } else {
        $balanced_days++;
    }
}

// Period label
$period_label = '';
$period_color = '';
$period_icon = '';

switch ($period_type) {
    case 'monthly':
        $period_label = 'Monthly Report';
        $period_color = '#2563eb';
        $period_icon = '📅';
        break;
    case 'quarterly':
        $period_label = 'Quarterly Report (3 Months)';
        $period_color = '#7c3aed';
        $period_icon = '📊';
        break;
    case 'semi_annual':
        $period_label = 'Semi-Annual Report (6 Months)';
        $period_color = '#d97706';
        $period_icon = '📈';
        break;
    case 'annual':
        $period_label = 'Annual Report (1 Year)';
        $period_color = '#dc2626';
        $period_icon = '🎯';
        break;
    default:
        $period_label = 'Custom Period Report';
        $period_color = '#6b7280';
        $period_icon = '📋';
        break;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="periodic_report_' . $start_date . '_to_' . $end_date . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Month', 'Transactions', 'Total Sales', 'Cash Sales', 'Bank/M-Pesa', 'Expenses', 'Returns', 'System Expected Money', 'Money Counted', 'Difference', 'Profit', 'Active Cashiers']);
    
    foreach ($monthly_data as $month) {
        $month_parts = explode('-', $month['month']);
        $month_label = getMonthName($month_parts[1]) . ' ' . $month_parts[0];
        fputcsv($output, [
            $month_label,
            $month['transactions'],
            number_format($month['total_sales'], 2),
            number_format($month['cash_sales'], 2),
            number_format($month['bank_sales'], 2),
            number_format($month['expenses'], 2),
            number_format($month['refunds'], 2),
            number_format($month['system_expected_money'], 2),
            $month['total_money_counted'] !== null ? number_format($month['total_money_counted'], 2) : '',
            $month['recon_difference'] !== null ? number_format($month['recon_difference'], 2) : '',
            number_format($month['profit'], 2),
            $month['active_cashiers']
        ]);
    }
    
    fputcsv($output, ['TOTALS', $total_transactions, number_format($total_sales, 2), number_format($total_cash, 2), number_format($total_bank, 2), number_format($total_expenses, 2), number_format($total_refunds, 2), number_format($total_expected_money, 2), number_format($total_counted_money, 2), number_format($total_difference, 2), number_format($total_profit, 2), $total_cashiers]);
    
    fclose($output);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Periodic Report - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 25px;
        }
        .report-header h1 { margin: 0; }
        .period-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            color: white;
        }
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            margin-bottom: 25px;
        }
        .filter-section .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-section .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-section .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-section .filter-group input,
        .filter-section .filter-group select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            min-width: 150px;
        }
        .filter-section .btn-filter {
            padding: 8px 20px;
            background: #111827;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .filter-section .btn-filter:hover { background: #374151; }
        .filter-section .btn-reset {
            padding: 8px 20px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
        }
        .filter-section .btn-reset:hover { background: #e5e7eb; }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .summary-card {
            background: white;
            padding: 16px 18px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        .summary-card .label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .summary-card .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .summary-card .sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .value-green { color: #16a34a; }
        .value-red { color: #dc2626; }
        .value-blue { color: #2563eb; }
        .value-purple { color: #7c3aed; }
        .value-orange { color: #d97706; }
        .value-dark { color: #111827; }
        
        .table-container {
            background: white;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            overflow-x: auto;
            margin-bottom: 25px;
        }
        .table-container .table-header {
            padding: 14px 18px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-container .table-header h3 { margin: 0; font-size: 16px; }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .report-table th {
            background: #f9fafb;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        .report-table td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; }
        .report-table .text-right { text-align: right; }
        .report-table .text-center { text-align: center; }
        .report-table tr:hover td { background: #f9fafb; }
        .report-table .total-row { background: #f3f4f6; font-weight: 700; }
        .report-table .total-row td { border-top: 2px solid #d1d5db; }
        .report-table .recon-row td { background: #eff6ff; border-top: 1px dashed #93c5fd; }
        
        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        
        .export-btn {
            padding: 8px 16px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .export-btn:hover { background: #15803d; }
        .print-btn {
            padding: 8px 16px;
            background: #111827;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .print-btn:hover { background: #374151; }
        
        .access-denied {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: #991b1b;
        }
        .access-denied strong { display: block; font-size: 16px; margin-bottom: 4px; }
        
        .recon-highlight {
            border-left: 4px solid #2563eb;
            background: #f0f7ff;
        }
        .recon-highlight .label { color: #1e40af; }
        .recon-highlight .value { color: #1e40af; }
        
        .difference-positive { color: #16a34a; }
        .difference-negative { color: #dc2626; }
        .difference-zero { color: #6b7280; }
        
        @media (max-width: 768px) {
            .filter-section .filter-row { flex-direction: column; align-items: stretch; }
            .filter-section .filter-group input,
            .filter-section .filter-group select { min-width: 100%; }
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) { .summary-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="app">
    <?php include "../assets/includes/sidebar.php"; ?>
    
    <main class="main">
        <?php include "../assets/includes/topbar.php"; ?>
        
        <section class="content">
            
            <?php if ($access_denied): ?>
                <div class="access-denied">
                    <strong>⚠️ Access Warning</strong>
                    You are logged in as a cashier. Some financial data may be limited.
                    <br><small>Your role: <?= htmlspecialchars($user_role) ?></small>
                </div>
            <?php endif; ?>
            
            <!-- Page Header -->
            <div class="report-header">
                <div>
                    <h1><?= $period_icon ?> <?= $period_label ?></h1>
                    <p style="color: #6b7280; margin-top: 4px;">
                        From <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?>
                        <span class="period-badge" style="background: <?= $period_color ?>;">
                            <?= strtoupper(str_replace('_', ' ', $period_type)) ?>
                        </span>
                    </p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button onclick="window.print()" class="print-btn">🖨️ Print</button>
                    <a href="?period_type=<?= $period_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export=csv" class="export-btn">📊 Export CSV</a>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-row">
                    <div class="filter-group">
                        <label for="period_type">Report Period</label>
                        <select name="period_type" id="period_type">
                            <option value="monthly" <?= $period_type == 'monthly' ? 'selected' : '' ?>>📅 Monthly</option>
                            <option value="quarterly" <?= $period_type == 'quarterly' ? 'selected' : '' ?>>📊 Quarterly (3 Months)</option>
                            <option value="semi_annual" <?= $period_type == 'semi_annual' ? 'selected' : '' ?>>📈 Semi-Annual (6 Months)</option>
                            <option value="annual" <?= $period_type == 'annual' ? 'selected' : '' ?>>🎯 Annual (1 Year)</option>
                            <option value="custom" <?= $period_type == 'custom' ? 'selected' : '' ?>>📋 Custom</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" value="<?= $start_date ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" value="<?= $end_date ?>">
                    </div>
                    <div style="display: flex; gap: 10px; padding-top: 22px;">
                        <button type="submit" class="btn-filter">🔍 Generate Report</button>
                        <a href="periodic_report.php" class="btn-reset">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Sales</div>
                    <div class="value value-blue"><?= money($total_sales) ?></div>
                    <div class="sub"><?= $total_transactions ?> transactions</div>
                </div>
                <div class="summary-card" style="border-color: #7c3aed;">
                    <div class="label">🏦 Bank / M-Pesa</div>
                    <div class="value value-purple"><?= money($total_bank) ?></div>
                    <div class="sub">Non-cash payments</div>
                </div>
                <div class="summary-card">
                    <div class="label">💵 Cash Sales</div>
                    <div class="value"><?= money($total_cash) ?></div>
                    <div class="sub">Physical cash collected</div>
                </div>
                <div class="summary-card">
                    <div class="label">Net Sales</div>
                    <div class="value value-blue"><?= money($net_sales_total) ?></div>
                    <div class="sub">Sales minus returns</div>
                </div>
                <div class="summary-card" style="border-color: #dc2626;">
                    <div class="label">💸 Total Expenses</div>
                    <div class="value value-red"><?= money($total_expenses) ?></div>
                    <div class="sub">Operating costs</div>
                </div>
                <div class="summary-card" style="border-color: #f59e0b;">
                    <div class="label">🔄 Returns</div>
                    <div class="value value-orange"><?= money($total_refunds) ?></div>
                    <div class="sub">Refunds processed</div>
                </div>
                <div class="summary-card" style="border-color: #16a34a;">
                    <div class="label">📈 Net Profit</div>
                    <div class="value <?= $total_profit >= 0 ? 'value-green' : 'value-red' ?>">
                        <?= money($total_profit) ?>
                    </div>
                    <div class="sub">Margin: <?= number_format($profit_margin, 1) ?>%</div>
                </div>
                <div class="summary-card">
                    <div class="label">👥 Active Cashiers</div>
                    <div class="value value-dark"><?= $total_cashiers ?></div>
                    <div class="sub">Cashiers with sales</div>
                </div>
            </div>
            
            <!-- RECONCILIATION SUMMARY - NEW SECTION -->
            <h2 style="font-size: 18px; font-weight: 700; margin: 25px 0 15px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;">
                💰 Reconciliation Summary
            </h2>
            
            <div class="summary-grid">
                <div class="summary-card recon-highlight" style="border-color: #2563eb; border-width: 2px;">
                    <div class="label">💰 Total System Expected Money</div>
                    <div class="value value-blue"><?= money($total_expected_money) ?></div>
                    <div class="sub">Cash + Bank - Expenses - Returns</div>
                </div>
                <div class="summary-card" style="border-color: #16a34a; border-width: 2px; background: #f0fdf4;">
                    <div class="label">💰 Total Money Counted</div>
                    <div class="value value-green"><?= money($total_counted_money) ?></div>
                    <div class="sub"><?= $total_recon_days ?> days reconciled</div>
                </div>
                <div class="summary-card" style="border-color: <?= $total_difference >= 0 ? '#16a34a' : '#dc2626' ?>; border-width: 2px;">
                    <div class="label">📊 Net Difference</div>
                    <div class="value <?= $total_difference >= 0 ? 'value-green' : 'value-red' ?>">
                        <?= $total_difference >= 0 ? '+' : '' ?><?= money($total_difference) ?>
                    </div>
                    <div class="sub"><?= $total_difference >= 0 ? 'Surplus / Excess' : 'Shortage / Loss' ?></div>
                </div>
                <div class="summary-card" style="border-color: #7c3aed;">
                    <div class="label">📊 Difference Margin</div>
                    <div class="value value-purple"><?= number_format($recon_margin, 1) ?>%</div>
                    <div class="sub">Of expected money</div>
                </div>
            </div>
            
            <!-- Reconciliation Summary Cards (Shortages/Excess) -->
            <?php if (count($reconciliation_data) > 0): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px;">
                    <div class="summary-card" style="border-color: #dc2626;">
                        <div class="label">🔴 Total Shortages</div>
                        <div class="value value-red"><?= money($total_shortages) ?></div>
                        <div class="sub"><?= $shortage_days ?> days with shortages</div>
                    </div>
                    <div class="summary-card" style="border-color: #16a34a;">
                        <div class="label">🟢 Total Surplus/Excess</div>
                        <div class="value value-green"><?= money($total_excess) ?></div>
                        <div class="sub"><?= $excess_days ?> days with excess</div>
                    </div>
                    <div class="summary-card" style="border-color: #2563eb;">
                        <div class="label">🔵 Balanced Days</div>
                        <div class="value value-blue"><?= $balanced_days ?></div>
                        <div class="sub">Days with no discrepancy</div>
                    </div>
                    <div class="summary-card" style="border-color: #f59e0b;">
                        <div class="label">📊 Net Reconciliation</div>
                        <div class="value <?= ($total_excess - $total_shortages) >= 0 ? 'value-green' : 'value-red' ?>">
                            <?= money($total_excess - $total_shortages) ?>
                        </div>
                        <div class="sub">Overall position</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Monthly Breakdown Table -->
            <?php if (count($monthly_data) > 0): ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3>📊 Monthly Breakdown</h3>
                        <span style="font-size: 13px; color: #6b7280;">
                            <?= count($monthly_data) ?> months
                        </span>
                    </div>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th class="text-right">Transactions</th>
                                <th class="text-right">Total Sales</th>
                                <th class="text-right">💵 Cash</th>
                                <th class="text-right">🏦 Bank</th>
                                <th class="text-right">💸 Expenses</th>
                                <th class="text-right">🔄 Returns</th>
                                <th class="text-right">💰 System Expected</th>
                                <th class="text-right">💰 Money Counted</th>
                                <th class="text-right">📊 Difference</th>
                                <th class="text-right">📈 Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_data as $month): ?>
                                <?php 
                                    $month_parts = explode('-', $month['month']);
                                    $month_label = getMonthName($month_parts[1]) . ' ' . $month_parts[0];
                                    $profit_class = $month['profit'] >= 0 ? 'value-green' : 'value-red';
                                    
                                    $diff_class = 'difference-zero';
                                    $diff_label = 'N/A';
                                    if ($month['recon_difference'] !== null) {
                                        if ($month['recon_difference'] < 0) {
                                            $diff_class = 'difference-negative';
                                            $diff_label = 'Shortage';
                                        } elseif ($month['recon_difference'] > 0) {
                                            $diff_class = 'difference-positive';
                                            $diff_label = 'Surplus';
                                        } else {
                                            $diff_label = 'Balanced';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?= $month_label ?></strong></td>
                                    <td class="text-right"><?= number_format($month['transactions']) ?></td>
                                    <td class="text-right"><?= money($month['total_sales']) ?></td>
                                    <td class="text-right"><?= money($month['cash_sales']) ?></td>
                                    <td class="text-right" style="color: #7c3aed;"><?= money($month['bank_sales']) ?></td>
                                    <td class="text-right"><?= money($month['expenses']) ?></td>
                                    <td class="text-right"><?= money($month['refunds']) ?></td>
                                    <td class="text-right" style="color: #2563eb; font-weight: 600;"><?= money($month['system_expected_money']) ?></td>
                                    <td class="text-right" style="color: #16a34a; font-weight: 600;">
                                        <?= $month['total_money_counted'] !== null ? money($month['total_money_counted']) : '—' ?>
                                        <?php if ($month['recon_days'] > 0): ?>
                                            <br><small style="font-weight: normal; color: #6b7280;">(<?= $month['recon_days'] ?> days)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right <?= $diff_class ?>">
                                        <?php if ($month['recon_difference'] !== null): ?>
                                            <?= $month['recon_difference'] >= 0 ? '+' : '' ?><?= money($month['recon_difference']) ?>
                                            <br><small style="font-weight: normal;"><?= $diff_label ?></small>
                                        <?php else: ?>
                                            <span style="color: #6b7280;">Not Reconciled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right <?= $profit_class ?>"><?= money($month['profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>TOTAL</strong></td>
                                <td class="text-right"><?= number_format($total_transactions) ?></td>
                                <td class="text-right"><?= money($total_sales) ?></td>
                                <td class="text-right"><?= money($total_cash) ?></td>
                                <td class="text-right" style="color: #7c3aed;"><?= money($total_bank) ?></td>
                                <td class="text-right"><?= money($total_expenses) ?></td>
                                <td class="text-right"><?= money($total_refunds) ?></td>
                                <td class="text-right" style="color: #2563eb; font-weight: 700;"><?= money($total_expected_money) ?></td>
                                <td class="text-right" style="color: #16a34a; font-weight: 700;"><?= money($total_counted_money) ?></td>
                                <td class="text-right <?= $total_difference >= 0 ? 'difference-positive' : 'difference-negative' ?>">
                                    <?= $total_difference >= 0 ? '+' : '' ?><?= money($total_difference) ?>
                                </td>
                                <td class="text-right <?= $total_profit >= 0 ? 'value-green' : 'value-red' ?>">
                                    <?= money($total_profit) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3>📊 Monthly Breakdown</h3>
                    </div>
                    <div style="padding: 30px; text-align: center; color: #6b7280;">
                        No sales data found for the selected period.
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Cashier Performance -->
            <?php if (count($cashier_summary) > 0): ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3>👥 Cashier Performance</h3>
                        <span style="font-size: 13px; color: #6b7280;">
                            <?= count($cashier_summary) ?> cashiers
                        </span>
                    </div>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Cashier</th>
                                <th class="text-right">Transactions</th>
                                <th class="text-right">Total Sales</th>
                                <th class="text-right">💵 Cash</th>
                                <th class="text-right">🏦 Bank</th>
                                <th class="text-right">💰 System Expected</th>
                                <th class="text-right">📈 Profit</th>
                                <th class="text-center">Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $max_sales = 0;
                            foreach ($cashier_summary as $c) {
                                if ($c['total_sales'] > $max_sales) $max_sales = $c['total_sales'];
                            }
                            foreach ($cashier_summary as $cashier): 
                                $performance = $max_sales > 0 ? ($cashier['total_sales'] / $max_sales) * 100 : 0;
                                $perf_class = $performance >= 80 ? 'badge-success' : ($performance >= 50 ? 'badge-warning' : 'badge-danger');
                                $perf_label = $performance >= 80 ? 'Top Performer' : ($performance >= 50 ? 'Average' : 'Needs Improvement');
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cashier['full_name']) ?></strong></td>
                                    <td class="text-right"><?= number_format($cashier['transactions']) ?></td>
                                    <td class="text-right"><?= money($cashier['total_sales']) ?></td>
                                    <td class="text-right"><?= money($cashier['cash_sales']) ?></td>
                                    <td class="text-right" style="color: #7c3aed;"><?= money($cashier['bank_sales']) ?></td>
                                    <td class="text-right" style="color: #2563eb;"><?= money($cashier['system_expected_money']) ?></td>
                                    <td class="text-right <?= $cashier['profit'] >= 0 ? 'value-green' : 'value-red' ?>">
                                        <?= money($cashier['profit']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-status <?= $perf_class ?>">
                                            <?= $perf_label ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Print Footer -->
            <div class="print-only" style="margin-top: 30px; border-top: 1px solid #000; padding-top: 15px; font-size: 12px;">
                <p>Report generated by BizFlow.</p>
                <p>Period: <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?></p>
                <p>Report Type: <?= $period_label ?></p>
                <p>Total System Expected Money: <?= money($total_expected_money) ?></p>
                <p>Total Money Counted: <?= money($total_counted_money) ?></p>
                <p>Net Difference: <?= money($total_difference) ?></p>
                <br>
                <p>Prepared By: ______________________________</p>
                <p>Approved By: ______________________________</p>
                <p>Signature: ______________________________</p>
            </div>
            
        </section>
    </main>
</div>

</body>
</html>
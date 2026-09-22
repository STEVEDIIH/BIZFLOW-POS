<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
/** @var PDO $pdo */

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];

// Check if admin
$stmt = $pdo->prepare("
    SELECT r.name 
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
");
$stmt->execute([':user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user['name'] ?? 'cashier';

$is_admin = in_array($user_role, ['admin', 'owner', 'manager']);

if (!$is_admin) {
    header("Location: ../cashier/dashboard.php");
    exit;
}

// Get filters
$activity_type = $_GET['activity_type'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

// Build the query
$sql = "
    SELECT 
        l.*,
        u.full_name AS user_name
    FROM inventory_activity_log l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.business_id = :business_id
";

$params = [':business_id' => $business_id];

if (!empty($activity_type)) {
    $sql .= " AND l.activity_type = :activity_type";
    $params[':activity_type'] = $activity_type;
}

if (!empty($date_from)) {
    $sql .= " AND DATE(l.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(l.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$sql .= " ORDER BY l.created_at DESC LIMIT :limit";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    if ($key === ':limit') {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity types for filter
$stmt = $pdo->prepare("
    SELECT DISTINCT activity_type 
    FROM inventory_activity_log 
    WHERE business_id = :business_id 
    ORDER BY activity_type ASC
");
$stmt->execute([':business_id' => $business_id]);
$activity_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get summary statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_activities,
        SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) AS total_added,
        SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) AS total_removed,
        COUNT(DISTINCT product_id) AS products_affected
    FROM inventory_activity_log
    WHERE business_id = :business_id
    AND DATE(created_at) BETWEEN :date_from AND :date_to
");
$stmt->execute([
    ':business_id' => $business_id,
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

function getActivityBadge($type) {
    $badges = [
        'product_added' => 'badge-success',
        'stock_adjustment' => 'badge-warning',
        'sale' => 'badge-info',
        'purchase' => 'badge-primary',
        'return' => 'badge-danger',
        'stock_take' => 'badge-secondary'
    ];
    return $badges[$type] ?? 'badge-info';
}

function getActivityIcon($type) {
    $icons = [
        'product_added' => '➕',
        'stock_adjustment' => '📦',
        'sale' => '🛒',
        'purchase' => '📥',
        'return' => '↩️',
        'stock_take' => '📊'
    ];
    return $icons[$type] ?? '📋';
}

function getActivityLabel($type) {
    $labels = [
        'product_added' => 'Product Added',
        'stock_adjustment' => 'Stock Adjusted',
        'sale' => 'Sale Made',
        'purchase' => 'Purchase Received',
        'return' => 'Return Processed',
        'stock_take' => 'Stock Take'
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Activity Log - BizFlow</title>
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
        }
        .summary-card .value {
            font-size: 22px;
            font-weight: 700;
            margin-top: 4px;
        }
        .summary-card .sub {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .value-green { color: #16a34a; }
        .value-blue { color: #2563eb; }
        .value-red { color: #dc2626; }
        .value-orange { color: #d97706; }
        .value-purple { color: #7c3aed; }
        
        .table-container {
            background: white;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            overflow-x: auto;
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
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .activity-table th {
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
        .activity-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        .activity-table .text-right { text-align: right; }
        .activity-table .text-center { text-align: center; }
        .activity-table tr:hover td { background: #f9fafb; }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-primary { background: #e0e7ff; color: #3730a3; }
        .badge-secondary { background: #f3f4f6; color: #374151; }
        
        .activity-icon { font-size: 20px; }
        .change-positive { color: #16a34a; font-weight: 700; }
        .change-negative { color: #dc2626; font-weight: 700; }
        .change-neutral { color: #6b7280; font-weight: 700; }
        .print-btn {
            padding: 8px 16px;
            background: #111827;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .print-btn:hover { background: #374151; }
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
        .activity-count {
            font-size: 13px;
            color: #6b7280;
        }
        @media (max-width: 768px) {
            .filter-section .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-section .filter-group input,
            .filter-section .filter-group select {
                min-width: 100%;
            }
            .summary-grid {
                grid-template-columns: 1fr 1fr;
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

            <div class="report-header">
                <div>
                    <h1>📋 Inventory Activity Log</h1>
                    <p>Track all inventory changes with dates and details</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button onclick="window.print()" class="print-btn">🖨️ Print</button>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-row">
                    <div class="filter-group">
                        <label for="activity_type">Activity Type</label>
                        <select name="activity_type" id="activity_type">
                            <option value="">All Activities</option>
                            <?php foreach ($activity_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= $activity_type == $type ? 'selected' : '' ?>>
                                    <?= getActivityLabel($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="limit">Records</label>
                        <select name="limit" id="limit">
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200</option>
                            <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 10px; padding-top: 22px;">
                        <button type="submit" class="btn-filter">🔍 Filter</button>
                        <a href="activity_log.php" class="btn-reset">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Summary -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Activities</div>
                    <div class="value value-blue"><?= number_format($summary['total_activities'] ?? 0) ?></div>
                    <div class="sub">In this period</div>
                </div>
                <div class="summary-card" style="border-color: #16a34a;">
                    <div class="label">Units Added</div>
                    <div class="value value-green"><?= number_format($summary['total_added'] ?? 0, 2) ?></div>
                    <div class="sub">Stock increased</div>
                </div>
                <div class="summary-card" style="border-color: #dc2626;">
                    <div class="label">Units Removed</div>
                    <div class="value value-red"><?= number_format($summary['total_removed'] ?? 0, 2) ?></div>
                    <div class="sub">Stock decreased</div>
                </div>
                <div class="summary-card" style="border-color: #7c3aed;">
                    <div class="label">Products Affected</div>
                    <div class="value value-purple"><?= number_format($summary['products_affected'] ?? 0) ?></div>
                    <div class="sub">Unique products</div>
                </div>
            </div>

            <!-- Activity Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>📋 Activity Log</h3>
                    <span class="activity-count"><?= count($activities) ?> records found</span>
                </div>
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Activity</th>
                            <th>Product</th>
                            <th class="text-right">Previous Stock</th>
                            <th class="text-right">New Stock</th>
                            <th class="text-right">Change</th>
                            <th>User</th>
                            <th>Reason / Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($activities)): ?>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td style="white-space: nowrap;">
                                        <?= date('d M Y H:i', strtotime($activity['created_at'])) ?>
                                    </td>
                                    <td>
                                        <span class="activity-icon"><?= getActivityIcon($activity['activity_type']) ?></span>
                                        <span class="badge <?= getActivityBadge($activity['activity_type']) ?>">
                                            <?= getActivityLabel($activity['activity_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($activity['product_name']) ?></strong>
                                        <?php if (!empty($activity['product_id'])): ?>
                                            <br><small style="color: #6b7280;">ID: #<?= $activity['product_id'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right"><?= number_format($activity['previous_stock'], 2) ?></td>
                                    <td class="text-right"><?= number_format($activity['new_stock'], 2) ?></td>
                                    <td class="text-right">
                                        <?php if ($activity['quantity_change'] > 0): ?>
                                            <span class="change-positive">+<?= number_format($activity['quantity_change'], 2) ?></span>
                                        <?php elseif ($activity['quantity_change'] < 0): ?>
                                            <span class="change-negative"><?= number_format($activity['quantity_change'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="change-neutral"><?= number_format($activity['quantity_change'], 2) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
                                    <td>
                                        <?php if (!empty($activity['reason'])): ?>
                                            <span style="font-weight: 500;"><?= htmlspecialchars($activity['reason']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($activity['notes'])): ?>
                                            <br><small style="color: #6b7280;"><?= htmlspecialchars($activity['notes']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:40px; color:#6b7280;">
                                    <div style="font-size: 48px; margin-bottom: 10px;">📭</div>
                                    No inventory activity found for the selected period.
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
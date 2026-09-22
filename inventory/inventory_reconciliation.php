<?php
// BIZFLOW/inventory/inventory_reconciliation.php
// Admin-facing inventory reconciliation/statistics page.

session_start();

// Use the same authentication/session setup as the existing BizFlow inventory.php.
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../assets/includes/inventory_stats.php';

$pdo = $pdo ?? ($db ?? null);

if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection not available.');
}

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$admin_id    = (int) ($_SESSION["user_id"] ?? 0);

$user_role = strtolower(
    (string) ($_SESSION["role"] ?? $_SESSION["user_role"] ?? "")
);

$is_admin = in_array($user_role, [
    "admin",
    "administrator",
    "owner"
], true);

if (!$business_id) {
    http_response_code(403);
    exit('Business session not found.');
}


$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$productId = $_GET['product_id'] ?? null;

$stats = getInventoryHistoricalStats($pdo, $business_id, $fromDate, $toDate, $productId);

// Normalize the result so this page also works with older/newer versions
// of inventory_stats.php where historical values may be nested differently.
$historical = (isset($stats['historical']) && is_array($stats['historical']))
    ? $stats['historical']
    : $stats;

// Some versions return the summary under 'summary'. Prefer it when present.
if (isset($stats['summary']) && is_array($stats['summary'])) {
    $historical = array_merge($historical, $stats['summary']);
}

$stats = array_merge([
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'stock_added' => 0,
    'stock_removed' => 0,
    'stock_sold' => 0,
    'stock_returned' => 0,
    'sales_value' => 0,
    'cost_of_stock_sold' => 0,
    'gross_profit' => 0,
    'gross_profit_margin' => 0,
    'remaining_stock_units' => 0,
    'remaining_stock_value' => 0,
    'actual_collected' => 0,
    'return_refund_value' => 0,
    'net_collected' => 0,
    'expected_vs_actual' => 0,
    'products' => [],
    'adjustments' => []
], $historical);

// If summary totals are missing, safely rebuild them from product rows.
if (is_array($stats['products'])) {
    foreach ($stats['products'] as $p) {
        $stats['stock_added'] += (float)($p['stock_added'] ?? 0);
        $stats['stock_removed'] += (float)($p['stock_removed'] ?? 0);
        $stats['stock_sold'] += (float)($p['stock_sold'] ?? 0);
        $stats['stock_returned'] += (float)($p['stock_returned'] ?? 0);
        $stats['sales_value'] += (float)($p['sales_value'] ?? 0);
        $stats['cost_of_stock_sold'] += (float)($p['cost_of_stock_sold'] ?? 0);
        $stats['remaining_stock_units'] += (float)($p['current_stock'] ?? 0);
        $stats['remaining_stock_value'] += (float)($p['current_stock'] ?? 0) * (float)($p['buying_price'] ?? 0);
        $stats['return_refund_value'] += (float)($p['return_refund_value'] ?? 0);
    }
}

// Recalculate derived totals only when the returned value is absent/zero.
if ((float)$stats['gross_profit'] == 0.0 && (float)$stats['sales_value'] != 0.0) {
    $stats['gross_profit'] = (float)$stats['sales_value'] - (float)$stats['cost_of_stock_sold'];
}
if ((float)$stats['gross_profit_margin'] == 0.0 && (float)$stats['sales_value'] > 0) {
    $stats['gross_profit_margin'] = ((float)$stats['gross_profit'] / (float)$stats['sales_value']) * 100;
}
if ((float)$stats['net_collected'] == 0.0 && (float)$stats['actual_collected'] != 0.0) {
    $stats['net_collected'] = (float)$stats['actual_collected'] - (float)$stats['return_refund_value'];
}
if ((float)$stats['expected_vs_actual'] == 0.0 && (float)$stats['sales_value'] != 0.0) {
    $stats['expected_vs_actual'] = (float)$stats['net_collected'] - ((float)$stats['sales_value'] - (float)$stats['return_refund_value']);
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return number_format((float) $value, 2);
}

function qty($value) {
    return number_format((float) $value, 2);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Reconciliation | BizFlow</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0}
.wrap{max-width:1400px;margin:0 auto;padding:24px}
h1{margin:0 0 6px}
.muted{color:#6b7280}
.filters,.cards,.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
.filters{padding:16px;margin:20px 0}
.filters form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
label{font-size:13px;font-weight:600;display:block;margin-bottom:6px}
input,select,button{height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 11px;box-sizing:border-box}
button{background:#111827;color:#fff;border-color:#111827;cursor:pointer;font-weight:600}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1px;overflow:hidden;margin-bottom:20px}
.card{padding:18px;background:#fff}
.card .label{font-size:12px;color:#6b7280;margin-bottom:7px}
.card .value{font-size:22px;font-weight:700}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.panel{padding:18px;overflow:auto}
.panel h2{margin:0 0 15px;font-size:18px}
table{width:100%;border-collapse:collapse;min-width:760px}
th,td{padding:10px 8px;border-bottom:1px solid #eef0f3;text-align:left;font-size:13px}
th{background:#f9fafb}
.right{text-align:right}
.note{padding:12px;background:#fff8e1;border:1px solid #f3df9b;border-radius:8px;margin-bottom:20px;font-size:13px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <h1>Inventory Reconciliation</h1>
    <div class="muted">Historical stock, sales, collections and adjustment statistics.</div>

    <div class="filters">
        <form method="get">
            <div>
                <label for="from">From</label>
                <input id="from" type="date" name="from" value="<?= h($stats['from_date']) ?>">
            </div>
            <div>
                <label for="to">To</label>
                <input id="to" type="date" name="to" value="<?= h($stats['to_date']) ?>">
            </div>
            <div>
                <label for="product_id">Product ID (optional)</label>
                <input id="product_id" type="number" min="1" name="product_id" value="<?= h($productId) ?>">
            </div>
            <button type="submit">Run Reconciliation</button>
        </form>
    </div>

    <div class="note">
        <strong>Collection allocation:</strong> payments are recorded at business level in the current schema,
        not against individual products. Product-level “actual collected” values are therefore allocated
        proportionally by sales value; the business-level total is the authoritative collection figure.
    </div>

    <div class="cards">
        <div class="card"><div class="label">Stock Added</div><div class="value"><?= qty($stats['stock_added']) ?></div></div>
        <div class="card"><div class="label">Stock Removed</div><div class="value"><?= qty($stats['stock_removed']) ?></div></div>
        <div class="card"><div class="label">Stock Sold</div><div class="value"><?= qty($stats['stock_sold']) ?></div></div>
        <div class="card"><div class="label">Stock Returned</div><div class="value"><?= qty($stats['stock_returned']) ?></div></div>
        <div class="card"><div class="label">Sales Value</div><div class="value"><?= money($stats['sales_value']) ?></div></div>
        <div class="card"><div class="label">Cost of Stock Sold</div><div class="value"><?= money($stats['cost_of_stock_sold']) ?></div></div>
        <div class="card"><div class="label">Gross Profit</div><div class="value"><?= money($stats['gross_profit']) ?></div></div>
        <div class="card"><div class="label">Gross Margin</div><div class="value"><?= money($stats['gross_profit_margin']) ?>%</div></div>
        <div class="card"><div class="label">Actual Collected</div><div class="value"><?= money($stats['actual_collected']) ?></div></div>
        <div class="card"><div class="label">Refunds</div><div class="value"><?= money($stats['return_refund_value']) ?></div></div>
        <div class="card"><div class="label">Net Collected</div><div class="value"><?= money($stats['net_collected']) ?></div></div>
        <div class="card"><div class="label">Expected vs Actual</div><div class="value"><?= money($stats['expected_vs_actual']) ?></div></div>
    </div>

    <div class="grid">
        <section class="panel">
            <h2>Product Reconciliation</h2>
            <table>
                <thead>
                <tr>
                    <th>Product</th>
                    <th class="right">Current Stock</th>
                    <th class="right">Added</th>
                    <th class="right">Removed</th>
                    <th class="right">Sold</th>
                    <th class="right">Returned</th>
                    <th class="right">Sales</th>
                    <th class="right">Cost</th>
                    <th class="right">Profit</th>
                    <th class="right">Allocated Collected</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($stats['products'])): ?>
                    <tr><td colspan="10">No products found for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($stats['products'] as $p): ?>
                        <tr>
                            <td><?= h($p['product_name']) ?></td>
                            <td class="right"><?= qty($p['current_stock'] ?? 0) ?></td>
                            <td class="right"><?= qty($p['stock_added'] ?? 0) ?></td>
                            <td class="right"><?= qty($p['stock_removed'] ?? 0) ?></td>
                            <td class="right"><?= qty($p['stock_sold'] ?? 0) ?></td>
                            <td class="right"><?= qty($p['stock_returned'] ?? 0) ?></td>
                            <td class="right"><?= money($p['sales_value'] ?? 0) ?></td>
                            <td class="right"><?= money($p['cost_of_stock_sold'] ?? 0) ?></td>
                            <td class="right"><?= money($p['gross_profit'] ?? 0) ?></td>
                            <td class="right"><?= money($p['actual_collected'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Stock Adjustment History</h2>
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Movement</th>
                    <th class="right">Qty</th>
                    <th>Reference</th>
                    <th>Notes</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($stats['adjustments'])): ?>
                    <tr><td colspan="6">No stock movements found for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($stats['adjustments'] as $a): ?>
                        <tr>
                            <td><?= h($a['created_at']) ?></td>
                            <td><?= h($a['product_name']) ?></td>
                            <td><?= h($a['movement_type']) ?></td>
                            <td class="right"><?= qty($a['quantity']) ?></td>
                            <td><?= h($a['reference_id']) ?></td>
                            <td><?= h($a['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>

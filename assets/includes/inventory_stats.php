<?php
// BIZFLOW/includes/inventory_stats.php
// Reusable inventory statistics + historical reconciliation functions.

if (!function_exists('getInventoryStats')) {

    function getInventoryStats($pdo, $business_id) {

        $stmt = $pdo->prepare("
            SELECT
                SUM(stock_quantity * buying_price) as total_buying_value,
                SUM(stock_quantity * selling_price) as total_selling_value,
                COUNT(id) as total_products,
                SUM(stock_quantity) as total_stock_units,
                SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN stock_quantity <= reorder_level AND stock_quantity > 0 THEN 1 ELSE 0 END) as low_stock
            FROM products
            WHERE business_id = :business_id
            AND is_active = 1
        ");
        $stmt->execute([':business_id' => $business_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || $result['total_buying_value'] === null) {
            return [
                'total_buying_value' => 0,
                'total_selling_value' => 0,
                'potential_gross_profit' => 0,
                'gross_profit_margin' => 0,
                'total_products' => 0,
                'total_stock_units' => 0,
                'out_of_stock' => 0,
                'low_stock' => 0,
                'historical' => []
            ];
        }

        $buying = (float) $result['total_buying_value'];
        $selling = (float) $result['total_selling_value'];
        $profit = $selling - $buying;
        $margin = $selling > 0 ? ($profit / $selling) * 100 : 0;

        return [
            'total_buying_value' => round($buying, 2),
            'total_selling_value' => round($selling, 2),
            'potential_gross_profit' => round($profit, 2),
            'gross_profit_margin' => round($margin, 1),
            'total_products' => (int) $result['total_products'],
            'total_stock_units' => round((float) $result['total_stock_units'], 2),
            'out_of_stock' => (int) $result['out_of_stock'],
            'low_stock' => (int) $result['low_stock'],
            'historical' => []
        ];
    }
}

if (!function_exists('inventoryStatsTableExists')) {
    function inventoryStatsTableExists($pdo, $table) {
        $allowed = ['stock_movements', 'sales', 'sale_items', 'returns', 'return_items', 'payments', 'users'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('inventoryStatsSafeFloat')) {
    function inventoryStatsSafeFloat($value) {
        return round((float) ($value ?? 0), 2);
    }
}

if (!function_exists('getInventoryHistoricalStats')) {

    function getInventoryHistoricalStats($pdo, $business_id, $fromDate, $toDate, $product_id = null) {

        $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fromDate) ? $fromDate : date('Y-m-01');
        $toDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $toDate) ? $toDate : date('Y-m-d');

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $params = [
            ':business_id' => $business_id,
            ':from_date' => $fromDate . ' 00:00:00',
            ':to_date' => $toDate . ' 23:59:59'
        ];

        $productWhere = '';
        if ($product_id !== null && $product_id !== '' && is_numeric($product_id)) {
            $productWhere = ' AND p.id = :product_id ';
            $params[':product_id'] = (int) $product_id;
        }

        $summary = [
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
        ];

        // Current stock baseline / valuation.
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.stock_quantity, p.buying_price, p.selling_price
            FROM products p
            WHERE p.business_id = :business_id
            AND p.is_active = 1
            {$productWhere}
            ORDER BY p.name ASC
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $productMap = [];
        foreach ($products as $p) {
            $productMap[(int) $p['id']] = [
                'product_id' => (int) $p['id'],
                'product_name' => $p['name'],
                'current_stock' => inventoryStatsSafeFloat($p['stock_quantity']),
                'buying_price' => inventoryStatsSafeFloat($p['buying_price']),
                'selling_price' => inventoryStatsSafeFloat($p['selling_price']),
                'stock_added' => 0,
                'stock_removed' => 0,
                'stock_sold' => 0,
                'stock_returned' => 0,
                'sales_value' => 0,
                'cost_of_stock_sold' => 0,
                'gross_profit' => 0,
                'actual_collected' => 0,
                'return_refund_value' => 0,
                'net_collected' => 0,
                'expected_vs_actual' => 0,
                'first_stock_added_at' => null,
                'last_stock_added_at' => null,
                'last_adjustment_at' => null
            ];
        }

        // Stock movement history.
        if (inventoryStatsTableExists($pdo, 'stock_movements') && $productMap) {
            $ids = array_keys($productMap);
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $sql = "
                SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity,
                       sm.reference_id, sm.notes, sm.created_at
                FROM stock_movements sm
                WHERE sm.business_id = ?
                  AND sm.created_at BETWEEN ? AND ?
                  AND sm.product_id IN ($ph)
                ORDER BY sm.created_at ASC, sm.id ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$business_id, $params[':from_date'], $params[':to_date']], $ids));

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int) $row['product_id'];
                if (!isset($productMap[$pid])) continue;

                $qty = abs((float) $row['quantity']);
                $type = strtolower(trim((string) $row['movement_type']));
                $notes = strtolower(trim((string) ($row['notes'] ?? '')));

                $isRemoval =
                    in_array($type, ['remove', 'removed', 'stock_removed', 'stock -', 'stock-', 'out'], true)
                    || strpos($notes, 'stock removed') !== false
                    || strpos($notes, 'stock -') !== false
                    || strpos($notes, 'removed') !== false;

                $isAddition =
                    in_array($type, ['purchase', 'opening', 'addition', 'add', 'stock_added', 'stock +', 'stock+'], true)
                    || strpos($notes, 'stock added') !== false
                    || strpos($notes, 'stock +') !== false;

                if ($isRemoval) {
                    $productMap[$pid]['stock_removed'] += $qty;
                } elseif ($isAddition || $qty > 0) {
                    $productMap[$pid]['stock_added'] += $qty;
                    if (!$productMap[$pid]['first_stock_added_at']) {
                        $productMap[$pid]['first_stock_added_at'] = $row['created_at'];
                    }
                    $productMap[$pid]['last_stock_added_at'] = $row['created_at'];
                }

                if (in_array($type, ['adjustment', 'adjust', 'correction'], true)) {
                    $productMap[$pid]['last_adjustment_at'] = $row['created_at'];
                }
            }
        }

        // Completed sales and product-level reconciliation.
        if (inventoryStatsTableExists($pdo, 'sales') && inventoryStatsTableExists($pdo, 'sale_items')) {
            $sql = "
                SELECT si.product_id,
                       SUM(si.quantity) AS qty,
                       SUM(COALESCE(si.total, si.quantity * si.unit_price)) AS sales_value,
                       SUM(si.quantity * si.buying_price) AS cost_value
                FROM sale_items si
                INNER JOIN sales s ON s.id = si.sale_id
                WHERE s.business_id = :business_id
                  AND s.sale_date BETWEEN :from_date AND :to_date
                  AND s.sale_status = 'completed'
            ";

            if ($product_id !== null && $product_id !== '' && is_numeric($product_id)) {
                $sql .= " AND si.product_id = :product_id ";
            }

            $sql .= " GROUP BY si.product_id ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int) $row['product_id'];
                if (!isset($productMap[$pid])) continue;

                $qty = inventoryStatsSafeFloat($row['qty']);
                $salesValue = inventoryStatsSafeFloat($row['sales_value']);
                $cost = inventoryStatsSafeFloat($row['cost_value']);

                $productMap[$pid]['stock_sold'] += $qty;
                $productMap[$pid]['sales_value'] += $salesValue;
                $productMap[$pid]['cost_of_stock_sold'] += $cost;
                $productMap[$pid]['gross_profit'] += $salesValue - $cost;
            }
        }

        // Returns / refunds.
        if (inventoryStatsTableExists($pdo, 'returns') && inventoryStatsTableExists($pdo, 'return_items')) {
            $sql = "
                SELECT ri.product_id,
                       SUM(ri.quantity) AS qty,
                       SUM(ri.total) AS return_value
                FROM return_items ri
                INNER JOIN returns r ON r.id = ri.return_id
                WHERE r.business_id = :business_id
                  AND r.created_at BETWEEN :from_date AND :to_date
                  AND r.status IN ('completed', 'approved', 'processed')
            ";

            if ($product_id !== null && $product_id !== '' && is_numeric($product_id)) {
                $sql .= " AND ri.product_id = :product_id ";
            }

            $sql .= " GROUP BY ri.product_id ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int) $row['product_id'];
                if (!isset($productMap[$pid])) continue;

                $productMap[$pid]['stock_returned'] += inventoryStatsSafeFloat($row['qty']);
                $productMap[$pid]['return_refund_value'] += inventoryStatsSafeFloat($row['return_value']);
            }
        }

        // Actual money retained from completed sales.
        // For cash, cash_amount represents the tender received, so change_given
        // must be removed. Bank amount is already the amount actually retained.
        // We intentionally use the sales record here rather than payments.amount
        // because payment rows can represent tender rather than retained cash.
        $actualCollected = 0.0;
        if (inventoryStatsTableExists($pdo, 'sales')) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(
                    COALESCE(cash_amount, 0)
                    - COALESCE(change_given, 0)
                    + COALESCE(bank_amount, 0)
                ), 0)
                FROM sales
                WHERE business_id = :business_id
                  AND sale_date BETWEEN :from_date AND :to_date
                  AND LOWER(COALESCE(sale_status, '')) = 'completed'
            ");
            $stmt->execute($params);
            $actualCollected = inventoryStatsSafeFloat($stmt->fetchColumn());
        }

        $refundValue = 0;
        if (inventoryStatsTableExists($pdo, 'returns')) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(refund_amount), 0)
                FROM returns
                WHERE business_id = :business_id
                  AND created_at BETWEEN :from_date AND :to_date
                  AND status IN ('completed', 'approved', 'processed')
            ");
            $stmt->execute($params);
            $refundValue = inventoryStatsSafeFloat($stmt->fetchColumn());
        }

        // Allocate business-level collections proportionally to product sales value.
        $totalProductSales = 0;
        foreach ($productMap as $p) {
            $totalProductSales += $p['sales_value'];
        }

        foreach ($productMap as &$p) {
            if ($totalProductSales > 0) {
                $p['actual_collected'] = round($actualCollected * ($p['sales_value'] / $totalProductSales), 2);
            } else {
                $p['actual_collected'] = 0;
            }

            $p['net_collected'] = round($p['actual_collected'] - $p['return_refund_value'], 2);
            $p['expected_vs_actual'] = round($p['actual_collected'] - $p['sales_value'], 2);
            $p['gross_profit'] = round($p['gross_profit'], 2);
        }
        unset($p);

        $summary['products'] = array_values($productMap);

        foreach ($summary['products'] as $p) {
            $summary['stock_added'] += $p['stock_added'];
            $summary['stock_removed'] += $p['stock_removed'];
            $summary['stock_sold'] += $p['stock_sold'];
            $summary['stock_returned'] += $p['stock_returned'];
            $summary['sales_value'] += $p['sales_value'];
            $summary['cost_of_stock_sold'] += $p['cost_of_stock_sold'];
            $summary['gross_profit'] += $p['gross_profit'];
            $summary['remaining_stock_units'] += $p['current_stock'];
            $summary['remaining_stock_value'] += $p['current_stock'] * $p['buying_price'];
            $summary['return_refund_value'] += $p['return_refund_value'];
        }

        $summary['actual_collected'] = $actualCollected;
        $summary['net_collected'] = round($actualCollected - $refundValue, 2);
        $netSalesValue = round($summary['sales_value'] - $refundValue, 2);
        $summary['expected_vs_actual'] = round($summary['net_collected'] - $netSalesValue, 2);
        $summary['gross_profit_margin'] =
            $summary['sales_value'] > 0
                ? round(($summary['gross_profit'] / $summary['sales_value']) * 100, 1)
                : 0;

        // Detailed adjustment history.
        if (inventoryStatsTableExists($pdo, 'stock_movements')) {
            $sql = "
                SELECT sm.id, sm.product_id, p.name AS product_name,
                       sm.movement_type, sm.quantity, sm.reference_id,
                       sm.notes, sm.created_at
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE sm.business_id = :business_id
                  AND sm.created_at BETWEEN :from_date AND :to_date
            ";

            if ($product_id !== null && $product_id !== '' && is_numeric($product_id)) {
                $sql .= " AND sm.product_id = :product_id ";
            }

            $sql .= " ORDER BY sm.created_at DESC, sm.id DESC ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $summary['adjustments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $summary;
    }
}

if (!function_exists('getInventoryProductAdjustmentHistory')) {

    function getInventoryProductAdjustmentHistory($pdo, $business_id, $product_id, $fromDate = null, $toDate = null) {

        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');

        if (!inventoryStatsTableExists($pdo, 'stock_movements')) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT sm.id, sm.product_id, p.name AS product_name,
                   sm.movement_type, sm.quantity, sm.reference_id,
                   sm.notes, sm.created_at
            FROM stock_movements sm
            INNER JOIN products p ON p.id = sm.product_id
            WHERE sm.business_id = :business_id
              AND sm.product_id = :product_id
              AND sm.created_at BETWEEN :from_date AND :to_date
            ORDER BY sm.created_at DESC, sm.id DESC
        ");

        $stmt->execute([
            ':business_id' => $business_id,
            ':product_id' => $product_id,
            ':from_date' => $fromDate . ' 00:00:00',
            ':to_date' => $toDate . ' 23:59:59'
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

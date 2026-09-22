<?php

declare(strict_types=1);

/**
 * BizFlow Cloud -> Local Synchronization Worker
 *
 * Run:
 * C:\wamp64\bin\php\php8.3.14\php.exe sync_reverse_worker.php
 *
 * Responsibilities:
 * - Authenticate the local device with the cloud.
 * - Pull cloud-originated events.
 * - Apply product changes.
 * - Apply stock delta changes.
 * - Apply reconciliation changes.
 * - Record processed events in sync_inbox.
 * - Remain idempotent when the same cloud event is delivered again.
 * - Acknowledge events only after the local transaction succeeds.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| LOAD LOCAL CONFIGURATION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sync.php';


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(
        STDERR,
        "ERROR: Local database connection is unavailable.\n"
    );

    exit(1);
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


/*
|--------------------------------------------------------------------------
| CONFIGURATION HELPER
|--------------------------------------------------------------------------
*/

function reverseWorkerConfig(
    string $name,
    $default = null
) {
    return defined($name)
        ? constant($name)
        : $default;
}


/*
|--------------------------------------------------------------------------
| DEVICE / BUSINESS CONFIGURATION
|--------------------------------------------------------------------------
*/

$deviceId = (int) reverseWorkerConfig(
    'BIZFLOW_SYNC_DEVICE_ID',
    0
);

$businessId = (int) reverseWorkerConfig(
    'BIZFLOW_SYNC_BUSINESS_ID',
    0
);

$pullUrl = (string) reverseWorkerConfig(
    'BIZFLOW_SYNC_PULL_URL',
    'https://bizflow.tfgi.co.ke/BIZFLOW/api/sync/pull.php'
);

$ackUrl = (string) reverseWorkerConfig(
    'BIZFLOW_SYNC_ACK_URL',
    'https://bizflow.tfgi.co.ke/BIZFLOW/api/sync/ack.php'
);

$secret = (string) reverseWorkerConfig(
    'BIZFLOW_SYNC_SECRET',
    ''
);


/*
|--------------------------------------------------------------------------
| VALIDATE DEVICE
|--------------------------------------------------------------------------
*/

if ($deviceId <= 0) {

    fwrite(
        STDERR,
        "ERROR: BIZFLOW_SYNC_DEVICE_ID is not configured in config/sync.php.\n"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| VALIDATE SECRET
|--------------------------------------------------------------------------
*/

if ($secret === '') {

    fwrite(
        STDERR,
        "ERROR: BIZFLOW_SYNC_SECRET is not configured in config/sync.php.\n"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| RESOLVE BUSINESS ID
|--------------------------------------------------------------------------
|
| If BIZFLOW_SYNC_BUSINESS_ID is not explicitly configured, resolve
| the business through the local sync_devices table.
|
*/

if ($businessId <= 0) {

    try {

        $stmt = $pdo->prepare("
            SELECT business_id
            FROM sync_devices
            WHERE id = :device_id
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([
            ':device_id' => $deviceId
        ]);

        $businessId = (int) $stmt->fetchColumn();

    } catch (Throwable $e) {

        fwrite(
            STDERR,
            "ERROR: Unable to resolve local business.\n"
            . "Reason: "
            . $e->getMessage()
            . "\n"
        );

        exit(1);
    }
}


if ($businessId <= 0) {

    fwrite(
        STDERR,
        "ERROR: Unable to resolve the local business for sync device "
        . $deviceId
        . ".\n"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| CURL JSON REQUEST
|--------------------------------------------------------------------------
*/

function reverseCurlJson(
    string $url,
    string $secret,
    array $payload
): array {

    $ch = curl_init($url);

    if ($ch === false) {

        throw new RuntimeException(
            'Unable to initialize cURL.'
        );
    }


    $body = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );


    if ($body === false) {

        curl_close($ch);

        throw new RuntimeException(
            'Unable to encode reverse synchronization request: '
            . json_last_error_msg()
        );
    }


    curl_setopt_array(
        $ch,
        [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $body,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-BIZFLOW-SECRET: ' . $secret
            ],

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_TIMEOUT => 45,

            /*
             * Temporary settings matching the existing BizFlow
             * Local -> Cloud worker.
             *
             * Re-enable certificate verification after confirming
             * the production certificate chain.
             */

            CURLOPT_SSL_VERIFYPEER => false,

            CURLOPT_SSL_VERIFYHOST => 0
        ]
    );


    $raw = curl_exec($ch);

    $errno = curl_errno($ch);

    $error = curl_error($ch);

    $http = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );


    curl_close($ch);


    if ($raw === false || $errno !== 0) {

        throw new RuntimeException(
            'Reverse synchronization network error: '
            . (
                $error !== ''
                    ? $error
                    : 'Unknown cURL error.'
            )
        );
    }


    $decoded = json_decode(
        (string) $raw,
        true
    );


    if (!is_array($decoded)) {

        throw new RuntimeException(
            'Reverse synchronization returned invalid JSON.'
            . ' HTTP status: '
            . $http
            . '. Response: '
            . trim((string) $raw)
        );
    }


    if (
        $http < 200 ||
        $http >= 300 ||
        !($decoded['success'] ?? false)
    ) {

        throw new RuntimeException(
            (string) (
                $decoded['message']
                ?? 'Reverse synchronization request failed.'
            )
        );
    }


    return $decoded;
}


/*
|--------------------------------------------------------------------------
| CHECK LOCAL TABLE
|--------------------------------------------------------------------------
*/

function localTableExists(
    PDO $pdo,
    string $table
): bool {

    static $cache = [];


    if (isset($cache[$table])) {

        return $cache[$table];
    }


    if (
        !preg_match(
            '/^[A-Za-z0-9_]+$/',
            $table
        )
    ) {

        return false;
    }


    $stmt = $pdo->query(
        "SHOW TABLES LIKE " . $pdo->quote($table)
    );


    $cache[$table] =
        (bool) $stmt->fetchColumn();


    return $cache[$table];
}


/*
|--------------------------------------------------------------------------
| RESOLVE LOCAL ACTOR
|--------------------------------------------------------------------------
*/

function resolveLocalActorId(
    PDO $pdo,
    int $businessId,
    int $preferred
): int {

    if (
        $preferred > 0 &&
        localTableExists($pdo, 'users')
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = :id
              AND business_id = :business_id
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $preferred,
            ':business_id' => $businessId
        ]);


        if ($stmt->fetchColumn()) {

            return $preferred;
        }
    }


    if (localTableExists($pdo, 'users')) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE business_id = :business_id
              AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");

        $stmt->execute([
            ':business_id' => $businessId
        ]);


        $id = $stmt->fetchColumn();


        if ($id !== false) {

            return (int) $id;
        }
    }


    return 0;
}


/*
|--------------------------------------------------------------------------
| PRODUCT CREATE / UPDATE
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Product updates NEVER overwrite stock_quantity.
|
| Stock changes must arrive as separate stock events.
|
*/

function productCreateOrUpdate(
    PDO $pdo,
    int $businessId,
    int $actionEntityId,
    string $action,
    array $payload
): void {

    $product =
        $payload['product']
        ?? $payload;


    if (!is_array($product)) {

        throw new InvalidArgumentException(
            'Invalid product payload.'
        );
    }


    $productId = (int) (
        $product['id']
        ?? $actionEntityId
    );


    if ($productId <= 0) {

        throw new InvalidArgumentException(
            'Product ID is required.'
        );
    }


    $name = trim(
        (string) (
            $product['name']
            ?? ''
        )
    );


    if ($name === '') {

        throw new InvalidArgumentException(
            'Product name is required.'
        );
    }


    $sku =
        isset($product['sku'])
            ? trim((string) $product['sku'])
            : null;


    $barcode =
        isset($product['barcode'])
            ? trim((string) $product['barcode'])
            : null;


    $buying =
        (float) (
            $product['buying_price']
            ?? 0
        );


    $selling =
        (float) (
            $product['selling_price']
            ?? 0
        );


    $wholesale =
        (float) (
            $product['wholesale_price']
            ?? 0
        );


    $wholesaleMin =
        (float) (
            $product['wholesale_min_qty']
            ?? 0
        );


    $reorder =
        (float) (
            $product['reorder_level']
            ?? 0
        );


    $unit =
        trim(
            (string) (
                $product['unit']
                ?? ''
            )
        );


    $categoryId =
        isset($product['category_id'])
        && (int) $product['category_id'] > 0
            ? (int) $product['category_id']
            : null;


    $active =
        isset($product['is_active'])
            ? (int) (bool) $product['is_active']
            : 1;


    $check = $pdo->prepare("
        SELECT id
        FROM products
        WHERE id = :id
          AND business_id = :business_id
        LIMIT 1
    ");


    $check->execute([
        ':id' => $productId,
        ':business_id' => $businessId
    ]);


    $exists =
        $check->fetchColumn() !== false;


    /*
     * CREATE
     */

    if (!$exists) {

        $stmt = $pdo->prepare("
            INSERT INTO products (
                id,
                business_id,
                name,
                sku,
                barcode,
                buying_price,
                selling_price,
                wholesale_price,
                wholesale_min_qty,
                stock_quantity,
                reorder_level,
                unit,
                category_id,
                is_active
            )
            VALUES (
                :id,
                :business_id,
                :name,
                :sku,
                :barcode,
                :buying_price,
                :selling_price,
                :wholesale_price,
                :wholesale_min_qty,
                0,
                :reorder_level,
                :unit,
                :category_id,
                :is_active
            )
        ");


        $stmt->execute([

            ':id' =>
                $productId,

            ':business_id' =>
                $businessId,

            ':name' =>
                $name,

            ':sku' =>
                $sku !== ''
                    ? $sku
                    : null,

            ':barcode' =>
                $barcode !== ''
                    ? $barcode
                    : null,

            ':buying_price' =>
                $buying,

            ':selling_price' =>
                $selling,

            ':wholesale_price' =>
                $wholesale,

            ':wholesale_min_qty' =>
                $wholesaleMin,

            ':reorder_level' =>
                $reorder,

            ':unit' =>
                $unit !== ''
                    ? $unit
                    : null,

            ':category_id' =>
                $categoryId,

            ':is_active' =>
                $active
        ]);


        return;
    }


    /*
     * UPDATE
     *
     * stock_quantity intentionally excluded.
     */

    $stmt = $pdo->prepare("
        UPDATE products
        SET
            name = :name,
            sku = :sku,
            barcode = :barcode,
            buying_price = :buying_price,
            selling_price = :selling_price,
            wholesale_price = :wholesale_price,
            wholesale_min_qty = :wholesale_min_qty,
            reorder_level = :reorder_level,
            unit = :unit,
            category_id = :category_id,
            is_active = :is_active
        WHERE id = :id
          AND business_id = :business_id
    ");


    $stmt->execute([

        ':id' =>
            $productId,

        ':business_id' =>
            $businessId,

        ':name' =>
            $name,

        ':sku' =>
            $sku !== ''
                ? $sku
                : null,

        ':barcode' =>
            $barcode !== ''
                ? $barcode
                : null,

        ':buying_price' =>
            $buying,

        ':selling_price' =>
            $selling,

        ':wholesale_price' =>
            $wholesale,

        ':wholesale_min_qty' =>
            $wholesaleMin,

        ':reorder_level' =>
            $reorder,

        ':unit' =>
            $unit !== ''
                ? $unit
                : null,

        ':category_id' =>
            $categoryId,

        ':is_active' =>
            $active
    ]);
}


/*
|--------------------------------------------------------------------------
| APPLY STOCK EVENT
|--------------------------------------------------------------------------
|
| Stock is changed using a DELTA.
|
| Examples:
|
| add     +10
| remove  -5
| adjust  +3 / -3
|
| We NEVER replace stock_quantity with a cloud snapshot.
|
*/

function applyStockEvent(
    PDO $pdo,
    int $businessId,
    int $entityId,
    string $action,
    array $payload
): void {

    $stock =
        $payload['stock']
        ?? $payload;


    if (!is_array($stock)) {

        throw new InvalidArgumentException(
            'Invalid stock payload.'
        );
    }


    $productId = (int) (
        $stock['product_id']
        ?? $entityId
    );


    $delta =
        (float) (
            $stock['delta']
            ?? 0
        );


    if ($productId <= 0) {

        throw new InvalidArgumentException(
            'Stock product ID is required.'
        );
    }


    /*
     * Normalize the delta according to the action.
     */

    if ($action === 'remove' && $delta > 0) {

        $delta = -$delta;
    }


    if ($action === 'add' && $delta < 0) {

        $delta = abs($delta);
    }


    if ($action === 'adjust') {

        /*
         * Adjustment payloads should already contain a signed delta.
         * No snapshot replacement occurs.
         */
    }


    $check = $pdo->prepare("
        SELECT
            id,
            stock_quantity,
            buying_price,
            selling_price
        FROM products
        WHERE id = :product_id
          AND business_id = :business_id
        LIMIT 1
        FOR UPDATE
    ");


    $check->execute([

        ':product_id' =>
            $productId,

        ':business_id' =>
            $businessId
    ]);


    $product =
        $check->fetch(PDO::FETCH_ASSOC);


    if (!$product) {

        throw new RuntimeException(
            'Product '
            . $productId
            . ' does not exist locally for business '
            . $businessId
            . '.'
        );
    }


    $oldStock =
        (float) $product['stock_quantity'];


    $newStock =
        $oldStock + $delta;


    /*
     * Never allow a remote event to create negative inventory.
     *
     * If your production inventory rules later permit negative stock,
     * this validation can be changed centrally.
     */

    if ($newStock < 0) {

        throw new RuntimeException(
            'Stock operation would make product '
            . $productId
            . ' negative. Current stock: '
            . $oldStock
            . ', delta: '
            . $delta
        );
    }


    $update = $pdo->prepare("
        UPDATE products
        SET stock_quantity = :stock_quantity
        WHERE id = :product_id
          AND business_id = :business_id
    ");


    $update->execute([

        ':stock_quantity' =>
            $newStock,

        ':product_id' =>
            $productId,

        ':business_id' =>
            $businessId
    ]);


    /*
     * Record stock movement when the local table exists.
     */

    if (localTableExists($pdo, 'stock_movements')) {

        $userId =
            (int) (
                $stock['user_id']
                ?? $stock['created_by']
                ?? 0
            );


        $reason =
            trim(
                (string) (
                    $stock['reason']
                    ?? $stock['notes']
                    ?? 'Cloud stock synchronization'
                )
            );


        $reference =
            trim(
                (string) (
                    $stock['reference']
                    ?? ''
                )
            );


        /*
         * We deliberately use a conservative insert strategy.
         *
         * If your existing stock_movements schema differs, the worker
         * will report the exact database error instead of silently
         * corrupting stock.
         */

        try {

            $columns = [
                'business_id',
                'product_id',
                'quantity',
                'type',
                'reason',
                'reference'
            ];


            $values = [
                ':business_id',
                ':product_id',
                ':quantity',
                ':type',
                ':reason',
                ':reference'
            ];


            $params = [

                ':business_id' =>
                    $businessId,

                ':product_id' =>
                    $productId,

                ':quantity' =>
                    $delta,

                ':type' =>
                    $action,

                ':reason' =>
                    $reason !== ''
                        ? $reason
                        : null,

                ':reference' =>
                    $reference !== ''
                        ? $reference
                        : null
            ];


            $insertMovement = $pdo->prepare("
                INSERT INTO stock_movements (
                    "
                    . implode(', ', $columns)
                    . "
                )
                VALUES (
                    "
                    . implode(', ', $values)
                    . "
                )
            ");


            $insertMovement->execute(
                $params
            );

        } catch (Throwable $e) {

            /*
             * Do not silently swallow schema problems.
             */

            throw new RuntimeException(
                'Stock was not fully recorded because '
                . 'stock_movements could not be updated: '
                . $e->getMessage()
            );
        }
    }


    /*
     * Record inventory activity when the table exists.
     */

    if (
        localTableExists(
            $pdo,
            'inventory_activity_log'
        )
    ) {

        $userId =
            (int) (
                $stock['user_id']
                ?? $stock['created_by']
                ?? 0
            );


        $reason =
            trim(
                (string) (
                    $stock['reason']
                    ?? $stock['notes']
                    ?? 'Cloud stock synchronization'
                )
            );


        try {

            $stmt = $pdo->prepare("
                INSERT INTO inventory_activity_log (
                    business_id,
                    product_id,
                    user_id,
                    action,
                    quantity_change,
                    previous_stock,
                    new_stock,
                    reason,
                    created_at
                )
                VALUES (
                    :business_id,
                    :product_id,
                    :user_id,
                    :action,
                    :quantity_change,
                    :previous_stock,
                    :new_stock,
                    :reason,
                    NOW()
                )
            ");


            $stmt->execute([

                ':business_id' =>
                    $businessId,

                ':product_id' =>
                    $productId,

                ':user_id' =>
                    $userId > 0
                        ? $userId
                        : null,

                ':action' =>
                    $action,

                ':quantity_change' =>
                    $delta,

                ':previous_stock' =>
                    $oldStock,

                ':new_stock' =>
                    $newStock,

                ':reason' =>
                    $reason !== ''
                        ? $reason
                        : null
            ]);

        } catch (Throwable $e) {

            throw new RuntimeException(
                'Stock was not fully recorded because '
                . 'inventory_activity_log could not be updated: '
                . $e->getMessage()
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| APPLY RECONCILIATION EVENT
|--------------------------------------------------------------------------
|
| Supported:
|
| daily
| monthly
| quarterly
|
*/

function applyReconciliationEvent(
    PDO $pdo,
    int $businessId,
    int $entityId,
    array $payload
): void {

    $reconciliation =
        $payload['reconciliation']
        ?? $payload;


    if (!is_array($reconciliation)) {

        throw new InvalidArgumentException(
            'Invalid reconciliation payload.'
        );
    }


    $periodType =
        strtolower(
            trim(
                (string) (
                    $reconciliation['period_type']
                    ?? $reconciliation['type']
                    ?? ''
                )
            )
        );


    if (
        !in_array(
            $periodType,
            [
                'daily',
                'monthly',
                'quarterly'
            ],
            true
        )
    ) {

        throw new InvalidArgumentException(
            'Unsupported reconciliation period type.'
        );
    }


    $cashierId =
        (int) (
            $reconciliation['cashier_id']
            ?? $reconciliation['user_id']
            ?? 0
        );


    $actor =
        resolveLocalActorId(
            $pdo,
            $businessId,
            (int) (
                $reconciliation['approved_by']
                ?? $reconciliation['created_by']
                ?? $reconciliation['user_id']
                ?? 0
            )
        );


    $systemCash =
        (float) (
            $reconciliation['system_cash']
            ?? $reconciliation['expected_cash']
            ?? 0
        );


    $actualCash =
        (float) (
            $reconciliation['actual_cash']
            ?? $reconciliation['physical_cash']
            ?? $reconciliation['actual_money_collected']
            ?? 0
        );


    $difference =
        isset($reconciliation['difference'])
            ? (float) $reconciliation['difference']
            : $actualCash - $systemCash;


    $collectedCash =
        (float) (
            $reconciliation['collected_cash']
            ?? $reconciliation['cash_collected']
            ?? $actualCash
        );


    $collectedBank =
        (float) (
            $reconciliation['collected_bank']
            ?? $reconciliation['bank_collected']
            ?? 0
        );


    $status =
        trim(
            (string) (
                $reconciliation['status']
                ?? 'pending'
            )
        );


    $resolution =
        trim(
            (string) (
                $reconciliation['resolution']
                ?? ''
            )
        );


    $notes =
        trim(
            (string) (
                $reconciliation['notes']
                ?? ''
            )
        );


    /*
     * Determine period.
     */

    if ($periodType === 'daily') {

        $period =
            (string) (
                $reconciliation['reconciliation_date']
                ?? $reconciliation['date']
                ?? ''
            );

        $table =
            'daily_reconciliations';

    } elseif ($periodType === 'monthly') {

        $period =
            (string) (
                $reconciliation['reconciliation_month']
                ?? $reconciliation['month']
                ?? ''
            );

        $table =
            'monthly_reconciliations';

    } else {

        $period =
            (string) (
                $reconciliation['reconciliation_quarter']
                ?? $reconciliation['quarter']
                ?? ''
            );

        $table =
            'quarterly_reconciliations';
    }


    if ($period === '') {

        throw new InvalidArgumentException(
            'Reconciliation period is required.'
        );
    }


    if (
        $cashierId <= 0 &&
        localTableExists($pdo, 'users')
    ) {

        $cashierId =
            resolveLocalActorId(
                $pdo,
                $businessId,
                0
            );
    }


    /*
     * If the target table does not exist locally, fail clearly.
     */

    if (!localTableExists($pdo, $table)) {

        throw new RuntimeException(
            'Local reconciliation table '
            . $table
            . ' does not exist.'
        );
    }


    /*
     * DAILY
     */

    if ($periodType === 'daily') {

        $check = $pdo->prepare("
            SELECT id
            FROM daily_reconciliations
            WHERE business_id = :business_id
              AND cashier_id = :cashier_id
              AND reconciliation_date = :period
            LIMIT 1
        ");


        $check->execute([

            ':business_id' =>
                $businessId,

            ':cashier_id' =>
                $cashierId,

            ':period' =>
                $period
        ]);


        $existing =
            $check->fetchColumn();


        if ($existing !== false) {

            $stmt = $pdo->prepare("
                UPDATE daily_reconciliations
                SET
                    system_cash = :system_cash,
                    actual_cash = :actual_cash,
                    difference = :difference,
                    collected_cash = :collected_cash,
                    collected_bank = :collected_bank,
                    status = :status,
                    resolution = :resolution,
                    notes = :notes,
                    approved_by = :approved_by,
                    approved_at =
                        CASE
                            WHEN :approved_by2 > 0
                            THEN NOW()
                            ELSE approved_at
                        END
                WHERE id = :id
                  AND business_id = :business_id
            ");


            $stmt->execute([

                ':system_cash' =>
                    $systemCash,

                ':actual_cash' =>
                    $actualCash,

                ':difference' =>
                    $difference,

                ':collected_cash' =>
                    $collectedCash,

                ':collected_bank' =>
                    $collectedBank,

                ':status' =>
                    $status !== ''
                        ? $status
                        : 'pending',

                ':resolution' =>
                    $resolution !== ''
                        ? $resolution
                        : null,

                ':notes' =>
                    $notes !== ''
                        ? $notes
                        : null,

                ':approved_by' =>
                    $actor > 0
                        ? $actor
                        : null,

                ':approved_by2' =>
                    $actor,

                ':id' =>
                    (int) $existing,

                ':business_id' =>
                    $businessId
            ]);


            return;
        }


        $stmt = $pdo->prepare("
            INSERT INTO daily_reconciliations (
                business_id,
                cashier_id,
                reconciliation_date,
                system_cash,
                actual_cash,
                difference,
                collected_cash,
                collected_bank,
                status,
                resolution,
                notes,
                approved_by,
                approved_at
            )
            VALUES (
                :business_id,
                :cashier_id,
                :period,
                :system_cash,
                :actual_cash,
                :difference,
                :collected_cash,
                :collected_bank,
                :status,
                :resolution,
                :notes,
                :approved_by,
                CASE
                    WHEN :approved_by2 > 0
                    THEN NOW()
                    ELSE NULL
                END
            )
        ");


        $stmt->execute([

            ':business_id' =>
                $businessId,

            ':cashier_id' =>
                $cashierId,

            ':period' =>
                $period,

            ':system_cash' =>
                $systemCash,

            ':actual_cash' =>
                $actualCash,

            ':difference' =>
                $difference,

            ':collected_cash' =>
                $collectedCash,

            ':collected_bank' =>
                $collectedBank,

            ':status' =>
                $status !== ''
                    ? $status
                    : 'pending',

            ':resolution' =>
                $resolution !== ''
                    ? $resolution
                    : null,

            ':notes' =>
                $notes !== ''
                    ? $notes
                    : null,

            ':approved_by' =>
                $actor > 0
                    ? $actor
                    : null,

            ':approved_by2' =>
                $actor
        ]);


        return;
    }


    /*
     * MONTHLY
     */

    if ($periodType === 'monthly') {

        $check = $pdo->prepare("
            SELECT id
            FROM monthly_reconciliations
            WHERE business_id = :business_id
              AND cashier_id = :cashier_id
              AND reconciliation_month = :period
            LIMIT 1
        ");


        $check->execute([

            ':business_id' =>
                $businessId,

            ':cashier_id' =>
                $cashierId,

            ':period' =>
                $period
        ]);


        $existing =
            $check->fetchColumn();


        if ($existing !== false) {

            $stmt = $pdo->prepare("
                UPDATE monthly_reconciliations
                SET
                    system_cash = :system_cash,
                    actual_cash = :actual_cash,
                    difference = :difference,
                    collected_cash = :collected_cash,
                    collected_bank = :collected_bank,
                    status = :status,
                    resolution = :resolution,
                    notes = :notes,
                    approved_by = :approved_by,
                    approved_at =
                        CASE
                            WHEN :approved_by2 > 0
                            THEN NOW()
                            ELSE approved_at
                        END
                WHERE id = :id
                  AND business_id = :business_id
            ");


            $stmt->execute([

                ':system_cash' =>
                    $systemCash,

                ':actual_cash' =>
                    $actualCash,

                ':difference' =>
                    $difference,

                ':collected_cash' =>
                    $collectedCash,

                ':collected_bank' =>
                    $collectedBank,

                ':status' =>
                    $status !== ''
                        ? $status
                        : 'pending',

                ':resolution' =>
                    $resolution !== ''
                        ? $resolution
                        : null,

                ':notes' =>
                    $notes !== ''
                        ? $notes
                        : null,

                ':approved_by' =>
                    $actor > 0
                        ? $actor
                        : null,

                ':approved_by2' =>
                    $actor,

                ':id' =>
                    (int) $existing,

                ':business_id' =>
                    $businessId
            ]);


            return;
        }


        $stmt = $pdo->prepare("
            INSERT INTO monthly_reconciliations (
                business_id,
                cashier_id,
                reconciliation_month,
                system_cash,
                actual_cash,
                difference,
                collected_cash,
                collected_bank,
                status,
                resolution,
                notes,
                approved_by,
                approved_at
            )
            VALUES (
                :business_id,
                :cashier_id,
                :period,
                :system_cash,
                :actual_cash,
                :difference,
                :collected_cash,
                :collected_bank,
                :status,
                :resolution,
                :notes,
                :approved_by,
                CASE
                    WHEN :approved_by2 > 0
                    THEN NOW()
                    ELSE NULL
                END
            )
        ");


        $stmt->execute([

            ':business_id' =>
                $businessId,

            ':cashier_id' =>
                $cashierId,

            ':period' =>
                $period,

            ':system_cash' =>
                $systemCash,

            ':actual_cash' =>
                $actualCash,

            ':difference' =>
                $difference,

            ':collected_cash' =>
                $collectedCash,

            ':collected_bank' =>
                $collectedBank,

            ':status' =>
                $status !== ''
                    ? $status
                    : 'pending',

            ':resolution' =>
                $resolution !== ''
                    ? $resolution
                    : null,

            ':notes' =>
                $notes !== ''
                    ? $notes
                    : null,

            ':approved_by' =>
                $actor > 0
                    ? $actor
                    : null,

            ':approved_by2' =>
                $actor
        ]);


        return;
    }


    /*
     * QUARTERLY
     */

    $check = $pdo->prepare("
        SELECT id
        FROM quarterly_reconciliations
        WHERE business_id = :business_id
          AND cashier_id = :cashier_id
          AND reconciliation_quarter = :period
        LIMIT 1
    ");


    $check->execute([

        ':business_id' =>
            $businessId,

        ':cashier_id' =>
            $cashierId,

        ':period' =>
            $period
    ]);


    $existing =
        $check->fetchColumn();


    if ($existing !== false) {

        $stmt = $pdo->prepare("
            UPDATE quarterly_reconciliations
            SET
                system_cash = :system_cash,
                actual_cash = :actual_cash,
                difference = :difference,
                collected_cash = :collected_cash,
                collected_bank = :collected_bank,
                status = :status,
                resolution = :resolution,
                notes = :notes,
                approved_by = :approved_by,
                approved_at =
                    CASE
                        WHEN :approved_by2 > 0
                        THEN NOW()
                        ELSE approved_at
                    END
            WHERE id = :id
              AND business_id = :business_id
        ");


        $stmt->execute([

            ':system_cash' =>
                $systemCash,

            ':actual_cash' =>
                $actualCash,

            ':difference' =>
                $difference,

            ':collected_cash' =>
                $collectedCash,

            ':collected_bank' =>
                $collectedBank,

            ':status' =>
                $status !== ''
                    ? $status
                    : 'pending',

            ':resolution' =>
                $resolution !== ''
                    ? $resolution
                    : null,

            ':notes' =>
                $notes !== ''
                    ? $notes
                    : null,

            ':approved_by' =>
                $actor > 0
                    ? $actor
                    : null,

            ':approved_by2' =>
                $actor,

            ':id' =>
                (int) $existing,

            ':business_id' =>
                $businessId
        ]);


        return;
    }


    $stmt = $pdo->prepare("
        INSERT INTO quarterly_reconciliations (
            business_id,
            cashier_id,
            reconciliation_quarter,
            system_cash,
            actual_cash,
            difference,
            collected_cash,
            collected_bank,
            status,
            resolution,
            notes,
            approved_by,
            approved_at
        )
        VALUES (
            :business_id,
            :cashier_id,
            :period,
            :system_cash,
            :actual_cash,
            :difference,
            :collected_cash,
            :collected_bank,
            :status,
            :resolution,
            :notes,
            :approved_by,
            CASE
                WHEN :approved_by2 > 0
                THEN NOW()
                ELSE NULL
            END
        )
    ");


    $stmt->execute([

        ':business_id' =>
            $businessId,

        ':cashier_id' =>
            $cashierId,

        ':period' =>
            $period,

        ':system_cash' =>
            $systemCash,

        ':actual_cash' =>
            $actualCash,

        ':difference' =>
            $difference,

        ':collected_cash' =>
            $collectedCash,

        ':collected_bank' =>
            $collectedBank,

        ':status' =>
            $status !== ''
                ? $status
                : 'pending',

        ':resolution' =>
            $resolution !== ''
                ? $resolution
                : null,

        ':notes' =>
            $notes !== ''
                ? $notes
                : null,

        ':approved_by' =>
            $actor > 0
                ? $actor
                : null,

        ':approved_by2' =>
            $actor
    ]);
}


/*
|--------------------------------------------------------------------------
| PROCESS ONE REVERSE EVENT
|--------------------------------------------------------------------------
*/

function processReverseEvent(
    PDO $pdo,
    int $businessId,
    int $deviceId,
    array $event
): string {

    $syncUuid =
        trim(
            (string) (
                $event['sync_uuid']
                ?? ''
            )
        );


    $entityType =
        trim(
            (string) (
                $event['entity_type']
                ?? ''
            )
        );


    $action =
        trim(
            (string) (
                $event['action']
                ?? ''
            )
        );


    $entityId =
        (int) (
            $event['entity_id']
            ?? 0
        );


    $payload =
        $event['payload']
        ?? null;


    /*
     * Validate UUID.
     */

    if (
        !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $syncUuid
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid reverse-sync UUID.'
        );
    }


    /*
     * Only the currently approved reverse-sync entities.
     */

    if (
        !in_array(
            $entityType,
            [
                'product',
                'stock',
                'reconciliation'
            ],
            true
        )
    ) {

        throw new InvalidArgumentException(
            'Unsupported reverse-sync entity: '
            . $entityType
        );
    }


    if (!is_array($payload)) {

        throw new InvalidArgumentException(
            'Reverse-sync payload must be an object.'
        );
    }


    /*
     * IDEMPOTENCY CHECK
     *
     * This happens inside the same transaction as the actual
     * business operation.
     */

    $existing = $pdo->prepare("
        SELECT
            id,
            processed_at
        FROM sync_inbox
        WHERE business_id = :business_id
          AND sync_uuid = :sync_uuid
        LIMIT 1
        FOR UPDATE
    ");


    $existing->execute([

        ':business_id' =>
            $businessId,

        ':sync_uuid' =>
            $syncUuid
    ]);


    if ($existing->fetch(PDO::FETCH_ASSOC)) {

        return 'already_processed';
    }


    /*
     * PRODUCT
     */

    if ($entityType === 'product') {

        if (
            !in_array(
                $action,
                [
                    'create',
                    'update'
                ],
                true
            )
        ) {

            throw new InvalidArgumentException(
                'Unsupported product action.'
            );
        }


        productCreateOrUpdate(
            $pdo,
            $businessId,
            $entityId,
            $action,
            $payload
        );
    }


    /*
     * STOCK
     */

    elseif ($entityType === 'stock') {

        if (
            !in_array(
                $action,
                [
                    'add',
                    'remove',
                    'adjust'
                ],
                true
            )
        ) {

            throw new InvalidArgumentException(
                'Unsupported stock action.'
            );
        }


        applyStockEvent(
            $pdo,
            $businessId,
            $entityId,
            $action,
            $payload
        );
    }


    /*
     * RECONCILIATION
     */

    else {

        if (
            !in_array(
                $action,
                [
                    'create',
                    'update'
                ],
                true
            )
        ) {

            throw new InvalidArgumentException(
                'Unsupported reconciliation action.'
            );
        }


        applyReconciliationEvent(
            $pdo,
            $businessId,
            $entityId,
            $payload
        );
    }


    /*
     * Save the event in sync_inbox.
     */

    $encoded =
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );


    $stmt = $pdo->prepare("
        INSERT INTO sync_inbox (
            business_id,
            device_id,
            sync_uuid,
            entity_type,
            entity_id,
            action,
            payload,
            processed_at
        )
        VALUES (
            :business_id,
            :device_id,
            :sync_uuid,
            :entity_type,
            :entity_id,
            :action,
            :payload,
            NOW()
        )
    ");


    $stmt->execute([

        ':business_id' =>
            $businessId,

        ':device_id' =>
            $deviceId,

        ':sync_uuid' =>
            $syncUuid,

        ':entity_type' =>
            $entityType,

        ':entity_id' =>
            $entityId,

        ':action' =>
            $action,

        ':payload' =>
            $encoded
    ]);


    return 'processed';
}


/*
|--------------------------------------------------------------------------
| MAIN WORKER
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "BizFlow Cloud -> Local Sync Worker\n";
echo "========================================\n";

echo "Device ID: "
    . $deviceId
    . "\n";

echo "Business ID: "
    . $businessId
    . "\n";

echo "Pull URL: "
    . $pullUrl
    . "\n";

echo "Starting cloud pull...\n";
echo "\n";


try {

    /*
     * Make sure sync_inbox exists.
     *
     * This is intentionally defensive so the worker can operate
     * even if the SQL migration was not run manually.
     */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_inbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id INT UNSIGNED NOT NULL,
            device_id INT UNSIGNED NOT NULL,
            sync_uuid CHAR(36) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            payload LONGTEXT NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,

            PRIMARY KEY (id),

            UNIQUE KEY uq_sync_inbox_uuid (
                business_id,
                sync_uuid
            ),

            KEY idx_sync_inbox_entity (
                business_id,
                entity_type,
                entity_id
            )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");


    /*
     * PULL EVENTS
     */

    $response =
        reverseCurlJson(
            $pullUrl,
            $secret,
            [
                'device_id' =>
                    $deviceId,

                'business_id' =>
                    $businessId,

                'limit' =>
                    50
            ]
        );


    $events =
        is_array(
            $response['events']
            ?? null
        )
            ? $response['events']
            : [];


    echo "Cloud connection successful.\n";

    echo "Events received: "
        . count($events)
        . "\n\n";


    /*
     * Counters.
     */

    $acks = [];

    $processed = 0;

    $alreadyProcessed = 0;

    $failed = 0;


    /*
     * PROCESS EVENTS
     */

    foreach ($events as $event) {

        $syncUuid =
            (string) (
                $event['sync_uuid']
                ?? ''
            );


        $outboxId =
            (int) (
                $event['outbox_id']
                ?? 0
            );


        echo "----------------------------------------\n";

        echo "Event: "
            . (
                $event['entity_type']
                ?? 'unknown'
            )
            . "/"
            . (
                $event['action']
                ?? 'unknown'
            )
            . "\n";

        echo "Sync UUID: "
            . $syncUuid
            . "\n";


        try {

            /*
             * Each cloud event gets its own local transaction.
             */

            $pdo->beginTransaction();


            $result =
                processReverseEvent(
                    $pdo,
                    $businessId,
                    $deviceId,
                    $event
                );


            /*
             * Commit ONLY after the complete event succeeds.
             */

            $pdo->commit();


            if ($result === 'already_processed') {

                $alreadyProcessed++;

                echo "Already processed locally.\n";

            } else {

                $processed++;

                echo "Applied successfully.\n";
            }


            /*
             * ACK only after commit.
             */

            $acks[] = [

                'outbox_id' =>
                    $outboxId,

                'sync_uuid' =>
                    $syncUuid,

                'status' =>
                    'processed'
            ];


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            $failed++;


            echo "FAILED: "
                . $e->getMessage()
                . "\n";


            $acks[] = [

                'outbox_id' =>
                    $outboxId,

                'sync_uuid' =>
                    $syncUuid,

                'status' =>
                    'failed',

                'error' =>
                    $e->getMessage()
            ];


            fwrite(
                STDERR,
                "[BizFlow reverse sync] "
                . $syncUuid
                . ": "
                . $e->getMessage()
                . PHP_EOL
            );
        }
    }


    /*
     * ACK PROCESSED EVENTS
     */

    if ($acks) {

        echo "\n";
        echo "Sending acknowledgements...\n";


        try {

            $ackResponse =
                reverseCurlJson(
                    $ackUrl,
                    $secret,
                    [
                        'device_id' =>
                            $deviceId,

                        'business_id' =>
                            $businessId,

                        'acks' =>
                            $acks
                    ]
                );


            echo "Acknowledgement successful.\n";


        } catch (Throwable $e) {

            /*
             * Important:
             *
             * The local transaction has already been committed.
             * sync_inbox prevents duplicate application if the same
             * event is delivered again.
             */

            fwrite(
                STDERR,
                "[BizFlow reverse sync] ACK failed: "
                . $e->getMessage()
                . PHP_EOL
            );


            echo "WARNING: Acknowledgement failed.\n";
            echo "The event will remain safely idempotent locally.\n";
        }
    }


    /*
     * FINAL RESULT
     */

    echo "\n";
    echo "========================================\n";
    echo "Synchronization complete\n";
    echo "========================================\n";

    echo "Received: "
        . count($events)
        . "\n";

    echo "Processed: "
        . $processed
        . "\n";

    echo "Already processed: "
        . $alreadyProcessed
        . "\n";

    echo "Failed: "
        . $failed
        . "\n";


    if ($failed > 0) {

        exit(2);
    }


    exit(0);


} catch (Throwable $e) {

    fwrite(
        STDERR,
        "[BizFlow reverse sync] "
        . $e->getMessage()
        . PHP_EOL
    );


    echo "\n";
    echo "========================================\n";
    echo "REVERSE SYNC FAILED\n";
    echo "========================================\n";

    echo $e->getMessage();
    echo "\n";


    exit(1);
}
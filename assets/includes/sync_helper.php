<?php

/**
 * BizFlow Synchronization Helper
 *
 * Handles:
 * - Sync device lookup
 * - Secure synchronization UUID generation
 * - Queue creation
 * - Product synchronization payloads
 * - Sale synchronization payloads
 * - Existing product bootstrap synchronization
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sync.php';


/*
|--------------------------------------------------------------------------
| SYNC DEVICE
|--------------------------------------------------------------------------
*/

function getSyncDevice(PDO $pdo): ?array
{
    $sql = "
        SELECT
            id,
            business_id,
            device_uuid,
            device_name,
            device_type,
            status,
            last_sync_at,
            last_seen_at
        FROM sync_devices
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([BIZFLOW_SYNC_DEVICE_ID]);

    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    return $device ?: null;
}


/*
|--------------------------------------------------------------------------
| UUID
|--------------------------------------------------------------------------
*/

function generateSyncUuid(): string
{
    if (function_exists('random_bytes')) {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }

    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}


/*
|--------------------------------------------------------------------------
| ADD RECORD TO SYNC QUEUE
|--------------------------------------------------------------------------
*/

function addToSyncQueue(
    PDO $pdo,
    string $entityType,
    int $entityId,
    string $action,
    array $payload
): bool {
    $device = getSyncDevice($pdo);

    if (!$device) {
        return false;
    }

    if (strtolower((string)$device['status']) !== 'active') {
        return false;
    }

    $syncUuid = generateSyncUuid();

    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($encodedPayload === false) {
        return false;
    }

    $sql = "
        INSERT INTO sync_queue (
            device_id,
            business_id,
            entity_type,
            entity_id,
            sync_uuid,
            action,
            payload,
            status
        )
        VALUES (
            :device_id,
            :business_id,
            :entity_type,
            :entity_id,
            :sync_uuid,
            :action,
            :payload,
            'pending'
        )
    ";

    $stmt = $pdo->prepare($sql);

    return $stmt->execute([
        ':device_id'   => (int)$device['id'],
        ':business_id' => (int)$device['business_id'],
        ':entity_type' => $entityType,
        ':entity_id'   => $entityId,
        ':sync_uuid'   => $syncUuid,
        ':action'       => $action,
        ':payload'      => $encodedPayload
    ]);
}


/*
|--------------------------------------------------------------------------
| PRODUCT PAYLOAD
|--------------------------------------------------------------------------
*/

function buildProductSyncPayload(array $product): array
{
    return [
        'product' => [
            'id'             => (int)($product['id'] ?? 0),
            'business_id'    => (int)($product['business_id'] ?? 0),
            'name'           => (string)($product['name'] ?? ''),
            'sku'            => (string)($product['sku'] ?? ''),
            'buying_price'   => (float)($product['buying_price'] ?? 0),
            'selling_price'  => (float)($product['selling_price'] ?? 0),
            'stock_quantity' => (float)($product['stock_quantity'] ?? 0),
            'reorder_level'  => (float)($product['reorder_level'] ?? 0),

            'category_id' => (
                isset($product['category_id'])
                && $product['category_id'] !== ''
                && $product['category_id'] !== null
            )
                ? (int)$product['category_id']
                : null,

            'is_active' => (int)($product['is_active'] ?? 1)
        ]
    ];
}


/*
|--------------------------------------------------------------------------
| COMPLETE SALE PAYLOAD
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We rebuild the sale from the actual local database.
|
| This prevents an incomplete queue payload from breaking synchronization.
|
*/

function buildSaleSyncPayload(
    PDO $pdo,
    int $businessId,
    int $saleId
): array {

    /*
    |--------------------------------------------------------------------------
    | SALE HEADER
    |--------------------------------------------------------------------------
    */

    $saleSql = "
        SELECT
            id,
            business_id,
            customer_id,
            user_id,
            receipt_number,
            subtotal,
            discount,
            tax,
            total_amount,
            cash_received,
            change_given,
            payment_method,
            cash_amount,
            bank_amount,
            sale_status,
            sale_date,
            payment_status,
            transaction_reference,
            mpesa_phone,
            paid_at,
            discount_type,
            discount_value,
            discount_amount,
            discount_reason
        FROM sales
        WHERE id = ?
          AND business_id = ?
        LIMIT 1
    ";

    $saleStmt = $pdo->prepare($saleSql);
    $saleStmt->execute([
        $saleId,
        $businessId
    ]);

    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new RuntimeException(
            "Sale {$saleId} was not found for business {$businessId}."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SALE ITEMS
    |--------------------------------------------------------------------------
    */

    $itemSql = "
        SELECT
            id,
            sale_id,
            product_id,
            quantity,
            unit_price,
            buying_price,
            discount,
            total,
            sale_type
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY id ASC
    ";

    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->execute([$saleId]);

    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException(
            "Sale {$saleId} contains no items."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PAYMENTS
    |--------------------------------------------------------------------------
    */

    $paymentSql = "
        SELECT
            id,
            business_id,
            sale_id,
            payment_method,
            phone_number,
            amount,
            reference,
            transaction_id,
            status,
            payment_date,
            updated_at,
            provider,
            bank_name,
            transaction_type
        FROM payments
        WHERE sale_id = ?
          AND business_id = ?
        ORDER BY id ASC
    ";

    $paymentStmt = $pdo->prepare($paymentSql);
    $paymentStmt->execute([
        $saleId,
        $businessId
    ]);

    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE SALE
    |--------------------------------------------------------------------------
    */

    $salePayload = [
        'id' => (int)$sale['id'],
        'business_id' => (int)$sale['business_id'],

        'customer_id' => (
            $sale['customer_id'] !== null
            ? (int)$sale['customer_id']
            : null
        ),

        'user_id' => (int)$sale['user_id'],

        'receipt_number' => (string)$sale['receipt_number'],

        'subtotal' => (float)$sale['subtotal'],
        'discount' => (float)$sale['discount'],
        'tax' => (float)$sale['tax'],
        'total_amount' => (float)$sale['total_amount'],

        'cash_received' => (float)$sale['cash_received'],
        'change_given' => (float)$sale['change_given'],

        'payment_method' => $sale['payment_method'],

        'cash_amount' => (
            $sale['cash_amount'] !== null
            ? (float)$sale['cash_amount']
            : 0
        ),

        'bank_amount' => (
            $sale['bank_amount'] !== null
            ? (float)$sale['bank_amount']
            : 0
        ),

        'sale_status' => $sale['sale_status'],
        'sale_date' => $sale['sale_date'],
        'payment_status' => $sale['payment_status'],

        'transaction_reference' =>
            $sale['transaction_reference'],

        'mpesa_phone' =>
            $sale['mpesa_phone'],

        'paid_at' =>
            $sale['paid_at'],

        'discount_type' =>
            $sale['discount_type'],

        'discount_value' => (
            $sale['discount_value'] !== null
            ? (float)$sale['discount_value']
            : 0
        ),

        'discount_amount' => (
            $sale['discount_amount'] !== null
            ? (float)$sale['discount_amount']
            : 0
        ),

        'discount_reason' =>
            $sale['discount_reason']
    ];


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE ITEMS
    |--------------------------------------------------------------------------
    */

    $salePayload['items'] = [];

    foreach ($items as $item) {

        $salePayload['items'][] = [
            'id' => (int)$item['id'],
            'sale_id' => (int)$item['sale_id'],
            'product_id' => (int)$item['product_id'],

            'quantity' =>
                (float)$item['quantity'],

            'unit_price' =>
                (float)$item['unit_price'],

            'buying_price' =>
                (float)$item['buying_price'],

            'discount' => (
                $item['discount'] !== null
                ? (float)$item['discount']
                : 0
            ),

            'total' =>
                (float)$item['total'],

            'sale_type' =>
                $item['sale_type']
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE PAYMENTS
    |--------------------------------------------------------------------------
    */

    $salePayload['payments'] = [];

    foreach ($payments as $payment) {

        $salePayload['payments'][] = [
            'id' =>
                (int)$payment['id'],

            'business_id' =>
                (int)$payment['business_id'],

            'sale_id' =>
                (int)$payment['sale_id'],

            'payment_method' =>
                $payment['payment_method'],

            'phone_number' =>
                $payment['phone_number'],

            'amount' => (
                $payment['amount'] !== null
                ? (float)$payment['amount']
                : 0
            ),

            'reference' =>
                $payment['reference'],

            'transaction_id' =>
                $payment['transaction_id'],

            'status' =>
                $payment['status'],

            'payment_date' =>
                $payment['payment_date'],

            'updated_at' =>
                $payment['updated_at'],

            'provider' =>
                $payment['provider'],

            'bank_name' =>
                $payment['bank_name'],

            'transaction_type' =>
                $payment['transaction_type']
        ];
    }


    return [
        'sale' => $salePayload
    ];
}


/*
|--------------------------------------------------------------------------
| REBUILD EXISTING SALE QUEUE PAYLOAD
|--------------------------------------------------------------------------
|
| This is important for sale #37.
|
| We keep the existing sync_uuid so cloud idempotency remains intact.
|
*/

function rebuildSaleQueuePayload(
    PDO $pdo,
    int $queueId
): bool {

    $sql = "
        SELECT
            id,
            business_id,
            entity_id,
            entity_type,
            action,
            status
        FROM sync_queue
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$queueId]);

    $queue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$queue) {
        return false;
    }

    if ($queue['entity_type'] !== 'sale') {
        return false;
    }

    if ($queue['action'] !== 'create') {
        return false;
    }

    if ($queue['status'] === 'synced') {
        return false;
    }

    $payload = buildSaleSyncPayload(
        $pdo,
        (int)$queue['business_id'],
        (int)$queue['entity_id']
    );

    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($encodedPayload === false) {
        throw new RuntimeException(
            'Unable to encode rebuilt sale payload.'
        );
    }

    $update = $pdo->prepare("
        UPDATE sync_queue
        SET
            payload = :payload,
            last_error = NULL
        WHERE id = :id
    ");

    return $update->execute([
        ':payload' => $encodedPayload,
        ':id' => $queueId
    ]);
}


/*
|--------------------------------------------------------------------------
| EXISTING PRODUCT BOOTSTRAP
|--------------------------------------------------------------------------
*/

function queueExistingProductsForSync(PDO $pdo): int
{
    $device = getSyncDevice($pdo);

    if (!$device) {
        return 0;
    }

    if (strtolower((string)$device['status']) !== 'active') {
        return 0;
    }

    $businessId = (int)$device['business_id'];

    $sql = "
        SELECT
            p.id,
            p.business_id,
            p.name,
            p.sku,
            p.buying_price,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.category_id,
            p.is_active
        FROM products p
        WHERE p.business_id = :business_id
          AND NOT EXISTS (
              SELECT 1
              FROM sync_queue sq
              WHERE sq.business_id = p.business_id
                AND sq.entity_type = 'product'
                AND sq.entity_id = p.id
          )
        ORDER BY p.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':business_id' => $businessId
    ]);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$products) {
        return 0;
    }

    $queued = 0;

    foreach ($products as $product) {

        $productId = (int)$product['id'];

        if ($productId <= 0) {
            continue;
        }

        $payload = buildProductSyncPayload($product);

        $success = addToSyncQueue(
            $pdo,
            'product',
            $productId,
            'create',
            $payload
        );

        if ($success) {
            $queued++;
        }
    }

    return $queued;
}
/**
 * Build synchronization payload for an expense.
 */
function buildExpenseSyncPayload(array $expense): array
{
    return [
        'expense' => [
            'id' => (int)($expense['id'] ?? 0),
            'business_id' => (int)($expense['business_id'] ?? 0),
            'user_id' => (int)($expense['user_id'] ?? 0),
            'category' => trim((string)($expense['category'] ?? '')),
            'description' => isset($expense['description'])
                ? trim((string)$expense['description'])
                : null,
            'amount' => (float)($expense['amount'] ?? 0),
            'expense_date' => (string)($expense['expense_date'] ?? '')
        ]
    ];
}
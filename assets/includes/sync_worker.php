<?php

declare(strict_types=1);

/**
 * BizFlow Offline → Cloud Synchronization Worker
 *
 * Responsibilities:
 * - Bootstrap existing products into the sync queue
 * - Read pending synchronization records
 * - Rebuild sale payloads from authoritative local tables
 * - Send records to the cloud synchronization API
 * - Mark successful records as synced
 * - Record failures without deleting local data
 *
 * IMPORTANT:
 * This worker is LOCAL → CLOUD only.
 *
 * CLOUD → LOCAL synchronization will be implemented separately.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sync.php';
require_once __DIR__ . '/sync_helper.php';


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(
        STDERR,
        "ERROR: Local database connection is not available.\n"
    );

    exit(1);
}

if (!defined('BIZFLOW_SYNC_API_URL') || !BIZFLOW_SYNC_API_URL) {
    fwrite(
        STDERR,
        "ERROR: BIZFLOW_SYNC_API_URL is not configured.\n"
    );

    exit(1);
}

if (!defined('BIZFLOW_SYNC_SECRET') || !BIZFLOW_SYNC_SECRET) {
    fwrite(
        STDERR,
        "ERROR: BIZFLOW_SYNC_SECRET is not configured.\n"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| HELPER: MARK QUEUE FAILURE
|--------------------------------------------------------------------------
*/

function markSyncQueueFailed(
    PDO $pdo,
    int $queueId,
    string $errorMessage
): void {

    $stmt = $pdo->prepare("
        UPDATE sync_queue
        SET
            status = 'failed',
            attempts = attempts + 1,
            last_error = :error
        WHERE id = :id
    ");

    $stmt->execute([
        ':error' => mb_substr($errorMessage, 0, 65000),
        ':id' => $queueId
    ]);
}


/*
|--------------------------------------------------------------------------
| HELPER: MARK QUEUE RETRY
|--------------------------------------------------------------------------
|
| Network failures are not permanent synchronization failures.
|
| We therefore leave the record as pending so the next worker run
| can retry it.
|
*/

function markSyncQueueRetry(
    PDO $pdo,
    int $queueId,
    string $errorMessage
): void {

    $stmt = $pdo->prepare("
        UPDATE sync_queue
        SET
            status = 'pending',
            attempts = attempts + 1,
            last_error = :error
        WHERE id = :id
    ");

    $stmt->execute([
        ':error' => mb_substr($errorMessage, 0, 65000),
        ':id' => $queueId
    ]);
}


/*
|--------------------------------------------------------------------------
| HELPER: MARK QUEUE SUCCESS
|--------------------------------------------------------------------------
*/

function markSyncQueueSynced(
    PDO $pdo,
    int $queueId
): void {

    $stmt = $pdo->prepare("
        UPDATE sync_queue
        SET
            status = 'synced',
            attempts = attempts + 1,
            last_error = NULL
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $queueId
    ]);
}


/*
|--------------------------------------------------------------------------
| STEP 1
| BOOTSTRAP EXISTING PRODUCTS
|--------------------------------------------------------------------------
*/

try {

    $queuedProducts = queueExistingProductsForSync($pdo);

    if ($queuedProducts > 0) {

        echo "Queued {$queuedProducts} product(s) for synchronization.\n";
        echo "\n";
    }

} catch (Throwable $e) {

    echo "WARNING: Unable to bootstrap product synchronization.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "\n";
}


/*
|--------------------------------------------------------------------------
| STEP 2
| CLAIM PENDING RECORDS
|--------------------------------------------------------------------------
|
| We first move records from pending → processing.
|
| This prevents two worker processes from simultaneously processing
| the same records.
|
| The current batch is intentionally small.
|
*/

$limit = 20;

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            device_id,
            business_id,
            entity_type,
            entity_id,
            sync_uuid,
            action,
            payload,
            attempts
        FROM sync_queue
        WHERE status = 'pending'
        ORDER BY
            CASE
                WHEN entity_type = 'category' THEN 1
                WHEN entity_type = 'product' THEN 2
                WHEN entity_type = 'stock' THEN 10
                WHEN entity_type = 'customer' THEN 20
                WHEN entity_type = 'supplier' THEN 21
                WHEN entity_type = 'sale' THEN 30
                WHEN entity_type = 'return' THEN 40
                WHEN entity_type = 'payment' THEN 50
                WHEN entity_type = 'purchase' THEN 60
                WHEN entity_type = 'expense' THEN 70
                ELSE 100
            END,
            id ASC
        LIMIT {$limit}
    ");

    $stmt->execute();

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "ERROR: Unable to read synchronization queue.\n"
        . "Reason: " . $e->getMessage() . "\n"
    );

    exit(1);
}


if (!$records) {

    echo "No pending synchronization records.\n";

    exit(0);
}


echo "Found " . count($records) . " pending synchronization record(s).\n";
echo "\n";


/*
|--------------------------------------------------------------------------
| STEP 3
| PROCESS EACH QUEUE RECORD
|--------------------------------------------------------------------------
*/

foreach ($records as $record) {

    $queueId = (int)$record['id'];

    $entityType = (string)$record['entity_type'];
    $action = (string)$record['action'];

    echo "Processing sync_queue ID: {$queueId}";
    echo " [{$entityType}/{$action}]";
    echo "\n";


    /*
    |--------------------------------------------------------------------------
    | CLAIM THIS RECORD
    |--------------------------------------------------------------------------
    |
    | Only change pending → processing if it is still pending.
    |
    */

    try {

        $claimStmt = $pdo->prepare("
            UPDATE sync_queue
            SET
                status = 'processing'
            WHERE id = ?
              AND status = 'pending'
        ");

        $claimStmt->execute([
            $queueId
        ]);

        if ($claimStmt->rowCount() !== 1) {

            echo "SKIPPED: Queue record is no longer pending.\n";
            echo "\n";

            continue;
        }

    } catch (Throwable $e) {

        echo "SKIPPED: Unable to claim queue record.\n";
        echo "Reason: " . $e->getMessage() . "\n";
        echo "\n";

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 4
    | REBUILD SALE PAYLOAD
    |--------------------------------------------------------------------------
    |
    | Sales are reconstructed from:
    |
    | sales
    | sale_items
    | payments
    |
    | This is especially important for sale #37, whose original queue
    | payload did not contain the items.
    |
    | The existing sync_uuid is NEVER changed.
    |
    */

    if (
        $entityType === 'sale' &&
        $action === 'create'
    ) {

        try {

            rebuildSaleQueuePayload(
                $pdo,
                $queueId
            );

            /*
             * Reload the rebuilt payload.
             */

            $refreshStmt = $pdo->prepare("
                SELECT payload
                FROM sync_queue
                WHERE id = ?
                LIMIT 1
            ");

            $refreshStmt->execute([
                $queueId
            ]);

            $freshPayload = $refreshStmt->fetchColumn();

            if ($freshPayload === false) {

                throw new RuntimeException(
                    'Unable to reload rebuilt sale payload.'
                );
            }

            $record['payload'] = $freshPayload;

            echo "Sale payload rebuilt from local database.\n";

        } catch (Throwable $e) {

            $errorMessage =
                'Unable to rebuild sale synchronization payload: '
                . $e->getMessage();

            echo "SYNC FAILED: {$errorMessage}\n";
            echo "\n";

            markSyncQueueFailed(
                $pdo,
                $queueId,
                $errorMessage
            );

            continue;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 5
    | DECODE PAYLOAD
    |--------------------------------------------------------------------------
    */

    $decodedPayload = json_decode(
        (string)$record['payload'],
        true
    );

    if (
        !is_array($decodedPayload) ||
        json_last_error() !== JSON_ERROR_NONE
    ) {

        $errorMessage =
            'Invalid JSON payload in local synchronization queue. '
            . json_last_error_msg();

        echo "SYNC FAILED: {$errorMessage}\n";
        echo "\n";

        markSyncQueueFailed(
            $pdo,
            $queueId,
            $errorMessage
        );

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 6
    | BUILD TRANSACTION PAYLOAD
    |--------------------------------------------------------------------------
    */

    $transactionPayload = [
        'sync_uuid' => (string)$record['sync_uuid'],

        'entity_type' =>
            (string)$record['entity_type'],

        'entity_id' =>
            (int)$record['entity_id'],

        'action' =>
            (string)$record['action'],

        'payload' =>
            $decodedPayload
    ];


    /*
    |--------------------------------------------------------------------------
    | STEP 7
    | BUILD CLOUD REQUEST
    |--------------------------------------------------------------------------
    */

    $requestPayload = [
        'device_id' =>
            (int)$record['device_id'],

        'business_id' =>
            (int)$record['business_id'],

        'transactions' => [
            $transactionPayload
        ]
    ];


    /*
    |--------------------------------------------------------------------------
    | STEP 8
    | ENCODE REQUEST
    |--------------------------------------------------------------------------
    */

    $encodedRequest = json_encode(
        $requestPayload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($encodedRequest === false) {

        $errorMessage =
            'Unable to encode synchronization request: '
            . json_last_error_msg();

        echo "SYNC FAILED: {$errorMessage}\n";
        echo "\n";

        markSyncQueueFailed(
            $pdo,
            $queueId,
            $errorMessage
        );

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 9
    | SEND TO CLOUD
    |--------------------------------------------------------------------------
    */

    $ch = curl_init(
        BIZFLOW_SYNC_API_URL
    );

    if ($ch === false) {

        $errorMessage =
            'Unable to initialize cURL.';

        echo "SYNC FAILED: {$errorMessage}\n";
        echo "\n";

        markSyncQueueRetry(
            $pdo,
            $queueId,
            $errorMessage
        );

        continue;
    }


    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS =>
            $encodedRequest,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-BIZFLOW-SECRET: ' .
                BIZFLOW_SYNC_SECRET
        ],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 10,

        CURLOPT_TIMEOUT => 30,

        /*
         * TEMPORARY LOCAL TEST SETTINGS
         *
         * Keep these only while testing localhost / SSL.
         *
         * Once the production HTTPS certificate is properly configured,
         * these MUST become true.
         */

        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);


    $response = curl_exec($ch);

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($ch);

    curl_close($ch);


    /*
    |--------------------------------------------------------------------------
    | STEP 10
    | HANDLE NETWORK ERROR
    |--------------------------------------------------------------------------
    */

    if ($response === false || $curlError !== '') {

        $errorMessage =
            $curlError !== ''
                ? $curlError
                : 'Unknown cURL/network error.';

        echo "CURL ERROR: {$errorMessage}\n";
        echo "\n";

        /*
         * Network failures remain pending so they can retry.
         */

        markSyncQueueRetry(
            $pdo,
            $queueId,
            $errorMessage
        );

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 11
    | DECODE SERVER RESPONSE
    |--------------------------------------------------------------------------
    */

    $serverResponse = json_decode(
        $response,
        true
    );


    /*
    |--------------------------------------------------------------------------
    | STEP 12
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    if (
        $httpCode >= 200 &&
        $httpCode < 300 &&
        is_array($serverResponse) &&
        isset($serverResponse['success']) &&
        $serverResponse['success'] === true
    ) {

        echo "SYNC SUCCESS\n";

        echo "Server response: ";
        echo $response;
        echo "\n\n";


        markSyncQueueSynced(
            $pdo,
            $queueId
        );

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 13
    | PARTIAL / SERVER FAILURE
    |--------------------------------------------------------------------------
    */

    $errorMessage = 'Synchronization failed.';

    if (is_array($serverResponse)) {

        if (
            isset($serverResponse['message']) &&
            $serverResponse['message'] !== ''
        ) {

            $errorMessage =
                (string)$serverResponse['message'];
        }

        /*
         * If the cloud gives a transaction-level error,
         * include it in the local queue error.
         */

        if (
            !empty($serverResponse['errors']) &&
            is_array($serverResponse['errors'])
        ) {

            $errorDetails = [];

            foreach (
                $serverResponse['errors']
                as $cloudError
            ) {

                if (!is_array($cloudError)) {
                    continue;
                }

                $syncUuid =
                    $cloudError['sync_uuid']
                    ?? '';

                $message =
                    $cloudError['message']
                    ?? 'Unknown synchronization error.';

                $errorDetails[] =
                    ($syncUuid !== ''
                        ? '[' . $syncUuid . '] '
                        : ''
                    )
                    . $message;
            }

            if ($errorDetails) {

                $errorMessage .=
                    ' ' .
                    implode(
                        ' | ',
                        $errorDetails
                    );
            }
        }

    } elseif ($response !== '') {

        $errorMessage =
            'HTTP ' .
            $httpCode .
            ': ' .
            trim($response);

    } else {

        $errorMessage =
            'HTTP ' .
            $httpCode .
            ': Empty server response.';
    }


    echo "SYNC FAILED: {$errorMessage}\n";
    echo "HTTP STATUS: {$httpCode}\n";

    if ($response !== '') {

        echo "SERVER RESPONSE:\n";
        echo $response;
        echo "\n";
    }


    /*
    |--------------------------------------------------------------------------
    | RETRY POLICY
    |--------------------------------------------------------------------------
    |
    | HTTP 5xx = temporary/server failure → pending
    |
    | HTTP 408 / 429 = retry later → pending
    |
    | HTTP 4xx = normally permanent payload/auth/business error → failed
    |
    */

    if (
        $httpCode >= 500 ||
        $httpCode === 408 ||
        $httpCode === 429 ||
        $httpCode === 0
    ) {

        markSyncQueueRetry(
            $pdo,
            $queueId,
            $errorMessage
        );

    } else {

        markSyncQueueFailed(
            $pdo,
            $queueId,
            $errorMessage
        );
    }


    echo "\n";
}


/*
|--------------------------------------------------------------------------
| COMPLETE
|--------------------------------------------------------------------------
*/

echo "Synchronization process complete.\n";
<?php

declare(strict_types=1);

session_start();


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';
require_once '../config/permissions.php';
require_once '../assets/includes/sync_helper.php';

requirePermission('adjust_stock');


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$business_id = (int)($_SESSION['business_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Product ID
|--------------------------------------------------------------------------
*/

$product_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;


$error   = '';
$success = '';


/*
|--------------------------------------------------------------------------
| Validate Session / Product
|--------------------------------------------------------------------------
*/

if ($business_id <= 0 || $user_id <= 0) {
    exit('Invalid session.');
}

if ($product_id <= 0) {
    header('Location: inventory.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        business_id,
        name,
        sku,
        stock_quantity,
        reorder_level,
        unit,
        is_active
    FROM products
    WHERE id = :id
      AND business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ':id'          => $product_id,
    ':business_id' => $business_id
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: inventory.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Process Adjustment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $movement_type = trim(
        (string)($_POST['movement_type'] ?? '')
    );

    $quantity_input = trim(
        (string)($_POST['quantity'] ?? '')
    );

    $notes = trim(
        (string)($_POST['notes'] ?? '')
    );


    /*
    |--------------------------------------------------------------------------
    | Validate Movement Type
    |--------------------------------------------------------------------------
    */

    if (
        $movement_type !== 'add' &&
        $movement_type !== 'remove'
    ) {

        $error = 'Invalid stock movement type.';

    } elseif (
        $quantity_input === '' ||
        !is_numeric($quantity_input)
    ) {

        $error = 'Please enter a valid quantity.';

    } else {

        $quantity = (float)$quantity_input;

        if ($quantity <= 0) {

            $error = 'Quantity must be greater than zero.';

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | BEGIN TRANSACTION
                |--------------------------------------------------------------------------
                */

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | LOCK PRODUCT
                |--------------------------------------------------------------------------
                |
                | This is important for production.
                |
                | If two terminals attempt to adjust the same product
                | simultaneously, they cannot both work from the same
                | old stock value.
                |
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        business_id,
                        name,
                        sku,
                        stock_quantity,
                        reorder_level,
                        unit,
                        is_active
                    FROM products
                    WHERE id = :id
                      AND business_id = :business_id
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    ':id'          => $product_id,
                    ':business_id' => $business_id
                ]);

                $lockedProduct = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$lockedProduct) {

                    throw new RuntimeException(
                        'Product no longer exists.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Current Stock
                |--------------------------------------------------------------------------
                */

                $current_stock = (float)$lockedProduct['stock_quantity'];


                /*
                |--------------------------------------------------------------------------
                | Calculate Delta
                |--------------------------------------------------------------------------
                */

                if ($movement_type === 'add') {

                    $quantity_change = $quantity;

                } else {

                    $quantity_change = -$quantity;
                }


                /*
                |--------------------------------------------------------------------------
                | Calculate New Stock
                |--------------------------------------------------------------------------
                */

                $new_quantity =
                    $current_stock + $quantity_change;


                /*
                |--------------------------------------------------------------------------
                | Prevent Negative Stock
                |--------------------------------------------------------------------------
                */

                if ($new_quantity < 0) {

                    throw new RuntimeException(
                        'Stock cannot become negative. ' .
                        'Current stock is ' .
                        number_format(
                            $current_stock,
                            3
                        ) .
                        ' ' .
                        $lockedProduct['unit']
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Reason
                |--------------------------------------------------------------------------
                */

                $reason =
                    $movement_type === 'add'
                        ? 'Stock Added'
                        : 'Stock Removed';


                /*
                |--------------------------------------------------------------------------
                | Stock History Notes
                |--------------------------------------------------------------------------
                */

                $history_notes = $reason;

                if ($notes !== '') {

                    $history_notes .=
                        ' | ' . $notes;
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE PRODUCT STOCK
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE products
                    SET
                        stock_quantity = :new_quantity
                    WHERE id = :id
                      AND business_id = :business_id
                ");

                $stmt->execute([
                    ':new_quantity' => $new_quantity,
                    ':id'           => $product_id,
                    ':business_id'  => $business_id
                ]);


                /*
                |--------------------------------------------------------------------------
                | STOCK MOVEMENT
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        business_id,
                        product_id,
                        user_id,
                        movement_type,
                        quantity,
                        reference_id,
                        notes,
                        created_at
                    )
                    VALUES (
                        :business_id,
                        :product_id,
                        :user_id,
                        'adjustment',
                        :quantity,
                        NULL,
                        :notes,
                        NOW()
                    )
                ");

                $stmt->execute([
                    ':business_id' => $business_id,
                    ':product_id'  => $product_id,
                    ':user_id'     => $user_id,
                    ':quantity'    => $quantity,
                    ':notes'       => $history_notes
                ]);

                $movement_id =
                    (int)$pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | INVENTORY ACTIVITY LOG
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO inventory_activity_log (
                        business_id,
                        user_id,
                        product_id,
                        product_name,
                        activity_type,
                        previous_stock,
                        new_stock,
                        quantity_change,
                        reason,
                        notes,
                        created_at
                    )
                    VALUES (
                        :business_id,
                        :user_id,
                        :product_id,
                        :product_name,
                        'stock_adjustment',
                        :previous_stock,
                        :new_stock,
                        :quantity_change,
                        :reason,
                        :notes,
                        NOW()
                    )
                ");

                $stmt->execute([
                    ':business_id'    => $business_id,
                    ':user_id'        => $user_id,
                    ':product_id'     => $product_id,
                    ':product_name'   => $lockedProduct['name'],
                    ':previous_stock' => $current_stock,
                    ':new_stock'      => $new_quantity,
                    ':quantity_change'=> $quantity_change,
                    ':reason'         => $reason,
                    ':notes'          => $notes !== ''
                        ? $notes
                        : null
                ]);

                $activity_log_id =
                    (int)$pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | CREATE UNIVERSAL SYNC PAYLOAD
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | We synchronize the STOCK DELTA.
                |
                | We do NOT tell the cloud:
                |
                |     stock = new_quantity
                |
                | We tell the cloud:
                |
                |     stock += quantity_change
                |
                | This is essential for offline synchronization.
                |
                */

                $syncPayload = [
                    'stock' => [
                        'business_id' =>
                            $business_id,

                        'product_id' =>
                            $product_id,

                        'user_id' =>
                            $user_id,

                        'movement_type' =>
                            'adjustment',

                        'direction' =>
                            $movement_type,

                        /*
                         * Positive quantity of the movement.
                         */
                        'quantity' =>
                            $quantity,

                        /*
                         * Signed stock delta.
                         *
                         * ADD    = +quantity
                         * REMOVE = -quantity
                         */
                        'quantity_change' =>
                            $quantity_change,

                        /*
                         * Audit information.
                         */
                        'previous_stock' =>
                            $current_stock,

                        'new_stock' =>
                            $new_quantity,

                        'reason' =>
                            $reason,

                        'notes' =>
                            $notes,

                        /*
                         * Local audit references.
                         *
                         * These are NOT used as cloud primary keys.
                         */
                        'stock_movement_id' =>
                            $movement_id,

                        'activity_log_id' =>
                            $activity_log_id
                    ]
                ];


                /*
                |--------------------------------------------------------------------------
                | ADD UNIVERSAL SYNC EVENT
                |--------------------------------------------------------------------------
                |
                | This uses the standard helper:
                |
                |     addToSyncQueue()
                |
                */

                $syncQueued = addToSyncQueue(
                    $pdo,
                    'stock',
                    $product_id,
                    'adjust',
                    $syncPayload
                );


                /*
                |--------------------------------------------------------------------------
                | SYNC QUEUE IS REQUIRED
                |--------------------------------------------------------------------------
                |
                | If we cannot create the sync event, rollback EVERYTHING.
                |
                | This prevents:
                |
                |     Local stock changed
                |     BUT
                |     Cloud sync event missing
                |
                */

                if (!$syncQueued) {

                    throw new RuntimeException(
                        'Unable to create stock synchronization event.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                $pdo->commit();


                /*
                |--------------------------------------------------------------------------
                | SUCCESS
                |--------------------------------------------------------------------------
                */

                $success =
                    'Stock adjusted successfully. ' .
                    'New stock: ' .
                    number_format(
                        $new_quantity,
                        3
                    ) .
                    ' ' .
                    $lockedProduct['unit'];


                /*
                |--------------------------------------------------------------------------
                | Refresh Displayed Product
                |--------------------------------------------------------------------------
                */

                $product =
                    $lockedProduct;

                $product['stock_quantity'] =
                    $new_quantity;


            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'BizFlow stock adjustment error: ' .
                    $e->getMessage()
                );

                $error =
                    $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Unable to adjust stock. Please try again.';
            }
        }
    }
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Adjust Stock - BizFlow</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

</head>

<body>

<div class="app">

<?php include "../assets/includes/sidebar.php"; ?>

<main class="main">

<?php include "../assets/includes/topbar.php"; ?>

<section class="content">

<div class="page-title">

    <div>

        <h1>Adjust Stock</h1>

        <p>
            Add or remove stock for this product.
        </p>

    </div>

    <a
        href="inventory.php"
        class="btn-secondary"
    >
        ← Back to Inventory
    </a>

</div>


<?php if ($error !== ''): ?>

<div class="alert alert-error">

    <?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<?php if ($success !== ''): ?>

<div class="alert alert-success">

    <?= htmlspecialchars($success) ?>

</div>

<?php endif; ?>


<div
    class="form-container"
    style="margin-bottom:20px;"
>

<h3>Product Information</h3>

<div
    style="
        background:#f3f4f6;
        padding:15px;
        border-radius:8px;
        margin-top:15px;
    "
>

<p>

<strong>Product:</strong>

<?= htmlspecialchars(
    (string)$product['name']
) ?>

</p>


<?php if (!empty($product['sku'])): ?>

<p>

<strong>SKU:</strong>

<?= htmlspecialchars(
    (string)$product['sku']
) ?>

</p>

<?php endif; ?>


<p>

<strong>Current Stock:</strong>

<?= number_format(
    (float)$product['stock_quantity'],
    3
) ?>

<?= htmlspecialchars(
    (string)$product['unit']
) ?>

</p>


<p>

<strong>Reorder Level:</strong>

<?= number_format(
    (float)$product['reorder_level'],
    3
) ?>

<?= htmlspecialchars(
    (string)$product['unit']
) ?>

</p>

</div>

</div>


<div class="form-container">

<form
    method="POST"
    action="?id=<?= $product_id ?>"
>

<div class="form-group">

<label for="movement_type">
Movement Type *
</label>

<select
    id="movement_type"
    name="movement_type"
    required
>

<option value="add">
Add Stock (+)
</option>

<option value="remove">
Remove Stock (-)
</option>

</select>

</div>


<div class="form-group">

<label for="quantity">
Quantity *
</label>

<input
    type="number"
    id="quantity"
    name="quantity"
    min="0.001"
    step="0.001"
    placeholder="Enter quantity"
    required
>

<small>
Enter the quantity you want
to add or remove.
</small>

</div>


<div class="form-group">

<label for="notes">
Notes
</label>

<input
    type="text"
    id="notes"
    name="notes"
    maxlength="255"
    placeholder="e.g. New stock received"
>

</div>


<div
    style="
        display:flex;
        gap:10px;
        margin-top:25px;
    "
>

<button
    type="submit"
    class="btn-primary"
>
Adjust Stock
</button>

<a
    href="inventory.php"
    class="btn-secondary"
>
Cancel
</a>

</div>

</form>

</div>

</section>

</main>

</div>

</body>

</html>
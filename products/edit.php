<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";
require_once "../assets/includes/sync_helper.php";

requirePermission("add_product");

$business_id = (int)($_SESSION["business_id"] ?? 0);
$user_id     = (int)($_SESSION["user_id"] ?? 0);
$product_id  = (int)($_GET["id"] ?? 0);

$error   = "";
$success = "";


/*
|--------------------------------------------------------------------------
| Validate
|--------------------------------------------------------------------------
*/

if ($business_id <= 0 || $user_id <= 0) {
    exit("Invalid business session.");
}

if ($product_id <= 0) {
    header("Location: view_products.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM products
    WHERE id = :id
      AND business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ":id"          => $product_id,
    ":business_id" => $business_id
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: view_products.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, name
    FROM categories
    WHERE business_id = :business_id
    ORDER BY name ASC
");

$stmt->execute([
    ":business_id" => $business_id
]);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Process Form
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");

    $category_id = $_POST["category_id"] ?? "";

    $sku     = trim($_POST["sku"] ?? "");
    $barcode = trim($_POST["barcode"] ?? "");

    $buying_price      = $_POST["buying_price"] ?? "";
    $selling_price     = $_POST["selling_price"] ?? "";
    $wholesale_price   = $_POST["wholesale_price"] ?? "";
    $wholesale_min_qty = $_POST["wholesale_min_qty"] ?? "";
    $reorder_level     = $_POST["reorder_level"] ?? "0";

    $unit = trim($_POST["unit"] ?? "pcs");

    $is_active = isset($_POST["is_active"]) ? 1 : 0;


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === "") {

        $error = "Product name is required.";

    } elseif (
        $buying_price === "" ||
        !is_numeric($buying_price)
    ) {

        $error = "Please enter a valid buying price.";

    } elseif (
        $selling_price === "" ||
        !is_numeric($selling_price)
    ) {

        $error = "Please enter a valid retail price.";

    } elseif (
        $wholesale_price !== "" &&
        !is_numeric($wholesale_price)
    ) {

        $error = "Please enter a valid wholesale price.";

    } elseif (
        $wholesale_min_qty !== "" &&
        !is_numeric($wholesale_min_qty)
    ) {

        $error = "Please enter a valid minimum wholesale quantity.";

    } elseif (
        $reorder_level === "" ||
        !is_numeric($reorder_level)
    ) {

        $error = "Please enter a valid reorder level.";

    } elseif ((float)$buying_price < 0) {

        $error = "Buying price cannot be negative.";

    } elseif ((float)$selling_price < 0) {

        $error = "Retail price cannot be negative.";

    } elseif (
        $wholesale_price !== "" &&
        (float)$wholesale_price < 0
    ) {

        $error = "Wholesale price cannot be negative.";

    } elseif (
        $wholesale_min_qty !== "" &&
        (float)$wholesale_min_qty < 0
    ) {

        $error = "Minimum wholesale quantity cannot be negative.";

    } elseif ((float)$reorder_level < 0) {

        $error = "Reorder level cannot be negative.";

    } elseif (
        (float)$selling_price <
        (float)$buying_price
    ) {

        $error = "Retail price cannot be lower than buying price.";

    } elseif ($unit === "") {

        $error = "Please enter the product unit.";
    }


    /*
    |--------------------------------------------------------------------------
    | Normalize Values
    |--------------------------------------------------------------------------
    */

    $categoryIdValue = (
        $category_id !== "" &&
        $category_id !== null
    )
        ? (int)$category_id
        : null;

    $skuValue = $sku !== ""
        ? $sku
        : null;

    $barcodeValue = $barcode !== ""
        ? $barcode
        : null;

    $buyingPriceValue = (float)$buying_price;

    $sellingPriceValue = (float)$selling_price;

    $wholesalePriceValue = (
        $wholesale_price !== ""
    )
        ? (float)$wholesale_price
        : 0.0;

    $wholesaleMinQtyValue = (
        $wholesale_min_qty !== ""
    )
        ? (float)$wholesale_min_qty
        : 0.0;

    $reorderLevelValue = (float)$reorder_level;


    /*
    |--------------------------------------------------------------------------
    | Validate Category Belongs To Business
    |--------------------------------------------------------------------------
    */

    if (
        $error === "" &&
        $categoryIdValue !== null
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE id = :id
              AND business_id = :business_id
            LIMIT 1
        ");

        $stmt->execute([
            ":id"          => $categoryIdValue,
            ":business_id" => $business_id
        ]);

        if (!$stmt->fetchColumn()) {

            $error = "Selected category is invalid.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Check Duplicate SKU
    |--------------------------------------------------------------------------
    */

    if (
        $error === "" &&
        $skuValue !== null
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE business_id = :business_id
              AND sku = :sku
              AND id != :product_id
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":sku"         => $skuValue,
            ":product_id"  => $product_id
        ]);

        if ($stmt->fetch()) {

            $error =
                "This SKU already belongs to another product.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Barcode
    |--------------------------------------------------------------------------
    */

    if (
        $error === "" &&
        $barcodeValue !== null
    ) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE business_id = :business_id
              AND barcode = :barcode
              AND id != :product_id
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":barcode"     => $barcodeValue,
            ":product_id"  => $product_id
        ]);

        if ($stmt->fetch()) {

            $error =
                "This barcode belongs to another product.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Product + Queue Sync Atomically
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Lock Product
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM products
                WHERE id = :id
                  AND business_id = :business_id
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                ":id"          => $product_id,
                ":business_id" => $business_id
            ]);

            $lockedProduct = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedProduct) {

                throw new RuntimeException(
                    "Product no longer exists."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Update MASTER PRODUCT DATA
            |
            | IMPORTANT:
            | stock_quantity is intentionally NOT updated here.
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE products
                SET
                    name = :name,
                    category_id = :category_id,
                    sku = :sku,
                    barcode = :barcode,
                    buying_price = :buying_price,
                    selling_price = :selling_price,
                    wholesale_price = :wholesale_price,
                    wholesale_min_qty = :wholesale_min_qty,
                    reorder_level = :reorder_level,
                    unit = :unit,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                  AND business_id = :business_id
            ");

            $stmt->execute([

                ":name" => $name,

                ":category_id" => $categoryIdValue,

                ":sku" => $skuValue,

                ":barcode" => $barcodeValue,

                ":buying_price" => $buyingPriceValue,

                ":selling_price" => $sellingPriceValue,

                ":wholesale_price" => $wholesalePriceValue,

                ":wholesale_min_qty" => $wholesaleMinQtyValue,

                ":reorder_level" => $reorderLevelValue,

                ":unit" => $unit,

                ":is_active" => $is_active,

                ":id" => $product_id,

                ":business_id" => $business_id
            ]);


            /*
            |--------------------------------------------------------------------------
            | Read Final Product
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM products
                WHERE id = :id
                  AND business_id = :business_id
                LIMIT 1
            ");

            $stmt->execute([
                ":id"          => $product_id,
                ":business_id" => $business_id
            ]);

            $updatedProduct =
                $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedProduct) {

                throw new RuntimeException(
                    "Unable to read updated product."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Build Product Sync Payload
            |--------------------------------------------------------------------------
            */

            $syncPayload = buildProductSyncPayload(
                $updatedProduct
            );


            /*
            |--------------------------------------------------------------------------
            | Add Product Update To Universal Sync Queue
            |--------------------------------------------------------------------------
            */

            $syncQueued = addToSyncQueue(
                $pdo,
                "product",
                $product_id,
                "update",
                $syncPayload
            );

            if (!$syncQueued) {

                throw new RuntimeException(
                    "Unable to create product synchronization event."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Refresh Display
            |--------------------------------------------------------------------------
            */

            $product = $updatedProduct;

            $success =
                "Product updated successfully and queued for synchronization.";

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                "BizFlow product update sync error: " .
                $e->getMessage()
            );

            $error =
                "Unable to update product. Please try again.";
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

    <title>Edit Product - BizFlow</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 700px) {

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .price-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

    </style>

</head>


<body>

<div class="app">


    <?php include "../assets/includes/sidebar.php"; ?>


    <main class="main">


        <?php include "../assets/includes/topbar.php"; ?>


        <section class="content">


            <div class="page-title">

                <div>

                    <h1>Edit Product</h1>

                    <p>
                        Update product information and pricing.
                    </p>

                </div>


                <div style="
                    display:flex;
                    gap:10px;
                    flex-wrap:wrap;
                ">

                    <a
                        href="../inventory/adjust_stock.php?id=<?= $product_id ?>"
                        class="btn-secondary"
                    >
                        Adjust Stock
                    </a>

                    <a
                        href="view_products.php"
                        class="btn-secondary"
                    >
                        ← Products
                    </a>

                </div>

            </div>


            <?php if ($error !== ""): ?>

                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <?php if ($success !== ""): ?>

                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>

            <?php endif; ?>


            <div class="form-container">


                <form method="POST">


                    <h3 style="margin-bottom:20px;">
                        Product Information
                    </h3>


                    <div class="form-row">


                        <div class="form-group">

                            <label for="name">
                                Product Name *
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?= htmlspecialchars(
                                    $product["name"] ?? ""
                                ) ?>"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label for="category_id">
                                Category
                            </label>

                            <select
                                id="category_id"
                                name="category_id"
                            >

                                <option value="">
                                    Uncategorized
                                </option>


                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?= (int)$category["id"] ?>"
                                        <?= (
                                            (int)$category["id"] ===
                                            (int)($product["category_id"] ?? 0)
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $category["name"]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>


                    <div class="form-row">


                        <div class="form-group">

                            <label for="sku">
                                SKU
                            </label>

                            <input
                                type="text"
                                id="sku"
                                name="sku"
                                value="<?= htmlspecialchars(
                                    $product["sku"] ?? ""
                                ) ?>"
                                placeholder="Product code"
                            >

                        </div>


                        <div class="form-group">

                            <label for="barcode">
                                Barcode
                            </label>

                            <input
                                type="text"
                                id="barcode"
                                name="barcode"
                                value="<?= htmlspecialchars(
                                    $product["barcode"] ?? ""
                                ) ?>"
                                placeholder="Barcode number"
                            >

                        </div>

                    </div>


                    <h3 style="margin:25px 0 20px;">
                        Pricing
                    </h3>


                    <div class="form-row">


                        <div class="form-group">

                            <label for="buying_price">
                                Buying Price (KSh) *
                            </label>

                            <input
                                type="number"
                                id="buying_price"
                                name="buying_price"
                                value="<?= htmlspecialchars(
                                    $product["buying_price"] ?? 0
                                ) ?>"
                                min="0"
                                step="0.01"
                                required
                            >

                            <small>
                                Use Adjust Cost when you need to record
                                a specific cost change.
                            </small>

                        </div>


                        <div class="form-group">

                            <label for="selling_price">
                                Retail Price (KSh) *
                            </label>

                            <input
                                type="number"
                                id="selling_price"
                                name="selling_price"
                                value="<?= htmlspecialchars(
                                    $product["selling_price"] ?? 0
                                ) ?>"
                                min="0"
                                step="0.01"
                                required
                            >

                            <small class="price-hint">
                                Regular price for walk-in customers.
                            </small>

                        </div>

                    </div>


                    <div class="form-row">


                        <div class="form-group">

                            <label for="wholesale_price">
                                Wholesale Price (KSh)
                            </label>

                            <input
                                type="number"
                                id="wholesale_price"
                                name="wholesale_price"
                                value="<?= htmlspecialchars(
                                    $product["wholesale_price"] ?? 0
                                ) ?>"
                                min="0"
                                step="0.01"
                            >

                            <small class="price-hint">
                                Special price for bulk buyers.
                            </small>

                        </div>


                        <div class="form-group">

                            <label for="wholesale_min_qty">
                                Minimum Wholesale Quantity
                            </label>

                            <input
                                type="number"
                                id="wholesale_min_qty"
                                name="wholesale_min_qty"
                                value="<?= htmlspecialchars(
                                    $product["wholesale_min_qty"] ?? 0
                                ) ?>"
                                min="0"
                                step="0.001"
                            >

                            <small class="price-hint">
                                Minimum quantity to qualify for wholesale price.
                            </small>

                        </div>

                    </div>


                    <h3 style="margin:25px 0 20px;">
                        Inventory Settings
                    </h3>


                    <div class="form-row">


                        <div class="form-group">

                            <label>
                                Current Stock
                            </label>

                            <input
                                type="text"
                                value="<?= htmlspecialchars(
                                    $product["stock_quantity"] ?? 0
                                ) ?>"
                                readonly
                            >

                            <small>
                                Stock is managed from Inventory,
                                not from this page.
                            </small>

                        </div>


                        <div class="form-group">

                            <label for="reorder_level">
                                Reorder Level
                            </label>

                            <input
                                type="number"
                                id="reorder_level"
                                name="reorder_level"
                                value="<?= htmlspecialchars(
                                    $product["reorder_level"] ?? 0
                                ) ?>"
                                min="0"
                                step="0.001"
                            >

                        </div>

                    </div>


                    <div class="form-group">

                        <label for="unit">
                            Unit *
                        </label>

                        <input
                            type="text"
                            id="unit"
                            name="unit"
                            value="<?= htmlspecialchars(
                                $product["unit"] ?? "pcs"
                            ) ?>"
                            placeholder="pcs, kg, litre, box..."
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>

                            <input
                                type="checkbox"
                                name="is_active"
                                <?= (
                                    (int)($product["is_active"] ?? 0) === 1
                                )
                                    ? "checked"
                                    : ""
                                ?>
                            >

                            Active and available for sale

                        </label>

                    </div>


                    <div style="
                        display:flex;
                        gap:10px;
                        margin-top:25px;
                    ">

                        <button
                            type="submit"
                            class="btn-primary"
                        >
                            Save Changes
                        </button>


                        <a
                            href="view_products.php"
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
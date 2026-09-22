<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("add_product");

require_once "../config/database.php";

$business_id = $_SESSION["business_id"];

$error = "";

/*
|--------------------------------------------------------------------------
| Default form values
|--------------------------------------------------------------------------
*/

$name = "";
$category_id = "";
$selling_method_id = "";
$unit_id = "";
$sku = "";
$barcode = "";
$buying_price = "";
$selling_price = "";
$wholesale_price = "";
$wholesale_min_qty = "";
$stock_quantity = "";
$reorder_level = "0";


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

$categories = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Get Selling Methods
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, name
    FROM selling_methods
    ORDER BY name ASC
");

$stmt->execute();

$selling_methods = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Get Units (all, for initial load)
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, selling_method_id, name
    FROM units
    ORDER BY name ASC
");

$stmt->execute();

$all_units = $stmt->fetchAll();

// Group units by selling_method_id for JavaScript
$units_by_method = [];
foreach ($all_units as $unit) {
    $method_id = $unit['selling_method_id'];
    if (!isset($units_by_method[$method_id])) {
        $units_by_method[$method_id] = [];
    }
    $units_by_method[$method_id][] = $unit;
}


/*
|--------------------------------------------------------------------------
| Process Form
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $category_id = $_POST["category_id"] ?? "";
    $selling_method_id = $_POST["selling_method_id"] ?? "";
    $unit_id = $_POST["unit_id"] ?? "";
    $sku = trim($_POST["sku"] ?? "");
    $barcode = trim($_POST["barcode"] ?? "");

    $buying_price = $_POST["buying_price"] ?? "";
    $selling_price = $_POST["selling_price"] ?? "";
    $wholesale_price = $_POST["wholesale_price"] ?? "";
    $wholesale_min_qty = $_POST["wholesale_min_qty"] ?? "";
    $stock_quantity = $_POST["stock_quantity"] ?? "";
    $reorder_level = $_POST["reorder_level"] ?? "0";

    // Get the unit name from unit_id
    $unit_name = "";
    if ($unit_id !== "") {
        $stmt = $pdo->prepare("SELECT name FROM units WHERE id = :id");
        $stmt->execute([":id" => $unit_id]);
        $unit = $stmt->fetch();
        if ($unit) {
            $unit_name = $unit['name'];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if ($name === "") {

        $error = "Product name is required.";

    } elseif ($selling_method_id === "") {

        $error = "Please select a selling method.";

    } elseif ($unit_id === "") {

        $error = "Please select a unit.";

    } elseif ($buying_price === "" || !is_numeric($buying_price)) {

        $error = "Please enter a valid buying price.";

    } elseif ($selling_price === "" || !is_numeric($selling_price)) {

        $error = "Please enter a valid retail selling price.";

    } elseif ($wholesale_price !== "" && !is_numeric($wholesale_price)) {

        $error = "Please enter a valid wholesale price.";

    } elseif ($wholesale_min_qty !== "" && !is_numeric($wholesale_min_qty)) {

        $error = "Please enter a valid minimum wholesale quantity.";

    } elseif ($stock_quantity === "" || !is_numeric($stock_quantity)) {

        $error = "Please enter a valid stock quantity.";

    } elseif (!is_numeric($reorder_level)) {

        $error = "Please enter a valid reorder level.";

    } elseif ((float)$buying_price < 0) {

        $error = "Buying price cannot be negative.";

    } elseif ((float)$selling_price < 0) {

        $error = "Retail price cannot be negative.";

    } elseif ((float)$wholesale_price < 0) {

        $error = "Wholesale price cannot be negative.";

    } elseif ((float)$wholesale_min_qty < 0) {

        $error = "Minimum wholesale quantity cannot be negative.";

    } elseif ((float)$stock_quantity < 0) {

        $error = "Stock quantity cannot be negative.";

    } elseif ((float)$reorder_level < 0) {

        $error = "Reorder level cannot be negative.";

    } elseif ((float)$selling_price < (float)$buying_price) {

        $error = "Retail price cannot be lower than buying price.";
    }


    /*
    |--------------------------------------------------------------------------
    | Check Duplicate SKU / Barcode
    |--------------------------------------------------------------------------
    */

    if ($error === "" && $sku !== "") {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE business_id = :business_id
            AND sku = :sku
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":sku" => $sku
        ]);

        if ($stmt->fetch()) {

            $error = "This SKU already exists for this business.";
        }
    }


    if ($error === "" && $barcode !== "") {

        $stmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE business_id = :business_id
            AND barcode = :barcode
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":barcode" => $barcode
        ]);

        if ($stmt->fetch()) {

            $error = "This barcode already exists for this business.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Insert Product
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO products (
                    business_id,
                    category_id,
                    selling_method_id,
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
                    is_active
                )
                VALUES (
                    :business_id,
                    :category_id,
                    :selling_method_id,
                    :name,
                    :sku,
                    :barcode,
                    :buying_price,
                    :selling_price,
                    :wholesale_price,
                    :wholesale_min_qty,
                    :stock_quantity,
                    :reorder_level,
                    :unit,
                    1
                )
            ");

            $stmt->execute([

                ":business_id" => $business_id,

                ":category_id" =>
                    $category_id !== ""
                    ? $category_id
                    : null,

                ":selling_method_id" => (int)$selling_method_id,

                ":name" => $name,

                ":sku" =>
                    $sku !== ""
                    ? $sku
                    : null,

                ":barcode" =>
                    $barcode !== ""
                    ? $barcode
                    : null,

                ":buying_price" => (float)$buying_price,

                ":selling_price" => (float)$selling_price,

                ":wholesale_price" => (float)($wholesale_price !== "" ? $wholesale_price : 0),

                ":wholesale_min_qty" => (float)($wholesale_min_qty !== "" ? $wholesale_min_qty : 0),

                ":stock_quantity" => (float)$stock_quantity,

                ":reorder_level" => (float)$reorder_level,

                ":unit" => $unit_name
            ]);


            /*
            |--------------------------------------------------------------------------
            | Redirect after successful submission
            |--------------------------------------------------------------------------
            */

            header("Location: view_products.php?added=1");
            exit;


        } catch (PDOException $e) {

            $error = "Unable to save product. Please try again.";

        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Add Product - BizFlow</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <style>
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 700px) {
            .form-row, .form-row-3 {
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


            <!-- PAGE HEADER -->

            <div class="page-title">

                <div>

                    <h1>Add Product</h1>

                    <p>
                        Add a new product to your inventory.
                    </p>

                </div>


                <a href="view_products.php"
                   class="btn-secondary">

                    ← Back to Products

                </a>

            </div>


            <!-- ERROR -->

            <?php if ($error !== ""): ?>

                <div class="alert alert-error">

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <!-- FORM -->

            <div class="form-container">


                <form method="POST" id="productForm">


                    <!-- BASIC INFORMATION -->

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
                                value="<?= htmlspecialchars($name) ?>"
                                placeholder="e.g. Sugar 1kg"
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
                                        value="<?= $category["id"] ?>"
                                        <?= ($category_id == $category["id"])
                                            ? "selected"
                                            : "" ?>
                                    >

                                        <?= htmlspecialchars($category["name"]) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                    </div>


                    <!-- SELLING METHOD & UNIT -->

                    <div class="form-row">


                        <div class="form-group">

                            <label for="selling_method_id">
                                Selling Method *
                            </label>

                            <select
                                id="selling_method_id"
                                name="selling_method_id"
                                required
                                onchange="updateUnits()"
                            >

                                <option value="">
                                    Select Selling Method
                                </option>


                                <?php foreach ($selling_methods as $method): ?>

                                    <option
                                        value="<?= $method["id"] ?>"
                                        <?= ($selling_method_id == $method["id"])
                                            ? "selected"
                                            : "" ?>
                                    >

                                        <?= htmlspecialchars($method["name"]) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="form-group">

                            <label for="unit_id">
                                Unit *
                            </label>

                            <select
                                id="unit_id"
                                name="unit_id"
                                required
                            >

                                <option value="">
                                    Select Unit
                                </option>

                            </select>

                            <small>
                                Units are linked to the selected selling method.
                            </small>

                        </div>


                    </div>


                    <!-- IDENTIFICATION -->

                    <div class="form-row">


                        <div class="form-group">

                            <label for="sku">
                                SKU
                            </label>

                            <input
                                type="text"
                                id="sku"
                                name="sku"
                                value="<?= htmlspecialchars($sku) ?>"
                                placeholder="e.g. SUG001"
                            >

                            <small>
                                Optional internal product code.
                            </small>

                        </div>


                        <div class="form-group">

                            <label for="barcode">
                                Barcode
                            </label>

                            <input
                                type="text"
                                id="barcode"
                                name="barcode"
                                value="<?= htmlspecialchars($barcode) ?>"
                                placeholder="Scan or enter barcode"
                            >

                            <small>
                                Optional. Useful for barcode scanners.
                            </small>

                        </div>


                    </div>


                    <!-- PRICING -->

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
                                value="<?= htmlspecialchars($buying_price) ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="selling_price">
                                Retail Price (KSh) *
                            </label>

                            <input
                                type="number"
                                id="selling_price"
                                name="selling_price"
                                value="<?= htmlspecialchars($selling_price) ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
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
                                value="<?= htmlspecialchars($wholesale_price) ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
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
                                value="<?= htmlspecialchars($wholesale_min_qty) ?>"
                                min="0"
                                step="0.001"
                                placeholder="0"
                            >

                            <small class="price-hint">
                                Minimum quantity to qualify for wholesale price.
                            </small>

                        </div>

                    </div>


                    <!-- STOCK -->

                    <h3 style="margin:25px 0 20px;">
                        Inventory
                    </h3>


                    <div class="form-row">


                        <div class="form-group">

                            <label for="stock_quantity">
                                Opening Stock *
                            </label>

                            <input
                                type="number"
                                id="stock_quantity"
                                name="stock_quantity"
                                value="<?= htmlspecialchars($stock_quantity) ?>"
                                min="0"
                                step="0.001"
                                placeholder="0"
                                required
                            >

                            <small>
                                Initial quantity available.
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
                                value="<?= htmlspecialchars($reorder_level) ?>"
                                min="0"
                                step="0.001"
                                placeholder="0"
                            >

                            <small>
                                BizFlow will flag stock at or below this level.
                            </small>

                        </div>


                    </div>


                    <!-- BUTTONS -->

                    <div style="
                        display:flex;
                        gap:10px;
                        margin-top:25px;
                    ">

                        <button
                            type="submit"
                            class="btn-primary"
                        >
                            Save Product
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


<script>
    // Units data from PHP
    const unitsData = <?= json_encode($units_by_method) ?>;
    
    // Get the selected unit from PHP (for preserving after validation errors)
    const selectedUnitId = "<?= htmlspecialchars($unit_id) ?>";
    
    // Get the selected selling method from PHP
    const selectedMethodId = "<?= htmlspecialchars($selling_method_id) ?>";
    
    // DOM elements
    const methodSelect = document.getElementById('selling_method_id');
    const unitSelect = document.getElementById('unit_id');
    
    /**
     * Update the unit dropdown based on selected selling method
     */
    function updateUnits() {
        const methodId = methodSelect.value;
        
        // Clear current options
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        
        // If no method selected, show message
        if (!methodId) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Please select a selling method first';
            option.disabled = true;
            option.selected = true;
            unitSelect.appendChild(option);
            return;
        }
        
        // Check if units exist for this method
        if (unitsData[methodId] && unitsData[methodId].length > 0) {
            unitsData[methodId].forEach(function(unit) {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.name;
                unitSelect.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No units available for this method';
            option.disabled = true;
            option.selected = true;
            unitSelect.appendChild(option);
        }
        
        // Preserve selected unit if it exists
        if (selectedUnitId) {
            unitSelect.value = selectedUnitId;
        }
    }
    
    /**
     * Initialize the form when the page loads
     */
    document.addEventListener('DOMContentLoaded', function() {
        // If a selling method is already selected, populate units
        if (selectedMethodId) {
            methodSelect.value = selectedMethodId;
            updateUnits();
        } else {
            // Default state: show "Please select a selling method first"
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Please select a selling method first';
            option.disabled = true;
            option.selected = true;
            unitSelect.innerHTML = '';
            unitSelect.appendChild(option);
        }
    });
    
    // Make updateUnits globally accessible
    window.updateUnits = updateUnits;
</script>

</body>

</html>
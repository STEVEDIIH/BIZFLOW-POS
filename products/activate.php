<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

if (strtolower($_SESSION["role_name"]) !== "admin") {
    die("Access Denied!");
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = $_SESSION["business_id"];
$product_id = (int)($_GET["id"] ?? 0);

/*
|--------------------------------------------------------------------------
| Validate Product ID
|--------------------------------------------------------------------------
*/

if ($product_id <= 0) {
    header("Location: view_products.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Confirm Product Exists
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, name, is_active
    FROM products
    WHERE id = :id
    AND business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ":id" => $product_id,
    ":business_id" => $business_id
]);

$product = $stmt->fetch();

if (!$product) {
    header("Location: view_products.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Activate Product
|--------------------------------------------------------------------------
*/

if ((int)$product["is_active"] === 0) {

    $stmt = $pdo->prepare("
        UPDATE products
        SET is_active = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
        AND business_id = :business_id
    ");

    $stmt->execute([
        ":id" => $product_id,
        ":business_id" => $business_id
    ]);
}

/*
|--------------------------------------------------------------------------
| Return to Products
|--------------------------------------------------------------------------
*/

header("Location: view_products.php?activated=1");
exit;
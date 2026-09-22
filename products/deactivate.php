<?php

session_start();

/*
|--------------------------------------------------------------------------
| Check Login
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Check Admin Permission
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    die("Access Denied!");
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Get Business and Product IDs
|--------------------------------------------------------------------------
*/

$business_id = $_SESSION["business_id"] ?? 0;
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
| Deactivate Product
|--------------------------------------------------------------------------
*/

if ((int)$product["is_active"] === 1) {

    $stmt = $pdo->prepare("
        UPDATE products
        SET is_active = 0,
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
| Redirect Back
|--------------------------------------------------------------------------
*/

header("Location: view_products.php?deactivated=1");
exit;

?>
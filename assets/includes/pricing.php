<?php

/*
|--------------------------------------------------------------------------
| BizFlow Pricing Engine
|--------------------------------------------------------------------------
| Handles:
| - Default Product Price
| - Price Groups
| - Customer Pricing
| - Future Promotions
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/../config/database.php";

/**
 * Get the default selling price of a product.
 */
function getDefaultPrice(int $product_id): ?float
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT selling_price
        FROM products
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $product_id
    ]);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return null;
    }

    return (float)$product["selling_price"];
}

/**
 * Get customer's price group.
 */
function getCustomerPriceGroup(int $customer_id): ?int
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT price_group_id
        FROM customers
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $customer_id
    ]);

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        return null;
    }

    return $customer["price_group_id"];
}

/**
 * Get price from product_prices table.
 */
function getGroupPrice(
    int $product_id,
    int $price_group_id
): ?float
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT price
        FROM product_prices
        WHERE product_id = :product
        AND price_group_id = :group_id
        LIMIT 1
    ");

    $stmt->execute([
        ":product" => $product_id,
        ":group_id" => $price_group_id
    ]);

    $price = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$price) {
        return null;
    }

    return (float)$price["price"];
}

/**
 * Main Pricing Function
 */
function getSellingPrice(
    int $product_id,
    ?int $customer_id = null
): float
{

    /*
    |--------------------------------------------------------------------------
    | Walk-in Customer
    |--------------------------------------------------------------------------
    */

    if ($customer_id === null) {

        return getDefaultPrice($product_id);

    }

    /*
    |--------------------------------------------------------------------------
    | Customer Price Group
    |--------------------------------------------------------------------------
    */

    $price_group = getCustomerPriceGroup($customer_id);

    if (!$price_group) {

        return getDefaultPrice($product_id);

    }

    /*
    |--------------------------------------------------------------------------
    | Group Price
    |--------------------------------------------------------------------------
    */

    $group_price = getGroupPrice(
        $product_id,
        $price_group
    );

    if ($group_price !== null) {

        return $group_price;

    }

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    */

    return getDefaultPrice($product_id);

}
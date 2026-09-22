<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];
$hold_id = (int) ($_GET['id'] ?? 0);

if (!$hold_id) {
    header("Location: pos.php?error=" . urlencode("Invalid hold ID"));
    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | GET HELD SALE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT *
        FROM held_sales
        WHERE id = :id
        AND business_id = :business_id
        AND user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ':id'          => $hold_id,
        ':business_id' => $business_id,
        ':user_id'     => $user_id
    ]);

    $held_sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$held_sale) {
        header("Location: pos.php?error=" . urlencode("Held sale not found"));
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GET HELD SALE ITEMS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            hsi.product_id,
            hsi.quantity,
            hsi.unit_price,
            hsi.total,

            p.name,
            p.unit,
            p.stock_quantity

        FROM held_sale_items hsi

        INNER JOIN products p
            ON hsi.product_id = p.id

        WHERE hsi.held_sale_id = :held_sale_id
    ");

    $stmt->execute([
        ':held_sale_id' => $hold_id
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | MAKE SURE ITEMS EXIST
    |--------------------------------------------------------------------------
    */

    if (empty($items)) {
        header("Location: pos.php?error=" . urlencode("This held sale has no items"));
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CONVERT ITEMS TO CART FORMAT
    |--------------------------------------------------------------------------
    |
    | Your JavaScript cart uses:
    | id
    | name
    | price
    | quantity
    | unit
    |
    */

    $resume_cart = [];

    foreach ($items as $item) {

        $resume_cart[] = [
            'id'       => (int) $item['product_id'],
            'name'     => $item['name'],
            'price'    => (float) $item['unit_price'],
            'quantity' => (float) $item['quantity'],
            'unit'     => $item['unit']
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | STORE CART IN SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['resume_cart'] = $resume_cart;

    $_SESSION['resumed_hold_id'] = $hold_id;


    /*
    |--------------------------------------------------------------------------
    | NOW DELETE THE HELD SALE
    |--------------------------------------------------------------------------
    |
    | We only delete it after the cart has successfully been prepared.
    |
    */

    $stmt = $pdo->prepare("
        DELETE FROM held_sales
        WHERE id = :id
        AND business_id = :business_id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ':id'          => $hold_id,
        ':business_id' => $business_id,
        ':user_id'     => $user_id
    ]);


    /*
    |--------------------------------------------------------------------------
    | RETURN TO POS
    |--------------------------------------------------------------------------
    */

    header("Location: pos.php?resumed=1");
    exit;


} catch (PDOException $e) {

    header(
        "Location: pos.php?error=" .
        urlencode($e->getMessage())
    );

    exit;
}
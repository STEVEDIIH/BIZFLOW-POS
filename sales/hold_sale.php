<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// Set JSON header first
header('Content-Type: application/json');

if (!isset($_SESSION["user_id"])) {
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please login again.'
    ]);
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];

// Get JSON data
$raw_input = file_get_contents("php://input");
$input = json_decode($raw_input, true);

// Check if JSON is valid
if ($input === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data received.'
    ]);
    exit;
}

$cart = $input['cart'] ?? [];
$customer_name = $input['customer_name'] ?? 'Walk-in Customer';

// Validate cart
if (empty($cart)) {
    echo json_encode([
        'success' => false,
        'message' => 'Cart is empty. Nothing to hold.'
    ]);
    exit;
}

// Generate hold number
$today = date('Ymd');
$hold_number = 'HOLD-' . $today . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);

try {
    $pdo->beginTransaction();

    // Calculate total
    $total = 0;
    foreach ($cart as $item) {
        $total += $item['price'] * $item['quantity'];
    }

    // Insert held sale (using created_at instead of hold_date)
    $stmt = $pdo->prepare("
        INSERT INTO held_sales (
            business_id, 
            user_id, 
            hold_number, 
            customer_name, 
            total_amount
        )
        VALUES (
            :business_id, 
            :user_id, 
            :hold_number, 
            :customer_name, 
            :total_amount
        )
    ");
    
    $stmt->execute([
        ':business_id' => $business_id,
        ':user_id' => $user_id,
        ':hold_number' => $hold_number,
        ':customer_name' => $customer_name,
        ':total_amount' => $total
    ]);

    $held_sale_id = $pdo->lastInsertId();

    if (!$held_sale_id) {
        throw new Exception("Failed to insert held sale.");
    }

    // Insert held sale items
    $stmt = $pdo->prepare("
        INSERT INTO held_sale_items (
            held_sale_id, 
            product_id, 
            quantity, 
            unit_price, 
            total
        )
        VALUES (
            :held_sale_id, 
            :product_id, 
            :quantity, 
            :unit_price, 
            :total
        )
    ");

    foreach ($cart as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        
        $stmt->execute([
            ':held_sale_id' => $held_sale_id,
            ':product_id' => $item['id'],
            ':quantity' => $item['quantity'],
            ':unit_price' => $item['price'],
            ':total' => $subtotal
        ]);
    }

    $pdo->commit();

    // Return success with hold number
    echo json_encode([
        'success' => true,
        'message' => 'Sale held successfully!',
        'hold_number' => $hold_number
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
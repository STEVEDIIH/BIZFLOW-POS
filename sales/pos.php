<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$user_id     = (int) $_SESSION["user_id"];

/*
|--------------------------------------------------------------------------
| Get products for POS with discounts
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        p.selling_price,
        p.wholesale_price,
        p.wholesale_min_qty,
        p.stock_quantity,
        p.unit,
        pd.id as discount_id,
        pd.discount_type,
        pd.discount_value,
        pd.start_date,
        pd.end_date
    FROM products p
    LEFT JOIN product_discounts pd ON p.id = pd.product_id
        AND (pd.start_date IS NULL OR pd.start_date <= NOW())
        AND (pd.end_date IS NULL OR pd.end_date >= NOW())
    WHERE p.business_id = :business_id
    AND p.is_active = 1
    AND p.stock_quantity > 0
    ORDER BY p.name
");

$stmt->execute([
    ":business_id" => $business_id
]);

$products = $stmt->fetchAll();

// Calculate discounted prices for products with active discounts
foreach ($products as &$product) {
    $product['has_discount'] = false;
    $product['discounted_price'] = $product['selling_price'];
    $product['discount_label'] = '';
    $product['discount_amount'] = 0;
    
    if ($product['discount_id'] && $product['discount_value'] > 0) {
        if ($product['discount_type'] === 'percentage') {
            $discount_amount = $product['selling_price'] * ($product['discount_value'] / 100);
            $product['discounted_price'] = $product['selling_price'] - $discount_amount;
            $product['discount_label'] = $product['discount_value'] . '% OFF';
            $product['discount_amount'] = $discount_amount;
        } else if ($product['discount_type'] === 'fixed') {
            $product['discounted_price'] = max(0, $product['selling_price'] - $product['discount_value']);
            $product['discount_label'] = 'KSh ' . $product['discount_value'] . ' OFF';
            $product['discount_amount'] = $product['discount_value'];
        }
        $product['has_discount'] = true;
    }
}

/*
|--------------------------------------------------------------------------
| Generate unique receipt number (FIXED - No duplicates)
|--------------------------------------------------------------------------
*/

function generateUniqueReceiptNumber($pdo, $business_id) {
    $max_attempts = 10;
    $attempt = 0;
    
    do {
        // Generate receipt number with 5 digits (more combinations = less collisions)
        $receipt_number = "REC-" . date("Ymd") . "-" . str_pad(
            random_int(1, 99999),
            5,
            "0",
            STR_PAD_LEFT
        );
        
        // Check if this receipt number already exists for this business
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM sales 
            WHERE receipt_number = :receipt_number 
            AND business_id = :business_id
        ");
        $stmt->execute([
            ':receipt_number' => $receipt_number,
            ':business_id' => $business_id
        ]);
        $exists = (int) $stmt->fetchColumn();
        
        if ($exists == 0) {
            // Unique receipt number found
            return $receipt_number;
        }
        
        $attempt++;
        
    } while ($attempt < $max_attempts);
    
    // Ultimate fallback: use timestamp (always unique)
    return "REC-" . date("YmdHis") . "-" . random_int(100, 999);
}

$receipt_number = generateUniqueReceiptNumber($pdo, $business_id);

/*
|--------------------------------------------------------------------------
| Check for resumed sale
|--------------------------------------------------------------------------
*/

$resume_cart_json = null;
if (isset($_SESSION['resume_cart']) && !empty($_SESSION['resume_cart'])) {
    $resume_items = $_SESSION['resume_cart'];
    $_SESSION['resume_cart'] = []; // Clear after reading
    $resume_cart_json = json_encode($resume_items);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        /* ============================================================
           FIXED HEIGHT POS LAYOUT
           ============================================================ */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
            background: #f3f4f6;
            font-family: Arial, Helvetica, sans-serif;
        }

        .app {
            display: flex;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
        }

        .app .sidebar {
            width: 230px;
            min-width: 230px;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
            background: #111827;
            color: #f4f5f0;
            padding: 20px 15px;
            position: relative;
            z-index: 10;
        }

        .app .main {
            flex: 5;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #05ed43;
        }

        .app .main .topbar {
            height: 56px;
            min-height: 56px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex ;
            align-items: left;
            justify-content: space-between;
            padding: 0 24px;
            flex-shrink: 0;
        }

        .app .main .content {
            flex: 1;
            padding: 8px 16px 12px 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            flex-shrink: 0;
        }

        .page-title h1 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .page-title .receipt-info {
            font-size: 13px;
            color: #6b7280;
        }

        .page-title .receipt-info strong {
            color: #111827;
        }

        .pos-container {
            display: flex;
            gap: 12px;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        /* ============================================================
           LEFT COLUMN
           ============================================================ */

        .pos-left {
            flex: 3;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
            background: #fff;
            border-radius: 8px;
            padding: 10px 14px 12px 14px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .pos-left .search-box {
            margin-bottom: 6px;
            flex-shrink: 0;
        }

        .pos-left .search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }

        .pos-left .search-box input:focus {
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
        }

        .pos-left .sale-type-selector {
            display: flex;
            gap: 8px;
            margin-bottom: 4px;
            flex-shrink: 0;
        }

        .pos-left .sale-type-selector label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            padding: 4px 12px;
            border-radius: 6px;
            background: #f3f4f6;
            font-size: 12px;
            font-weight: 500;
        }

        .pos-left .sale-type-selector label.active {
            background: #e5e7eb;
        }

        .pos-left .product-grid {
            flex: 1;
            overflow-y: auto;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 6px;
            align-content: start;
            padding-right: 4px;
            padding-top: 4px;
        }

        .pos-left .product-grid .product-btn {
            padding: 10px 6px;
            border: 1px solid #0a57f2;
            border-radius: 6px;
            background: #fafafa;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
            position: relative;
        }

        .pos-left .product-grid .product-btn:hover {
            background: #f3f4f6;
            border-color: #111827;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .pos-left .product-grid .product-btn .product-name {
            font-weight: 600;
            font-size: 12px;
            color: #111827;
        }

        .pos-left .product-grid .product-btn .price {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        .pos-left .product-grid .product-btn .discount-price {
            color: #16a34a;
            font-weight: 700;
        }

        .pos-left .product-grid .product-btn .original-price {
            text-decoration: line-through;
            color: #9ca3af;
            font-size: 10px;
        }

        .pos-left .product-grid .product-btn .stock-info {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .pos-left .product-grid .product-btn .discount-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #fff;
            font-size: 8px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 8px;
            animation: pulse 1.5s infinite;
        }

        .pos-left .product-grid .product-btn .wholesale-badge {
            display: block;
            font-size: 9px;
            color: #0cba32;
            margin-top: 1px;
        }

        @keyframes pulse {
            0%,
            100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .pos-left .product-grid::-webkit-scrollbar {
            width: 4px;
        }

        .pos-left .product-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .pos-left .product-grid::-webkit-scrollbar-thumb {
            background: #0c5fda;
            border-radius: 4px;
        }

        /* ============================================================
           RIGHT COLUMN
           ============================================================ */

        .pos-right {
            flex: 2;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
            background: #fff;
            border-radius: 8px;
            padding: 10px 14px 12px 14px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .pos-right .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            margin-bottom: 4px;
        }

        .pos-right .cart-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }

        .pos-right .cart-actions {
            display: flex;
            gap: 6px;
        }

        .pos-right .cart-actions button {
            padding: 4px 12px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .pos-right .cart-actions .btn-hold {
            background: #f59e0b;
            color: #fff;
        }

        .pos-right .cart-actions .btn-hold:hover {
            background: #d97706;
        }

        .pos-right .cart-actions .btn-resume {
            background: #2563eb;
            color: #fff;
        }

        .pos-right .cart-actions .btn-resume:hover {
            background: #1d4ed8;
        }

        .pos-right .cart-items-wrapper {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 4px;
            min-height: 0;
            padding-right: 4px;
        }

        .pos-right .cart-items-wrapper::-webkit-scrollbar {
            width: 4px;
        }

        .pos-right .cart-items-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .pos-right .cart-items-wrapper::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }

        .pos-right .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
            border-bottom: 1px solid #f3f4f6;
            gap: 4px;
        }

        .pos-right .cart-item .item-info {
            flex: 1;
            min-width: 0;
        }

        .pos-right .cart-item .item-name {
            font-weight: 500;
            font-size: 12px;
            color: #111827;
        }

        .pos-right .cart-item .item-details {
            font-size: 11px;
            color: #6b7280;
        }

        .pos-right .cart-item .item-details .badge {
            font-size: 9px;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: 4px;
        }

        .pos-right .cart-item .item-details .badge-retail {
            background: #dbeafe;
            color: #1e40af;
        }

        .pos-right .cart-item .item-details .badge-wholesale {
            background: #fef3c7;
            color: #92400e;
        }

        .pos-right .cart-item .qty-control {
            display: flex;
            align-items: center;
            gap: 3px;
            flex-shrink: 0;
        }

        .pos-right .cart-item .qty-control .qty-input {
            width: 45px;
            padding: 2px 3px;
            border: 1.5px solid #d1d5db;
            border-radius: 3px;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            background: #fff;
        }

        .pos-right .cart-item .qty-control .qty-input:focus {
            outline: none;
            border-color: #111827;
        }

        .pos-right .cart-item .qty-control .btn-remove {
            background: #ef4444;
            color: #fff;
            border: none;
            border-radius: 3px;
            padding: 2px 6px;
            font-size: 12px;
            cursor: pointer;
            line-height: 1.4;
        }

        .pos-right .cart-item .qty-control .btn-remove:hover {
            background: #dc2626;
        }

        .pos-right .cart-item .item-total {
            font-weight: 600;
            font-size: 12px;
            color: #111827;
            text-align: right;
            min-width: 60px;
            flex-shrink: 0;
        }

        .pos-right .cart-empty {
            color: #9ca3af;
            text-align: center;
            padding: 20px 0;
            font-size: 13px;
        }

        /* ============================================================
           CART TOTALS
           ============================================================ */

        .pos-right .cart-totals {
            flex-shrink: 0;
            border-top: 2px solid #e5e7eb;
            padding-top: 6px;
            margin-top: 2px;
        }

        .pos-right .cart-totals .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 2px 0;
            color: #374151;
        }

        .pos-right .cart-totals .total-row .label {
            color: #6b7280;
        }

        .pos-right .cart-totals .grand-total {
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            padding-top: 4px;
            border-top: 1px solid #e5e7eb;
            margin-top: 2px;
        }

        /* ============================================================
           PAYMENT SECTION
           ============================================================ */

        .pos-right .payment-section {
            flex-shrink: 0;
            margin-top: 4px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
        }

        .pos-right .payment-section .payment-methods {
            display: flex;
            gap: 4px;
            margin-bottom: 4px;
        }

        .pos-right .payment-section .payment-methods label {
            display: flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            padding: 3px 10px;
            border-radius: 4px;
            background: #f3f4f6;
            font-size: 12px;
            font-weight: 500;
            transition: background 0.2s;
        }

        .pos-right .payment-section .payment-methods label.active {
            background: #e5e7eb;
        }

        .pos-right .payment-section .payment-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
            align-items: center;
        }

        .pos-right .payment-section .payment-fields .field-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pos-right .payment-section .payment-fields .field-group label {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
        }

        .pos-right .payment-section .payment-fields .field-group input {
            width: 80px;
            padding: 4px 6px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            text-align: right;
        }

        .pos-right .payment-section .payment-fields .field-group input:focus {
            outline: none;
            border-color: #111827;
        }

        .pos-right .payment-section .payment-fields .change-display {
            font-size: 13px;
            font-weight: 600;
            color: #16a34a;
        }

        .pos-right .payment-section .payment-messages {
            margin-top: 4px;
        }

        .pos-right .payment-section .payment-messages .payment-error {
            color: #991b1b;
            background: #fee2e2;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: none;
            border: 1px solid #fca5a5;
        }

        .pos-right .payment-section .payment-messages .payment-error.show {
            display: block;
        }

        .pos-right .payment-section .payment-messages .payment-success {
            color: #065f46;
            background: #d1fae5;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: none;
            border: 1px solid #6ee7b7;
        }

        .pos-right .payment-section .payment-messages .payment-success.show {
            display: block;
        }

        .pos-right .btn-checkout {
            width: 100%;
            padding: 10px;
            background: #22c55e;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .pos-right .btn-checkout:hover {
            background: #16a34a;
        }

        .pos-right .btn-checkout:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        /* ============================================================
           MODAL
           ============================================================ */

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 12px;
            padding: 24px 28px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-box .product-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .modal-box .product-price {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 12px;
        }

        .modal-box .product-price strong {
            color: #111827;
            font-size: 18px;
        }

        .modal-box .divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 10px 0;
        }

        .modal-box .mode-label {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 4px;
            color: #374151;
        }

        .modal-box .mode-options {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
        }

        .modal-box .mode-options label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            font-size: 13px;
        }

        .modal-box .input-group {
            margin-bottom: 10px;
        }

        .modal-box .input-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 3px;
            color: #374151;
        }

        .modal-box .input-group input {
            width: 100%;
            padding: 8px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .modal-box .input-group input:focus {
            outline: none;
            border-color: #111827;
        }

        .modal-box .input-group .unit-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        .modal-box .calculation-preview {
            background: #f3f4f6;
            padding: 8px;
            border-radius: 6px;
            margin: 8px 0;
            text-align: center;
            font-size: 13px;
        }

        .modal-box .calculation-preview strong {
            font-size: 16px;
            color: #111827;
        }

        .modal-box .wholesale-warning {
            display: none;
            padding: 6px 10px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 12px;
            color: #92400e;
        }

        .modal-box .wholesale-warning.show {
            display: block;
        }

        .modal-box .error-text {
            color: #dc2626;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }

        .modal-box .error-text.visible {
            display: block;
        }

        .modal-box .modal-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .modal-box .modal-actions button {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .modal-box .modal-actions .btn-cancel {
            background: #f3f4f6;
            color: #374151;
        }

        .modal-box .modal-actions .btn-cancel:hover {
            background: #e5e7eb;
        }

        .modal-box .modal-actions .btn-add {
            background: #111827;
            color: #fff;
        }

        .modal-box .modal-actions .btn-add:hover {
            background: #374151;
        }

        .modal-box .modal-actions .btn-add:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        /* ============================================================
           HELD SALES MODAL
           ============================================================ */

        .held-sales-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .held-sales-modal .modal-content {
            background: #fff;
            border-radius: 12px;
            padding: 24px 28px;
            max-width: 540px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .held-sales-modal .sale-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #f9fafb;
        }

        .held-sales-modal .sale-item:hover {
            background: #f3f4f6;
        }

        .held-sales-modal .btn-resume {
            padding: 4px 14px;
            background: #22c55e;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
        }

        .held-sales-modal .btn-resume:hover {
            background: #16a34a;
        }

        .held-sales-modal .btn-close-modal {
            padding: 8px 16px;
            background: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            margin-top: 10px;
        }

        .held-sales-modal .btn-close-modal:hover {
            background: #e5e7eb;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */

        @media (max-width: 1024px) {
            .pos-container {
                gap: 10px;
            }
            .pos-left .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
            .pos-right .cart-item .qty-control .qty-input {
                width: 40px;
            }
            .pos-right .payment-section .payment-fields .field-group input {
                width: 70px;
            }
        }

        @media (max-width: 820px) {
            .pos-container {
                flex-direction: column;
                gap: 8px;
            }
            .pos-left {
                flex: 1;
                min-height: 35%;
            }
            .pos-right {
                flex: 1;
                min-height: 35%;
            }
            .pos-left .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            }
            .pos-right .payment-section .payment-fields {
                flex-direction: column;
                align-items: stretch;
            }
            .pos-right .payment-section .payment-fields .field-group {
                flex-wrap: wrap;
            }
            .pos-right .payment-section .payment-fields .field-group input {
                width: 100%;
            }
            .pos-right .cart-item .qty-control .qty-input {
                width: 50px;
            }
        }

        @media (max-width: 500px) {
            .app .main .topbar {
                padding: 0 12px;
                font-size: 12px;
            }
            .app .main .content {
                padding: 4px 8px 8px 8px;
            }
            .pos-left .sale-type-selector {
                flex-wrap: wrap;
            }
            .pos-left .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(75px, 1fr));
            }
            .pos-left .product-grid .product-btn {
                padding: 6px 4px;
            }
            .pos-left .product-grid .product-btn .product-name {
                font-size: 10px;
            }
            .pos-left .product-grid .product-btn .price {
                font-size: 10px;
            }
            .pos-right .cart-item {
                flex-wrap: wrap;
                gap: 2px;
            }
            .pos-right .cart-item .qty-control {
                width: 100%;
                justify-content: flex-end;
            }
            .pos-right .cart-item .item-total {
                min-width: 50px;
            }
            .pos-right .payment-section .payment-methods {
                flex-wrap: wrap;
            }
            .pos-right .payment-section .payment-methods label {
                padding: 2px 8px;
                font-size: 11px;
            }
            .pos-right .cart-totals .grand-total {
                font-size: 15px;
            }
            .pos-right .btn-checkout {
                font-size: 14px;
                padding: 8px;
            }
        }
        .app {
    gap: 0 !important;
}

.app .main {
    margin-left: 0 !important;
    padding-left: 0 !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.app .main .content {
    margin-left: 0 !important;
    padding-left: 8px !important;
}

.pos-container {
    margin-left: 0 !important;
}
/* ============================================================
   ELECTRON FIX: Force inputs to be interactive
   ============================================================ */

/* Force all inputs to accept events in Electron */
input, select, textarea {
    pointer-events: auto !important;
    -webkit-user-select: text !important;
    user-select: text !important;
}

/* Ensure disabled inputs don't block events */
input:disabled, select:disabled, textarea:disabled {
    pointer-events: none !important;
    opacity: 0.6;
}

/* Force input focus in Electron */
input[type="number"]:focus, input[type="text"]:focus {
    outline: 2px solid #111827 !important;
    outline-offset: 1px !important;
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
                        <h1>POS - Point of Sale</h1>
                        <div>
                            Receipt:
                            <strong><?= htmlspecialchars($receipt_number) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="pos-container">

                    <!-- =========================================================
                    LEFT COLUMN: PRODUCTS
                    ========================================================= -->

                    <div class="pos-left">

                        <div class="search-box">
                            <input
                            type="text"
                            id="searchProduct"
                            placeholder="Search products..."
                            >
                        </div>

                        <div class="sale-type-selector">

                            <span style="font-weight:600; font-size:12px; color:#374151;">Sale Type:</span>

                            <label id="retailLabel" class="active">
                                <input type="radio" name="sale_type" value="retail" checked onchange="toggleSaleType()">
                                🏪 Retail
                            </label>

                            <label id="wholesaleLabel">
                                <input type="radio" name="sale_type" value="wholesale" onchange="toggleSaleType()">
                                📦 Wholesale
                            </label>

                        </div>

                        <div class="product-grid" id="productGrid">

                            <?php foreach ($products as $product): ?>
                                <div class="product-btn"
                                data-id="<?= (int)$product["id"] ?>"
                                data-name="<?= htmlspecialchars($product["name"]) ?>"
                                data-price="<?= htmlspecialchars($product["selling_price"]) ?>"
                                data-discounted-price="<?= htmlspecialchars($product['discounted_price']) ?>"
                                data-has-discount="<?= $product['has_discount'] ? 'true' : 'false' ?>"
                                data-discount-label="<?= htmlspecialchars($product['discount_label']) ?>"
                                data-wholesale-price="<?= htmlspecialchars($product["wholesale_price"] ?? 0) ?>"
                                data-wholesale-min-qty="<?= htmlspecialchars($product["wholesale_min_qty"] ?? 0) ?>"
                                data-stock="<?= htmlspecialchars($product["stock_quantity"]) ?>"
                                data-unit="<?= htmlspecialchars($product["unit"]) ?>"
                                onclick="openModal(this)"
                                >
                                <?php if ($product['has_discount']): ?>
                                    <span class="discount-badge">🔥 <?= $product['discount_label'] ?></span>
                                <?php endif; ?>

                                <div class="product-name"><?= htmlspecialchars($product["name"]) ?></div>

                                <div class="price" id="price-<?= $product["id"] ?>">
                                    <?php if ($product['has_discount']): ?>
                                        <span class="original-price">KSh <?= number_format((float)$product["selling_price"], 2) ?></span>
                                        <span class="discount-price">KSh <?= number_format((float)$product['discounted_price'], 2) ?></span>
                                    <?php else: ?>
                                        KSh <?= number_format((float)$product["selling_price"], 2) ?>
                                    <?php endif; ?>
                                    <?php if ((float)$product["wholesale_price"] > 0): ?>
                                        <span class="wholesale-badge">Wholesale: KSh <?= number_format((float)$product["wholesale_price"], 2) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="stock-info">
                                    Stock: <?= htmlspecialchars($product["stock_quantity"]) ?> <?= htmlspecialchars($product["unit"]) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>

                </div>

                <!-- =========================================================
                RIGHT COLUMN: CART + PAYMENT
                ========================================================= -->

                <div class="pos-right">

                    <div class="cart-header">
                        <h3>Shopping Cart</h3>
                        <div class="cart-actions">
                            <button type="button" class="btn-hold" id="holdSaleBtn" onclick="holdSale()">💾 Hold Sale</button>
                            <button type="button" class="btn-resume" id="resumeSaleBtn" onclick="loadHeldSales()">📂 Resume Sale</button>
                        </div>
                    </div>

                    <div class="cart-items-wrapper" id="cartItems">
                        <p class="cart-empty">No items in cart</p>
                    </div>

                    <div class="cart-totals">
                        <div class="total-row">
                            <span class="label">Subtotal</span>
                            <span id="cartSubtotal">KSh 0.00</span>
                        </div>
                        <div class="grand-total">
                            <span>Total</span>
                            <span id="cartTotal">KSh 0.00</span>
                        </div>
                    </div>

                    <div class="payment-section">

                        <div class="payment-methods">

                            <label id="cashOption" class="active">
                                <input type="radio" name="payment_method" value="cash" id="paymentCash" checked onchange="updatePaymentFields()">
                                💵 Cash
                            </label>

                            <label id="bankOption">
                                <input type="radio" name="payment_method" value="bank" id="paymentBank" onchange="updatePaymentFields()">
                                🏦 Bank
                            </label>

                            <label id="mixedOption">
                                <input type="radio" name="payment_method" value="mixed" id="paymentMixed" onchange="updatePaymentFields()">
                                🔄 Mixed
                            </label>

                        </div>

                        <div class="payment-fields" id="paymentFields">

                            <div id="cashFields" class="field-group">
                                <label for="cashReceived">Cash Received</label>
                                <input type="number" id="cashReceived" step="0.01" min="0" placeholder="0.00" oninput="calculatePayment()">
                                <span style="font-size:12px; color:#6b7280; margin-left:4px;">Change: <span id="changeAmount" class="change-display">KSh 0.00</span></span>
                            </div>

                            <div id="bankFields" class="field-group" style="display:none;">
                                <label for="bankAmount">Bank Transfer</label>
                                <input type="number" id="bankAmount" step="0.01" min="0" placeholder="0.00" oninput="calculatePayment()">
                            </div>

                            <div id="mixedFields" style="display:none; width:100%;">
                                <div style="display:flex; flex-wrap:wrap; gap:4px 8px;">
                                    <div class="field-group">
                                        <label for="mixedCash">Cash</label>
                                        <input type="number" id="mixedCash" step="0.01" min="0" placeholder="0.00" oninput="calculatePayment()">
                                    </div>
                                    <div class="field-group">
                                        <label for="mixedBank">Bank</label>
                                        <input type="number" id="mixedBank" step="0.01" min="0" placeholder="0.00" oninput="calculatePayment()">
                                    </div>
                                    <div style="font-size:12px; color:#6b7280; display:flex; align-items:center; gap:6px;">
                                        <span>Paid: <strong id="mixedPaid">KSh 0.00</strong></span>
                                        <span>Balance: <span id="mixedBalance" style="font-weight:600;">KSh 0.00</span></span>
                                    </div>
                                </div>
                            </div>

                            <div class="payment-messages">
                                <div id="paymentError" class="payment-error">⚠️ <span id="paymentErrorMessage">Payment amount is not enough.</span></div>
                                <div id="paymentSuccess" class="payment-success">✅ <span id="paymentSuccessMessage">Payment is valid. You can complete the sale.</span></div>
                            </div>

                        </div>

                        <form method="POST" action="add_sale.php" id="saleForm">
                            <input type="hidden" name="receipt_number" value="<?= htmlspecialchars($receipt_number) ?>">
                            <input type="hidden" name="cart_data" id="cartData">
                            <input type="hidden" name="payment_method" id="paymentMethodInput" value="cash">
                            <input type="hidden" name="cash_amount" id="cashAmountInput" value="0">
                            <input type="hidden" name="bank_amount" id="bankAmountInput" value="0">
                            <button type="submit" class="btn-checkout" id="checkoutBtn" disabled>
                                Complete Sale
                            </button>
                        </form>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

<!-- =========================================================
MODAL
========================================================= -->

<div class="modal-overlay" id="modalOverlay">

    <div class="modal-box">

        <div class="product-name" id="modalProductName">Product Name</div>

        <div class="product-price" id="modalProductPriceContainer">
            Selling Price: <strong id="modalProductPrice">KSh 0.00</strong>
            <span id="modalProductUnit" style="font-weight:normal;color:#6b7280;">/ pcs</span>
            <span id="modalDiscountBadge" style="display:none; color:#16a34a; font-size:13px; margin-left:10px;"></span>
        </div>

        <hr class="divider">

        <div class="wholesale-warning" id="wholesaleWarning">
            ⚠️ Minimum wholesale quantity: <span id="wholesaleMinQtyDisplay">0</span>
        </div>

        <div class="mode-label">Select Selling Mode</div>

        <div class="mode-options">
            <label>
                <input type="radio" name="selling_mode" value="quantity" checked onchange="toggleSellingMode()">
                By Quantity
            </label>
            <label>
                <input type="radio" name="selling_mode" value="amount" onchange="toggleSellingMode()">
                By Amount
            </label>
        </div>

        <div id="quantityFields" class="input-group">
            <label for="modalQuantity">Quantity</label>
            <input type="number" id="modalQuantity" step="0.001" min="0.001" placeholder="Enter quantity" oninput="calculateFromQuantity()">
            <div class="unit-hint" id="quantityUnitHint">e.g. 0.25, 0.5, 0.75, 1.25, 1.5, 2.75</div>
        </div>

        <div id="amountFields" class="input-group" style="display:none;">
            <label for="modalAmount">Amount (KSh)</label>
            <input type="number" id="modalAmount" step="0.01" min="0.01" placeholder="Enter amount" oninput="calculateFromAmount()">
            <div class="unit-hint">Enter how much the customer wants to pay</div>
        </div>

        <div class="calculation-preview" id="calculationPreview">
            <span id="previewLabel">Quantity:</span>
            <strong id="previewValue">0.000</strong>
            <span id="previewUnit">pcs</span>
            &nbsp;=&nbsp; <strong id="previewTotal">KSh 0.00</strong>
        </div>

        <div class="error-text" id="modalError">Please enter a valid value.</div>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button type="button" class="btn-add" id="modalAddBtn" onclick="addToCartFromModal()" disabled>Add to Cart</button>
        </div>

    </div>

</div>

<script>

    let cart = [];
    let selectedProduct = null;
    let currentSaleType = 'retail';

    function toggleSaleType() {
        const selected = document.querySelector('input[name="sale_type"]:checked');
        if (!selected) return;

        currentSaleType = selected.value;

        const retailLabel = document.getElementById('retailLabel');
        const wholesaleLabel = document.getElementById('wholesaleLabel');

        if (currentSaleType === 'retail') {
            retailLabel.className = 'active';
            wholesaleLabel.className = '';
        } else {
            retailLabel.className = '';
            wholesaleLabel.className = 'active';
        }

        document.querySelectorAll('.product-btn').forEach(btn => {
            const priceEl = btn.querySelector('.price');
            const retailPrice = parseFloat(btn.dataset.price);
            const discountedPrice = parseFloat(btn.dataset.discountedPrice) || retailPrice;
            const hasDiscount = btn.dataset.hasDiscount === 'true';
            const wholesalePrice = parseFloat(btn.dataset.wholesalePrice) || 0;
            const wholesaleMinQty = parseFloat(btn.dataset.wholesaleMinQty) || 0;

            if (currentSaleType === 'retail') {
                if (hasDiscount) {
                    priceEl.innerHTML = `
                                <span class="original-price">KSh ${retailPrice.toFixed(2)}</span>
                                <span class="discount-price">KSh ${discountedPrice.toFixed(2)}</span>
                                ${wholesalePrice > 0 ? `<span class="wholesale-badge">Wholesale: KSh ${wholesalePrice.toFixed(2)}</span>` : ''}
                            `;
                } else {
                    priceEl.innerHTML = `
                                KSh ${retailPrice.toFixed(2)}
                                ${wholesalePrice > 0 ? `<span class="wholesale-badge">Wholesale: KSh ${wholesalePrice.toFixed(2)}</span>` : ''}
                            `;
                }
            } else if (wholesalePrice > 0) {
                priceEl.innerHTML = `
                            KSh ${wholesalePrice.toFixed(2)}
                            <span class="wholesale-badge">Min Qty: ${wholesaleMinQty.toFixed(2)}</span>
                        `;
            } else {
                priceEl.innerHTML = `
                            KSh ${retailPrice.toFixed(2)}
                            <span class="wholesale-badge" style="color:#ef4444;">No wholesale price</span>
                        `;
            }
        });
    }

    function openModal(btn) {
        const retailPrice = parseFloat(btn.dataset.price);
        const discountedPrice = parseFloat(btn.dataset.discountedPrice) || retailPrice;
        const hasDiscount = btn.dataset.hasDiscount === 'true';
        const discountLabel = btn.dataset.discountLabel || '';

        selectedProduct = {
            id: btn.dataset.id,
            name: btn.dataset.name,
            price: hasDiscount ? discountedPrice : retailPrice,
            originalPrice: retailPrice,
            hasDiscount: hasDiscount,
            discountLabel: discountLabel,
            wholesalePrice: parseFloat(btn.dataset.wholesalePrice) || 0,
            wholesaleMinQty: parseFloat(btn.dataset.wholesaleMinQty) || 0,
            stock: parseFloat(btn.dataset.stock),
            unit: btn.dataset.unit || 'pcs'
        };

        let displayPrice = selectedProduct.price;
        let isWholesale = false;

        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            displayPrice = selectedProduct.wholesalePrice;
            isWholesale = true;
        }

        document.getElementById('modalProductName').textContent = selectedProduct.name;

        if (selectedProduct.hasDiscount && !isWholesale) {
            document.getElementById('modalProductPrice').innerHTML = `
                        <span style="text-decoration:line-through; color:#9ca3af; font-size:16px;">
                            KSh ${selectedProduct.originalPrice.toFixed(2)}
                        </span>
                        <span style="color:#16a34a; font-size:20px;">
                            KSh ${displayPrice.toFixed(2)}
                        </span>
                    `;
            document.getElementById('modalDiscountBadge').style.display = 'inline';
            document.getElementById('modalDiscountBadge').textContent = '🔥 ' + selectedProduct.discountLabel;
        } else {
            document.getElementById('modalProductPrice').textContent = 'KSh ' + displayPrice.toFixed(2);
            document.getElementById('modalDiscountBadge').style.display = 'none';
        }

        document.getElementById('modalProductUnit').textContent = '/ ' + selectedProduct.unit;

        const wholesaleWarning = document.getElementById('wholesaleWarning');
        const wholesaleMinQtyDisplay = document.getElementById('wholesaleMinQtyDisplay');

        if (isWholesale && selectedProduct.wholesalePrice > 0) {
            wholesaleWarning.className = 'wholesale-warning show';
            wholesaleMinQtyDisplay.textContent = selectedProduct.wholesaleMinQty + ' ' + selectedProduct.unit;
        } else {
            wholesaleWarning.className = 'wholesale-warning';
        }

        document.getElementById('modalQuantity').value = '';
        document.getElementById('modalAmount').value = '';
        document.getElementById('modalError').classList.remove('visible');
        document.getElementById('modalAddBtn').disabled = true;

        document.querySelector('input[name="selling_mode"][value="quantity"]').checked = true;
        toggleSellingMode();

        document.getElementById('modalOverlay').classList.add('active');
        document.getElementById('modalQuantity').focus();
    }

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('active');
        selectedProduct = null;
    }

    function toggleSellingMode() {
        const mode = document.querySelector('input[name="selling_mode"]:checked');
        if (!mode) return;

        const isQuantity = mode.value === 'quantity';

        document.getElementById('quantityFields').style.display = isQuantity ? 'block' : 'none';
        document.getElementById('amountFields').style.display = isQuantity ? 'none' : 'block';

        document.getElementById('previewValue').textContent = '0.000';
        document.getElementById('previewTotal').textContent = 'KSh 0.00';
        document.getElementById('previewUnit').textContent = selectedProduct ? selectedProduct.unit : 'pcs';
        document.getElementById('previewLabel').textContent = isQuantity ? 'Quantity:' : 'Amount:';

        document.getElementById('modalAddBtn').disabled = true;
        document.getElementById('modalError').classList.remove('visible');

        if (isQuantity) {
            document.getElementById('modalQuantity').focus();
        } else {
            document.getElementById('modalAmount').focus();
        }
    }

    function calculateFromQuantity() {
        if (!selectedProduct) return;

        const qty = parseFloat(document.getElementById('modalQuantity').value);
        const error = document.getElementById('modalError');
        const addBtn = document.getElementById('modalAddBtn');

        if (!qty || qty <= 0) {
            document.getElementById('previewValue').textContent = '0.000';
            document.getElementById('previewTotal').textContent = 'KSh 0.00';
            document.getElementById('previewUnit').textContent = selectedProduct.unit;
            addBtn.disabled = true;
            error.classList.remove('visible');
            return;
        }

        if (qty > selectedProduct.stock) {
            error.textContent = 'Not enough stock! Available: ' + selectedProduct.stock + ' ' + selectedProduct.unit;
            error.classList.add('visible');
            addBtn.disabled = true;
            return;
        }

        // Strict wholesale minimum quantity validation
        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            if (qty < selectedProduct.wholesaleMinQty) {
                error.textContent = '⚠️ Minimum wholesale quantity is ' + selectedProduct.wholesaleMinQty + ' ' + selectedProduct.unit + '. Current: ' + qty.toFixed(3);
                error.classList.add('visible');
                addBtn.disabled = true;
                // Update preview anyway so user can see the quantity
                let unitPrice = selectedProduct.wholesalePrice;
                const total = qty * unitPrice;
                document.getElementById('previewValue').textContent = qty.toFixed(3);
                document.getElementById('previewTotal').textContent = 'KSh ' + total.toFixed(2);
                document.getElementById('previewUnit').textContent = selectedProduct.unit;
                document.getElementById('previewLabel').textContent = 'Quantity:';
                return;
            } else {
                error.classList.remove('visible');
                addBtn.disabled = false;
            }
        } else {
            error.classList.remove('visible');
            addBtn.disabled = false;
        }

        let unitPrice = selectedProduct.price;
        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            unitPrice = selectedProduct.wholesalePrice;
        }

        const total = qty * unitPrice;
        document.getElementById('previewValue').textContent = qty.toFixed(3);
        document.getElementById('previewTotal').textContent = 'KSh ' + total.toFixed(2);
        document.getElementById('previewUnit').textContent = selectedProduct.unit;
        document.getElementById('previewLabel').textContent = 'Quantity:';

        addBtn.disabled = false;
    }

    function calculateFromAmount() {
        if (!selectedProduct) return;

        const amount = parseFloat(document.getElementById('modalAmount').value);
        const error = document.getElementById('modalError');
        const addBtn = document.getElementById('modalAddBtn');

        if (!amount || amount <= 0) {
            document.getElementById('previewValue').textContent = '0.000';
            document.getElementById('previewTotal').textContent = 'KSh 0.00';
            document.getElementById('previewUnit').textContent = selectedProduct.unit;
            addBtn.disabled = true;
            error.classList.remove('visible');
            return;
        }

        let unitPrice = selectedProduct.price;
        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            unitPrice = selectedProduct.wholesalePrice;
        }

        const qty = amount / unitPrice;
        const actualAmount = qty * unitPrice;

        if (qty > selectedProduct.stock) {
            error.textContent = 'Not enough stock! Available: ' + selectedProduct.stock + ' ' + selectedProduct.unit;
            error.classList.add('visible');
            addBtn.disabled = true;
            return;
        }

        // Strict wholesale minimum quantity validation
        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            if (qty < selectedProduct.wholesaleMinQty) {
                error.textContent = '⚠️ Minimum wholesale quantity is ' + selectedProduct.wholesaleMinQty + ' ' + selectedProduct.unit + '. Current: ' + qty.toFixed(3);
                error.classList.add('visible');
                addBtn.disabled = true;
                // Update preview anyway
                document.getElementById('previewValue').textContent = qty.toFixed(3);
                document.getElementById('previewTotal').textContent = 'KSh ' + actualAmount.toFixed(2);
                document.getElementById('previewUnit').textContent = selectedProduct.unit;
                document.getElementById('previewLabel').textContent = 'Amount: KSh ' + amount.toFixed(2) + ' → Quantity:';
                return;
            } else {
                error.classList.remove('visible');
                addBtn.disabled = false;
            }
        } else {
            error.classList.remove('visible');
            addBtn.disabled = false;
        }

        document.getElementById('previewValue').textContent = qty.toFixed(3);
        document.getElementById('previewTotal').textContent = 'KSh ' + actualAmount.toFixed(2);
        document.getElementById('previewUnit').textContent = selectedProduct.unit;
        document.getElementById('previewLabel').textContent = 'Amount: KSh ' + amount.toFixed(2) + ' → Quantity:';

        addBtn.disabled = false;
    }

    function addToCartFromModal() {
        if (!selectedProduct) return;

        const mode = document.querySelector('input[name="selling_mode"]:checked').value;
        let qty = 0;
        let unitPrice = selectedProduct.price;
        let saleType = 'retail';

        if (currentSaleType === 'wholesale' && selectedProduct.wholesalePrice > 0) {
            unitPrice = selectedProduct.wholesalePrice;
            saleType = 'wholesale';
        }

        if (mode === 'quantity') {
            qty = parseFloat(document.getElementById('modalQuantity').value);
        } else {
            const amount = parseFloat(document.getElementById('modalAmount').value);
            if (amount && amount > 0) {
                qty = amount / unitPrice;
            }
        }

        if (!qty || qty <= 0) {
            alert('Please enter a valid value.');
            return;
        }

        if (qty > selectedProduct.stock) {
            alert('Not enough stock! Available: ' + selectedProduct.stock + ' ' + selectedProduct.unit);
            return;
        }

        // FIX: Strict wholesale minimum quantity validation - BLOCK if below minimum
        if (saleType === 'wholesale' && qty < selectedProduct.wholesaleMinQty) {
            alert(
                '❌ Quantity (' + qty.toFixed(3) + ' ' + selectedProduct.unit +
                ') is below the minimum wholesale quantity (' +
                selectedProduct.wholesaleMinQty + ' ' + selectedProduct.unit + ').\n\n' +
                'Please increase the quantity to at least ' + selectedProduct.wholesaleMinQty + ' ' + selectedProduct.unit + '.\n' +
                'Or switch to Retail mode for smaller quantities.'
            );
            return;
        }

        const existing = cart.find(item => item.id === selectedProduct.id);

        if (existing) {
            if (existing.quantity + qty <= existing.max_stock) {
                existing.quantity += qty;
                existing.unit_price = unitPrice;
                existing.sale_type = saleType;
                existing.has_discount = selectedProduct.hasDiscount;
                existing.original_price = selectedProduct.originalPrice;
                existing.discount_label = selectedProduct.discountLabel;
            } else {
                alert('Not enough stock!');
                closeModal();
                return;
            }
        } else {
            cart.push({
                id: selectedProduct.id,
                name: selectedProduct.name,
                price: unitPrice,
                quantity: qty,
                max_stock: selectedProduct.stock,
                unit: selectedProduct.unit,
                sale_type: saleType,
                has_discount: selectedProduct.hasDiscount,
                original_price: selectedProduct.originalPrice,
                discount_label: selectedProduct.discountLabel
            });
        }

        updateCart();
        closeModal();
    }

    function updateCart() {
        const cartDiv = document.getElementById('cartItems');
        const subtotalSpan = document.getElementById('cartSubtotal');
        const totalSpan = document.getElementById('cartTotal');
        const checkoutBtn = document.getElementById('checkoutBtn');

        if (cart.length === 0) {
            cartDiv.innerHTML = `<p class="cart-empty">No items in cart</p>`;
            subtotalSpan.textContent = 'KSh 0.00';
            totalSpan.textContent = 'KSh 0.00';
            checkoutBtn.disabled = true;
            document.getElementById('cartData').value = '';
            calculatePayment();
            return;
        }

        let html = '';
        let subtotal = 0;

        cart.forEach((item, index) => {
            const subtotalItem = item.price * item.quantity;
            subtotal += subtotalItem;

            const saleTypeClass = item.sale_type === 'wholesale' ? 'wholesale' : 'retail';
            const saleTypeLabel = item.sale_type === 'wholesale' ? '📦 Wholesale' : '🏪 Retail';

            html += `
                        <div class="cart-item">
                            <div class="item-info">
                                <div class="item-name">${escapeHtml(item.name)}</div>
                                <div class="item-details">
                                    KSh ${item.price.toFixed(2)} × <span class="qty-display">${item.quantity.toFixed(3)}</span> ${item.unit}
                                    ${item.has_discount ? '<span style="color:#16a34a; font-size:11px;">🔥 ' + item.discount_label + '</span>' : ''}
                                    <span class="badge badge-${saleTypeClass}">${saleTypeLabel}</span>
                                </div>
                            </div>
                            <div class="qty-control">
                                <input type="number" class="qty-input" id="qty-input-${index}" value="${item.quantity.toFixed(3)}" step="0.01" min="0.01" data-index="${index}" data-max-stock="${item.max_stock}" onchange="updateQuantityFromInput(this)" onfocus="this.select()" onkeydown="if(event.key==='Enter'){this.blur();}">
                                <button type="button" class="btn-remove" onclick="removeItem(${index})">×</button>
                            </div>
                            <div class="item-total">KSh ${subtotalItem.toFixed(2)}</div>
                        </div>
                    `;
        });

        cartDiv.innerHTML = html;
        subtotalSpan.textContent = `KSh ${subtotal.toFixed(2)}`;
        document.getElementById('cartData').value = JSON.stringify(cart);
        calculatePayment();
        updateTotal(subtotal);
    }

    function updateTotal(subtotal) {
        const totalSpan = document.getElementById('cartTotal');
        const checkoutBtn = document.getElementById('checkoutBtn');

        if (subtotal === undefined) {
            subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        }

        let total = subtotal;

        if (cart.length > 0 && total > 0) {
            checkoutBtn.disabled = false;
        } else {
            checkoutBtn.disabled = true;
        }

        totalSpan.textContent = `KSh ${total.toFixed(2)}`;
    }

    function updateQuantityFromInput(input) {
        const index = parseInt(input.dataset.index);
        const maxStock = parseFloat(input.dataset.maxStock) || 9999;
        const newQty = parseFloat(input.value);

        if (isNaN(newQty) || newQty < 0.01) {
            alert('Please enter a valid quantity (minimum 0.01).');
            input.value = cart[index].quantity.toFixed(3);
            return;
        }

        if (newQty > maxStock) {
            alert('Not enough stock! Available: ' + maxStock.toFixed(3) + ' ' + cart[index].unit);
            input.value = cart[index].quantity.toFixed(3);
            return;
        }

        cart[index].quantity = parseFloat(newQty.toFixed(3));
        updateCart();
    }

    function removeItem(index) {
        cart.splice(index, 1);
        updateCart();
    }

    function updatePaymentFields() {
        const selected = document.querySelector('input[name="payment_method"]:checked');
        if (!selected) return;

        const method = selected.value;
        document.getElementById('paymentMethodInput').value = method;

        document.querySelectorAll('.payment-methods label').forEach(opt => {
            opt.classList.remove('active');
        });

        if (method === 'cash') {
            document.getElementById('cashOption').classList.add('active');
            document.getElementById('cashFields').style.display = 'flex';
            document.getElementById('bankFields').style.display = 'none';
            document.getElementById('mixedFields').style.display = 'none';
        } else if (method === 'bank') {
            document.getElementById('bankOption').classList.add('active');
            document.getElementById('cashFields').style.display = 'none';
            document.getElementById('bankFields').style.display = 'flex';
            document.getElementById('mixedFields').style.display = 'none';
        } else if (method === 'mixed') {
            document.getElementById('mixedOption').classList.add('active');
            document.getElementById('cashFields').style.display = 'none';
            document.getElementById('bankFields').style.display = 'none';
            document.getElementById('mixedFields').style.display = 'block';
        }

        if (method !== 'cash') {
            document.getElementById('cashReceived').value = '';
        }
        if (method !== 'bank') {
            document.getElementById('bankAmount').value = '';
        }
        if (method !== 'mixed') {
            document.getElementById('mixedCash').value = '';
            document.getElementById('mixedBank').value = '';
        }

        calculatePayment();
    }

    function calculatePayment() {
        const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        const selected = document.querySelector('input[name="payment_method"]:checked');

        if (!selected) return;

        const method = selected.value;
        let cash = 0;
        let bank = 0;
        let valid = false;
        let errorMessage = '';
        let successMessage = '';

        if (method === 'cash') {
            cash = parseFloat(document.getElementById('cashReceived').value) || 0;

            if (cart.length === 0) {
                errorMessage = 'Cart is empty. Add items first.';
            } else if (cash <= 0) {
                errorMessage = 'Please enter the cash amount received.';
            } else if (cash < total) {
                errorMessage = 'Cash received (KSh ' + cash.toFixed(2) + ') is less than the total (KSh ' + total.toFixed(
                    2) + ').';
            } else {
                const change = cash - total;
                document.getElementById('changeAmount').textContent = 'KSh ' + change.toFixed(2);
                valid = true;
                successMessage = 'Payment accepted. Change: KSh ' + change.toFixed(2);
            }
        } else if (method === 'bank') {
            bank = parseFloat(document.getElementById('bankAmount').value) || 0;

            if (cart.length === 0) {
                errorMessage = 'Cart is empty. Add items first.';
            } else if (bank <= 0) {
                errorMessage = 'Please enter the bank transfer amount.';
            } else if (bank !== total) {
                errorMessage = 'Bank transfer amount (KSh ' + bank.toFixed(2) + ') must equal the total (KSh ' + total
                    .toFixed(2) + ').';
            } else {
                valid = true;
                successMessage = 'Bank transfer accepted.';
            }
        } else if (method === 'mixed') {
            cash = parseFloat(document.getElementById('mixedCash').value) || 0;
            bank = parseFloat(document.getElementById('mixedBank').value) || 0;
            const paid = cash + bank;
            const balance = total - paid;

            document.getElementById('mixedPaid').textContent = 'KSh ' + paid.toFixed(2);
            document.getElementById('mixedBalance').textContent = 'KSh ' + balance.toFixed(2);
            document.getElementById('mixedBalance').style.color = balance >= 0 ? '#16a34a' : '#dc2626';

            if (cart.length === 0) {
                errorMessage = 'Cart is empty. Add items first.';
            } else if (cash < 0 || bank < 0) {
                errorMessage = 'Amounts cannot be negative.';
            } else if (cash === 0 && bank === 0) {
                errorMessage = 'Please enter at least one payment amount.';
            } else if (paid < total) {
                errorMessage = 'Total paid (KSh ' + paid.toFixed(2) + ') is less than the total (KSh ' + total.toFixed(
                    2) + ').';
            } else {
                valid = true;
                const change = paid - total;
                successMessage = 'Payment accepted. Change: KSh ' + change.toFixed(2);
            }
        }

        const errorDiv = document.getElementById('paymentError');
        const errorMsg = document.getElementById('paymentErrorMessage');
        const successDiv = document.getElementById('paymentSuccess');
        const successMsg = document.getElementById('paymentSuccessMessage');

        if (errorMessage) {
            errorDiv.className = 'payment-error show';
            errorMsg.textContent = errorMessage;
            successDiv.className = 'payment-success';
            successMsg.textContent = '';
        } else if (valid) {
            errorDiv.className = 'payment-error';
            errorMsg.textContent = '';
            successDiv.className = 'payment-success show';
            successMsg.textContent = successMessage;
        } else {
            errorDiv.className = 'payment-error';
            errorMsg.textContent = '';
            successDiv.className = 'payment-success';
            successMsg.textContent = '';
        }

        document.getElementById('cashAmountInput').value = cash.toFixed(2);
        document.getElementById('bankAmountInput').value = bank.toFixed(2);
        document.getElementById('checkoutBtn').disabled = !valid || cart.length === 0;
    }

    document.getElementById('searchProduct').addEventListener('keyup', function() {
        const search = this.value.toLowerCase();
        document.querySelectorAll('.product-btn').forEach(product => {
            const name = product.dataset.name.toLowerCase();
            product.style.display = name.includes(search) ? '' : 'none';
        });
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }

        if (e.key === 'Enter' && document.getElementById('modalOverlay').classList.contains('active')) {
            const addBtn = document.getElementById('modalAddBtn');
            if (!addBtn.disabled) {
                addToCartFromModal();
            }
        }
    });

    // ============================================================
    // HOLD SALE - FIXED
    // ============================================================
// ============================================================
// HOLD SALE - FIXED (Electron Compatible)
// ============================================================
// ============================================================
// HOLD SALE - FORCE DOM REFRESH FOR ELECTRON
// ============================================================

function holdSale() {
    if (cart.length === 0) {
        alert("Cart is empty. Nothing to hold.");
        return;
    }

    if (!confirm("Hold this sale? It will be saved and the cart will be cleared.")) {
        return;
    }

    const holdData = {
        cart: cart,
        customer_name: "Walk-in Customer"
    };

    const holdBtn = document.getElementById('holdSaleBtn');
    holdBtn.disabled = true;
    holdBtn.textContent = '⏳ Saving...';

    fetch("hold_sale.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify(holdData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Clear the cart
            cart = [];
            updateCart();

            // Reset payment fields
            document.getElementById('cashReceived').value = '';
            document.getElementById('bankAmount').value = '';
            document.getElementById('mixedCash').value = '';
            document.getElementById('mixedBank').value = '';
            document.getElementById('changeAmount').textContent = 'KSh 0.00';
            document.getElementById('mixedPaid').textContent = 'KSh 0.00';
            document.getElementById('mixedBalance').textContent = 'KSh 0.00';

            // Reset payment messages
            document.getElementById('paymentError').className = 'payment-error';
            document.getElementById('paymentSuccess').className = 'payment-success';
            document.getElementById('paymentSuccessMessage').textContent = 'Payment is valid. You can complete the sale.';

            // Reset hidden fields
            document.getElementById('cartData').value = '';
            document.getElementById('cashAmountInput').value = '0';
            document.getElementById('bankAmountInput').value = '0';

            // Reset checkout button
            const checkoutBtn = document.getElementById('checkoutBtn');
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = 'Complete Sale';

            // ============================================================
            // ELECTRON DOM REFRESH FIX
            // ============================================================

            // 1. Force modal overlay closed
            const modalOverlay = document.getElementById('modalOverlay');
            if (modalOverlay) {
                modalOverlay.classList.remove('active');
                modalOverlay.style.display = 'none';
            }

            // 2. Force re-enable all inputs with a DOM refresh
            const allInputs = document.querySelectorAll('input, select, textarea');
            allInputs.forEach(function(input) {
                // Store the value and id
                var value = input.value;
                var id = input.id;
                var className = input.className;
                
                // Create a replacement element
                var newInput = document.createElement(input.tagName);
                newInput.id = id;
                newInput.className = className;
                newInput.value = value;
                newInput.disabled = false;
                newInput.readOnly = false;
                newInput.style.pointerEvents = 'auto';
                newInput.style.opacity = '1';
                newInput.style.display = '';
                
                // Copy all attributes
                for (var i = 0; i < input.attributes.length; i++) {
                    var attr = input.attributes[i];
                    if (attr.name !== 'id' && attr.name !== 'class' && attr.name !== 'value') {
                        newInput.setAttribute(attr.name, attr.value);
                    }
                }
                
                // Replace the old input with the new one
                input.parentNode.replaceChild(newInput, input);
                
                // Re-attach event listeners for specific inputs
                if (id === 'cashReceived' || id === 'bankAmount' || 
                    id === 'mixedCash' || id === 'mixedBank') {
                    newInput.oninput = calculatePayment;
                }
            });

            // 3. Force re-enable buttons
            document.querySelectorAll('button').forEach(function(btn) {
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
                btn.style.opacity = '1';
            });

            // 4. Force a DOM reflow by toggling display
            var paymentFields = document.getElementById('paymentFields');
            if (paymentFields) {
                paymentFields.style.display = 'none';
                setTimeout(function() {
                    paymentFields.style.display = '';
                }, 10);
            }

            // 5. Force focus on search input after a delay
            setTimeout(function() {
                var searchInput = document.getElementById('searchProduct');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                    // Trigger a click event to ensure Electron registers focus
                    searchInput.click();
                }
                
                // Also try to focus on cash received
                var cashInput = document.getElementById('cashReceived');
                if (cashInput && cashInput.parentNode) {
                    // Just to ensure it's in the DOM and enabled
                    console.log('cashReceived exists and is enabled:', cashInput.disabled);
                }
            }, 100);

            // 6. Force a window resize to trigger re-render
            window.dispatchEvent(new Event('resize'));

            alert("✅ Sale held successfully!\nHold Number: " + data.hold_number);
        } else {
            alert("❌ Error: " + (data.message || 'Unknown error occurred.'));
        }
    })
    .catch(error => {
        console.error('Hold Sale Fetch Error:', error);
        alert("❌ Network error: " + error.message);
    })
    .finally(() => {
        holdBtn.disabled = false;
        holdBtn.textContent = '💾 Hold Sale';
    });
}

    // ============================================================
    // LOAD HELD SALES
    // ============================================================

    function loadHeldSales() {
        fetch("get_held_sales.php")
            .then(response => response.json())
            .then(data => {
                console.log("Held Sales Data:", data);

                if (!Array.isArray(data) || data.length === 0) {
                    alert("📂 No held sales found.");
                    return;
                }

                let html = `
                    <div class="held-sales-modal" id="heldSalesModal">
                        <div class="modal-content">
                            <h2 style="margin-bottom:20px;">📂 Held Sales</h2>
                            <div style="margin-bottom:15px;color:#6b7280;font-size:14px;">
                                ${data.length} sale(s) on hold
                            </div>
                `;

                data.forEach(sale => {
                    html += `
                        <div class="sale-item">
                            <div>
                                <div style="font-weight:bold;">${sale.hold_number}</div>
                                <div style="font-size:14px;color:#6b7280;">
                                    ${sale.customer_name || 'Walk-in Customer'} • ${sale.item_count || 0} items
                                </div>
                                <div style="font-size:13px;color:#9ca3af;">
                                    ${new Date(sale.hold_date).toLocaleString()}
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:bold;font-size:18px;">
                                    KSh ${parseFloat(sale.total_amount || 0).toFixed(2)}
                                </div>
                                <button class="btn-resume" onclick="resumeSale(${sale.id})">
                                    Resume
                                </button>
                            </div>
                        </div>
                    `;
                });

                html += `
                            <div style="display:flex;gap:10px;margin-top:20px;">
                                <button class="btn-close-modal" onclick="closeHeldSalesModal()">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                const existing = document.getElementById("heldSalesModal");
                if (existing) existing.remove();

                document.body.insertAdjacentHTML("beforeend", html);
            })
            .catch(error => {
                console.error("Load Held Sales Error:", error);
                alert("❌ Error loading held sales: " + error.message);
            });
    }

    function closeHeldSalesModal() {
        const modal = document.getElementById('heldSalesModal');
        if (modal) modal.remove();
    }

    function resumeSale(heldSaleId) {
        if (!confirm("Resume this sale? It will be loaded into the cart.")) {
            return;
        }
        window.location.href = "resume_sale.php?id=" + heldSaleId;
    }

    document.getElementById('saleForm').addEventListener('submit', function(e) {
        calculatePayment();

        if (document.getElementById('checkoutBtn').disabled) {
            e.preventDefault();
            const errorDiv = document.getElementById('paymentError');
            if (errorDiv.className.includes('show')) {
                alert('⚠️ ' + document.getElementById('paymentErrorMessage').textContent);
            } else {
                alert('⚠️ Please complete the payment details correctly.');
            }
        }
    });

    updatePaymentFields();

    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList && e.target.classList.contains('held-sales-modal')) {
            closeHeldSalesModal();
        }
    });

  <?php if ($resume_cart_json !== null): ?>
window.addEventListener('load', function () {

    // Close any leftover modal overlay
    const modalOverlay = document.getElementById('modalOverlay');

    if (modalOverlay) {
        modalOverlay.classList.remove('active');
    }

    const resumedItems = <?= $resume_cart_json ?>;

    if (!Array.isArray(resumedItems) || resumedItems.length === 0) {
        return;
    }

    cart = resumedItems.map(function (item) {
        return {
            id: parseInt(item.id),
            name: item.name,
            price: parseFloat(item.price),
            quantity: parseFloat(item.quantity),
            max_stock: 9999,
            unit: item.unit || 'pcs',
            sale_type: 'retail',
            has_discount: false,
            original_price: parseFloat(item.price),
            discount_label: ''
        };
    });

    updateCart();
    updatePaymentFields();

});
<?php endif; ?>
// ============================================================
// ELECTRON FIX: Force inputs to work when modal closes
// ============================================================

(function fixElectronInputs() {
    
    // Listen for any click on the modal overlay
    document.addEventListener('click', function(e) {
        const modalOverlay = document.getElementById('modalOverlay');
        if (modalOverlay && modalOverlay.classList.contains('active')) {
            // If user clicks outside modal, close it
            if (e.target === modalOverlay) {
                modalOverlay.classList.remove('active');
                // Force focus to search
                setTimeout(function() {
                    const search = document.getElementById('searchProduct');
                    if (search) search.focus();
                }, 50);
            }
        }
    });

    // Force inputs to work after hold
    const originalHold = window.holdSale;
    if (originalHold) {
        window.holdSale = function() {
            // Call original holdSale
            originalHold.apply(this, arguments);
            
            // After a delay, force all inputs to be interactive
            setTimeout(function() {
                document.querySelectorAll('input, select, textarea').forEach(function(el) {
                    el.disabled = false;
                    el.readOnly = false;
                    el.style.pointerEvents = 'auto';
                    el.style.opacity = '1';
                    el.style.display = '';
                    // Force reflow
                    el.style.zoom = '1';
                    el.style.zoom = '';
                });
                
                // Force modal to close
                const modal = document.getElementById('modalOverlay');
                if (modal) {
                    modal.classList.remove('active');
                    modal.style.display = 'none';
                }
                
                // Focus on search
                const search = document.getElementById('searchProduct');
                if (search) {
                    search.focus();
                    search.select();
                }
            }, 200);
        };
    }

})();
</script>

</body>

</html>
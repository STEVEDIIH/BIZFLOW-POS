<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION["user_id"], $_SESSION["business_id"])) {
    echo json_encode([]);
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];
$user_id = (int) $_SESSION["user_id"];

try {

    $stmt = $pdo->prepare("
        SELECT
            hs.id,
            hs.hold_number,
            hs.customer_name,
            hs.total_amount,
            hs.created_at AS hold_date,
            COUNT(hsi.id) AS item_count
        FROM held_sales hs
        LEFT JOIN held_sale_items hsi
            ON hsi.held_sale_id = hs.id
        WHERE hs.business_id = :business_id
        AND hs.user_id = :user_id
        GROUP BY
            hs.id,
            hs.hold_number,
            hs.customer_name,
            hs.total_amount,
            hs.created_at
        ORDER BY hs.created_at DESC
    ");

    $stmt->execute([
        ":business_id" => $business_id,
        ":user_id" => $user_id
    ]);

    $held_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return array directly (not wrapped in success/data)
    echo json_encode($held_sales);

} catch (PDOException $e) {
    echo json_encode([]);
}
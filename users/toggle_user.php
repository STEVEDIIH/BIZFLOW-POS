<?php
session_start();


if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("toggle_");
require_once "../config/database.php";

$business_id = $_SESSION["business_id"];
$current_user_id = $_SESSION["user_id"];

$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    header("Location: users.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Only Admin can activate/deactivate users
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
      AND u.business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ':user_id' => $current_user_id,
    ':business_id' => $business_id
]);

$current_user = $stmt->fetch();

if (!$current_user || strtolower($current_user['role_name']) !== 'admin') {
    die("Access denied. Only administrators can manage users.");
}

/*
|--------------------------------------------------------------------------
| Prevent admin from deactivating themselves
|--------------------------------------------------------------------------
*/

if ($user_id === $current_user_id) {
    die("You cannot deactivate your own account.");
}

/*
|--------------------------------------------------------------------------
| Get selected user
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, is_active
    FROM users
    WHERE id = :id
      AND business_id = :business_id
    LIMIT 1
");

$stmt->execute([
    ':id' => $user_id,
    ':business_id' => $business_id
]);

$user = $stmt->fetch();

if (!$user) {
    header("Location: users.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Toggle status
|--------------------------------------------------------------------------
*/

$new_status = $user['is_active'] ? 0 : 1;

$stmt = $pdo->prepare("
    UPDATE users
    SET is_active = :is_active
    WHERE id = :id
      AND business_id = :business_id
");

$stmt->execute([
    ':is_active' => $new_status,
    ':id' => $user_id,
    ':business_id' => $business_id
]);

header("Location: users.php");
exit;
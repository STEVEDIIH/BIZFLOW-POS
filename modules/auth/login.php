<?php

session_start();

require_once __DIR__ . "/../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../index.php");
    exit;
}

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

/*
|--------------------------------------------------------------------------
| Validate input
|--------------------------------------------------------------------------
*/

if ($username === "" || $password === "") {
    header("Location: ../../index.php?error=missing");
    exit;
}

/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
*/

$sql = "SELECT 
            users.id,
            users.business_id,
            users.role_id,
            users.username,
            users.password_hash,
            users.full_name,
            users.is_active,
            roles.name AS role_name,
            businesses.business_name
        FROM users
        INNER JOIN roles ON users.role_id = roles.id
        INNER JOIN businesses ON users.business_id = businesses.id
        WHERE users.username = :username
        LIMIT 1";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ":username" => $username
]);

$user = $stmt->fetch();

/*
|--------------------------------------------------------------------------
| Invalid username / inactive account
|--------------------------------------------------------------------------
*/

if (!$user || !$user["is_active"]) {
    header("Location: ../../index.php?error=invalid");
    exit;
}

/*
|--------------------------------------------------------------------------
| Wrong password
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user["password_hash"])) {
    header(
        "Location: ../../index.php?error=invalid&username=" .
        urlencode($username)
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Successful login
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["business_id"] = $user["business_id"];
$_SESSION["role_id"] = $user["role_id"];
$_SESSION["username"] = $user["username"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["role_name"] = $user["role_name"];
$_SESSION["business_name"] = $user["business_name"];

/*
|--------------------------------------------------------------------------
| Redirect according to role
|--------------------------------------------------------------------------
*/

$role = strtolower(trim($user["role_name"]));

if ($role === "admin") {
    header("Location: ../../dashboard.php");
    exit;
}

if ($role === "cashier") {
    header("Location: ../../cashier/dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Unknown role
|--------------------------------------------------------------------------
*/

$_SESSION = [];

session_destroy();

header("Location: ../../index.php?error=role");
exit;
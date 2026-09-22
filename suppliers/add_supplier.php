<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("add_supplier");
require_once "../config/database.php";

$business_id = $_SESSION["business_id"];
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    $stmt = $pdo->prepare("
        INSERT INTO suppliers (business_id, name, phone, email, address)
        VALUES (:business_id, :name, :phone, :email, :address)
    ");

    try {
        $stmt->execute([
            ':business_id' => $business_id,
            ':name' => $name,
            ':phone' => $phone,
            ':email' => $email,
            ':address' => $address
        ]);
        $success = "Supplier added successfully!";
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Supplier - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app">
    <?php include '../assets/includes/sidebar.php'; ?>
    
    <main class="main">
        <?php include '../assets/includes/topbar.php'; ?>
        
        <section class="content">
            <div class="page-title">
                <h1>Add Supplier</h1>
                <a href="suppliers.php" class="btn-secondary">← Back to Suppliers</a>
            </div>

            <?php if($error): ?>
                <div class="alert alert-error"><?= $error ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST">
                    <div class="form-group">
                        <label>Supplier Name *</label>
                        <input type="text" name="name" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone">
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address">
                    </div>

                    <button type="submit" class="btn-primary">Add Supplier</button>
                </form>
            </div>
        </section>
    </main>
</div>
</body>
</html>
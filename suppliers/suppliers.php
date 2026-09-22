<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../index.php");
    exit;
}
require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = $_SESSION["business_id"];

$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE business_id = :business_id ORDER BY name");
$stmt->execute([':business_id' => $business_id]);
$suppliers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app">
    <?php include '../assets/includes/sidebar.php'; ?>
    
    <main class="main">
        <?php include '../assets/includes/topbar.php'; ?>
        
        <section class="content">
            <div class="page-title">
                <h1>Suppliers</h1>
                <a href="add_supplier.php" class="btn-primary">+ Add Supplier</a>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($suppliers as $supplier): ?>
                        <tr>
                            <td><?= $supplier['id'] ?></td>
                            <td><?= htmlspecialchars($supplier['name']) ?></td>
                            <td><?= htmlspecialchars($supplier['phone'] ?? '') ?></td>
                            <td><?= htmlspecialchars($supplier['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars($supplier['address'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
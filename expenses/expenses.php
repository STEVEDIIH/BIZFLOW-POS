<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../index.php");
    exit;
}
require_once "../config/database.php";

$business_id = $_SESSION["business_id"];
$today = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT e.*, u.full_name as created_by 
    FROM expenses e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.business_id = :business_id
    ORDER BY e.expense_date DESC
");
$stmt->execute([':business_id' => $business_id]);
$expenses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_today FROM expenses WHERE business_id = :business_id AND DATE(expense_date) = :today");
$stmt->execute([':business_id' => $business_id, ':today' => $today]);
$total_today = $stmt->fetch()['total_today'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_month FROM expenses WHERE business_id = :business_id AND MONTH(expense_date) = MONTH(CURRENT_DATE) AND YEAR(expense_date) = YEAR(CURRENT_DATE)");
$stmt->execute([':business_id' => $business_id]);
$total_month = $stmt->fetch()['total_month'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses - BizFlow</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app">
    <?php include '../assets/includes/sidebar.php'; ?>
    
    <main class="main">
        <?php include '../assets/includes/topbar.php'; ?>
        
        <section class="content">
            <div class="page-title">
                <h1>Expenses</h1>
                <a href="add_expense.php" class="btn-primary">+ Record Expense</a>
            </div>

            <div class="cards" style="grid-template-columns: repeat(2, 1fr);">
                <div class="card">
                    <div class="card-title">Today's Expenses</div>
                    <div class="card-value">KSh <?= number_format($total_today, 2) ?></div>
                </div>
                <div class="card">
                    <div class="card-title">This Month's Expenses</div>
                    <div class="card-value">KSh <?= number_format($total_month, 2) ?></div>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($expenses as $expense): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($expense['expense_date'])) ?></td>
                            <td><?= htmlspecialchars($expense['category']) ?></td>
                            <td><?= htmlspecialchars($expense['description'] ?? '') ?></td>
                            <td>KSh <?= number_format($expense['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($expense['created_by'] ?? '') ?></td>
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
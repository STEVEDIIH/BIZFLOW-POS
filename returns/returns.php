<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = (int) $_SESSION["business_id"];


/*
|--------------------------------------------------------------------------
| Get all returns for this business
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.return_number,
        r.sale_id,
        r.refund_amount,
        r.reason,
        r.status,
        r.created_at,
        s.receipt_number,
        u.full_name
    FROM returns r
    LEFT JOIN sales s
        ON r.sale_id = s.id
    LEFT JOIN users u
        ON r.returned_by = u.id
    WHERE r.business_id = :business_id
    ORDER BY r.id DESC
");

$stmt->execute([
    ':business_id' => $business_id
]);

$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);



/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$total_returns = count($returns);

$total_refunds = 0;

foreach ($returns as $return) {
    $total_refunds += (float) $return['refund_amount'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Returns & Exchanges - BizFlow</title>

<link rel="stylesheet" href="../assets/css/style.css">

</head>


<body>

<div class="app">


<?php include "../assets/includes/sidebar.php"; ?>


<main class="main">


<?php include "../assets/includes/topbar.php"; ?>


<section class="content">


<div class="page-title">

<div>

<h1>
Returns & Exchanges
</h1>

<p>
Manage customer returns, refunds and product exchanges.
</p>

</div>


<a href="process_return.php" class="btn-primary">
+ Process Return
</a>


</div>



<div class="cards">


<div class="card">

<div class="card-title">
Total Returns
</div>

<div class="card-value">
<?= $total_returns ?>
</div>

</div>



<div class="card">

<div class="card-title">
Total Refunds
</div>

<div class="card-value">

KSh
<?= number_format($total_refunds, 2) ?>

</div>

</div>


</div>




<div class="table-container">


<table class="data-table">


<thead>

<tr>

<th>Return No.</th>
<th>Receipt</th>
<th>Refund</th>
<th>Reason</th>
<th>Processed By</th>
<th>Status</th>
<th>Date</th>
<th>Action</th>

</tr>

</thead>


<tbody>


<?php if (!empty($returns)): ?>


<?php foreach ($returns as $return): ?>


<tr>


<td>

<strong>
<?= htmlspecialchars($return['return_number'] ?? '-') ?>
</strong>

</td>



<td>

<?= htmlspecialchars($return['receipt_number'] ?? '-') ?>

</td>




<td>

<strong>

KSh
<?= number_format((float)$return['refund_amount'], 2) ?>

</strong>

</td>




<td>

<?= htmlspecialchars($return['reason'] ?? '-') ?>

</td>




<td>

<?= htmlspecialchars($return['full_name'] ?? 'Unknown') ?>

</td>




<td>


<?php if ($return['status'] === 'completed'): ?>


<span class="badge badge-success">
Completed
</span>


<?php elseif ($return['status'] === 'Pending' || $return['status'] === 'pending'): ?>


<span class="badge badge-warning">
Pending
</span>


<?php else: ?>


<span class="badge badge-danger">
Cancelled
</span>


<?php endif; ?>


</td>




<td>

<?= date(
    'd M Y H:i',
    strtotime($return['created_at'])
) ?>

</td>




<td>

<a
href="view_returns.php?id=<?= (int)$return['id'] ?>"
class="btn-edit"
>
View
</a>

</td>



</tr>


<?php endforeach; ?>



<?php else: ?>


<tr>

<td colspan="8" style="text-align:center;padding:40px;color:#777;">

<strong>
No returns recorded yet.
</strong>

<br>

<span style="display:block;margin-top:8px;">

Click
<strong>
+ Process Return
</strong>
above to record a customer return.

</span>

</td>

</tr>


<?php endif; ?>


</tbody>


</table>


</div>


</section>


</main>


</div>


</body>

</html>
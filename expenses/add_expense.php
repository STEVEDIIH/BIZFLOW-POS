<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";
require_once "../assets/includes/sync_helper.php";

$business_id = (int)$_SESSION["business_id"];
$user_id = (int)$_SESSION["user_id"];

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $expense_date_input = $_POST['expense_date'] ?? '';

    /*
     * ---------------------------------------------------------
     * VALIDATION
     * ---------------------------------------------------------
     */

    if ($category === '') {

        $error = "Please enter an expense category.";

    } elseif (
        $amount === '' ||
        !is_numeric($amount) ||
        (float)$amount <= 0
    ) {

        $error = "Please enter a valid expense amount.";

    } else {

        /*
         * ---------------------------------------------------------
         * CONVERT DATETIME
         * ---------------------------------------------------------
         */

        if ($expense_date_input !== '') {

            $timestamp = strtotime($expense_date_input);

            if ($timestamp === false) {
                $error = "Please enter a valid expense date and time.";
            } else {
                $expense_date = date(
                    'Y-m-d H:i:s',
                    $timestamp
                );
            }

        } else {

            $expense_date = date('Y-m-d H:i:s');
        }


        /*
         * ---------------------------------------------------------
         * SAVE EXPENSE + QUEUE SYNC
         * ---------------------------------------------------------
         */

        if ($error === '') {

            try {

                /*
                 * Everything below must succeed together.
                 */
                $pdo->beginTransaction();


                /*
                 * Insert local expense.
                 */
                $stmt = $pdo->prepare("
                    INSERT INTO expenses
                    (
                        business_id,
                        user_id,
                        category,
                        description,
                        amount,
                        expense_date
                    )
                    VALUES
                    (
                        :business_id,
                        :user_id,
                        :category,
                        :description,
                        :amount,
                        :expense_date
                    )
                ");

                $stmt->execute([
                    ':business_id' => $business_id,
                    ':user_id' => $user_id,
                    ':category' => $category,
                    ':description' => $description !== ''
                        ? $description
                        : null,
                    ':amount' => (float)$amount,
                    ':expense_date' => $expense_date
                ]);


                /*
                 * Get the newly-created local expense ID.
                 */
                $expenseId = (int)$pdo->lastInsertId();

                if ($expenseId <= 0) {
                    throw new RuntimeException(
                        'Unable to determine the newly created expense ID.'
                    );
                }


                /*
                 * Read the exact saved record.
                 *
                 * We queue the database record rather than the
                 * original POST values.
                 */
                $expenseStmt = $pdo->prepare("
                    SELECT
                        id,
                        business_id,
                        user_id,
                        category,
                        description,
                        amount,
                        expense_date
                    FROM expenses
                    WHERE id = :id
                      AND business_id = :business_id
                    LIMIT 1
                ");

                $expenseStmt->execute([
                    ':id' => $expenseId,
                    ':business_id' => $business_id
                ]);

                $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);

                if (!$expense) {
                    throw new RuntimeException(
                        'Expense was created but could not be loaded for synchronization.'
                    );
                }


                /*
                 * Queue LOCAL → CLOUD synchronization.
                 */
                $queued = addToSyncQueue(
                    $pdo,
                    'expense',
                    $expenseId,
                    'create',
                    buildExpenseSyncPayload($expense)
                );

                if (!$queued) {
                    throw new RuntimeException(
                        'Expense was saved but could not be added to the synchronization queue.'
                    );
                }


                /*
                 * Commit both the expense and its sync event.
                 */
                $pdo->commit();

                $success = "Expense recorded successfully!";


            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "Unable to record expense: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Record Expense - BizFlow</title>

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
                    <h1>Record Expense</h1>
                    <p>Record a business expense.</p>
                </div>

                <a href="expenses.php" class="btn-secondary">
                    ← Back to Expenses
                </a>

            </div>


            <?php if ($error): ?>

                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <?php if ($success): ?>

                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>

            <?php endif; ?>


            <div class="form-container">

                <form method="POST">

                    <div class="form-group">

                        <label>Category *</label>

                        <input
                            type="text"
                            name="category"
                            placeholder="e.g., Rent, Utilities, Salaries"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Description</label>

                        <input
                            type="text"
                            name="description"
                            placeholder="Brief description of the expense"
                        >

                    </div>


                    <div class="form-row">

                        <div class="form-group">

                            <label>Amount (KSh) *</label>

                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                name="amount"
                                placeholder="0.00"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>Date & Time</label>

                            <input
                                type="datetime-local"
                                name="expense_date"
                                value="<?= date('Y-m-d\TH:i') ?>"
                            >

                        </div>

                    </div>


                    <button
                        type="submit"
                        class="btn-primary"
                    >
                        Record Expense
                    </button>

                </form>

            </div>

        </section>

    </main>

</div>

</body>

</html>
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";

$business_id = (int) ($_SESSION["business_id"] ?? 0);
$admin_id    = (int) ($_SESSION["user_id"] ?? 0);

/* GET USER ROLE */
$stmt = $pdo->prepare("
    SELECT r.name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :user_id
");
$stmt->execute([":user_id" => $admin_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_role = strtolower(trim($current_user["name"] ?? ""));

$allowed_roles = ["admin", "administrator", "owner", "manager"];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(403);
    die("<div style='font-family:Arial;padding:40px;text-align:center'><h2>Access Denied</h2><p>You do not have permission to access this report.</p><a href='../dashboard.php'>Return to Dashboard</a></div>");
}

/* REPORT PERIOD */
$period = $_GET["period"] ?? "daily";
if (!in_array($period, ["daily", "monthly", "quarterly"], true)) {
    $period = "daily";
}

$selected_date = $_GET["date"] ?? date("Y-m-d");
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $selected_date)) {
    $selected_date = date("Y-m-d");
}

$selected_month = $_GET["month"] ?? date("Y-m", strtotime($selected_date));
if (!preg_match("/^\d{4}-\d{2}$/", $selected_month)) {
    $selected_month = date("Y-m", strtotime($selected_date));
}

$selected_quarter = $_GET["quarter"] ?? (date("Y", strtotime($selected_date)) . "-Q" . ceil((int)date("n", strtotime($selected_date)) / 3));
if (!preg_match("/^\d{4}-Q[1-4]$/", $selected_quarter)) {
    $selected_quarter = date("Y", strtotime($selected_date)) . "-Q" . ceil((int)date("n", strtotime($selected_date)) / 3);
}

function money($amount) {
    return "KSh " . number_format((float)$amount, 2);
}

function differenceLabel($difference) {
    if ($difference === null) return "Not Counted";
    if ($difference < 0) return "Shortage / Loss";
    if ($difference > 0) return "Surplus / Excess";
    return "Balanced";
}

function qDate($date) {
    return date("d F Y", strtotime($date));
}

/*
|--------------------------------------------------------------------------
| CREATE TABLES
|--------------------------------------------------------------------------
*/
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_reconciliations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        cashier_id INT NOT NULL,
        reconciliation_date DATE NOT NULL,
        system_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        actual_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_bank DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        resolution VARCHAR(50) NULL,
        notes TEXT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cashier_date (business_id, cashier_id, reconciliation_date)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_reconciliations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        cashier_id INT NOT NULL,
        reconciliation_month DATE NOT NULL,
        system_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        actual_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_bank DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        resolution VARCHAR(50) NULL,
        notes TEXT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cashier_month (business_id, cashier_id, reconciliation_month)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quarterly_reconciliations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        cashier_id INT NOT NULL,
        reconciliation_quarter VARCHAR(7) NOT NULL,
        system_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        actual_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        collected_bank DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        resolution VARCHAR(50) NULL,
        notes TEXT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cashier_quarter (business_id, cashier_id, reconciliation_quarter)
    )");

    // Add missing columns if they don't exist
    $cols = $pdo->query("SHOW COLUMNS FROM daily_reconciliations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array("collected_cash", $cols, true)) {
        $pdo->exec("ALTER TABLE daily_reconciliations ADD COLUMN collected_cash DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER difference");
    }
    if (!in_array("collected_bank", $cols, true)) {
        $pdo->exec("ALTER TABLE daily_reconciliations ADD COLUMN collected_bank DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER collected_cash");
    }

} catch (Exception $e) {
    /* Do not stop if tables already exist */
}

/*
|--------------------------------------------------------------------------
| SAVE RECONCILIATION
|--------------------------------------------------------------------------
*/
$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // ============================================================
    // DAILY RECONCILIATION
    // ============================================================
    if ($action === "save_reconciliation") {
        $cashier_id = (int) ($_POST["cashier_id"] ?? 0);
        $reconciliation_date = $_POST["reconciliation_date"] ?? $selected_date;
        $actual_cash = (float) ($_POST["actual_cash"] ?? 0); // This is now "Total Money Counted"
        $collected_cash = (float) ($_POST["collected_cash"] ?? 0);
        $collected_bank = (float) ($_POST["collected_bank"] ?? 0);
        $resolution = trim($_POST["resolution"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($cashier_id <= 0 || $actual_cash < 0 || $collected_cash < 0 || $collected_bank < 0) {
            $message = "Invalid input. Please check all fields.";
            $message_type = "error";
        } else {
            // Calculate Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN payment_method IN ('cash','mixed') THEN cash_amount - change_given ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method IN ('bank','mixed') THEN bank_amount ELSE 0 END),0) AS bank_sales
                FROM sales
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND sale_status = 'completed'
                  AND DATE(sale_date) = :reconciliation_date
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":reconciliation_date" => $reconciliation_date]);
            $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $cash_sales = (float)($sales_data["cash_sales"] ?? 0);
            $bank_sales = (float)($sales_data["bank_sales"] ?? 0);

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id = :business_id AND user_id = :cashier_id AND DATE(expense_date) = :reconciliation_date");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":reconciliation_date" => $reconciliation_date]);
            $expenses = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM returns WHERE business_id = :business_id AND user_id = :cashier_id AND status = 'completed' AND DATE(created_at) = :reconciliation_date");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":reconciliation_date" => $reconciliation_date]);
            $refunds = (float)$stmt->fetchColumn();

            // Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
            $system_cash = $cash_sales + $bank_sales - $expenses - $refunds;
            $difference = $actual_cash - $system_cash;
            $status = $resolution === "" ? "pending" : "resolved";

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_reconciliations
                    (business_id, cashier_id, reconciliation_date, system_cash, actual_cash, difference,
                     collected_cash, collected_bank, status, resolution, notes, approved_by, approved_at)
                    VALUES
                    (:business_id, :cashier_id, :reconciliation_date, :system_cash, :actual_cash, :difference,
                     :collected_cash, :collected_bank, :status, :resolution, :notes, :approved_by, :approved_at)
                    ON DUPLICATE KEY UPDATE
                        system_cash = VALUES(system_cash),
                        actual_cash = VALUES(actual_cash),
                        difference = VALUES(difference),
                        collected_cash = VALUES(collected_cash),
                        collected_bank = VALUES(collected_bank),
                        status = VALUES(status),
                        resolution = VALUES(resolution),
                        notes = VALUES(notes),
                        approved_by = VALUES(approved_by),
                        approved_at = VALUES(approved_at)
                ");
                $stmt->execute([
                    ":business_id" => $business_id,
                    ":cashier_id" => $cashier_id,
                    ":reconciliation_date" => $reconciliation_date,
                    ":system_cash" => $system_cash,
                    ":actual_cash" => $actual_cash,
                    ":difference" => $difference,
                    ":collected_cash" => $collected_cash,
                    ":collected_bank" => $collected_bank,
                    ":status" => $status,
                    ":resolution" => $resolution !== "" ? $resolution : null,
                    ":notes" => $notes !== "" ? $notes : null,
                    ":approved_by" => $resolution !== "" ? $admin_id : null,
                    ":approved_at" => $resolution !== "" ? date("Y-m-d H:i:s") : null
                ]);

                $message = "Daily reconciliation saved for " . qDate($reconciliation_date);
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // ============================================================
    // MONTHLY RECONCILIATION
    // ============================================================
    elseif ($action === "save_monthly_reconciliation") {
        $cashier_id = (int) ($_POST["cashier_id"] ?? 0);
        $reconciliation_month = $_POST["reconciliation_month"] ?? $selected_month . "-01";
        $actual_cash = (float) ($_POST["actual_cash"] ?? 0); // This is now "Total Money Counted"
        $collected_cash = (float) ($_POST["collected_cash"] ?? 0);
        $collected_bank = (float) ($_POST["collected_bank"] ?? 0);
        $resolution = trim($_POST["resolution"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($cashier_id <= 0 || $actual_cash < 0 || $collected_cash < 0 || $collected_bank < 0) {
            $message = "Invalid input. Please check all fields.";
            $message_type = "error";
        } else {
            $month_start = $reconciliation_month;
            $month_end = date("Y-m-t", strtotime($month_start));

            // Calculate monthly system cash: Cash Sales + Bank Sales - Expenses - Returns
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN payment_method IN ('cash','mixed') THEN cash_amount - change_given ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method IN ('bank','mixed') THEN bank_amount ELSE 0 END),0) AS bank_sales
                FROM sales
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND sale_status = 'completed'
                  AND DATE(sale_date) BETWEEN :month_start AND :month_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":month_start" => $month_start, ":month_end" => $month_end]);
            $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $cash_sales = (float)($sales_data["cash_sales"] ?? 0);
            $bank_sales = (float)($sales_data["bank_sales"] ?? 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount),0)
                FROM expenses
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND DATE(expense_date) BETWEEN :month_start AND :month_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":month_start" => $month_start, ":month_end" => $month_end]);
            $expenses = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(refund_amount),0)
                FROM returns
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND status = 'completed'
                  AND DATE(created_at) BETWEEN :month_start AND :month_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":month_start" => $month_start, ":month_end" => $month_end]);
            $refunds = (float)$stmt->fetchColumn();

            // Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
            $system_cash = $cash_sales + $bank_sales - $expenses - $refunds;
            $difference = $actual_cash - $system_cash;
            $status = $resolution === "" ? "pending" : "resolved";

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO monthly_reconciliations
                    (business_id, cashier_id, reconciliation_month, system_cash, actual_cash, difference,
                     collected_cash, collected_bank, status, resolution, notes, approved_by, approved_at)
                    VALUES
                    (:business_id, :cashier_id, :reconciliation_month, :system_cash, :actual_cash, :difference,
                     :collected_cash, :collected_bank, :status, :resolution, :notes, :approved_by, :approved_at)
                    ON DUPLICATE KEY UPDATE
                        system_cash = VALUES(system_cash),
                        actual_cash = VALUES(actual_cash),
                        difference = VALUES(difference),
                        collected_cash = VALUES(collected_cash),
                        collected_bank = VALUES(collected_bank),
                        status = VALUES(status),
                        resolution = VALUES(resolution),
                        notes = VALUES(notes),
                        approved_by = VALUES(approved_by),
                        approved_at = VALUES(approved_at)
                ");
                $stmt->execute([
                    ":business_id" => $business_id,
                    ":cashier_id" => $cashier_id,
                    ":reconciliation_month" => $month_start,
                    ":system_cash" => $system_cash,
                    ":actual_cash" => $actual_cash,
                    ":difference" => $difference,
                    ":collected_cash" => $collected_cash,
                    ":collected_bank" => $collected_bank,
                    ":status" => $status,
                    ":resolution" => $resolution !== "" ? $resolution : null,
                    ":notes" => $notes !== "" ? $notes : null,
                    ":approved_by" => $resolution !== "" ? $admin_id : null,
                    ":approved_at" => $resolution !== "" ? date("Y-m-d H:i:s") : null
                ]);

                $message = "Monthly reconciliation saved for " . date("F Y", strtotime($month_start));
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // ============================================================
    // QUARTERLY RECONCILIATION
    // ============================================================
    elseif ($action === "save_quarterly_reconciliation") {
        $cashier_id = (int) ($_POST["cashier_id"] ?? 0);
        $reconciliation_quarter = $_POST["reconciliation_quarter"] ?? $selected_quarter;
        $actual_cash = (float) ($_POST["actual_cash"] ?? 0); // This is now "Total Money Counted"
        $collected_cash = (float) ($_POST["collected_cash"] ?? 0);
        $collected_bank = (float) ($_POST["collected_bank"] ?? 0);
        $resolution = trim($_POST["resolution"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($cashier_id <= 0 || $actual_cash < 0 || $collected_cash < 0 || $collected_bank < 0) {
            $message = "Invalid input. Please check all fields.";
            $message_type = "error";
        } else {
            [$qy, $qq] = explode("-Q", $reconciliation_quarter);
            $qm = ((int)$qq - 1) * 3 + 1;
            $quarter_start = sprintf("%04d-%02d-01", (int)$qy, $qm);
            $quarter_end = date("Y-m-t", strtotime("+2 months", strtotime($quarter_start)));

            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN payment_method IN ('cash','mixed') THEN cash_amount - change_given ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method IN ('bank','mixed') THEN bank_amount ELSE 0 END),0) AS bank_sales
                FROM sales
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND sale_status = 'completed'
                  AND DATE(sale_date) BETWEEN :quarter_start AND :quarter_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":quarter_start" => $quarter_start, ":quarter_end" => $quarter_end]);
            $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $cash_sales = (float)($sales_data["cash_sales"] ?? 0);
            $bank_sales = (float)($sales_data["bank_sales"] ?? 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount),0)
                FROM expenses
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND DATE(expense_date) BETWEEN :quarter_start AND :quarter_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":quarter_start" => $quarter_start, ":quarter_end" => $quarter_end]);
            $expenses = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(refund_amount),0)
                FROM returns
                WHERE business_id = :business_id
                  AND user_id = :cashier_id
                  AND status = 'completed'
                  AND DATE(created_at) BETWEEN :quarter_start AND :quarter_end
            ");
            $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":quarter_start" => $quarter_start, ":quarter_end" => $quarter_end]);
            $refunds = (float)$stmt->fetchColumn();

            // Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
            $system_cash = $cash_sales + $bank_sales - $expenses - $refunds;
            $difference = $actual_cash - $system_cash;
            $status = $resolution === "" ? "pending" : "resolved";

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO quarterly_reconciliations
                    (business_id, cashier_id, reconciliation_quarter, system_cash, actual_cash, difference,
                     collected_cash, collected_bank, status, resolution, notes, approved_by, approved_at)
                    VALUES
                    (:business_id, :cashier_id, :reconciliation_quarter, :system_cash, :actual_cash, :difference,
                     :collected_cash, :collected_bank, :status, :resolution, :notes, :approved_by, :approved_at)
                    ON DUPLICATE KEY UPDATE
                        system_cash = VALUES(system_cash),
                        actual_cash = VALUES(actual_cash),
                        difference = VALUES(difference),
                        collected_cash = VALUES(collected_cash),
                        collected_bank = VALUES(collected_bank),
                        status = VALUES(status),
                        resolution = VALUES(resolution),
                        notes = VALUES(notes),
                        approved_by = VALUES(approved_by),
                        approved_at = VALUES(approved_at)
                ");
                $stmt->execute([
                    ":business_id" => $business_id,
                    ":cashier_id" => $cashier_id,
                    ":reconciliation_quarter" => $reconciliation_quarter,
                    ":system_cash" => $system_cash,
                    ":actual_cash" => $actual_cash,
                    ":difference" => $difference,
                    ":collected_cash" => $collected_cash,
                    ":collected_bank" => $collected_bank,
                    ":status" => $status,
                    ":resolution" => $resolution !== "" ? $resolution : null,
                    ":notes" => $notes !== "" ? $notes : null,
                    ":approved_by" => $resolution !== "" ? $admin_id : null,
                    ":approved_at" => $resolution !== "" ? date("Y-m-d H:i:s") : null
                ]);

                $message = "Quarterly reconciliation saved for " . $reconciliation_quarter;
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DATE RANGE
|--------------------------------------------------------------------------
*/
if ($period === "daily") {
    $range_start = $selected_date;
    $range_end = $selected_date;
    $period_title = qDate($selected_date);
} elseif ($period === "monthly") {
    $range_start = $selected_month . "-01";
    $range_end = date("Y-m-t", strtotime($range_start));
    $period_title = date("F Y", strtotime($range_start));
} else {
    [$qy, $qq] = explode("-Q", $selected_quarter);
    $qm = ((int)$qq - 1) * 3 + 1;
    $range_start = sprintf("%04d-%02d-01", (int)$qy, $qm);
    $range_end = date("Y-m-t", strtotime("+2 months", strtotime($range_start)));
    $period_title = "Q" . $qq . " " . $qy;
}

/*
|--------------------------------------------------------------------------
| GET CASHIERS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name
    FROM users u
    WHERE u.business_id = :business_id
      AND u.role_id IN (SELECT id FROM roles WHERE name = 'cashier')
    ORDER BY u.full_name ASC
");
$stmt->execute([":business_id" => $business_id]);
$cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| BUILD REPORT
|--------------------------------------------------------------------------
*/
$daily_report = [];
$grand_sales = 0;
$grand_cash_sales = 0;
$grand_bank_sales = 0;
$grand_expenses = 0;
$grand_returns = 0;
$grand_expected_money = 0;
$grand_total_counted = 0;
$grand_difference = 0;
$grand_collected_cash = 0;
$grand_collected_bank = 0;
$total_transactions = 0;
$total_shortage = 0;
$total_excess = 0;

foreach ($cashiers as $cashier) {
    $cashier_id = (int)$cashier["id"];

    // Get sales data for the period
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(total_amount),0) AS total_sales,
            COUNT(*) AS transactions,
            COALESCE(SUM(CASE WHEN payment_method IN ('cash','mixed') THEN cash_amount - change_given ELSE 0 END),0) AS cash_sales,
            COALESCE(SUM(CASE WHEN payment_method IN ('bank','mixed') THEN bank_amount ELSE 0 END),0) AS bank_sales
        FROM sales
        WHERE business_id = :business_id
          AND user_id = :cashier_id
          AND sale_status = 'completed'
          AND DATE(sale_date) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":start_date" => $range_start, ":end_date" => $range_end]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_sales = (float)($sales["total_sales"] ?? 0);
    $transactions = (int)($sales["transactions"] ?? 0);
    $cash_sales = (float)($sales["cash_sales"] ?? 0);
    $bank_sales = (float)($sales["bank_sales"] ?? 0);

    // Get expenses
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM expenses
        WHERE business_id = :business_id
          AND user_id = :cashier_id
          AND DATE(expense_date) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":start_date" => $range_start, ":end_date" => $range_end]);
    $expenses = (float)$stmt->fetchColumn();

    // Get returns
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(refund_amount),0)
        FROM returns
        WHERE business_id = :business_id
          AND user_id = :cashier_id
          AND status = 'completed'
          AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([":business_id" => $business_id, ":cashier_id" => $cashier_id, ":start_date" => $range_start, ":end_date" => $range_end]);
    $refunds = (float)$stmt->fetchColumn();

    // Total System Expected Money = Cash Sales + Bank Sales - Expenses - Returns
    // ALWAYS calculated fresh from transaction data - NEVER override with saved system_cash
    $expected_money = $cash_sales + $bank_sales - $expenses - $refunds;

    // Get saved reconciliation data (for manual fields only)
    $total_counted = null;
    $difference = null;
    $status = "pending";
    $resolution = "";
    $notes = "";
    $collected_cash = 0;
    $collected_bank = 0;

    if ($period === "daily") {
        $stmt = $pdo->prepare("SELECT status, resolution, notes, collected_cash, collected_bank, actual_cash FROM daily_reconciliations WHERE business_id = :b AND cashier_id = :c AND reconciliation_date = :d LIMIT 1");
        $stmt->execute([":b" => $business_id, ":c" => $cashier_id, ":d" => $selected_date]);
        $recon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($recon) {
            $status = $recon["status"] ?? "pending";
            $resolution = $recon["resolution"] ?? "";
            $notes = $recon["notes"] ?? "";
            $collected_cash = (float)($recon["collected_cash"] ?? 0);
            $collected_bank = (float)($recon["collected_bank"] ?? 0);
            $total_counted = (float)($recon["actual_cash"] ?? 0);
            // Recalculate difference using fresh expected_money
            $difference = $total_counted - $expected_money;
        }
    } elseif ($period === "monthly") {
        $stmt = $pdo->prepare("SELECT status, resolution, notes, collected_cash, collected_bank, actual_cash FROM monthly_reconciliations WHERE business_id = :b AND cashier_id = :c AND reconciliation_month = :m LIMIT 1");
        $stmt->execute([":b" => $business_id, ":c" => $cashier_id, ":m" => $range_start]);
        $recon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($recon) {
            $status = $recon["status"] ?? "pending";
            $resolution = $recon["resolution"] ?? "";
            $notes = $recon["notes"] ?? "";
            $collected_cash = (float)($recon["collected_cash"] ?? 0);
            $collected_bank = (float)($recon["collected_bank"] ?? 0);
            $total_counted = (float)($recon["actual_cash"] ?? 0);
            // Recalculate difference using fresh expected_money
            $difference = $total_counted - $expected_money;
            // DO NOT override $expected_money with saved system_cash
        }
    } else {
        $stmt = $pdo->prepare("SELECT status, resolution, notes, collected_cash, collected_bank, actual_cash FROM quarterly_reconciliations WHERE business_id = :b AND cashier_id = :c AND reconciliation_quarter = :q LIMIT 1");
        $stmt->execute([":b" => $business_id, ":c" => $cashier_id, ":q" => $selected_quarter]);
        $recon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($recon) {
            $status = $recon["status"] ?? "pending";
            $resolution = $recon["resolution"] ?? "";
            $notes = $recon["notes"] ?? "";
            $collected_cash = (float)($recon["collected_cash"] ?? 0);
            $collected_bank = (float)($recon["collected_bank"] ?? 0);
            $total_counted = (float)($recon["actual_cash"] ?? 0);
            // Recalculate difference using fresh expected_money
            $difference = $total_counted - $expected_money;
            // DO NOT override $expected_money with saved system_cash
        }
    }

    // Track shortages and excess
    if ($difference !== null) {
        if ($difference < 0) {
            $total_shortage += abs($difference);
        } elseif ($difference > 0) {
            $total_excess += $difference;
        }
    }

    $daily_report[] = [
        "id" => $cashier_id,
        "name" => $cashier["full_name"],
        "sales" => $total_sales,
        "transactions" => $transactions,
        "cash_sales" => $cash_sales,
        "bank_sales" => $bank_sales,
        "expenses" => $expenses,
        "refunds" => $refunds,
        "expected_money" => $expected_money,
        "total_counted" => $total_counted,
        "difference" => $difference,
        "status" => $status,
        "resolution" => $resolution,
        "notes" => $notes,
        "collected_cash" => $collected_cash,
        "collected_bank" => $collected_bank
    ];

    $grand_sales += $total_sales;
    $grand_cash_sales += $cash_sales;
    $grand_bank_sales += $bank_sales;
    $grand_expenses += $expenses;
    $grand_returns += $refunds;
    $grand_expected_money += $expected_money;
    $grand_collected_cash += $collected_cash;
    $grand_collected_bank += $collected_bank;
    $total_transactions += $transactions;

    if ($total_counted !== null) {
        $grand_total_counted += $total_counted;
        $grand_difference += (float)$difference;
    }
}

// Profit
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(si.quantity * (p.selling_price - p.buying_price)),0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = :business_id
      AND DATE(s.sale_date) BETWEEN :start_date AND :end_date
      AND s.sale_status = 'completed'
");
$stmt->execute([":business_id" => $business_id, ":start_date" => $range_start, ":end_date" => $range_end]);
$gross_profit = (float)$stmt->fetchColumn();
$net_profit = $gross_profit - $grand_returns - $grand_expenses;

// History
if ($period === "daily") {
    $stmt = $pdo->prepare("
        SELECT r.reconciliation_date, u.full_name, r.system_cash, r.actual_cash, r.difference,
               r.collected_cash, r.collected_bank, r.status, r.resolution
        FROM daily_reconciliations r
        LEFT JOIN users u ON r.cashier_id = u.id
        WHERE r.business_id = :business_id
          AND r.reconciliation_date BETWEEN :start_date AND :end_date
        ORDER BY r.reconciliation_date DESC
    ");
    $stmt->execute([":business_id" => $business_id, ":start_date" => $range_start, ":end_date" => $range_end]);
} elseif ($period === "monthly") {
    $stmt = $pdo->prepare("
        SELECT r.reconciliation_month AS reconciliation_date, u.full_name, r.system_cash, r.actual_cash, r.difference,
               r.collected_cash, r.collected_bank, r.status, r.resolution
        FROM monthly_reconciliations r
        LEFT JOIN users u ON r.cashier_id = u.id
        WHERE r.business_id = :business_id
          AND r.reconciliation_month BETWEEN :start_date AND :end_date
        ORDER BY r.reconciliation_month DESC
    ");
    $stmt->execute([":business_id" => $business_id, ":start_date" => $range_start, ":end_date" => $range_end]);
} else {
    $stmt = $pdo->prepare("
        SELECT r.reconciliation_quarter AS reconciliation_date, u.full_name, r.system_cash, r.actual_cash, r.difference,
               r.collected_cash, r.collected_bank, r.status, r.resolution
        FROM quarterly_reconciliations r
        LEFT JOIN users u ON r.cashier_id = u.id
        WHERE r.business_id = :business_id
          AND r.reconciliation_quarter = :quarter
        ORDER BY r.reconciliation_quarter DESC
    ");
    $stmt->execute([":business_id" => $business_id, ":quarter" => $selected_quarter]);
}
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary totals
$total_shortage = 0;
$total_excess = 0;
foreach ($daily_report as $row) {
    if ($row['difference'] !== null) {
        if ($row['difference'] < 0) {
            $total_shortage += abs($row['difference']);
        } elseif ($row['difference'] > 0) {
            $total_excess += $row['difference'];
        }
    }
}

// Number of cashiers with issues
$cashiers_with_shortage = 0;
$cashiers_with_excess = 0;
foreach ($daily_report as $row) {
    if ($row['difference'] !== null) {
        if ($row['difference'] < 0) $cashiers_with_shortage++;
        elseif ($row['difference'] > 0) $cashiers_with_excess++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reconciliation - BizFlow</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.report-header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:25px;flex-wrap:wrap}
.period-form{display:flex;gap:10px;align-items:flex-end;background:#fff;padding:15px;border:1px solid #e5e7eb;border-radius:10px;flex-wrap:wrap}
.period-form label{display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:5px}
.period-form input,.period-form select{padding:10px;border:1px solid #d1d5db;border-radius:7px;font-size:14px}
.period-form button{padding:10px 18px;border:0;border-radius:7px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer}
.print-btn{background:#111827;padding:10px 18px;border:0;border-radius:7px;color:#fff;font-weight:600;cursor:pointer;margin-bottom:25px}
.export-btn{background:#16a34a;text-decoration:none;color:#fff;display:inline-block;padding:10px 18px;border-radius:7px;font-weight:600;margin-bottom:25px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:25px}
.summary-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px}
.summary-card .label{font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase}
.summary-card .value{font-size:22px;font-weight:700;margin-top:4px}
.summary-card .sub{font-size:12px;color:#6b7280;margin-top:2px}
.value-green{color:#16a34a}.value-red{color:#dc2626}.value-blue{color:#2563eb}.value-purple{color:#7c3aed}.value-orange{color:#d97706}
.period-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;margin-bottom:25px}
.period-card h3{margin-top:0}
.period-buttons{display:flex;gap:8px;flex-wrap:wrap}
.period-buttons a{padding:9px 14px;border-radius:7px;text-decoration:none;border:1px solid #d1d5db;color:#374151;background:#fff;font-weight:600}
.period-buttons a.active{background:#2563eb;color:#fff;border-color:#2563eb}
.cashier-section,.history-section{background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:25px;overflow:hidden}
.cashier-header{padding:18px 20px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
.cashier-name{font-size:18px;font-weight:700}
.status{display:inline-block;padding:5px 10px;border-radius:20px;font-size:12px;font-weight:700}
.status-pending{background:#fef3c7;color:#92400e}
.status-resolved{background:#dcfce7;color:#166534}
.cashier-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:#e5e7eb}
.cashier-stat{background:#fff;padding:14px 16px}
.cashier-stat-label{font-size:12px;color:#6b7280;margin-bottom:5px}
.cashier-stat-value{font-size:17px;font-weight:700}
.reconciliation{padding:20px;border-top:1px solid #e5e7eb;background:#fcfcfd}
.reconciliation-title{font-weight:700;margin-bottom:15px;font-size:16px}
.reconciliation-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px;align-items:end}
.form-group label{display:block;font-size:12px;font-weight:600;color:#4b5563;margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:7px;font-size:14px;background:#fff}
.form-group textarea{min-height:42px;resize:vertical}
.save-btn{padding:11px 18px;border:0;border-radius:7px;background:#111827;color:#fff;cursor:pointer;font-weight:600;width:100%}
.save-btn:hover{background:#374151}
.save-btn-monthly{background:#d97706}
.save-btn-monthly:hover{background:#b45309}
.collection-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:15px;margin-bottom:15px}
.collection-title{font-weight:700;color:#166534;margin-bottom:12px}
.collection-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px}
.difference-box{padding:12px;border-radius:8px;background:#f3f4f6;font-weight:700}
.difference-shortage{color:#dc2626;background:#fee2e2}
.difference-overage{color:#16a34a;background:#dcfce7}
.difference-balanced{color:#2563eb;background:#dbeafe}
.message{padding:14px 18px;border-radius:8px;margin-bottom:20px;font-weight:600}
.message-success{background:#dcfce7;color:#166534}
.message-error{background:#fee2e2;color:#991b1b}
.history-header{padding:18px 20px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:18px;font-weight:700}
.history-table{width:100%;border-collapse:collapse;min-width:900px}
.history-table th{background:#f3f4f6;padding:12px;text-align:left;font-size:12px;color:#4b5563}
.history-table td{padding:12px;border-top:1px solid #e5e7eb;font-size:13px}
.loss-text{color:#dc2626;font-weight:700}.excess-text{color:#16a34a;font-weight:700}.balance-text{color:#2563eb;font-weight:700}
.report-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:15px;border-radius:8px;margin-bottom:25px;font-size:13px;line-height:1.6}
.history-table-wrapper{overflow-x:auto}
.print-only{display:none}
.summary-section-title{font-size:16px;font-weight:700;color:#111827;margin:25px 0 15px;border-bottom:2px solid #e5e7eb;padding-bottom:8px}
@media print{body{background:#fff!important}.sidebar,.topbar,.period-form,.period-card,.print-btn,.export-btn,.reconciliation,.save-btn,.message{display:none!important}.main{margin:0!important;padding:0!important;width:100%!important}.content{padding:0!important}.cashier-section,.summary-card,.history-section{break-inside:avoid;box-shadow:none!important}.print-only{display:block}.report-note{background:#fff;color:#000;border:1px solid #000}.cashier-section,.history-section{border:1px solid #000}.cashier-header{background:#f5f5f5}}
@media(max-width:700px){.period-form{width:100%;flex-direction:column;align-items:stretch}.period-form button{width:100%}}
</style>
</head>
<body>
<div class="app">
<?php include "../assets/includes/sidebar.php"; ?>
<main class="main">
<?php include "../assets/includes/topbar.php"; ?>
<section class="content">

<div class="print-only print-title">
    <h1>BIZFLOW</h1>
    <h2><?= htmlspecialchars(ucfirst($period)) ?> Reconciliation Report</h2>
    <p>Period: <strong><?= htmlspecialchars($period_title) ?></strong></p>
</div>

<div class="report-header">
    <div>
        <h1>📊 Reconciliation</h1>
        <p>Reconcile money on <?= ucfirst($period) ?> basis</p>
    </div>

    <form method="GET" class="period-form">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        <?php if($period === 'daily'): ?>
            <div><label>Date</label><input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" required></div>
        <?php elseif($period === 'monthly'): ?>
            <div><label>Month</label><input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>" required></div>
        <?php else: ?>
            <div><label>Quarter</label><select name="quarter"><option value="<?= htmlspecialchars($selected_quarter) ?>" selected><?= htmlspecialchars($period_title) ?></option>
                <?php $cy=(int)date('Y'); for($yy=$cy-2;$yy<=$cy+1;$yy++): for($qq=1;$qq<=4;$qq++): $qv=$yy.'-Q'.$qq; if($qv===$selected_quarter) continue; ?><option value="<?= $qv ?>"><?= $qv ?></option><?php endfor; endfor; ?>
            </select></div>
        <?php endif; ?>
        <button type="submit">📅 View</button>
    </form>
</div>

<div class="period-card">
    <h3>📊 Select Period</h3>
    <div class="period-buttons">
        <a class="<?= $period==='daily'?'active':'' ?>" href="?period=daily&date=<?= urlencode($selected_date) ?>">📅 Daily</a>
        <a class="<?= $period==='monthly'?'active':'' ?>" href="?period=monthly&month=<?= urlencode($selected_month) ?>">📆 Monthly</a>
        <a class="<?= $period==='quarterly'?'active':'' ?>" href="?period=quarterly&quarter=<?= urlencode($selected_quarter) ?>">📊 Quarterly</a>
    </div>
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:25px">
    <button type="button" class="print-btn" onclick="window.print()">🖨️ Print</button>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="export-btn">📊 Export CSV</a>
</div>

<?php if($message !== ''): ?>
<div class="message <?= $message_type==='success'?'message-success':'message-error' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="report-note">
<strong>📅 <?= ucfirst($period) ?> Reconciliation Summary</strong><br><br>
<strong>💰 Total System Expected Money:</strong> Cash Sales + Bank/M-Pesa Sales − Expenses − Returns for the period.<br>
<strong>🔴 Total Shortage/Loss:</strong> Total amount where counted money is less than expected across all cashiers.<br>
<strong>🟢 Total Excess/Surplus:</strong> Total amount where counted money is more than expected across all cashiers.<br>
<strong>📝 Enter Total Money Counted</strong> (Cash + Bank/M-Pesa) for each cashier to calculate differences.
</div>

<!-- ============================================================
     SUMMARY WITH LOSS/EXCESS
============================================================ -->

<h2 class="summary-section-title">📊 <?= ucfirst($period) ?> Summary</h2>
<div class="summary-grid">
    <div class="summary-card"><div class="label">Total Sales</div><div class="value value-blue"><?= money($grand_sales) ?></div></div>
    <div class="summary-card"><div class="label">💵 Cash Sales</div><div class="value"><?= money($grand_cash_sales) ?></div></div>
    <div class="summary-card" style="border-color:#7c3aed"><div class="label">🏦 Bank/M-Pesa</div><div class="value value-purple"><?= money($grand_bank_sales) ?></div></div>
    <div class="summary-card"><div class="label">💸 Expenses</div><div class="value value-red"><?= money($grand_expenses) ?></div></div>
    <div class="summary-card"><div class="label">🔄 Returns</div><div class="value value-orange"><?= money($grand_returns) ?></div></div>
    <div class="summary-card" style="border-color:#2563eb;border-width:2px;"><div class="label">💰 Total System Expected Money</div><div class="value value-blue"><?= money($grand_expected_money) ?></div></div>
    <div class="summary-card" style="border-color:#16a34a;border-width:2px;"><div class="label">💰 Total Money Counted</div><div class="value value-green"><?= money($grand_total_counted) ?></div></div>
    <div class="summary-card"><div class="label">📈 Net Difference</div><div class="value <?= $grand_difference>=0?'value-green':'value-red' ?>"><?= $grand_difference>=0?'+':'' ?><?= money($grand_difference) ?></div></div>
</div>

<!-- SHORTAGE & EXCESS SUMMARY - HIGHLIGHTED -->
<div class="summary-grid" style="margin-top:10px;">
    <div class="summary-card" style="border-color:#dc2626;border-width:2px;background:#fef2f2;">
        <div class="label">🔴 TOTAL SHORTAGE / LOSS</div>
        <div class="value value-red"><?= money($total_shortage) ?></div>
        <div class="sub"><?= $cashiers_with_shortage ?> cashier(s) with shortage</div>
    </div>
    <div class="summary-card" style="border-color:#16a34a;border-width:2px;background:#f0fdf4;">
        <div class="label">🟢 TOTAL EXCESS / SURPLUS</div>
        <div class="value value-green"><?= money($total_excess) ?></div>
        <div class="sub"><?= $cashiers_with_excess ?> cashier(s) with excess</div>
    </div>
    <div class="summary-card" style="border-color:#2563eb;border-width:2px;background:#eff6ff;">
        <div class="label">📊 NET POSITION</div>
        <div class="value <?= ($total_excess - $total_shortage)>=0?'value-green':'value-red' ?>">
            <?= ($total_excess - $total_shortage)>=0?'+':'' ?><?= money($total_excess - $total_shortage) ?>
        </div>
        <div class="sub"><?= ($total_excess - $total_shortage)>=0?'Overall Surplus':'Overall Shortage' ?></div>
    </div>
    <div class="summary-card" style="border-color:#d97706;border-width:2px;background:#fffbeb;">
        <div class="label">🧾 ADMIN COLLECTED</div>
        <div class="value value-orange"><?= money($grand_collected_cash + $grand_collected_bank) ?></div>
        <div class="sub">Cash: <?= money($grand_collected_cash) ?> | Bank: <?= money($grand_collected_bank) ?></div>
    </div>
</div>

<!-- Cashiers -->
<?php if(!empty($daily_report)): ?>
<?php foreach($daily_report as $row): ?>
<div class="cashier-section">
    <div class="cashier-header">
        <div class="cashier-name">👤 <?= htmlspecialchars($row['name']) ?></div>
        <div>
            <span class="status <?= $row['status']==='resolved'?'status-resolved':'status-pending' ?>">
                <?= $row['status']==='resolved' ? '✓ Resolved' : '⏳ Pending' ?>
            </span>
            <?php if($row['difference'] !== null): ?>
                <?php if($row['difference'] < 0): ?>
                    <span style="background:#fee2e2;color:#dc2626;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-left:8px;">🔴 Shortage: <?= money(abs($row['difference'])) ?></span>
                <?php elseif($row['difference'] > 0): ?>
                    <span style="background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-left:8px;">🟢 Surplus: <?= money($row['difference']) ?></span>
                <?php else: ?>
                    <span style="background:#dbeafe;color:#2563eb;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-left:8px;">✅ Balanced</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="cashier-stats">
        <div class="cashier-stat"><div class="cashier-stat-label">Transactions</div><div class="cashier-stat-value"><?= number_format($row['transactions']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">Total Sales</div><div class="cashier-stat-value"><?= money($row['sales']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">💵 Cash Sales</div><div class="cashier-stat-value"><?= money($row['cash_sales']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">🏦 Bank/M-Pesa</div><div class="cashier-stat-value" style="color:#7c3aed"><?= money($row['bank_sales']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">💸 Expenses</div><div class="cashier-stat-value" style="color:#dc2626"><?= money($row['expenses']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">🔄 Returns</div><div class="cashier-stat-value"><?= money($row['refunds']) ?></div></div>
        <div class="cashier-stat" style="border-color:#2563eb;border-width:2px;"><div class="cashier-stat-label">💰 System Expected Money</div><div class="cashier-stat-value value-blue"><?= money($row['expected_money']) ?></div></div>
        <div class="cashier-stat" style="border-color:#16a34a;border-width:2px;"><div class="cashier-stat-label">💰 Total Money Counted</div><div class="cashier-stat-value value-green"><?= $row['total_counted'] !== null ? money($row['total_counted']) : 'Not Counted' ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">🧾 Collected Cash</div><div class="cashier-stat-value value-green"><?= money($row['collected_cash']) ?></div></div>
        <div class="cashier-stat"><div class="cashier-stat-label">🧾 Collected Bank</div><div class="cashier-stat-value" style="color:#7c3aed"><?= money($row['collected_bank']) ?></div></div>
    </div>

    <?php if($period === 'daily'): ?>
    <!-- DAILY RECONCILIATION -->
    <div class="reconciliation">
        <div class="reconciliation-title">📅 Daily Reconciliation</div>
        <form method="POST">
            <input type="hidden" name="action" value="save_reconciliation">
            <input type="hidden" name="cashier_id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="reconciliation_date" value="<?= htmlspecialchars($selected_date) ?>">

            <div class="collection-box">
                <div class="collection-title">🧾 Admin Collection</div>
                <div class="collection-grid">
                    <div class="form-group">
                        <label>💵 Admin Collected Cash</label>
                        <input type="number" name="collected_cash" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_cash'],2,'.','')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>🏦 Admin Collected Bank/M-Pesa</label>
                        <input type="number" name="collected_bank" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_bank'],2,'.','')) ?>" required>
                    </div>
                </div>
            </div>

            <div class="reconciliation-grid">
                <div class="form-group"><label>💰 Total System Expected Money</label><input type="text" value="<?= number_format($row['expected_money'],2) ?>" readonly></div>
                <div class="form-group"><label>💰 Total Money Counted (Cash + Bank)</label><input type="number" name="actual_cash" step="0.01" min="0" value="<?= $row['total_counted'] !== null ? htmlspecialchars(number_format($row['total_counted'],2,'.','')) : '' ?>" required></div>
                <div class="form-group">
                    <label>Difference</label>
                    <?php $dc='difference-balanced'; if($row['difference'] !== null && $row['difference'] < 0) $dc='difference-shortage'; elseif($row['difference'] !== null && $row['difference'] > 0) $dc='difference-overage'; ?>
                    <div class="difference-box <?= $dc ?>">
                        <?php if($row['difference'] === null): ?>Not Counted<?php else: ?><?= money($row['difference']) ?><br><small><?= differenceLabel($row['difference']) ?></small><?php endif; ?>
                    </div>
                </div>
                <div class="form-group"><label>Resolution</label><select name="resolution">
                    <option value="">Pending</option>
                    <option value="system_error" <?= $row['resolution']==='system_error'?'selected':'' ?>>System Error</option>
                    <option value="cashier_loss" <?= $row['resolution']==='cashier_loss'?'selected':'' ?>>Cashier Loss</option>
                    <option value="cashier_shortage" <?= $row['resolution']==='cashier_shortage'?'selected':'' ?>>Cashier Shortage</option>
                    <option value="unrecorded_sale" <?= $row['resolution']==='unrecorded_sale'?'selected':'' ?>>Unrecorded Sale</option>
                    <option value="overage" <?= $row['resolution']==='overage'?'selected':'' ?>>Cash Excess</option>
                    <option value="other" <?= $row['resolution']==='other'?'selected':'' ?>>Other</option>
                </select></div>
                <div class="form-group"><label>Notes</label><textarea name="notes"><?= htmlspecialchars($row['notes']) ?></textarea></div>
                <div><button type="submit" class="save-btn">💾 Save Daily</button></div>
            </div>
        </form>
    </div>

    <?php elseif($period === 'monthly'): ?>
    <!-- MONTHLY RECONCILIATION -->
    <div class="reconciliation">
        <div class="reconciliation-title">📆 Monthly Reconciliation - <?= date("F Y", strtotime($range_start)) ?></div>
        <form method="POST">
            <input type="hidden" name="action" value="save_monthly_reconciliation">
            <input type="hidden" name="cashier_id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="reconciliation_month" value="<?= htmlspecialchars($range_start) ?>">

            <div class="collection-box" style="background:#fef3c7;border-color:#fcd34d;">
                <div class="collection-title" style="color:#92400e;">🧾 Monthly Admin Collection - <?= date("F Y", strtotime($range_start)) ?></div>
                <div class="collection-grid">
                    <div class="form-group">
                        <label>💵 Monthly Collected Cash</label>
                        <input type="number" name="collected_cash" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_cash'],2,'.','')) ?>" required>
                        <small style="color:#6b7280;">Total cash collected for the entire month</small>
                    </div>
                    <div class="form-group">
                        <label>🏦 Monthly Collected Bank/M-Pesa</label>
                        <input type="number" name="collected_bank" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_bank'],2,'.','')) ?>" required>
                        <small style="color:#6b7280;">Total bank/M-Pesa for the entire month</small>
                    </div>
                </div>
            </div>

            <div class="reconciliation-grid">
                <div class="form-group"><label>💰 Total System Expected Money</label><input type="text" value="<?= number_format($row['expected_money'],2) ?>" readonly></div>
                <div class="form-group"><label>💰 Total Money Counted (Cash + Bank)</label><input type="number" name="actual_cash" step="0.01" min="0" value="<?= $row['total_counted'] !== null ? htmlspecialchars(number_format($row['total_counted'],2,'.','')) : '' ?>" required>
                    <small style="color:#6b7280;">Enter the total money (cash + bank/M-Pesa) counted for the month</small>
                </div>
                <div class="form-group">
                    <label>Monthly Difference</label>
                    <?php $dc='difference-balanced'; if($row['difference'] !== null && $row['difference'] < 0) $dc='difference-shortage'; elseif($row['difference'] !== null && $row['difference'] > 0) $dc='difference-overage'; ?>
                    <div class="difference-box <?= $dc ?>">
                        <?php if($row['difference'] === null): ?>Not Counted<?php else: ?><?= money($row['difference']) ?><br><small><?= differenceLabel($row['difference']) ?></small><?php endif; ?>
                    </div>
                </div>
                <div class="form-group"><label>Resolution</label><select name="resolution">
                    <option value="">Pending</option>
                    <option value="system_error" <?= $row['resolution']==='system_error'?'selected':'' ?>>System Error</option>
                    <option value="cashier_loss" <?= $row['resolution']==='cashier_loss'?'selected':'' ?>>Cashier Loss</option>
                    <option value="cashier_shortage" <?= $row['resolution']==='cashier_shortage'?'selected':'' ?>>Cashier Shortage</option>
                    <option value="unrecorded_sale" <?= $row['resolution']==='unrecorded_sale'?'selected':'' ?>>Unrecorded Sale</option>
                    <option value="overage" <?= $row['resolution']==='overage'?'selected':'' ?>>Cash Excess</option>
                    <option value="other" <?= $row['resolution']==='other'?'selected':'' ?>>Other</option>
                </select></div>
                <div class="form-group"><label>Notes</label><textarea name="notes"><?= htmlspecialchars($row['notes']) ?></textarea></div>
                <div><button type="submit" class="save-btn save-btn-monthly">💾 Save Monthly</button></div>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- QUARTERLY RECONCILIATION -->
    <div class="reconciliation">
        <div class="reconciliation-title">📊 Quarterly Reconciliation - <?= htmlspecialchars($selected_quarter) ?></div>
        <form method="POST">
            <input type="hidden" name="action" value="save_quarterly_reconciliation">
            <input type="hidden" name="cashier_id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="reconciliation_quarter" value="<?= htmlspecialchars($selected_quarter) ?>">

            <div class="collection-box" style="background:#ede9fe;border-color:#c4b5fd;">
                <div class="collection-title" style="color:#5b21b6;">🧾 Quarterly Admin Collection - <?= htmlspecialchars($selected_quarter) ?></div>
                <div class="collection-grid">
                    <div class="form-group">
                        <label>💵 Quarterly Collected Cash</label>
                        <input type="number" name="collected_cash" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_cash'],2,'.','')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>🏦 Quarterly Collected Bank/M-Pesa</label>
                        <input type="number" name="collected_bank" step="0.01" min="0" value="<?= htmlspecialchars(number_format($row['collected_bank'],2,'.','')) ?>" required>
                    </div>
                </div>
            </div>

            <div class="reconciliation-grid">
                <div class="form-group"><label>💰 Total System Expected Money</label><input type="text" value="<?= number_format($row['expected_money'],2) ?>" readonly></div>
                <div class="form-group"><label>💰 Total Money Counted (Cash + Bank)</label><input type="number" name="actual_cash" step="0.01" min="0" value="<?= $row['total_counted'] !== null ? htmlspecialchars(number_format($row['total_counted'],2,'.','')) : '' ?>" required></div>
                <div class="form-group">
                    <label>Difference</label>
                    <?php $dc='difference-balanced'; if($row['difference'] !== null && $row['difference'] < 0) $dc='difference-shortage'; elseif($row['difference'] !== null && $row['difference'] > 0) $dc='difference-overage'; ?>
                    <div class="difference-box <?= $dc ?>">
                        <?php if($row['difference'] === null): ?>Not Counted<?php else: ?><?= money($row['difference']) ?><br><small><?= differenceLabel($row['difference']) ?></small><?php endif; ?>
                    </div>
                </div>
                <div class="form-group"><label>Resolution</label><select name="resolution">
                    <option value="">Pending</option>
                    <option value="system_error" <?= $row['resolution']==='system_error'?'selected':'' ?>>System Error</option>
                    <option value="cashier_loss" <?= $row['resolution']==='cashier_loss'?'selected':'' ?>>Cashier Loss</option>
                    <option value="cashier_shortage" <?= $row['resolution']==='cashier_shortage'?'selected':'' ?>>Cashier Shortage</option>
                    <option value="unrecorded_sale" <?= $row['resolution']==='unrecorded_sale'?'selected':'' ?>>Unrecorded Sale</option>
                    <option value="overage" <?= $row['resolution']==='overage'?'selected':'' ?>>Cash Excess</option>
                    <option value="other" <?= $row['resolution']==='other'?'selected':'' ?>>Other</option>
                </select></div>
                <div class="form-group"><label>Notes</label><textarea name="notes"><?= htmlspecialchars($row['notes']) ?></textarea></div>
                <div><button type="submit" class="save-btn" style="background:#5b21b6;">💾 Save Quarterly</button></div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="cashier-section"><div style="padding:40px;text-align:center"><h3>No cashiers found</h3><p>No cashiers have activity for <?= htmlspecialchars($period_title) ?>.</p></div></div>
<?php endif; ?>

<!-- History -->
<div class="history-section">
    <div class="history-header">📋 Reconciliation History</div>
    <div class="history-table-wrapper">
        <table class="history-table">
            <thead><tr>
                <th>Date/Period</th><th>Cashier</th><th>System Expected</th><th>Total Counted</th><th>Difference</th>
                <th>Collected Cash</th><th>Collected Bank</th><th>Result</th><th>Resolution</th>
            </tr></thead>
            <tbody>
            <?php if(!empty($history)): foreach($history as $h): ?>
                <tr>
                    <td><?= $period === 'daily' ? qDate($h['reconciliation_date']) : htmlspecialchars($h['reconciliation_date']) ?></td>
                    <td><strong><?= htmlspecialchars($h['full_name'] ?? 'Unknown') ?></strong></td>
                    <td><?= money($h['system_cash']) ?></td>
                    <td><?= money($h['actual_cash']) ?></td>
                    <td><?= money($h['difference']) ?></td>
                    <td class="value-green"><?= money($h['collected_cash']) ?></td>
                    <td style="color:#7c3aed"><?= money($h['collected_bank']) ?></td>
                    <td>
                        <?php if((float)$h['difference'] < 0): ?><span class="loss-text">🔴 SHORTAGE</span>
                        <?php elseif((float)$h['difference'] > 0): ?><span class="excess-text">🟢 SURPLUS</span>
                        <?php else: ?><span class="balance-text">🔵 BALANCED</span><?php endif; ?>
                    </td>
                    <td><?= $h['resolution'] ? htmlspecialchars(ucwords(str_replace('_',' ',$h['resolution']))) : 'Pending' ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" style="text-align:center;padding:30px">No reconciliation records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="print-only" style="margin-top:30px;border-top:1px solid #000;padding-top:15px;font-size:12px">
    <p>Report generated by BizFlow.</p>
    <p>Period: <?= htmlspecialchars($period_title) ?></p>
    <p>Total Shortage/Loss: <?= money($total_shortage) ?></p>
    <p>Total Excess/Surplus: <?= money($total_excess) ?></p>
    <p>Net Position: <?= money($total_excess - $total_shortage) ?></p>
    <p>Prepared By: ______________________________</p>
    <p>Approved By: ______________________________</p>
    <p>Signature: _________________________________</p>
</div>

</section>
</main>
</div>
</body>
</html>
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("add_user");

require_once "../config/database.php";

$business_id = $_SESSION["business_id"];
$current_user_id = $_SESSION["user_id"];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Confirm current user is an Admin
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT u.id, r.name AS role_name
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
    die("Access denied. Only administrators can add users.");
}


/*
|--------------------------------------------------------------------------
| Get available roles
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name
    FROM roles
    ORDER BY id
");

$roles = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Handle form submission
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    /*
    | Validation
    */

    if ($full_name === '') {

        $error = "Please enter the user's full name.";

    } elseif ($username === '') {

        $error = "Please enter a username.";

    } elseif ($role_id <= 0) {

        $error = "Please select a role.";

    } elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters long.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } else {

        /*
        | Check username within this business
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE business_id = :business_id
              AND username = :username
            LIMIT 1
        ");

        $stmt->execute([
            ':business_id' => $business_id,
            ':username' => $username
        ]);

        if ($stmt->fetch()) {

            $error = "That username already exists.";

        } else {

            /*
            | Verify selected role exists
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM roles
                WHERE id = :role_id
                LIMIT 1
            ");

            $stmt->execute([
                ':role_id' => $role_id
            ]);

            if (!$stmt->fetch()) {

                $error = "Invalid role selected.";

            } else {

                /*
                | Securely hash password
                */

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO users
                        (
                            business_id,
                            role_id,
                            username,
                            password_hash,
                            full_name,
                            phone,
                            is_active
                        )
                        VALUES
                        (
                            :business_id,
                            :role_id,
                            :username,
                            :password_hash,
                            :full_name,
                            :phone,
                            1
                        )
                    ");

                    $stmt->execute([
                        ':business_id' => $business_id,
                        ':role_id' => $role_id,
                        ':username' => $username,
                        ':password_hash' => $password_hash,
                        ':full_name' => $full_name,
                        ':phone' => $phone !== '' ? $phone : null
                    ]);

                    $success = "User created successfully.";

                    /*
                    | Clear form values
                    */

                    $full_name = '';
                    $username = '';
                    $phone = '';

                } catch (PDOException $e) {

                    $error = "Unable to create user: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Add User - BizFlow</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>

<body>

<div class="app">

    <?php include "../assets/includes/sidebar.php"; ?>

    <main class="main">

        <?php include "../assets/includes/topbar.php"; ?>

        <section class="content">

            <div class="page-title">

                <div>

                    <h1>Add User</h1>

                    <p>
                        Create a new BizFlow user account.
                    </p>

                </div>

                <a
                    href="users.php"
                    class="btn-secondary"
                >
                    ← Back to Users
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

                    <div class="form-row">

                        <div class="form-group">

                            <label>
                                Full Name *
                            </label>

                            <input
                                type="text"
                                name="full_name"
                                value="<?= htmlspecialchars($full_name ?? '') ?>"
                                placeholder="e.g. John Maina"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Username *
                            </label>

                            <input
                                type="text"
                                name="username"
                                value="<?= htmlspecialchars($username ?? '') ?>"
                                placeholder="e.g. john"
                                required
                            >

                        </div>

                    </div>


                    <div class="form-row">

                        <div class="form-group">

                            <label>
                                Phone Number
                            </label>

                            <input
                                type="text"
                                name="phone"
                                value="<?= htmlspecialchars($phone ?? '') ?>"
                                placeholder="e.g. 0712345678"
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Role *
                            </label>

                            <select
                                name="role_id"
                                required
                            >

                                <option value="">
                                    Select Role
                                </option>

                                <?php foreach ($roles as $role): ?>

                                    <option
                                        value="<?= (int)$role['id'] ?>"
                                        <?= (
                                            isset($_POST['role_id']) &&
                                            (int)$_POST['role_id'] === (int)$role['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($role['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>


                    <div class="form-row">

                        <div class="form-group">

                            <label>
                                Password *
                            </label>

                            <input
                                type="password"
                                name="password"
                                minlength="6"
                                required
                            >

                            <small>
                                Minimum 6 characters.
                            </small>

                        </div>


                        <div class="form-group">

                            <label>
                                Confirm Password *
                            </label>

                            <input
                                type="password"
                                name="confirm_password"
                                minlength="6"
                                required
                            >

                        </div>

                    </div>


                    <button
                        type="submit"
                        class="btn-primary"
                    >
                        Create User
                    </button>

                </form>

            </div>

        </section>

    </main>

</div>

</body>

</html>
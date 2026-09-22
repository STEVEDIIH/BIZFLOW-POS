<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

$business_id = $_SESSION["business_id"];
$current_user_id = $_SESSION["user_id"];

$error = '';
$success = '';

$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    header("Location: users.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Confirm current user is Admin
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
    die("Access denied. Only administrators can edit users.");
}


/*
|--------------------------------------------------------------------------
| Get user to edit
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
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
| Get roles
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
| Handle update
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;


    /*
    | Validation
    */

    if ($full_name === '') {

        $error = "Please enter the user's full name.";

    } elseif ($username === '') {

        $error = "Please enter a username.";

    } elseif ($role_id <= 0) {

        $error = "Please select a role.";

    } elseif ($password !== '' && strlen($password) < 6) {

        $error = "New password must be at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        if ($password !== '' || $confirm_password !== '') {
            $error = "Passwords do not match.";
        }

    }


    /*
    | Prevent current admin from deactivating themselves
    */

    if (
        $error === '' &&
        $user_id === $current_user_id &&
        $is_active === 0
    ) {
        $error = "You cannot deactivate your own account.";
    }


    /*
    | Continue if validation passed
    */

    if ($error === '') {

        /*
        | Check username is not already used
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE business_id = :business_id
              AND username = :username
              AND id != :id
            LIMIT 1
        ");

        $stmt->execute([
            ':business_id' => $business_id,
            ':username' => $username,
            ':id' => $user_id
        ]);

        if ($stmt->fetch()) {

            $error = "That username is already being used by another user.";

        } else {

            /*
            | Verify role exists
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

                try {

                    /*
                    | Update password only if a new one was entered
                    */

                    if ($password !== '') {

                        $password_hash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET
                                role_id = :role_id,
                                username = :username,
                                password_hash = :password_hash,
                                full_name = :full_name,
                                phone = :phone,
                                is_active = :is_active
                            WHERE id = :id
                              AND business_id = :business_id
                        ");

                        $stmt->execute([
                            ':role_id' => $role_id,
                            ':username' => $username,
                            ':password_hash' => $password_hash,
                            ':full_name' => $full_name,
                            ':phone' => $phone !== '' ? $phone : null,
                            ':is_active' => $is_active,
                            ':id' => $user_id,
                            ':business_id' => $business_id
                        ]);

                    } else {

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET
                                role_id = :role_id,
                                username = :username,
                                full_name = :full_name,
                                phone = :phone,
                                is_active = :is_active
                            WHERE id = :id
                              AND business_id = :business_id
                        ");

                        $stmt->execute([
                            ':role_id' => $role_id,
                            ':username' => $username,
                            ':full_name' => $full_name,
                            ':phone' => $phone !== '' ? $phone : null,
                            ':is_active' => $is_active,
                            ':id' => $user_id,
                            ':business_id' => $business_id
                        ]);
                    }

                    $success = "User updated successfully.";

                    /*
                    | Refresh user information
                    */

                    $stmt = $pdo->prepare("
                        SELECT *
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

                } catch (PDOException $e) {

                    $error = "Unable to update user: " . $e->getMessage();
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

    <title>Edit User - BizFlow</title>

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

                    <h1>Edit User</h1>

                    <p>
                        Update this user's BizFlow account.
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
                                value="<?= htmlspecialchars($user['full_name']) ?>"
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
                                value="<?= htmlspecialchars($user['username']) ?>"
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
                                value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
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

                                <?php foreach ($roles as $role): ?>

                                    <option
                                        value="<?= (int)$role['id'] ?>"
                                        <?= (
                                            (int)$role['id'] ===
                                            (int)$user['role_id']
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
                                New Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                minlength="6"
                                placeholder="Leave blank to keep current password"
                            >

                            <small>
                                Leave blank if you don't want to change the password.
                            </small>

                        </div>


                        <div class="form-group">

                            <label>
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                name="confirm_password"
                                minlength="6"
                            >

                        </div>

                    </div>


                    <div class="form-group">

                        <label>

                            <input
                                type="checkbox"
                                name="is_active"
                                <?= $user['is_active'] ? 'checked' : '' ?>
                                <?= (
                                    (int)$user['id'] ===
                                    (int)$current_user_id
                                ) ? 'disabled' : '' ?>
                            >

                            Active user

                        </label>

                        <?php if ((int)$user['id'] === (int)$current_user_id): ?>

                            <small>
                                Your own account cannot be deactivated here.
                            </small>

                            <input
                                type="hidden"
                                name="is_active"
                                value="1"
                            >

                        <?php endif; ?>

                    </div>


                    <button
                        type="submit"
                        class="btn-primary"
                    >
                        Update User
                    </button>

                </form>

            </div>

        </section>

    </main>

</div>

</body>

</html>
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("users");
require_once "../config/database.php";

$business_id = $_SESSION["business_id"];
$current_user_id = $_SESSION["user_id"];

/*
|--------------------------------------------------------------------------
| Get users
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.username,
        u.full_name,
        u.phone,
        u.is_active,
        u.created_at,
        r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.business_id = :business_id
    ORDER BY u.id DESC
");

$stmt->execute([
    ':business_id' => $business_id
]);

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Users - BizFlow</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .user-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-activate {
            display: inline-block;
            padding: 7px 12px;
            background: #16a34a;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
        }

        .btn-activate:hover {
            background: #15803d;
        }

        .btn-deactivate {
            display: inline-block;
            padding: 7px 12px;
            background: #dc2626;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
        }

        .btn-deactivate:hover {
            background: #b91c1c;
        }

        .current-user {
            display: inline-block;
            padding: 7px 12px;
            background: #f3f4f6;
            color: #6b7280;
            border-radius: 5px;
            font-size: 13px;
        }

        .badge-active {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 12px;
            background: #dcfce7;
            color: #166534;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-inactive {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 12px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 12px;
            font-weight: 600;
        }
    </style>

</head>

<body>

<div class="app">

    <?php include "../assets/includes/sidebar.php"; ?>

    <main class="main">

        <?php include "../assets/includes/topbar.php"; ?>

        <section class="content">

            <!-- PAGE HEADER -->

            <div class="page-title">

                <div>

                    <h1>Users</h1>

                    <p>
                        Manage users who can access BizFlow.
                    </p>

                </div>

                <a
                    href="add_user.php"
                    class="btn-primary"
                >
                    + Add User
                </a>

            </div>


            <!-- USERS TABLE -->

            <div class="table-container">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Full Name</th>

                            <th>Username</th>

                            <th>Phone</th>

                            <th>Role</th>

                            <th>Status</th>

                            <th>Created</th>

                            <th>Actions</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!empty($users)): ?>

                        <?php foreach ($users as $user): ?>

                            <tr>

                                <!-- ID -->

                                <td>
                                    <?= (int)$user['id'] ?>
                                </td>


                                <!-- FULL NAME -->

                                <td>
                                    <?= htmlspecialchars(
                                        $user['full_name'] ?? '-'
                                    ) ?>
                                </td>


                                <!-- USERNAME -->

                                <td>
                                    <?= htmlspecialchars(
                                        $user['username']
                                    ) ?>
                                </td>


                                <!-- PHONE -->

                                <td>

                                    <?php if (!empty($user['phone'])): ?>

                                        <?= htmlspecialchars(
                                            $user['phone']
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>


                                <!-- ROLE -->

                                <td>

                                    <span class="badge badge-success">

                                        <?= htmlspecialchars(
                                            $user['role_name'] ?? 'Unknown'
                                        ) ?>

                                    </span>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <?php if ((int)$user['is_active'] === 1): ?>

                                        <span class="badge-active">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge-inactive">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- CREATED -->

                                <td>

                                    <?php

                                    if (!empty($user['created_at'])) {

                                        echo date(
                                            'd M Y',
                                            strtotime($user['created_at'])
                                        );

                                    } else {

                                        echo '-';

                                    }

                                    ?>

                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="user-actions">

                                        <!-- EDIT -->

                                        <a
                                            href="edit_user.php?id=<?= (int)$user['id'] ?>"
                                            class="btn-edit"
                                        >
                                            Edit
                                        </a>


                                        <!-- CURRENT ADMIN -->

                                        <?php if (
                                            (int)$user['id'] ===
                                            (int)$current_user_id
                                        ): ?>

                                            <span class="current-user">
                                                Current User
                                            </span>


                                        <!-- OTHER ACTIVE USERS -->

                                        <?php elseif (
                                            (int)$user['is_active'] === 1
                                        ): ?>

                                            <a
                                                href="toggle_user.php?id=<?= (int)$user['id'] ?>"
                                                class="btn-deactivate"
                                                onclick="return confirm('Are you sure you want to deactivate this user?')"
                                            >
                                                Deactivate
                                            </a>


                                        <!-- OTHER INACTIVE USERS -->

                                        <?php else: ?>

                                            <a
                                                href="toggle_user.php?id=<?= (int)$user['id'] ?>"
                                                class="btn-activate"
                                                onclick="return confirm('Are you sure you want to activate this user?')"
                                            >
                                                Activate
                                            </a>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                    <?php else: ?>

                        <tr>

                            <td
                                colspan="8"
                                style="text-align:center; padding:30px;"
                            >

                                No users found.

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
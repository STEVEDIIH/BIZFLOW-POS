<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
require_once "../config/permissions.php";

requirePermission("manage_");

require_once "../config/database.php";

$business_id = $_SESSION["business_id"];

$error = "";
$success = "";


/*
|--------------------------------------------------------------------------
| Edit mode
|--------------------------------------------------------------------------
*/

$edit_id = (int)($_GET["edit"] ?? 0);

$edit_category = null;

if ($edit_id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM categories
        WHERE id = :id
        AND business_id = :business_id
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $edit_id,
        ":business_id" => $business_id
    ]);

    $edit_category = $stmt->fetch();

    if (!$edit_category) {
        $edit_id = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Add / Update Category
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    $category_name = trim($_POST["category_name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    $category_id = (int)($_POST["category_id"] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($category_name === "") {

        $error = "Category name is required.";

    }


    /*
    |--------------------------------------------------------------------------
    | Check duplicate category
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE business_id = :business_id
            AND LOWER(name) = LOWER(:name)
            AND id != :category_id
            LIMIT 1
        ");

        $stmt->execute([
            ":business_id" => $business_id,
            ":name" => $category_name,
            ":category_id" => $category_id
        ]);

        if ($stmt->fetch()) {

            $error = "A category with this name already exists.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Add Category
    |--------------------------------------------------------------------------
    */

    if ($error === "" && $action === "add") {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO categories (
                    business_id,
                    name,
                    description
                )
                VALUES (
                    :business_id,
                    :name,
                    :description
                )
            ");

            $stmt->execute([

                ":business_id" => $business_id,

                ":name" => $category_name,

                ":description" =>
                    $description !== ""
                    ? $description
                    : null
            ]);

            header("Location: manage_categories.php?added=1");
            exit;

        } catch (PDOException $e) {

            $error = "Unable to add category. Please try again.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Category
    |--------------------------------------------------------------------------
    */

    if ($error === "" && $action === "update" && $category_id > 0) {

        try {

            $stmt = $pdo->prepare("
                UPDATE categories
                SET
                    name = :name,
                    description = :description
                WHERE id = :id
                AND business_id = :business_id
            ");

            $stmt->execute([

                ":name" => $category_name,

                ":description" =>
                    $description !== ""
                    ? $description
                    : null,

                ":id" => $category_id,

                ":business_id" => $business_id
            ]);

            header("Location: manage_categories.php?updated=1");
            exit;

        } catch (PDOException $e) {

            $error = "Unable to update category. Please try again.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Success Messages
|--------------------------------------------------------------------------
*/

if (isset($_GET["added"])) {

    $success = "Category added successfully.";
}

if (isset($_GET["updated"])) {

    $success = "Category updated successfully.";
}

if (isset($_GET["deactivated"])) {

    $success = "Category deactivated successfully.";
}


/*
|--------------------------------------------------------------------------
| Deactivate Category
|--------------------------------------------------------------------------
*/

if (isset($_GET["deactivate"])) {

    $category_id = (int)$_GET["deactivate"];

    if ($category_id > 0) {

        /*
        |--------------------------------------------------------------------------
        | Check category
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id, name
            FROM categories
            WHERE id = :id
            AND business_id = :business_id
            LIMIT 1
        ");

        $stmt->execute([
            ":id" => $category_id,
            ":business_id" => $business_id
        ]);

        $category = $stmt->fetch();


        if ($category) {

            /*
            |--------------------------------------------------------------------------
            | Check whether products use this category
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM products
                WHERE category_id = :category_id
                AND business_id = :business_id
            ");

            $stmt->execute([
                ":category_id" => $category_id,
                ":business_id" => $business_id
            ]);

            $product_count = (int)$stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | For now, don't permanently delete.
            |
            | If categories table doesn't yet have is_active,
            | we leave the category intact.
            |--------------------------------------------------------------------------
            */

            if ($product_count > 0) {

                $error =
                    "This category has "
                    . $product_count
                    . " product(s). It cannot be deleted because those products depend on it.";

            } else {

                /*
                | No products depend on it.
                | Delete is safe for now.
                */

                $stmt = $pdo->prepare("
                    DELETE FROM categories
                    WHERE id = :id
                    AND business_id = :business_id
                ");

                $stmt->execute([
                    ":id" => $category_id,
                    ":business_id" => $business_id
                ]);

                header("Location: manage_categories.php?deactivated=1");
                exit;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.category_id = c.id
            AND p.business_id = c.business_id
        ) AS product_count
    FROM categories c
    WHERE c.business_id = :business_id
    ORDER BY c.name ASC
");

$stmt->execute([
    ":business_id" => $business_id
]);

$categories = $stmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Categories - BizFlow</title>

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


            <!-- PAGE HEADER -->

            <div class="page-title">

                <div>

                    <h1>Categories</h1>

                    <p>
                        Organise your products into categories.
                    </p>

                </div>


                <a
                    href="view_products.php"
                    class="btn-secondary"
                >
                    ← Back to Products
                </a>

            </div>


            <!-- MESSAGES -->

            <?php if ($error !== ""): ?>

                <div class="alert alert-error">

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <?php if ($success !== ""): ?>

                <div class="alert alert-success">

                    <?= htmlspecialchars($success) ?>

                </div>

            <?php endif; ?>


            <div style="
                display:grid;
                grid-template-columns:minmax(280px, 1fr) minmax(400px, 2fr);
                gap:30px;
                align-items:start;
            ">


                <!-- ADD / EDIT CATEGORY -->

                <div class="form-container">


                    <?php if ($edit_category): ?>

                        <h3>
                            Edit Category
                        </h3>

                    <?php else: ?>

                        <h3>
                            Add New Category
                        </h3>

                    <?php endif; ?>


                    <form method="POST">


                        <?php if ($edit_category): ?>

                            <input
                                type="hidden"
                                name="action"
                                value="update"
                            >

                            <input
                                type="hidden"
                                name="category_id"
                                value="<?= $edit_category["id"] ?>"
                            >

                        <?php else: ?>

                            <input
                                type="hidden"
                                name="action"
                                value="add"
                            >

                        <?php endif; ?>


                        <div class="form-group">

                            <label for="category_name">
                                Category Name *
                            </label>

                            <input
                                type="text"
                                id="category_name"
                                name="category_name"
                                value="<?= htmlspecialchars(
                                    $edit_category["name"] ?? ""
                                ) ?>"
                                placeholder="e.g. Beverages"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label for="description">
                                Description
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                rows="4"
                                placeholder="Optional description"
                            ><?= htmlspecialchars(
                                $edit_category["description"] ?? ""
                            ) ?></textarea>

                        </div>


                        <div style="
                            display:flex;
                            gap:10px;
                            flex-wrap:wrap;
                        ">


                            <?php if ($edit_category): ?>

                                <button
                                    type="submit"
                                    class="btn-primary"
                                >
                                    Save Changes
                                </button>


                                <a
                                    href="manage_categories.php"
                                    class="btn-secondary"
                                >
                                    Cancel
                                </a>

                            <?php else: ?>

                                <button
                                    type="submit"
                                    class="btn-primary"
                                >
                                    + Add Category
                                </button>

                            <?php endif; ?>


                        </div>


                    </form>

                </div>


                <!-- CATEGORY LIST -->

                <div class="table-container">


                    <h3>
                        Existing Categories
                    </h3>


                    <table class="data-table">


                        <thead>

                        <tr>

                            <th>ID</th>

                            <th>Name</th>

                            <th>Description</th>

                            <th>Products</th>

                            <th>Actions</th>

                        </tr>

                        </thead>


                        <tbody>


                        <?php if (count($categories) > 0): ?>


                            <?php foreach ($categories as $category): ?>

                                <tr>


                                    <td>
                                        <?= (int)$category["id"] ?>
                                    </td>


                                    <td>

                                        <strong>
                                            <?= htmlspecialchars(
                                                $category["name"]
                                            ) ?>
                                        </strong>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $category["description"] ?? ""
                                        ) ?>

                                    </td>


                                    <td>

                                        <span class="badge badge-success">

                                            <?= (int)$category["product_count"] ?>

                                        </span>

                                    </td>


                                    <td>

                                        <div style="
                                            display:flex;
                                            gap:5px;
                                            flex-wrap:wrap;
                                        ">


                                            <a
                                                href="manage_categories.php?edit=<?= $category["id"] ?>"
                                                class="btn-edit"
                                            >
                                                Edit
                                            </a>


                                            <?php if ((int)$category["product_count"] === 0): ?>

                                                <a
                                                    href="manage_categories.php?deactivate=<?= $category["id"] ?>"
                                                    class="btn-delete"
                                                    onclick="return confirm('Remove this category?')"
                                                >
                                                    Remove
                                                </a>

                                            <?php else: ?>

                                                <span
                                                    title="This category is being used by products."
                                                    style="
                                                        color:#777;
                                                        font-size:13px;
                                                    "
                                                >
                                                    In Use
                                                </span>

                                            <?php endif; ?>


                                        </div>

                                    </td>


                                </tr>

                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="5"
                                    style="
                                        text-align:center;
                                        padding:30px;
                                    "
                                >

                                    No categories have been created yet.

                                </td>

                            </tr>


                        <?php endif; ?>


                        </tbody>


                    </table>


                </div>


            </div>


        </section>


    </main>


</div>

</body>

</html>
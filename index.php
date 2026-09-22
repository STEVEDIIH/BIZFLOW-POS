<?php
session_start();

$error = $_GET["error"] ?? "";
$username = $_GET["username"] ?? "";
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>BizFlow POS</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 380px;

            background: white;

            padding: 35px;

            border-radius: 12px;

            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        }

        .logo {
            text-align: center;
            margin-bottom: 25px;
        }

        .logo h1 {
            font-size: 32px;
            margin-bottom: 5px;
        }

        .logo p {
            color: #777;
            font-size: 14px;
        }

        /* Error message */

        .login-error {
            background: #fff1f1;

            color: #c62828;

            border: 1px solid #ffcaca;

            padding: 11px 13px;

            border-radius: 7px;

            margin-bottom: 18px;

            font-size: 14px;

            text-align: center;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;

            margin-bottom: 7px;

            font-weight: bold;

            font-size: 14px;
        }

        input {
            width: 100%;

            padding: 12px;

            border: 1px solid #ddd;

            border-radius: 7px;

            font-size: 15px;
        }

        input:focus {
            outline: none;

            border-color: #333;

            box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.05);
        }

        button {
            width: 100%;

            padding: 12px;

            border: none;

            border-radius: 7px;

            background: #222;

            color: white;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

            transition: background 0.2s ease;
        }

        button:hover {
            background: #444;
        }

        button:active {
            transform: scale(0.99);
        }

        .footer {
            text-align: center;

            margin-top: 20px;

            color: #888;

            font-size: 12px;
        }

    </style>

</head>

<body>

    <div class="container">

        <?php if ($error === "invalid"): ?>

            <div class="login-error">
                Invalid username or password.
            </div>

        <?php elseif ($error === "missing"): ?>

            <div class="login-error">
                Please enter your username and password.
            </div>

        <?php elseif ($error === "role"): ?>

            <div class="login-error">
                Your account role has not been configured correctly.
            </div>

        <?php endif; ?>


        <div class="logo">

            <h1>BizFlow</h1>

            <p>Business Management & POS</p>

        </div>


        <form action="modules/auth/login.php" method="POST">

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter username"
                    value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="username"
                    required
                    autofocus
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter password"
                    autocomplete="current-password"
                    required
                >

            </div>


            <button type="submit">
                Login
            </button>

        </form>


        <div class="footer">

            BizFlow POS &copy; <?php echo date('Y'); ?>

        </div>

    </div>

</body>
</html>
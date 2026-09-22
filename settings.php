<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| BizFlow Settings
|--------------------------------------------------------------------------
| Production-oriented business settings page.
| - Authenticates the current user
| - Requires the settings permission
| - Uses CSRF protection
| - Validates all submitted values
| - Updates only the current business
| - Uses a transaction for the update
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/permissions.php';

requirePermission('settings');

$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business account is not available.');
}

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['settings_csrf_token'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Load current business
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        business_name,
        business_type,
        phone,
        email,
        address,
        currency,
        created_at,
        updated_at
    FROM businesses
    WHERE id = :business_id
    LIMIT 1
");

$stmt->execute([
    ':business_id' => $businessId
]);

$business = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$business) {
    http_response_code(404);
    exit('Business account could not be found.');
}

/*
|--------------------------------------------------------------------------
| Save settings
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === '' ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $error = 'Your session security token is invalid or has expired. Please reload the page and try again.';
    } else {

        $businessName = trim((string)($_POST['business_name'] ?? ''));
        $businessType = trim((string)($_POST['business_type'] ?? ''));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $email        = trim((string)($_POST['email'] ?? ''));
        $address      = trim((string)($_POST['address'] ?? ''));
        $currency     = trim((string)($_POST['currency'] ?? ''));

        $allowedCurrencies = [
            'KSh',
            'USD',
            'EUR',
            'GBP',
            'TZS',
            'UGX'
        ];

        if ($businessName === '') {
            $error = 'Business name is required.';
        } elseif (mb_strlen($businessName) > 150) {
            $error = 'Business name is too long.';
        } elseif (mb_strlen($businessType) > 100) {
            $error = 'Business type is too long.';
        } elseif (mb_strlen($phone) > 50) {
            $error = 'Phone number is too long.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (mb_strlen($email) > 150) {
            $error = 'Email address is too long.';
        } elseif (mb_strlen($address) > 500) {
            $error = 'Address is too long.';
        } elseif (!in_array($currency, $allowedCurrencies, true)) {
            $error = 'Please select a valid currency.';
        } else {

            try {
                $pdo->beginTransaction();

                /*
                 * The WHERE clause includes business_id only.
                 * This prevents a user from modifying another business.
                 */
                $update = $pdo->prepare("
                    UPDATE businesses
                    SET
                        business_name = :business_name,
                        business_type = :business_type,
                        phone = :phone,
                        email = :email,
                        address = :address,
                        currency = :currency
                    WHERE id = :business_id
                    LIMIT 1
                ");

                $update->execute([
                    ':business_name' => $businessName,
                    ':business_type' => $businessType !== '' ? $businessType : null,
                    ':phone'        => $phone !== '' ? $phone : null,
                    ':email'        => $email !== '' ? $email : null,
                    ':address'      => $address !== '' ? $address : null,
                    ':currency'     => $currency,
                    ':business_id'  => $businessId
                ]);

                $pdo->commit();

                $success = 'Business settings updated successfully.';

                /*
                 * Reload the saved record so the page always reflects
                 * the database rather than the submitted form.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        business_name,
                        business_type,
                        phone,
                        email,
                        address,
                        currency,
                        created_at,
                        updated_at
                    FROM businesses
                    WHERE id = :business_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':business_id' => $businessId
                ]);

                $business = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'BizFlow settings update failed for business '
                    . $businessId
                    . ': '
                    . $e->getMessage()
                );

                $error = 'Unable to save settings right now. Please try again.';
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
    <title>Settings - BizFlow</title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 25px;
        }

        .settings-card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        }

        .settings-card h3 {
            margin: 0 0 6px;
        }

        .settings-card > p {
            margin: 0 0 20px;
            color: #6b7280;
        }

        .settings-full {
            grid-column: 1 / -1;
        }

        .settings-info-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
        }

        .settings-info {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
        }

        .settings-info strong {
            display: block;
            margin-bottom: 5px;
        }

        .settings-info span {
            color: #4b5563;
            word-break: break-word;
        }

        .settings-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 5px;
        }

        .settings-security-note {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px 16px;
            color: #4b5563;
            font-size: 14px;
        }

        .settings-card input,
        .settings-card select,
        .settings-card textarea {
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 900px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }

            .settings-full {
                grid-column: auto;
            }

            .settings-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="app">

    <?php include __DIR__ . '/assets/includes/sidebar.php'; ?>

    <main class="main">

        <?php include __DIR__ . '/assets/includes/topbar.php'; ?>

        <section class="content">

            <div class="page-title">
                <div>
                    <h1>Settings</h1>
                    <p>
                        Manage your BizFlow business information and system preferences.
                    </p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <div class="settings-grid">

                    <!-- BUSINESS INFORMATION -->
                    <div class="settings-card">

                        <h3>Business Information</h3>

                        <p>
                            Basic information about your business.
                        </p>

                        <div class="form-group">
                            <label for="business_name">
                                Business Name *
                            </label>

                            <input
                                id="business_name"
                                type="text"
                                name="business_name"
                                maxlength="150"
                                value="<?= e($business['business_name'] ?? '') ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="business_type">
                                Business Type
                            </label>

                            <input
                                id="business_type"
                                type="text"
                                name="business_type"
                                maxlength="100"
                                value="<?= e($business['business_type'] ?? '') ?>"
                                placeholder="e.g. Retail Shop, Supermarket"
                            >
                        </div>

                        <div class="form-group">
                            <label for="phone">
                                Phone
                            </label>

                            <input
                                id="phone"
                                type="text"
                                name="phone"
                                maxlength="50"
                                value="<?= e($business['phone'] ?? '') ?>"
                                placeholder="e.g. 0712345678"
                            >
                        </div>

                    </div>

                    <!-- CONTACT INFORMATION -->
                    <div class="settings-card">

                        <h3>Contact Information</h3>

                        <p>
                            Information that can appear on receipts and business documents.
                        </p>

                        <div class="form-group">
                            <label for="email">
                                Email
                            </label>

                            <input
                                id="email"
                                type="email"
                                name="email"
                                maxlength="150"
                                value="<?= e($business['email'] ?? '') ?>"
                                placeholder="business@example.com"
                            >
                        </div>

                        <div class="form-group">
                            <label for="address">
                                Address
                            </label>

                            <textarea
                                id="address"
                                name="address"
                                rows="6"
                                maxlength="500"
                                placeholder="Business location/address"
                            ><?= e($business['address'] ?? '') ?></textarea>
                        </div>

                    </div>

                    <!-- CURRENCY -->
                    <div class="settings-card settings-full">

                        <h3>Currency & System</h3>

                        <p>
                            Choose the currency displayed throughout BizFlow.
                        </p>

                        <div class="form-group">
                            <label for="currency">
                                Currency *
                            </label>

                            <select
                                id="currency"
                                name="currency"
                                required
                            >
                                <?php
                                $currencyOptions = [
                                    'KSh' => 'Kenyan Shilling',
                                    'USD' => 'US Dollar',
                                    'EUR' => 'Euro',
                                    'GBP' => 'British Pound',
                                    'TZS' => 'Tanzanian Shilling',
                                    'UGX' => 'Ugandan Shilling'
                                ];
                                ?>

                                <?php foreach ($currencyOptions as $code => $label): ?>
                                    <option
                                        value="<?= e($code) ?>"
                                        <?= ($business['currency'] ?? 'KSh') === $code ? 'selected' : '' ?>
                                    >
                                        <?= e($code) ?> - <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="settings-security-note">
                            Currency changes affect how monetary values are displayed.
                            Transaction amounts already stored in the database are not converted.
                        </div>

                    </div>

                    <!-- BUSINESS ACCOUNT -->
                    <div class="settings-card settings-full">

                        <h3>Business Account</h3>

                        <p>
                            Internal information for this BizFlow business account.
                        </p>

                        <div class="settings-info-grid">

                            <div class="settings-info">
                                <strong>Business ID</strong>
                                <span><?= (int)$business['id'] ?></span>
                            </div>

                            <div class="settings-info">
                                <strong>Created</strong>
                                <span>
                                    <?= !empty($business['created_at'])
                                        ? e(date('d M Y H:i', strtotime((string)$business['created_at'])))
                                        : '-' ?>
                                </span>
                            </div>

                            <div class="settings-info">
                                <strong>Last Updated</strong>
                                <span>
                                    <?= !empty($business['updated_at'])
                                        ? e(date('d M Y H:i', strtotime((string)$business['updated_at'])))
                                        : '-' ?>
                                </span>
                            </div>

                        </div>

                    </div>

                    <!-- SAVE -->
                    <div class="settings-full settings-actions">

                        <button
                            type="submit"
                            class="btn-primary"
                        >
                            Save Settings
                        </button>

                    </div>

                </div>

            </form>

        </section>

    </main>

</div>

</body>
</html>

<?php
session_start();

require_once __DIR__ . '/../config/db.php';


function trader_db_connection() {
    global $conn, $connection, $db_conn, $db, $oracle_conn;
    global $db_user, $db_password, $db_connection;

    foreach ([$conn ?? null, $connection ?? null, $db_conn ?? null, $db ?? null, $oracle_conn ?? null] as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    if (function_exists('oci_connect') && !empty($db_user) && isset($db_password) && !empty($db_connection)) {
        return oci_connect($db_user, $db_password, $db_connection);
    }

    return null;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function oracle_error_message($resource = null) {
    return shoplocalfy_db_error_message($resource, 'A database error occurred. Please try again.');
}

$conn = trader_db_connection();
$errors = [];
$successMessage = isset($_GET['registered'])
    ? 'Registration submitted successfully. Please verify your email, then wait for admin verification before logging in.'
    : '';

if (($_GET['verify'] ?? '') === 'new-email') {
    $successMessage = 'Please verify your new email address before logging in again.';
}

if (($_GET['verified'] ?? '') === '1') {
    $successMessage = 'Email verified successfully. You can log in after admin approval.';
}
$emailValue = $_POST['email'] ?? ($_COOKIE['trader_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $emailValue = $email;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (!$conn) {
        $errors[] = 'Database connection is not available. Please try again later.';
    }

    if (!$errors) {
        $sql = '
            SELECT
                u.USER_ID,
                u.FIRST_NAME,
                u.LAST_NAME,
                u.EMAIL_ADDRESS,
                u.PASSWORD_HASH,
                u.USER_ROLE,
                u.EMAIL_VERIFIED,
                NVL(UPPER(TRIM(u.ACTIVE_STATUS)), \'ACTIVE\') AS ACTIVE_STATUS,
                t.VERIFIED_STATUS
            FROM "USER" u
            INNER JOIN TRADER t ON t.USER_ID = u.USER_ID
            WHERE LOWER(u.EMAIL_ADDRESS) = LOWER(:email)
              AND UPPER(u.USER_ROLE) = \'TRADER\'
        ';

        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':email', $email);

        if (!oci_execute($stmt)) {
            $errors[] = oracle_error_message($stmt);
        } else {
            $user = oci_fetch_assoc($stmt);

            if (!$user) {
                $errors[] = 'Invalid trader email or password.';
            } else {
                $storedPassword = (string)($user['PASSWORD_HASH'] ?? '');

                $passwordOk = password_verify($password, $storedPassword);

                if (!$passwordOk) {
                    $errors[] = 'Invalid trader email or password.';
                } elseif (array_key_exists('EMAIL_VERIFIED', $user) && (string)$user['EMAIL_VERIFIED'] === '0') {
                    $errors[] = 'Please verify your email before logging in.';
                } elseif (strtoupper((string)($user['ACTIVE_STATUS'] ?? 'ACTIVE')) !== 'ACTIVE') {
                    $errors[] = 'Your account has been suspended.';
                } else {
                    $status = strtoupper((string)($user['VERIFIED_STATUS'] ?? 'PENDING'));

                    if ($status === 'PENDING') {
                        $errors[] = 'Your trader account is waiting for admin verification.';
                    } elseif ($status === 'REJECTED') {
                        $errors[] = 'Your trader account has been rejected. Please contact the admin.';
                    } elseif ($status !== 'VERIFIED') {
                        $errors[] = 'Your trader account cannot log in because its verification status is invalid.';
                    } else {
                        session_regenerate_id(true);
                        unset($_SESSION['customer_id'], $_SESSION['CUSTOMER_ID'], $_SESSION['admin_id']);

                        $_SESSION['user_id'] = $user['USER_ID'];
                        $_SESSION['trader_user_id'] = $user['USER_ID'];
                        $_SESSION['first_name'] = $user['FIRST_NAME'];
                        $_SESSION['last_name'] = $user['LAST_NAME'];
                        $_SESSION['trader_name'] = trim($user['FIRST_NAME'] . ' ' . $user['LAST_NAME']);
                        $_SESSION['trader_email'] = $user['EMAIL_ADDRESS'];
                        $_SESSION['user_role'] = 'TRADER';
                        $_SESSION['role'] = 'trader';

                        if (isset($_POST['remember_me'])) {
                            setcookie('trader_email', $email, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                        } else {
                            setcookie('trader_email', '', time() - 3600, '/', '', false, true);
                        }

                        header('Location: index.php');
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ShopLocalfy – Trader Sign In</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/shared/css/auth.css?v=20260517">
</head>
<body>

<div class="container">
    <div class="left-panel">
        <img
            class="logo-img"
            src="../config/logos/main_project.svg"
            alt="ShopLocalfy logo"
        >
    </div>

    <div class="right-panel">
        <main class="form-card">
            <h1>Welcome back</h1>
            <p class="lead">Login as a trader to manage your shop and products.</p>

            <?php if ($successMessage): ?>
                <div class="alert alert--success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert--error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" action="login.php" method="POST" novalidate>
                <div class="field" id="field-email">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="name@gmail.com"
                        value="<?php echo e($emailValue); ?>"
                        autocomplete="email"
                        required
                    />
                </div>

                <div class="field" id="field-password">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    />
                </div>

                <div class="options-row">
                    <label class="remember" for="remember_me">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" <?php echo $emailValue !== '' ? 'checked' : ''; ?> />
                        <span>Remember email</span>
                    </label>
                </div>

                <button type="submit" class="btn-login" id="submitBtn">
                    <span class="btn-text">Login</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>

                <div class="links-row">
                    <span>Do not have a trader account? <a href="register.php">Create account</a></span>
                    <span>Want to shop products? <a href="../customer/login.php">Customer login</a></span>
                </div>
            </form>
        </main>
    </div>
</div>

<script src="../assets/trader/js/login.js?v=20260517"></script>
</body>
</html>

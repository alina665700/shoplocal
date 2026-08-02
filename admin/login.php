<?php
session_start();
define('ADMIN_COMMON_SKIP_AUTH', true);
require_once __DIR__ . '/admin_common.php';

function admin_login_redirect_target(): string {
    $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard.php';
    $redirect = basename((string)$redirect);

    if ($redirect === '' || $redirect === 'login.php' || !preg_match('/^[A-Za-z0-9_-]+\.php$/', $redirect)) {
        return 'dashboard.php';
    }

    return $redirect;
}

$redirectTarget = admin_login_redirect_target();
$loginError = '';

if (admin_current_user_id()) {
    header('Location: ' . $redirectTarget);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $loginError = 'Please enter email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginError = 'Please enter a valid admin email.';
    } else {
        try {
            $adminUser = db_one(
                $conn,
                '
                SELECT
                    u.USER_ID,
                    u.FIRST_NAME,
                    u.LAST_NAME,
                    u.EMAIL_ADDRESS,
                    u.PASSWORD_HASH,
                    u.USER_ROLE,
                    u.EMAIL_VERIFIED,
                    NVL(UPPER(TRIM(u.ACTIVE_STATUS)), \'ACTIVE\') AS ACTIVE_STATUS
                FROM "USER" u
                INNER JOIN ADMIN a ON a.USER_ID = u.USER_ID
                WHERE LOWER(u.EMAIL_ADDRESS) = LOWER(:email)
                  AND UPPER(u.USER_ROLE) = :role
                FETCH FIRST 1 ROWS ONLY
                ',
                [':email' => $email, ':role' => 'ADMIN']
            );

            $passwordOk = $adminUser && verify_user_password($password, $adminUser['PASSWORD_HASH'] ?? '');

            if (!$passwordOk) {
                $loginError = 'Invalid admin email or password.';
            } elseif (isset($adminUser['EMAIL_VERIFIED']) && (string)$adminUser['EMAIL_VERIFIED'] === '0') {
                $loginError = 'This admin account is not verified.';
            } elseif (strtoupper((string)($adminUser['ACTIVE_STATUS'] ?? 'ACTIVE')) !== 'ACTIVE') {
                $loginError = 'This admin account has been suspended.';
            } else {
                session_regenerate_id(true);
                unset(
                    $_SESSION['customer_id'],
                    $_SESSION['CUSTOMER_ID'],
                    $_SESSION['trader_user_id'],
                    $_SESSION['trader_id'],
                    $_SESSION['trader_email'],
                    $_SESSION['trader_name']
                );

                $_SESSION['admin_id'] = $adminUser['USER_ID'];
                $_SESSION['user_id'] = $adminUser['USER_ID'];
                $_SESSION['admin_email'] = $adminUser['EMAIL_ADDRESS'];
                $_SESSION['email_address'] = $adminUser['EMAIL_ADDRESS'];
                $_SESSION['first_name'] = $adminUser['FIRST_NAME'] ?? '';
                $_SESSION['last_name'] = $adminUser['LAST_NAME'] ?? '';
                $_SESSION['user_role'] = 'ADMIN';
                $_SESSION['role'] = 'ADMIN';
                $_SESSION['admin_logged_in'] = true;

                header('Location: ' . $redirectTarget);
                exit;
            }
        } catch (Throwable $e) {
            $loginError = 'Login failed. Check that the ADMIN table and first admin account exist.';
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
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/shared/css/auth.css?v=20260517">
<title>ShopLocalfy – Login</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
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
      <h1>Admin login</h1>
      <p class="lead">Login as an admin to manage ShopLocalfy.</p>

      <?php if ($loginError !== ''): ?>
        <div class="box error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
          <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Email address"
            autocomplete="email"
            required
          >
        </div>

        <div class="field">
          <input
            type="password"
            id="password"
            name="password"
            placeholder="Password"
            autocomplete="current-password"
            required
          >
        </div>

        <button class="btn-login" type="submit">Login</button>
      </form>

      <div class="links-row">
        <span>Customer area? <a href="../customer/login.php">Customer login</a></span>
        <span>Trader area? <a href="../trader/login.php">Trader login</a></span>
      </div>
    </main>
  </div>
</div>
</body>
</html>

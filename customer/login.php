<?php
require_once __DIR__ . '/customer_common.php';

$errors = [];
$success = '';
$email = trim((string)($_COOKIE['shoplocalfy_customer_email'] ?? ''));
$redirect = safe_customer_redirect('index.php');

if (($_GET['verify'] ?? '') === 'new-email') {
    $success = 'Please verify your new email address before logging in again.';
}

if (($_GET['verified'] ?? '') === '1') {
    $success = 'Email verified successfully. You can now log in.';
}

if (!empty($_SESSION['login_error'])) {
    $errors[] = (string)$_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && current_customer_id()) {
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        try {
            $user = db_one(
                $conn,
                '
                SELECT
                    USER_ID,
                    FIRST_NAME,
                    LAST_NAME,
                    EMAIL_ADDRESS,
                    PASSWORD_HASH,
                    USER_ROLE,
                    EMAIL_VERIFIED,
                    NVL(UPPER(TRIM(ACTIVE_STATUS)), \'ACTIVE\') AS ACTIVE_STATUS
                FROM "USER"
                WHERE LOWER(EMAIL_ADDRESS) = LOWER(:email)
                  AND UPPER(USER_ROLE) = :role
                FETCH FIRST 1 ROWS ONLY
                ',
                [
                    ':email' => $email,
                    ':role' => 'CUSTOMER'
                ]
            );

            if (!$user || !customer_password_matches($password, $user['PASSWORD_HASH'] ?? '')) {
                $errors[] = 'Invalid customer email or password.';
            } elseif (array_key_exists('EMAIL_VERIFIED', $user) && (string)$user['EMAIL_VERIFIED'] === '0') {
                $errors[] = 'Please verify your email before logging in.';
            } elseif (strtoupper((string)($user['ACTIVE_STATUS'] ?? 'ACTIVE')) !== 'ACTIVE') {
                $errors[] = 'Your account has been suspended.';
            } else {
                // Ensure this account has a matching CUSTOMER row before starting the session.
                $customer = db_one(
                    $conn,
                    'SELECT USER_ID FROM CUSTOMER WHERE USER_ID = :user_id',
                    [':user_id' => $user['USER_ID']]
                );

                if (!$customer) {
                    db_bind_and_execute(
                        $conn,
                        'INSERT INTO CUSTOMER (USER_ID) VALUES (:user_id)',
                        [':user_id' => $user['USER_ID']]
                    );
                }

                session_regenerate_id(true);
                set_customer_session($user);

                if ($remember) {
                    setcookie('shoplocalfy_customer_email', $email, time() + (86400 * 30), '/', '', false, true);
                } else {
                    setcookie('shoplocalfy_customer_email', '', time() - 3600, '/', '', false, true);
                }

                $pendingRedirect = customer_complete_pending_action($user['USER_ID']);
                if ($pendingRedirect !== '' && customer_is_safe_local_redirect($pendingRedirect)) {
                    $redirect = str_replace(["\r", "\n"], '', $pendingRedirect);
                }

                header('Location: ' . $redirect);
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Login failed. Please check your details and try again.';
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy - Login</title>
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
      <p class="lead">Login as a customer to continue shopping locally.</p>

      <?php if ($success !== ''): ?>
        <div class="box success"><?php echo e($success); ?></div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="box error">
          <?php foreach ($errors as $error): ?>
            <div><?php echo e($error); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php?redirect=<?php echo e(rawurlencode($redirect)); ?>">
        <input type="hidden" name="redirect" value="<?php echo e($redirect); ?>">

        <div class="field">
          <input
            type="email"
            name="email"
            placeholder="Email address"
            value="<?php echo e($email); ?>"
            autocomplete="email"
            required
          >
        </div>

        <div class="field">
          <input
            type="password"
            name="password"
            placeholder="Password"
            autocomplete="current-password"
            required
          >
        </div>

        <div class="options-row">
          <label class="remember">
            <input type="checkbox" name="remember" value="1" <?php echo $email !== '' ? 'checked' : ''; ?>>
            <span>Remember email</span>
          </label>
        </div>

        <button class="btn-login" type="submit">Login</button>
      </form>

      <div class="links-row">
        <span>Do not have an account? <a href="register.php">Create account</a></span>
        <span>Want to sell products? <a href="../trader/login.php">Trader login</a></span>
      </div>
    </main>
  </div>

</div>

</body>
</html>

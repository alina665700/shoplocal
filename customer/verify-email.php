<?php
require_once __DIR__ . '/customer_common.php';

$message = '';
$isSuccess = false;
$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    $message = 'Verification link is missing or invalid.';
} else {
    try {
        $user = db_one($conn, '
            SELECT USER_ID, FIRST_NAME, EMAIL_VERIFIED
            FROM "USER"
            WHERE EMAIL_TOKEN = :token
              AND UPPER(USER_ROLE) = :role
            FETCH FIRST 1 ROWS ONLY
        ', [':token' => $token, ':role' => 'CUSTOMER']);

        if (!$user) {
            $message = 'Verification link is invalid or has already been used.';
        } else {
            db_bind_and_execute($conn, '
                UPDATE "USER"
                SET EMAIL_VERIFIED = 1,
                    EMAIL_TOKEN = NULL
                WHERE USER_ID = :user_id
                  AND UPPER(USER_ROLE) = :role
            ', [':user_id' => $user['USER_ID'], ':role' => 'CUSTOMER']);

            $isSuccess = true;
            $message = 'Email verified successfully. You can now log in.';
        }
    } catch (Throwable $e) {
        $message = shoplocalfy_public_exception_message($e, 'Could not verify email. Please try again.');
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
  <title>Email Verification - ShopLocalfy</title>
  <link rel="stylesheet" href="../assets/customer/css/verify-email.css?v=20260517">
</head>
<body>
  <main class="card">
    <div class="status <?php echo $isSuccess ? 'ok' : 'bad'; ?>"><?php echo $isSuccess ? 'Verified' : 'Not verified'; ?></div>
    <h1>Email verification</h1>
    <p><?php echo e($message); ?></p>
    <a href="login.php?verified=1">Go to login</a>
  </main>
</body>
</html>

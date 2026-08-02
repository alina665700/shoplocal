<?php
require_once __DIR__ . '/customer_common.php';
require_once __DIR__ . '/../config/email_verification.php';

$errors = [];
$success = '';

$firstName = '';
$lastName = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email_address'] ?? '');
    $phone = trim($_POST['ph_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!ctype_digit($phone)) {
        $errors[] = 'Phone number must contain digits only.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Confirm password is required.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $existing = db_one(
                $conn,
                'SELECT USER_ID FROM "USER" WHERE LOWER(EMAIL_ADDRESS) = LOWER(:email)',
                [':email' => $email]
            );

            if ($existing) {
                $errors[] = 'Already registered. Please login instead.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not check email: ' . shoplocalfy_public_exception_message($e, 'Could not check email.');
        }
    }

    if (empty($errors)) {
        try {
            $newUserId = '';
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'CUSTOMER';
            $emailVerified = 0;
            $emailToken = shoplocalfy_generate_verification_token();

            $userSql = '
                INSERT INTO "USER" (
                    FIRST_NAME,
                    LAST_NAME,
                    EMAIL_ADDRESS,
                    PH_NUMBER,
                    PASSWORD_HASH,
                    USER_ROLE,
                    EMAIL_VERIFIED,
                    EMAIL_TOKEN,
                    DATE_CREATED
                ) VALUES (
                    :first_name,
                    :last_name,
                    :email_address,
                    :ph_number,
                    :password_hash,
                    :user_role,
                    :email_verified,
                    :email_token,
                    SYSDATE
                )
                RETURNING USER_ID INTO :new_user_id
            ';

            $stmt = oci_parse($conn, $userSql);

            if (!$stmt) {
                throw new Exception(oracle_error_message($conn));
            }

            oci_bind_by_name($stmt, ':first_name', $firstName);
            oci_bind_by_name($stmt, ':last_name', $lastName);
            oci_bind_by_name($stmt, ':email_address', $email);
            oci_bind_by_name($stmt, ':ph_number', $phone);
            oci_bind_by_name($stmt, ':password_hash', $passwordHash);
            oci_bind_by_name($stmt, ':user_role', $role);
            oci_bind_by_name($stmt, ':email_verified', $emailVerified);
            oci_bind_by_name($stmt, ':email_token', $emailToken);
            oci_bind_by_name($stmt, ':new_user_id', $newUserId, 20);

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error_message($stmt));
            }

            if (trim($newUserId) === '') {
                throw new Exception('Account could not be created. Please try again.');
            }

            $checkCustomerStmt = oci_parse($conn, 'SELECT USER_ID FROM CUSTOMER WHERE USER_ID = :user_id');

            if (!$checkCustomerStmt) {
                throw new Exception(oracle_error_message($conn));
            }

            oci_bind_by_name($checkCustomerStmt, ':user_id', $newUserId);

            if (!oci_execute($checkCustomerStmt, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error_message($checkCustomerStmt));
            }

            $customerExists = oci_fetch_assoc($checkCustomerStmt);
            oci_free_statement($checkCustomerStmt);

            if (!$customerExists) {
                $customerStmt = oci_parse($conn, 'INSERT INTO CUSTOMER (USER_ID) VALUES (:user_id)');

                if (!$customerStmt) {
                    throw new Exception(oracle_error_message($conn));
                }

                oci_bind_by_name($customerStmt, ':user_id', $newUserId);

                if (!oci_execute($customerStmt, OCI_NO_AUTO_COMMIT)) {
                    throw new Exception(oracle_error_message($customerStmt));
                }
            }

            oci_commit($conn);

            $verificationLink = shoplocalfy_absolute_url('customer/verify-email.php?token=' . rawurlencode($emailToken));
            $emailSent = shoplocalfy_send_verification_email(
                $email,
                trim($firstName . ' ' . $lastName),
                'CUSTOMER',
                $verificationLink
            );

            $success = $emailSent
                ? 'Registration successful. Please check your email and verify your account before logging in.'
                : 'Registration successful, but the verification email could not be sent automatically. Please check the mail configuration and resend/verify the account manually.';

            $firstName = '';
            $lastName = '';
            $email = '';
            $phone = '';
        } catch (Throwable $e) {
            oci_rollback($conn);
            $errors[] = 'Registration failed: ' . shoplocalfy_public_exception_message($e, 'Could not complete registration.');
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
  <title>ShopLocalfy - Register</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
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
    <main class="form-card register-card">
      <h1>Create account</h1>
      <p class="lead">Register as a customer. You will need to verify your email before logging in.</p>

      <?php if ($success !== ''): ?>
        <div class="box success">
          <?php echo e($success); ?>
          <br>
          <a href="login.php">Go to login</a>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="box error">
          <?php foreach ($errors as $error): ?>
            <div><?php echo e($error); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="register.php">
        <div class="grid">
          <div class="field">
            <input
              type="text"
              name="first_name"
              placeholder="First name"
              value="<?php echo e($firstName); ?>"
              autocomplete="given-name"
              required
            >
          </div>

          <div class="field">
            <input
              type="text"
              name="last_name"
              placeholder="Last name"
              value="<?php echo e($lastName); ?>"
              autocomplete="family-name"
              required
            >
          </div>
        </div>

        <div class="field">
          <input
            type="email"
            name="email_address"
            placeholder="Email address"
            value="<?php echo e($email); ?>"
            autocomplete="email"
            required
          >
        </div>

        <div class="field">
          <input
            type="text"
            name="ph_number"
            placeholder="Phone number"
            value="<?php echo e($phone); ?>"
            autocomplete="tel"
            required
          >
        </div>

        <div class="field">
          <input
            type="password"
            name="password"
            placeholder="Password"
            autocomplete="new-password"
            required
          >
        </div>

        <div class="field">
          <input
            type="password"
            name="confirm_password"
            placeholder="Confirm password"
            autocomplete="new-password"
            required
          >
        </div>

        <button class="btn-register" type="submit">Register</button>
      </form>

      <div class="links-row">
        <span>Already have an account? <a href="login.php">Login</a></span>
        <span>Want to sell products? <a href="../trader/register.php">Register as trader</a></span>
      </div>
    </main>
  </div>

</div>

</body>
</html>

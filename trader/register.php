<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_verification.php';

if (!isset($conn) || !$conn) {
    exit('Database connection failed. Please try again later.');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function oracle_error_message($resource = null) {
    return shoplocalfy_db_error_message($resource, 'A database error occurred. Please try again.');
}

function bind_all($stmt, array $binds) {
    $localBinds = [];

    foreach ($binds as $key => $value) {
        $bindName = substr($key, 0, 1) === ':' ? $key : ':' . $key;
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }
}

function count_rows($conn, $sql, array $binds = []) {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        throw new Exception(oracle_error_message($conn));
    }

    bind_all($stmt, $binds);

    if (!oci_execute($stmt)) {
        throw new Exception(oracle_error_message($stmt));
    }

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    return (int)($row['CNT'] ?? 0);
}

$errors = [];
$success = '';

$firstName = '';
$lastName = '';
$email = '';
$phone = '';
$panNumber = '';
$shopName = '';
$location = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email_address'] ?? '');
    $phone = trim($_POST['ph_number'] ?? '');
    $panNumber = strtoupper(trim($_POST['pan_number'] ?? ''));
    $shopName = trim($_POST['shop_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
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
    }

    if ($panNumber === '') {
        $errors[] = 'PAN number is required.';
    }

    if ($shopName === '') {
        $errors[] = 'Shop name is required.';
    }

    if ($location === '') {
        $errors[] = 'Shop location is required.';
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
            $shopLimit = 10;
            $currentShopCount = count_rows(
                $conn,
                "SELECT COUNT(*) AS CNT
                 FROM SHOP
                 WHERE UPPER(NVL(APPROVAL_STATUS, 'PENDING')) NOT IN ('REJECTED', 'SUSPENDED')"
            );

            if ($currentShopCount >= $shopLimit) {
                $errors[] = 'ShopLocalfy is limited to 10 shops. New shop registration is currently closed.';
            }

            $emailExists = count_rows(
                $conn,
                'SELECT COUNT(*) AS CNT FROM "USER" WHERE LOWER(EMAIL_ADDRESS) = LOWER(:email)',
                [':email' => $email]
            );

            if ($emailExists > 0) {
                $errors[] = 'Already registered. Try logging in.';
            }

            $panExists = count_rows(
                $conn,
                'SELECT COUNT(*) AS CNT FROM TRADER WHERE UPPER(PAN_NUMBER) = UPPER(:pan_number)',
                [':pan_number' => $panNumber]
            );

            if ($panExists > 0) {
                $errors[] = 'This PAN number is already registered.';
            }

            $shopExists = count_rows(
                $conn,
                'SELECT COUNT(*) AS CNT FROM SHOP WHERE LOWER(SHOP_NAME) = LOWER(:shop_name)',
                [':shop_name' => $shopName]
            );

            if ($shopExists > 0) {
                $errors[] = 'This shop name is already registered.';
            }

            if (empty($errors)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $newUserId = '';


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

                $userStmt = oci_parse($conn, $userSql);

                if (!$userStmt) {
                    throw new Exception(oracle_error_message($conn));
                }

                $userRole = 'TRADER';
                $emailVerified = '0';
                $emailToken = shoplocalfy_generate_verification_token();

                oci_bind_by_name($userStmt, ':first_name', $firstName);
                oci_bind_by_name($userStmt, ':last_name', $lastName);
                oci_bind_by_name($userStmt, ':email_address', $email);
                oci_bind_by_name($userStmt, ':ph_number', $phone);
                oci_bind_by_name($userStmt, ':password_hash', $passwordHash);
                oci_bind_by_name($userStmt, ':user_role', $userRole);
                oci_bind_by_name($userStmt, ':email_verified', $emailVerified);
                oci_bind_by_name($userStmt, ':email_token', $emailToken);
                oci_bind_by_name($userStmt, ':new_user_id', $newUserId, 20);

                if (!oci_execute($userStmt, OCI_NO_AUTO_COMMIT)) {
                    throw new Exception(oracle_error_message($userStmt));
                }

                oci_free_statement($userStmt);

                if (trim($newUserId) === '') {
                    throw new Exception('Trader account could not be created. Please try again.');
                }

                $traderSql = '
                    UPDATE TRADER
                    SET
                        PAN_NUMBER = :pan_number,
                        VERIFIED_STATUS = :verified_status
                    WHERE USER_ID = :user_id
                ';

                $traderStmt = oci_parse($conn, $traderSql);

                if (!$traderStmt) {
                    throw new Exception(oracle_error_message($conn));
                }

                $verifiedStatus = 'PENDING';

                oci_bind_by_name($traderStmt, ':pan_number', $panNumber);
                oci_bind_by_name($traderStmt, ':verified_status', $verifiedStatus);
                oci_bind_by_name($traderStmt, ':user_id', $newUserId);

                if (!oci_execute($traderStmt, OCI_NO_AUTO_COMMIT)) {
                    throw new Exception(oracle_error_message($traderStmt));
                }

                if (oci_num_rows($traderStmt) !== 1) {
                    throw new Exception('Trader account could not be created. Please try again.');
                }

                oci_free_statement($traderStmt);

                $shopSql = '
                    INSERT INTO SHOP (
                        TRADER_ID,
                        SHOP_NAME,
                        LOCATION,
                        APPROVAL_STATUS
                    ) VALUES (
                        :trader_id,
                        :shop_name,
                        :location,
                        :approval_status
                    )
                ';

                $shopStmt = oci_parse($conn, $shopSql);

                if (!$shopStmt) {
                    throw new Exception(oracle_error_message($conn));
                }

                $shopApprovalStatus = 'PENDING';

                oci_bind_by_name($shopStmt, ':trader_id', $newUserId);
                oci_bind_by_name($shopStmt, ':shop_name', $shopName);
                oci_bind_by_name($shopStmt, ':location', $location);
                oci_bind_by_name($shopStmt, ':approval_status', $shopApprovalStatus);

                if (!oci_execute($shopStmt, OCI_NO_AUTO_COMMIT)) {
                    throw new Exception(oracle_error_message($shopStmt));
                }

                oci_free_statement($shopStmt);
                oci_commit($conn);

                $verificationLink = shoplocalfy_absolute_url('trader/verify-email.php?token=' . rawurlencode($emailToken));
                $emailSent = shoplocalfy_send_verification_email(
                    $email,
                    trim($firstName . ' ' . $lastName),
                    'TRADER',
                    $verificationLink
                );

                $success = $emailSent
                    ? 'Registration submitted successfully. Please verify your email. After that, wait for admin approval before logging in.'
                    : 'Registration submitted successfully, but the verification email could not be sent automatically. Please check the mail configuration and resend/verify the account manually. After verification, wait for admin approval.';

                $firstName = '';
                $lastName = '';
                $email = '';
                $phone = '';
                $panNumber = '';
                $shopName = '';
                $location = '';
            }
        } catch (Exception $e) {
            oci_rollback($conn);

            $errors[] = 'Registration failed: ' . shoplocalfy_public_exception_message($e, 'Could not complete trader registration.');
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
    <title>Trader Register | ShopLocalfy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <main class="form-card register-card">
                <h1>Create trader account</h1>
                <p class="lead">
                    Register your trader account. You must verify your email first, then wait for admin approval before logging in.
                </p>

                <?php if (!empty($errors)): ?>
                    <div class="box error">
                        <?php foreach ($errors as $error): ?>
                            <div>
                                <?= h($error) ?>
                                <?php if ($error === 'Already registered. Try logging in.'): ?>
                                    <a href="login.php"> Login</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="box success">
                        <?= h($success) ?>
                        <br>
                        <a href="login.php">Go to login</a>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" autocomplete="off">
                    <div class="grid">
                        <div class="field">
                            <input
                                type="text"
                                id="first_name"
                                name="first_name"
                                placeholder="First name"
                                value="<?= h($firstName) ?>"
                                autocomplete="given-name"
                                required
                            >
                        </div>

                        <div class="field">
                            <input
                                type="text"
                                id="last_name"
                                name="last_name"
                                placeholder="Last name"
                                value="<?= h($lastName) ?>"
                                autocomplete="family-name"
                                required
                            >
                        </div>
                    </div>

                    <div class="field">
                        <input
                            type="email"
                            id="email_address"
                            name="email_address"
                            placeholder="Email address"
                            value="<?= h($email) ?>"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div class="grid">
                        <div class="field">
                            <input
                                type="text"
                                id="ph_number"
                                name="ph_number"
                                placeholder="Phone number"
                                value="<?= h($phone) ?>"
                                autocomplete="tel"
                                required
                            >
                        </div>

                        <div class="field">
                            <input
                                type="text"
                                id="pan_number"
                                name="pan_number"
                                placeholder="PAN number"
                                value="<?= h($panNumber) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="grid">
                        <div class="field">
                            <input
                                type="text"
                                id="shop_name"
                                name="shop_name"
                                placeholder="Shop name"
                                value="<?= h($shopName) ?>"
                                required
                            >
                        </div>

                        <div class="field">
                            <input
                                type="text"
                                id="location"
                                name="location"
                                placeholder="Shop location"
                                value="<?= h($location) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="field">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Confirm password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <button type="submit" class="btn-register">Register</button>

                    <div class="links-row">
                        <span>Already have a trader account? <a href="login.php">Login</a></span>
                        <span>Want to shop products? <a href="../customer/register.php">Customer register</a></span>
                    </div>
                </form>
            </main>
        </div>
    </div>
</body>
</html>

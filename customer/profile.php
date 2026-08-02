<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/customer_common.php';
require_customer_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_verification.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function db_run(string $sql, array $binds = [], int $mode = OCI_COMMIT_ON_SUCCESS) {
    global $conn;

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        exit(h(shoplocalfy_db_error_message($conn, 'Could not prepare profile request.')));
    }

    $localBinds = [];
    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim((string)$key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!oci_execute($stmt, $mode)) {
        exit(h(shoplocalfy_db_error_message($stmt, 'Could not update profile.')));
    }

    return $stmt;
}

function fetch_one(string $sql, array $binds = []): ?array {
    $stmt = db_run($sql, $binds);
    $row = oci_fetch_assoc($stmt);
    return $row ?: null;
}

function get_customer_profile(string $customerId): ?array {
    return fetch_one(
        "SELECT
            u.user_id,
            u.first_name,
            u.last_name,
            u.email_address,
            u.ph_number,
            u.password_hash,
            u.user_role,
            u.email_verified,
            TO_CHAR(u.date_created, 'YYYY-MM-DD') AS date_created
         FROM \"USER\" u
         JOIN CUSTOMER c ON c.user_id = u.user_id
         WHERE u.user_id = :user_id
           AND u.user_role = :role",
        ['user_id' => $customerId, 'role' => 'CUSTOMER']
    );
}

function initials_from_name(string $firstName, string $lastName): string {
    $firstInitial = $firstName !== '' ? substr($firstName, 0, 1) : '';
    $lastInitial = $lastName !== '' ? substr($lastName, 0, 1) : '';
    $initials = strtoupper($firstInitial . $lastInitial);
    return $initials !== '' ? $initials : 'U';
}

$customerId = current_customer_id();
if (!$customerId) {
    header('Location: login.php');
    exit;
}

$profile = get_customer_profile($customerId);
if (!$profile) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$errors = [];
$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email_address'] ?? '');
        $phone = preg_replace('/\D+/', '', (string)($_POST['ph_number'] ?? ''));
        $profileCurrentPassword = (string)($_POST['profile_current_password'] ?? '');

        if ($firstName === '') {
            $errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } elseif (strlen($phone) > 15) {
            $errors[] = 'Phone number must be 15 digits or less.';
        }

        if ($profileCurrentPassword === '') {
            $errors[] = 'Enter your current password to save profile changes.';
        } else {
            $storedHash = (string)($profile['PASSWORD_HASH'] ?? '');
            if ($storedHash === '' || !password_verify($profileCurrentPassword, $storedHash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (!$errors) {
            $duplicate = fetch_one(
                'SELECT COUNT(*) AS total
                 FROM "USER"
                 WHERE LOWER(email_address) = LOWER(:email_address)
                   AND user_id <> :user_id',
                ['email_address' => $email, 'user_id' => $customerId]
            );

            if ((int)($duplicate['TOTAL'] ?? 0) > 0) {
                $errors[] = 'That email address is already used by another account.';
            }
        }

        if (!$errors) {
            $emailChanged = strtolower($email) !== strtolower((string)$profile['EMAIL_ADDRESS']);
            $emailVerified = $emailChanged ? 0 : (int)$profile['EMAIL_VERIFIED'];
            $emailToken = $emailChanged ? shoplocalfy_generate_verification_token() : null;

            db_run(
                'UPDATE "USER"
                 SET first_name = :first_name,
                     last_name = :last_name,
                     email_address = :email_address,
                     ph_number = :ph_number,
                     email_verified = :email_verified,
                     email_token = :email_token
                 WHERE user_id = :user_id
                   AND user_role = :role',
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email_address' => $email,
                    'ph_number' => $phone,
                    'email_verified' => $emailVerified,
                    'email_token' => $emailToken,
                    'user_id' => $customerId,
                    'role' => 'CUSTOMER',
                ]
            );

            if ($emailChanged) {
                $verificationLink = shoplocalfy_absolute_url('customer/verify-email.php?token=' . rawurlencode((string)$emailToken));
                shoplocalfy_send_verification_email($email, trim($firstName . ' ' . $lastName), 'CUSTOMER', $verificationLink);

                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();

                header('Location: login.php?verify=new-email');
                exit;
            }

            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $_SESSION['email_address'] = $email;
            $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);
            $_SESSION['profile_success'] = 'Profile updated.';

            header('Location: profile.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '') {
            $errors[] = 'Current password is required.';
        }
        if (strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirm password do not match.';
        }

        $storedHash = (string)$profile['PASSWORD_HASH'];
        $passwordOk = password_verify($currentPassword, $storedHash);

        if (!$passwordOk) {
            $errors[] = 'Current password is incorrect.';
        }

        if (!$errors) {
            db_run(
                'UPDATE "USER"
                 SET password_hash = :password_hash
                 WHERE user_id = :user_id
                   AND user_role = :role',
                [
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'user_id' => $customerId,
                    'role' => 'CUSTOMER',
                ]
            );

            $_SESSION['profile_success'] = 'Password updated.';
            header('Location: profile.php');
            exit;
        }
    }

    if ($action === 'save_profile') {
        $profile['FIRST_NAME'] = trim($_POST['first_name'] ?? $profile['FIRST_NAME']);
        $profile['LAST_NAME'] = trim($_POST['last_name'] ?? $profile['LAST_NAME']);
        $profile['EMAIL_ADDRESS'] = trim($_POST['email_address'] ?? $profile['EMAIL_ADDRESS']);
        $profile['PH_NUMBER'] = preg_replace('/\D+/', '', (string)($_POST['ph_number'] ?? $profile['PH_NUMBER']));
    }
}

$firstName = (string)($profile['FIRST_NAME'] ?? '');
$lastName = (string)($profile['LAST_NAME'] ?? '');
$fullName = trim($firstName . ' ' . $lastName);
$emailAddress = (string)($profile['EMAIL_ADDRESS'] ?? '');
$phoneNumber = (string)($profile['PH_NUMBER'] ?? '');
$dateCreated = (string)($profile['DATE_CREATED'] ?? '');
$emailVerified = (int)($profile['EMAIL_VERIFIED'] ?? 0);
$avatarInitials = initials_from_name($firstName, $lastName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Profile — ShopLocalfy</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&family=DM+Serif+Display&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../assets/customer/css/profile.css?v=20260517">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

  <div class="profile-wrapper">
    <div class="profile-inner">

      <div class="page-header">
        <h1>Personal information</h1>
        <p>Manage your profile and account settings.</p>
      </div>

      <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= h($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-label">Profile</div>
        <div class="profile-row">
          <div class="avatar"><?= h($avatarInitials) ?></div>
          <div class="profile-meta">
            <strong><?= h($fullName !== '' ? $fullName : 'Customer') ?></strong>
            <span><?= h($emailAddress) ?></span>
            <span>Avatar is generated from your name.</span>
          </div>
        </div>
      </div>

      <form method="post" action="profile.php" autocomplete="on">
        <input type="hidden" name="action" value="save_profile">

        <div class="card">
          <div class="section-title">Full name</div>
          <div class="section-desc">Your name shown on orders and receipts.</div>
          <div class="two-col">
            <div class="field-group">
              <label class="field-label" for="first_name">First name</label>
              <input class="field-input" id="first_name" name="first_name" type="text" value="<?= h($firstName) ?>" placeholder="First name" required />
            </div>
            <div class="field-group">
              <label class="field-label" for="last_name">Last name</label>
              <input class="field-input" id="last_name" name="last_name" type="text" value="<?= h($lastName) ?>" placeholder="Last name" required />
            </div>
          </div>
        </div>

        <div class="card">
          <div class="section-title">Contact details</div>
          <div class="section-desc">Used for order confirmations and account messages.</div>
          <div class="field-group">
            <label class="field-label" for="email_address">Email</label>
            <div class="email-wrap">
              <span class="email-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
              </span>
              <input class="field-input" id="email_address" name="email_address" type="email" value="<?= h($emailAddress) ?>" placeholder="Email" required />
            </div>
          </div>
          <div class="field-group">
            <label class="field-label" for="ph_number">Phone number</label>
            <input class="field-input" id="ph_number" name="ph_number" type="text" inputmode="numeric" maxlength="15" value="<?= h($phoneNumber) ?>" placeholder="Phone number" required />
          </div>
          <div class="field-group">
            <label class="field-label" for="profile-current-pass">Current password</label>
            <div class="pass-wrap">
              <span class="pass-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
              </span>
              <input class="field-input" id="profile-current-pass" name="profile_current_password" type="password" placeholder="Enter current password to save changes" required />
              <button class="pass-toggle" type="button" onclick="togglePass('profile-current-pass',this)">show</button>
            </div>
          </div>
          <div class="security-row">
            <button class="btn btn-primary" type="submit">Save profile</button>
          </div>
        </div>
      </form>

      <form method="post" action="profile.php" autocomplete="off">
        <input type="hidden" name="action" value="change_password">

        <div class="card">
          <div class="section-title">Password</div>
          <div class="section-desc">Update your password. Your existing password is never shown on this page.</div>
          <div class="field-group">
            <label class="field-label" for="cur-pass">Current password</label>
            <div class="pass-wrap">
              <span class="pass-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
              </span>
              <input class="field-input" name="current_password" type="password" id="cur-pass" placeholder="Current password" />
              <button class="pass-toggle" type="button" onclick="togglePass('cur-pass',this)">show</button>
            </div>
          </div>
          <div class="two-col">
            <div class="field-group">
              <label class="field-label" for="new-pass">New password</label>
              <div class="pass-wrap">
                <span class="pass-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                </span>
                <input class="field-input" name="new_password" type="password" id="new-pass" placeholder="New password" />
                <button class="pass-toggle" type="button" onclick="togglePass('new-pass',this)">show</button>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label" for="confirm-pass">Confirm password</label>
              <div class="pass-wrap">
                <span class="pass-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                </span>
                <input class="field-input" name="confirm_password" type="password" id="confirm-pass" placeholder="Confirm password" />
                <button class="pass-toggle" type="button" onclick="togglePass('confirm-pass',this)">show</button>
              </div>
            </div>
          </div>
          <div class="security-row">
            <button class="btn btn-primary" type="submit">Update password</button>
          </div>
        </div>
      </form>

      <div class="card">
        <div class="section-title">Account details</div>
        <div class="section-desc">Loaded from the logged-in customer account.</div>
        <div class="status-grid">
          <div class="status-item">
            <span class="status-label">User ID</span>
            <span class="status-value"><?= h($customerId) ?></span>
          </div>
          <div class="status-item">
            <span class="status-label">Role</span>
            <span class="status-value">Customer</span>
          </div>
          <div class="status-item">
            <span class="status-label">Email status</span>
            <span class="status-value">
              <span class="pill <?= $emailVerified ? 'pill-ok' : '' ?>"><?= $emailVerified ? 'Verified' : 'Not verified' ?></span>
            </span>
          </div>
          <div class="status-item">
            <span class="status-label">Joined</span>
            <span class="status-value"><?= h($dateCreated !== '' ? $dateCreated : 'Not available') ?></span>
          </div>
        </div>
        <p class="danger-note">For account deletion requests, please contact support.</p>
      </div>

    </div>
  </div>

  <script src="../assets/customer/js/profile.js?v=20260517"></script>

</body>
</html>
<?php include __DIR__ . '/footer.php'; ?>

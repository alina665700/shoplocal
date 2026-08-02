<?php


require_once __DIR__ . '/trader_common.php';
require_once __DIR__ . '/../config/email_verification.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$errors = [];
$success = '';

function first_existing_column($conn, $tableName, array $candidates) {
    foreach ($candidates as $column) {
        if (column_exists($conn, $tableName, $column)) {
            return strtoupper($column);
        }
    }
    return null;
}

function get_profile_details($conn, $traderId, &$errors) {
    $details = [
        'USER_ID' => $traderId,
        'FIRST_NAME' => '',
        'LAST_NAME' => '',
        'EMAIL_ADDRESS' => '',
        'PH_NUMBER' => '',
        'PASSWORD_HASH' => '',
        'USER_ROLE' => 'TRADER',
        'EMAIL_VERIFIED' => 0,
        'DATE_CREATED' => '',
        'PAN_NUMBER' => '',
        'VERIFIED_STATUS' => 'PENDING',
        'SHOP_ID' => '',
        'SHOP_NAME' => '',
        'APPROVAL_STATUS' => '',
        'SHOP_ADDRESS' => '',
        'SHOP_ADDRESS_COLUMN' => '',
    ];

    if (!$conn) {
        $errors[] = 'Database connection is not available.';
        return $details;
    }

    try {
        $userRow = db_one($conn, '
            SELECT
                u.USER_ID,
                u.FIRST_NAME,
                u.LAST_NAME,
                u.EMAIL_ADDRESS,
                u.PH_NUMBER,
                u.PASSWORD_HASH,
                u.USER_ROLE,
                u.EMAIL_VERIFIED,
                TO_CHAR(u.DATE_CREATED, \'YYYY-MM-DD\') AS DATE_CREATED,
                t.PAN_NUMBER,
                t.VERIFIED_STATUS
            FROM "USER" u
            INNER JOIN TRADER t ON t.USER_ID = u.USER_ID
            WHERE u.USER_ID = :trader_id
        ', [':trader_id' => $traderId]);

        if ($userRow) {
            $details = array_merge($details, $userRow);
        }

        if (table_exists($conn, 'SHOP')) {
            $shopSelect = ['SHOP_ID'];

            if (column_exists($conn, 'SHOP', 'SHOP_NAME')) {
                $shopSelect[] = 'SHOP_NAME';
            }
            if (column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
                $shopSelect[] = 'APPROVAL_STATUS';
            }

            $addressColumn = first_existing_column($conn, 'SHOP', [
                'SHOP_ADDRESS',
                'BUSINESS_ADDRESS',
                'ADDRESS',
                'SHOP_LOCATION',
                'LOCATION'
            ]);

            if ($addressColumn) {
                $shopSelect[] = $addressColumn . ' AS SHOP_ADDRESS';
                $details['SHOP_ADDRESS_COLUMN'] = $addressColumn;
            }

            $shopSql = 'SELECT ' . implode(', ', $shopSelect) . '
                        FROM SHOP
                        WHERE TRADER_ID = :trader_id
                        ORDER BY SHOP_ID
                        FETCH FIRST 1 ROWS ONLY';

            $shopRow = db_one($conn, $shopSql, [':trader_id' => $traderId]);
            if ($shopRow) {
                $details = array_merge($details, $shopRow);
                $details['SHOP_ADDRESS_COLUMN'] = $addressColumn ?: '';
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Profile query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load profile.');
    }

    return $details;
}

function profile_display_name(array $details) {
    return trim(($details['FIRST_NAME'] ?? '') . ' ' . ($details['LAST_NAME'] ?? '')) ?: 'Trader Profile';
}

function update_profile($conn, $traderId, array $post, array $currentDetails, &$errors) {
    if (!$conn) {
        $errors[] = 'Database connection is not available.';
        return false;
    }

    $firstName = trim($post['first_name'] ?? '');
    $lastName  = trim($post['last_name'] ?? '');
    $email     = strtolower(trim($post['email_address'] ?? ''));
    $phone     = trim($post['phone'] ?? '');
    $panNumber = trim($post['pan_number'] ?? '');
    $shopName  = trim($post['shop_name'] ?? '');
    $address   = trim($post['business_address'] ?? '');
    $password  = (string)($post['password'] ?? '');
    $confirm   = (string)($post['confirm_password'] ?? '');
    $currentPassword = (string)($post['current_password'] ?? '');

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }

    if ($currentPassword === '') {
        $errors[] = 'Enter your current password to save profile changes.';
    } else {
        $storedHash = (string)($currentDetails['PASSWORD_HASH'] ?? '');
        if ($storedHash === '' || !password_verify($currentPassword, $storedHash)) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if ($password !== '') {
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
    }

    if ($errors) {
        return false;
    }

    $emailChanged = strtolower((string)($currentDetails['EMAIL_ADDRESS'] ?? '')) !== $email;

    try {
        $userSets = [
            'FIRST_NAME = :first_name',
            'LAST_NAME = :last_name',
            'EMAIL_ADDRESS = :email_address',
            'PH_NUMBER = :phone'
        ];

        $userBinds = [
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email_address' => $email,
            ':phone' => $phone,
            ':trader_id' => $traderId
        ];

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $userSets[] = 'PASSWORD_HASH = :password_hash';
            $userBinds[':password_hash'] = $passwordHash;
        }

        $emailToken = null;
        if ($emailChanged && column_exists($conn, 'USER', 'EMAIL_VERIFIED')) {
            $emailToken = shoplocalfy_generate_verification_token();
            $userSets[] = 'EMAIL_VERIFIED = 0';
        }
        if ($emailChanged && column_exists($conn, 'USER', 'EMAIL_TOKEN')) {
            $userSets[] = 'EMAIL_TOKEN = :email_token';
            $userBinds[':email_token'] = $emailToken;
        }

        $userSql = 'UPDATE "USER" SET ' . implode(', ', $userSets) . ' WHERE USER_ID = :trader_id';
        db_bind_and_execute($conn, $userSql, $userBinds, OCI_NO_AUTO_COMMIT);

        if (table_exists($conn, 'TRADER') && column_exists($conn, 'TRADER', 'PAN_NUMBER')) {
            db_bind_and_execute($conn, '
                UPDATE TRADER
                SET PAN_NUMBER = :pan_number
                WHERE USER_ID = :trader_id
            ', [':pan_number' => $panNumber, ':trader_id' => $traderId], OCI_NO_AUTO_COMMIT);
        }

        if (table_exists($conn, 'SHOP') && !empty($currentDetails['SHOP_ID'])) {
            $shopSets = [];
            $shopBinds = [':shop_id' => $currentDetails['SHOP_ID']];

            if (column_exists($conn, 'SHOP', 'SHOP_NAME')) {
                $shopSets[] = 'SHOP_NAME = :shop_name';
                $shopBinds[':shop_name'] = $shopName;
            }

            $addressColumn = $currentDetails['SHOP_ADDRESS_COLUMN'] ?? '';
            if ($addressColumn && column_exists($conn, 'SHOP', $addressColumn)) {
                $shopSets[] = $addressColumn . ' = :business_address';
                $shopBinds[':business_address'] = $address;
            }

            if ($shopSets) {
                $shopSql = 'UPDATE SHOP SET ' . implode(', ', $shopSets) . ' WHERE SHOP_ID = :shop_id';
                db_bind_and_execute($conn, $shopSql, $shopBinds, OCI_NO_AUTO_COMMIT);
            }
        }

        if (!oci_commit($conn)) {
            throw new Exception(oracle_error_message($conn));
        }

        if ($emailChanged && $emailToken !== null) {
            $verificationLink = shoplocalfy_absolute_url('trader/verify-email.php?token=' . rawurlencode((string)$emailToken));
            shoplocalfy_send_verification_email($email, trim($firstName . ' ' . $lastName), 'TRADER', $verificationLink);

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
        $_SESSION['trader_email'] = $email;

        return true;
    } catch (Throwable $e) {
        @oci_rollback($conn);
        $errors[] = 'Profile update failed: ' . shoplocalfy_public_exception_message($e, 'Could not update profile.');
        return false;
    }
}

$currentDetails = get_profile_details($conn, $traderId, $errors);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postErrors = [];
    if (update_profile($conn, $traderId, $_POST, $currentDetails, $postErrors)) {
        $success = 'Profile updated successfully.';
        $currentDetails = get_profile_details($conn, $traderId, $errors);
    } else {
        $errors = array_merge($errors, $postErrors);
        $currentDetails = array_merge($currentDetails, [
            'FIRST_NAME' => trim($_POST['first_name'] ?? $currentDetails['FIRST_NAME']),
            'LAST_NAME' => trim($_POST['last_name'] ?? $currentDetails['LAST_NAME']),
            'EMAIL_ADDRESS' => trim($_POST['email_address'] ?? $currentDetails['EMAIL_ADDRESS']),
            'PH_NUMBER' => trim($_POST['phone'] ?? $currentDetails['PH_NUMBER']),
            'PAN_NUMBER' => trim($_POST['pan_number'] ?? $currentDetails['PAN_NUMBER']),
            'SHOP_NAME' => trim($_POST['shop_name'] ?? $currentDetails['SHOP_NAME']),
            'SHOP_ADDRESS' => trim($_POST['business_address'] ?? $currentDetails['SHOP_ADDRESS']),
        ]);
    }
}

$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);
$displayName = profile_display_name($currentDetails);
$emailVerified = ((int)($currentDetails['EMAIL_VERIFIED'] ?? 0)) === 1;
$traderVerified = strtoupper((string)($currentDetails['VERIFIED_STATUS'] ?? '')) === 'VERIFIED';
$shopHasAddress = !empty($currentDetails['SHOP_ADDRESS_COLUMN']);
$shopExists = !empty($currentDetails['SHOP_ID']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ShopLocalfy - Trader Profile</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/profile.css?v=20260517">
</head>
<body>
<?php $active = 'profile'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>

<div class="main">
  <?php render_topbar('Trader Profile', 'Manage your account settings'); ?>

  <div class="body">
    <div class="profile-page-header">
      <div class="profile-page-header-left">
        <h1>Personal Information</h1>
        <p>Update your real trader account details from the database.</p>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert error">
        <strong>Please check this:</strong>
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?php echo e($error); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form id="profileForm" method="POST" action="profile.php" autocomplete="on">
      <div class="profile-card">
        <div class="profile-card-head">
          <h2>Account Details</h2>
          <p>Update your account and shop details.</p>
        </div>

        <div class="profile-card-body">
          <div class="account-summary">
            <div class="summary-tile">
              <div class="summary-label">Trader</div>
              <div class="summary-value"><?php echo e($displayName); ?></div>
            </div>
            <div class="summary-tile">
              <div class="summary-label">User ID</div>
              <div class="summary-value"><?php echo e($currentDetails['USER_ID']); ?></div>
            </div>
            <div class="summary-tile">
              <div class="summary-label">Trader Status</div>
              <div class="summary-value">
                <span class="status-badge <?php echo $traderVerified ? 'good' : 'warn'; ?>">
                  <?php echo e($currentDetails['VERIFIED_STATUS'] ?: 'PENDING'); ?>
                </span>
              </div>
            </div>
            <div class="summary-tile">
              <div class="summary-label">Email</div>
              <div class="summary-value">
                <span class="status-badge <?php echo $emailVerified ? 'good' : 'warn'; ?>">
                  <?php echo $emailVerified ? 'Verified' : 'Not verified'; ?>
                </span>
              </div>
            </div>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label" for="firstName">First Name</label>
              <input type="text" id="firstName" name="first_name" class="form-input" value="<?php echo e($currentDetails['FIRST_NAME']); ?>" placeholder="Enter first name" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="lastName">Last Name</label>
              <input type="text" id="lastName" name="last_name" class="form-input" value="<?php echo e($currentDetails['LAST_NAME']); ?>" placeholder="Enter last name" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="emailInput">Email Address</label>
              <input type="email" id="emailInput" name="email_address" class="form-input" value="<?php echo e($currentDetails['EMAIL_ADDRESS']); ?>" placeholder="Enter email address" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="phoneInput">Phone Number</label>
              <input type="tel" id="phoneInput" name="phone" class="form-input" value="<?php echo e($currentDetails['PH_NUMBER']); ?>" placeholder="+977-XXXXXXXXXX" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="panInput">PAN Number</label>
              <input type="text" id="panInput" name="pan_number" class="form-input" value="<?php echo e($currentDetails['PAN_NUMBER']); ?>" placeholder="Enter PAN number" />
            </div>

            <div class="form-group">
              <label class="form-label" for="dateCreated">Date Created</label>
              <input type="text" id="dateCreated" class="form-input" value="<?php echo e($currentDetails['DATE_CREATED']); ?>" disabled />
            </div>

            <div class="form-group full">
              <label class="form-label" for="currentPassword">Current Password</label>
              <input type="password" id="currentPassword" name="current_password" class="form-input" placeholder="Enter current password to save any profile changes" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="passwordInput">New Password</label>
              <input type="password" id="passwordInput" name="password" class="form-input" />
              <div class="pw-strength"><div class="pw-strength-bar" id="pwStrengthBar"></div></div>
            </div>

            <div class="form-group">
              <label class="form-label" for="confirmPassword">Confirm New Password</label>
              <input type="password" id="confirmPassword" name="confirm_password" class="form-input" placeholder="Re-enter new password" />
            </div>

            <?php if ($shopExists): ?>
              <div class="form-group">
                <label class="form-label" for="shopName">Shop Name</label>
                <input type="text" id="shopName" name="shop_name" class="form-input" value="<?php echo e($currentDetails['SHOP_NAME']); ?>" placeholder="Enter shop name" />
          
              </div>

              <div class="form-group">
                <label class="form-label" for="shopStatus">Shop Approval Status</label>
                <input type="text" id="shopStatus" class="form-input" value="<?php echo e($currentDetails['APPROVAL_STATUS']); ?>" disabled />
              </div>

              <?php if ($shopHasAddress): ?>
                <div class="form-group full">
                  <label class="form-label" for="addressInput">Business Address</label>
                  <textarea id="addressInput" name="business_address" class="form-input" placeholder="Enter business address..."><?php echo e($currentDetails['SHOP_ADDRESS']); ?></textarea>
      
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="form-group full">
                <div class="muted-box">
                  No SHOP row was found for this trader. The page can update USER and TRADER details now. Shop name/address can only be edited after a SHOP record exists for this trader.
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="profile-actions">
          <button type="reset" class="btn-discard">Discard Change</button>
          <button type="submit" class="btn-save-continue" id="saveContinueBtn">Save &amp; Continue</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="toast"></div>

<script src="../assets/trader/js/profile.js?v=20260517"></script>
</body>
</html>


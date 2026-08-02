<?php
require_once __DIR__ . '/admin_common.php';

$conn = admin_db_connection();

$adminId = require_admin_login();
$errors = [];
$notices = [];

function profile_admin_row($adminId) {
    global $conn;

    return db_one($conn, '
        SELECT
            u.USER_ID,
            u.FIRST_NAME,
            u.LAST_NAME,
            u.EMAIL_ADDRESS,
            u.PH_NUMBER,
            u.PASSWORD_HASH,
            u.USER_ROLE,
            u.EMAIL_VERIFIED
        FROM "USER" u
        INNER JOIN ADMIN a ON a.USER_ID = u.USER_ID
        WHERE u.USER_ID = :admin_id
    ', [':admin_id' => $adminId]);
}

function profile_initials($first, $last) {
    $first = trim((string)$first);
    $last = trim((string)$last);
    $a = $first !== '' ? strtoupper(substr($first, 0, 1)) : 'A';
    $b = $last !== '' ? strtoupper(substr($last, 0, 1)) : 'D';
    return $a . $b;
}

$admin = $adminId ? profile_admin_row($adminId) : null;

if (!$admin) {
    $errors[] = 'Admin profile could not be loaded. Check that this user still exists in ADMIN and "USER".';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email_address'] ?? ''));
        $phone = trim((string)($_POST['ph_number'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            $errors[] = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } else {
            try {
                $existing = db_one($conn, '
                    SELECT USER_ID
                    FROM "USER"
                    WHERE LOWER(EMAIL_ADDRESS) = LOWER(:email)
                      AND USER_ID <> :admin_id
                    FETCH FIRST 1 ROWS ONLY
                ', [':email' => $email, ':admin_id' => $adminId]);

                if ($existing) {
                    $errors[] = 'Another account already uses that email address.';
                } else {
                    db_bind_and_execute($conn, '
                        UPDATE "USER"
                        SET FIRST_NAME = :first_name,
                            LAST_NAME = :last_name,
                            EMAIL_ADDRESS = :email_address,
                            PH_NUMBER = :ph_number
                        WHERE USER_ID = :admin_id
                    ', [
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':email_address' => $email,
                        ':ph_number' => $phone !== '' ? $phone : null,
                        ':admin_id' => $adminId,
                    ]);

                    $_SESSION['first_name'] = $firstName;
                    $_SESSION['last_name'] = $lastName;
                    $_SESSION['admin_email'] = $email;
                    $_SESSION['email_address'] = $email;
                    $notices[] = 'Profile details updated.';
                    $admin = profile_admin_row($adminId);
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not update profile: ' . shoplocalfy_public_exception_message($e, 'Could not update profile.');
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errors[] = 'Fill all password fields.';
        } elseif (!verify_user_password($currentPassword, $admin['PASSWORD_HASH'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            try {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                db_bind_and_execute($conn, '
                    UPDATE "USER"
                    SET PASSWORD_HASH = :password_hash
                    WHERE USER_ID = :admin_id
                ', [':password_hash' => $hash, ':admin_id' => $adminId]);

                $notices[] = 'Password changed successfully.';
                $admin = profile_admin_row($adminId);
            } catch (Throwable $e) {
                $errors[] = 'Could not change password: ' . shoplocalfy_public_exception_message($e, 'Could not change password.');
            }
        }
    }
}

$fullName = trim((string)($admin['FIRST_NAME'] ?? '') . ' ' . (string)($admin['LAST_NAME'] ?? ''));
if ($fullName === '') $fullName = 'Admin User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Profile - ShopLocalfy</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin/css/profile.css?v=20260517">
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main-content">
  <?php include 'topbar.php'; ?>
  <section class="content">
    <div class="page-head">
      <div>
        <div class="eyebrow">Account settings</div>
        <h1>Admin Profile</h1>
        <p class="subtitle">Manage your admin name, phone number, email, and password.</p>
      </div>
      <div class="profile-pill">Logged in as <?php echo admin_h($admin['EMAIL_ADDRESS'] ?? 'admin'); ?></div>
    </div>

    <?php foreach ($notices as $notice): ?><div class="notice"><?php echo admin_h($notice); ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="error"><?php echo admin_h($error); ?></div><?php endforeach; ?>

    <?php if ($admin): ?>
    <div class="grid">
      <aside class="card summary-card">
        <div class="avatar-lg"><?php echo admin_h(profile_initials($admin['FIRST_NAME'] ?? '', $admin['LAST_NAME'] ?? '')); ?></div>
        <h2><?php echo admin_h($fullName); ?></h2>
        <p><?php echo admin_h($admin['EMAIL_ADDRESS'] ?? ''); ?></p>
        <span class="role"><?php echo admin_h($admin['USER_ROLE'] ?? 'ADMIN'); ?></span>
        <div class="detail-list">
          <div class="detail"><span>User ID</span><strong><?php echo admin_h($admin['USER_ID'] ?? ''); ?></strong></div>
          <div class="detail"><span>Phone</span><strong><?php echo admin_h($admin['PH_NUMBER'] ?? 'Not set'); ?></strong></div>
          <div class="detail"><span>Email verified</span><strong><?php echo ((string)($admin['EMAIL_VERIFIED'] ?? '0') === '1') ? 'Yes' : 'No'; ?></strong></div>
        </div>
      </aside>

      <div>
        <section class="card form-card">
          <h2 class="form-title"><i class="fa-solid fa-user-pen"></i> Profile details</h2>
          <form method="POST">
            <input type="hidden" name="action" value="update_profile">
            <div class="field-grid">
              <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" value="<?php echo admin_h($admin['FIRST_NAME'] ?? ''); ?>" required></div>
              <div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" value="<?php echo admin_h($admin['LAST_NAME'] ?? ''); ?>" required></div>
            </div>
            <div class="field-grid">
              <div class="field"><label for="email_address">Email</label><input id="email_address" type="email" name="email_address" value="<?php echo admin_h($admin['EMAIL_ADDRESS'] ?? ''); ?>" required></div>
              <div class="field"><label for="ph_number">Phone number</label><input id="ph_number" name="ph_number" value="<?php echo admin_h($admin['PH_NUMBER'] ?? ''); ?>"></div>
            </div>
            <div class="actions"><button class="btn" type="submit">Save profile</button></div>
          </form>
        </section>

        <section class="card form-card">
          <h2 class="form-title"><i class="fa-solid fa-lock"></i> Change password</h2>
          <p class="hint">Use this only when you want to replace the current admin password.</p>
          <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="field"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password"></div>
            <div class="field-grid">
              <div class="field"><label for="new_password">New password</label><input id="new_password" type="password" name="new_password" autocomplete="new-password"></div>
              <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password"></div>
            </div>
            <div class="actions"><button class="btn btn-secondary" type="submit">Change password</button></div>
          </form>
        </section>
      </div>
    </div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>

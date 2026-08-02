<?php
// admin/user-management.php
require_once __DIR__ . '/admin_common.php';

date_default_timezone_set('Asia/Kathmandu');

$adminId = require_admin_login();
$conn = admin_db_connection();
$users = [];
$userError = '';
$userNotice = '';

if (!function_exists('um_h')) {
    function um_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('um_execute')) {
    function um_execute($conn, string $sql, array $binds = []) {
        if (!$conn) {
            throw new RuntimeException('Database connection is not available. Please try again later.');
        }

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            throw new RuntimeException(shoplocalfy_db_error_message($conn, 'Could not prepare user query.'));
        }

        $localBinds = [];
        foreach ($binds as $key => $value) {
            $bindName = ':' . ltrim((string)$key, ':');
            $localBinds[$bindName] = $value;
            oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
        }

        if (!oci_execute($stmt)) {
            throw new RuntimeException(shoplocalfy_db_error_message($stmt, 'Could not run user query.'));
        }

        return $stmt;
    }
}

if (!function_exists('um_user_rows')) {
    function um_user_rows($conn): array {
        $stmt = um_execute($conn, <<<'SQL'
            SELECT
                USER_ID,
                TRIM(NVL(FIRST_NAME, '') || ' ' || NVL(LAST_NAME, '')) AS USER_NAME,
                EMAIL_ADDRESS,
                PH_NUMBER,
                USER_ROLE,
                CASE
                    WHEN EMAIL_VERIFIED IS NULL THEN 'NO'
                    WHEN UPPER(TRIM(CAST(EMAIL_VERIFIED AS VARCHAR2(30)))) IN ('1', 'Y', 'YES', 'TRUE', 'VERIFIED') THEN 'YES'
                    ELSE 'NO'
                END AS EMAIL_VERIFIED,
                NVL(UPPER(TRIM(ACTIVE_STATUS)), 'ACTIVE') AS ACTIVE_STATUS,
                TO_CHAR(DATE_CREATED, 'DD Mon YYYY') AS JOINED_DATE
            FROM "USER"
            WHERE UPPER(TRIM(USER_ROLE)) <> 'ADMIN'
            ORDER BY DATE_CREATED DESC NULLS LAST, USER_ID DESC
SQL);

        $rows = [];
        while (($row = oci_fetch_assoc($stmt)) !== false) {
            $row['ACTIVE_STATUS'] = strtoupper(trim((string)($row['ACTIVE_STATUS'] ?? 'ACTIVE')));
            if (!in_array($row['ACTIVE_STATUS'], ['ACTIVE', 'SUSPEND'], true)) {
                $row['ACTIVE_STATUS'] = 'ACTIVE';
            }
            $row['USER_ROLE'] = strtoupper(trim((string)($row['USER_ROLE'] ?? '')));
            $row['EMAIL_VERIFIED'] = strtoupper(trim((string)($row['EMAIL_VERIFIED'] ?? 'NO')));
            $rows[] = $row;
        }
        return $rows;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['next_status'])) {
    try {
        $userId = trim((string)$_POST['user_id']);
        $nextStatus = strtoupper(trim((string)$_POST['next_status']));

        if ($userId === '') {
            throw new RuntimeException('User ID is required.');
        }
        if (!in_array($nextStatus, ['ACTIVE', 'SUSPEND'], true)) {
            throw new RuntimeException('Invalid account status.');
        }

        um_execute($conn, '
            UPDATE "USER"
            SET ACTIVE_STATUS = :active_status
            WHERE USER_ID = :user_id
        ', [
            ':active_status' => $nextStatus,
            ':user_id' => $userId,
        ]);

        $userNotice = $nextStatus === 'ACTIVE' ? 'User account activated.' : 'User account suspended.';
    } catch (Throwable $e) {
        $userError = shoplocalfy_public_exception_message($e, 'Could not update user account.');
    }
}

try {
    $users = um_user_rows($conn);
} catch (Throwable $e) {
    $userError = shoplocalfy_public_exception_message($e, 'Could not update user account.');
}

$totalUsers = count($users);
$activeUsers = count(array_filter($users, fn($u) => ($u['ACTIVE_STATUS'] ?? 'ACTIVE') === 'ACTIVE'));
$suspendedUsers = count(array_filter($users, fn($u) => ($u['ACTIVE_STATUS'] ?? 'ACTIVE') === 'SUSPEND'));
$verifiedUsers = count(array_filter($users, fn($u) => in_array(($u['EMAIL_VERIFIED'] ?? 'NO'), ['YES', 'Y', '1'], true)));
$customerUsers = count(array_filter($users, fn($u) => ($u['USER_ROLE'] ?? '') === 'CUSTOMER'));
$traderUsers = count(array_filter($users, fn($u) => ($u['USER_ROLE'] ?? '') === 'TRADER'));
$todayLabel = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=10" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=10" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=10" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy – User Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/user-management.css?v=20260517b">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>

    <main class="soft-admin-page">
      <section class="soft-hero">
        <div>
          <p class="soft-kicker">Admin Control Centre</p>
          <h1 class="soft-title">User Management</h1>
        </div>
        <div class="date-pill"><i class="fa-regular fa-calendar"></i> <?= um_h($todayLabel) ?></div>
      </section>

      <?php if ($userNotice !== ''): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i><?= um_h($userNotice) ?></div>
      <?php endif; ?>
      <?php if ($userError !== ''): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i><?= um_h($userError) ?></div>
      <?php endif; ?>

      <section class="soft-stats">
        <article class="soft-stat">
          <div class="soft-icon"><i class="fa-solid fa-users"></i></div>
          <div><span class="stat-label">Total Users</span><span class="stat-value"><?= (int)$totalUsers ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon"><i class="fa-solid fa-user-check"></i></div>
          <div><span class="stat-label">Active Accounts</span><span class="stat-value"><?= (int)$activeUsers ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon red"><i class="fa-solid fa-user-lock"></i></div>
          <div><span class="stat-label">Suspended</span><span class="stat-value"><?= (int)$suspendedUsers ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon orange"><i class="fa-solid fa-envelope-circle-check"></i></div>
          <div><span class="stat-label">Email Verified</span><span class="stat-value"><?= (int)$verifiedUsers ?></span></div>
        </article>
      </section>

      <section class="quick-grid" aria-label="User filters">
        <button class="quick-card is-active" type="button" data-role-card="ALL">
          <span class="soft-icon"><i class="fa-solid fa-layer-group"></i></span>
          <span><span class="quick-title">All Accounts</span><span class="quick-subtitle"><?= (int)$totalUsers ?> users</span></span>
        </button>
        <button class="quick-card" type="button" data-role-card="CUSTOMER">
          <span class="soft-icon"><i class="fa-solid fa-user"></i></span>
          <span><span class="quick-title">Customers</span><span class="quick-subtitle"><?= (int)$customerUsers ?> accounts</span></span>
        </button>
        <button class="quick-card" type="button" data-role-card="TRADER">
          <span class="soft-icon"><i class="fa-solid fa-store"></i></span>
          <span><span class="quick-title">Traders</span><span class="quick-subtitle"><?= (int)$traderUsers ?> accounts</span></span>
        </button>

      </section>

      <section class="soft-card">
        <div class="soft-card-header">
          <div>
            <h2 class="soft-card-title">All Users</h2>
            <p class="soft-card-note">Search, filter and control account status without leaving this page.</p>
          </div>
          <span class="count-pill"><i class="fa-solid fa-database"></i> <?= (int)$totalUsers ?> accounts</span>
        </div>

        <div class="user-tools">
          <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search name, email, phone, role or status" id="userSearch" autocomplete="off">
          </div>
          <div class="select-row">
            <select class="soft-select" id="roleFilter" aria-label="Role filter">
              <option value="ALL">All roles</option>
              <option value="CUSTOMER">Customers</option>
              <option value="TRADER">Traders</option>
      
            </select>
            <select class="soft-select" id="statusFilter" aria-label="Status filter">
              <option value="ALL">All statuses</option>
              <option value="ACTIVE">Active</option>
              <option value="SUSPEND">Suspended</option>
            </select>
          </div>
        </div>

        <div class="table-scroll">
          <table class="soft-table" id="userTable">
            <thead>
              <tr>
                <th>User</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Joined</th>
                <th>Email</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr class="empty-row"><td colspan="8">No users found.</td></tr>
              <?php else: ?>
                <?php foreach ($users as $user): ?>
                  <?php
                    $status = strtoupper($user['ACTIVE_STATUS'] ?? 'ACTIVE');
                    $isActive = $status === 'ACTIVE';
                    $nextStatus = $isActive ? 'SUSPEND' : 'ACTIVE';
                    $buttonText = $isActive ? 'Suspend' : 'Activate';
                    $name = trim((string)($user['USER_NAME'] ?? '')) ?: 'Unnamed user';
                    $role = strtoupper(trim((string)($user['USER_ROLE'] ?? '-')));
                    $roleClass = strtolower($role ?: 'user');
                    $emailVerified = strtoupper(trim((string)($user['EMAIL_VERIFIED'] ?? 'NO')));
                    $isVerified = in_array($emailVerified, ['YES', 'Y', '1'], true);
                  ?>
                  <tr data-role="<?= um_h($role) ?>" data-status="<?= um_h($status) ?>">
                    <td>
                      <span class="user-name"><?= um_h($name) ?></span>
                      <span class="user-id-cell"><?= um_h($user['USER_ID'] ?? '') ?></span>
                    </td>
                    <td><?= um_h($user['EMAIL_ADDRESS'] ?? '-') ?></td>
                    <td><?= um_h($user['PH_NUMBER'] ?? '-') ?></td>
                    <td><span class="role-pill <?= um_h($roleClass) ?>"><?= um_h($role ?: '-') ?></span></td>
                    <td><span class="muted-cell"><?= um_h($user['JOINED_DATE'] ?? '-') ?></span></td>
                    <td><span class="verified-pill <?= $isVerified ? 'verified-yes' : 'verified-no' ?>"><?= $isVerified ? 'Verified' : 'Not verified' ?></span></td>
                    <td><span class="status-badge <?= $isActive ? 'status-active' : 'status-suspend' ?>"><?= $isActive ? 'ACTIVE' : 'SUSPEND' ?></span></td>
                    <td>
                      <form class="inline-form" method="POST" onsubmit="return confirm('Change this user status to <?= $nextStatus ?>?');">
                        <input type="hidden" name="user_id" value="<?= um_h($user['USER_ID'] ?? '') ?>">
                        <input type="hidden" name="next_status" value="<?= $nextStatus ?>">
                        <button class="btn <?= $isActive ? 'btn-danger' : 'btn-primary' ?>" type="submit">
                          <i class="fa-solid <?= $isActive ? 'fa-ban' : 'fa-check' ?>"></i><?= $buttonText ?>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <?php include 'footer.php'; ?>
  </div>
</div>
<script src="../assets/admin/js/user-management.js?v=20260517b"></script>
</body>
</html>

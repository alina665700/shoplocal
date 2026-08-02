<?php
// admin/approve-trader.php
require_once __DIR__ . '/admin_common.php';

date_default_timezone_set('Asia/Kathmandu');

if (function_exists('require_admin_login')) {
    require_admin_login();
}

if (!isset($conn) || !$conn) {
    $conn = admin_db_connection();
}

$traders = [];
$traderError = '';
$traderNotice = '';

if (!function_exists('at_h')) {
    function at_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trader_id'], $_POST['status'])) {
    try {
        $traderId = trim((string)$_POST['trader_id']);
        $status = strtoupper(trim((string)$_POST['status']));

        if ($traderId === '') {
            throw new RuntimeException('Trader ID is missing.');
        }

        if (!in_array($status, ['VERIFIED', 'PENDING', 'REJECTED'], true)) {
            throw new RuntimeException('Invalid trader status.');
        }

        if ($status === 'VERIFIED') {
            $verifiedCountRow = db_one(
                $conn,
                "SELECT COUNT(*) AS TOTAL
                 FROM TRADER
                 WHERE UPPER(NVL(VERIFIED_STATUS, 'PENDING')) = 'VERIFIED'
                   AND USER_ID <> :user_id",
                [':user_id' => $traderId]
            );
            $verifiedCount = (int)($verifiedCountRow['TOTAL'] ?? 0);

            if ($verifiedCount >= 10) {
                throw new RuntimeException('ShopLocalfy already has 10 approved traders. Reject or remove a trader before approving another one.');
            }
        }

        execute_sql($conn, 'UPDATE TRADER SET VERIFIED_STATUS = :status, ADMIN_ID = :admin_id WHERE USER_ID = :user_id', [
            ':status' => $status,
            ':admin_id' => admin_first_admin_id(),
            ':user_id' => $traderId
        ]);

        $shopStatus = $status === 'VERIFIED' ? 'APPROVED' : ($status === 'REJECTED' ? 'SUSPENDED' : 'PENDING');
        execute_sql($conn, 'UPDATE SHOP SET APPROVAL_STATUS = :status WHERE TRADER_ID = :user_id', [
            ':status' => $shopStatus,
            ':user_id' => $traderId
        ]);

        header('Location: approve-trader.php?success=' . rawurlencode($status === 'VERIFIED' ? 'Trader approved.' : 'Trader rejected.'));
        exit;
    } catch (Throwable $e) {
        $traderError = shoplocalfy_public_exception_message($e, 'Could not update trader request.');
    }
}

$traderNotice = trim((string)($_GET['success'] ?? ''));

try {
    $traders = admin_trader_rows(false);
} catch (Throwable $e) {
    $traderError = shoplocalfy_public_exception_message($e, 'Could not update trader request.');
}

$totalTraders = count($traders);
$pendingTraders = count(array_filter($traders, fn($t) => strtoupper((string)($t['VERIFIED_STATUS'] ?? 'PENDING')) === 'PENDING'));
$verifiedTraders = count(array_filter($traders, fn($t) => strtoupper((string)($t['VERIFIED_STATUS'] ?? 'PENDING')) === 'VERIFIED'));
$rejectedTraders = count(array_filter($traders, fn($t) => strtoupper((string)($t['VERIFIED_STATUS'] ?? 'PENDING')) === 'REJECTED'));
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
  <title>ShopLocalfy – Approve Trader</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/approve-trader.css?v=20260517">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>

    <main class="soft-admin-page">
      <section class="soft-hero">
        <div>
          <h1 class="soft-title">Approve Trader</h1>
        </div>
        <div class="date-pill"><i class="fa-regular fa-calendar"></i> <?= at_h($todayLabel) ?></div>
      </section>

      <?php if ($traderNotice !== ''): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i><?= at_h($traderNotice) ?></div>
      <?php endif; ?>
      <?php if ($traderError !== ''): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i><?= at_h($traderError) ?></div>
      <?php endif; ?>

      <section class="soft-stats" aria-label="Trader filters">
        <button class="soft-stat is-active" type="button" data-status-card="ALL">
          <span class="soft-icon blue"><i class="fa-solid fa-store"></i></span>
          <span><span class="stat-label">Total Traders</span><span class="stat-value"><?= (int)$totalTraders ?></span></span>
        </button>
        <button class="soft-stat" type="button" data-status-card="PENDING">
          <span class="soft-icon orange"><i class="fa-solid fa-clock"></i></span>
          <span><span class="stat-label">Pending</span><span class="stat-value"><?= (int)$pendingTraders ?></span></span>
        </button>
        <button class="soft-stat" type="button" data-status-card="VERIFIED">
          <span class="soft-icon"><i class="fa-solid fa-circle-check"></i></span>
          <span><span class="stat-label">Verified</span><span class="stat-value"><?= (int)$verifiedTraders ?></span></span>
        </button>
        <button class="soft-stat" type="button" data-status-card="REJECTED">
          <span class="soft-icon red"><i class="fa-solid fa-circle-xmark"></i></span>
          <span><span class="stat-label">Rejected</span><span class="stat-value"><?= (int)$rejectedTraders ?></span></span>
        </button>
      </section>

      <section class="soft-card">
        <div class="soft-card-header">
          <div>
            <h2 class="soft-card-title">Trader Applications</h2>
            <p class="soft-card-note">Search, filter and approve or reject traders from one table.</p>
          </div>
          <span class="count-pill"><i class="fa-solid fa-database"></i> <?= (int)$totalTraders ?> traders</span>
        </div>

        <div class="trader-tools">
          <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search trader, shop, email or status" id="traderSearch" autocomplete="off">
          </div>
          <select class="soft-select" id="statusFilter" aria-label="Status filter">
            <option value="ALL">All statuses</option>
            <option value="PENDING">Pending</option>
            <option value="VERIFIED">Verified</option>
            <option value="REJECTED">Rejected</option>
          </select>
        </div>

        <div class="table-scroll">
          <table class="soft-table" id="traderTable">
            <thead>
              <tr>
                <th>Trader</th>
                <th>Shop</th>
                <th>Email</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($traders)): ?>
                <tr class="empty-row"><td colspan="6">No trader applications found.</td></tr>
              <?php else: ?>
                <?php foreach ($traders as $trader): ?>
                  <?php
                    $status = strtoupper((string)($trader['VERIFIED_STATUS'] ?? 'PENDING'));
                    if (!in_array($status, ['VERIFIED', 'PENDING', 'REJECTED'], true)) {
                        $status = 'PENDING';
                    }
                  ?>
                  <tr data-status="<?= at_h($status) ?>">
                    <td>
                      <span class="trader-name"><?= at_h($trader['TRADER_NAME'] ?? 'Trader') ?></span>
                      <span class="muted-cell"><?= at_h($trader['USER_ID'] ?? '') ?></span>
                    </td>
                    <td><?= at_h($trader['SHOP_NAME'] ?? '-') ?></td>
                    <td><?= at_h($trader['EMAIL_ADDRESS'] ?? '-') ?></td>
                    <td><span class="muted-cell"><?= at_h($trader['SUBMITTED_DATE'] ?? '-') ?></span></td>
                    <td>
                      <span class="status-badge <?= $status === 'VERIFIED' ? 'status-approved' : ($status === 'REJECTED' ? 'status-rejected' : 'status-pending') ?>">
                        <?= at_h($status) ?>
                      </span>
                    </td>
                    <td>
                      <form class="action-form" method="POST">
                        <input type="hidden" name="trader_id" value="<?= at_h($trader['USER_ID'] ?? '') ?>">
                        <button class="btn btn-primary" type="submit" name="status" value="VERIFIED" onclick="return confirm('Approve this trader?');">
                          <i class="fa-solid fa-check"></i>Approve
                        </button>
                        <button class="btn btn-danger" type="submit" name="status" value="REJECTED" onclick="return confirm('Reject this trader?');">
                          <i class="fa-solid fa-xmark"></i>Reject
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
  </div>
</div>
<?php include 'footer.php'; ?>
<script src="../assets/admin/js/approve-trader.js?v=20260517"></script>
</body>
</html>

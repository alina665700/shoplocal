<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/order_lifecycle.php';

$adminId = require_admin_login();
$conn = admin_db_connection();
$message = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$dateScope = strtolower(trim((string)($_GET['date_scope'] ?? 'today')));
$customDate = trim((string)($_GET['date'] ?? ''));
$traderFilter = trim((string)($_GET['trader'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$autoCancelNotice = '';
$traderOptions = [];

function tc_redirect($success = '', $error = '') {
    $params = [];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    header('Location: today-collections.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}
function tc_money($value) { return '£' . number_format((float)$value, 2); }
function tc_status_class($status) {
    return match (strtoupper((string)$status)) {
        'COLLECTED' => 'is-good',
        'READY' => 'is-ready',
        'CANCELLED' => 'is-bad',
        default => 'is-warn',
    };
}
function tc_date_label($scope, $customDate) {
    return match ($scope) {
        'tomorrow' => 'Tomorrow',
        'next7' => 'Next 7 days',
        'upcoming' => 'All upcoming pickup dates',
        'custom' => $customDate !== '' ? $customDate : 'Custom date',
        default => 'Today',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_collected') {
    $orderId = trim((string)($_POST['order_id'] ?? ''));
    $productId = trim((string)($_POST['product_id'] ?? ''));
    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if ($orderId === '' || $productId === '') throw new RuntimeException('Invalid collection request.');
        try {
            $stmt = db_bind_and_execute($conn, "
                UPDATE ORDER_ITEM oi
                SET oi.ITEM_STATUS = 'COLLECTED'
                WHERE oi.ORDER_ID = :order_id
                  AND oi.PRODUCT_ID = :product_id
                  AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) IN ('READY', 'PENDING')
                  AND EXISTS (
                      SELECT 1
                      FROM ORDERS o
                      INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
                      WHERE o.ORDER_ID = oi.ORDER_ID
                        AND UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'
                        AND TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE)
                        AND UPPER(NVL(o.ORDER_STATUS, 'CONFIRMED')) <> 'CANCELLED'
                  )
            ", [':order_id' => $orderId, ':product_id' => $productId], OCI_NO_AUTO_COMMIT);
            if (function_exists('oci_num_rows') && oci_num_rows($stmt) !== 1) {
                throw new RuntimeException('This item was not marked collected. It may already be collected/cancelled, unpaid, or not scheduled for today.');
            }
            sl_order_sync_parent_status($conn, $orderId, OCI_NO_AUTO_COMMIT);
            oci_commit($conn);
        } catch (Throwable $tx) {
            oci_rollback($conn);
            throw $tx;
        }
        tc_redirect('Item marked collected.');
    } catch (Throwable $e) {
        tc_redirect('', shoplocalfy_public_exception_message($e, 'Could not mark item collected.'));
    }
}

$rows = [];
$summary = ['items' => 0, 'ready' => 0, 'pending' => 0, 'collected' => 0, 'cancelled' => 0, 'value' => 0.0];

try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    $autoCancel = sl_order_auto_cancel_overdue_uncollected($conn);
    if (!empty($autoCancel['cancelled_items'])) $autoCancelNotice = $autoCancel['message'];

    $traderOptions = db_all($conn, "
        SELECT t.USER_ID AS TRADER_ID,
               TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS TRADER_NAME,
               NVL(MAX(s.SHOP_NAME), 'No shop recorded') AS SHOP_NAME
        FROM TRADER t
        JOIN \"USER\" u ON u.USER_ID = t.USER_ID
        LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
        GROUP BY t.USER_ID, u.FIRST_NAME, u.LAST_NAME
        ORDER BY TRADER_NAME ASC
    ");

    $where = ["UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'"];
    $binds = [];
    switch ($dateScope) {
        case 'tomorrow':
            $where[] = "TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE) + 1";
            break;
        case 'next7':
            $where[] = "TRUNC(o.PICKUP_DATE) BETWEEN TRUNC(SYSDATE) AND TRUNC(SYSDATE) + 7";
            break;
        case 'upcoming':
            $where[] = "TRUNC(o.PICKUP_DATE) >= TRUNC(SYSDATE)";
            break;
        case 'custom':
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $customDate)) {
                $where[] = "TRUNC(o.PICKUP_DATE) = TO_DATE(:custom_date, 'YYYY-MM-DD')";
                $binds[':custom_date'] = $customDate;
            } else {
                $dateScope = 'today';
                $where[] = "TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE)";
            }
            break;
        case 'today':
        default:
            $dateScope = 'today';
            $where[] = "TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE)";
            break;
    }
    if (in_array($statusFilter, ['PENDING', 'READY', 'COLLECTED', 'CANCELLED'], true)) {
        $where[] = "UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = :status_filter";
        $binds[':status_filter'] = $statusFilter;
    }
    if ($traderFilter !== '') {
        $where[] = "oi.TRADER_ID = :trader_id";
        $binds[':trader_id'] = $traderFilter;
    }
    if ($searchTerm !== '') {
        $where[] = "(UPPER(o.ORDER_ID) LIKE :q OR UPPER(u.FIRST_NAME || ' ' || u.LAST_NAME) LIKE :q OR UPPER(u.EMAIL_ADDRESS) LIKE :q OR UPPER(pr.PRODUCT_NAME) LIKE :q OR UPPER(NVL(s.SHOP_NAME, '')) LIKE :q)";
        $binds[':q'] = '%' . strtoupper($searchTerm) . '%';
    }
    $whereSql = implode(' AND ', $where);

    $rows = db_all($conn, "
        SELECT o.ORDER_ID,
               o.ORDER_STATUS,
               TO_CHAR(o.ORDER_DATE, 'Mon DD, YYYY HH24:MI') AS ORDER_DATE_TEXT,
               TO_CHAR(o.PICKUP_DATE, 'YYYY-MM-DD') AS PICKUP_DATE_TEXT,
               CASE WHEN TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE) THEN 1 ELSE 0 END AS IS_TODAY,
               INITCAP(ps.ALLOWED_DAY) || ', ' || LPAD(ps.START_HOUR, 2, '0') || ':00-' || LPAD(ps.END_HOUR, 2, '0') || ':00' AS PICKUP_SLOT_LABEL,
               u.FIRST_NAME || ' ' || u.LAST_NAME AS CUSTOMER_NAME,
               u.EMAIL_ADDRESS AS CUSTOMER_EMAIL,
               pr.PRODUCT_ID,
               pr.PRODUCT_NAME,
               oi.QUANTITY,
               oi.LOCKED_PRICE,
               (oi.QUANTITY * oi.LOCKED_PRICE) AS LINE_TOTAL,
               oi.ITEM_STATUS,
               tu.FIRST_NAME || ' ' || tu.LAST_NAME AS TRADER_NAME,
               s.SHOP_NAME
        FROM ORDERS o
        INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
        INNER JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
        INNER JOIN PRODUCT pr ON pr.PRODUCT_ID = oi.PRODUCT_ID
        INNER JOIN CUSTOMER c ON c.USER_ID = o.CUSTOMER_ID
        INNER JOIN \"USER\" u ON u.USER_ID = c.USER_ID
        INNER JOIN TRADER t ON t.USER_ID = oi.TRADER_ID
        INNER JOIN \"USER\" tu ON tu.USER_ID = t.USER_ID
        LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
        LEFT JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
        WHERE $whereSql
        ORDER BY o.PICKUP_DATE ASC, ps.START_HOUR ASC NULLS LAST, o.ORDER_ID DESC, s.SHOP_NAME ASC, pr.PRODUCT_NAME ASC
    ", $binds);

    foreach ($rows as $row) {
        $summary['items']++;
        $summary['value'] += (float)($row['LINE_TOTAL'] ?? 0);
        $s = strtolower((string)($row['ITEM_STATUS'] ?? 'pending'));
        if (isset($summary[$s])) $summary[$s]++;
    }
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load collection items.');
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
  <title>Collections - ShopLocalfy Admin</title>
  <link rel="stylesheet" href="../assets/admin/css/today-collections.css?v=20260518d">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <main class="collections-page">
      <section class="collections-hero">
        <div><p class="eyebrow">Pickup desk</p><h1>Collections</h1><p>Filter pickup items by date, status, trader or search. Only same-day rows can be marked collected.</p></div>
        <a class="ghost-link" href="uncollected-orders.php?filter=refund">Refund queue</a>
      </section>
      <?php if ($message !== ''): ?><div class="notice success"><?php echo admin_h($message); ?></div><?php endif; ?>
      <?php if ($autoCancelNotice !== ''): ?><div class="notice success"><?php echo admin_h($autoCancelNotice); ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="notice error"><?php echo admin_h($error); ?></div><?php endif; ?>

      <section class="summary-grid">
        <article><span>Current view</span><strong><?php echo admin_h(tc_date_label($dateScope, $customDate)); ?></strong></article>
        <article><span>Total item rows</span><strong><?php echo number_format($summary['items']); ?></strong></article>
        <article><span>Ready</span><strong><?php echo number_format($summary['ready']); ?></strong></article>
        <article><span>Pending prep</span><strong><?php echo number_format($summary['pending']); ?></strong></article>
        <article><span>Collected</span><strong><?php echo number_format($summary['collected']); ?></strong></article>
        <article><span>Value</span><strong><?php echo tc_money($summary['value']); ?></strong></article>
      </section>

      <form class="toolbar-card dynamic-toolbar" method="get">
        <label><span>Date</span><select name="date_scope" onchange="this.form.submit()">
          <option value="today" <?php echo $dateScope === 'today' ? 'selected' : ''; ?>>Today</option>
          <option value="tomorrow" <?php echo $dateScope === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
          <option value="next7" <?php echo $dateScope === 'next7' ? 'selected' : ''; ?>>Next 7 days</option>
          <option value="upcoming" <?php echo $dateScope === 'upcoming' ? 'selected' : ''; ?>>All upcoming</option>
          <option value="custom" <?php echo $dateScope === 'custom' ? 'selected' : ''; ?>>Custom date</option>
        </select></label>
        <label><span>Custom date</span><input type="date" name="date" value="<?php echo admin_h($customDate); ?>"></label>
        <label><span>Status</span><select name="status" onchange="this.form.submit()"><option value="">All statuses</option><?php foreach (['PENDING','READY','COLLECTED','CANCELLED'] as $opt): ?><option value="<?php echo admin_h($opt); ?>" <?php echo $statusFilter === $opt ? 'selected' : ''; ?>><?php echo admin_h(ucfirst(strtolower($opt))); ?></option><?php endforeach; ?></select></label>
        <label><span>Trader</span><select name="trader" onchange="this.form.submit()"><option value="">All traders</option><?php foreach ($traderOptions as $trader): $tid = (string)($trader['TRADER_ID'] ?? ''); ?><option value="<?php echo admin_h($tid); ?>" <?php echo $traderFilter === $tid ? 'selected' : ''; ?>><?php echo admin_h(($trader['SHOP_NAME'] ?? 'No shop') . ' — ' . ($trader['TRADER_NAME'] ?? $tid)); ?></option><?php endforeach; ?></select></label>
        <label class="search-field"><span>Search</span><input type="search" name="q" value="<?php echo admin_h($searchTerm); ?>" placeholder="Order, customer, product, shop"></label>
        <button type="submit">Apply</button>
      </form>

      <?php if (!$rows): ?>
        <section class="empty-card"><h2>No collection items found</h2><p>Try a wider date filter such as “Next 7 days” or “All upcoming”.</p></section>
      <?php else: ?>
        <section class="table-card"><div class="table-wrap"><table>
          <thead><tr><th>Order</th><th>Pickup</th><th>Slot</th><th>Customer</th><th>Product</th><th>Trader / Shop</th><th>Qty</th><th>Value</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): $itemStatus = strtoupper((string)($row['ITEM_STATUS'] ?? 'PENDING')); ?>
            <tr>
              <td><a href="order-detail.php?id=<?php echo rawurlencode((string)$row['ORDER_ID']); ?>"><?php echo admin_h($row['ORDER_ID']); ?></a><small><?php echo admin_h($row['ORDER_DATE_TEXT'] ?? ''); ?></small></td>
              <td><?php echo admin_h($row['PICKUP_DATE_TEXT'] ?? '—'); ?></td>
              <td><?php echo admin_h($row['PICKUP_SLOT_LABEL'] ?? '—'); ?></td>
              <td><strong><?php echo admin_h($row['CUSTOMER_NAME'] ?? 'Customer'); ?></strong><small><?php echo admin_h($row['CUSTOMER_EMAIL'] ?? '—'); ?></small></td>
              <td><strong><?php echo admin_h($row['PRODUCT_NAME'] ?? 'Product'); ?></strong><small><?php echo admin_h($row['PRODUCT_ID'] ?? ''); ?></small></td>
              <td><strong><?php echo admin_h($row['SHOP_NAME'] ?: ($row['TRADER_NAME'] ?? 'Trader')); ?></strong></td>
              <td><?php echo number_format((int)($row['QUANTITY'] ?? 0)); ?></td>
              <td><?php echo tc_money($row['LINE_TOTAL'] ?? 0); ?></td>
              <td><span class="status-pill <?php echo admin_h(tc_status_class($itemStatus)); ?>"><?php echo admin_h($itemStatus); ?></span></td>
              <td>
                <?php if ($itemStatus === 'COLLECTED' || $itemStatus === 'CANCELLED'): ?>
                  <span>No action</span>
                <?php elseif ((int)($row['IS_TODAY'] ?? 0) !== 1): ?>
                  <span>Available on pickup date</span>
                <?php else: ?>
                  <form method="post" onsubmit="return confirm('Mark this item collected?');"><input type="hidden" name="action" value="mark_collected"><input type="hidden" name="order_id" value="<?php echo admin_h($row['ORDER_ID']); ?>"><input type="hidden" name="product_id" value="<?php echo admin_h($row['PRODUCT_ID']); ?>"><button class="collect-btn" type="submit">Mark collected</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div></section>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>

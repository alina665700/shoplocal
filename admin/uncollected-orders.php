<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/order_lifecycle.php';
require_once __DIR__ . '/../config/order_email_notifications.php';

$adminId = require_admin_login();
$conn = admin_db_connection();
$message = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$filter = strtolower(trim((string)($_GET['filter'] ?? 'due')));
if ($filter === 'refund') { $filter = 'due'; }
if ($filter === 'refunded') { $filter = 'done'; }
if (!in_array($filter, ['due', 'done', 'all'], true)) { $filter = 'due'; }
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$traderFilter = trim((string)($_GET['trader'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$autoCancelNotice = '';
$traderOptions = [];

function uq_redirect($success = '', $error = '', $filter = 'due') {
    $params = [];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    if ($filter !== '') $params['filter'] = $filter;
    header('Location: uncollected-orders.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}
function uq_money($value) { return '£' . number_format((float)$value, 2); }
function uq_status_class($status) {
    return match (strtoupper((string)$status)) {
        'COLLECTED', 'COMPLETED', 'REFUNDED' => 'is-good',
        'READY', 'CONFIRMED', 'PENDING' => 'is-warn',
        'CANCELLED', 'FAILED' => 'is-bad',
        default => 'is-neutral',
    };
}
function uq_order_cancel_summary($conn, $orderId) {
    return db_one($conn, "
        SELECT COUNT(*) AS TOTAL_ITEMS,
               SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'CANCELLED' THEN 1 ELSE 0 END) AS CANCELLED_ITEMS,
               SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'COLLECTED' THEN 1 ELSE 0 END) AS COLLECTED_ITEMS
        FROM ORDER_ITEM
        WHERE ORDER_ID = :order_id
    ", [':order_id' => $orderId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $orderId = trim((string)($_POST['order_id'] ?? ''));
    $productId = trim((string)($_POST['product_id'] ?? ''));
    $filterBack = trim((string)($_POST['filter'] ?? $filter));
    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if ($action === 'cancel_item') {
            if ($orderId === '' || $productId === '') throw new RuntimeException('Invalid cancellation request.');
            $item = db_one($conn, "
                SELECT oi.ORDER_ID, oi.PRODUCT_ID, oi.QUANTITY, oi.ITEM_STATUS
                FROM ORDER_ITEM oi
                INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
                INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
                INNER JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
                WHERE oi.ORDER_ID = :order_id
                  AND oi.PRODUCT_ID = :product_id
                  AND UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'
                  AND SYSDATE > (TRUNC(o.PICKUP_DATE) + (ps.END_HOUR / 24))
            ", [':order_id' => $orderId, ':product_id' => $productId]);
            if (!$item) throw new RuntimeException('This item is not eligible for local cancellation.');
            $currentStatus = strtoupper((string)($item['ITEM_STATUS'] ?? 'PENDING'));
            if (in_array($currentStatus, ['COLLECTED', 'CANCELLED'], true)) throw new RuntimeException('This item is already collected or cancelled.');
            try {
                db_bind_and_execute($conn, "UPDATE ORDER_ITEM SET ITEM_STATUS = 'CANCELLED' WHERE ORDER_ID = :order_id AND PRODUCT_ID = :product_id", [':order_id' => $orderId, ':product_id' => $productId], OCI_NO_AUTO_COMMIT);
                db_bind_and_execute($conn, "UPDATE PRODUCT SET STOCK_AVAILABLE = STOCK_AVAILABLE + :qty WHERE PRODUCT_ID = :product_id", [':qty' => (int)($item['QUANTITY'] ?? 0), ':product_id' => $productId], OCI_NO_AUTO_COMMIT);
                sl_order_sync_parent_status($conn, $orderId, OCI_NO_AUTO_COMMIT);
                oci_commit($conn);
                if (function_exists('shoplocalfy_send_order_cancellation_emails')) {
                    shoplocalfy_send_order_cancellation_emails($conn, $orderId, $productId, 'admin_cancelled');
                }
            } catch (Throwable $tx) { oci_rollback($conn); throw $tx; }
            uq_redirect('Item cancelled locally, stock restored, and cancellation emails were attempted. Refund still needs manual PayPal handling.', '', $filterBack ?: 'due');
        }
        if ($action === 'mark_refunded') {
            if ($orderId === '') throw new RuntimeException('Invalid refund record request.');
            $summary = uq_order_cancel_summary($conn, $orderId);
            $total = (int)($summary['TOTAL_ITEMS'] ?? 0);
            $cancelled = (int)($summary['CANCELLED_ITEMS'] ?? 0);
            if ($total <= 0 || $cancelled !== $total) throw new RuntimeException('Only fully cancelled orders can be marked as fully refunded with the current database design.');
            db_bind_and_execute($conn, "UPDATE PAYMENT SET PAYMENT_STATUS = 'REFUNDED' WHERE ORDER_ID = :order_id", [':order_id' => $orderId]);
            uq_redirect('Manual refund recorded. Payment status is now REFUNDED.', '', 'done');
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        uq_redirect('', shoplocalfy_public_exception_message($e, 'Could not update refund queue.'), $filterBack ?: $filter);
    }
}

$rows = [];
$summary = ['items' => 0, 'gross' => 0.0, 'refund_pending' => 0.0, 'orders' => []];
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

    /*
      Refund queue filters intentionally do not require the pickup slot to be overdue.
      Reason: an admin can cancel an order manually before or after pickup time.
      If it was paid and cancelled locally, it still needs refund handling.
    */
    if ($filter === 'done') {
        $where = [
            "UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'CANCELLED'",
            "UPPER(NVL(p.PAYMENT_STATUS, 'PENDING')) = 'REFUNDED'"
        ];
    } elseif ($filter === 'all') {
        $where = [
            "UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'CANCELLED'",
            "UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) IN ('COMPLETED', 'REFUNDED')"
        ];
    } else {
        $filter = 'due';
        $where = [
            "UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'CANCELLED'",
            "UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'"
        ];
    }
    $binds = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) { $where[] = "TRUNC(o.PICKUP_DATE) >= TO_DATE(:from_date, 'YYYY-MM-DD')"; $binds[':from_date'] = $fromDate; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) { $where[] = "TRUNC(o.PICKUP_DATE) <= TO_DATE(:to_date, 'YYYY-MM-DD')"; $binds[':to_date'] = $toDate; }
    if ($traderFilter !== '') { $where[] = "oi.TRADER_ID = :trader_id"; $binds[':trader_id'] = $traderFilter; }
    if ($searchTerm !== '') {
        $where[] = "(UPPER(o.ORDER_ID) LIKE :q OR UPPER(u.FIRST_NAME || ' ' || u.LAST_NAME) LIKE :q OR UPPER(u.EMAIL_ADDRESS) LIKE :q OR UPPER(pr.PRODUCT_NAME) LIKE :q OR UPPER(NVL(s.SHOP_NAME, '')) LIKE :q)";
        $binds[':q'] = '%' . strtoupper($searchTerm) . '%';
    }
    $whereSql = implode(' AND ', $where);

    $rows = db_all($conn, "
        SELECT o.ORDER_ID,
               TO_CHAR(o.ORDER_DATE, 'Mon DD, YYYY') AS ORDER_DATE_TEXT,
               TO_CHAR(o.PICKUP_DATE, 'YYYY-MM-DD') AS PICKUP_DATE_TEXT,
               ps.START_HOUR,
               ps.END_HOUR,
               o.ORDER_STATUS,
               p.PAYMENT_STATUS,
               u.FIRST_NAME || ' ' || u.LAST_NAME AS CUSTOMER_NAME,
               u.EMAIL_ADDRESS AS CUSTOMER_EMAIL,
               oi.PRODUCT_ID,
               pr.PRODUCT_NAME,
               oi.TRADER_ID,
               tu.FIRST_NAME || ' ' || tu.LAST_NAME AS TRADER_NAME,
               tu.EMAIL_ADDRESS AS TRADER_EMAIL,
               s.SHOP_NAME,
               oi.QUANTITY,
               oi.LOCKED_PRICE,
               (oi.QUANTITY * oi.LOCKED_PRICE) AS LINE_TOTAL,
               CASE
                   WHEN NVL((SELECT SUM(x.QUANTITY * x.LOCKED_PRICE) FROM ORDER_ITEM x WHERE x.ORDER_ID = o.ORDER_ID), 0) > 0
                   THEN ROUND(((oi.QUANTITY * oi.LOCKED_PRICE) / (SELECT SUM(x.QUANTITY * x.LOCKED_PRICE) FROM ORDER_ITEM x WHERE x.ORDER_ID = o.ORDER_ID)) * NVL(p.AMOUNT_PAID, o.TOTAL_AMOUNT), 2)
                   ELSE (oi.QUANTITY * oi.LOCKED_PRICE)
               END AS REFUND_ESTIMATE,
               oi.ITEM_STATUS,
               (SELECT COUNT(*) FROM ORDER_ITEM x WHERE x.ORDER_ID = o.ORDER_ID) AS ORDER_ITEM_COUNT,
               (SELECT COUNT(*) FROM ORDER_ITEM x WHERE x.ORDER_ID = o.ORDER_ID AND UPPER(NVL(x.ITEM_STATUS, 'PENDING')) = 'CANCELLED') AS ORDER_CANCELLED_COUNT
        FROM ORDERS o
        INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
        INNER JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
        INNER JOIN CUSTOMER c ON c.USER_ID = o.CUSTOMER_ID
        INNER JOIN \"USER\" u ON u.USER_ID = c.USER_ID
        INNER JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
        INNER JOIN PRODUCT pr ON pr.PRODUCT_ID = oi.PRODUCT_ID
        INNER JOIN TRADER t ON t.USER_ID = oi.TRADER_ID
        INNER JOIN \"USER\" tu ON tu.USER_ID = t.USER_ID
        LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
        WHERE $whereSql
          AND UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) IN ('COMPLETED', 'REFUNDED')
          AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) <> 'COLLECTED'
        ORDER BY o.PICKUP_DATE ASC, o.ORDER_ID DESC, tu.FIRST_NAME ASC, pr.PRODUCT_NAME ASC
    ", $binds);

    foreach ($rows as $row) {
        $summary['items']++;
        $summary['orders'][(string)$row['ORDER_ID']] = true;
        $line = (float)($row['LINE_TOTAL'] ?? 0);
        $summary['gross'] += $line;
        if (strtoupper((string)($row['PAYMENT_STATUS'] ?? '')) === 'COMPLETED') $summary['refund_pending'] += (float)($row['REFUND_ESTIMATE'] ?? $line);
    }
} catch (Throwable $e) { $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load uncollected orders.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Refund Queue - ShopLocalfy Admin</title>
  <link rel="stylesheet" href="../assets/admin/css/uncollected-orders.css?v=20260520">
</head>
<body><div class="layout-wrapper"><?php include 'sidebar.php'; ?><div class="main-content"><?php include 'topbar.php'; ?>
<main class="uncollected-page">
  <section class="uncollected-hero"><div class="hero-card"><p class="eyebrow">Refund management</p><h1>Refund Queue</h1></div></section>
  <?php if ($message !== ''): ?><div class="notice success"><?php echo admin_h($message); ?></div><?php endif; ?>
  <?php if ($autoCancelNotice !== ''): ?><div class="notice success"><?php echo admin_h($autoCancelNotice); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice error"><?php echo admin_h($error); ?></div><?php endif; ?>
  <section class="summary-grid"><article class="summary-card"><span>Orders in view</span><strong><?php echo number_format(count($summary['orders'])); ?></strong></article><article class="summary-card"><span>Item rows</span><strong><?php echo number_format((int)$summary['items']); ?></strong></article><article class="summary-card"><span>Gross item value</span><strong><?php echo uq_money($summary['gross']); ?></strong></article><article class="summary-card"><span>Refund owed</span><strong><?php echo uq_money($summary['refund_pending']); ?></strong></article></section>
  <section class="toolbar-card dynamic-toolbar">
    <form method="get" class="filter-form">
      <label>
        <span>Refund status</span>
        <select name="filter" onchange="this.form.submit()">
          <option value="due" <?php echo $filter === 'due' ? 'selected' : ''; ?>>Pending manual refunds</option>
          <option value="done" <?php echo $filter === 'done' ? 'selected' : ''; ?>>Completed refund records</option>
          <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All refund records</option>
        </select>
      </label>

      <label>
        <span>From date</span>
        <input type="date" name="from" value="<?php echo admin_h($fromDate); ?>">
      </label>

      <label>
        <span>To date</span>
        <input type="date" name="to" value="<?php echo admin_h($toDate); ?>">
      </label>

      <label>
        <span>Trader</span>
        <select name="trader" onchange="this.form.submit()">
          <option value="">All traders</option>
          <?php foreach ($traderOptions as $trader): $tid = (string)($trader['TRADER_ID'] ?? ''); ?>
            <option value="<?php echo admin_h($tid); ?>" <?php echo $traderFilter === $tid ? 'selected' : ''; ?>><?php echo admin_h(($trader['SHOP_NAME'] ?? 'No shop') . ' — ' . ($trader['TRADER_NAME'] ?? $tid)); ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="search-field">
        <span>Search records</span>
        <input type="search" name="q" value="<?php echo admin_h($searchTerm); ?>" placeholder="Order ID, customer, product, shop">
      </label>

      <button type="submit">Apply filters</button>
    </form>
  </section>
  <?php if (!$rows): ?><section class="empty-card"><h2>No refund queue items found</h2><p>Try the “All refund records” filter, clear the search field, or widen the date range.</p></section><?php else: ?>
  <section class="table-card"><div class="table-wrap"><table><thead><tr><th>Order</th><th>Pickup</th><th>Customer</th><th>Product</th><th>Trader / Shop</th><th>Qty</th><th>Value / Refund owed</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php $lastOrderId = null; foreach ($rows as $row): $itemStatus = strtoupper((string)($row['ITEM_STATUS'] ?? 'PENDING')); $paymentStatus = strtoupper((string)($row['PAYMENT_STATUS'] ?? '')); $isPaidStatus = ($paymentStatus === 'COMPLETED'); $orderFullyCancelled = ((int)($row['ORDER_ITEM_COUNT'] ?? 0) > 0 && (int)($row['ORDER_ITEM_COUNT'] ?? 0) === (int)($row['ORDER_CANCELLED_COUNT'] ?? 0)); $slotText = str_pad((string)($row['START_HOUR'] ?? ''), 2, '0', STR_PAD_LEFT) . ':00-' . str_pad((string)($row['END_HOUR'] ?? ''), 2, '0', STR_PAD_LEFT) . ':00'; $currentOrderId = (string)($row['ORDER_ID'] ?? ''); if ($currentOrderId !== $lastOrderId): $lastOrderId = $currentOrderId; ?>
    <tr class="order-group-row"><td colspan="9"><strong>Order <?php echo admin_h($currentOrderId); ?></strong><span>Pickup: <?php echo admin_h(($row['PICKUP_DATE_TEXT'] ?? '—') . ' ' . $slotText); ?></span><span>Customer: <?php echo admin_h($row['CUSTOMER_NAME'] ?? 'Customer'); ?></span><span>Payment: <?php echo admin_h($paymentStatus ?: '—'); ?></span></td></tr>
  <?php endif; ?>
    <tr><td><a class="order-link" href="order-detail.php?id=<?php echo rawurlencode((string)$row['ORDER_ID']); ?>"><?php echo admin_h($row['ORDER_ID']); ?></a><small><?php echo admin_h($row['ORDER_DATE_TEXT'] ?? ''); ?></small></td><td><?php echo admin_h($row['PICKUP_DATE_TEXT'] ?? '—'); ?></td><td><strong><?php echo admin_h($row['CUSTOMER_NAME'] ?? 'Customer'); ?></strong><small><?php echo admin_h($row['CUSTOMER_EMAIL'] ?? '—'); ?></small></td><td><strong><?php echo admin_h($row['PRODUCT_NAME'] ?? 'Product'); ?></strong><small><?php echo admin_h($row['PRODUCT_ID'] ?? ''); ?></small></td><td><strong><?php echo admin_h($row['SHOP_NAME'] ?: ($row['TRADER_NAME'] ?? 'Trader')); ?></strong><small><?php echo admin_h($row['TRADER_EMAIL'] ?? '—'); ?></small></td><td><?php echo number_format((int)($row['QUANTITY'] ?? 0)); ?></td><td><?php echo uq_money($row['LINE_TOTAL'] ?? 0); ?><small>Refund owed: <?php echo uq_money($row['REFUND_ESTIMATE'] ?? $row['LINE_TOTAL'] ?? 0); ?></small></td><td><span class="status-pill <?php echo admin_h(uq_status_class($itemStatus)); ?>"><?php echo admin_h($itemStatus); ?></span><small>Payment: <?php echo admin_h($paymentStatus); ?></small></td><td><?php if ($itemStatus !== 'CANCELLED' && $isPaidStatus): ?><form method="post" onsubmit="return confirm('Cancel this uncollected item locally and restore stock? Refund still has to be done manually in PayPal Sandbox.');"><input type="hidden" name="action" value="cancel_item"><input type="hidden" name="order_id" value="<?php echo admin_h($row['ORDER_ID']); ?>"><input type="hidden" name="product_id" value="<?php echo admin_h($row['PRODUCT_ID']); ?>"><input type="hidden" name="filter" value="<?php echo admin_h($filter); ?>"><button class="cancel-btn" type="submit">Cancel locally</button></form><?php elseif ($itemStatus === 'CANCELLED' && $isPaidStatus && $orderFullyCancelled): ?><form method="post" onsubmit="return confirm('Only click after refunding this order manually in PayPal Sandbox. Record PAYMENT_STATUS as REFUNDED?');"><input type="hidden" name="action" value="mark_refunded"><input type="hidden" name="order_id" value="<?php echo admin_h($row['ORDER_ID']); ?>"><input type="hidden" name="filter" value="<?php echo admin_h($filter); ?>"><button class="refund-btn" type="submit">Mark refund complete</button></form><?php elseif ($itemStatus === 'CANCELLED' && $isPaidStatus): ?><small>Partial refund owed: <?php echo uq_money($row['REFUND_ESTIMATE'] ?? $row['LINE_TOTAL'] ?? 0); ?></small><?php else: ?><small>No action needed.</small><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section><?php endif; ?>
</main></div></div></body></html>

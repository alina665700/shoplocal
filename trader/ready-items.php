<?php
// trader/ready-items.php
// Focused preparation queue for trader order items.

require_once __DIR__ . '/trader_common.php';
require_once __DIR__ . '/../config/order_lifecycle.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$errors = [];
$flash = trim((string)($_GET['success'] ?? ''));
$filter = 'upcoming';
$autoCancelNotice = '';

function ready_items_redirect($success = '', $error = '', $filter = 'upcoming') {
    $params = [];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    if ($filter !== '') $params['filter'] = $filter;
    header('Location: ready-items.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

if (isset($_GET['error']) && trim((string)$_GET['error']) !== '') {
    $errors[] = trim((string)$_GET['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_ready') {
    $orderId = trim((string)($_POST['order_id'] ?? ''));
    $productId = trim((string)($_POST['product_id'] ?? ''));
    $filterBack = 'upcoming';

    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if ($orderId === '' || $productId === '') throw new RuntimeException('Invalid item request.');
        if (!column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS')) throw new RuntimeException('ORDER_ITEM.ITEM_STATUS is missing.');

        try {
            $stmt = db_bind_and_execute($conn, "
                UPDATE ORDER_ITEM oi
                SET oi.ITEM_STATUS = 'READY'
                WHERE oi.ORDER_ID = :order_id
                  AND oi.PRODUCT_ID = :product_id
                  AND oi.TRADER_ID = :trader_id
                  AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) NOT IN ('READY', 'COLLECTED', 'CANCELLED')
                  AND EXISTS (
                      SELECT 1
                      FROM ORDERS o
                      INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
                      WHERE o.ORDER_ID = oi.ORDER_ID
                        AND UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'
                        AND TRUNC(o.PICKUP_DATE) > TRUNC(SYSDATE)
                        AND UPPER(NVL(o.ORDER_STATUS, 'CONFIRMED')) <> 'CANCELLED'
                  )
            ", [':order_id' => $orderId, ':product_id' => $productId, ':trader_id' => $traderId], OCI_NO_AUTO_COMMIT);

            if (function_exists('oci_num_rows') && oci_num_rows($stmt) !== 1) {
                throw new RuntimeException('This item was not updated. It may already be ready, collected, cancelled, overdue, unpaid, or not yours.');
            }

            sl_order_sync_parent_status($conn, $orderId, OCI_NO_AUTO_COMMIT);
            oci_commit($conn);
        } catch (Throwable $tx) {
            oci_rollback($conn);
            throw $tx;
        }

        ready_items_redirect('Item marked ready.', '', $filterBack);
    } catch (Throwable $e) {
        ready_items_redirect('', shoplocalfy_public_exception_message($e, 'Could not mark item ready.'), $filterBack);
    }
}

$items = [];
$summary = ['items' => 0, 'today' => 0, 'upcoming' => 0, 'value' => 0.0];

try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    $autoCancel = sl_order_auto_cancel_overdue_uncollected($conn);
    if (!empty($autoCancel['cancelled_items'])) $autoCancelNotice = $autoCancel['message'];

    // This page is intentionally a future-pickup preparation queue.
    // Today's pickup items are handled by the admin Today's Collections page.
    $dateCondition = "TRUNC(o.PICKUP_DATE) > TRUNC(SYSDATE)";

    $imageSelect = column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE') ? 'p.PRODUCT_IMAGE' : 'NULL';
    $items = db_all($conn, "
        SELECT
            o.ORDER_ID,
            TO_CHAR(o.PICKUP_DATE, 'DD Mon YYYY') AS PICKUP_DATE_LABEL,
            TRUNC(o.PICKUP_DATE) AS PICKUP_DATE_RAW,
            INITCAP(ps.ALLOWED_DAY) || ', ' || LPAD(ps.START_HOUR, 2, '0') || ':00-' || LPAD(ps.END_HOUR, 2, '0') || ':00' AS PICKUP_SLOT_LABEL,
            p.PRODUCT_ID,
            p.PRODUCT_NAME,
            {$imageSelect} AS PRODUCT_IMAGE,
            oi.QUANTITY,
            oi.LOCKED_PRICE,
            (oi.QUANTITY * oi.LOCKED_PRICE) AS LINE_TOTAL,
            oi.ITEM_STATUS,
            u.FIRST_NAME || ' ' || u.LAST_NAME AS CUSTOMER_NAME
        FROM ORDER_ITEM oi
        INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
        INNER JOIN PAYMENT pay ON pay.ORDER_ID = o.ORDER_ID
        INNER JOIN PRODUCT p ON p.PRODUCT_ID = oi.PRODUCT_ID
        LEFT JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
        LEFT JOIN \"USER\" u ON u.USER_ID = o.CUSTOMER_ID
        WHERE oi.TRADER_ID = :trader_id
          AND UPPER(NVL(pay.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'
          AND UPPER(NVL(o.ORDER_STATUS, 'CONFIRMED')) <> 'CANCELLED'
          AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'PENDING'
          AND $dateCondition
        ORDER BY o.PICKUP_DATE ASC, ps.START_HOUR ASC NULLS LAST, o.ORDER_ID DESC, p.PRODUCT_NAME ASC
    ", [':trader_id' => $traderId]);

    foreach ($items as $item) {
        $summary['items']++;
        $summary['value'] += (float)($item['LINE_TOTAL'] ?? 0);
        $pickupText = (string)($item['PICKUP_DATE_LABEL'] ?? '');
        // These are display counters; the SQL filter remains the source of truth.
        if ($filter === 'today') $summary['today']++;
        elseif ($filter === 'upcoming') $summary['upcoming']++;
    }
} catch (Throwable $e) {
    $errors[] = shoplocalfy_public_exception_message($e, 'Could not load preparation queue.');
}

$pendingOrderCount = get_pending_order_count($conn, $traderId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Get Items Ready - ShopLocalfy Trader</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/ready-items.css?v=20260518">
</head>
<body>
<?php $active = 'ready'; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Get Items Ready', 'Prepare paid pickup items before the customer arrives'); ?>
  <main class="ready-page body">
    <?php if ($flash !== ''): ?><div class="notice success"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($autoCancelNotice !== ''): ?><div class="notice success"><?php echo e($autoCancelNotice); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <section class="ready-hero">
      <div><p class="eyebrow">Preparation queue</p><h1>Items to Get Ready</h1><p>Only paid, non-cancelled, not-yet-ready items from your shop appear here.</p></div>
      <a class="ghost-link" href="orders.php">All orders</a>
    </section>

    <section class="summary-grid">
      <article><span>Item rows</span><strong><?php echo int_fmt($summary['items']); ?></strong></article>
      <article><span>Queue value</span><strong><?php echo money_fmt($summary['value']); ?></strong></article>
      <article><span>Shop</span><strong><?php echo e($profile['SHOP_NAME'] ?? 'Your shop'); ?></strong></article>
    </section>

    <section class="toolbar-card">
      <strong>Showing future pickup dates</strong>
      <span>Only paid future-pickup items that still need preparation are listed here.</span>
    </section>

    <?php if (!$items): ?>
      <section class="empty-card"><h2>No items need preparation</h2><p>When customers place paid orders for your shop, pending items will appear here until you mark them ready.</p></section>
    <?php else: ?>
      <section class="ready-list">
        <?php foreach ($items as $item): $img = product_image_path($item['PRODUCT_IMAGE'] ?? ''); ?>
          <article class="ready-card">
            <?php if ($img): ?><img src="<?php echo e($img); ?>" alt="<?php echo e($item['PRODUCT_NAME']); ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"><?php else: ?><div class="img-placeholder">🏷</div><?php endif; ?>
            <div class="ready-info">
              <div class="ready-title"><strong><?php echo e($item['PRODUCT_NAME']); ?></strong><span><?php echo e($item['PRODUCT_ID']); ?></span></div>
              <div class="ready-meta">
                <span>Order <?php echo e($item['ORDER_ID']); ?></span>
                <span>Pickup <?php echo e($item['PICKUP_DATE_LABEL']); ?></span>
                <span><?php echo e($item['PICKUP_SLOT_LABEL'] ?: 'Slot not set'); ?></span>
                <span>Customer: <?php echo e($item['CUSTOMER_NAME'] ?: 'Customer'); ?></span>
              </div>
            </div>
            <div class="ready-side">
              <strong>Qty <?php echo int_fmt($item['QUANTITY']); ?></strong>
              <span><?php echo money_fmt($item['LINE_TOTAL']); ?></span>
              <form method="post" onsubmit="return confirm('Mark this item as ready for collection?');">
                <input type="hidden" name="action" value="mark_ready">
                <input type="hidden" name="order_id" value="<?php echo e($item['ORDER_ID']); ?>">
                <input type="hidden" name="product_id" value="<?php echo e($item['PRODUCT_ID']); ?>">
                <input type="hidden" name="filter" value="upcoming">
                <button type="submit">Mark ready</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
</div>
</body>
</html>

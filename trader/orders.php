<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$errors = [];
$flash = '';

function sync_parent_order_status_from_items($conn, $orderId, $mode = OCI_COMMIT_ON_SUCCESS) {
    if (!$conn || !$orderId || !column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS')) {
        return;
    }

    $summary = db_one($conn, "
        SELECT
            COUNT(*) AS TOTAL,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'PENDING' THEN 1 ELSE 0 END) AS PENDING_TOTAL,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'READY' THEN 1 ELSE 0 END) AS READY_TOTAL,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'COLLECTED' THEN 1 ELSE 0 END) AS COLLECTED_TOTAL,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'CANCELLED' THEN 1 ELSE 0 END) AS CANCELLED_TOTAL
        FROM ORDER_ITEM
        WHERE ORDER_ID = :order_id
    ", [':order_id' => $orderId]);

    $total = (int)($summary['TOTAL'] ?? 0);
    $pending = (int)($summary['PENDING_TOTAL'] ?? 0);
    $ready = (int)($summary['READY_TOTAL'] ?? 0);
    $collected = (int)($summary['COLLECTED_TOTAL'] ?? 0);
    $cancelled = (int)($summary['CANCELLED_TOTAL'] ?? 0);

    if ($total <= 0) {
        return;
    }

    $activeItems = $total - $cancelled;
    if ($activeItems <= 0) {
        $newStatus = 'CANCELLED';
    } elseif ($collected === $activeItems) {
        $newStatus = 'COLLECTED';
    } elseif (($ready + $collected) === $activeItems && $pending === 0) {
        $newStatus = 'READY';
    } else {
        $newStatus = 'CONFIRMED';
    }

    db_bind_and_execute($conn, 'UPDATE ORDERS SET ORDER_STATUS = :status WHERE ORDER_ID = :order_id', [':status' => $newStatus, ':order_id' => $orderId], $mode);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_ready') {
    $orderId = trim($_POST['order_id'] ?? '');
    $productId = trim($_POST['product_id'] ?? '');
    if ($conn && $orderId !== '' && $productId !== '') {
        try {
            if (!column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS')) {
                throw new RuntimeException('ORDER_ITEM.ITEM_STATUS is missing. Reset the database using setup/Create Database.sql and setup/Auto sequence.sql.');
            }

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
                          WHERE o.ORDER_ID = oi.ORDER_ID
                            AND UPPER(NVL(o.ORDER_STATUS, 'CONFIRMED')) IN ('CONFIRMED', 'READY')
                      )
                ", [':order_id' => $orderId, ':product_id' => $productId, ':trader_id' => $traderId], OCI_NO_AUTO_COMMIT);

                if (oci_num_rows($stmt) !== 1) {
                    throw new RuntimeException('This item was not updated. It may already be ready, cancelled, collected, unpaid, or it may not belong to your shop.');
                }

                sync_parent_order_status_from_items($conn, $orderId, OCI_NO_AUTO_COMMIT);
                oci_commit($conn);
                $flash = 'Your item was marked ready. The full order becomes READY only after every item is ready.';
            } catch (Throwable $tx) {
                oci_rollback($conn);
                throw $tx;
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not update item status: ' . shoplocalfy_public_exception_message($e, 'Could not update order item.');
        }
    }
}

function get_trader_order_items($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'PRODUCT')) {
        return [];
    }

    $imageSelect = column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE') ? 'p.PRODUCT_IMAGE' : 'NULL';
    $itemStatusSelect = column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS') ? 'oi.ITEM_STATUS' : "'PENDING'";

    try {
        return db_all($conn, "
            SELECT
                o.ORDER_ID,
                o.CUSTOMER_ID,
                o.ORDER_STATUS,
                {$itemStatusSelect} AS ITEM_STATUS,
                TO_CHAR(o.ORDER_DATE, 'DD Mon YYYY') AS ORDER_DATE_LABEL,
                TO_CHAR(o.PICKUP_DATE, 'DD Mon YYYY') AS PICKUP_DATE_LABEL,
                ps.ALLOWED_DAY,
                ps.START_HOUR,
                ps.END_HOUR,
                INITCAP(ps.ALLOWED_DAY) || ', ' || LPAD(ps.START_HOUR, 2, '0') || ':00-' || LPAD(ps.END_HOUR, 2, '0') || ':00' AS PICKUP_SLOT_LABEL,
                (u.FIRST_NAME || ' ' || u.LAST_NAME) AS CUSTOMER_NAME,
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                {$imageSelect} AS PRODUCT_IMAGE,
                oi.QUANTITY,
                oi.LOCKED_PRICE,
                (oi.QUANTITY * oi.LOCKED_PRICE) AS LINE_TOTAL
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            INNER JOIN PRODUCT p ON p.PRODUCT_ID = oi.PRODUCT_ID
            LEFT JOIN \"USER\" u ON u.USER_ID = o.CUSTOMER_ID
            LEFT JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
            WHERE oi.TRADER_ID = :trader_id
            ORDER BY o.ORDER_DATE DESC NULLS LAST, o.ORDER_ID DESC, p.PRODUCT_NAME ASC
        ", [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        $errors[] = 'Orders query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load orders.');
        return [];
    }
}

$orders = get_trader_order_items($conn, $traderId, $errors);
$pendingCount = get_pending_order_count($conn, $traderId);

$orderIdsByStatus = [];
foreach ($orders as $row) {
    $oid = $row['ORDER_ID'];
    $displayStatus = strtoupper((string)($row['ITEM_STATUS'] ?? $row['ORDER_STATUS'] ?? 'PENDING'));
    $orderIdsByStatus[$displayStatus][$oid] = true;
}
$totalOrders = count(array_unique(array_map(fn($r) => $r['ORDER_ID'], $orders)));

// Internally, ORDER_ITEM uses PENDING for paid items that the trader has not marked READY yet.
// In the trader UI, show these as Confirmed so it is clearer for users.
$countConfirmed = isset($orderIdsByStatus['PENDING']) ? count($orderIdsByStatus['PENDING']) : 0;
$countReady = isset($orderIdsByStatus['READY']) ? count($orderIdsByStatus['READY']) : 0;
$countCollected = isset($orderIdsByStatus['COLLECTED']) ? count($orderIdsByStatus['COLLECTED']) : 0;
$countCancelled = isset($orderIdsByStatus['CANCELLED']) ? count($orderIdsByStatus['CANCELLED']) : 0;

$tabs = [
    ['all', 'All', $totalOrders],
    ['pending', 'Confirmed', $countConfirmed],
    ['ready', 'Ready', $countReady],
    ['collected', 'Collected', $countCollected],
    ['cancelled', 'Cancelled', $countCancelled],
];

$orderGroups = [];
foreach ($orders as $row) {
    $oid = (string)($row['ORDER_ID'] ?? '');
    if ($oid === '') {
        $oid = 'UNKNOWN';
    }

    $displayStatus = strtoupper((string)($row['ITEM_STATUS'] ?? $row['ORDER_STATUS'] ?? 'PENDING'));
    $statusKey = strtolower($displayStatus);
    $pickupSlot = trim((string)($row['PICKUP_SLOT_LABEL'] ?? ''));
    if ($pickupSlot === '') {
        $pickupSlot = 'Slot not set';
    }

    if (!isset($orderGroups[$oid])) {
        $orderGroups[$oid] = [
            'ORDER_ID' => $oid,
            'CUSTOMER_ID' => $row['CUSTOMER_ID'] ?? '',
            'ORDER_STATUS' => $row['ORDER_STATUS'] ?? 'CONFIRMED',
            'ORDER_DATE_LABEL' => $row['ORDER_DATE_LABEL'] ?? '',
            'PICKUP_DATE_LABEL' => $row['PICKUP_DATE_LABEL'] ?? '',
            'PICKUP_SLOT_LABEL' => $pickupSlot,
            'ITEMS' => [],
            'TOTAL' => 0,
            'STATUSES' => [],
            'SEARCH_PARTS' => [
                $oid,
                $row['CUSTOMER_ID'] ?? '',
                $row['ORDER_DATE_LABEL'] ?? '',
                $row['PICKUP_DATE_LABEL'] ?? '',
                $pickupSlot,
            ],
        ];
    }

    $orderGroups[$oid]['ITEMS'][] = $row;
    $orderGroups[$oid]['TOTAL'] += (float)($row['LINE_TOTAL'] ?? 0);
    $orderGroups[$oid]['STATUSES'][$statusKey] = $displayStatus;
    $orderGroups[$oid]['SEARCH_PARTS'][] = $row['PRODUCT_NAME'] ?? '';
    $orderGroups[$oid]['SEARCH_PARTS'][] = $displayStatus;
}

foreach ($orderGroups as &$group) {
    $statusKeys = array_keys($group['STATUSES']);
    $statuses = array_values($group['STATUSES']);
    $group['STATUS_KEYS'] = implode(' ', $statusKeys);
    $group['GROUP_STATUS'] = count($statuses) === 1 ? $statuses[0] : 'MIXED';
    $group['SEARCH'] = strtolower(trim(implode(' ', $group['SEARCH_PARTS'])));
}
unset($group);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy — Orders</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/orders.css?v=20260517">
</head>
<body>
<?php $active = 'orders'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Order Management', 'Only items belonging to your shop are shown'); ?>
  <div class="body">
    <?php if ($flash): ?><div class="notice" style="background:#ecfdf5;border-color:#bbf7d0;color:#047857"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="page-header">
      <div class="page-header-left"><h1>📦 Orders</h1><p><?php echo int_fmt($totalOrders); ?> order<?php echo $totalOrders === 1 ? '' : 's'; ?> found for <?php echo e($profile['SHOP_NAME']); ?></p></div>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="orders-search"><span>🔍</span><input type="text" id="orderSearch" placeholder="Search orders…"></div>
        <a class="btn-add" href="orders.php"><span>↻</span> Refresh</a>
      </div>
    </div>

    <div class="filter-tabs">
      <?php foreach ($tabs as $i => $tab): ?>
        <button type="button" class="ftab <?php echo $i === 0 ? 'active' : ''; ?>" data-filter="<?php echo e($tab[0]); ?>"><?php echo e($tab[1]); ?><span class="ftab-count"><?php echo e($tab[2]); ?></span></button>
      <?php endforeach; ?>
    </div>

    <div class="summary-strip">
      <div class="sum-card"><div class="sum-ico" style="background:rgba(61,191,164,.12)">✅</div><div><div class="sum-val"><?php echo int_fmt($countConfirmed); ?></div><div class="sum-lbl">Confirmed</div></div></div>
      <div class="sum-card"><div class="sum-ico" style="background:rgba(16,185,129,.12)">📦</div><div><div class="sum-val"><?php echo int_fmt($countReady); ?></div><div class="sum-lbl">Ready</div></div></div>
      <div class="sum-card"><div class="sum-ico" style="background:rgba(59,130,246,.12)">🛍️</div><div><div class="sum-val"><?php echo int_fmt($countCollected); ?></div><div class="sum-lbl">Collected</div></div></div>
      <div class="sum-card"><div class="sum-ico" style="background:rgba(239,68,68,.12)">✕</div><div><div class="sum-val"><?php echo int_fmt($countCancelled); ?></div><div class="sum-lbl">Cancelled</div></div></div>
    </div>

    <div id="orderList" style="display:flex;flex-direction:column;gap:14px">
      <?php if (!$orderGroups): ?>
        <div class="empty-state"><div class="empty-ico">📦</div><p>No order items for your shop yet. When a customer checks out with your products, they will appear here.</p></div>
      <?php else: ?>
        <?php foreach ($orderGroups as $group):
          $groupStatus = strtoupper((string)($group['GROUP_STATUS'] ?? 'PENDING'));
          $groupStatusClass = $groupStatus === 'MIXED' ? status_class('CONFIRMED') : status_class($groupStatus);
          if ($groupStatus === 'MIXED') {
              $groupStatusLabel = 'Mixed';
          } elseif ($groupStatus === 'PENDING') {
              $groupStatusLabel = 'Confirmed';
          } else {
              $groupStatusLabel = ucwords(strtolower($groupStatus));
          }
          $itemCount = count($group['ITEMS']);
        ?>
          <section class="order-group" data-status="<?php echo e($group['STATUS_KEYS']); ?>" data-search="<?php echo e($group['SEARCH']); ?>">
            <div class="order-group-head">
              <div>
                <div class="order-group-title">
                  <strong>Order <?php echo e($group['ORDER_ID']); ?></strong>
                  <span class="pill <?php echo e($groupStatusClass); ?>"><?php echo e($groupStatusLabel); ?></span>
                </div>
                <div class="order-group-sub">
                  <span>Customer ID: <?php echo e($group['CUSTOMER_ID'] ?: '—'); ?></span>
                  <span>Ordered: <?php echo e($group['ORDER_DATE_LABEL'] ?: '—'); ?></span>
                  <span>Pickup: <?php echo e($group['PICKUP_DATE_LABEL'] ?: '—'); ?></span>
                  <span>Slot: <?php echo e($group['PICKUP_SLOT_LABEL'] ?: 'Slot not set'); ?></span>
                  <span><?php echo int_fmt($itemCount); ?> item<?php echo $itemCount === 1 ? '' : 's'; ?></span>
                </div>
              </div>
              <div class="order-group-total">
                <span class="amount"><?php echo money_fmt($group['TOTAL']); ?></span>
                <span class="order-date">Order total for your shop</span>
              </div>
            </div>

            <div class="order-group-items">
              <?php foreach ($group['ITEMS'] as $row):
                $displayStatus = strtoupper((string)($row['ITEM_STATUS'] ?? $row['ORDER_STATUS'] ?? 'PENDING'));
                $displayStatusLabel = $displayStatus === 'PENDING' ? 'Confirmed' : ucwords(strtolower($displayStatus));
                $img = product_image_path($row['PRODUCT_IMAGE'] ?? '');
              ?>
                <div class="order-line">
                  <?php if ($img): ?>
                    <img class="order-img" src="<?php echo e($img); ?>" alt="<?php echo e($row['PRODUCT_NAME']); ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';">
                  <?php else: ?>
                    <div class="order-img-placeholder">🏷</div>
                  <?php endif; ?>

                  <div class="order-line-info">
                    <div class="order-name"><?php echo e($row['PRODUCT_NAME']); ?></div>
                    <div class="order-line-meta">
                      <span>Product ID: <?php echo e($row['PRODUCT_ID']); ?></span>
                      <span>Qty: <?php echo int_fmt($row['QUANTITY']); ?></span>
                      <span>Unit: <?php echo money_fmt($row['LOCKED_PRICE']); ?></span>
                    </div>
                  </div>

                  <div class="order-line-right">
                    <div class="order-line-status">
                      <span class="order-line-price"><?php echo money_fmt($row['LINE_TOTAL']); ?></span>
                      <span class="pill <?php echo e(status_class($displayStatus)); ?>"><?php echo e($displayStatusLabel); ?></span>
                    </div>
                    <div class="order-actions">
                      <form method="POST" style="display:inline" onsubmit="return confirm('Mark this item as READY? The full order becomes READY only when every item is ready.');">
                        <input type="hidden" name="action" value="mark_ready">
                        <input type="hidden" name="order_id" value="<?php echo e($row['ORDER_ID']); ?>">
                        <input type="hidden" name="product_id" value="<?php echo e($row['PRODUCT_ID']); ?>">
                        <button class="act-btn complete" title="Mark this item Ready" <?php echo in_array($displayStatus, ['READY','COLLECTED','CANCELLED'], true) ? 'disabled' : ''; ?>>✓</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="toast" id="toast"></div>
<script src="../assets/trader/js/orders.js?v=20260517"></script>
</body>
</html>


<?php

require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/order_lifecycle.php';
require_once __DIR__ . '/../config/order_email_notifications.php';

$adminId = require_admin_login();

date_default_timezone_set('Asia/Kathmandu');

$conn = admin_db_connection();
$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');
$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$periodFilter = trim($_GET['period'] ?? '');
$autoCancelNotice = '';

if (!function_exists('admin_h')) {
    function admin_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function admin_cols($conn, $table) {
    if (!$conn || !table_exists($conn, $table)) return [];
    $rows = db_all($conn, "
        SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = :table_name
        ORDER BY COLUMN_ID
    ", [':table_name' => strtoupper($table)]);
    $cols = [];
    foreach ($rows as $row) $cols[strtoupper($row['COLUMN_NAME'])] = $row;
    return $cols;
}

function admin_pick_col($cols, $names) {
    foreach ($names as $name) {
        $name = strtoupper($name);
        if (isset($cols[$name])) return $name;
    }
    return null;
}

function admin_redirect_orders($success = '', $error = '') {
    $params = [];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: order-management.php' . $query);
    exit;
}

function admin_name_expr($cols) {
    if (isset($cols['FULL_NAME'])) return 'FULL_NAME';
    if (isset($cols['NAME'])) return 'NAME';
    if (isset($cols['USERNAME'])) return 'USERNAME';
    if (isset($cols['FIRST_NAME']) && isset($cols['LAST_NAME'])) return "TRIM(FIRST_NAME || ' ' || LAST_NAME)";
    if (isset($cols['FIRST_NAME'])) return 'FIRST_NAME';
    if (isset($cols['EMAIL'])) return 'EMAIL';
    return "'Unknown'";
}

function admin_user_display($conn, $userId) {
    if ($userId === null || $userId === '') return ['name' => 'Guest', 'email' => '—'];
    $uCols = admin_cols($conn, 'USER');
    $uIdCol = admin_pick_col($uCols, ['USER_ID', 'ID']);
    if ($uIdCol) {
        $nameExpr = admin_name_expr($uCols);
        $emailCol = admin_pick_col($uCols, ['EMAIL_ADDRESS', 'EMAIL', 'USER_EMAIL', 'MAIL']);
        $emailSelect = $emailCol ? "$emailCol AS EMAIL" : "NULL AS EMAIL";
        $row = db_one($conn, "SELECT $nameExpr AS NAME, $emailSelect FROM \"USER\" WHERE $uIdCol = :id", [':id' => $userId]);
        if ($row) return ['name' => $row['NAME'] ?? (string)$userId, 'email' => $row['EMAIL'] ?? '—'];
    }

    $cCols = admin_cols($conn, 'CUSTOMER');
    $cIdCol = admin_pick_col($cCols, ['CUSTOMER_ID', 'USER_ID', 'ID']);
    $cUserCol = admin_pick_col($cCols, ['USER_ID']);
    if ($cIdCol && $cUserCol) {
        $row = db_one($conn, "SELECT $cUserCol AS USER_ID FROM CUSTOMER WHERE $cIdCol = :id", [':id' => $userId]);
        if ($row && !empty($row['USER_ID']) && (string)$row['USER_ID'] !== (string)$userId) return admin_user_display($conn, $row['USER_ID']);
    }
    return ['name' => (string)$userId, 'email' => '—'];
}

function admin_status_class($status) {
    $s = strtoupper((string)$status);
    if (in_array($s, ['COLLECTED', 'COMPLETED', 'PAID', 'DELIVERED'], true)) return 'status-completed';
    if (in_array($s, ['CANCELLED', 'CANCELED', 'REJECTED'], true)) return 'status-cancelled';
    if (in_array($s, ['CONFIRMED', 'READY'], true)) return 'status-processing';
    return 'status-pending';
}

function admin_product_display($conn, $productId) {
    if ($productId === null || $productId === '') return ['name' => 'Product', 'image' => ''];
    $cols = admin_cols($conn, 'PRODUCT');
    $idCol = admin_pick_col($cols, ['PRODUCT_ID', 'ID']);
    if (!$idCol) return ['name' => (string)$productId, 'image' => ''];
    $nameCol = admin_pick_col($cols, ['PRODUCT_NAME', 'NAME', 'TITLE']);
    $imageCol = admin_pick_col($cols, ['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_URL', 'PRODUCT_IMG', 'IMG']);
    $nameSelect = $nameCol ? "$nameCol AS PRODUCT_NAME" : "'Product' AS PRODUCT_NAME";
    $imageSelect = $imageCol ? "$imageCol AS PRODUCT_IMAGE" : "NULL AS PRODUCT_IMAGE";
    $row = db_one($conn, "SELECT $nameSelect, $imageSelect FROM PRODUCT WHERE $idCol = :id", [':id' => $productId]);
    return ['name' => $row['PRODUCT_NAME'] ?? (string)$productId, 'image' => $row['PRODUCT_IMAGE'] ?? ''];
}

function admin_image_src($value) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $value = trim(str_replace('\\', '/', (string)$value));
    if ($value === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $value)) return $value;
    if (strpos($value, 'uploads/products/') === 0) {
        $file = dirname(__DIR__) . '/' . $value;
        return is_file($file) ? '../' . $value : $placeholder;
    }
    if (preg_match('/^(\/|\.\.\/)/i', $value)) return $value;
    $file = dirname(__DIR__) . '/uploads/products/' . basename($value);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($value)) : $placeholder;
}

function admin_order_items($conn, $orderId) {
    $itemCols = admin_cols($conn, 'ORDER_ITEM');
    if (!$itemCols) return [];
    $orderCol = admin_pick_col($itemCols, ['ORDER_ID']);
    $productCol = admin_pick_col($itemCols, ['PRODUCT_ID']);
    if (!$orderCol || !$productCol) return [];
    $qtyCol = admin_pick_col($itemCols, ['QUANTITY', 'QTY', 'ORDER_QUANTITY']);
    $priceCol = admin_pick_col($itemCols, ['LOCKED_PRICE', 'PRICE', 'UNIT_PRICE', 'ITEM_PRICE', 'PRODUCT_PRICE']);
    $qtySelect = $qtyCol ? "$qtyCol AS QUANTITY" : "1 AS QUANTITY";
    $priceSelect = $priceCol ? "$priceCol AS ITEM_PRICE" : "NULL AS ITEM_PRICE";
    $statusCol = admin_pick_col($itemCols, ['ITEM_STATUS']);
    $statusSelect = $statusCol ? "$statusCol AS ITEM_STATUS" : "'PENDING' AS ITEM_STATUS";
    $traderCol = admin_pick_col($itemCols, ['TRADER_ID']);
    $traderSelect = $traderCol ? "$traderCol AS TRADER_ID" : "NULL AS TRADER_ID";
    $rows = db_all($conn, "SELECT $productCol AS PRODUCT_ID, $traderSelect, $qtySelect, $priceSelect, $statusSelect FROM ORDER_ITEM WHERE $orderCol = :id ORDER BY $productCol", [':id' => $orderId]);
    foreach ($rows as &$row) {
        $product = admin_product_display($conn, $row['PRODUCT_ID'] ?? '');
        $row['PRODUCT_NAME'] = $product['name'];
        $row['PRODUCT_IMAGE'] = $product['image'];
        $row['LINE_TOTAL'] = (float)($row['ITEM_PRICE'] ?? 0) * max(1, (int)($row['QUANTITY'] ?? 1));
    }
    unset($row);
    return $rows;
}

function admin_calculate_items_total($items) {
    $total = 0;
    foreach ($items as $item) {
        if ($item['ITEM_PRICE'] !== null && $item['ITEM_PRICE'] !== '') {
            $total += (float)$item['ITEM_PRICE'] * max(1, (int)($item['QUANTITY'] ?? 1));
        }
    }
    return $total;
}

function admin_get_orders($conn, $q, $statusFilter, $periodFilter) {
    $cols = admin_cols($conn, 'ORDERS');
    if (!$cols) return [];
    $idCol = admin_pick_col($cols, ['ORDER_ID', 'ID']);
    if (!$idCol) return [];
    $customerCol = admin_pick_col($cols, ['CUSTOMER_ID', 'USER_ID']);
    $statusCol = admin_pick_col($cols, ['ORDER_STATUS', 'STATUS', 'ORDER_STATE']);
    $dateCol = admin_pick_col($cols, ['ORDER_DATE', 'CREATED_AT', 'CREATED_DATE', 'ORDERED_AT', 'PLACED_AT']);
    $totalCol = admin_pick_col($cols, ['TOTAL_AMOUNT', 'TOTAL_PRICE', 'GRAND_TOTAL', 'ORDER_TOTAL', 'AMOUNT']);
    $pickupCol = admin_pick_col($cols, ['PICKUP_DATE', 'COLLECTION_DATE', 'COLLECT_DATE']);

    $select = ["$idCol AS ORDER_ID"];
    $select[] = $customerCol ? "$customerCol AS CUSTOMER_ID" : "NULL AS CUSTOMER_ID";
    $select[] = $statusCol ? "$statusCol AS ORDER_STATUS" : "'CONFIRMED' AS ORDER_STATUS";
    $select[] = $dateCol ? "TO_CHAR($dateCol, 'Mon DD, YYYY') AS ORDER_DATE_TEXT" : "NULL AS ORDER_DATE_TEXT";
    $select[] = $pickupCol ? "TO_CHAR($pickupCol, 'Mon DD, YYYY') AS PICKUP_DATE_TEXT" : "NULL AS PICKUP_DATE_TEXT";
    $select[] = $totalCol ? "$totalCol AS TOTAL_AMOUNT" : "NULL AS TOTAL_AMOUNT";

    $where = [];
    $binds = [];
    if ($statusFilter !== '' && $statusCol) {
        $where[] = "UPPER($statusCol) = UPPER(:status_filter)";
        $binds[':status_filter'] = $statusFilter;
    }
    if ($periodFilter !== '' && $dateCol) {
        if ($periodFilter === 'today') $where[] = "TRUNC($dateCol) = TRUNC(SYSDATE)";
        if ($periodFilter === 'week') $where[] = "TRUNC($dateCol) >= TRUNC(SYSDATE, 'IW')";
        if ($periodFilter === 'month') $where[] = "TRUNC($dateCol) >= TRUNC(SYSDATE, 'MM')";
    }
    if ($q !== '') {
        $where[] = "(UPPER(TO_CHAR($idCol)) LIKE UPPER(:q)" . ($customerCol ? " OR UPPER(TO_CHAR($customerCol)) LIKE UPPER(:q)" : '') . ")";
        $binds[':q'] = '%' . $q . '%';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderSql = $dateCol ? "ORDER BY $dateCol DESC" : "ORDER BY $idCol DESC";
    $rows = db_all($conn, 'SELECT ' . implode(', ', $select) . " FROM ORDERS $whereSql $orderSql FETCH FIRST 100 ROWS ONLY", $binds);

    foreach ($rows as &$row) {
        $customer = admin_user_display($conn, $row['CUSTOMER_ID'] ?? '');
        $row['CUSTOMER_NAME'] = $customer['name'];
        $row['CUSTOMER_EMAIL'] = $customer['email'];
        $row['ITEMS'] = admin_order_items($conn, $row['ORDER_ID'] ?? '');
        if ($row['TOTAL_AMOUNT'] === null || $row['TOTAL_AMOUNT'] === '') {
            $row['TOTAL_AMOUNT'] = admin_calculate_items_total($row['ITEMS']);
        }
    }
    unset($row);
    return $rows;
}

function admin_total_orders($conn) {
    if (!$conn || !table_exists($conn, 'ORDERS')) return 0;
    $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM ORDERS');
    return (int)($row['TOTAL'] ?? 0);
}

function admin_order_item_status_enabled($conn) {
    return $conn && table_exists($conn, 'ORDER_ITEM') && column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS');
}

function admin_order_item_summary($conn, $orderId) {
    if (!admin_order_item_status_enabled($conn)) {
        return ['total' => 0, 'ready' => 0, 'collected' => 0, 'cancelled' => 0, 'pending' => 0];
    }

    $row = db_one($conn, "
        SELECT
            COUNT(*) AS TOTAL,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'READY' THEN 1 ELSE 0 END) AS READY_COUNT,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'COLLECTED' THEN 1 ELSE 0 END) AS COLLECTED_COUNT,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'CANCELLED' THEN 1 ELSE 0 END) AS CANCELLED_COUNT,
            SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) NOT IN ('READY', 'COLLECTED', 'CANCELLED') THEN 1 ELSE 0 END) AS PENDING_COUNT
        FROM ORDER_ITEM
        WHERE ORDER_ID = :order_id
    ", [':order_id' => $orderId]);

    return [
        'total' => (int)($row['TOTAL'] ?? 0),
        'ready' => (int)($row['READY_COUNT'] ?? 0),
        'collected' => (int)($row['COLLECTED_COUNT'] ?? 0),
        'cancelled' => (int)($row['CANCELLED_COUNT'] ?? 0),
        'pending' => (int)($row['PENDING_COUNT'] ?? 0),
    ];
}

function admin_order_can_be_ready($conn, $orderId) {
    if (!admin_order_item_status_enabled($conn)) return true;
    $summary = admin_order_item_summary($conn, $orderId);
    return $summary['total'] > 0
        && $summary['pending'] === 0
        && $summary['cancelled'] === 0
        && ($summary['ready'] + $summary['collected']) === $summary['total'];
}

function admin_current_order_status($conn, $orderId) {
    $row = db_one($conn, 'SELECT ORDER_STATUS FROM ORDERS WHERE ORDER_ID = :order_id', [':order_id' => $orderId]);
    return strtoupper((string)($row['ORDER_STATUS'] ?? ''));
}

function admin_restore_stock_for_order($conn, $orderId, $mode = OCI_COMMIT_ON_SUCCESS) {
    if (!admin_order_item_status_enabled($conn)) return;

    db_bind_and_execute($conn, "
        UPDATE PRODUCT p
        SET p.STOCK_AVAILABLE = NVL(p.STOCK_AVAILABLE, 0) + (
            SELECT NVL(SUM(oi.QUANTITY), 0)
            FROM ORDER_ITEM oi
            WHERE oi.ORDER_ID = :order_id
              AND oi.PRODUCT_ID = p.PRODUCT_ID
              AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) <> 'CANCELLED'
        )
        WHERE EXISTS (
            SELECT 1
            FROM ORDER_ITEM oi
            WHERE oi.ORDER_ID = :order_id
              AND oi.PRODUCT_ID = p.PRODUCT_ID
              AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) <> 'CANCELLED'
        )
    ", [':order_id' => $orderId], $mode);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        $cols = admin_cols($conn, 'ORDERS');
        $idCol = admin_pick_col($cols, ['ORDER_ID', 'ID']);
        $statusCol = admin_pick_col($cols, ['ORDER_STATUS', 'STATUS', 'ORDER_STATE']);
        if (!$idCol || !$statusCol) throw new RuntimeException('ORDERS ID or status column was not found.');

        $allowedStatuses = ['CONFIRMED', 'READY', 'COLLECTED', 'CANCELLED'];
        $orderId = trim($_POST['order_id'] ?? '');
        $newStatus = strtoupper(trim($_POST['order_status'] ?? ''));

        if ($orderId === '' || $newStatus === '') throw new RuntimeException('Order ID and status are required.');
        if (!in_array($newStatus, $allowedStatuses, true)) throw new RuntimeException('Invalid order status.');

        $currentStatus = admin_current_order_status($conn, $orderId);
        if ($currentStatus === 'CANCELLED' && $newStatus !== 'CANCELLED') {
            throw new RuntimeException('Cancelled orders cannot be reopened from this page. Create a new order instead.');
        }

        if (in_array($newStatus, ['READY', 'COLLECTED'], true) && !admin_order_can_be_ready($conn, $orderId)) {
            throw new RuntimeException('This order cannot be marked ' . $newStatus . ' until every item in the order is READY.');
        }

        $sendCancellationEmail = false;
        try {
            if ($newStatus === 'CANCELLED' && $currentStatus !== 'CANCELLED') {
                $sendCancellationEmail = true;
                admin_restore_stock_for_order($conn, $orderId, OCI_NO_AUTO_COMMIT);
              // Cancellation restores stock; the refund is recorded from the refund queue after PayPal Sandbox is handled.
                if (admin_order_item_status_enabled($conn)) {
                    db_bind_and_execute(
                        $conn,
                        'UPDATE ORDER_ITEM SET ITEM_STATUS = :status WHERE ORDER_ID = :order_id',
                        [':status' => 'CANCELLED', ':order_id' => $orderId],
                        OCI_NO_AUTO_COMMIT
                    );
                }
            }

            if ($newStatus === 'COLLECTED' && admin_order_item_status_enabled($conn)) {
                db_bind_and_execute(
                    $conn,
                    'UPDATE ORDER_ITEM SET ITEM_STATUS = :status WHERE ORDER_ID = :order_id',
                    [':status' => 'COLLECTED', ':order_id' => $orderId],
                    OCI_NO_AUTO_COMMIT
                );
            }

            db_bind_and_execute(
                $conn,
                "UPDATE ORDERS SET $statusCol = :status WHERE $idCol = :id",
                [':status' => $newStatus, ':id' => $orderId],
                OCI_NO_AUTO_COMMIT
            );
            oci_commit($conn);
            if ($sendCancellationEmail && function_exists('shoplocalfy_send_order_cancellation_emails')) {
                shoplocalfy_send_order_cancellation_emails($conn, $orderId, null, 'admin_cancelled');
            }
        } catch (Throwable $tx) {
            oci_rollback($conn);
            throw $tx;
        }

        admin_redirect_orders('Order status updated successfully.');
    } catch (Throwable $e) {
        admin_redirect_orders('', shoplocalfy_public_exception_message($e, 'Could not update order.'));
    }
}

$orders = [];
$totalOrders = 0;
try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    $autoCancel = sl_order_auto_cancel_overdue_uncollected($conn);
    if (!empty($autoCancel['cancelled_items'])) {
        $autoCancelNotice = $autoCancel['message'];
    }
    $orders = admin_get_orders($conn, $q, $statusFilter, $periodFilter);
    $totalOrders = admin_total_orders($conn);
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load orders.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy – Order Management</title>

  <link rel="stylesheet" href="../assets/admin/css/order-management.css?v=20260517">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  </head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <div class="page-body">

      <div class="page-subtitle-row">
        <div>
          <h1 class="page-title">Order Management</h1>
          <p class="page-subtitle">Total orders overview</p>
        </div>
        <span class="total-orders-badge">
          <i class="fa-solid fa-box-open" style="margin-right:4px;"></i>Total Orders: <?php echo number_format($totalOrders); ?>
        </span>
      </div>

      <?php if ($message): ?>
        <div class="card" style="padding:14px 16px;margin-bottom:16px;color:#15803d;"><?php echo admin_h($message); ?></div>
      <?php endif; ?>
      <?php if ($autoCancelNotice !== ''): ?>
        <div class="card" style="padding:14px 16px;margin-bottom:16px;color:#b45309;"><?php echo admin_h($autoCancelNotice); ?> <a href="uncollected-orders.php?filter=refund" style="font-weight:700;color:#92400e;">View refund queue</a></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="card" style="padding:14px 16px;margin-bottom:16px;color:#dc2626;"><?php echo admin_h($error); ?></div>
      <?php endif; ?>

      <form class="order-filters" method="get">
        <div class="filter-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" value="<?php echo admin_h($q); ?>" placeholder="Search by order ID or customer ID…"/>
        </div>
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach (['CONFIRMED', 'READY', 'COLLECTED', 'CANCELLED'] as $statusOption): ?>
            <option value="<?php echo admin_h($statusOption); ?>" <?php echo strtoupper($statusFilter) === $statusOption ? 'selected' : ''; ?>><?php echo admin_h(ucfirst(strtolower($statusOption))); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="filter-select" name="period" onchange="this.form.submit()">
          <option value="" <?php echo $periodFilter === '' ? 'selected' : ''; ?>>All Time</option>
          <option value="today" <?php echo $periodFilter === 'today' ? 'selected' : ''; ?>>Today</option>
          <option value="week" <?php echo $periodFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
          <option value="month" <?php echo $periodFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
        </select>
      </form>

      <?php if (!$orders): ?>
        <div class="card">
          <div class="empty-state">
            <div class="empty-state-icon"><i class="fa-solid fa-inbox"></i></div>
            <div class="empty-state-title">No orders to display</div>
            <p class="empty-text">Orders will appear here once customers place them.</p>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($orders as $order): ?>
          <?php $itemCount = is_array($order['ITEMS'] ?? null) ? count($order['ITEMS']) : 0; ?>
          <div class="order-card organized-order-card">
            <div class="order-header organized-order-header">
              <div class="order-header-left">
                <div class="order-id">
                  <span class="order-id-pill"># <?php echo admin_h($order['ORDER_ID'] ?? '—'); ?></span>
                </div>
                <div class="order-meta">
                  <span><i class="fa-regular fa-user"></i> <?php echo admin_h($order['CUSTOMER_NAME'] ?? 'Customer'); ?></span>
                  <span><i class="fa-regular fa-clock"></i> Ordered: <?php echo admin_h($order['ORDER_DATE_TEXT'] ?? '—'); ?></span>
                  <span><i class="fa-solid fa-calendar-check"></i> Pickup: <?php echo admin_h($order['PICKUP_DATE_TEXT'] ?? '—'); ?></span>
                  <span><i class="fa-solid fa-boxes-stacked"></i> <?php echo number_format($itemCount); ?> item<?php echo $itemCount === 1 ? '' : 's'; ?></span>
                </div>
              </div>

              <div class="order-right organized-order-right">
                <div class="order-amount">£<?php echo number_format((float)($order['TOTAL_AMOUNT'] ?? 0), 2); ?></div>
                <span class="order-status <?php echo admin_h(admin_status_class($order['ORDER_STATUS'] ?? 'Pending')); ?>">
                  <i class="fa-solid fa-circle"></i> <?php echo admin_h($order['ORDER_STATUS'] ?? 'Pending'); ?>
                </span>
              </div>
            </div>

            <div class="order-body organized-order-body">
              <div class="order-items-panel">
                <div class="panel-title">Ordered Items</div>

                <?php if (empty($order['ITEMS'])): ?>
                  <div class="order-product organized-product">
                    <div class="product-img"><i class="fa-solid fa-box"></i></div>
                    <div class="product-info">
                      <span class="product-name">No order items found</span>
                      <span class="product-subtext">Check order details for raw order data.</span>
                    </div>
                    <span class="product-qty">Qty: —</span>
                  </div>
                <?php else: ?>
                  <?php foreach ($order['ITEMS'] as $item): ?>
                    <?php $img = admin_image_src($item['PRODUCT_IMAGE'] ?? ''); ?>
                    <div class="order-product organized-product">
                      <div class="product-img">
                        <?php if ($img): ?>
                          <img src="<?php echo admin_h($img); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';">
                        <?php else: ?>
                          <i class="fa-solid fa-image"></i>
                        <?php endif; ?>
                      </div>
                      <div class="product-info">
                        <span class="product-name"><?php echo admin_h($item['PRODUCT_NAME'] ?? 'Product'); ?></span>
                        <span class="product-subtext">Status: <?php echo admin_h(strtoupper((string)($item['ITEM_STATUS'] ?? 'PENDING'))); ?> · Line: £<?php echo number_format((float)($item['LINE_TOTAL'] ?? 0), 2); ?></span>
                      </div>
                      <span class="product-qty">Qty: <?php echo admin_h($item['QUANTITY'] ?? '1'); ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <aside class="order-action-panel">
                <form method="post" class="status-form">
                  <input type="hidden" name="order_id" value="<?php echo admin_h($order['ORDER_ID'] ?? ''); ?>">
                  <label class="status-label" for="status-<?php echo admin_h($order['ORDER_ID'] ?? ''); ?>">Update order status</label>
                  <select id="status-<?php echo admin_h($order['ORDER_ID'] ?? ''); ?>" name="order_status" class="filter-select status-select">
                    <?php foreach (['CONFIRMED', 'READY', 'COLLECTED', 'CANCELLED'] as $statusOption): ?>
                      <option value="<?php echo admin_h($statusOption); ?>" <?php echo strtoupper((string)($order['ORDER_STATUS'] ?? '')) === $statusOption ? 'selected' : ''; ?>><?php echo admin_h(ucfirst(strtolower($statusOption))); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn-order-action btn-update-status" type="submit">
                    <i class="fa-solid fa-pen-to-square"></i> Update Status
                  </button>
                </form>

                <a class="btn-order-action btn-view-order" href="order-detail.php?id=<?php echo rawurlencode((string)($order['ORDER_ID'] ?? '')); ?>">
                  <i class="fa-solid fa-eye"></i> View Details
                </a>
              </aside>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
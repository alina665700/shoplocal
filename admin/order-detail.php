<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/order_email_notifications.php';

$conn = admin_db_connection();
$adminId = require_admin_login();
$allowedOrderStatuses = ['CONFIRMED', 'READY', 'COLLECTED', 'CANCELLED'];
$allowedItemStatuses = ['PENDING', 'READY', 'COLLECTED', 'CANCELLED'];
$orderId = trim($_GET['id'] ?? $_POST['order_id'] ?? '');
$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');

function od_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function od_row_value($row, $keys, $default = '') {
    if (!is_array($row)) return $default;
    $keys = is_array($keys) ? $keys : [$keys];

    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) return $row[$key];
        $upper = strtoupper($key);
        if (array_key_exists($upper, $row)) return $row[$upper];
        $lower = strtolower($key);
        if (array_key_exists($lower, $row)) return $row[$lower];
    }

    foreach ($row as $existingKey => $value) {
        foreach ($keys as $key) {
            if (strcasecmp((string)$existingKey, (string)$key) === 0) {
                return $value;
            }
        }
    }

    return $default;
}

function od_row_text($row, $keys, $default = '—') {
    $value = od_row_value($row, $keys, null);
    if ($value === null) return $default;
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}


function od_redirect($orderId, $success = '', $error = '') {
    $params = ['id' => $orderId];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    header('Location: order-detail.php?' . http_build_query($params));
    exit;
}

function od_item_status_enabled($conn) {
    return $conn && table_exists($conn, 'ORDER_ITEM') && column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS');
}

function od_status_class($status) {
    $s = strtoupper((string)$status);
    if (in_array($s, ['COLLECTED', 'COMPLETED', 'PAID', 'DELIVERED'], true)) return 'status-completed';
    if (in_array($s, ['CANCELLED', 'CANCELED', 'REJECTED'], true)) return 'status-cancelled';
    if (in_array($s, ['CONFIRMED', 'READY'], true)) return 'status-processing';
    return 'status-pending';
}

function od_image_src($value) {
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

function od_order_summary($conn, $orderId) {
    if (!$conn || $orderId === '') return null;

    return db_one($conn, "
        SELECT
            o.ORDER_ID,
            o.CUSTOMER_ID,
            o.SLOT_ID,
            o.VOUCHER_ID,
            TO_CHAR(o.PICKUP_DATE, 'Mon DD, YYYY') AS PICKUP_DATE_TEXT,
            TO_CHAR(o.ORDER_DATE, 'Mon DD, YYYY') AS ORDER_DATE_TEXT,
            o.DISCOUNT_APPLIED,
            o.TOTAL_AMOUNT,
            o.ORDER_STATUS,
            TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS CUSTOMER_NAME,
            u.EMAIL_ADDRESS,
            u.PH_NUMBER,
            ps.ALLOWED_DAY,
            ps.START_HOUR,
            ps.END_HOUR,
            v.VOUCHER_CODE,
            v.DISCOUNT_TYPE,
            v.DISCOUNT_VALUE,
            p.PAYMENT_ID,
            p.AMOUNT_PAID,
            p.PAYMENT_METHOD,
            p.PAYMENT_STATUS,
            TO_CHAR(p.PAYMENT_DATE, 'Mon DD, YYYY HH24:MI') AS PAYMENT_DATE_TEXT
        FROM ORDERS o
        LEFT JOIN \"USER\" u ON u.USER_ID = o.CUSTOMER_ID
        LEFT JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
        LEFT JOIN VOUCHER v ON v.VOUCHER_ID = o.VOUCHER_ID
        LEFT JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
        WHERE o.ORDER_ID = :order_id
    ", [':order_id' => $orderId]);
}

function od_order_items($conn, $orderId) {
    if (!$conn || $orderId === '') return [];

    // Keep this page usable even on an older database where ORDER_ITEM.ITEM_STATUS
    // has not been added yet. In that case every item is displayed as PENDING,
    // and the update controls are hidden by od_item_status_enabled().
    $itemStatusSelect = od_item_status_enabled($conn)
        ? 'ITEM_STATUS'
        : "'PENDING' AS ITEM_STATUS";

    $stmt = oci_parse($conn, "
        SELECT ORDER_ID, PRODUCT_ID, TRADER_ID, QUANTITY, LOCKED_PRICE, {$itemStatusSelect}
        FROM ORDER_ITEM
        WHERE ORDER_ID = :order_id
        ORDER BY PRODUCT_ID
    ");
    if (!$stmt) {
        throw new RuntimeException(shoplocalfy_db_error_message($conn, 'Could not prepare order item query.'));
    }

    oci_bind_by_name($stmt, ':order_id', $orderId);
    if (!oci_execute($stmt)) {
        throw new RuntimeException(shoplocalfy_db_error_message($stmt, 'Could not load order item rows.'));
    }

    $orderItems = [];
    while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) !== false) {
        $quantity = (float)od_row_value($row, 'QUANTITY', 0);
        $lockedPrice = (float)od_row_value($row, 'LOCKED_PRICE', 0);
        $row['LINE_TOTAL'] = $quantity * $lockedPrice;
        $orderItems[] = array_merge($row, od_order_item_details(
            $conn,
            od_row_value($row, 'PRODUCT_ID', ''),
            od_row_value($row, 'TRADER_ID', '')
        ));
    }

    oci_free_statement($stmt);
    return $orderItems;
}

function od_order_item_details($conn, $productId, $traderId) {
    $details = [
        'PRODUCT_NAME' => '',
        'PRODUCT_IMAGE' => '',
        'SHOP_NAME' => '',
        'TRADER_NAME' => '',
    ];

    try {
        if ($productId !== '') {
            $product = db_one($conn, "
                SELECT p.PRODUCT_NAME, p.PRODUCT_IMAGE, s.SHOP_NAME
                FROM PRODUCT p
                LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
                WHERE p.PRODUCT_ID = :product_id
            ", [':product_id' => $productId]);
            if ($product) {
                $details['PRODUCT_NAME'] = od_row_value($product, 'PRODUCT_NAME', '');
                $details['PRODUCT_IMAGE'] = od_row_value($product, 'PRODUCT_IMAGE', '');
                $details['SHOP_NAME'] = od_row_value($product, 'SHOP_NAME', '');
            }
        }

        if ($traderId !== '') {
            $trader = db_one($conn, "
                SELECT TRIM(FIRST_NAME || ' ' || LAST_NAME) AS TRADER_NAME
                FROM \"USER\"
                WHERE USER_ID = :trader_id
            ", [':trader_id' => $traderId]);
            if ($trader) {
                $details['TRADER_NAME'] = od_row_value($trader, 'TRADER_NAME', '');
            }
        }
    } catch (Throwable $e) {
        return $details;
    }

    return $details;
}

function od_item_summary($conn, $orderId) {
    if (!od_item_status_enabled($conn)) {
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

function od_can_order_be_ready($conn, $orderId) {
    if (!od_item_status_enabled($conn)) return false;
    $summary = od_item_summary($conn, $orderId);
    return $summary['total'] > 0 && $summary['pending'] === 0 && $summary['cancelled'] === 0 && ($summary['ready'] + $summary['collected']) === $summary['total'];
}

function od_current_order_status($conn, $orderId) {
    $row = db_one($conn, 'SELECT ORDER_STATUS FROM ORDERS WHERE ORDER_ID = :order_id', [':order_id' => $orderId]);
    return strtoupper((string)($row['ORDER_STATUS'] ?? ''));
}

function od_sync_order_status_from_items($conn, $orderId, $mode = OCI_COMMIT_ON_SUCCESS) {
    if (!od_item_status_enabled($conn)) return;

    $summary = od_item_summary($conn, $orderId);
    if ($summary['total'] <= 0) {
        return;
    }

    $activeItems = $summary['total'] - $summary['cancelled'];

    if ($activeItems <= 0) {
        $newStatus = 'CANCELLED';
    } elseif ($summary['collected'] === $activeItems) {
        $newStatus = 'COLLECTED';
    } elseif (($summary['ready'] + $summary['collected']) === $activeItems && $summary['pending'] === 0) {
        $newStatus = 'READY';
    } else {
        $newStatus = 'CONFIRMED';
    }

    db_bind_and_execute(
        $conn,
        'UPDATE ORDERS SET ORDER_STATUS = :status WHERE ORDER_ID = :order_id',
        [':status' => $newStatus, ':order_id' => $orderId],
        $mode
    );
}

function od_restore_stock_for_order($conn, $orderId, $mode = OCI_COMMIT_ON_SUCCESS) {
    if (!od_item_status_enabled($conn)) return;

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


function od_adjust_stock_for_item_status_change($conn, $productId, $quantity, $oldStatus, $newStatus, $mode = OCI_COMMIT_ON_SUCCESS) {
    $quantity = max(0, (int)$quantity);
    if ($quantity <= 0 || $oldStatus === $newStatus) return;

    if ($oldStatus !== 'CANCELLED' && $newStatus === 'CANCELLED') {
        db_bind_and_execute(
            $conn,
            'UPDATE PRODUCT SET STOCK_AVAILABLE = NVL(STOCK_AVAILABLE, 0) + :quantity WHERE PRODUCT_ID = :product_id',
            [':quantity' => $quantity, ':product_id' => $productId],
            $mode
        );
        return;
    }

    if ($oldStatus === 'CANCELLED' && $newStatus !== 'CANCELLED') {
        $stmt = db_bind_and_execute(
            $conn,
            'UPDATE PRODUCT
             SET STOCK_AVAILABLE = NVL(STOCK_AVAILABLE, 0) - :quantity
             WHERE PRODUCT_ID = :product_id
               AND NVL(STOCK_AVAILABLE, 0) >= :quantity',
            [':quantity' => $quantity, ':product_id' => $productId],
            $mode
        );

        if (oci_num_rows($stmt) !== 1) {
            throw new RuntimeException('This cancelled item cannot be reopened because there is not enough stock available.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if ($orderId === '') throw new RuntimeException('Order ID is required.');
        $action = $_POST['action'] ?? '';

        if ($action === 'update_order_status') {
            $newStatus = strtoupper(trim($_POST['order_status'] ?? ''));
            if (!in_array($newStatus, $allowedOrderStatuses, true)) {
                throw new RuntimeException('Invalid order status.');
            }

            $currentStatus = od_current_order_status($conn, $orderId);
            if ($currentStatus === 'CANCELLED' && $newStatus !== 'CANCELLED') {
                throw new RuntimeException('Cancelled orders cannot be reopened from this page. Create a new order instead.');
            }
            if ($newStatus === 'READY' && !od_can_order_be_ready($conn, $orderId)) {
                throw new RuntimeException('This order cannot be marked READY until every item in this order is READY.');
            }
            if ($newStatus === 'COLLECTED' && !od_can_order_be_ready($conn, $orderId)) {
                throw new RuntimeException('This order cannot be marked COLLECTED until every item is READY.');
            }

            $sendCancellationEmail = false;
            try {
                if ($newStatus === 'CANCELLED' && $currentStatus !== 'CANCELLED') {
                    $sendCancellationEmail = true;
                    od_restore_stock_for_order($conn, $orderId, OCI_NO_AUTO_COMMIT);
                   
                    if (od_item_status_enabled($conn)) {
                        db_bind_and_execute(
                            $conn,
                            'UPDATE ORDER_ITEM SET ITEM_STATUS = :status WHERE ORDER_ID = :order_id',
                            [':status' => 'CANCELLED', ':order_id' => $orderId],
                            OCI_NO_AUTO_COMMIT
                        );
                    }
                }

                if ($newStatus === 'COLLECTED' && od_item_status_enabled($conn)) {
                    db_bind_and_execute(
                        $conn,
                        'UPDATE ORDER_ITEM SET ITEM_STATUS = :status WHERE ORDER_ID = :order_id',
                        [':status' => 'COLLECTED', ':order_id' => $orderId],
                        OCI_NO_AUTO_COMMIT
                    );
                }

                db_bind_and_execute(
                    $conn,
                    'UPDATE ORDERS SET ORDER_STATUS = :status WHERE ORDER_ID = :order_id',
                    [':status' => $newStatus, ':order_id' => $orderId],
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

            od_redirect($orderId, 'Order status updated.');
        }

        if ($action === 'update_item_status') {
            if (!od_item_status_enabled($conn)) {
                throw new RuntimeException('ORDER_ITEM.ITEM_STATUS is missing. Reset the database using setup/Create Database.sql and setup/Auto sequence.sql.');
            }
            $productId = trim($_POST['product_id'] ?? '');
            $newStatus = strtoupper(trim($_POST['item_status'] ?? ''));
            if ($productId === '') throw new RuntimeException('Product ID is required.');
            if (!in_array($newStatus, $allowedItemStatuses, true)) throw new RuntimeException('Invalid item status.');

            $parentStatus = od_current_order_status($conn, $orderId);
            if (in_array($parentStatus, ['CANCELLED', 'COLLECTED'], true)) {
                throw new RuntimeException('Items cannot be changed after the full order is ' . $parentStatus . '.');
            }

            $currentItem = db_one($conn, "
                SELECT QUANTITY, UPPER(NVL(ITEM_STATUS, 'PENDING')) AS ITEM_STATUS
                FROM ORDER_ITEM
                WHERE ORDER_ID = :order_id
                  AND PRODUCT_ID = :product_id
            ", [':order_id' => $orderId, ':product_id' => $productId]);

            if (!$currentItem) {
                throw new RuntimeException('Order item was not found.');
            }

            $oldStatus = strtoupper((string)($currentItem['ITEM_STATUS'] ?? 'PENDING'));
            $quantity = (int)($currentItem['QUANTITY'] ?? 0);
            $sendItemCancellationEmail = ($oldStatus !== 'CANCELLED' && $newStatus === 'CANCELLED');

            try {
                od_adjust_stock_for_item_status_change($conn, $productId, $quantity, $oldStatus, $newStatus, OCI_NO_AUTO_COMMIT);
                db_bind_and_execute($conn, '
                    UPDATE ORDER_ITEM
                    SET ITEM_STATUS = :status
                    WHERE ORDER_ID = :order_id
                      AND PRODUCT_ID = :product_id
                ', [':status' => $newStatus, ':order_id' => $orderId, ':product_id' => $productId], OCI_NO_AUTO_COMMIT);

                od_sync_order_status_from_items($conn, $orderId, OCI_NO_AUTO_COMMIT);
                oci_commit($conn);
                if ($sendItemCancellationEmail && function_exists('shoplocalfy_send_order_cancellation_emails')) {
                    shoplocalfy_send_order_cancellation_emails($conn, $orderId, $productId, 'admin_cancelled');
                }
            } catch (Throwable $tx) {
                oci_rollback($conn);
                throw $tx;
            }

            od_redirect($orderId, 'Item status updated.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        od_redirect($orderId, '', shoplocalfy_public_exception_message($e, 'Could not update order.'));
    }
}

$order = null;
$orderItems = [];
$summary = ['total' => 0, 'ready' => 0, 'collected' => 0, 'cancelled' => 0, 'pending' => 0];
try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    if ($orderId === '') throw new RuntimeException('Order ID was not provided.');
    $order = od_order_summary($conn, $orderId);
    if (!$order) throw new RuntimeException('Order was not found.');
    $orderItems = od_order_items($conn, $orderId);
    $summary = od_item_summary($conn, $orderId);
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load order details.');
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
  <title>ShopLocalfy – Order Detail</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/order-detail.css?v=20260517">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <div class="page-body">
      <div class="page-head">
        <div>
          <h1 class="page-title">Order Detail</h1>
          <p class="page-subtitle">Full order information, items, traders, payment, and readiness status.</p>
        </div>
        <a class="back-btn" href="order-management.php"><i class="fa-solid fa-arrow-left"></i> Back to Orders</a>
      </div>

      <?php if ($message): ?><div class="notice ok"><?php echo od_h($message); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice err"><?php echo od_h($error); ?></div><?php endif; ?>

      <?php if (!$order): ?>
        <div class="card"><div class="card-body">No order could be loaded.</div></div>
      <?php else: ?>
        <?php $orderStatusClass = od_status_class($order['ORDER_STATUS'] ?? 'CONFIRMED'); ?>
        <div class="grid">
          <div>
            <div class="card">
              <div class="card-header">
                <div>
                  <div class="card-title">Order #<?php echo od_h($order['ORDER_ID']); ?></div>
                  <div class="item-meta">Placed <?php echo od_h($order['ORDER_DATE_TEXT'] ?? '—'); ?></div>
                </div>
                <span class="status-pill <?php echo od_h($orderStatusClass); ?>"><i class="fa-solid fa-circle"></i><?php echo od_h($order['ORDER_STATUS'] ?? 'CONFIRMED'); ?></span>
              </div>
              <div class="card-body">
                <div class="info-grid">
                  <div class="info-box"><span class="info-label">Customer</span><span class="info-value"><?php echo od_h($order['CUSTOMER_NAME'] ?: $order['CUSTOMER_ID']); ?></span></div>
                  <div class="info-box"><span class="info-label">Email</span><span class="info-value"><?php echo od_h($order['EMAIL_ADDRESS'] ?? '—'); ?></span></div>
                  <div class="info-box"><span class="info-label">Phone</span><span class="info-value"><?php echo od_h($order['PH_NUMBER'] ?? '—'); ?></span></div>
                  <div class="info-box"><span class="info-label">Pickup</span><span class="info-value"><?php echo od_h($order['PICKUP_DATE_TEXT'] ?? '—'); ?><?php if (!empty($order['START_HOUR'])): ?>, <?php echo od_h($order['START_HOUR']); ?>:00–<?php echo od_h($order['END_HOUR']); ?>:00<?php endif; ?></span></div>
                  <div class="info-box"><span class="info-label">Voucher</span><span class="info-value"><?php echo od_h($order['VOUCHER_CODE'] ?? 'None'); ?></span></div>
                  <div class="info-box"><span class="info-label">Discount</span><span class="info-value"><?php echo admin_money($order['DISCOUNT_APPLIED'] ?? 0); ?></span></div>
                  <div class="info-box"><span class="info-label">Payment</span><span class="info-value"><?php echo od_h($order['PAYMENT_STATUS'] ?? 'No payment row'); ?></span></div>
                  <div class="info-box"><span class="info-label">Total</span><span class="info-value"><?php echo admin_money($order['TOTAL_AMOUNT'] ?? 0); ?></span></div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-header">
                <div class="card-title">Order Items</div>
                <span class="status-pill status-processing"><?php echo count($orderItems); ?> item<?php echo count($orderItems) === 1 ? '' : 's'; ?></span>
              </div>
              <div class="card-body">
                <?php if (!od_item_status_enabled($conn)): ?>
                  <div class="warn" style="margin-bottom:14px;">Per-item readiness is not enabled yet. Reset the database using <strong>setup/Create Database.sql</strong> and <strong>setup/Auto sequence.sql</strong> so one trader cannot mark a multi-shop order as READY by themselves.</div>
                <?php endif; ?>
                <?php if (!$orderItems): ?>
                  <p class="item-meta">No order items found.</p>
                <?php else: ?>
                  <?php foreach ($orderItems as $item): ?>
                    <?php
                      $itemProductId = od_row_text($item, 'PRODUCT_ID', '');
                      $itemTraderId = od_row_text($item, 'TRADER_ID', '');
                      $itemProductName = od_row_text($item, 'PRODUCT_NAME', $itemProductId !== '' ? $itemProductId : 'Unknown product');
                      $itemShopName = od_row_text($item, 'SHOP_NAME', '—');
                      $itemTraderName = od_row_text($item, 'TRADER_NAME', $itemTraderId !== '' ? $itemTraderId : '—');
                      $itemQuantity = od_row_text($item, 'QUANTITY', '—');
                      $itemLockedPrice = od_row_value($item, 'LOCKED_PRICE', 0);
                      $itemLineTotal = od_row_value($item, 'LINE_TOTAL', 0);
                      $img = od_image_src(od_row_value($item, 'PRODUCT_IMAGE', ''));
                      $itemStatus = strtoupper((string)od_row_value($item, 'ITEM_STATUS', 'PENDING'));
                    ?>
                    <div class="item-row">
                      <div class="product-img"><img src="<?php echo od_h($img); ?>" alt="" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"></div>
                      <div>
                        <div class="item-name"><?php echo od_h($itemProductName); ?></div>
                        <div class="item-meta">Product ID: <?php echo od_h($itemProductId); ?> | Trader ID: <?php echo od_h($itemTraderId); ?></div>
                        <div class="item-meta">Shop: <?php echo od_h($itemShopName); ?> · Trader: <?php echo od_h($itemTraderName); ?></div>
                      </div>
                      <div class="item-qty"><strong>Qty:</strong> <?php echo od_h($itemQuantity); ?><div class="item-meta">Status: <?php echo od_h($itemStatus); ?></div></div>
                      <div class="item-money"><?php echo admin_money($itemLineTotal); ?><div class="item-meta">Locked: <?php echo admin_money($itemLockedPrice); ?></div></div>
                      <div class="item-actions">
                        <?php if (od_item_status_enabled($conn)): ?>
                          <form class="form-inline" method="post">
                            <input type="hidden" name="action" value="update_item_status">
                            <input type="hidden" name="order_id" value="<?php echo od_h($order['ORDER_ID']); ?>">
                            <input type="hidden" name="product_id" value="<?php echo od_h($itemProductId); ?>">
                            <select name="item_status">
                              <?php foreach ($allowedItemStatuses as $statusOption): ?>
                                <option value="<?php echo od_h($statusOption); ?>" <?php echo $itemStatus === $statusOption ? 'selected' : ''; ?>><?php echo od_h(ucfirst(strtolower($statusOption))); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">Save</button>
                          </form>
                        <?php else: ?>
                          <span class="status-pill <?php echo od_h(od_status_class($order['ORDER_STATUS'] ?? 'CONFIRMED')); ?>"><?php echo od_h($order['ORDER_STATUS'] ?? 'CONFIRMED'); ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <aside>
            <div class="card">
              <div class="card-header"><div class="card-title">Update Order Status</div></div>
              <div class="card-body">
                <form method="post" style="display:flex;flex-direction:column;gap:12px;">
                  <input type="hidden" name="action" value="update_order_status">
                  <input type="hidden" name="order_id" value="<?php echo od_h($order['ORDER_ID']); ?>">
                  <select name="order_status">
                    <?php foreach ($allowedOrderStatuses as $statusOption): ?>
                      <option value="<?php echo od_h($statusOption); ?>" <?php echo strtoupper((string)$order['ORDER_STATUS']) === $statusOption ? 'selected' : ''; ?>><?php echo od_h(ucfirst(strtolower($statusOption))); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary" type="submit"><i class="fa-solid fa-pen-to-square"></i> Update Status</button>
                </form>
                <p class="item-meta" style="margin-top:12px;">READY is blocked until every item is marked READY.</p>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><div class="card-title">Item Readiness</div></div>
              <div class="card-body">
                <div class="summary-strip">
                  <div class="sum-box"><div class="sum-value"><?php echo (int)$summary['total']; ?></div><div class="sum-label">Total</div></div>
                  <div class="sum-box"><div class="sum-value"><?php echo (int)$summary['pending']; ?></div><div class="sum-label">Pending</div></div>
                  <div class="sum-box"><div class="sum-value"><?php echo (int)$summary['ready']; ?></div><div class="sum-label">Ready</div></div>
                  <div class="sum-box"><div class="sum-value"><?php echo (int)$summary['collected']; ?></div><div class="sum-label">Collected</div></div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><div class="card-title">Payment Details</div></div>
              <div class="card-body">
                <div class="info-grid" style="grid-template-columns:1fr;">
                  <div class="info-box"><span class="info-label">Payment ID</span><span class="info-value"><?php echo od_h($order['PAYMENT_ID'] ?? '—'); ?></span></div>
                  <div class="info-box"><span class="info-label">Method</span><span class="info-value"><?php echo od_h($order['PAYMENT_METHOD'] ?? '—'); ?></span></div>
                  <div class="info-box"><span class="info-label">Amount Paid</span><span class="info-value"><?php echo admin_money($order['AMOUNT_PAID'] ?? 0); ?></span></div>
                  <div class="info-box"><span class="info-label">Paid At</span><span class="info-value"><?php echo od_h($order['PAYMENT_DATE_TEXT'] ?? '—'); ?></span></div>
                </div>
              </div>
            </div>
          </aside>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
<script src="../assets/admin/js/order-detail.js?v=20260517"></script>
</body>
</html>

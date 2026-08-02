<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);
$errors = [];

$customerId = trim((string)($_GET['customer_id'] ?? ''));

function local_column_exists($conn, $tableName, $columnName) {
    if (!$conn) return false;

    try {
        $rows = db_all($conn, '
            SELECT COUNT(*) AS CNT
            FROM USER_TAB_COLUMNS
            WHERE TABLE_NAME = UPPER(:table_name)
              AND COLUMN_NAME = UPPER(:column_name)
        ', [
            ':table_name' => $tableName,
            ':column_name' => $columnName
        ]);

        return (int)($rows[0]['CNT'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function get_customer_for_trader($conn, $traderId, $customerId, &$errors) {
    if ($customerId === '') {
        return null;
    }

    if (!$conn || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'USER')) {
        $errors[] = 'Required order/customer tables were not found.';
        return null;
    }

    try {
        $rows = db_all($conn, '
            SELECT
                u.USER_ID AS CUSTOMER_ID,
                TRIM(NVL(u.FIRST_NAME, \'\') || \' \' || NVL(u.LAST_NAME, \'\')) AS CUSTOMER_NAME,
                u.EMAIL_ADDRESS,
                u.PH_NUMBER,
                TO_CHAR(MIN(o.ORDER_DATE), \'DD Mon YYYY\') AS FIRST_ORDER_LABEL,
                TO_CHAR(MAX(o.ORDER_DATE), \'DD Mon YYYY\') AS LAST_ORDER_LABEL,
                COUNT(DISTINCT o.ORDER_ID) AS ORDERS_COUNT,
                NVL(SUM(NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0)), 0) AS TOTAL_SPENT
            FROM "USER" u
            INNER JOIN ORDERS o ON o.CUSTOMER_ID = u.USER_ID
            INNER JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
              AND u.USER_ID = :customer_id
            GROUP BY u.USER_ID, u.FIRST_NAME, u.LAST_NAME, u.EMAIL_ADDRESS, u.PH_NUMBER
        ', [
            ':trader_id' => $traderId,
            ':customer_id' => $customerId
        ]);

        return $rows[0] ?? null;
    } catch (Throwable $e) {
        $errors[] = 'Customer lookup failed: ' . shoplocalfy_public_exception_message($e, 'Could not load customer.');
        return null;
    }
}

function get_customer_orders_for_trader($conn, $traderId, $customerId, &$errors) {
    if ($customerId === '') {
        $errors[] = 'Missing customer id.';
        return [];
    }

    if (!$conn || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'ORDER_ITEM')) {
        $errors[] = 'Required order tables were not found.';
        return [];
    }

    $hasOrderDate = local_column_exists($conn, 'ORDERS', 'ORDER_DATE');
    $statusColumn = local_column_exists($conn, 'ORDERS', 'ORDER_STATUS') ? 'ORDER_STATUS' : (local_column_exists($conn, 'ORDERS', 'STATUS') ? 'STATUS' : null);

    $dateSelect = $hasOrderDate ? "TO_CHAR(MIN(o.ORDER_DATE), 'DD Mon YYYY')" : "'—'";
    $sortSql = $hasOrderDate ? 'MIN(o.ORDER_DATE) DESC NULLS LAST, o.ORDER_ID DESC' : 'o.ORDER_ID DESC';
    $statusSelect = $statusColumn ? "NVL(o.$statusColumn, '—')" : "'—'";
    $groupSql = $statusColumn ? "GROUP BY o.ORDER_ID, NVL(o.$statusColumn, '—')" : 'GROUP BY o.ORDER_ID';

    try {
        return db_all($conn, "
            SELECT
                o.ORDER_ID,
                $dateSelect AS ORDER_DATE_LABEL,
                $statusSelect AS ORDER_STATUS,
                COUNT(*) AS ITEM_COUNT,
                NVL(SUM(NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0)), 0) AS ORDER_TOTAL
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
              AND o.CUSTOMER_ID = :customer_id
            $groupSql
            ORDER BY $sortSql
        ", [
            ':trader_id' => $traderId,
            ':customer_id' => $customerId
        ]);
    } catch (Throwable $e) {
        $errors[] = 'Order summary query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load order summary.');
        return [];
    }
}

function get_customer_order_items_for_trader($conn, $traderId, $customerId, &$errors) {
    if ($customerId === '') {
        return [];
    }

    if (!$conn || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'ORDER_ITEM')) {
        return [];
    }

    $hasOrderDate = local_column_exists($conn, 'ORDERS', 'ORDER_DATE');
    $statusColumn = local_column_exists($conn, 'ORDERS', 'ORDER_STATUS') ? 'ORDER_STATUS' : (local_column_exists($conn, 'ORDERS', 'STATUS') ? 'STATUS' : null);
    $hasProductTable = table_exists($conn, 'PRODUCT');
    $hasProductName = $hasProductTable && local_column_exists($conn, 'PRODUCT', 'PRODUCT_NAME');

    $productJoin = $hasProductTable ? 'LEFT JOIN PRODUCT p ON p.PRODUCT_ID = oi.PRODUCT_ID' : '';
    $productNameExpr = $hasProductName ? "NVL(p.PRODUCT_NAME, 'Product #' || oi.PRODUCT_ID)" : "'Product #' || oi.PRODUCT_ID";
    $dateSelect = $hasOrderDate ? "TO_CHAR(o.ORDER_DATE, 'DD Mon YYYY')" : "'—'";
    $statusSelect = $statusColumn ? "NVL(o.$statusColumn, '—')" : "'—'";
    $sortSql = $hasOrderDate ? 'o.ORDER_DATE DESC NULLS LAST, o.ORDER_ID DESC, PRODUCT_NAME' : 'o.ORDER_ID DESC, PRODUCT_NAME';

    try {
        return db_all($conn, "
            SELECT
                o.ORDER_ID,
                $dateSelect AS ORDER_DATE_LABEL,
                $statusSelect AS ORDER_STATUS,
                oi.PRODUCT_ID,
                $productNameExpr AS PRODUCT_NAME,
                NVL(oi.QUANTITY, 0) AS QUANTITY,
                NVL(oi.LOCKED_PRICE, 0) AS UNIT_PRICE,
                NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0) AS LINE_TOTAL
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            $productJoin
            WHERE oi.TRADER_ID = :trader_id
              AND o.CUSTOMER_ID = :customer_id
            ORDER BY $sortSql
        ", [
            ':trader_id' => $traderId,
            ':customer_id' => $customerId
        ]);
    } catch (Throwable $e) {
        $errors[] = 'Order item query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load order items.');
        return [];
    }
}

$customer = get_customer_for_trader($conn, $traderId, $customerId, $errors);
$orders = $customer ? get_customer_orders_for_trader($conn, $traderId, $customerId, $errors) : [];
$orderItems = $customer ? get_customer_order_items_for_trader($conn, $traderId, $customerId, $errors) : [];

$itemsByOrder = [];
foreach ($orderItems as $item) {
    $oid = (string)($item['ORDER_ID'] ?? '');
    if (!isset($itemsByOrder[$oid])) {
        $itemsByOrder[$oid] = [];
    }
    $itemsByOrder[$oid][] = $item;
}

$customerName = trim($customer['CUSTOMER_NAME'] ?? '') ?: 'Customer';
$totalOrders = (int)($customer['ORDERS_COUNT'] ?? count($orders));
$totalSpent = (float)($customer['TOTAL_SPENT'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy — Customer Orders</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/customer_all_order.css?v=20260517">
</head>
<body>
<?php $active = 'customers'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Customer Orders', 'Orders placed by a customer for your shop'); ?>
  <div class="body">
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="page-header">
      <div class="page-header-left">
        <h1>📦 Customer Orders</h1>
        <p>Showing orders from <?php echo e($profile['SHOP_NAME'] ?? 'your shop'); ?></p>
      </div>
      <a class="back-btn" href="customer.php"><span>←</span> Back to customers</a>
    </div>

    <?php if (!$customer): ?>
      <div class="empty-order"><div class="ico">⚠️</div><p>No matching customer was found for this trader.</p></div>
    <?php else: ?>
      <div class="customer-card">
        <div class="customer-main">
          <div class="avatar"><?php echo e(initials_from_name($customerName)); ?></div>
          <div>
            <div class="cust-name"><?php echo e($customerName); ?></div>
            <div class="cust-sub">Customer ID: <?php echo e($customer['CUSTOMER_ID']); ?></div>
          </div>
        </div>
        <div><div class="info-label">Email</div><div class="info-value"><?php echo e($customer['EMAIL_ADDRESS'] ?: '—'); ?></div></div>
        <div><div class="info-label">Phone</div><div class="info-value"><?php echo e($customer['PH_NUMBER'] ?: '—'); ?></div></div>
      </div>

      <div class="summary-strip">
        <div class="sum-card"><div class="sum-ico" style="background:rgba(29,158,117,.12)">📦</div><div><div class="sum-val"><?php echo int_fmt($totalOrders); ?></div><div class="sum-lbl">Total Orders</div></div></div>
        <div class="sum-card"><div class="sum-ico" style="background:rgba(61,191,164,.12)">💰</div><div><div class="sum-val"><?php echo money_fmt($totalSpent); ?></div><div class="sum-lbl">Total Spent</div></div></div>
        <div class="sum-card"><div class="sum-ico" style="background:rgba(244,124,90,.12)">🗓️</div><div><div class="sum-val"><?php echo e($customer['LAST_ORDER_LABEL'] ?: '—'); ?></div><div class="sum-lbl">Last Order</div></div></div>
      </div>

      <?php if (!$orders): ?>
        <div class="empty-order"><div class="ico">📦</div><p>This customer has no orders containing your products.</p></div>
      <?php else: ?>
        <div class="orders-wrap">
          <?php foreach ($orders as $order):
            $orderId = (string)($order['ORDER_ID'] ?? '');
            $lines = $itemsByOrder[$orderId] ?? [];
          ?>
            <div class="order-card">
              <div class="order-head">
                <div>
                  <div class="order-title">Order #<?php echo e($orderId); ?></div>
                  <div class="order-meta"><?php echo e($order['ORDER_DATE_LABEL'] ?? '—'); ?> · <?php echo int_fmt($order['ITEM_COUNT'] ?? count($lines)); ?> item<?php echo ((int)($order['ITEM_COUNT'] ?? count($lines)) === 1) ? '' : 's'; ?></div>
                </div>
                <span class="status-pill"><?php echo e($order['ORDER_STATUS'] ?: '—'); ?></span>
                <div class="order-total"><div class="amount"><?php echo money_fmt($order['ORDER_TOTAL'] ?? 0); ?></div><div class="label">Trader total</div></div>
              </div>

              <div style="overflow-x:auto">
                <table class="items-table">
                  <thead><tr><th style="width:42%">Product</th><th style="width:16%">Product ID</th><th style="width:14%">Quantity</th><th style="width:14%">Unit Price</th><th style="width:14%">Line Total</th></tr></thead>
                  <tbody>
                    <?php if (!$lines): ?>
                      <tr><td colspan="5">No item details found for this order.</td></tr>
                    <?php else: ?>
                      <?php foreach ($lines as $item): ?>
                        <tr>
                          <td><span class="product-name"><?php echo e($item['PRODUCT_NAME'] ?: 'Product'); ?></span></td>
                          <td><?php echo e($item['PRODUCT_ID'] ?? '—'); ?></td>
                          <td><?php echo int_fmt($item['QUANTITY'] ?? 0); ?></td>
                          <td><?php echo money_fmt($item['UNIT_PRICE'] ?? 0); ?></td>
                          <td><span class="amount-cell"><?php echo money_fmt($item['LINE_TOTAL'] ?? 0); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

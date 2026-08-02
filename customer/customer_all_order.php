<?php
require_once __DIR__ . '/customer_common.php';
require_customer_login();

$customerId = current_customer_id();
$pageError = '';
$orders = [];

function order_money($value) {
    return '£' . number_format((float)$value, 2);
}

function order_status_class($status) {
    $status = strtoupper((string)$status);
    return match ($status) {
        'COLLECTED', 'COMPLETED', 'PAID', 'VERIFIED', 'APPROVED' => 'done',
        'CONFIRMED', 'READY' => 'proc',
        'SHIPPED', 'OUT_FOR_DELIVERY' => 'ship',
        'CANCELLED', 'REJECTED' => 'bad',
        default => 'pend',
    };
}

function order_first_col($table, array $columns) {
    global $conn;
    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return strtoupper($column);
        }
    }
    return null;
}

function order_image_select() {
    $column = order_first_col('PRODUCT', ['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_PATH', 'PRODUCT_IMAGE_PATH', 'PRODUCT_PHOTO', 'PHOTO', 'PICTURE', 'PRODUCT_PICTURE']);
    return $column ? 'p.' . $column . ' AS PRODUCT_IMAGE' : 'NULL AS PRODUCT_IMAGE';
}

function load_customer_orders($customerId) {
    global $conn;

    if (!$conn || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'ORDER_ITEM')) {
        throw new RuntimeException('ORDERS or ORDER_ITEM table was not found.');
    }

    $orderDateCol = order_first_col('ORDERS', ['ORDER_DATE', 'CREATED_DATE', 'DATE_CREATED', 'CREATED_AT', 'PLACED_DATE', 'PLACED_AT']);
    $orderDateSelect = $orderDateCol ? "TO_CHAR(o.$orderDateCol, 'YYYY-MM-DD') AS ORDER_DATE" : "'' AS ORDER_DATE";
    $orderDateOrder = $orderDateCol ? "o.$orderDateCol DESC NULLS LAST," : '';

    $pickupDateCol = order_first_col('ORDERS', ['PICKUP_DATE', 'COLLECTION_DATE']);
    $pickupDateSelect = $pickupDateCol ? "TO_CHAR(o.$pickupDateCol, 'YYYY-MM-DD') AS PICKUP_DATE" : "'' AS PICKUP_DATE";

    $orderStatusSelect = column_exists($conn, 'ORDERS', 'ORDER_STATUS') ? 'o.ORDER_STATUS' : "'CONFIRMED'";
    $totalSelect = column_exists($conn, 'ORDERS', 'TOTAL_AMOUNT') ? 'NVL(o.TOTAL_AMOUNT, 0)' : '0';
    $discountSelect = column_exists($conn, 'ORDERS', 'DISCOUNT_APPLIED') ? 'NVL(o.DISCOUNT_APPLIED, 0)' : '0';
    $itemStatusSelect = column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS') ? 'oi.ITEM_STATUS' : $orderStatusSelect;
    $lockedPriceSelect = column_exists($conn, 'ORDER_ITEM', 'LOCKED_PRICE') ? 'NVL(oi.LOCKED_PRICE, NVL(p.ITEM_PRICE, 0))' : 'NVL(p.ITEM_PRICE, 0)';
    $imageSelect = order_image_select();

    $paymentJoin = '';
    $paymentSelect = "'—' AS PAYMENT_STATUS, '—' AS PAYMENT_METHOD";
    if (table_exists($conn, 'PAYMENT')) {
        $paymentStatusCol = order_first_col('PAYMENT', ['PAYMENT_STATUS', 'STATUS']);
        $paymentMethodCol = order_first_col('PAYMENT', ['PAYMENT_METHOD', 'METHOD']);
        $paymentStatusSelect = $paymentStatusCol ? "pay.$paymentStatusCol" : "'—'";
        $paymentMethodSelect = $paymentMethodCol ? "pay.$paymentMethodCol" : "'—'";
        $paymentSelect = "$paymentStatusSelect AS PAYMENT_STATUS, $paymentMethodSelect AS PAYMENT_METHOD";
        $paymentJoin = 'LEFT JOIN PAYMENT pay ON pay.ORDER_ID = o.ORDER_ID';
    }

    $rows = db_all($conn, "
        SELECT
            o.ORDER_ID,
            $orderDateSelect,
            $pickupDateSelect,
            $orderStatusSelect AS ORDER_STATUS,
            $totalSelect AS TOTAL_AMOUNT,
            $discountSelect AS DISCOUNT_APPLIED,
            $paymentSelect,
            oi.PRODUCT_ID,
            NVL(oi.QUANTITY, 1) AS QUANTITY,
            $lockedPriceSelect AS LOCKED_PRICE,
            $itemStatusSelect AS ITEM_STATUS,
            NVL(p.PRODUCT_NAME, oi.PRODUCT_ID) AS PRODUCT_NAME,
            $imageSelect,
            NVL(s.SHOP_NAME, 'Unknown shop') AS SHOP_NAME
        FROM ORDERS o
        JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
        LEFT JOIN PRODUCT p ON p.PRODUCT_ID = oi.PRODUCT_ID
        LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        $paymentJoin
        WHERE o.CUSTOMER_ID = :customer_id
        ORDER BY $orderDateOrder o.ORDER_ID DESC, p.PRODUCT_NAME
    ", [':customer_id' => $customerId]);

    $grouped = [];
    foreach ($rows as $row) {
        $orderId = (string)($row['ORDER_ID'] ?? '');
        if ($orderId === '') {
            continue;
        }

        if (!isset($grouped[$orderId])) {
            $grouped[$orderId] = [
                'order_id' => $orderId,
                'order_date' => $row['ORDER_DATE'] ?? '',
                'pickup_date' => $row['PICKUP_DATE'] ?? '',
                'order_status' => $row['ORDER_STATUS'] ?? 'CONFIRMED',
                'payment_status' => $row['PAYMENT_STATUS'] ?? '—',
                'payment_method' => $row['PAYMENT_METHOD'] ?? '—',
                'discount' => (float)($row['DISCOUNT_APPLIED'] ?? 0),
                'total' => (float)($row['TOTAL_AMOUNT'] ?? 0),
                'items' => [],
            ];
        }

        $grouped[$orderId]['items'][] = [
            'product_id' => $row['PRODUCT_ID'] ?? '',
            'name' => $row['PRODUCT_NAME'] ?? 'Product',
            'shop' => $row['SHOP_NAME'] ?? 'Unknown shop',
            'quantity' => (int)($row['QUANTITY'] ?? 1),
            'locked_price' => (float)($row['LOCKED_PRICE'] ?? 0),
            'item_status' => $row['ITEM_STATUS'] ?? $row['ORDER_STATUS'] ?? 'PENDING',
            'image' => product_image_src($row['PRODUCT_IMAGE'] ?? ''),
        ];
    }

    return array_values($grouped);
}

try {
    $orders = load_customer_orders($customerId);
} catch (Throwable $e) {
    $pageError = shoplocalfy_public_exception_message($e, 'Could not load orders.');
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
  <title>My Orders - ShopLocalfy</title>
  <link rel="stylesheet" href="../assets/customer/css/customer_all_order.css?v=20260517">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="page">
  <section class="hero">
    <div>
      <div class="eyebrow">Customer account</div>
      <h1>My Orders</h1>
      <p>All orders placed from your customer account are listed here.</p>
    </div>
    <a class="btn" href="index.php#productGrid">Continue shopping →</a>
  </section>

  <?php if ($pageError !== ''): ?>
    <div class="alert"><?php echo e($pageError); ?></div>
  <?php elseif (empty($orders)): ?>
    <section class="empty">
      <h2>No orders yet</h2>
      <p>When you place an order, it will appear here with product, payment, and collection details.</p>
    </section>
  <?php else: ?>
    <section class="orders">
      <?php foreach ($orders as $order): ?>
        <?php $status = strtoupper((string)$order['order_status']); ?>
        <article class="order-card">
          <header class="order-head">
            <div>
              <div class="order-id">Order <?php echo e($order['order_id']); ?></div>
              <div class="meta">
                <?php if ($order['order_date'] !== ''): ?><span>Placed: <?php echo e($order['order_date']); ?></span><?php endif; ?>
                <?php if ($order['pickup_date'] !== ''): ?><span>Pickup: <?php echo e($order['pickup_date']); ?></span><?php endif; ?>
                <span>Payment: <?php echo e($order['payment_status']); ?></span>
              </div>
            </div>
            <div class="summary">
              <span class="pill <?php echo e(order_status_class($status)); ?>"><?php echo e($status); ?></span>
              <strong><?php echo e(order_money($order['total'])); ?></strong>
            </div>
          </header>

          <div class="items">
            <?php foreach ($order['items'] as $item): ?>
              <?php $lineTotal = $item['locked_price'] * max(1, (int)$item['quantity']); ?>
              <div class="item">
                <div class="thumb"><img src="<?php echo e($item['image']); ?>" alt="<?php echo e($item['name']); ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"></div>
                <div>
                  <div class="item-name"><?php echo e($item['name']); ?></div>
                  <div class="item-sub"><?php echo e($item['shop']); ?> · Qty <?php echo e($item['quantity']); ?> · <?php echo e(order_money($item['locked_price'])); ?> each</div>
                  <div class="item-sub">Item status: <?php echo e(strtoupper((string)$item['item_status'])); ?></div>
                </div>
                <div class="line"><?php echo e(order_money($lineTotal)); ?><small><?php echo e($item['product_id']); ?></small></div>
              </div>
            <?php endforeach; ?>
          </div>

          <footer class="order-foot">
            <span>Discount applied: <?php echo e(order_money($order['discount'])); ?></span>
            <span>Payment method: <?php echo e($order['payment_method']); ?></span>
          </footer>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

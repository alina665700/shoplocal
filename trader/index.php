<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$errors = [];

function dashboard_stats($conn, $traderId, &$errors) {
    $stats = ['REVENUE' => 0, 'PRODUCTS_SOLD' => 0, 'CUSTOMERS' => 0, 'ACTIVE_ORDERS' => 0];
    if (!$conn || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS')) return $stats;
    try {
        $row = db_one($conn, '
            SELECT
                NVL(SUM(CASE WHEN o.ORDER_STATUS <> \'CANCELLED\' THEN oi.QUANTITY * oi.LOCKED_PRICE ELSE 0 END), 0) * 0.92 AS REVENUE,
                NVL(SUM(CASE WHEN o.ORDER_STATUS <> \'CANCELLED\' THEN oi.QUANTITY ELSE 0 END), 0) AS PRODUCTS_SOLD,
                COUNT(DISTINCT CASE WHEN o.ORDER_STATUS <> \'CANCELLED\' THEN o.CUSTOMER_ID END) AS CUSTOMERS,
                COUNT(DISTINCT CASE WHEN o.ORDER_STATUS IN (\'CONFIRMED\', \'READY\') THEN o.ORDER_ID END) AS ACTIVE_ORDERS
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
        ', [':trader_id' => $traderId]);
        return array_merge($stats, $row ?: []);
    } catch (Throwable $e) {
        $errors[] = 'Dashboard stats query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load dashboard stats.');
        return $stats;
    }
}

function sales_last_7_days($conn, $traderId, &$errors) {
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i days");
        $days[date('Y-m-d', $ts)] = ['label' => date('D', $ts), 'orders' => 0];
    }
    if (!$conn || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS')) return $days;
    try {
        $rows = db_all($conn, '
            SELECT TO_CHAR(TRUNC(o.ORDER_DATE), \'YYYY-MM-DD\') AS ORDER_DAY,
                   COUNT(DISTINCT o.ORDER_ID) AS TOTAL_ORDERS
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
              AND o.ORDER_DATE >= TRUNC(SYSDATE) - 6
              AND o.ORDER_STATUS <> \'CANCELLED\'
            GROUP BY TRUNC(o.ORDER_DATE)
            ORDER BY TRUNC(o.ORDER_DATE)
        ', [':trader_id' => $traderId]);
        foreach ($rows as $row) {
            $key = $row['ORDER_DAY'];
            if (isset($days[$key])) $days[$key]['orders'] = (int)$row['TOTAL_ORDERS'];
        }
    } catch (Throwable $e) {
        $errors[] = 'Sales chart query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load sales chart.');
    }
    return $days;
}

function recent_orders($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS')) return [];
    try {
        return db_all($conn, '
            SELECT
                o.ORDER_ID,
                (u.FIRST_NAME || \' \' || u.LAST_NAME) AS CUSTOMER_NAME,
                o.ORDER_STATUS,
                NVL(SUM(oi.QUANTITY * oi.LOCKED_PRICE), 0) * 0.92 AS TRADER_AMOUNT,
                MAX(o.ORDER_DATE) AS LAST_ORDER_DATE
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            LEFT JOIN "USER" u ON u.USER_ID = o.CUSTOMER_ID
            WHERE oi.TRADER_ID = :trader_id
            GROUP BY o.ORDER_ID, u.FIRST_NAME, u.LAST_NAME, o.ORDER_STATUS
            ORDER BY MAX(o.ORDER_DATE) DESC
            FETCH FIRST 6 ROWS ONLY
        ', [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        $errors[] = 'Recent orders query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load recent orders.');
        return [];
    }
}

function top_products($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'PRODUCT') || !table_exists($conn, 'SHOP')) return [];
    try {
        return db_all($conn, '
            SELECT
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                p.ITEM_PRICE,
                p.STOCK_AVAILABLE,
                NVL(SUM(CASE WHEN o.ORDER_ID IS NOT NULL THEN oi.QUANTITY ELSE 0 END), 0) AS SOLD
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            LEFT JOIN ORDER_ITEM oi ON oi.PRODUCT_ID = p.PRODUCT_ID AND oi.TRADER_ID = :trader_id
            LEFT JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
                              AND o.ORDER_DATE >= TRUNC(SYSDATE) - 6
                              AND o.ORDER_STATUS <> \'CANCELLED\'
            WHERE s.TRADER_ID = :trader_id
            GROUP BY p.PRODUCT_ID, p.PRODUCT_NAME, p.ITEM_PRICE, p.STOCK_AVAILABLE
            ORDER BY SOLD DESC, p.PRODUCT_NAME
            FETCH FIRST 5 ROWS ONLY
        ', [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        $errors[] = 'Top products query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load top products.');
        return [];
    }
}

function category_split($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'PRODUCT')) return [];
    try {
        $rows = db_all($conn, '
            SELECT
                NVL(c.CATEGORY_NAME, \'Uncategorised\') AS CATEGORY_NAME,
                NVL(SUM(oi.QUANTITY), 0) AS SOLD
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            INNER JOIN PRODUCT p ON p.PRODUCT_ID = oi.PRODUCT_ID
            LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID
            WHERE oi.TRADER_ID = :trader_id
              AND o.ORDER_STATUS <> \'CANCELLED\'
            GROUP BY NVL(c.CATEGORY_NAME, \'Uncategorised\')
            ORDER BY SOLD DESC
            FETCH FIRST 4 ROWS ONLY
        ', [':trader_id' => $traderId]);
        $total = array_sum(array_map(fn($r) => (int)$r['SOLD'], $rows));
        foreach ($rows as &$r) {
            $r['PCT'] = $total > 0 ? round(((int)$r['SOLD'] / $total) * 100) : 0;
        }
        return $rows;
    } catch (Throwable $e) {
        $errors[] = 'Category split query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load category chart.');
        return [];
    }
}

$pendingCount = get_pending_order_count($conn, $traderId);
$stats = dashboard_stats($conn, $traderId, $errors);
$salesDays = sales_last_7_days($conn, $traderId, $errors);
$recentOrders = recent_orders($conn, $traderId, $errors);
$topProducts = top_products($conn, $traderId, $errors);
$categories = category_split($conn, $traderId, $errors);
$orderValues = array_values(array_map(fn($d) => (int)$d['orders'], $salesDays));
$maxOrders = max(array_merge([1], $orderValues));
$ordersTotal7 = array_sum(array_map(fn($d) => (int)$d['orders'], $salesDays));
$todayLabel = date('D, d M Y');
$displayName = $profile['FULL_NAME'] ?: 'Trader Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy — Trader Dashboard</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/index.css?v=20260519-fee-ui">
</head>
<body>
<?php $active = 'dashboard'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Dashboard', 'Welcome back, ' . $displayName); ?>
  <div class="body">
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="greet">
      <div class="greet-text">
        <h2>Good morning, <?php echo e($displayName); ?>!</h2>
        <p> You have <strong><?php echo int_fmt($pendingCount); ?> pending orders</strong> waiting for review.</p>
      </div>
      <div class="greet-date"><?php echo e($todayLabel); ?></div>
    </div>

    <div class="stats">
      <div class="stat"><div class="stat-head"><div class="stat-ico">⚡</div><div class="stat-trend">After 8% fee</div></div><div class="stat-val"><?php echo money_fmt($stats['REVENUE'] ?? 0); ?></div><div class="stat-lbl">Trader revenue</div></div>
      <div class="stat"><div class="stat-head"><div class="stat-ico">👕</div><div class="stat-trend">Live</div></div><div class="stat-val"><?php echo int_fmt($stats['PRODUCTS_SOLD'] ?? 0); ?></div><div class="stat-lbl">Products Sold</div></div>
      <div class="stat"><div class="stat-head"><div class="stat-ico">🏡</div><div class="stat-trend">Live</div></div><div class="stat-val"><?php echo int_fmt($stats['CUSTOMERS'] ?? 0); ?></div><div class="stat-lbl">Customers</div></div>
      <div class="stat"><div class="stat-head"><div class="stat-ico">📦</div><div class="stat-trend">Live</div></div><div class="stat-val"><?php echo int_fmt($stats['ACTIVE_ORDERS'] ?? 0); ?></div><div class="stat-lbl">Active Orders</div></div>
    </div>

    <div class="actions">
      <a href="products.php" class="action-btn"><div class="action-lbl">Add Product</div><div class="action-sub">New listing</div></a>
      <a href="orders.php" class="action-btn"><div class="action-lbl">View Orders</div><div class="action-sub">Customer orders</div></a>
      <a href="warehouse.php" class="action-btn"><div class="action-lbl">Warehouse</div><div class="action-sub">Stock control</div></a>
      <a href="analytics.php" class="action-btn"><div class="action-lbl">Analytics</div><div class="action-sub">Live charts</div></a>
    </div>

    <div class="charts">
      <div class="card">
        <div class="card-head"><div><div class="card-title">Sales — Last 7 Days</div><div class="card-sub">Total: <strong class="dash-accent-text"><?php echo int_fmt($ordersTotal7); ?> orders</strong></div></div><a href="analytics.php" class="view-link">View all stats →</a></div>
        <div class="bar-chart">
          <?php foreach ($salesDays as $day): $ordersForDay = (int)$day['orders']; $h = $ordersForDay > 0 ? max(22, round(($ordersForDay / $maxOrders) * 120)) : 5; ?>
            <div class="bar-col"><div class="bar" data-v="<?php echo e($day['orders']); ?>" style="height:<?php echo e($h); ?>px"></div><span class="bar-day"><?php echo e($day['label']); ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div class="card-title">Category Split</div></div>
        <?php if (!$categories): ?>
          <div class="empty-state" style="padding:35px 10px"><div class="empty-ico">📊</div><p>No category sales yet.</p></div>
        <?php else: ?>
          <div class="donut-wrap"><div class="donut-leg">
            <?php $colors = ['#3DBFA4','#1D9E75','#A8EDD8','#F47C5A']; foreach ($categories as $i => $cat): ?>
              <div class="leg-row"><div class="leg-dot" style="background:<?php echo e($colors[$i] ?? '#3DBFA4'); ?>"></div><span class="leg-name"><?php echo e($cat['CATEGORY_NAME']); ?></span><span class="leg-pct"><?php echo e($cat['PCT']); ?>%</span></div>
            <?php endforeach; ?>
          </div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="bottom">
      <div class="card">
        <div class="card-head"><div><div class="card-title">Recent Orders</div><div class="card-sub"><?php echo count($recentOrders); ?> latest transactions. Payout shown after 8% platform fee.</div></div><a href="orders.php" class="view-link">View all →</a></div>
        <?php if (!$recentOrders): ?>
          <div class="empty-state"><div class="empty-ico">📦</div><p>No orders yet.</p></div>
        <?php else: ?>
          <table class="otable"><thead><tr><th>Order</th><th>Customer</th><th>Payout</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($recentOrders as $order): ?>
            <tr><td><span class="oid"><?php echo e($order['ORDER_ID']); ?></span></td><td><?php echo e($order['CUSTOMER_NAME'] ?: 'Customer'); ?></td><td class="oamt"><?php echo money_fmt($order['TRADER_AMOUNT']); ?></td><td><span class="pill <?php echo e(status_class($order['ORDER_STATUS'])); ?>"><?php echo e(ucwords(strtolower($order['ORDER_STATUS']))); ?></span></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-head"><div><div class="card-title">Top Products</div><div class="card-sub">By units sold this week</div></div><a href="warehouse.php" class="view-link">View stock →</a></div>
        <?php if (!$topProducts): ?>
          <div class="empty-state"><div class="empty-ico">🏷</div><p>No product sales yet.</p></div>
        <?php else: ?>
          <?php $soldValues = array_values(array_map(fn($p) => (int)$p['SOLD'], $topProducts)); $maxSold = max(array_merge([1], $soldValues)); foreach ($topProducts as $i => $p): $rank=$i+1; $pct=round(((int)$p['SOLD']/$maxSold)*100); ?>
            <div class="prod-item"><div class="prod-rank <?php echo $rank <= 3 ? 'g' . $rank : ''; ?>">#<?php echo e($rank); ?></div><div class="prod-inf"><div class="prod-name"><?php echo e($p['PRODUCT_NAME']); ?></div><div class="prod-meta"><?php echo int_fmt($p['SOLD']); ?> sold · <?php echo int_fmt($p['STOCK_AVAILABLE']); ?> in stock</div><div class="prod-bar-wrap"><div class="prod-bar" style="width:<?php echo e($pct); ?>%"></div></div></div><div class="prod-price"><?php echo money_fmt($p['ITEM_PRICE']); ?></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="../assets/trader/js/index.js?v=20260517"></script>
</body>
</html>


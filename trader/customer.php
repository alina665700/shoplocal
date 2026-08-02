<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);
$errors = [];

function get_trader_customers($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'ORDERS') || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'USER')) {
        return [];
    }

    try {
        return db_all($conn, '
            SELECT
                u.USER_ID AS CUSTOMER_ID,
                TRIM(NVL(u.FIRST_NAME, \'\') || \' \' || NVL(u.LAST_NAME, \'\')) AS CUSTOMER_NAME,
                u.EMAIL_ADDRESS,
                u.PH_NUMBER,
                TO_CHAR(MIN(o.ORDER_DATE), \'Mon YYYY\') AS JOINED_LABEL,
                TO_CHAR(MAX(o.ORDER_DATE), \'DD Mon YYYY\') AS LAST_ORDER_LABEL,
                COUNT(DISTINCT o.ORDER_ID) AS ORDERS_COUNT,
                NVL(SUM(NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0)), 0) AS TOTAL_SPENT
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            INNER JOIN "USER" u ON u.USER_ID = o.CUSTOMER_ID
            WHERE oi.TRADER_ID = :trader_id
            GROUP BY u.USER_ID, u.FIRST_NAME, u.LAST_NAME, u.EMAIL_ADDRESS, u.PH_NUMBER
            ORDER BY MAX(o.ORDER_DATE) DESC NULLS LAST, CUSTOMER_NAME
        ', [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        $errors[] = 'Customer query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load customers.');
        return [];
    }
}

function customer_avatar_color_index($customerId) {
    return abs(crc32((string)$customerId)) % 6;
}

$customers = get_trader_customers($conn, $traderId, $errors);
$totalCustomers = count($customers);
$totalOrders = array_sum(array_map(fn($c) => (int)($c['ORDERS_COUNT'] ?? 0), $customers));
$totalSpent = array_sum(array_map(fn($c) => (float)($c['TOTAL_SPENT'] ?? 0), $customers));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy — Customers</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/customer.css?v=20260517">
</head>
<body>
<?php $active = 'customers'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Customers', 'Customers who ordered products from your shop'); ?>
  <div class="body">
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="page-header">
      <div class="page-header-left"><h1>👥 Customers</h1><p id="customerCount"><?php echo int_fmt($totalCustomers); ?> customer<?php echo $totalCustomers === 1 ? '' : 's'; ?> found for <?php echo e($profile['SHOP_NAME']); ?></p></div>
      <a class="btn-add" href="customer.php"><span>↻</span> Refresh</a>
    </div>

    <div class="summary-strip">
      <div class="sum-card"><div class="sum-ico" style="background:rgba(29,158,117,.12)">👥</div><div><div class="sum-val"><?php echo int_fmt($totalCustomers); ?></div><div class="sum-lbl">Customers</div></div></div>
      <div class="sum-card"><div class="sum-ico" style="background:rgba(61,191,164,.12)">📦</div><div><div class="sum-val"><?php echo int_fmt($totalOrders); ?></div><div class="sum-lbl">Total Orders</div></div></div>
      <div class="sum-card"><div class="sum-ico" style="background:rgba(244,124,90,.12)">💰</div><div><div class="sum-val"><?php echo money_fmt($totalSpent); ?></div><div class="sum-lbl">Total Spent</div></div></div>
    </div>

    <div class="tab-bar"><div class="tab-item active">Customers</div></div>

    <div class="cust-search-wrap"><div class="cust-search"><span>🔍</span><input type="text" id="custSearch" placeholder="Search by name, email or phone number"></div></div>

    <div class="cust-table-wrap">
      <table class="cust-table">
        <colgroup><col><col><col><col><col><col></colgroup>
        <thead><tr><th>Name</th><th>Email</th><th>Phone Number</th><th>Orders Count</th><th>Total Spent</th><th>Action</th></tr></thead>
        <tbody id="custTableBody">
          <?php if (!$customers): ?>
            <tr class="static-empty"><td colspan="6"><div class="empty-state" style="border:none;box-shadow:none"><div class="empty-ico">👥</div><p>No customers yet. When customers place orders containing your products, they will appear here.</p></div></td></tr>
          <?php else: ?>
            <?php foreach ($customers as $c):
              $customerId = (string)($c['CUSTOMER_ID'] ?? '');
              $name = trim($c['CUSTOMER_NAME'] ?? '') ?: 'Customer';
              $initials = initials_from_name($name);
              $search = strtolower($name . ' ' . ($c['EMAIL_ADDRESS'] ?? '') . ' ' . ($c['PH_NUMBER'] ?? '') . ' ' . $customerId);
              $color = customer_avatar_color_index($customerId ?: $name);
            ?>
              <tr data-search="<?php echo e($search); ?>">
                <td><div class="cust-name-cell"><div class="cust-avatar cav-<?php echo e($color); ?>"><?php echo e($initials); ?></div><div class="cust-name-info"><div class="cust-fullname"><?php echo e($name); ?></div><div class="cust-joined">First order: <?php echo e($c['JOINED_LABEL'] ?: '—'); ?></div></div></div></td>
                <td><span class="cust-email"><?php echo e($c['EMAIL_ADDRESS'] ?: '—'); ?></span></td>
                <td><span class="cust-phone"><?php echo e($c['PH_NUMBER'] ?: '—'); ?></span></td>
                <td><span class="orders-badge"><?php echo int_fmt($c['ORDERS_COUNT']); ?></span></td>
                <td><span class="cust-spent"><?php echo money_fmt($c['TOTAL_SPENT']); ?></span></td>
                <td><a class="view-orders-btn" href="customer_all_order.php?customer_id=<?php echo urlencode($customerId); ?>">View all orders</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="empty-state" id="custEmpty" style="display:none;border:none;box-shadow:none"><div class="empty-ico">👥</div><p>No customers found matching your search.</p></div>
      <div class="pagination"><span class="pag-info" id="pagInfo"></span><div class="pag-btns" id="pagBtns"></div></div>
    </div>
  </div>
</div>
<div class="toast" id="toast"></div>
<script src="../assets/trader/js/customer.js?v=20260517"></script>
</body>
</html>

<?php
require_once __DIR__ . '/trader_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $productId = trim($_POST['product_id'] ?? '');

    if ($conn && $productId !== '') {
        try {
            if ($action === 'set_stock') {
                $rawStock = trim($_POST['stock_available'] ?? '');

                if ($rawStock === '' || !ctype_digit($rawStock)) {
                    $errors[] = 'Please enter a valid stock amount.';
                } else {
                    $newStock = (int)$rawStock;

                    $stmt = db_bind_and_execute($conn, '
                        UPDATE PRODUCT p
                        SET p.STOCK_AVAILABLE = :stock_available
                        WHERE p.PRODUCT_ID = :product_id
                          AND EXISTS (
                              SELECT 1
                              FROM SHOP s
                              WHERE s.SHOP_ID = p.SHOP_ID
                                AND s.TRADER_ID = :trader_id
                          )
                    ', [
                        ':stock_available' => $newStock,
                        ':product_id' => $productId,
                        ':trader_id' => $traderId
                    ]);

                    if (function_exists('oci_num_rows') && oci_num_rows($stmt) < 1) {
                        throw new RuntimeException('No matching product was updated. Refresh the page and try again.');
                    }

                    if ($newStock <= 0) {
                        remove_product_from_all_carts($conn, $productId);
                    }

                    $flash = 'Stock updated successfully.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Warehouse update failed: ' . shoplocalfy_public_exception_message($e, 'Could not update warehouse.');
        }
    }
}

function get_warehouse_products($conn, $traderId, &$errors) {
    if (!$conn || !table_exists($conn, 'PRODUCT') || !table_exists($conn, 'SHOP')) return [];
    $imageSelect = column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE') ? 'p.PRODUCT_IMAGE' : 'NULL';
    $activeSelect = column_exists($conn, 'PRODUCT', 'IS_ACTIVE') ? 'p.IS_ACTIVE' : '1 AS IS_ACTIVE';
    try {
        return db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                p.ITEM_PRICE,
                p.STOCK_AVAILABLE,
                p.MIN_ORDER,
                p.MAX_ORDER,
                {$activeSelect},
                p.SHOP_ID,
                s.SHOP_NAME,
                NVL(c.CATEGORY_NAME, 'Uncategorised') AS CATEGORY_NAME,
                {$imageSelect} AS PRODUCT_IMAGE
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID
            WHERE s.TRADER_ID = :trader_id
            ORDER BY p.PRODUCT_NAME
        ", [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        $errors[] = 'Warehouse query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load warehouse.');
        return [];
    }
}

$products = get_warehouse_products($conn, $traderId, $errors);
$pendingCount = get_pending_order_count($conn, $traderId);
$totalProducts = count($products);
$lowStock = 0;
$outStock = 0;
foreach ($products as $p) {
    $status = stock_status($p['STOCK_AVAILABLE'], $p['MIN_ORDER']);
    if ($status === 'low') $lowStock++;
    if ($status === 'out') $outStock++;
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
  <title>ShopLocalfy — Warehouse</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/warehouse.css?v=20260517">
</head>
<body>
<?php $active = 'warehouse'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Warehouse', 'Manage your stock and inventory'); ?>
  <div class="body">
    <?php if ($flash): ?><div class="notice" style="background:#ecfdf5;border-color:#bbf7d0;color:#047857"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="wh-header">
      <h2 class="wh-title">Warehouse / Stock</h2>
      <a class="btn-add" href="products.php">+ Add product</a>
    </div>

    <div class="wh-stats">
      <div class="wh-stat"><div class="wh-stat-lbl">Total Products</div><div class="wh-stat-val"><?php echo int_fmt($totalProducts); ?></div></div>
      <div class="wh-stat"><div class="wh-stat-lbl">Low Stock Items</div><div class="wh-stat-val"><?php echo int_fmt($lowStock); ?></div></div>
      <div class="wh-stat"><div class="wh-stat-lbl">Out of Stock</div><div class="wh-stat-val"><?php echo int_fmt($outStock); ?></div></div>
    </div>

    <div class="wh-filter">
      <select id="statusFilter"><option value="all">All Status</option><option value="active">Active</option><option value="low">Low Stock</option><option value="out">Out of Stock</option></select>
      <span class="right-note">Only products from shops owned by <?php echo e($profile['FULL_NAME']); ?> are listed.</span>
    </div>

    <div class="wh-table-wrap">
      <table class="wh-table">
        <thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Qty</th><th>Status</th><th>Set Stock</th></tr></thead>
        <tbody id="stockTableBody">
          <?php if (!$products): ?>
            <tr><td colspan="6"><div class="empty-state"><div class="empty-ico">🏗</div><p>No warehouse products yet. Add products and they will appear here automatically.</p></div></td></tr>
          <?php else: ?>
            <?php foreach ($products as $p):
              $status = stock_status($p['STOCK_AVAILABLE'], $p['MIN_ORDER']);
              $img = product_image_path($p['PRODUCT_IMAGE'] ?? '');
              $search = strtolower(($p['PRODUCT_NAME'] ?? '') . ' ' . ($p['PRODUCT_ID'] ?? '') . ' ' . ($p['CATEGORY_NAME'] ?? '') . ' ' . $status);
            ?>
              <tr data-status="<?php echo e($status); ?>" data-search="<?php echo e($search); ?>">
                <td><div class="prod-cell"><?php if ($img): ?><img class="prod-thumb" src="<?php echo e($img); ?>" alt="<?php echo e($p['PRODUCT_NAME']); ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"><?php else: ?><div class="prod-thumb-placeholder">🏷</div><?php endif; ?><div><div class="prod-name-small"><?php echo e($p['PRODUCT_NAME']); ?></div><div class="prod-category-small"><?php echo e($p['CATEGORY_NAME']); ?> · <?php echo e($p['SHOP_NAME']); ?></div></div></div></td>
                <td><?php echo e($p['PRODUCT_ID']); ?></td>
                <td><?php echo money_fmt($p['ITEM_PRICE']); ?></td>
                <td><?php echo int_fmt($p['STOCK_AVAILABLE']); ?></td>
                <td><span class="status-pill <?php echo e($status); ?>"><?php echo e($status === 'out' ? 'Out of Stock' : ($status === 'low' ? 'Low Stock' : 'Active')); ?></span></td>
                <td>
                  <form class="stock-form" method="POST">
                    <input type="hidden" name="action" value="set_stock">
                    <input type="hidden" name="product_id" value="<?php echo e($p['PRODUCT_ID']); ?>">

                    <input
                      type="number"
                      name="stock_available"
                      class="stock-input"
                      min="0"
                      step="1"
                      value="<?php echo e((int)$p['STOCK_AVAILABLE']); ?>"
                      required
                    >

                    <button type="submit" class="btn-stock-update">Update</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="../assets/trader/js/warehouse.js?v=20260517"></script>
</body>
</html>


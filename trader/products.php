<?php
require_once __DIR__ . '/trader_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

date_default_timezone_set('Asia/Kathmandu');

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);

$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');

function product_db_execute_safe($conn, $sql, $binds = []) {
    set_error_handler(function () { return true; });
    try {
        return db_bind_and_execute($conn, $sql, $binds);
    } finally {
        restore_error_handler();
    }
}
function product_create_shop($conn, $traderId) {
    if (!table_exists($conn, 'SHOP')) throw new RuntimeException('SHOP table does not exist.');

    $existingShop = db_one(
        $conn,
        'SELECT SHOP_ID FROM SHOP WHERE TRADER_ID = :trader_id FETCH FIRST 1 ROWS ONLY',
        [':trader_id' => $traderId]
    );

    if ($existingShop) {
        throw new RuntimeException('A shop already exists for this trader account.');
    }

    $activeShopCount = db_one(
        $conn,
        "SELECT COUNT(*) AS CNT
         FROM SHOP
         WHERE UPPER(NVL(APPROVAL_STATUS, 'PENDING')) NOT IN ('REJECTED', 'SUSPENDED')"
    );

    if ((int)($activeShopCount['CNT'] ?? 0) >= 10) {
        throw new RuntimeException('ShopLocalfy is limited to 10 shops. New shop requests are currently closed.');
    }

    $columns = [];
    $values = [];
    $binds = [];

    // Create SHOP_ID in PHP as well, so this still works if seq_shop_id was reset while data exists.
    if (column_exists($conn, 'SHOP', 'SHOP_ID')) {
        $columns[] = 'SHOP_ID';
        $values[] = ':shop_id';
        $binds[':shop_id'] = product_next_id($conn, 'SHOP', 'SHOP_ID', 'S');
    }

    $columns[] = 'TRADER_ID';
    $values[] = ':trader_id';
    $binds[':trader_id'] = $traderId;

    if (column_exists($conn, 'SHOP', 'SHOP_NAME')) {
        $columns[] = 'SHOP_NAME';
        $values[] = ':shop_name';
        $binds[':shop_name'] = trim($_POST['shop_name'] ?? '');
    }

    if (column_exists($conn, 'SHOP', 'LOCATION')) {
        $columns[] = 'LOCATION';
        $values[] = ':location';
        $binds[':location'] = trim($_POST['location'] ?? '');
    } elseif (column_exists($conn, 'SHOP', 'SHOP_ADDRESS')) {
        $columns[] = 'SHOP_ADDRESS';
        $values[] = ':location';
        $binds[':location'] = trim($_POST['location'] ?? '');
    }

    if (column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
        $columns[] = 'APPROVAL_STATUS';
        $values[] = ':approval_status';
        
        $traderStatusRow = db_one(
    $conn,
    'SELECT NVL(UPPER(TRIM(VERIFIED_STATUS)), \'PENDING\') AS VERIFIED_STATUS
     FROM TRADER
     WHERE USER_ID = :trader_id',
    [':trader_id' => $traderId]
);

$traderStatus = (string)($traderStatusRow['VERIFIED_STATUS'] ?? 'PENDING');

$binds[':approval_status'] = $traderStatus === 'VERIFIED' ? 'APPROVED' : 'PENDING';
    }

    product_db_execute_safe($conn, 'INSERT INTO SHOP (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')', $binds);
}
$productReady = $conn && table_exists($conn, 'PRODUCT');
$shopReady = $conn && table_exists($conn, 'SHOP');
$hasImage = $productReady && column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE');
$hasActive = $productReady && column_exists($conn, 'PRODUCT', 'IS_ACTIVE');
$hasApproval = $productReady && column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS');

$requiredColumns = ['PRODUCT_ID','SHOP_ID','CATEGORY_ID','PRODUCT_NAME','DESCRIPTION','ITEM_PRICE','QUANTITY_PER_ITEM','STOCK_AVAILABLE','MIN_ORDER','MAX_ORDER','ALLERGY_INFO'];
$missingColumns = [];
if ($productReady) {
    foreach ($requiredColumns as $col) {
        if (!column_exists($conn, 'PRODUCT', $col)) $missingColumns[] = $col;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_shop') {
            product_create_shop($conn, $traderId);
            header('Location: products.php?success=' . urlencode('Shop request created. It is pending admin approval.'));
            exit;
        }

        if ($action === 'set_visibility') {
            if (!$productReady) throw new RuntimeException('PRODUCT table was not found.');
            if (!$hasActive) throw new RuntimeException('PRODUCT.IS_ACTIVE column is missing. Add it before using hide/unhide.');

            $shop = product_get_shop($conn, $traderId);
            if (!$shop) throw new RuntimeException('Shop not found.');

            $productId = trim($_POST['product_id'] ?? '');
            $newStatus = (int)($_POST['is_active'] ?? 0);
            $newStatus = $newStatus === 1 ? 1 : 0;
            if ($productId === '') throw new RuntimeException('Product ID is missing.');

            $stmt = product_db_execute_safe($conn, '
                UPDATE PRODUCT
                SET IS_ACTIVE = :is_active
                WHERE PRODUCT_ID = :product_id
                  AND SHOP_ID = :shop_id
            ', [
                ':is_active' => $newStatus,
                ':product_id' => $productId,
                ':shop_id' => $shop['SHOP_ID']
            ]);

            if (function_exists('oci_num_rows') && oci_num_rows($stmt) < 1) {
                throw new RuntimeException('No matching product was updated.');
            }

            if ($newStatus === 0) {
                remove_product_from_all_carts($conn, $productId);
            }

            $txt = $newStatus === 1 ? 'Product has been unhidden.' : 'Product has been hidden.';
            header('Location: products.php?success=' . urlencode($txt));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: products.php?error=' . urlencode(shoplocalfy_public_exception_message($e, 'Could not update product.')));
        exit;
    }
}

$shop = ($conn && $shopReady) ? product_get_shop($conn, $traderId) : null;
$products = [];

try {
    if ($conn && $shop && $productReady && empty($missingColumns)) {
        $imageSelect = $hasImage ? 'p.PRODUCT_IMAGE' : "'' AS PRODUCT_IMAGE";
        $activeSelect = $hasActive ? 'p.IS_ACTIVE' : '1 AS IS_ACTIVE';
        $approvalSelect = $hasApproval ? "NVL(UPPER(TRIM(p.ADMIN_APPROVAL_STATUS)), 'PENDING') AS ADMIN_APPROVAL_STATUS" : "'APPROVED' AS ADMIN_APPROVAL_STATUS";
        $categoryJoin = table_exists($conn, 'CATEGORY') ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID' : '';
        $categorySelect = table_exists($conn, 'CATEGORY') && column_exists($conn, 'CATEGORY', 'CATEGORY_NAME') ? 'c.CATEGORY_NAME' : "'Others' AS CATEGORY_NAME";

        $products = db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.SHOP_ID,
                p.CATEGORY_ID,
                p.PRODUCT_NAME,
                p.DESCRIPTION,
                p.ITEM_PRICE,
                p.QUANTITY_PER_ITEM,
                p.STOCK_AVAILABLE,
                p.MIN_ORDER,
                p.MAX_ORDER,
                p.ALLERGY_INFO,
                $imageSelect,
                $activeSelect,
                $approvalSelect,
                $categorySelect
            FROM PRODUCT p
            $categoryJoin
            WHERE p.SHOP_ID = :shop_id
            ORDER BY p.PRODUCT_NAME
        ", [':shop_id' => $shop['SHOP_ID']]);
    }
} catch (Throwable $e) {
    $error = shoplocalfy_public_exception_message($e, 'Could not load products.');
}

$totalProducts = count($products);
$lowStock = 0;
$outStock = 0;
$activeProducts = 0;
$approvedProducts = 0;
$pendingProducts = 0;
$rejectedProducts = 0;
foreach ($products as $product) {
    $stock = (int)($product['STOCK_AVAILABLE'] ?? 0);
    $active = (int)($product['IS_ACTIVE'] ?? 1) === 1;
    if ($active) $activeProducts++;
    $approvalStatus = strtoupper((string)($product['ADMIN_APPROVAL_STATUS'] ?? 'APPROVED'));
    if ($approvalStatus === 'APPROVED') $approvedProducts++;
    elseif ($approvalStatus === 'REJECTED') $rejectedProducts++;
    else $pendingProducts++;
    if ($stock <= 0) $outStock++;
    if ($stock > 0 && $stock <= 5) $lowStock++;
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
  <title>ShopLocalfy - Products</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/products.css?v=20260517">
</head>
<body>
<?php $active = 'products'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Products', 'Manage your product catalogue'); ?>
  <div class="content">
    <?php if ($message): ?><div class="notice success"><?php echo e($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!$productReady): ?><div class="notice">PRODUCT table was not found.</div><?php endif; ?>
    <?php if (!empty($missingColumns)): ?><div class="notice">PRODUCT table is missing these expected columns: <?php echo e(implode(', ', $missingColumns)); ?></div><?php endif; ?>
    <?php if ($productReady && !$hasImage): ?><div class="notice warn">Product image column is missing. To enable uploads, run: <strong>ALTER TABLE PRODUCT ADD (PRODUCT_IMAGE VARCHAR2(500));</strong></div><?php endif; ?>
    <?php if ($productReady && !$hasActive): ?><div class="notice warn">Hide/unhide needs this column: <strong>ALTER TABLE PRODUCT ADD (IS_ACTIVE NUMBER(1) DEFAULT 1 NOT NULL);</strong></div><?php endif; ?>
    <?php if ($productReady && !$hasApproval): ?><div class="notice warn">Admin product approval needs this column: <strong>ALTER TABLE PRODUCT ADD (ADMIN_APPROVAL_STATUS VARCHAR2(20) DEFAULT 'PENDING' NOT NULL);</strong></div><?php endif; ?>

    <div class="content-header">
      <div>
        <h2 class="content-title">Products</h2>
        <p class="content-count" id="productCount"><?php echo int_fmt($totalProducts); ?> product<?php echo $totalProducts === 1 ? '' : 's'; ?> found</p>
      </div>
      <?php if ($shop && empty($missingColumns)): ?>
        <div class="header-right">
          <div class="prod-search"><span>Search</span><input type="text" id="prodSearch" placeholder="Search products..."></div>
          <a class="btn-add" href="add_product.php">+ Add Product</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$shop && $shopReady): ?>
      <form method="POST" class="shop-card">
        <input type="hidden" name="action" value="create_shop">
        <div class="field-row">
          <div class="field"><label>Shop Name</label><input name="shop_name" required></div>
          <div class="field"><label>Location</label><input name="location"></div>
        </div>
        <button class="btn-save" type="submit">Create Shop</button>
      </form>
    <?php elseif ($shop && empty($missingColumns)): ?>
      <div class="stat-row">
        <div class="stat-card"><div class="stat-ico" style="background:#e8f5ee;">#</div><div><div class="stat-val"><?php echo int_fmt($totalProducts); ?></div><div class="stat-lbl">Total Products</div></div></div>
        <div class="stat-card"><div class="stat-ico" style="background:#ecfdf5;">✓</div><div><div class="stat-val"><?php echo int_fmt($approvedProducts); ?></div><div class="stat-lbl">Approved</div></div></div>
        <div class="stat-card"><div class="stat-ico" style="background:#fff3e0;">!</div><div><div class="stat-val"><?php echo int_fmt($pendingProducts); ?></div><div class="stat-lbl">Pending Approval</div></div></div>
        <div class="stat-card"><div class="stat-ico" style="background:#fce8e8;">0</div><div><div class="stat-val"><?php echo int_fmt($outStock); ?></div><div class="stat-lbl">Out of Stock</div></div></div>
      </div>

      <div class="table-card">
        <table class="prod-table">
          <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Visibility</th><th>Approval</th><th>Actions</th></tr></thead>
          <tbody id="prodTableBody">
            <?php foreach ($products as $p):
              $stock = (int)($p['STOCK_AVAILABLE'] ?? 0);
              $isActive = (int)($p['IS_ACTIVE'] ?? 1) === 1;
              $badgeClass = !$isActive ? 'badge-off' : ($stock <= 0 ? 'badge-out' : ($stock <= 5 ? 'badge-low' : 'badge-ok'));
              $badgeText = !$isActive ? 'Hidden' : ($stock <= 0 ? 'Out of Stock' : ($stock <= 5 ? 'Low Stock' : 'In Stock'));
              $approvalStatus = strtoupper((string)($p['ADMIN_APPROVAL_STATUS'] ?? 'APPROVED'));
              $approvalClass = $approvalStatus === 'APPROVED' ? 'badge-ok' : ($approvalStatus === 'REJECTED' ? 'badge-out' : 'badge-low');
              $search = strtolower(($p['PRODUCT_NAME'] ?? '') . ' ' . ($p['CATEGORY_NAME'] ?? '') . ' ' . ($p['PRODUCT_ID'] ?? '') . ' ' . $approvalStatus);
              $imgSrc = product_image_src($p['PRODUCT_IMAGE'] ?? '');
            ?>
              <tr data-search="<?php echo e($search); ?>">
                <td>
                  <div class="prod-cell">
                    <div class="prod-thumb"><img src="<?php echo e($imgSrc); ?>" alt="Product image" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"></div>
                    <div><div class="prod-name"><?php echo e($p['PRODUCT_NAME']); ?></div><div class="prod-sku">ID #<?php echo e($p['PRODUCT_ID']); ?></div></div>
                  </div>
                </td>
                <td><span class="prod-cat"><?php echo e($p['CATEGORY_NAME'] ?? 'Others'); ?></span></td>
                <td><span class="prod-price"><?php echo money_fmt($p['ITEM_PRICE']); ?></span></td>
                <td><span class="prod-stock"><?php echo int_fmt($stock); ?></span></td>
                <td><span class="badge <?php echo e($badgeClass); ?>"><?php echo e($badgeText); ?></span></td>
                <td><span class="badge <?php echo e($approvalClass); ?>"><?php echo e($approvalStatus); ?></span></td>
                <td class="actions-cell">
                  <div class="act-group">
                    <a class="act-btn" href="edit_product.php?id=<?php echo urlencode($p['PRODUCT_ID']); ?>">Edit</a>
                    <?php if ($hasActive): ?>
                      <form class="inline-form" method="POST" onsubmit="return confirm('<?php echo $isActive ? 'Hide this product?' : 'Unhide this product?'; ?>');">
                        <input type="hidden" name="action" value="set_visibility">
                        <input type="hidden" name="product_id" value="<?php echo e($p['PRODUCT_ID']); ?>">
                        <input type="hidden" name="is_active" value="<?php echo $isActive ? '0' : '1'; ?>">
                        <button class="act-btn <?php echo $isActive ? 'hide' : 'unhide'; ?>" type="submit"><?php echo $isActive ? 'Hide' : 'Unhide'; ?></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="empty-state" id="emptyState" style="<?php echo $products ? 'display:none;' : ''; ?>"><div class="empty-icon">#</div><p>No products found.</p></div>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="../assets/trader/js/products.js?v=20260517"></script>
</body>
</html>


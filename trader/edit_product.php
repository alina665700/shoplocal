<?php
require_once __DIR__ . '/trader_common.php';

date_default_timezone_set('Asia/Kathmandu');

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);

$error = '';
$productId = trim($_GET['id'] ?? $_POST['product_id'] ?? '');
function product_fetch_for_trader($conn, $traderId, $productId, $hasImage, $hasActive, $hasApproval = false) {
    if ($productId === '') return null;

    $imageSelect = $hasImage ? 'p.PRODUCT_IMAGE' : "'' AS PRODUCT_IMAGE";
    $activeSelect = $hasActive ? 'p.IS_ACTIVE' : '1 AS IS_ACTIVE';
    $approvalSelect = $hasApproval ? "NVL(UPPER(TRIM(p.ADMIN_APPROVAL_STATUS)), 'PENDING') AS ADMIN_APPROVAL_STATUS" : "'APPROVED' AS ADMIN_APPROVAL_STATUS";

    return db_one($conn, "
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
            $approvalSelect
        FROM PRODUCT p
        JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        WHERE p.PRODUCT_ID = :product_id
          AND s.TRADER_ID = :trader_id
        FETCH FIRST 1 ROWS ONLY
    ", [':product_id' => $productId, ':trader_id' => $traderId]);
}

$productReady = $conn && table_exists($conn, 'PRODUCT');
$shopReady = $conn && table_exists($conn, 'SHOP');
$shop = ($conn && $shopReady) ? product_get_shop($conn, $traderId) : null;
$categories = ($conn && table_exists($conn, 'CATEGORY')) ? product_get_categories($conn) : [];
if (!$categories && $conn && table_exists($conn, 'CATEGORY')) {
    product_default_category_id($conn);
    $categories = product_get_categories($conn);
}
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

$product = null;
if ($productReady && empty($missingColumns)) {
    $product = product_fetch_for_trader($conn, $traderId, $productId, $hasImage, $hasActive, $hasApproval);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$productReady) throw new RuntimeException('PRODUCT table was not found.');
        if (!$shop) throw new RuntimeException('Shop not found.');
        if (!empty($missingColumns)) throw new RuntimeException('PRODUCT table is missing: ' . implode(', ', $missingColumns));
        if (!$product) throw new RuntimeException('Product was not found or does not belong to your shop.');

        $name = trim($_POST['product_name'] ?? '');
        if ($name === '') throw new RuntimeException('Product name is required.');

        $price = product_clean_money($_POST['item_price'] ?? '0');
        if ((float)$price <= 0) {
            throw new RuntimeException('Product price must be greater than £0.');
        }
        $stock = product_clean_int($_POST['stock_available'] ?? '0', 0, 0);
        $quantity = product_clean_int($_POST['quantity_per_item'] ?? '1', 1, 1);
        $minOrder = product_clean_int($_POST['min_order'] ?? '1', 1, 1);
        $maxOrder = product_clean_int($_POST['max_order'] ?? '100', 100, 1);

        if ((int)$minOrder > (int)$maxOrder) {
            throw new RuntimeException('Minimum order cannot be greater than maximum order.');
        }

        $categoryId = trim($_POST['category_id'] ?? '');
        if ($categoryId === '') {
            $categoryId = product_default_category_id($conn);
            if ($categoryId === null && product_col_required($conn, 'PRODUCT', 'CATEGORY_ID')) {
                throw new RuntimeException('CATEGORY_ID is required. Create at least one category first.');
            }
        }
        if ($categoryId !== null && $categoryId !== '' && !product_category_exists($conn, $categoryId)) {
            throw new RuntimeException('Selected category was not found. Refresh the page and try again.');
        }

        $sets = [
            'CATEGORY_ID = :category_id',
            'PRODUCT_NAME = :product_name',
            'DESCRIPTION = :description',
            'ITEM_PRICE = :item_price',
            'QUANTITY_PER_ITEM = :quantity_per_item',
            'STOCK_AVAILABLE = :stock_available',
            'MIN_ORDER = :min_order',
            'MAX_ORDER = :max_order',
            'ALLERGY_INFO = :allergy_info',
        ];

        $binds = [
            ':category_id' => $categoryId,
            ':product_name' => $name,
            ':description' => trim($_POST['description'] ?? ''),
            ':item_price' => $price,
            ':quantity_per_item' => $quantity,
            ':stock_available' => $stock,
            ':min_order' => $minOrder,
            ':max_order' => $maxOrder,
            ':allergy_info' => trim($_POST['allergy_info'] ?? ''),
            ':product_id' => $productId,
            ':shop_id' => $shop['SHOP_ID'],
        ];

        if ($hasActive) {
            $sets[] = 'IS_ACTIVE = :is_active';
            $binds[':is_active'] = isset($_POST['is_active']) ? 1 : 0;
        }

        if ($hasImage) {
            $imagePath = product_upload_image('product_image');
            if ($imagePath !== null) {
                $sets[] = 'PRODUCT_IMAGE = :product_image';
                $binds[':product_image'] = $imagePath;
            }
        }

        $stmt = db_bind_and_execute($conn, '
            UPDATE PRODUCT
            SET ' . implode(', ', $sets) . '
            WHERE PRODUCT_ID = :product_id
              AND SHOP_ID = :shop_id
        ', $binds);

        if (function_exists('oci_num_rows') && oci_num_rows($stmt) < 1) {
            throw new RuntimeException('No matching product was updated.');
        }

        header('Location: products.php?success=' . urlencode('Product updated successfully.'));
        exit;
    } catch (Throwable $e) {
        $error = shoplocalfy_public_exception_message($e, 'Could not save product.');
        $product = product_fetch_for_trader($conn, $traderId, $productId, $hasImage, $hasActive, $hasApproval);
    }
}

$currentImage = $product ? product_image_src($product['PRODUCT_IMAGE'] ?? '') : '';
function old_value($name, $product, $fallback = '') {
    if (isset($_POST[$name])) return $_POST[$name];
    $map = [
        'product_name' => 'PRODUCT_NAME',
        'category_id' => 'CATEGORY_ID',
        'item_price' => 'ITEM_PRICE',
        'stock_available' => 'STOCK_AVAILABLE',
        'quantity_per_item' => 'QUANTITY_PER_ITEM',
        'min_order' => 'MIN_ORDER',
        'max_order' => 'MAX_ORDER',
        'description' => 'DESCRIPTION',
        'allergy_info' => 'ALLERGY_INFO',
    ];
    $col = $map[$name] ?? null;
    return $col && $product ? ($product[$col] ?? $fallback) : $fallback;
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
  <title>ShopLocalfy - Edit Product</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/edit_product.css?v=20260517">
</head>
<body>
<?php $active = 'products'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Products', 'Edit a product'); ?>
  <div class="content">
    <div class="page-head">
      <div><h2 class="title">Edit Product</h2><p class="subtitle">Update product details, stock, status, and image.</p></div>
      <a class="back-link" href="products.php">Back to Products</a>
    </div>

    <?php if ($error): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!$productReady): ?><div class="notice">PRODUCT table was not found.</div><?php endif; ?>
    <?php if (!$shop): ?><div class="notice warn">Shop not found for this trader.</div><?php endif; ?>
    <?php if ($productId === ''): ?><div class="notice">Product ID is missing from the URL.</div><?php endif; ?>
    <?php if ($productReady && $productId !== '' && !$product): ?><div class="notice">Product was not found or does not belong to your shop.</div><?php endif; ?>
    <?php if (!empty($missingColumns)): ?><div class="notice">PRODUCT table is missing these expected columns: <?php echo e(implode(', ', $missingColumns)); ?></div><?php endif; ?>
    <?php if ($productReady && !$hasImage): ?><div class="notice warn">PRODUCT_IMAGE column is missing, so image upload is hidden.</div><?php endif; ?>
    <?php if ($productReady && !$hasApproval): ?><div class="notice warn">ADMIN_APPROVAL_STATUS column is missing, so the current approval status cannot be displayed.</div><?php endif; ?>

    <?php if ($productReady && $shop && $product && empty($missingColumns)): ?>
    <form class="form-card" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="product_id" value="<?php echo e($product['PRODUCT_ID']); ?>">
      <div class="form-head">
        <h3><?php echo e($product['PRODUCT_NAME']); ?> <span style="color:var(--dim);font-size:12px;">#<?php echo e($product['PRODUCT_ID']); ?></span></h3>
        <?php $activeNow = (int)($product['IS_ACTIVE'] ?? 1) === 1; ?>
        <div class="head-actions">
          <span class="pill <?php echo $activeNow ? 'active' : ''; ?>"><?php echo $activeNow ? 'Active' : 'Hidden'; ?></span>
          <button class="btn-save-top" type="submit">Save Changes</button>
        </div>
      </div>
      <div class="form-body">
        <div class="field-row">
          <div class="field"><label>Product Name</label><input type="text" name="product_name" value="<?php echo e(old_value('product_name', $product)); ?>" required></div>
          <div class="field"><label>Category</label><select name="category_id"><option value="">Others</option><?php $selectedCat = (string)old_value('category_id', $product); foreach ($categories as $cat): ?><option value="<?php echo e($cat['CATEGORY_ID']); ?>" <?php echo $selectedCat === (string)$cat['CATEGORY_ID'] ? 'selected' : ''; ?>><?php echo e($cat['CATEGORY_NAME']); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Price</label><input type="number" name="item_price" min="0" step="0.01" value="<?php echo e(old_value('item_price', $product)); ?>" required></div>
          <div class="field"><label>Stock Quantity</label><input type="number" name="stock_available" min="0" step="1" value="<?php echo e(old_value('stock_available', $product, '0')); ?>" required></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Quantity Per Item</label><input type="number" name="quantity_per_item" min="1" step="1" value="<?php echo e(old_value('quantity_per_item', $product, '1')); ?>"></div>
          <div class="field"><label>Min Order</label><input type="number" name="min_order" min="1" step="1" value="<?php echo e(old_value('min_order', $product, '1')); ?>"></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Max Order</label><input type="number" name="max_order" min="1" step="1" value="<?php echo e(old_value('max_order', $product, '100')); ?>"></div>
          <div class="field"><label>Status</label><div class="check-row"><input type="checkbox" name="is_active" <?php echo ((isset($_POST['is_active']) || (!$_POST && $activeNow)) ? 'checked' : ''); ?> <?php echo !$hasActive ? 'disabled' : ''; ?>><span>Show this product</span></div></div>
          <div class="field"><label>Admin Approval</label><div class="check-row"><span><?php echo e($product['ADMIN_APPROVAL_STATUS'] ?? 'APPROVED'); ?></span></div><p class="form-hint">Approval status is only set when a product is first created or reviewed by admin.</p></div>
        </div>
        <?php if ($hasImage): ?>
        <div class="field-row">
          <div class="field"><label>Replace Image</label><input type="file" name="product_image" id="productImage" accept="image/jpeg,image/png,image/webp"><p class="form-hint">Leave empty to keep the current image. Allowed: JPG, PNG, WEBP. Max: 2MB.</p></div>
          <div class="field"><label>Current / New Preview</label><div class="image-preview" id="imagePreview"><img src="<?php echo e($currentImage); ?>" alt="Current product image" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"></div></div>
        </div>
        <?php endif; ?>
        <div class="field"><label>Description</label><textarea name="description"><?php echo e(old_value('description', $product)); ?></textarea></div>
        <div class="field"><label>Allergy Info</label><textarea name="allergy_info"><?php echo e(old_value('allergy_info', $product)); ?></textarea></div>
      </div>
      <div class="form-foot"><a class="btn-cancel" href="products.php">Cancel</a><button class="btn-save" type="submit">Save Changes</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<script src="../assets/trader/js/edit_product.js?v=20260517"></script>
</body>
</html>


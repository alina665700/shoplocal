<?php
require_once __DIR__ . '/trader_common.php';

date_default_timezone_set('Asia/Kathmandu');

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);

$error = '';
function product_uploaded_file_cleanup($relativePath)
{
    $relativePath = trim(str_replace('\\', '/', (string)$relativePath));
    if ($relativePath === '' || strpos($relativePath, 'uploads/products/') !== 0) return;

    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}


function product_next_product_id($conn)
{
    if (!$conn) {
        throw new RuntimeException('Database connection is not available.');
    }

    // Preferred path: use the PRODUCT sequence created by the project setup scripts.
    $stmt = @oci_parse($conn, "SELECT 'P' || LPAD(seq_product_id.NEXTVAL, 9, '0') AS PRODUCT_ID FROM dual");
    if ($stmt && @oci_execute($stmt)) {
        $row = oci_fetch_assoc($stmt);
        if (!empty($row['PRODUCT_ID'])) {
            return $row['PRODUCT_ID'];
        }
    }

    // Fallback for local databases where the sequence was not recreated yet.
    $stmt = oci_parse($conn, "
        SELECT 'P' || LPAD(NVL(MAX(TO_NUMBER(SUBSTR(product_id, 2))), 0) + 1, 9, '0') AS PRODUCT_ID
        FROM PRODUCT
        WHERE REGEXP_LIKE(product_id, '^P[0-9]+$')
    ");

    if (!$stmt || !oci_execute($stmt)) {
        error_log('ShopLocalfy product ID generation failed: ' . json_encode($stmt ? oci_error($stmt) : oci_error($conn)));
        throw new RuntimeException('Could not generate product ID. Please try again.');
    }

    $row = oci_fetch_assoc($stmt);
    if (empty($row['PRODUCT_ID'])) {
        throw new RuntimeException('Could not generate product ID.');
    }

    return $row['PRODUCT_ID'];
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

$productApprovalColumn = '';
foreach ([
    'ADMIN_APPROVAL_STATUS',
    'ADMIN_APPROVAL',
    'PRODUCT_APPROVAL_STATUS',
    'APPROVAL_STATUS',
    'PRODUCT_STATUS'
] as $approvalCol) {
    if ($productReady && column_exists($conn, 'PRODUCT', $approvalCol)) {
        $productApprovalColumn = $approvalCol;
        break;
    }
}
$hasApproval = $productApprovalColumn !== '';

$requiredColumns = ['SHOP_ID', 'CATEGORY_ID', 'PRODUCT_NAME', 'DESCRIPTION', 'ITEM_PRICE', 'QUANTITY_PER_ITEM', 'STOCK_AVAILABLE', 'MIN_ORDER', 'MAX_ORDER', 'ALLERGY_INFO'];
$missingColumns = [];
if ($productReady) {
    foreach ($requiredColumns as $col) {
        if (!column_exists($conn, 'PRODUCT', $col)) $missingColumns[] = $col;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadedImagePath = null;

    try {
        if (!$productReady) throw new RuntimeException('PRODUCT table was not found.');
        if (!$shop) throw new RuntimeException('Create a shop before adding products.');
        if (!empty($missingColumns)) throw new RuntimeException('PRODUCT table is missing: ' . implode(', ', $missingColumns));

        $name = trim($_POST['product_name'] ?? '');
        if ($name === '') throw new RuntimeException('Product name is required.');

        $price = product_clean_money($_POST['item_price'] ?? '0');
        $priceNumber = (float)$price;
        if ($priceNumber <= 0) {
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
            if ($categoryId === null) {
                throw new RuntimeException('Please create at least one category before adding a product.');
            }
        }

        if (!product_category_exists($conn, $categoryId)) {
            throw new RuntimeException('Selected category was not found. Refresh the page and try again.');
        }

        $productId = product_next_product_id($conn);

        $columns = [
            'PRODUCT_ID',
            'SHOP_ID',
            'CATEGORY_ID',
            'PRODUCT_NAME',
            'DESCRIPTION',
            'ITEM_PRICE',
            'QUANTITY_PER_ITEM',
            'STOCK_AVAILABLE',
            'MIN_ORDER',
            'MAX_ORDER',
            'ALLERGY_INFO'
        ];
        $values = [
            ':product_id',
            ':shop_id',
            ':category_id',
            ':product_name',
            ':description',
            ':item_price',
            ':quantity_per_item',
            ':stock_available',
            ':min_order',
            ':max_order',
            ':allergy_info'
        ];
        $binds = [
            ':product_id' => $productId,
            ':shop_id' => $shop['SHOP_ID'],
            ':category_id' => $categoryId,
            ':product_name' => $name,
            ':description' => trim($_POST['description'] ?? ''),
            ':item_price' => $price,
            ':quantity_per_item' => $quantity,
            ':stock_available' => $stock,
            ':min_order' => $minOrder,
            ':max_order' => $maxOrder,
            ':allergy_info' => trim($_POST['allergy_info'] ?? ''),
        ];

        if ($hasActive) {
            $columns[] = 'IS_ACTIVE';
            $values[] = ':is_active';
            $binds[':is_active'] = isset($_POST['is_active']) ? 1 : 0;
        }

        if ($hasApproval) {
            $columns[] = $productApprovalColumn;
            $values[] = ':admin_approval_status';
            $binds[':admin_approval_status'] = 'PENDING';
        }

        if ($hasImage) {
            $uploadedImagePath = product_upload_image('product_image');
            $columns[] = 'PRODUCT_IMAGE';
            $values[] = ':product_image';
            $binds[':product_image'] = $uploadedImagePath;
        }

        db_bind_and_execute($conn, 'INSERT INTO PRODUCT (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')', $binds);

        header('Location: products.php?success=' . urlencode($hasApproval ? 'Product added. It is pending admin approval.' : 'Product added successfully.'));
        exit;
    } catch (Throwable $e) {
        if (!empty($uploadedImagePath)) {
            product_uploaded_file_cleanup($uploadedImagePath);
        }

        $error = shoplocalfy_public_exception_message($e, 'Could not save product. Please check the details and try again.');
    }
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
    <title>ShopLocalfy - Add Product</title>
    <?php render_base_css(); ?>
    <link rel="stylesheet" href="../assets/trader/css/add_product.css?v=20260517">
</head>

<body>
    <?php $active = 'products';
    $pendingOrderCount = $pendingCount;
    include __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <?php render_topbar('Products', 'Add a product'); ?>
        <div class="content">
            <div class="page-head">
                <div>
                    <h2 class="title">Add Product</h2>
                    <p class="subtitle">Create a new product for your shop.</p>
                </div>
                <a class="back-link" href="products.php">Back to Products</a>
            </div>

            <?php if ($error): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>
            <?php if (!$productReady): ?><div class="notice">PRODUCT table was not found.</div><?php endif; ?>
            <?php if (!$shop): ?><div class="notice warn">You need to create your shop first from the Products page.</div><?php endif; ?>
            <?php if (!empty($missingColumns)): ?><div class="notice">PRODUCT table is missing these expected columns: <?php echo e(implode(', ', $missingColumns)); ?></div><?php endif; ?>
            <?php if ($productReady && !$hasImage): ?><div class="notice warn">PRODUCT_IMAGE column is missing, so image upload is hidden.</div><?php endif; ?>
            <?php if ($productReady && !$hasApproval): ?><div class="notice warn">No product approval column was found. New products cannot be held for admin approval until you run the PRODUCT ALTER TABLE code.</div><?php endif; ?>

            <?php if ($productReady && $shop && empty($missingColumns)): ?>
                <form class="form-card" method="POST" enctype="multipart/form-data">
                    <div class="form-head">
                        <h3>Product Details</h3>
                        <p>Use clear names, accurate prices, and a real stock count.</p>
                    </div>
                    <div class="form-body">
                        <div class="field-row">
                            <div class="field"><label>Product Name</label><input type="text" name="product_name" value="<?php echo e($_POST['product_name'] ?? ''); ?>" required></div>
                            <div class="field">
                                <label>Category</label>
                                <select name="category_id" required>
                                    <option value="" disabled <?php echo empty($_POST['category_id'] ?? '') ? 'selected' : ''; ?>>Select category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo e($cat['CATEGORY_ID']); ?>" <?php echo (($_POST['category_id'] ?? '') === $cat['CATEGORY_ID']) ? 'selected' : ''; ?>>
                                            <?php echo e($cat['CATEGORY_NAME']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label>Price</label><input type="number" name="item_price" min="0" step="0.01" value="<?php echo e($_POST['item_price'] ?? ''); ?>" required></div>
                            <div class="field"><label>Stock Quantity</label><input type="number" name="stock_available" min="0" step="1" value="<?php echo e($_POST['stock_available'] ?? '0'); ?>" required></div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label>Quantity Per Item</label><input type="number" name="quantity_per_item" min="1" step="1" value="<?php echo e($_POST['quantity_per_item'] ?? '1'); ?>"></div>
                            <div class="field"><label>Min Order</label><input type="number" name="min_order" min="1" step="1" value="<?php echo e($_POST['min_order'] ?? '1'); ?>"></div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label>Max Order</label><input type="number" name="max_order" min="1" step="1" value="<?php echo e($_POST['max_order'] ?? '100'); ?>"></div>
                            <div class="field"><label>Status</label>
                                <div class="check-row"><input type="checkbox" name="is_active" checked <?php echo !$hasActive ? 'disabled' : ''; ?>><span>Show this product</span></div>
                            </div>
                        </div>
                        <?php if ($hasImage): ?>
                            <div class="field-row upload-row">
                                <div class="field"><label>Product Image</label><input type="file" name="product_image" id="productImage" accept="image/jpeg,image/png,image/webp">
                                    <p class="form-hint">Allowed: JPG, PNG, WEBP. Maximum size: 2MB.</p>
                                </div>
                                <div class="field"><label>Preview</label>
                                    <div class="image-preview" id="imagePreview">No image</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="field"><label>Description</label><textarea name="description"><?php echo e($_POST['description'] ?? ''); ?></textarea></div>
                        <div class="field"><label>Allergy Info</label><textarea name="allergy_info"><?php echo e($_POST['allergy_info'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="form-foot"><a class="btn-cancel" href="products.php">Cancel</a><button class="btn-save" type="submit">Add Product</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="../assets/trader/js/add_product.js?v=20260517"></script>
</body>

</html>
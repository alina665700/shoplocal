<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

date_default_timezone_set('Asia/Kathmandu');

if (function_exists('require_admin_login')) {
    require_admin_login();
}

$conn = $conn ?? admin_db_connection();
$notice = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if (!function_exists('mp_h')) {
    function mp_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function mp_redirect($success = '', $error = '') {
    $params = [];
    if ($success !== '') $params['success'] = $success;
    if ($error !== '') $params['error'] = $error;
    header('Location: manage-products.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function mp_lob_text($value, $limit = 120) {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        $value = $loaded === false ? '' : $loaded;
    }
    $text = trim(strip_tags((string)($value ?? '')));
    if (strlen($text) > $limit) {
        return substr($text, 0, $limit - 1) . '…';
    }
    return $text;
}

function mp_product_image_src($value) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $value = trim(str_replace('\\', '/', (string)$value));
    if ($value === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $value)) return $value;
    if (str_starts_with($value, '../') || str_starts_with($value, '/')) return $value;
    if (str_starts_with($value, 'uploads/products/')) return '../' . $value;
    return '../uploads/products/' . rawurlencode(basename($value));
}

function mp_status_label($status) {
    $status = strtoupper(trim((string)$status));
    return $status !== '' ? $status : 'PENDING';
}

function mp_status_class($status) {
    return match (mp_status_label($status)) {
        'APPROVED' => 'approved',
        'REJECTED' => 'rejected',
        default => 'pending',
    };
}

$productReady = $conn && table_exists($conn, 'PRODUCT');
$hasApproval = $productReady && column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS');
$hasActive = $productReady && column_exists($conn, 'PRODUCT', 'IS_ACTIVE');
$hasImage = $productReady && column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE');
$hasAdminId = $productReady && column_exists($conn, 'PRODUCT', 'ADMIN_ID');
$hasDescription = $productReady && column_exists($conn, 'PRODUCT', 'DESCRIPTION');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$productReady) throw new RuntimeException('PRODUCT table was not found.');

        $action = trim((string)($_POST['action'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? ''));
        if ($productId === '') throw new RuntimeException('Product ID is missing.');

        if ($action === 'set_approval') {
            if (!$hasApproval) throw new RuntimeException('PRODUCT.ADMIN_APPROVAL_STATUS column is missing. Run the ALTER TABLE code first.');
            $status = mp_status_label($_POST['approval_status'] ?? 'PENDING');
            if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
                throw new RuntimeException('Invalid approval status. Manage Products can only approve or reject. Pending is only created when traders add or edit products.');
            }

            $sets = ['ADMIN_APPROVAL_STATUS = :status'];
            $binds = [':status' => $status, ':product_id' => $productId];

            if ($hasAdminId) {
                $sets[] = 'ADMIN_ID = :admin_id';
                $binds[':admin_id'] = admin_first_admin_id();
            }

            $stmt = db_bind_and_execute($conn, 'UPDATE PRODUCT SET ' . implode(', ', $sets) . ' WHERE PRODUCT_ID = :product_id', $binds);
            if (function_exists('oci_num_rows') && oci_num_rows($stmt) < 1) {
                throw new RuntimeException('No matching product was updated.');
            }

            if ($status !== 'APPROVED') {
                remove_product_from_all_carts($conn, $productId);
            }

            mp_redirect($status === 'APPROVED' ? 'Product approved successfully.' : 'Product rejected successfully.');
        }

        if ($action === 'set_active') {
            if (!$hasActive) throw new RuntimeException('PRODUCT.IS_ACTIVE column is missing.');
            $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = db_bind_and_execute($conn, 'UPDATE PRODUCT SET IS_ACTIVE = :is_active WHERE PRODUCT_ID = :product_id', [
                ':is_active' => $active,
                ':product_id' => $productId,
            ]);
            if (function_exists('oci_num_rows') && oci_num_rows($stmt) < 1) {
                throw new RuntimeException('No matching product was updated.');
            }
            if (!$active) {
                remove_product_from_all_carts($conn, $productId);
            }
            mp_redirect($active ? 'Product made active.' : 'Product hidden.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        mp_redirect('', shoplocalfy_public_exception_message($e, 'Could not update product.'));
    }
}

$products = [];
try {
    if ($productReady) {
        $imageSelect = $hasImage ? 'p.PRODUCT_IMAGE' : "'' AS PRODUCT_IMAGE";
        $approvalSelect = $hasApproval ? "NVL(UPPER(TRIM(p.ADMIN_APPROVAL_STATUS)), 'PENDING') AS ADMIN_APPROVAL_STATUS" : "'APPROVED' AS ADMIN_APPROVAL_STATUS";
        $activeSelect = $hasActive ? 'NVL(p.IS_ACTIVE, 1) AS IS_ACTIVE' : '1 AS IS_ACTIVE';
        $adminSelect = $hasAdminId ? 'p.ADMIN_ID' : "NULL AS ADMIN_ID";
        $descriptionSelect = $hasDescription ? 'p.DESCRIPTION' : "NULL AS DESCRIPTION";

        $categoryJoin = table_exists($conn, 'CATEGORY') ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID' : '';
        $categoryNameSelect = table_exists($conn, 'CATEGORY') && column_exists($conn, 'CATEGORY', 'CATEGORY_NAME') ? 'c.CATEGORY_NAME' : "NULL AS CATEGORY_NAME";

        $userJoin = table_exists($conn, 'USER') ? 'LEFT JOIN "USER" tu ON tu.USER_ID = s.TRADER_ID' : '';
        $traderNameSelect = table_exists($conn, 'USER') ? "TRIM(tu.FIRST_NAME || ' ' || tu.LAST_NAME) AS TRADER_NAME" : "NULL AS TRADER_NAME";

        $products = db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.SHOP_ID,
                p.CATEGORY_ID,
                p.PRODUCT_NAME,
                $descriptionSelect,
                p.ITEM_PRICE,
                NVL(p.STOCK_AVAILABLE, 0) AS STOCK_AVAILABLE,
                NVL(p.QUANTITY_PER_ITEM, 1) AS QUANTITY_PER_ITEM,
                NVL(p.MIN_ORDER, 1) AS MIN_ORDER,
                NVL(p.MAX_ORDER, 100) AS MAX_ORDER,
                p.ALLERGY_INFO,
                $imageSelect,
                $approvalSelect,
                $activeSelect,
                $adminSelect,
                s.SHOP_NAME,
                s.TRADER_ID,
                NVL(s.APPROVAL_STATUS, 'PENDING') AS SHOP_STATUS,
                $categoryNameSelect,
                $traderNameSelect
            FROM PRODUCT p
            LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            $categoryJoin
            $userJoin
            ORDER BY
                CASE NVL(UPPER(TRIM(p.ADMIN_APPROVAL_STATUS)), 'PENDING')
                    WHEN 'PENDING' THEN 0
                    WHEN 'REJECTED' THEN 1
                    ELSE 2
                END,
                p.PRODUCT_ID DESC
        ");
    }
} catch (Throwable $e) {
    $error = shoplocalfy_public_exception_message($e, 'Could not load products.');
}

$totalProducts = count($products);
$pendingProducts = 0;
$approvedProducts = 0;
$rejectedProducts = 0;
$hiddenProducts = 0;
$outOfStock = 0;
foreach ($products as $row) {
    $status = mp_status_label($row['ADMIN_APPROVAL_STATUS'] ?? 'PENDING');
    if ($status === 'APPROVED') $approvedProducts++;
    elseif ($status === 'REJECTED') $rejectedProducts++;
    else $pendingProducts++;
    if ((int)($row['IS_ACTIVE'] ?? 1) !== 1) $hiddenProducts++;
    if ((int)($row['STOCK_AVAILABLE'] ?? 0) <= 0) $outOfStock++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=11" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=11" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=11" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy Admin - Manage Products</title>
  <link rel="stylesheet" href="../assets/admin/css/manage-products.css?v=20260517">
</head>
<body>
<div class="layout-wrapper">
  <?php $active = 'products'; include __DIR__ . '/sidebar.php'; ?>
  <main class="main-content">
    <?php include __DIR__ . '/topbar.php'; ?>
    <div class="page-body product-admin-page">
      <section class="mp-hero">
        <div>
          <p class="mp-kicker">Product approval</p>
          <h1 class="mp-title">Manage Products</h1>
        </div>
        <span class="mp-hero-pill"><?= mp_h(date('D, d M Y')) ?></span>
      </section>

      <?php if ($notice): ?><div class="mp-alert success">✓ <?= mp_h($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="mp-alert error">! <?= mp_h($error) ?></div><?php endif; ?>
      <?php if (!$productReady): ?><div class="mp-alert error">PRODUCT table was not found.</div><?php endif; ?>
      <?php if ($productReady && !$hasApproval): ?>
        <div class="mp-alert warn">
          <div>
            PRODUCT.ADMIN_APPROVAL_STATUS is missing. Run this once:
            <code class="mp-sql">ALTER TABLE PRODUCT ADD (ADMIN_APPROVAL_STATUS VARCHAR2(20) DEFAULT 'PENDING' NOT NULL);
ALTER TABLE PRODUCT ADD CONSTRAINT chk_product_admin_approval CHECK (ADMIN_APPROVAL_STATUS IN ('PENDING', 'APPROVED', 'REJECTED'));</code>
          </div>
        </div>
      <?php endif; ?>

      <div class="mp-stats" role="group" aria-label="Product filters">
        <button class="mp-stat is-active" type="button" data-filter="all"><div class="mp-stat-value"><?= (int)$totalProducts ?></div><div class="mp-stat-label">All Products</div></button>
        <button class="mp-stat" type="button" data-filter="pending"><div class="mp-stat-value"><?= (int)$pendingProducts ?></div><div class="mp-stat-label">Pending Approval</div></button>
        <button class="mp-stat" type="button" data-filter="approved"><div class="mp-stat-value"><?= (int)$approvedProducts ?></div><div class="mp-stat-label">Approved</div></button>
        <button class="mp-stat" type="button" data-filter="rejected"><div class="mp-stat-value"><?= (int)$rejectedProducts ?></div><div class="mp-stat-label">Rejected</div></button>
        <button class="mp-stat" type="button" data-filter="hidden"><div class="mp-stat-value"><?= (int)$hiddenProducts ?></div><div class="mp-stat-label">Hidden</div></button>
      </div>

      <div class="mp-tools">
        <div class="mp-search"><input id="productSearch" type="search" placeholder="Search product, shop, trader, category, ID..."></div>
        <span class="mp-hero-pill" id="visibleCount"><?= (int)$totalProducts ?> showing</span>
      </div>

      <div class="mp-table-card">
        <div class="mp-table-wrap">
          <table class="mp-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Shop / Trader</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Approval</th>
                <th>Visibility</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="productRows">
            <?php foreach ($products as $product):
                $approval = mp_status_label($product['ADMIN_APPROVAL_STATUS'] ?? 'PENDING');
                $approvalClass = mp_status_class($approval);
                $isActive = (int)($product['IS_ACTIVE'] ?? 1) === 1;
                $search = strtolower(implode(' ', [
                    $product['PRODUCT_ID'] ?? '',
                    $product['PRODUCT_NAME'] ?? '',
                    $product['SHOP_NAME'] ?? '',
                    $product['TRADER_NAME'] ?? '',
                    $product['CATEGORY_NAME'] ?? '',
                    $approval,
                ]));
            ?>
              <tr data-status="<?= mp_h(strtolower($approval)) ?>" data-hidden="<?= $isActive ? '0' : '1' ?>" data-search="<?= mp_h($search) ?>">
                <td>
                  <div class="mp-product">
                    <div class="mp-thumb"><img src="<?= mp_h(mp_product_image_src($product['PRODUCT_IMAGE'] ?? '')) ?>" alt="" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"></div>
                    <div>
                      <div class="mp-name"><?= mp_h($product['PRODUCT_NAME'] ?? 'Product') ?></div>
                      <div class="mp-id"><?= mp_h($product['PRODUCT_ID'] ?? '') ?></div>
                      <?php $desc = mp_lob_text($product['DESCRIPTION'] ?? '', 95); if ($desc !== ''): ?><div class="mp-desc"><?= mp_h($desc) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td>
                  <strong><?= mp_h($product['SHOP_NAME'] ?? 'Unknown shop') ?></strong>
                  <span class="mp-small"><?= mp_h($product['TRADER_NAME'] ?? $product['TRADER_ID'] ?? 'Unknown trader') ?></span>
                  <span class="mp-small">Shop: <?= mp_h($product['SHOP_STATUS'] ?? 'PENDING') ?></span>
                </td>
                <td><?= mp_h($product['CATEGORY_NAME'] ?? 'Uncategorised') ?></td>
                <td><span class="mp-price"><?= mp_h(admin_money($product['ITEM_PRICE'] ?? 0)) ?></span></td>
                <td><?= (int)($product['STOCK_AVAILABLE'] ?? 0) ?></td>
                <td><span class="badge <?= mp_h($approvalClass) ?>"><?= mp_h($approval) ?></span></td>
                <td><span class="badge <?= $isActive ? 'active' : 'hidden' ?>"><?= $isActive ? 'ACTIVE' : 'HIDDEN' ?></span></td>
                <td>
                  <div class="mp-actions">
                    <?php if ($hasApproval): ?>
                      <?php if ($approval !== 'APPROVED'): ?>
                        <form class="mp-form" method="POST"><input type="hidden" name="action" value="set_approval"><input type="hidden" name="product_id" value="<?= mp_h($product['PRODUCT_ID']) ?>"><input type="hidden" name="approval_status" value="APPROVED"><button class="mp-btn approve" type="submit">Approve</button></form>
                      <?php endif; ?>
                      <?php if ($approval !== 'REJECTED'): ?>
                        <form class="mp-form" method="POST" onsubmit="return confirm('Reject this product?');"><input type="hidden" name="action" value="set_approval"><input type="hidden" name="product_id" value="<?= mp_h($product['PRODUCT_ID']) ?>"><input type="hidden" name="approval_status" value="REJECTED"><button class="mp-btn reject" type="submit">Reject</button></form>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($hasActive): ?>
                      <form class="mp-form" method="POST"><input type="hidden" name="action" value="set_active"><input type="hidden" name="product_id" value="<?= mp_h($product['PRODUCT_ID']) ?>"><input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>"><button class="mp-btn" type="submit"><?= $isActive ? 'Hide' : 'Unhide' ?></button></form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mp-empty" id="emptyProducts">No products match this filter.</div>
      </div>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
  </main>
</div>
<script src="../assets/admin/js/manage-products.js?v=20260517"></script>
</body>
</html>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function db_value($value) {
    return $value instanceof OCILob ? $value->load() : $value;
}

function db_exec($conn, $sql, $binds = []) {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        exit(htmlspecialchars(shoplocalfy_db_error_message($conn, 'Could not prepare wishlist request.'), ENT_QUOTES, 'UTF-8'));
    }

    $localBinds = [];

    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim($key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!oci_execute($stmt)) {
        exit(htmlspecialchars(shoplocalfy_db_error_message($stmt, 'Could not load wishlist.'), ENT_QUOTES, 'UTF-8'));
    }

    return $stmt;
}

function db_all($conn, $sql, $binds = []) {
    $stmt = db_exec($conn, $sql, $binds);
    $rows = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }

    return $rows;
}

function db_try_one($conn, $sql, $binds = []) {
    $stmt = @oci_parse($conn, $sql);

    if (!$stmt) {
        return null;
    }

    $localBinds = [];

    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim($key, ':');
        $localBinds[$bindName] = $value;
        @oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!@oci_execute($stmt)) {
        return null;
    }

    $row = oci_fetch_assoc($stmt);
    return $row ?: null;
}

function db_column_exists($conn, $table, $column) {
    $row = db_try_one(
        $conn,
        "
        SELECT COUNT(*) AS cnt
        FROM user_tab_columns
        WHERE table_name = UPPER(:table_name)
        AND column_name = UPPER(:column_name)
        ",
        [
            'table_name' => $table,
            'column_name' => $column
        ]
    );

    return $row && (int)$row['CNT'] > 0;
}

function get_product_rating($conn, $product_id) {
    if (
        !db_column_exists($conn, 'REVIEW', 'PRODUCT_ID') ||
        !db_column_exists($conn, 'REVIEW', 'RATING')
    ) {
        return [
            'avg' => 0,
            'count' => 0
        ];
    }

    // Do not count suspended/hidden/rejected reviews in customer-facing ratings.
    // This supports the common review status column names used in this project.
    $conditions = ["product_id = :product_id"];

    foreach (['APPROVAL_STATUS', 'APPROVAL_STATU', 'APPROVED_STATUS', 'STATUS', 'REVIEW_STATUS'] as $statusColumn) {
        if (db_column_exists($conn, 'REVIEW', $statusColumn)) {
            $conditions[] = "NVL(UPPER(TRIM($statusColumn)), 'ACTIVE') IN ('YES', 'Y', 'APPROVED', 'ACTIVE')";
            break;
        }
    }

    if (db_column_exists($conn, 'REVIEW', 'IS_ACTIVE')) {
        $conditions[] = "NVL(UPPER(TRIM(IS_ACTIVE)), 'Y') IN ('Y', 'YES', 'ACTIVE', '1')";
    }

    if (db_column_exists($conn, 'REVIEW', 'ACTIVE_STATUS')) {
        $conditions[] = "NVL(UPPER(TRIM(ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'";
    }

    if (db_column_exists($conn, 'REVIEW', 'IS_SUSPENDED')) {
        $conditions[] = "NVL(UPPER(TRIM(IS_SUSPENDED)), 'N') NOT IN ('Y', 'YES', '1', 'TRUE')";
    }

    $whereSql = implode(" AND ", $conditions);

    $row = db_try_one(
        $conn,
        "
        SELECT
            NVL(AVG(rating), 0) AS avg_rating,
            COUNT(rating) AS review_count
        FROM review
        WHERE $whereSql
        ",
        ['product_id' => $product_id]
    );

    if (!$row) {
        return [
            'avg' => 0,
            'count' => 0
        ];
    }

    return [
        'avg' => round((float)db_value($row['AVG_RATING']), 1),
        'count' => (int)db_value($row['REVIEW_COUNT'])
    ];
}

function get_active_discount_percentage($conn, $product_id) {
    if (
        !db_column_exists($conn, 'DISCOUNT', 'PRODUCT_ID') ||
        !db_column_exists($conn, 'DISCOUNT', 'DISCOUNT_PERCENTAGE')
    ) {
        return 0;
    }

    $conditions = ["product_id = :product_id"];

    if (db_column_exists($conn, 'DISCOUNT', 'START_DATE')) {
        $conditions[] = "(start_date IS NULL OR TRUNC(SYSDATE) >= TRUNC(start_date))";
    }

    if (db_column_exists($conn, 'DISCOUNT', 'END_DATE')) {
        $conditions[] = "(end_date IS NULL OR TRUNC(SYSDATE) <= TRUNC(end_date))";
    }

    $whereSql = implode(" AND ", $conditions);

    $row = db_try_one(
        $conn,
        "
        SELECT NVL(MAX(discount_percentage), 0) AS discount_percentage
        FROM discount
        WHERE $whereSql
        ",
        ['product_id' => $product_id]
    );

    if (!$row) {
        return 0;
    }

    $discount = (float)db_value($row['DISCOUNT_PERCENTAGE']);
    return max(0, min(100, $discount));
}

function final_price($conn, $product_id, $base_price) {
    $discount = get_active_discount_percentage($conn, $product_id);
    $price = (float)$base_price;

    if ($discount <= 0) {
        return round($price, 2);
    }

    return round(max(0, $price - ($price * $discount / 100)), 2);
}

function product_image_src($image) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $image = db_value($image);
    $image = trim(str_replace('\\', '/', (string)($image ?? '')));

    if ($image === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $image)) return $image;

    if (strpos($image, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $image) ? '../' . $image : $placeholder;
    }

    if (strpos($image, '/') !== false) {
        $clean = ltrim(preg_replace('#^(\.\./)+#', '', $image), '/');
        if (is_file(dirname(__DIR__) . '/' . $clean)) return '../' . $clean;
    }

    $file = dirname(__DIR__) . '/uploads/products/' . basename($image);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($image)) : $placeholder;
}

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
$customer_id = $sessionRole !== '' && $sessionRole !== 'CUSTOMER'
    ? null
    : ($_SESSION['customer_id'] ?? $_SESSION['CUSTOMER_ID'] ?? null);

if (!$customer_id && $sessionRole === 'CUSTOMER') {
    $customer_id = $_SESSION['user_id'] ?? $_SESSION['USER_ID'] ?? null;
}

$customer_id = strtoupper(trim((string)$customer_id));
$customerAccount = null;
if ($customer_id !== '') {
    $customerAccount = db_try_one(
        $conn,
        <<<'SQL'
        SELECT c.USER_ID
        FROM CUSTOMER c
        INNER JOIN "USER" u ON u.USER_ID = c.USER_ID
        WHERE c.USER_ID = :customer_id
          AND UPPER(TRIM(u.USER_ROLE)) = 'CUSTOMER'
          AND NVL(UPPER(TRIM(u.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
SQL,
        ['customer_id' => $customer_id]
    );
}

if (!$customerAccount) {
    header('Location: login.php?redirect=wishlist.php');
    exit;
}

$customer_id = $customerAccount['USER_ID'];


$wishlistVisibilitySql = '';
if (function_exists('customer_public_product_filter')) {
    $wishlistVisibilitySql = ' AND ' . customer_public_product_filter('p', 's');
} else {
    $wishlistVisibility = [];
    if (db_column_exists($conn, 'PRODUCT', 'IS_ACTIVE')) {
        $wishlistVisibility[] = 'NVL(p.is_active, 1) = 1';
    }
    if (db_column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS')) {
        $wishlistVisibility[] = "NVL(UPPER(TRIM(p.admin_approval_status)), 'PENDING') = 'APPROVED'";
    }
    if (db_column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE')) {
        $wishlistVisibility[] = 'NVL(p.stock_available, 0) > 0';
    }
    if (db_column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
        $wishlistVisibility[] = "NVL(UPPER(TRIM(s.approval_status)), 'PENDING') = 'APPROVED'";
    }
    if (db_column_exists($conn, 'SHOP', 'TRADER_ID')) {
        $wishlistVisibility[] = <<<'SQL'
EXISTS (
    SELECT 1
    FROM TRADER t
    INNER JOIN "USER" tu ON tu.USER_ID = t.USER_ID
    WHERE t.USER_ID = s.TRADER_ID
      AND NVL(UPPER(TRIM(t.VERIFIED_STATUS)), 'PENDING') = 'VERIFIED'
      AND NVL(UPPER(TRIM(tu.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
)
SQL;
    }
    $wishlistVisibilitySql = $wishlistVisibility ? ' AND ' . implode(' AND ', $wishlistVisibility) : '';
}

$wishlistRows = db_all(
    $conn,
    "
    SELECT
        w.wishlist_id,
        wi.product_id,
        p.product_name,
        p.item_price,
        p.product_image,
        p.quantity_per_item,
        NVL(s.shop_name, 'Local Shop') AS shop_name
    FROM wishlist w
    JOIN wishlist_item wi
        ON wi.wishlist_id = w.wishlist_id
    JOIN product p
        ON p.product_id = wi.product_id
    LEFT JOIN shop s
        ON s.shop_id = p.shop_id
    WHERE w.customer_id = :customer_id
      $wishlistVisibilitySql
    ORDER BY w.created_date DESC
    ",
    ['customer_id' => $customer_id]
);

$wishlistItems = [];

foreach ($wishlistRows as $row) {
    $product_id = db_value($row['PRODUCT_ID']);
    $base_price = (float)db_value($row['ITEM_PRICE']);
    $price = final_price($conn, $product_id, $base_price);
    $discount_percentage = get_active_discount_percentage($conn, $product_id);
    $rating = get_product_rating($conn, $product_id);

    $wishlistItems[] = [
        'wishlist_id' => db_value($row['WISHLIST_ID']),
        'product_id' => $product_id,
        'product_url' => 'product-detail.php?id=' . rawurlencode((string)$product_id),
        'name' => db_value($row['PRODUCT_NAME']),
        'shop_name' => db_value($row['SHOP_NAME']),
        'quantity_per_item' => db_value($row['QUANTITY_PER_ITEM']),
        'image' => product_image_src($row['PRODUCT_IMAGE']),
        'base_price' => $base_price,
        'price' => $price,
        'discount_percentage' => $discount_percentage,
        'rating' => $rating['avg'],
        'reviews' => $rating['count']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Wishlist – ShopLocalfy</title>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/customer/css/wishlist.css?v=20260517">
</head>

<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="breadcrumb">
    <a href="index.php">Home</a>
    <span>/</span>
    <strong style="color:var(--text)">Wishlist</strong>
</div>

<main class="wishlist-page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Wishlist</h1>
            <p class="page-subtitle">
                <?= count($wishlistItems) ?> saved product<?= count($wishlistItems) === 1 ? '' : 's' ?>
            </p>
        </div>

        <a href="index.php" class="browse-link">
            Browse Products
        </a>
    </div>

    <?php if (empty($wishlistItems)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-heart"></i>
            <h3>Your wishlist is empty</h3>
            <p>Products added to your wishlist will show here.</p>
            <a href="index.php" class="browse-link">Browse Products</a>
        </div>
    <?php else: ?>
        <div class="wishlist-grid">
            <?php foreach ($wishlistItems as $item): ?>
                <article
                    class="wishlist-card"
                    role="link"
                    tabindex="0"
                    data-href="<?= e($item['product_url']) ?>"
                    onclick="if (!event.target.closest('form, button, a')) { window.location = this.dataset.href; }"
                    onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('form, button, a')) { event.preventDefault(); window.location = this.dataset.href; }"
                >
                    <div class="image-wrap">
                        <img
                            src="<?= e($item['image']) ?>"
                            alt="<?= e($item['name']) ?>"
                            class="product-img"
                            onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"
                        >

                        <?php if ($item['discount_percentage'] > 0): ?>
                            <span class="discount-badge">
                                <?= e($item['discount_percentage']) ?>% OFF
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="shop-name"><?= e($item['shop_name']) ?></div>
                        <a class="product-name" href="<?= e($item['product_url']) ?>">
                            <?= e($item['name']) ?>
                        </a>

                        <?php if (!empty($item['quantity_per_item'])): ?>
                            <div class="quantity-text"><?= e($item['quantity_per_item']) ?></div>
                        <?php endif; ?>

                        <div class="rating-row">
                            <i class="fa-solid fa-star"></i>
                            <?php if ($item['reviews'] > 0): ?>
                                <?= e($item['rating']) ?>
                                <span>(<?= e($item['reviews']) ?>)</span>
                            <?php else: ?>
                                <span>No reviews yet</span>
                            <?php endif; ?>
                        </div>

                        <div class="price-row">
                            <span class="price">£<?= number_format($item['price'], 2) ?></span>

                            <?php if ($item['discount_percentage'] > 0 && $item['base_price'] > $item['price']): ?>
                                <span class="old-price">£<?= number_format($item['base_price'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-actions">
                        <form method="POST" action="cart_action.php">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="product_id" value="<?= e($item['product_id']) ?>">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="redirect" value="wishlist.php">

                            <button type="submit" class="add-cart-btn">
                                Add to Cart
                            </button>
                        </form>

                        <form method="POST" action="wishlist_action.php">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= e($item['product_id']) ?>">
                            <input type="hidden" name="redirect" value="wishlist.php">

                            <button type="submit" class="remove-wishlist-btn" title="Remove from wishlist">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>

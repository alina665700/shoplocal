<?php
require_once __DIR__ . '/customer_common.php';

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function shop_money($amount)
{
    return '£' . number_format(max(0, (float)$amount), 2);
}

function shop_db_value($value)
{
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        return $loaded === false ? '' : $loaded;
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return (string)($value ?? '');
}

function shop_text($value, $fallback = '')
{
    $text = trim((string)shop_db_value($value));
    return $text !== '' ? $text : $fallback;
}

function shop_clean_text($value, $fallback = '')
{
    $text = shop_db_value($value);
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(strip_tags($text));

    if ($text === '') {
        return $fallback;
    }

    if (stripos($text, 'Fatal error') !== false || stripos($text, 'Stack trace') !== false || stripos($text, 'OCILob') !== false) {
        return $fallback;
    }

    return $text;
}

function shop_detect_image_mime($bytes)
{
    if ($bytes === '' || $bytes === null) {
        return '';
    }

    if (substr($bytes, 0, 2) === "\xFF\xD8") return 'image/jpeg';
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
    if (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a') return 'image/gif';
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);
            if (is_string($detected) && preg_match('/^image\//', $detected)) {
                return $detected;
            }
        }
    }

    return '';
}

function shop_encode_url_path($path)
{
    $path = str_replace('\\', '/', (string)$path);
    if (preg_match('/^https?:\/\//i', $path) || preg_match('/^data:image\//i', $path)) {
        return $path;
    }

    $prefix = '';
    if (strpos($path, '/') === 0) {
        $prefix = '/';
        $path = ltrim($path, '/');
    }

    $parts = array_map('rawurlencode', array_filter(explode('/', $path), 'strlen'));
    return $prefix . implode('/', $parts);
}

function shop_file_to_url($filePath)
{
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $realPath = realpath($filePath);

    if (!$docRoot || !$realPath) {
        return '';
    }

    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $realPath = str_replace('\\', '/', $realPath);

    if (stripos($realPath, $docRoot) === 0) {
        $relative = substr($realPath, strlen($docRoot));
        return shop_encode_url_path('/' . ltrim($relative, '/'));
    }

    return '';
}

function shop_project_url_root()
{
    $root = shop_file_to_url(dirname(__DIR__));
    return $root !== '' ? rtrim($root, '/') . '/' : '';
}

function shop_product_image_src($value)
{
    $raw = shop_db_value($value);

    if ($raw === '' || $raw === null) {
        return '../uploads/products/product-placeholder.svg';
    }

    $mime = shop_detect_image_mime($raw);
    if ($mime !== '') {
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    $src = trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $src = trim($src, " \t\n\r\0\x0B\"'");

    if ($src === '') {
        return '../uploads/products/product-placeholder.svg';
    }

    if (preg_match('/^data:image\//i', $src) || preg_match('/^https?:\/\//i', $src)) {
        return $src;
    }

    $compactBase64 = preg_replace('/\s+/', '', $src);
    if (strlen($compactBase64) > 80 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compactBase64)) {
        $decoded = base64_decode($compactBase64, true);
        if ($decoded !== false) {
            $decodedMime = shop_detect_image_mime($decoded);
            if ($decodedMime !== '') {
                return 'data:' . $decodedMime . ';base64,' . base64_encode($decoded);
            }
        }
    }

    $normalized = str_replace('\\', '/', $src);
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($docRoot !== '' && stripos($normalized, $docRoot) === 0) {
        return shop_encode_url_path('/' . ltrim(substr($normalized, strlen($docRoot)), '/'));
    }

    $htdocsPos = stripos($normalized, '/htdocs/');
    if ($htdocsPos !== false) {
        return shop_encode_url_path('/' . substr($normalized, $htdocsPos + 8));
    }

    $baseDir = dirname(__DIR__);
    $clean = ltrim(preg_replace('#^(\.\./)+#', '', $normalized), '/');
    $baseName = basename($clean);

    $candidates = [
        __DIR__ . '/' . $normalized,
        $baseDir . '/' . $clean,
        $baseDir . '/customer/' . $clean,
        $baseDir . '/trader/' . $clean,
        $baseDir . '/uploads/' . $baseName,
        $baseDir . '/uploads/products/' . $baseName,
        $baseDir . '/product_images/' . $baseName,
        $baseDir . '/assets/images/' . $baseName,
        $baseDir . '/assets/images/products/' . $baseName,
        $baseDir . '/customer/assets/images/' . $baseName,
        $baseDir . '/customer/assets/images/products/' . $baseName,
        $baseDir . '/trader/uploads/' . $baseName,
        $baseDir . '/trader/uploads/products/' . $baseName,
        $baseDir . '/trader/assets/images/' . $baseName,
        $baseDir . '/trader/assets/images/products/' . $baseName,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $url = shop_file_to_url($candidate);
            if ($url !== '') {
                return $url;
            }
        }
    }

    $projectRoot = shop_project_url_root();
    if ($projectRoot !== '' && preg_match('#^(trader|customer|assets|uploads)/#i', $clean)) {
        return $projectRoot . shop_encode_url_path($clean);
    }

    return '../uploads/products/product-placeholder.svg';
}

function shop_render_stars(float $rating, int $size = 16): string
{
    $rating = max(0, min(5, $rating));
    $full = (int)floor($rating);
    $half = ($rating - $full) >= 0.5;
    $empty = 5 - $full - ($half ? 1 : 0);
    $id = 'sg' . uniqid();
    $out = '';

    for ($i = 0; $i < $full; $i++) {
        $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='#f5a623' stroke='#f5a623' stroke-width='1'><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
    }

    if ($half) {
        $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='url(#$id)' stroke='#f5a623' stroke-width='1'><defs><linearGradient id='$id'><stop offset='50%' stop-color='#f5a623'/><stop offset='50%' stop-color='transparent'/></linearGradient></defs><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
    }

    for ($i = 0; $i < $empty; $i++) {
        $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='none' stroke='#f5a623' stroke-width='1'><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
    }

    return $out;
}

$shop_id = trim((string)(
    $_GET['id']
    ?? $_GET['shop_id']
    ?? $_GET['SHOP_ID']
    ?? ''
));

$page_error = '';
$shop = [
    'id' => $shop_id,
    'name' => 'Shop not found',
    'description' => '',
    'rating' => 0,
    'reviews' => 0,
    'product_count' => 0,
];
$products = [];

try {
    if ($shop_id === '') {
        throw new Exception('Invalid shop ID. Open this page from a shop name link.');
    }

    if (!$conn || !table_exists($conn, 'SHOP') || !table_exists($conn, 'PRODUCT')) {
        throw new Exception('Shop or product table is missing.');
    }

    $shopDescriptionSelect = 'NULL AS SHOP_DESCRIPTION';
    foreach (['SHOP_DESCRIPTION', 'DESCRIPTION', 'ABOUT', 'SHOP_ABOUT'] as $shopDescriptionColumn) {
        if (column_exists($conn, 'SHOP', $shopDescriptionColumn)) {
            $shopDescriptionSelect = 's.' . $shopDescriptionColumn . ' AS SHOP_DESCRIPTION';
            break;
        }
    }

    $shopVisibilityConditions = [];

    if (column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
        $shopVisibilityConditions[] = "NVL(UPPER(TRIM(s.APPROVAL_STATUS)), 'PENDING') = 'APPROVED'";
    }

    if (
        table_exists($conn, 'TRADER')
        && table_exists($conn, 'USER')
        && column_exists($conn, 'SHOP', 'TRADER_ID')
        && column_exists($conn, 'TRADER', 'USER_ID')
    ) {
        $traderChecks = [];

        if (column_exists($conn, 'TRADER', 'VERIFIED_STATUS')) {
            $traderChecks[] = "NVL(UPPER(TRIM(t.VERIFIED_STATUS)), 'PENDING') = 'VERIFIED'";
        }

        if (column_exists($conn, 'USER', 'ACTIVE_STATUS')) {
            $traderChecks[] = "NVL(UPPER(TRIM(u.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'";
        }

        if ($traderChecks) {
            $shopVisibilityConditions[] = "EXISTS (
            SELECT 1
            FROM TRADER t
            INNER JOIN \"USER\" u ON u.USER_ID = t.USER_ID
            WHERE t.USER_ID = s.TRADER_ID
              AND " . implode(' AND ', $traderChecks) . "
        )";
        }
    }

    $shopVisibilityFilter = $shopVisibilityConditions
        ? ' AND ' . implode(' AND ', $shopVisibilityConditions)
        : '';

    $shopRows = db_all($conn, "
        SELECT
            s.SHOP_ID,
            s.SHOP_NAME,
            $shopDescriptionSelect
        FROM SHOP s
        WHERE s.SHOP_ID = :shop_id
          $shopVisibilityFilter
    ", [':shop_id' => $shop_id]);

    if (empty($shopRows)) {
        throw new Exception('Shop not found. Check that the URL contains the exact SHOP_ID.');
    }

    $shopRow = $shopRows[0];

    $imageSelect = 'NULL AS PRODUCT_IMAGE';
    foreach (['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_PATH', 'PRODUCT_IMAGE_PATH', 'PRODUCT_PHOTO', 'PHOTO', 'PICTURE', 'PRODUCT_PICTURE'] as $imageColumn) {
        if (column_exists($conn, 'PRODUCT', $imageColumn)) {
            $imageSelect = 'p.' . $imageColumn . ' AS PRODUCT_IMAGE';
            break;
        }
    }

    $hasCategory = table_exists($conn, 'CATEGORY');
    $categoryJoin = $hasCategory ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID' : '';
    $categorySelect = $hasCategory ? 'c.CATEGORY_NAME AS CATEGORY_NAME' : 'NULL AS CATEGORY_NAME';

    $discountJoin = '';
    $discountSelect = "
        0 AS DISCOUNT_PERCENTAGE,
        p.ITEM_PRICE AS FINAL_PRICE,
        0 AS HAS_DISCOUNT";

    if (table_exists($conn, 'DISCOUNT')) {
        $discountJoin = "
        LEFT JOIN (
            SELECT
                product_id,
                MAX(discount_percentage) AS discount_percentage
            FROM DISCOUNT
            WHERE discount_percentage IS NOT NULL
              AND discount_percentage > 0
              AND discount_percentage <= 100
              AND start_date IS NOT NULL
              AND end_date IS NOT NULL
              AND end_date > start_date
              AND TRUNC(SYSDATE) BETWEEN TRUNC(start_date) AND TRUNC(end_date)
            GROUP BY product_id
        ) d ON d.PRODUCT_ID = p.PRODUCT_ID";

        $discountSelect = "
            NVL(d.DISCOUNT_PERCENTAGE, 0) AS DISCOUNT_PERCENTAGE,
            CASE
                WHEN NVL(d.DISCOUNT_PERCENTAGE, 0) > 0 THEN
                    GREATEST(0, ROUND(p.ITEM_PRICE - (p.ITEM_PRICE * d.DISCOUNT_PERCENTAGE / 100), 2))
                ELSE
                    p.ITEM_PRICE
            END AS FINAL_PRICE,
            CASE
                WHEN NVL(d.DISCOUNT_PERCENTAGE, 0) > 0 THEN 1
                ELSE 0
            END AS HAS_DISCOUNT";
    }

    $reviewAvailable = table_exists($conn, 'REVIEW')
        && column_exists($conn, 'REVIEW', 'PRODUCT_ID')
        && column_exists($conn, 'REVIEW', 'RATING');

    $reviewApprovalFilter = '';
    $reviewApprovalJoinFilter = '';
    if ($reviewAvailable && column_exists($conn, 'REVIEW', 'APPROVAL_STATUS')) {
        $reviewApprovalFilter = " AND NVL(r.APPROVAL_STATUS, 'YES') = 'YES'";
        $reviewApprovalJoinFilter = " AND NVL(r.APPROVAL_STATUS, 'YES') = 'YES'";
    }

    $productRatingSelect = $reviewAvailable
        ? "(SELECT NVL(ROUND(AVG(r.RATING), 1), 0) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID" . $reviewApprovalFilter . ") AS PRODUCT_RATING,
           (SELECT COUNT(r.RATING) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID" . $reviewApprovalFilter . ") AS PRODUCT_REVIEW_COUNT"
        : "0 AS PRODUCT_RATING,
           0 AS PRODUCT_REVIEW_COUNT";

    $publicProductFilter = function_exists('customer_public_product_filter')
        ? customer_public_product_filter('p', 's')
        : '1 = 1';

    if ($reviewAvailable) {
        $ratingRows = db_all($conn, "
            SELECT
                NVL(ROUND(AVG(r.RATING), 1), 0) AS AVG_RATING,
                COUNT(r.RATING) AS REVIEW_COUNT
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            LEFT JOIN REVIEW r ON r.PRODUCT_ID = p.PRODUCT_ID" . $reviewApprovalJoinFilter . "
            WHERE p.SHOP_ID = :shop_id
              AND $publicProductFilter
        ", [':shop_id' => $shop_id]);

        if (!empty($ratingRows)) {
            $shop['rating'] = (float)($ratingRows[0]['AVG_RATING'] ?? 0);
            $shop['reviews'] = (int)($ratingRows[0]['REVIEW_COUNT'] ?? 0);
        }
    }

    $productRows = db_all($conn, "
        SELECT
            p.PRODUCT_ID,
            p.PRODUCT_NAME,
            p.DESCRIPTION,
            p.ITEM_PRICE,
            p.ALLERGY_INFO,
            $imageSelect,
            $categorySelect,
            $discountSelect,
            $productRatingSelect
        FROM PRODUCT p
        INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        $categoryJoin
        $discountJoin
        WHERE p.SHOP_ID = :shop_id
          AND $publicProductFilter
          AND p.ITEM_PRICE IS NOT NULL
          AND p.ITEM_PRICE >= 0
          AND (p.MIN_ORDER IS NULL OR p.MAX_ORDER IS NULL OR p.MIN_ORDER <= p.MAX_ORDER)
        ORDER BY p.PRODUCT_ID DESC
    ", [':shop_id' => $shop_id]);

    foreach ($productRows as $row) {
        $basePrice = max(0, (float)($row['ITEM_PRICE'] ?? 0));
        $finalPrice = max(0, (float)($row['FINAL_PRICE'] ?? $basePrice));
        $discountPercent = max(0, min(100, (float)($row['DISCOUNT_PERCENTAGE'] ?? 0)));
        $hasDiscount = ((int)($row['HAS_DISCOUNT'] ?? 0) === 1) && $discountPercent > 0 && $finalPrice < $basePrice;

        $products[] = [
            'id' => shop_text($row['PRODUCT_ID'] ?? '', ''),
            'name' => shop_text($row['PRODUCT_NAME'] ?? '', 'Product'),
            'description' => shop_clean_text($row['DESCRIPTION'] ?? '', ''),
            'category' => shop_text($row['CATEGORY_NAME'] ?? '', ''),
            'allergy' => shop_text($row['ALLERGY_INFO'] ?? '', ''),
            'image' => shop_product_image_src($row['PRODUCT_IMAGE'] ?? ''),
            'price' => $finalPrice,
            'old_price' => $basePrice,
            'has_discount' => $hasDiscount,
            'discount_percent' => $discountPercent,
            'rating' => (float)($row['PRODUCT_RATING'] ?? 0),
            'reviews' => (int)($row['PRODUCT_REVIEW_COUNT'] ?? 0),
        ];
    }

    $shop['id'] = shop_text($shopRow['SHOP_ID'] ?? $shop_id, $shop_id);
    $shop['name'] = shop_text($shopRow['SHOP_NAME'] ?? '', 'Shop');
    $shop['description'] = shop_clean_text($shopRow['SHOP_DESCRIPTION'] ?? '', '');
    $shop['product_count'] = count($products);
} catch (Throwable $e) {
    $page_error = shoplocalfy_public_exception_message($e, 'Could not load shop details.');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
    <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
    <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($shop['name']) ?> – ShopLocalfy</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/customer/css/shop_details.css?v=20260517">
</head>

<body>

    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container">
        <?php if ($page_error !== ''): ?>
            <div class="error-box"><?= e($page_error) ?></div>
        <?php endif; ?>

        <section class="shop-hero">
            <div>
                <span class="eyebrow">Shop details</span>
                <h1 class="shop-title"><?= e($shop['name']) ?></h1>
                <?php if ($shop['description'] !== ''): ?>
                    <p class="shop-desc"><?= e($shop['description']) ?></p>
                <?php else: ?>
                    <p class="shop-desc">Browse every product currently listed by this shop.</p>
                <?php endif; ?>
            </div>

            <div class="shop-stats">
                <div class="stat-card">
                    <div class="stat-label">Shop rating</div>
                    <div class="stat-value">
                        <?= number_format((float)$shop['rating'], 1) ?>
                        <span class="stars"><?= shop_render_stars((float)$shop['rating'], 15) ?></span>
                    </div>
                    <div class="section-sub"><?= (int)$shop['reviews'] ?> product review<?= (int)$shop['reviews'] === 1 ? '' : 's' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Products</div>
                    <div class="stat-value"><?= (int)$shop['product_count'] ?></div>
                    <div class="section-sub">listed by this shop</div>
                </div>
            </div>
        </section>

        <div class="section-head">
            <div>
                <h2 class="section-title">Products by <?= e($shop['name']) ?></h2>
                <p class="section-sub">Click any product to view its full details.</p>
            </div>
        </div>

        <section class="product-grid">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <a class="product-card" href="product-detail.php?id=<?= e(rawurlencode($product['id'])) ?>">
                        <?php if (!empty($product['has_discount'])): ?>
                            <span class="discount-badge"><?= e(rtrim(rtrim(number_format($product['discount_percent'], 2), '0'), '.')) ?>% OFF</span>
                        <?php endif; ?>

                        <div class="product-img">
                            <?php if (!empty($product['image'])): ?>
                                <img
                                    src="<?= e($product['image']) ?>"
                                    alt="<?= e($product['name']) ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none'; var p=this.parentElement.querySelector('.no-img'); if(p){p.style.display='inline';}" />
                            <?php endif; ?>
                            <span class="no-img" style="<?= !empty($product['image']) ? 'display:none;' : '' ?>">🛒</span>
                        </div>

                        <div class="product-body">
                            <h3 class="product-name"><?= e($product['name']) ?></h3>

                            <div class="product-rating">
                                <span class="stars"><?= shop_render_stars((float)$product['rating'], 13) ?></span>
                                <span><?= number_format((float)$product['rating'], 1) ?> (<?= (int)$product['reviews'] ?>)</span>
                            </div>

                            <div class="meta-tags">
                                <?php if ($product['category'] !== ''): ?>
                                    <span class="tag"><?= e($product['category']) ?></span>
                                <?php endif; ?>
                                <?php if ($product['allergy'] !== ''): ?>
                                    <span class="tag">Allergy: <?= e($product['allergy']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($product['has_discount'])): ?>
                                    <span class="tag"><?= e(rtrim(rtrim(number_format($product['discount_percent'], 2), '0'), '.')) ?>% OFF</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($product['description'] !== ''): ?>
                                <p class="product-desc"><?= e($product['description']) ?></p>
                            <?php else: ?>
                                <p class="product-desc">No description has been added for this product.</p>
                            <?php endif; ?>

                            <div class="price-row">
                                <span class="price-main"><?= e(shop_money($product['price'])) ?></span>
                                <?php if (!empty($product['has_discount'])): ?>
                                    <span class="price-old"><?= e(shop_money($product['old_price'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-card">
                    <h3>No products found</h3>
                    <p>This shop does not currently have products listed.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>

</body>

</html>
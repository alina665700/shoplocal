<?php
require_once __DIR__ . '/customer_common.php';

$categories = [];
$products = [];
$selectedCategory = null;
$db_error = '';

$selectedCategoryId = trim((string)($_GET['category_id'] ?? ''));
$selectedCategoryName = trim((string)($_GET['category'] ?? ''));

function category_page_db_value($value) {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        return $loaded === false ? '' : $loaded;
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return (string)($value ?? '');
}

function category_page_text($value, $fallback = '') {
    $text = trim((string)category_page_db_value($value));
    return $text !== '' ? $text : $fallback;
}

function category_page_clean_text($value, $fallback = '') {
    $text = category_page_db_value($value);
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(strip_tags($text));

    if ($text === '') {
        return $fallback;
    }

    if (
        stripos($text, 'Fatal error') !== false ||
        stripos($text, 'Stack trace') !== false ||
        stripos($text, 'OCILob') !== false
    ) {
        return $fallback;
    }

    return $text;
}

function category_page_money($amount) {
    return '£' . number_format(max(0, (float)$amount), 2);
}

function category_page_detect_image_mime($bytes) {
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

function category_page_encode_url_path($path) {
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

function category_page_file_to_url($filePath) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $realPath = realpath($filePath);

    if (!$docRoot || !$realPath) {
        return '';
    }

    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $realPath = str_replace('\\', '/', $realPath);

    if (stripos($realPath, $docRoot) === 0) {
        $relative = substr($realPath, strlen($docRoot));
        return category_page_encode_url_path('/' . ltrim($relative, '/'));
    }

    return '';
}

function category_page_project_url_root() {
    $root = category_page_file_to_url(dirname(__DIR__));
    return $root !== '' ? rtrim($root, '/') . '/' : '';
}

function category_page_image_src($value, $fallback, array $folders = []) {
    $raw = category_page_db_value($value);

    if ($raw === '' || $raw === null) {
        return $fallback;
    }

    $mime = category_page_detect_image_mime($raw);
    if ($mime !== '') {
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    $src = trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $src = trim($src, " \t\n\r\0\x0B\"'");

    if ($src === '') {
        return $fallback;
    }

    if (preg_match('/^data:image\//i', $src) || preg_match('/^https?:\/\//i', $src)) {
        return $src;
    }

    $compactBase64 = preg_replace('/\s+/', '', $src);
    if (strlen($compactBase64) > 80 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compactBase64)) {
        $decoded = base64_decode($compactBase64, true);
        if ($decoded !== false) {
            $decodedMime = category_page_detect_image_mime($decoded);
            if ($decodedMime !== '') {
                return 'data:' . $decodedMime . ';base64,' . base64_encode($decoded);
            }
        }
    }

    $normalized = str_replace('\\', '/', $src);
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($docRoot !== '' && stripos($normalized, $docRoot) === 0) {
        return category_page_encode_url_path('/' . ltrim(substr($normalized, strlen($docRoot)), '/'));
    }

    $htdocsPos = stripos($normalized, '/htdocs/');
    if ($htdocsPos !== false) {
        return category_page_encode_url_path('/' . substr($normalized, $htdocsPos + 8));
    }

    $baseDir = dirname(__DIR__);
    $clean = ltrim(preg_replace('#^(\.\./)+#', '', $normalized), '/');
    $baseName = basename($clean);

    $candidates = [
        __DIR__ . '/' . $normalized,
        $baseDir . '/' . $clean,
        $baseDir . '/customer/' . $clean,
        $baseDir . '/uploads/' . $baseName,
    ];

    foreach ($folders as $folder) {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder !== '') {
            $candidates[] = $baseDir . '/' . $folder . '/' . $baseName;
        }
    }

    $candidates = array_merge($candidates, [
        $baseDir . '/uploads/products/' . $baseName,
        $baseDir . '/uploads/category/' . $baseName,
        $baseDir . '/assets/images/' . $baseName,
        $baseDir . '/assets/images/products/' . $baseName,
        $baseDir . '/assets/images/category/' . $baseName,
        $baseDir . '/customer/assets/images/' . $baseName,
        $baseDir . '/trader/uploads/products/' . $baseName,
    ]);

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $url = category_page_file_to_url($candidate);
            if ($url !== '') {
                return $url;
            }
        }
    }

    $projectRoot = category_page_project_url_root();
    if ($projectRoot !== '' && preg_match('#^(trader|customer|assets|uploads)/#i', $clean)) {
        return $projectRoot . category_page_encode_url_path($clean);
    }

    return $fallback;
}

function category_page_render_stars(float $rating, int $size = 13): string {
    $rating = max(0, min(5, $rating));
    $full = (int)floor($rating);
    $half = ($rating - $full) >= 0.5;
    $empty = 5 - $full - ($half ? 1 : 0);
    $id = 'catStar' . uniqid();
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

function category_page_first_existing_column($conn, $table, array $columns) {
    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return '';
}

function category_page_product_filters($conn, $productAlias = 'p') {
    $filters = [];

    if (column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE')) {
        $filters[] = "NVL($productAlias.STOCK_AVAILABLE, 0) > 0";
    }

    if (column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS')) {
        $filters[] = "NVL(UPPER(TRIM(TO_CHAR($productAlias.ADMIN_APPROVAL_STATUS))), 'PENDING') = 'APPROVED'";
    }

    foreach (['IS_HIDDEN', 'HIDDEN', 'IS_DELETED', 'DELETED'] as $column) {
        if (column_exists($conn, 'PRODUCT', $column)) {
            $filters[] = "NVL(UPPER(TRIM(TO_CHAR($productAlias.$column))), '0') NOT IN ('1', 'Y', 'YES', 'TRUE', 'HIDDEN', 'DELETED')";
        }
    }

    foreach (['IS_ACTIVE', 'ACTIVE'] as $column) {
        if (column_exists($conn, 'PRODUCT', $column)) {
            $filters[] = "NVL(UPPER(TRIM(TO_CHAR($productAlias.$column))), '1') IN ('1', 'Y', 'YES', 'TRUE', 'ACTIVE')";
        }
    }

    if (column_exists($conn, 'PRODUCT', 'ACTIVE_STATUS')) {
        $filters[] = "NVL(UPPER(TRIM(TO_CHAR($productAlias.ACTIVE_STATUS))), 'ACTIVE') = 'ACTIVE'";
    }

    return $filters;
}

function category_page_shop_filters($conn, $shopAlias = 's') {
    $shopAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$shopAlias) ?: 's';

    if (function_exists('customer_public_shop_filter')) {
        return [customer_public_shop_filter($shopAlias)];
    }

    $filters = [];

    if (column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
        $filters[] = "NVL(UPPER(TRIM(TO_CHAR($shopAlias.APPROVAL_STATUS))), 'PENDING') = 'APPROVED'";
    }

    foreach (['IS_ACTIVE', 'ACTIVE'] as $column) {
        if (column_exists($conn, 'SHOP', $column)) {
            $filters[] = "NVL(UPPER(TRIM(TO_CHAR($shopAlias.$column))), '1') IN ('1', 'Y', 'YES', 'TRUE', 'ACTIVE')";
        }
    }

    if (column_exists($conn, 'SHOP', 'ACTIVE_STATUS')) {
        $filters[] = "NVL(UPPER(TRIM(TO_CHAR($shopAlias.ACTIVE_STATUS))), 'ACTIVE') = 'ACTIVE'";
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
            $filters[] = "EXISTS (
                SELECT 1
                FROM TRADER t
                INNER JOIN \"USER\" u ON u.USER_ID = t.USER_ID
                WHERE t.USER_ID = $shopAlias.TRADER_ID
                  AND " . implode(' AND ', $traderChecks) . "
            )";
        }
    }

    return $filters;
}

try {
    if (!$conn) {
        throw new RuntimeException('Database connection is not available.');
    }

    if (!table_exists($conn, 'CATEGORY')) {
        throw new RuntimeException('CATEGORY table was not found.');
    }

    $categoryImageColumn = category_page_first_existing_column($conn, 'CATEGORY', [
        'CATEGORY_IMAGE',
        'IMAGE',
        'IMAGE_PATH',
        'CATEGORY_IMAGE_PATH',
        'PHOTO',
        'PICTURE'
    ]);

    $categoryDescriptionColumn = category_page_first_existing_column($conn, 'CATEGORY', [
        'CATEGORY_DESCRIPTION',
        'DESCRIPTION'
    ]);

    $categoryImageSelect = $categoryImageColumn !== ''
        ? "c.$categoryImageColumn AS CATEGORY_IMAGE"
        : "NULL AS CATEGORY_IMAGE";

    $categoryDescriptionSelect = $categoryDescriptionColumn !== ''
        ? "c.$categoryDescriptionColumn AS CATEGORY_DESCRIPTION"
        : "NULL AS CATEGORY_DESCRIPTION";

    $productCountSelect = "0 AS PRODUCT_COUNT";

    if (table_exists($conn, 'PRODUCT')) {
        $countJoin = '';
        $countWhere = [
            'p.CATEGORY_ID = c.CATEGORY_ID',
            'p.ITEM_PRICE IS NOT NULL',
            'p.ITEM_PRICE >= 0'
        ];

        if (table_exists($conn, 'SHOP')) {
            $countJoin = 'INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID';
            $countWhere = array_merge($countWhere, category_page_shop_filters($conn, 's'));
        }

        $countWhere = array_merge($countWhere, category_page_product_filters($conn, 'p'));
        $productCountSelect = "(SELECT COUNT(*) FROM PRODUCT p $countJoin WHERE " . implode(' AND ', $countWhere) . ") AS PRODUCT_COUNT";
    }

    $categories = db_all($conn, "
        SELECT
            c.CATEGORY_ID,
            c.CATEGORY_NAME,
            $categoryImageSelect,
            $categoryDescriptionSelect,
            $productCountSelect
        FROM CATEGORY c
        ORDER BY c.CATEGORY_NAME
    ");

    // Only show categories that currently have at least one visible product.
    // This hides empty categories such as a default/fallback category until products are assigned to them.
    $categories = array_values(array_filter($categories, function ($cat) {
        return (int)($cat['PRODUCT_COUNT'] ?? 0) > 0;
    }));

    if ($selectedCategoryId === '' && $selectedCategoryName !== '') {
        $matched = db_all($conn, "
            SELECT CATEGORY_ID, CATEGORY_NAME
            FROM CATEGORY
            WHERE LOWER(CATEGORY_NAME) = LOWER(:category_name)
            FETCH FIRST 1 ROWS ONLY
        ", [
            ':category_name' => $selectedCategoryName
        ]);

        if (!empty($matched)) {
            $selectedCategoryId = category_page_text($matched[0]['CATEGORY_ID'] ?? '');
        }
    }

    if ($selectedCategoryId !== '') {
        foreach ($categories as $cat) {
            if (category_page_text($cat['CATEGORY_ID'] ?? '') === $selectedCategoryId) {
                $selectedCategory = $cat;
                break;
            }
        }
    }

    if ($selectedCategoryId !== '' && $selectedCategory && table_exists($conn, 'PRODUCT') && table_exists($conn, 'SHOP')) {
        $productImageColumn = category_page_first_existing_column($conn, 'PRODUCT', [
            'PRODUCT_IMAGE',
            'IMAGE',
            'IMAGE_PATH',
            'PRODUCT_IMAGE_PATH',
            'PRODUCT_PHOTO',
            'PHOTO',
            'PICTURE',
            'PRODUCT_PICTURE'
        ]);

        $productImageSelect = $productImageColumn !== ''
            ? "p.$productImageColumn AS PRODUCT_IMAGE"
            : "NULL AS PRODUCT_IMAGE";

        $productDescriptionSelect = column_exists($conn, 'PRODUCT', 'DESCRIPTION')
            ? "p.DESCRIPTION AS DESCRIPTION"
            : "NULL AS DESCRIPTION";

        $productQuantitySelect = column_exists($conn, 'PRODUCT', 'QUANTITY_PER_ITEM')
            ? "p.QUANTITY_PER_ITEM AS QUANTITY_PER_ITEM"
            : "NULL AS QUANTITY_PER_ITEM";

        $productStockSelect = column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE')
            ? "p.STOCK_AVAILABLE AS STOCK_AVAILABLE"
            : "NULL AS STOCK_AVAILABLE";

        $productAllergySelect = column_exists($conn, 'PRODUCT', 'ALLERGY_INFO')
            ? "p.ALLERGY_INFO AS ALLERGY_INFO"
            : "NULL AS ALLERGY_INFO";

        $discountJoin = '';
        $discountSelect = "
            0 AS DISCOUNT_PERCENTAGE,
            NULL AS DISCOUNT_START_DATE,
            NULL AS DISCOUNT_END_DATE,
            p.ITEM_PRICE AS FINAL_PRICE,
            0 AS HAS_DISCOUNT";

        if (table_exists($conn, 'DISCOUNT')) {
            $discountJoin = "
                LEFT JOIN (
                    SELECT
                        PRODUCT_ID,
                        MAX(DISCOUNT_PERCENTAGE) AS DISCOUNT_PERCENTAGE,
                        MIN(START_DATE) AS START_DATE,
                        MAX(END_DATE) AS END_DATE
                    FROM DISCOUNT
                    WHERE DISCOUNT_PERCENTAGE IS NOT NULL
                      AND DISCOUNT_PERCENTAGE > 0
                      AND DISCOUNT_PERCENTAGE <= 100
                      AND START_DATE IS NOT NULL
                      AND END_DATE IS NOT NULL
                      AND END_DATE > START_DATE
                      AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE)
                    GROUP BY PRODUCT_ID
                ) d ON d.PRODUCT_ID = p.PRODUCT_ID";

            $discountSelect = "
                NVL(d.DISCOUNT_PERCENTAGE, 0) AS DISCOUNT_PERCENTAGE,
                d.START_DATE AS DISCOUNT_START_DATE,
                d.END_DATE AS DISCOUNT_END_DATE,
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
        if ($reviewAvailable && column_exists($conn, 'REVIEW', 'APPROVAL_STATUS')) {
            $reviewApprovalFilter = " AND NVL(UPPER(TRIM(TO_CHAR(r.APPROVAL_STATUS))), 'YES') = 'YES'";
        }

        $ratingSelect = $reviewAvailable
            ? "(SELECT NVL(ROUND(AVG(r.RATING), 1), 0) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID$reviewApprovalFilter) AS PRODUCT_RATING,
               (SELECT COUNT(r.RATING) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID$reviewApprovalFilter) AS PRODUCT_REVIEW_COUNT"
            : "0 AS PRODUCT_RATING,
               0 AS PRODUCT_REVIEW_COUNT";

        $where = [
            'p.CATEGORY_ID = :category_id',
            'p.ITEM_PRICE IS NOT NULL',
            'p.ITEM_PRICE >= 0'
        ];

        $where = array_merge($where, category_page_product_filters($conn, 'p'));
        $where = array_merge($where, category_page_shop_filters($conn, 's'));

        $products = db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                p.ITEM_PRICE,
                $productDescriptionSelect,
                $productQuantitySelect,
                $productStockSelect,
                $productAllergySelect,
                $productImageSelect,
                s.SHOP_ID,
                s.SHOP_NAME,
                c.CATEGORY_NAME,
                $discountSelect,
                $ratingSelect
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            INNER JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID
            $discountJoin
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.PRODUCT_NAME
        ", [
            ':category_id' => $selectedCategoryId
        ]);
    }
} catch (Throwable $e) {
    $db_error = shoplocalfy_public_exception_message($e, 'Could not load categories.');
}

$pageTitle = $selectedCategory
    ? category_page_text($selectedCategory['CATEGORY_NAME'] ?? '', 'Category')
    : 'Categories';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo e($pageTitle); ?> | ShopLocalfy</title>

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="icon" href="../config/logos/favicon.ico?v=8" sizes="any">
    <link rel="icon" href="../config/logos/favicon.svg?v=8" type="image/svg+xml">

    <link rel="stylesheet" href="../assets/customer/css/categories.css?v=20260517">
</head>

<body>

<?php include __DIR__ . '/navbar.php'; ?>

<section class="category-hero">
    <div class="category-kicker">Shop by category</div>
    <h1><?php echo $selectedCategory ? e(category_page_text($selectedCategory['CATEGORY_NAME'] ?? '', 'Category')) : 'Browse Categories'; ?></h1>
    <p>
        <?php if ($selectedCategory) : ?>
            Showing products from this category.
        <?php else : ?>
            Pick a category like fruits, bakery, dairy, meat, or essentials. Each box is loaded directly from your CATEGORY table.
        <?php endif; ?>
    </p>
</section>

<main class="page-wrap">

    <?php if ($db_error !== '') : ?>
        <div class="empty-state" style="margin-bottom: 24px;">
            <h3>Could not load categories</h3>
            <p><?php echo e($db_error); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($selectedCategoryId === '') : ?>
        <div class="section-head">
            <div>
                <h2>Categories</h2>
                <p>Click a category box to view products that belong to it.</p>
            </div>
        </div>

        <div class="category-grid">
        <?php if (!empty($categories)) : ?>
            <?php foreach ($categories as $cat) : ?>
                <?php
                    $categoryId = category_page_text($cat['CATEGORY_ID'] ?? '');
                    $categoryName = category_page_text($cat['CATEGORY_NAME'] ?? '', 'Unnamed category');
                    $categoryDescription = category_page_clean_text($cat['CATEGORY_DESCRIPTION'] ?? '', '');
                    $categoryImage = category_page_image_src(
                        $cat['CATEGORY_IMAGE'] ?? '',
                        '',
                        ['uploads/category']
                    );
                    $productCount = (int)($cat['PRODUCT_COUNT'] ?? 0);
                    $isActive = $selectedCategoryId !== '' && $categoryId === $selectedCategoryId;
                    $categoryUrl = 'categories.php?category_id=' . rawurlencode($categoryId);
                    $initial = function_exists('mb_substr') ? mb_substr($categoryName, 0, 1, 'UTF-8') : substr($categoryName, 0, 1);
                ?>

                <a class="category-card <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($categoryUrl); ?>">
                    <div class="category-image">
                        <?php if ($categoryImage !== '') : ?>
                            <img
                                src="<?php echo e($categoryImage); ?>"
                                alt="<?php echo e($categoryName); ?>"
                                loading="lazy"
                                onerror="this.style.display='none'; this.parentElement.querySelector('.category-fallback').style.display='flex';"
                            >
                            <span class="category-fallback" style="display:none;"><?php echo e($initial); ?></span>
                        <?php else : ?>
                            <span class="category-fallback"><?php echo e($initial); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="category-body">
                        <div class="category-name"><?php echo e($categoryName); ?></div>

                        <?php if ($categoryDescription !== '') : ?>
                            <div class="category-desc"><?php echo e($categoryDescription); ?></div>
                        <?php else : ?>
                            <div class="category-desc">View all products listed under <?php echo e($categoryName); ?>.</div>
                        <?php endif; ?>

                        <span class="category-count">
                            <?php echo e($productCount); ?> product<?php echo $productCount === 1 ? '' : 's'; ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="empty-state">
                <h3>No categories with products found</h3>
                <p>Add products to a category first. Empty categories are hidden from customers.</p>
            </div>
        <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedCategoryId !== '') : ?>

        <?php if ($selectedCategory) : ?>
            <div class="selected-panel">
                <div>
                    <h2><?php echo e(category_page_text($selectedCategory['CATEGORY_NAME'] ?? '', 'Selected category')); ?></h2>
                    <p>
                        <?php echo count($products); ?> product<?php echo count($products) === 1 ? '' : 's'; ?>
                        found in this category.
                    </p>
                </div>

                <a class="back-link" href="categories.php">Choose another category</a>
            </div>

            <div class="product-grid">
                <?php if (!empty($products)) : ?>
                    <?php foreach ($products as $row) : ?>
                        <?php
                            $basePrice = max(0, (float)($row['ITEM_PRICE'] ?? 0));
                            $finalPrice = max(0, (float)($row['FINAL_PRICE'] ?? $basePrice));
                            $discountPercent = max(0, min(100, (float)($row['DISCOUNT_PERCENTAGE'] ?? 0)));
                            $hasDiscount = ((int)($row['HAS_DISCOUNT'] ?? 0) === 1) && $discountPercent > 0 && $finalPrice < $basePrice;

                            $productId = category_page_text($row['PRODUCT_ID'] ?? '');
                            $productName = category_page_text($row['PRODUCT_NAME'] ?? '', 'Product');
                            $shopId = category_page_text($row['SHOP_ID'] ?? '');
                            $shopName = category_page_text($row['SHOP_NAME'] ?? '', 'Unknown shop');
                            $description = category_page_clean_text($row['DESCRIPTION'] ?? '', '');
                            $quantity = category_page_text($row['QUANTITY_PER_ITEM'] ?? '', '');
                            $allergy = category_page_text($row['ALLERGY_INFO'] ?? '', '');
                            $stock = category_page_text($row['STOCK_AVAILABLE'] ?? '', '');
                            $image = category_page_image_src(
                                $row['PRODUCT_IMAGE'] ?? '',
                                '../uploads/products/product-placeholder.svg',
                                ['uploads/products', 'product_images', 'assets/images/products']
                            );

                            $productUrl = 'product-detail.php?id=' . rawurlencode($productId);
                            $shopUrl = 'shop_details.php?id=' . rawurlencode($shopId);
                            $rating = (float)($row['PRODUCT_RATING'] ?? 0);
                            $reviewCount = (int)($row['PRODUCT_REVIEW_COUNT'] ?? 0);
                        ?>

                        <div
                            class="product-card"
                            role="link"
                            tabindex="0"
                            data-href="<?php echo e($productUrl); ?>"
                            onclick="window.location=this.dataset.href;"
                            onkeydown="if(event.key === 'Enter' || event.key === ' '){event.preventDefault(); window.location=this.dataset.href;}"
                        >
                            <?php if ($hasDiscount) : ?>
                                <div class="discount-badge">
                                    <?php echo e(rtrim(rtrim(number_format($discountPercent, 2), '0'), '.')); ?>% OFF
                                </div>
                            <?php endif; ?>

                            <div class="product-img">
                                <img
                                    src="<?php echo e($image); ?>"
                                    alt="<?php echo e($productName); ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none'; var p=this.parentElement.querySelector('.no-img'); if(p){p.style.display='inline';}"
                                >
                                <span class="no-img" style="display:none;">🛒</span>
                            </div>

                            <div class="product-info">
                                <div class="product-name"><?php echo e($productName); ?></div>

                                <div class="product-shop">
                                    Sold by
                                    <?php if ($shopId !== '') : ?>
                                        <a
                                            class="shop-link"
                                            href="<?php echo e($shopUrl); ?>"
                                            onclick="event.stopPropagation();"
                                            onkeydown="event.stopPropagation();"
                                        >
                                            <?php echo e($shopName); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo e($shopName); ?>
                                    <?php endif; ?>
                                </div>

                                <div class="product-rating">
                                    <span class="stars"><?php echo category_page_render_stars($rating, 13); ?></span>
                                    <span><?php echo e(number_format($rating, 1)); ?> (<?php echo e($reviewCount); ?>)</span>
                                </div>

                                <?php if ($quantity !== '') : ?>
                                    <div class="product-meta">Quantity: <?php echo e($quantity); ?></div>
                                <?php endif; ?>

                                <?php if ($stock !== '') : ?>
                                    <div class="product-meta">Stock: <?php echo e($stock); ?></div>
                                <?php endif; ?>

                                <?php if ($allergy !== '') : ?>
                                    <div class="product-meta">Allergy: <?php echo e($allergy); ?></div>
                                <?php endif; ?>

                                <?php if ($description !== '') : ?>
                                    <div class="product-desc"><?php echo e($description); ?></div>
                                <?php endif; ?>

                                <div class="price-row">
                                    <span class="product-price"><?php echo e(category_page_money($finalPrice)); ?></span>

                                    <?php if ($hasDiscount) : ?>
                                        <span class="product-price-old"><?php echo e(category_page_money($basePrice)); ?></span>
                                        <span class="discount-note">Discount applied</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="empty-state">
                        <h3>No products in this category yet</h3>
                        <p>This category exists, but there are no visible in-stock products linked to it right now.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="selected-panel">
                <div>
                    <h2>Category not available</h2>
                    <p>This category either does not exist or has no visible in-stock approved products right now.</p>
                </div>

                <a class="back-link" href="categories.php">Back to categories</a>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>

<?php
require_once __DIR__ . '/customer_common.php';

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_format_customer')) {
    function money_format_customer($amount) {
        return '£' . number_format(max(0, (float)$amount), 2);
    }
}

function detail_db_value($value) {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        return $loaded === false ? '' : $loaded;
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return (string)($value ?? '');
}

function detail_clean_product_text($value, $fallback = '') {
    $text = detail_db_value($value);
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

function detail_text($value, $fallback = '') {
    $text = trim((string)detail_db_value($value));
    return $text !== '' ? $text : $fallback;
}

function detail_detect_image_mime($bytes) {
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

function detail_encode_url_path($path) {
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

function detail_file_to_url($filePath) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $realPath = realpath($filePath);

    if (!$docRoot || !$realPath) {
        return '';
    }

    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $realPath = str_replace('\\', '/', $realPath);

    if (stripos($realPath, $docRoot) === 0) {
        $relative = substr($realPath, strlen($docRoot));
        return detail_encode_url_path('/' . ltrim($relative, '/'));
    }

    return '';
}

function detail_project_url_root() {
    $root = detail_file_to_url(dirname(__DIR__));
    return $root !== '' ? rtrim($root, '/') . '/' : '';
}

function detail_product_image_src($value) {
    $raw = detail_db_value($value);

    if ($raw === '' || $raw === null) {
        return '../uploads/products/product-placeholder.svg';
    }

    $mime = detail_detect_image_mime($raw);
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
            $decodedMime = detail_detect_image_mime($decoded);
            if ($decodedMime !== '') {
                return 'data:' . $decodedMime . ';base64,' . base64_encode($decoded);
            }
        }
    }

    $normalized = str_replace('\\', '/', $src);
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($docRoot !== '' && stripos($normalized, $docRoot) === 0) {
        return detail_encode_url_path('/' . ltrim(substr($normalized, strlen($docRoot)), '/'));
    }

    $htdocsPos = stripos($normalized, '/htdocs/');
    if ($htdocsPos !== false) {
        return detail_encode_url_path('/' . substr($normalized, $htdocsPos + 8));
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
            $url = detail_file_to_url($candidate);
            if ($url !== '') {
                return $url;
            }
        }
    }

    $projectRoot = detail_project_url_root();
    if ($projectRoot !== '' && preg_match('#^(trader|customer|assets|uploads)/#i', $clean)) {
        return $projectRoot . detail_encode_url_path($clean);
    }

    return '../uploads/products/product-placeholder.svg';
}


function renderStars(float $rating, int $size = 16): string {
    $rating = max(0, min(5, $rating));
    $full  = (int) floor($rating);
    $half  = ($rating - $full) >= 0.5;
    $empty = 5 - $full - ($half ? 1 : 0);
    $id    = 'hg' . uniqid();
    $out   = '';

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

function detail_first_existing_column($table, array $columns): string {
    global $conn;

    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return '';
}

function detail_review_approval_column(): string {
    return detail_first_existing_column('REVIEW', ['APPROVAL_STATUS', 'APPROVAL_STATU', 'APPROVED_STATUS', 'IS_APPROVED']);
}

function detail_review_visible_condition(): string {
    $approvalColumn = detail_review_approval_column();
    if ($approvalColumn === '') {
        return '';
    }

    return " AND NVL(UPPER(TRIM($approvalColumn)), 'YES') IN ('YES', 'Y', 'APPROVED', 'ACTIVE')";
}

function detail_review_active_customer_condition(string $userAlias = 'u'): string {
    global $conn;

    if (
        !table_exists($conn, 'USER') ||
        !column_exists($conn, 'USER', 'ACTIVE_STATUS') ||
        !column_exists($conn, 'REVIEW', 'CUSTOMER_ID')
    ) {
        return '';
    }

    $userAlias = preg_replace('/[^A-Za-z0-9_]/', '', $userAlias);
    if ($userAlias === '') {
        $userAlias = 'u';
    }

    return " AND NVL(UPPER(TRIM(" . $userAlias . ".ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'";
}

function detail_next_id($table, $column, $prefix): string {
    global $conn;

    try {
        $row = db_one(
            $conn,
            "SELECT :prefix || LPAD(NVL(MAX(TO_NUMBER(REGEXP_SUBSTR($column, '[0-9]+'))), 0) + 1, 8, '0') AS NEXT_ID FROM $table",
            [':prefix' => $prefix]
        );

        if (!empty($row['NEXT_ID'])) {
            return (string)$row['NEXT_ID'];
        }
    } catch (Throwable $e) {
        // Fall through to a safe fallback ID.
    }

    return $prefix . str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
}

function detail_current_customer_id_safe(): string {
    if (function_exists('current_customer_id')) {
        try {
            $customerId = current_customer_id();
            if (trim((string)$customerId) !== '') {
                return trim((string)$customerId);
            }
        } catch (Throwable $e) {
            // Do not break public product view just because the user is not logged in.
        }
    }

    foreach (['customer_id', 'CUSTOMER_ID', 'user_id', 'USER_ID', 'customerId'] as $key) {
        if (!empty($_SESSION[$key])) {
            return trim((string)$_SESSION[$key]);
        }
    }

    return '';
}


function detail_customer_wishlist_ids(string $customerId, array $productIds): array {
    global $conn;

    $productIds = array_values(array_unique(array_filter(array_map(static function ($id) {
        return strtoupper(trim((string)$id));
    }, $productIds))));

    if ($customerId === '' || empty($productIds) || !table_exists($conn, 'WISHLIST') || !table_exists($conn, 'WISHLIST_ITEM')) {
        return [];
    }

    $placeholders = [];
    $binds = [':customer_id' => $customerId];

    foreach ($productIds as $index => $productId) {
        $key = ':pid' . $index;
        $placeholders[] = $key;
        $binds[$key] = $productId;
    }

    $rows = db_all($conn, "
        SELECT wi.PRODUCT_ID
        FROM WISHLIST w
        INNER JOIN WISHLIST_ITEM wi ON wi.WISHLIST_ID = w.WISHLIST_ID
        WHERE w.CUSTOMER_ID = :customer_id
          AND wi.PRODUCT_ID IN (" . implode(',', $placeholders) . ")
    ", $binds);

    $ids = [];
    foreach ($rows as $row) {
        $id = strtoupper(trim((string)($row['PRODUCT_ID'] ?? '')));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return $ids;
}

function detail_customer_has_bought_product(string $customerId, string $productId): bool {
    global $conn;

    if ($customerId === '' || $productId === '') {
        return false;
    }

    if (
        !table_exists($conn, 'ORDERS') ||
        !table_exists($conn, 'ORDER_ITEM') ||
        !table_exists($conn, 'PAYMENT')
    ) {
        return false;
    }

    $row = db_one($conn, "
        SELECT COUNT(*) AS CNT
        FROM ORDERS o
        INNER JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
        INNER JOIN PAYMENT pay ON pay.ORDER_ID = o.ORDER_ID
        WHERE o.CUSTOMER_ID = :customer_id
          AND oi.PRODUCT_ID = :product_id
          AND NVL(UPPER(TRIM(o.ORDER_STATUS)), 'CONFIRMED') <> 'CANCELLED'
          AND NVL(UPPER(TRIM(oi.ITEM_STATUS)), 'PENDING') <> 'CANCELLED'
          AND NVL(UPPER(TRIM(pay.PAYMENT_STATUS)), 'FAILED') = 'COMPLETED'
    ", [
        ':customer_id' => $customerId,
        ':product_id' => $productId
    ]);

    return ((int)($row['CNT'] ?? 0)) > 0;
}

function detail_review_form_message(string $customerId, string $productId): string {
    if ($customerId === '') {
        return 'Please log in before posting a review.';
    }

    if (!detail_customer_has_bought_product($customerId, $productId)) {
        return 'You can only review products you have ordered.';
    }

    return '';
}

function detail_save_review($productId, array &$errors): bool {
    global $conn;

    $rating = (int)($_POST['rating'] ?? 0);
    $reviewText = trim((string)($_POST['review_text'] ?? ''));
    $customerId = detail_current_customer_id_safe();

    if (!table_exists($conn, 'REVIEW')) {
        $errors[] = 'Review table is missing in the database.';
        return false;
    }

    if (!column_exists($conn, 'REVIEW', 'PRODUCT_ID') || !column_exists($conn, 'REVIEW', 'RATING')) {
        $errors[] = 'REVIEW table must contain PRODUCT_ID and RATING columns.';
        return false;
    }

    $reviewTextColumn = detail_first_existing_column('REVIEW', ['REVIEW_TEXT', 'COMMENTS', 'COMMENT', 'REVIEW_COMMENT', 'DESCRIPTION']);
    if ($reviewTextColumn === '') {
        $errors[] = 'REVIEW table needs a text column such as REVIEW_TEXT or COMMENTS.';
        return false;
    }

    $hasCustomerColumn = column_exists($conn, 'REVIEW', 'CUSTOMER_ID');
    if ($hasCustomerColumn && $customerId === '') {
        $errors[] = 'Please log in before posting a review.';
    } elseif ($hasCustomerColumn && !detail_customer_has_bought_product($customerId, (string)$productId)) {
        $errors[] = 'You can only review products you have ordered.';
    }

    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Choose a rating between 1 and 5 stars.';
    }

    if ($reviewText === '') {
        $errors[] = 'Write your review before posting.';
    } elseif (mb_strlen($reviewText) > 1000) {
        $errors[] = 'Review must be 1000 characters or less.';
    }

    if (!empty($errors)) {
        return false;
    }

    $dateColumn = detail_first_existing_column('REVIEW', ['DATE_POSTED', 'REVIEW_DATE', 'CREATED_AT', 'DATE_CREATED']);
    $idColumn = detail_first_existing_column('REVIEW', ['REVIEW_ID', 'ID']);
    $approvalColumn = detail_review_approval_column();

    $columns = [];
    $values = [];
    $binds = [];

    // REVIEW_ID is generated by Oracle trigger trg_generate_review_id.

    $columns[] = 'PRODUCT_ID';
    $values[] = ':product_id';
    $binds[':product_id'] = $productId;

    if ($hasCustomerColumn) {
        $columns[] = 'CUSTOMER_ID';
        $values[] = ':customer_id';
        $binds[':customer_id'] = $customerId;
    }

    $columns[] = 'RATING';
    $values[] = ':rating';
    $binds[':rating'] = $rating;

    $columns[] = $reviewTextColumn;
    $values[] = ':review_text';
    $binds[':review_text'] = $reviewText;

    if ($approvalColumn !== '') {
        $columns[] = $approvalColumn;
        $values[] = "'YES'";
    }

    if ($dateColumn !== '') {
        $columns[] = $dateColumn;
        $values[] = 'SYSDATE';
    }

    db_bind_and_execute(
        $conn,
        'INSERT INTO REVIEW (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
        $binds
    );

    return true;
}


$product_id = trim((string)(
    $_GET['id']
    ?? $_GET['product_id']
    ?? $_GET['PRODUCT_ID']
    ?? $_GET['pid']
    ?? ''
));
$page_error = '';
$reviewErrors = [];
$reviewSuccess = ($_GET['review'] ?? '') === 'saved' ? 'Review saved successfully.' : '';

$product = [
    'id' => '',
    'seller' => '',
    'shop_id' => '',
    'category_id' => '',
    'category_name' => '',
    'name' => 'Product not found',
    'rating' => 0,
    'reviews' => 0,
    'desc' => 'This product could not be loaded from the database.',
    'price' => 0,
    'old_price' => 0,
    'has_discount' => false,
    'image' => '',
    'tags' => [],
];

$reviews = [];
$similar = [];
$currentCustomerIdForReview = detail_current_customer_id_safe();
$reviewFormMessage = '';

try {
    if ($product_id === '') {
        throw new Exception('Invalid product ID. Open this page from a product card.');
    }

    if (!$conn || !table_exists($conn, 'PRODUCT') || !table_exists($conn, 'SHOP')) {
        throw new Exception('Product or shop table is missing.');
    }

    $imageSelect = "NULL AS PRODUCT_IMAGE";
    foreach (['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_PATH', 'PRODUCT_IMAGE_PATH', 'PRODUCT_PHOTO', 'PHOTO', 'PICTURE', 'PRODUCT_PICTURE'] as $imageColumn) {
        if (column_exists($conn, 'PRODUCT', $imageColumn)) {
            $imageSelect = 'p.' . $imageColumn . ' AS PRODUCT_IMAGE';
            break;
        }
    }

    $hasCategory = table_exists($conn, 'CATEGORY');
    $categoryJoin = $hasCategory
        ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID'
        : '';
    $categorySelect = $hasCategory
        ? 'c.CATEGORY_NAME AS CATEGORY_NAME'
        : 'NULL AS CATEGORY_NAME';

    $discountJoin = '';
    $discountSelect = "
        0 AS DISCOUNT_PERCENTAGE,
        NULL AS DISCOUNT_START_DATE,
        NULL AS DISCOUNT_END_DATE,
        p.ITEM_PRICE AS FINAL_PRICE,
        0 AS HAS_DISCOUNT";

    $publicProductFilter = function_exists('customer_public_product_filter')
        ? customer_public_product_filter('p', 's')
        : '1 = 1';

    if (table_exists($conn, 'DISCOUNT')) {
        $discountJoin = "
        LEFT JOIN (
            SELECT
                product_id,
                MAX(discount_percentage) AS discount_percentage,
                MIN(start_date) AS start_date,
                MAX(end_date) AS end_date
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

    $rows = db_all($conn, "
        SELECT
            p.PRODUCT_ID,
            p.CATEGORY_ID,
            p.PRODUCT_NAME,
            p.DESCRIPTION,
            p.ITEM_PRICE,
            p.ALLERGY_INFO,
            $imageSelect,
            s.SHOP_ID,
            s.SHOP_NAME,
            $categorySelect,
            $discountSelect
        FROM PRODUCT p
        INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        $categoryJoin
        $discountJoin
        WHERE p.PRODUCT_ID = :product_id
          AND $publicProductFilter
          AND p.ITEM_PRICE IS NOT NULL
          AND p.ITEM_PRICE >= 0
          AND (p.MIN_ORDER IS NULL OR p.MAX_ORDER IS NULL OR p.MIN_ORDER <= p.MAX_ORDER)
    ", [':product_id' => $product_id]);

    if (empty($rows)) {
        throw new Exception('Product not found. Check that the URL contains the exact PRODUCT_ID, for example product-detail.php?id=P000000001.');
    }

    $row = $rows[0];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_review') {
        try {
            if (detail_save_review($product_id, $reviewErrors)) {
                header('Location: product-detail.php?id=' . rawurlencode($product_id) . '&review=saved#reviews');
                exit;
            }
        } catch (Throwable $e) {
            $reviewErrors[] = 'Review could not be saved: ' . shoplocalfy_public_exception_message($e, 'Could not save review.');
        }
    }

    $basePrice = max(0, (float)($row['ITEM_PRICE'] ?? 0));
    $finalPrice = max(0, (float)($row['FINAL_PRICE'] ?? $basePrice));
    $discountPercent = max(0, min(100, (float)($row['DISCOUNT_PERCENTAGE'] ?? 0)));
    $hasDiscount = ((int)($row['HAS_DISCOUNT'] ?? 0) === 1) && $discountPercent > 0 && $finalPrice < $basePrice;

    $categoryName = detail_text($row['CATEGORY_NAME'] ?? '');
    $allergyInfo = detail_text($row['ALLERGY_INFO'] ?? '');
    $tags = [];

    if ($allergyInfo !== '') {
        $tags[] = 'Allergy: ' . $allergyInfo;
    }
    if ($hasDiscount) {
        $tags[] = rtrim(rtrim(number_format($discountPercent, 2), '0'), '.') . '% OFF';
    }

    $rating = 0;
    $reviewCount = 0;

    if (table_exists($conn, 'REVIEW') && column_exists($conn, 'REVIEW', 'PRODUCT_ID') && column_exists($conn, 'REVIEW', 'RATING')) {
        $ratingCustomerJoin = '';
        $ratingCustomerActiveCondition = '';

        if (
            table_exists($conn, 'USER') &&
            column_exists($conn, 'REVIEW', 'CUSTOMER_ID') &&
            column_exists($conn, 'USER', 'ACTIVE_STATUS')
        ) {
            $ratingCustomerJoin = 'LEFT JOIN "USER" u ON u.USER_ID = r.CUSTOMER_ID';
            $ratingCustomerActiveCondition = detail_review_active_customer_condition('u');
        }

        $ratingRows = db_all($conn, "
            SELECT
                ROUND(AVG(r.RATING), 1) AS AVG_RATING,
                COUNT(*) AS REVIEW_COUNT
            FROM REVIEW r
            $ratingCustomerJoin
            WHERE r.PRODUCT_ID = :product_id
              " . detail_review_visible_condition() . "
              $ratingCustomerActiveCondition
        ", [':product_id' => $product_id]);

        if (!empty($ratingRows)) {
            $rating = (float)($ratingRows[0]['AVG_RATING'] ?? 0);
            $reviewCount = (int)($ratingRows[0]['REVIEW_COUNT'] ?? 0);
        }

        $reviewIdExpr = column_exists($conn, 'REVIEW', 'REVIEW_ID') ? 'r.REVIEW_ID' : "''";
        $reviewCustomerExpr = column_exists($conn, 'REVIEW', 'CUSTOMER_ID') ? 'r.CUSTOMER_ID' : "''";
        $reviewTextExpr = column_exists($conn, 'REVIEW', 'REVIEW_TEXT')
            ? 'r.REVIEW_TEXT'
            : (column_exists($conn, 'REVIEW', 'COMMENTS') ? 'r.COMMENTS' : 'NULL');

        $reviewDateExpr = column_exists($conn, 'REVIEW', 'DATE_POSTED')
            ? "TO_CHAR(r.DATE_POSTED, 'Mon DD, YYYY')"
            : (column_exists($conn, 'REVIEW', 'REVIEW_DATE')
                ? "TO_CHAR(r.REVIEW_DATE, 'Mon DD, YYYY')"
                : (column_exists($conn, 'REVIEW', 'CREATED_AT') ? "TO_CHAR(r.CREATED_AT, 'Mon DD, YYYY')" : 'NULL'));

        $reviewOrder = column_exists($conn, 'REVIEW', 'DATE_POSTED')
            ? 'ORDER BY r.DATE_POSTED DESC'
            : (column_exists($conn, 'REVIEW', 'REVIEW_DATE')
                ? 'ORDER BY r.REVIEW_DATE DESC'
                : (column_exists($conn, 'REVIEW', 'CREATED_AT') ? 'ORDER BY r.CREATED_AT DESC' : 'ORDER BY r.REVIEW_ID DESC'));

                $canJoinReviewCustomer = table_exists($conn, 'USER') && column_exists($conn, 'REVIEW', 'CUSTOMER_ID');

        $customerNameSelect = $canJoinReviewCustomer
            ? "NVL(TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME), 'Customer') AS CUSTOMER_NAME"
            : "'Customer' AS CUSTOMER_NAME";

        $customerJoin = $canJoinReviewCustomer
            ? 'LEFT JOIN "USER" u ON u.USER_ID = r.CUSTOMER_ID'
            : '';

        $customerActiveCondition = $canJoinReviewCustomer && column_exists($conn, 'USER', 'ACTIVE_STATUS')
            ? detail_review_active_customer_condition('u')
            : '';

        $reportedByExpr = column_exists($conn, 'REVIEW', 'REPORTED_BY') ? 'r.REPORTED_BY' : "''";
        $reportReasonExpr = column_exists($conn, 'REVIEW', 'REPORT_REASON') ? 'r.REPORT_REASON' : "''";
        $currentCustomerId = detail_current_customer_id_safe();

        $reviewRows = db_all($conn, "
            SELECT
                $reviewIdExpr AS REVIEW_ID,
                $reviewCustomerExpr AS CUSTOMER_ID,
                NVL(r.RATING, 0) AS RATING,
                $reviewTextExpr AS REVIEW_TEXT,
                $reviewDateExpr AS REVIEW_DATE,
                $reportedByExpr AS REPORTED_BY,
                $reportReasonExpr AS REPORT_REASON,
                $customerNameSelect
            FROM REVIEW r
            $customerJoin
            WHERE r.PRODUCT_ID = :product_id
              " . detail_review_visible_condition() . "
              $customerActiveCondition
            $reviewOrder
            FETCH FIRST 50 ROWS ONLY
        ", [':product_id' => $product_id]);

        foreach ($reviewRows as $reviewRow) {
            $reviewText = detail_text($reviewRow['REVIEW_TEXT'] ?? '');
            if ($reviewText === '') {
                continue;
            }

            $reviewCustomerId = detail_text($reviewRow['CUSTOMER_ID'] ?? '', '');
            $reportedBy = detail_text($reviewRow['REPORTED_BY'] ?? '', '');

            $reviews[] = [
                'id' => detail_text($reviewRow['REVIEW_ID'] ?? '', ''),
                'customer_id' => $reviewCustomerId,
                'name' => detail_text($reviewRow['CUSTOMER_NAME'] ?? '', 'Customer'),
                'date' => detail_text($reviewRow['REVIEW_DATE'] ?? '', 'Recent'),
                'rating' => (float)($reviewRow['RATING'] ?? 0),
                'text' => $reviewText,
                'is_own' => $currentCustomerId !== '' && $reviewCustomerId !== '' && $currentCustomerId === $reviewCustomerId,
                'is_flagged' => $reportedBy !== '',
                'report_reason' => detail_text($reviewRow['REPORT_REASON'] ?? '', '')
            ];
        }
    }

    $product = [
        'id' => detail_text($row['PRODUCT_ID'] ?? '', ''),
        'seller' => detail_text($row['SHOP_NAME'] ?? '', 'Unknown shop'),
        'shop_id' => detail_text($row['SHOP_ID'] ?? '', ''),
        'category_id' => detail_text($row['CATEGORY_ID'] ?? '', ''),
        'category_name' => detail_text($row['CATEGORY_NAME'] ?? '', ''),
        'name' => detail_text($row['PRODUCT_NAME'] ?? '', 'Product'),
        'rating' => $rating,
        'reviews' => $reviewCount,
        'desc' => detail_clean_product_text($row['DESCRIPTION'] ?? '', 'No description has been added for this product.'),
        'price' => $finalPrice,
        'old_price' => $basePrice,
        'has_discount' => $hasDiscount,
        'image' => detail_product_image_src($row['PRODUCT_IMAGE'] ?? ''),
        'tags' => $tags,
    ];

    $similarBinds = [':product_id' => $product_id];
    $similarCategoryFilter = '';
    if (!empty($row['CATEGORY_ID'])) {
        $similarCategoryFilter = 'AND p.CATEGORY_ID = :category_id';
        $similarBinds[':category_id'] = $row['CATEGORY_ID'];
    }

    $similarRows = db_all($conn, "
        SELECT
            p.PRODUCT_ID,
            p.PRODUCT_NAME,
            p.ITEM_PRICE,
            $imageSelect,
            s.SHOP_NAME,
            $discountSelect
        FROM PRODUCT p
        INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        $discountJoin
        WHERE p.PRODUCT_ID <> :product_id
          $similarCategoryFilter
          AND $publicProductFilter
          AND p.ITEM_PRICE IS NOT NULL
          AND p.ITEM_PRICE >= 0
          AND (p.MIN_ORDER IS NULL OR p.MAX_ORDER IS NULL OR p.MIN_ORDER <= p.MAX_ORDER)
        ORDER BY p.PRODUCT_ID DESC
        FETCH FIRST 4 ROWS ONLY
    ", $similarBinds);

    foreach ($similarRows as $similarRow) {
        $similarBase = max(0, (float)($similarRow['ITEM_PRICE'] ?? 0));
        $similarFinal = max(0, (float)($similarRow['FINAL_PRICE'] ?? $similarBase));

        $similar[] = [
            'id' => detail_text($similarRow['PRODUCT_ID'] ?? '', ''),
            'seller' => detail_text($similarRow['SHOP_NAME'] ?? '', 'Unknown shop'),
            'name' => detail_text($similarRow['PRODUCT_NAME'] ?? '', 'Product'),
            'price' => $similarFinal,
            'rating' => 0,
            'image' => detail_product_image_src($similarRow['PRODUCT_IMAGE'] ?? ''),
            'in_wishlist' => false,
        ];
    }

    $wishlistCheckIds = [];
    if ($product['id'] !== '') {
        $wishlistCheckIds[] = $product['id'];
    }
    foreach ($similar as $similarItem) {
        if (!empty($similarItem['id'])) {
            $wishlistCheckIds[] = $similarItem['id'];
        }
    }

    $wishlistIds = detail_customer_wishlist_ids($currentCustomerIdForReview, $wishlistCheckIds);
    $product['in_wishlist'] = isset($wishlistIds[strtoupper((string)$product['id'])]);

    foreach ($similar as $similarIndex => $similarItem) {
        $similar[$similarIndex]['in_wishlist'] = isset($wishlistIds[strtoupper((string)$similarItem['id'])]);
    }
} catch (Throwable $e) {
    $page_error = shoplocalfy_public_exception_message($e, 'Could not load product details.');
}

if ($product['id'] !== '') {
    $reviewFormMessage = detail_review_form_message($currentCustomerIdForReview, $product['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= e($product['name']) ?> – ShopLocalfy</title>
  <link href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../assets/customer/css/product-detail.css?v=20260517">
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container">

  <?php if ($page_error !== ''): ?>
    <div class="error-box"><?= e($page_error) ?></div>
  <?php endif; ?>

  <!-- ════════════ PRODUCT HERO ════════════ -->
  <section class="product-hero">

    <!-- Left: one real product image -->
    <div class="img-col">
      <div class="main-img" id="mainImg">
        <?php if (!empty($product['image'])): ?>
          <img
            src="<?= e($product['image']) ?>"
            alt="<?= e($product['name']) ?>"
            onerror="this.style.display='none'; var p=this.parentElement.querySelector('.img-placeholder'); if(p){p.style.display='flex';}"
          />
        <?php endif; ?>
        <div class="img-placeholder" style="<?= !empty($product['image']) ? 'display:none;' : '' ?>">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <span>Product Image</span>
        </div>
      </div>
    </div>

    <!-- Right: info -->
    <div class="info-col">
      <h1 class="product-title">
        <?= e($product['name']) ?>
        <span class="title-shop">
          by
          <?php if (!empty($product['shop_id'])): ?>
            <a href="shop_details.php?id=<?= e(rawurlencode($product['shop_id'])) ?>"><?= e($product['seller']) ?></a>
          <?php else: ?>
            <?= e($product['seller']) ?>
          <?php endif; ?>
        </span>
      </h1>

      <?php if (!empty($product['category_id']) && !empty($product['category_name'])): ?>
        <div class="product-category-line">
          Category:
          <a href="categories.php?category_id=<?= e(rawurlencode($product['category_id'])) ?>">
            <?= e($product['category_name']) ?>
          </a>
        </div>
      <?php endif; ?>

      <div class="rating-row">
        <div class="stars"><?= renderStars($product['rating'], 18) ?></div>
        <span class="rating-text">
          <?= number_format($product['rating'], 1) ?>
          (<a href="#reviews"><?= $product['reviews'] ?> reviews</a>)
        </span>
      </div>

      <?php if (!empty($product['tags'])): ?>
      <div class="meta-tags">
        <?php foreach ($product['tags'] as $tag): ?>
          <span class="tag"><?= e($tag) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <p class="product-desc"><?= e($product['desc']) ?></p>

      <div class="price-row">
        <span class="price-main"><?= e(money_format_customer($product['price'])) ?></span>
        <?php if (!empty($product['has_discount'])): ?>
          <span class="price-unit"><s><?= e(money_format_customer($product['old_price'])) ?></s></span>
        <?php endif; ?>
      </div>

      <div class="qty-row">
        <span class="qty-label">Qty</span>
        <div class="qty-ctrl">
          <button type="button" onclick="changeQty(-1)">−</button>
          <span id="qtyVal">1</span>
          <button type="button" onclick="changeQty(1)">+</button>
        </div>
      </div>

      <div class="action-row">
        <form method="POST" action="cart_action.php" onsubmit="syncQtyInputs()">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
          <input type="hidden" name="quantity" class="qtyInput" value="1">
          <input type="hidden" name="redirect" value="checkout.php">
          <button class="btn-buy" type="submit">Buy now</button>
        </form>
        <form method="POST" action="cart_action.php" onsubmit="syncQtyInputs()">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
          <input type="hidden" name="quantity" class="qtyInput" value="1">
          <input type="hidden" name="redirect" value="product-detail.php?id=<?= e(rawurlencode($product['id'])) ?>">
          <button class="btn-cart" type="submit">Add to cart</button>
        </form>
        <form method="POST" action="wishlist_action.php">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
          <input type="hidden" name="redirect" value="product-detail.php?id=<?= e(rawurlencode($product['id'])) ?>">
          <button class="btn-wish <?= !empty($product['in_wishlist']) ? 'active' : '' ?>" id="wishBtn" type="submit" title="<?= !empty($product['in_wishlist']) ? 'Remove from wishlist' : 'Save to wishlist' ?>" aria-pressed="<?= !empty($product['in_wishlist']) ? 'true' : 'false' ?>">
            <svg viewBox="0 0 24 24">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
          </button>
        </form>
      </div>
    </div>
  </section>

  <hr class="divider"/>

  <!-- ════════════ REVIEWS ════════════ -->
  <section class="reviews-section" id="reviews">

    <div class="sec-header">
      <h2 class="sec-title">Product Reviews</h2>
      <div class="rating-summary">
        <span class="big-num"><?= number_format($product['rating'], 1) ?></span>
        <div>
          <div class="stars"><?= renderStars($product['rating'], 14) ?></div>
          <div class="small-meta"><?= $product['reviews'] ?> reviews</div>
        </div>
      </div>
    </div>

    <!-- Existing reviews — fetched from DB -->
    <div class="review-list" id="reviewList">
      <?php if (!empty($reviews)): ?>
      <?php foreach ($reviews as $r): ?>
      <div class="review-card">
        <div class="rev-top">
          <div class="reviewer">
            <div class="avatar"><?= e(strtoupper(substr($r['name'], 0, 1))) ?></div>
            <div>
              <div class="rev-name">
                <?= e($r['name']) ?>
                <?php if (!empty($r['is_own'])): ?><span class="own-review-badge"><small> - Your review</small></span><?php endif; ?>
              </div>
              <div class="rev-date"><?= e($r['date']) ?></div>
            </div>
          </div>
        </div>
        <div class="rev-stars"><?= renderStars($r['rating'], 13) ?></div>
        <p class="rev-text"><?= e($r['text']) ?></p>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
        <p class="empty-msg">No reviews yet.</p>
      <?php endif; ?>
    </div>

    <!-- Write a review -->
    <?php if (!empty($reviewErrors)): ?>
      <div class="review-alert error">
        <?php foreach ($reviewErrors as $reviewError): ?>
          <div><?= e($reviewError) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($reviewSuccess !== ''): ?>
      <div class="review-alert success"><?= e($reviewSuccess) ?></div>
    <?php endif; ?>

    <?php if ($reviewFormMessage !== ''): ?>
      <div class="review-alert info"><?= e($reviewFormMessage) ?></div>
    <?php else: ?>
      <form class="write-review" method="POST" action="product-detail.php?id=<?= e(rawurlencode($product['id'])) ?>#reviews">
        <input type="hidden" name="action" value="post_review">
        <input type="hidden" name="rating" id="reviewRating" value="<?= e($_POST['rating'] ?? '') ?>">

        <h3>Write a Review</h3>
        <div class="star-picker" id="starPicker">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <button type="button" class="star-button" onclick="pickStar(<?= $i ?>)" aria-label="Rate <?= $i ?> star<?= $i === 1 ? '' : 's' ?>">
              <svg viewBox="0 0 24 24">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
              </svg>
            </button>
          <?php endfor; ?>
        </div>
        <textarea class="review-textarea" name="review_text" id="reviewText" placeholder="Share your experience with this product…" required><?= e($_POST['review_text'] ?? '') ?></textarea>
        <button class="btn-post" type="submit">Post Review</button>
      </form>
    <?php endif; ?>

  </section>

  <!-- ════════════ SIMILAR PRODUCTS ════════════ -->
  <section class="similar-section">
    <h2 class="sec-title" style="font-family:var(--font-display)">More from Local Traders</h2>
    <div class="similar-grid">
      <?php if (!empty($similar)): ?>
      <?php foreach ($similar as $s): ?>
      <div class="sim-card" onclick="window.location='product-detail.php?id=<?= e(rawurlencode($s['id'])) ?>'">
        <div class="sim-img">
          <?php if (!empty($s['image'])): ?>
            <img src="<?= e($s['image']) ?>" alt="<?= e($s['name']) ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"/>
          <?php else: ?>
            <div class="s-placeholder">
              <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            </div>
          <?php endif; ?>
          <form method="POST" action="wishlist_action.php" onclick="event.stopPropagation();">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="product_id" value="<?= e($s['id']) ?>">
            <input type="hidden" name="redirect" value="product-detail.php?id=<?= e(rawurlencode($product['id'])) ?>">
            <button class="sim-wish <?= !empty($s['in_wishlist']) ? 'active' : '' ?>" type="submit" title="<?= !empty($s['in_wishlist']) ? 'Remove from wishlist' : 'Save to wishlist' ?>" aria-pressed="<?= !empty($s['in_wishlist']) ? 'true' : 'false' ?>">
              <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </button>
          </form>
        </div>
        <div class="sim-body">
          <p class="sim-seller"><?= e($s['seller']) ?></p>
          <p class="sim-name"><?= e($s['name']) ?></p>
          <div class="sim-stars"><?= renderStars($s['rating'], 11) ?></div>
          <p class="sim-price"><?= e(money_format_customer($s['price'])) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
        <p class="empty-msg">No similar products found.</p>
      <?php endif; ?>
    </div>
  </section>

</div><!-- /.container -->

<div class="toast" id="toast"></div>

<script src="../assets/customer/js/product-detail.js?v=20260517"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

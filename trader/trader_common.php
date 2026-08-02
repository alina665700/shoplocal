<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function trader_db_connection() {
    global $conn, $connection, $db_conn, $db, $oracle_conn;
    global $db_user, $db_password, $db_connection;

    foreach ([$conn ?? null, $connection ?? null, $db_conn ?? null, $db ?? null, $oracle_conn ?? null] as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    if (function_exists('oci_connect') && !empty($db_user) && isset($db_password) && !empty($db_connection)) {
        return oci_connect($db_user, $db_password, $db_connection);
    }

    return null;
}

function e($value) {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        $value = $loaded === false ? '' : $loaded;
    } elseif (is_object($value)) {
        $value = method_exists($value, '__toString') ? (string)$value : '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt($value) {
    return '£' . number_format((float)$value, 2);
}

if (!defined('SHOPLOCALFY_PLATFORM_FEE_RATE')) {
    define('SHOPLOCALFY_PLATFORM_FEE_RATE', 0.08);
}

function trader_platform_fee_rate(): float {
    return (float) SHOPLOCALFY_PLATFORM_FEE_RATE;
}

function trader_net_multiplier(): float {
    return 1 - trader_platform_fee_rate();
}

function trader_platform_fee_amount($gross): float {
    return round(max(0, (float)$gross) * trader_platform_fee_rate(), 2);
}

function trader_net_revenue($gross): float {
    return round(max(0, (float)$gross) * trader_net_multiplier(), 2);
}

function int_fmt($value) {
    return number_format((int)$value);
}

function oracle_error_message($resource = null) {
    return shoplocalfy_db_error_message($resource, 'A database error occurred. Please try again.');
}

function db_bind_and_execute($conn, $sql, $binds = [], $mode = OCI_COMMIT_ON_SUCCESS) {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        throw new Exception(oracle_error_message($conn));
    }

    $localBinds = [];
    foreach ($binds as $key => $value) {
        $bindName = str_starts_with($key, ':') ? $key : ':' . $key;
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!@oci_execute($stmt, $mode)) {
        throw new Exception(oracle_error_message($stmt));
    }

    return $stmt;
}

function db_all($conn, $sql, $binds = []) {
    $stmt = db_bind_and_execute($conn, $sql, $binds);
    $rows = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }
    return $rows;
}

function db_one($conn, $sql, $binds = []) {
    $stmt = db_bind_and_execute($conn, $sql, $binds);
    $row = oci_fetch_assoc($stmt);
    return $row ?: null;
}

function table_exists($conn, $tableName) {
    try {
        $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM USER_TABLES WHERE TABLE_NAME = UPPER(:table_name)', [':table_name' => $tableName]);
        return ((int)($row['TOTAL'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists($conn, $tableName, $columnName) {
    try {
        $row = db_one(
            $conn,
            'SELECT COUNT(*) AS TOTAL FROM USER_TAB_COLUMNS WHERE TABLE_NAME = UPPER(:table_name) AND COLUMN_NAME = UPPER(:column_name)',
            [':table_name' => $tableName, ':column_name' => $columnName]
        );
        return ((int)($row['TOTAL'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function current_trader_id() {
    $role = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
    if ($role !== 'TRADER') {
        return null;
    }

    $traderId = trim((string)($_SESSION['trader_user_id'] ?? ''));

    if ($traderId === '') {
        $traderId = trim((string)($_SESSION['user_id'] ?? ''));
    }

    if ($traderId === '') {
        return null;
    }

    $conn = trader_db_connection();
    if (!$conn) {
        return null;
    }

    try {
        $row = db_one($conn, <<<'SQL'
            SELECT t.USER_ID
            FROM TRADER t
            INNER JOIN "USER" u ON u.USER_ID = t.USER_ID
            WHERE t.USER_ID = :trader_id
              AND UPPER(TRIM(u.USER_ROLE)) = 'TRADER'
              AND NVL(UPPER(TRIM(u.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
              AND NVL(u.EMAIL_VERIFIED, 0) = 1
              AND NVL(UPPER(TRIM(t.VERIFIED_STATUS)), 'PENDING') = 'VERIFIED'
SQL
        , [':trader_id' => $traderId]);
        return $row && !empty($row['USER_ID']) ? $row['USER_ID'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function require_trader_login() {
    $traderId = current_trader_id();
    if (!$traderId) {
        header('Location: login.php');
        exit;
    }
    return $traderId;
}

function get_trader_profile($conn, $traderId) {
    $profile = [
        'USER_ID' => $traderId,
        'FIRST_NAME' => $_SESSION['first_name'] ?? 'Trader',
        'LAST_NAME' => $_SESSION['last_name'] ?? 'Profile',
        'EMAIL_ADDRESS' => $_SESSION['trader_email'] ?? '',
        'FULL_NAME' => trim(($_SESSION['first_name'] ?? 'Trader') . ' ' . ($_SESSION['last_name'] ?? 'Profile')),
        'INITIALS' => 'TP',
        'SHOP_ID' => null,
        'SHOP_NAME' => 'Store Owner',
        'APPROVAL_STATUS' => '',
        'VERIFIED_STATUS' => '',
    ];

    if (!$conn || !$traderId) {
        return $profile;
    }

    try {
        $row = db_one($conn, '
            SELECT
                u.USER_ID,
                u.FIRST_NAME,
                u.LAST_NAME,
                u.EMAIL_ADDRESS,
                t.VERIFIED_STATUS
            FROM "USER" u
            INNER JOIN TRADER t ON t.USER_ID = u.USER_ID
            WHERE u.USER_ID = :trader_id
        ', [':trader_id' => $traderId]);

        if ($row) {
            $profile = array_merge($profile, $row);
            $profile['FULL_NAME'] = trim(($row['FIRST_NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? '')) ?: 'Trader Profile';
        }

        if (table_exists($conn, 'SHOP')) {
            $shop = db_one($conn, '
                SELECT SHOP_ID, SHOP_NAME, APPROVAL_STATUS
                FROM SHOP
                WHERE TRADER_ID = :trader_id
                ORDER BY SHOP_NAME
                FETCH FIRST 1 ROWS ONLY
            ', [':trader_id' => $traderId]);

            if ($shop) {
                $profile['SHOP_ID'] = $shop['SHOP_ID'] ?? null;
                $profile['SHOP_NAME'] = $shop['SHOP_NAME'] ?? 'Store Owner';
                $profile['APPROVAL_STATUS'] = $shop['APPROVAL_STATUS'] ?? '';
            }
        }
    } catch (Throwable $e) {
       
    }

    $profile['INITIALS'] = initials_from_name($profile['FULL_NAME']);
    return $profile;
}

function initials_from_name($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return 'TP';
    }
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'T', 0, 1));
    $last = strtoupper(substr($parts[count($parts) - 1] ?? 'P', 0, 1));
    return $first . $last;
}

function status_class($status) {
    $s = strtoupper((string)$status);
    return match ($s) {
        'COLLECTED', 'COMPLETED', 'PAID', 'VERIFIED', 'APPROVED' => 'done',
        'CONFIRMED', 'READY' => 'proc',
        'SHIPPED', 'OUT_FOR_DELIVERY' => 'ship',
        default => 'pend',
    };
}

function stock_status($stock, $minOrder = 1) {
    $qty = (int)$stock;
    $threshold = max(5, (int)$minOrder);
    if ($qty <= 0) {
        return 'out';
    }
    if ($qty <= $threshold) {
        return 'low';
    }
    return 'active';
}

function product_image_path($filename) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $filename = trim(str_replace('\\', '/', (string)$filename));
    if ($filename === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $filename)) return $filename;
    if (strpos($filename, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $filename) ? '../' . $filename : $placeholder;
    }
    $file = dirname(__DIR__) . '/uploads/products/' . basename($filename);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($filename)) : $placeholder;
}

function get_pending_order_count($conn, $traderId) {
    if (!$conn || !$traderId || !table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS')) {
        return 0;
    }

    try {
        if (column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS')) {
            $row = db_one($conn, "
                SELECT COUNT(DISTINCT oi.ORDER_ID) AS TOTAL
                FROM ORDER_ITEM oi
                INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
                WHERE oi.TRADER_ID = :trader_id
                  AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'PENDING'
                  AND UPPER(NVL(o.ORDER_STATUS, 'CONFIRMED')) <> 'CANCELLED'
            ", [':trader_id' => $traderId]);
            return (int)($row['TOTAL'] ?? 0);
        }

        $row = db_one($conn, "
            SELECT COUNT(DISTINCT o.ORDER_ID) AS TOTAL
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
              AND o.ORDER_STATUS IN ('CONFIRMED', 'READY')
        ", [':trader_id' => $traderId]);
        return (int)($row['TOTAL'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}


if (!function_exists('product_col_required')) {
function product_col_required($conn, $table, $column)
{
    $row = db_one($conn, '
        SELECT NULLABLE
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = UPPER(:table_name)
          AND COLUMN_NAME = UPPER(:column_name)
    ', [':table_name' => $table, ':column_name' => $column]);

    return strtoupper((string)($row['NULLABLE'] ?? 'Y')) === 'N';
}
}

if (!function_exists('product_col_length')) {
function product_col_length($conn, $table, $column)
{
    $row = db_one($conn, '
        SELECT DATA_LENGTH
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = UPPER(:table_name)
          AND COLUMN_NAME = UPPER(:column_name)
    ', [':table_name' => $table, ':column_name' => $column]);

    return (int)($row['DATA_LENGTH'] ?? 10);
}
}

if (!function_exists('product_next_id')) {
function product_next_id($conn, $table, $column, $prefix)
{
    $length = product_col_length($conn, $table, $column);
    $prefix = strtoupper($prefix);
    $pad = max(1, $length - strlen($prefix));

    $row = db_one($conn, "
        SELECT NVL(MAX(TO_NUMBER(REGEXP_SUBSTR($column, '[0-9]+$'))), 0) + 1 AS NEXT_NUM
        FROM $table
        WHERE REGEXP_LIKE($column, '^[A-Za-z]+[0-9]+$')
    ");

    $num = (int)($row['NEXT_NUM'] ?? 1);

    do {
        $candidate = $prefix . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
        $exists = db_one($conn, "SELECT $column FROM $table WHERE $column = :id", [':id' => $candidate]);
        $num++;
    } while ($exists);

    return $candidate;
}
}

if (!function_exists('product_get_shop')) {
function product_get_shop($conn, $traderId)
{
    if (!$conn || !table_exists($conn, 'SHOP')) return null;

    $select = ['SHOP_ID', 'TRADER_ID'];
    foreach (['SHOP_NAME', 'LOCATION', 'SHOP_ADDRESS', 'APPROVAL_STATUS'] as $col) {
        if (column_exists($conn, 'SHOP', $col)) $select[] = $col;
    }

    return db_one($conn, '
        SELECT ' . implode(', ', $select) . '
        FROM SHOP
        WHERE TRADER_ID = :trader_id
        FETCH FIRST 1 ROWS ONLY
    ', [':trader_id' => $traderId]);
}
}

if (!function_exists('product_get_categories')) {
function product_get_categories($conn)
{
    if (!$conn || !table_exists($conn, 'CATEGORY')) return [];
    if (!column_exists($conn, 'CATEGORY', 'CATEGORY_ID')) return [];

    $nameCol = column_exists($conn, 'CATEGORY', 'CATEGORY_NAME') ? 'CATEGORY_NAME' : null;
    if (!$nameCol && column_exists($conn, 'CATEGORY', 'NAME')) $nameCol = 'NAME';
    if (!$nameCol) return [];

    return db_all($conn, "
        SELECT CATEGORY_ID, $nameCol AS CATEGORY_NAME
        FROM CATEGORY
        ORDER BY $nameCol
    ");
}
}

if (!function_exists('product_default_category_id')) {
function product_default_category_id($conn)
{
    if (!$conn || !table_exists($conn, 'CATEGORY')) return null;
    if (!column_exists($conn, 'CATEGORY', 'CATEGORY_ID')) return null;

    $fallbackId = 'CAT0000000';
    $nameCol = column_exists($conn, 'CATEGORY', 'CATEGORY_NAME') ? 'CATEGORY_NAME' : null;
    if (!$nameCol && column_exists($conn, 'CATEGORY', 'NAME')) $nameCol = 'NAME';
    if (!$nameCol) return null;

    try {
        $exists = db_one($conn, 'SELECT CATEGORY_ID FROM CATEGORY WHERE CATEGORY_ID = :category_id', [':category_id' => $fallbackId]);
        if (!$exists) {
            $columns = ['CATEGORY_ID', $nameCol];
            $values = [':category_id', ':category_name'];
            $binds = [':category_id' => $fallbackId, ':category_name' => 'Others'];

            if (column_exists($conn, 'CATEGORY', 'DESCRIPTION')) {
                $columns[] = 'DESCRIPTION';
                $values[] = ':description';
                $binds[':description'] = 'Default fallback category for products without a specific category.';
            }
            if (column_exists($conn, 'CATEGORY', 'CATEGORY_IMAGE')) {
                $columns[] = 'CATEGORY_IMAGE';
                $values[] = 'NULL';
            }

            db_bind_and_execute(
                $conn,
                'INSERT INTO CATEGORY (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
                $binds
            );
        }
        return $fallbackId;
    } catch (Throwable $e) {
       
    }

    $row = db_one($conn, "
        SELECT CATEGORY_ID
        FROM CATEGORY
        ORDER BY CASE WHEN UPPER($nameCol) = 'OTHERS' THEN 0 ELSE 1 END, $nameCol
        FETCH FIRST 1 ROWS ONLY
    ");

    return $row['CATEGORY_ID'] ?? null;
}
}

if (!function_exists('product_category_exists')) {
function product_category_exists($conn, $categoryId)
{
    $categoryId = trim((string)$categoryId);
    if ($categoryId === '') return false;
    if (!$conn || !table_exists($conn, 'CATEGORY') || !column_exists($conn, 'CATEGORY', 'CATEGORY_ID')) return false;

    try {
        $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM CATEGORY WHERE CATEGORY_ID = :category_id', [':category_id' => $categoryId]);
        return ((int)($row['TOTAL'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('product_clean_money')) {
function product_clean_money($value)
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return '0';
    return (string)round((float)$value, 2);
}
}

if (!function_exists('product_clean_int')) {
function product_clean_int($value, $fallback = 0, $min = 0)
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return (string)$fallback;
    return (string)max($min, (int)$value);
}
}

if (!function_exists('product_upload_image')) {
function product_upload_image($field = 'product_image')
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Try again.');
    }

    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Product image must be smaller than 2MB.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES[$field]['tmp_name']);
    if (!$mime || !array_key_exists($mime, $allowed)) {
        throw new RuntimeException('Only JPG, PNG, and WEBP images are allowed.');
    }

    $relativeDir = 'uploads/products/';
    $uploadDir = dirname(__DIR__) . '/' . $relativeDir;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('Could not create uploads/products folder.');
    }

    $filename = 'prod_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save uploaded product image.');
    }

    return $relativeDir . $filename;
}
}

if (!function_exists('product_image_src')) {
function product_image_src($dbValue) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $dbValue = trim(str_replace('\\', '/', (string)$dbValue));
    if ($dbValue === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $dbValue)) return $dbValue;
    if (strpos($dbValue, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $dbValue) ? '../' . $dbValue : $placeholder;
    }
    if (strpos($dbValue, 'assets/uploads/products/') === 0) {
        $file = dirname(__DIR__) . '/uploads/products/' . basename($dbValue);
        return is_file($file) ? '../uploads/products/' . rawurlencode(basename($dbValue)) : $placeholder;
    }
    $file = dirname(__DIR__) . '/uploads/products/' . basename($dbValue);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($dbValue)) : $placeholder;
}
}

function render_trader_sidebar($active, $profile, $pendingOrderCount = 0) {
    include __DIR__ . '/sidebar.php';
}


function render_topbar($title, $subtitle) {
    echo '<header class="trader-topbar topbar">';
    echo '  <div class="trader-topbar-left topbar-left">';
    echo '    <button class="trader-sidebar-toggle sidebar-toggle" id="sidebarToggle" title="Toggle sidebar" type="button" aria-label="Toggle sidebar">';
    echo '      <span class="hamburger-lines"><span></span><span></span><span></span></span>';
    echo '    </button>';
    echo '    <div class="tb-title">' . e($title) . '<small>' . e($subtitle) . '</small></div>';
    echo '  </div>';
    echo '  <div class="trader-topbar-right topbar-right">';
    echo '    <a href="profile.php" class="tb-btn" title="Profile" aria-label="Profile">';
    echo '      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="8" r="4"></circle></svg>';
    echo '    </a>';
    echo '  </div>';
    echo '</header>';
}

function render_base_css() {
    echo <<<'CSS'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/trader/css/trader_common.css?v=20260520">

<script src="../assets/trader/js/trader_common.js?v=20260520"></script>
CSS;
}

if (!function_exists('trader_run_order_lifecycle_check')) {
    function trader_run_order_lifecycle_check() {
        $lastCheck = (int)($_SESSION['order_lifecycle_checked_at'] ?? 0);
        if ((time() - $lastCheck) <= 300) {
            return;
        }

        $conn = trader_db_connection();
        require_once __DIR__ . '/../config/order_lifecycle.php';
        if ($conn && function_exists('sl_order_auto_cancel_overdue_uncollected')) {
            sl_order_auto_cancel_overdue_uncollected($conn);
            $_SESSION['order_lifecycle_checked_at'] = time();
        }
    }
}

trader_run_order_lifecycle_check();

?>

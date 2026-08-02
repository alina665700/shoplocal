<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

    if (!oci_execute($stmt, $mode)) {
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
        $row = db_one(
            $conn,
            'SELECT COUNT(*) AS TOTAL FROM USER_TABLES WHERE TABLE_NAME = UPPER(:table_name)',
            [':table_name' => $tableName]
        );
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


function customer_public_product_filter($productAlias = 'p', $shopAlias = 's') {
    global $conn;

    $productAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$productAlias) ?: 'p';
    $shopAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$shopAlias) ?: 's';
    $clauses = [];

    if ($conn && table_exists($conn, 'PRODUCT')) {
        if (column_exists($conn, 'PRODUCT', 'IS_ACTIVE')) {
            $clauses[] = "NVL($productAlias.IS_ACTIVE, 1) = 1";
        }
        if (column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS')) {
            $clauses[] = "NVL(UPPER(TRIM($productAlias.ADMIN_APPROVAL_STATUS)), 'PENDING') = 'APPROVED'";
        }
        if (column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE')) {
            $clauses[] = "NVL($productAlias.STOCK_AVAILABLE, 0) > 0";
        }
    }

    if ($conn && table_exists($conn, 'SHOP')) {
        if (column_exists($conn, 'SHOP', 'APPROVAL_STATUS')) {
            $clauses[] = "NVL(UPPER(TRIM($shopAlias.APPROVAL_STATUS)), 'PENDING') = 'APPROVED'";
        }

        // Customer-facing pages must not expose products from suspended or unapproved traders.
        // This uses EXISTS so pages that already join SHOP as $shopAlias do not need extra joins.
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
                $clauses[] = "EXISTS (
                    SELECT 1
                    FROM TRADER t
                    INNER JOIN \"USER\" u ON u.USER_ID = t.USER_ID
                    WHERE t.USER_ID = $shopAlias.TRADER_ID
                      AND " . implode(' AND ', $traderChecks) . "
                )";
            }
        }
    }

    return $clauses ? implode(' AND ', $clauses) : '1 = 1';
}

function customer_public_product_join() {
    global $conn;
    return ($conn && table_exists($conn, 'SHOP')) ? 'INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID' : '';
}

function customer_active_discount_subquery($productColumn = 'PRODUCT_ID', $valueAlias = 'DISCOUNT_PERCENTAGE') {
    global $conn;

    $productColumn = preg_replace('/[^A-Za-z0-9_]/', '', (string)$productColumn) ?: 'PRODUCT_ID';
    $valueAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$valueAlias) ?: 'DISCOUNT_PERCENTAGE';

    if (!$conn || !table_exists($conn, 'DISCOUNT') || !column_exists($conn, 'DISCOUNT', $productColumn)) {
        return "SELECT NULL AS $productColumn, 0 AS $valueAlias FROM DUAL WHERE 1 = 0";
    }

    $valueColumn = column_exists($conn, 'DISCOUNT', 'DISCOUNT_PERCENTAGE') ? 'DISCOUNT_PERCENTAGE' : null;
    if (!$valueColumn) {
        return "SELECT NULL AS $productColumn, 0 AS $valueAlias FROM DUAL WHERE 1 = 0";
    }

    $conditions = [
        "$valueColumn IS NOT NULL",
        "$valueColumn > 0",
        "$valueColumn <= 100",
    ];

    if (column_exists($conn, 'DISCOUNT', 'START_DATE')) {
        $conditions[] = 'START_DATE IS NOT NULL';
        $conditions[] = 'TRUNC(START_DATE) <= TRUNC(SYSDATE)';
    }
    if (column_exists($conn, 'DISCOUNT', 'END_DATE')) {
        $conditions[] = 'END_DATE IS NOT NULL';
        $conditions[] = 'TRUNC(END_DATE) >= TRUNC(SYSDATE)';
    }
    if (column_exists($conn, 'DISCOUNT', 'START_DATE') && column_exists($conn, 'DISCOUNT', 'END_DATE')) {
        $conditions[] = 'END_DATE > START_DATE';
    }
    if (column_exists($conn, 'DISCOUNT', 'STATUS')) {
        $conditions[] = "NVL(UPPER(TRIM(STATUS)), 'ACTIVE') = 'ACTIVE'";
    }

    return "
        SELECT $productColumn, MAX($valueColumn) AS $valueAlias
        FROM DISCOUNT
        WHERE " . implode("\n          AND ", $conditions) . "
        GROUP BY $productColumn
    ";
}

function customer_validate_voucher($conn, $code, $subtotal, &$notice = '') {
    $notice = '';
    $code = strtoupper(trim((string)$code));
    $subtotal = max(0, (float)$subtotal);

    if ($code === '' || $subtotal <= 0) {
        return [null, 0.0];
    }

    if (!$conn || !table_exists($conn, 'VOUCHER')) {
        $notice = 'Voucher service is not available.';
        return [null, 0.0];
    }

    $voucher = db_one(
        $conn,
        'SELECT VOUCHER_ID, VOUCHER_CODE, DISCOUNT_TYPE, DISCOUNT_VALUE, MIN_ORDER_AMOUNT, USED_COUNT, USAGE_LIMIT, STATUS
         FROM VOUCHER
         WHERE UPPER(TRIM(VOUCHER_CODE)) = :voucher_code
           AND NVL(UPPER(TRIM(STATUS)), \'ACTIVE\') = \'ACTIVE\'
           AND START_DATE IS NOT NULL
           AND END_DATE IS NOT NULL
           AND END_DATE > START_DATE
           AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE)
           AND NVL(USED_COUNT, 0) < NVL(USAGE_LIMIT, 0)',
        [':voucher_code' => $code]
    );

    if (!$voucher) {
        $notice = 'Invalid, expired, inactive, or fully used voucher code.';
        return [null, 0.0];
    }

    $minOrder = max(0, (float)($voucher['MIN_ORDER_AMOUNT'] ?? 0));
    if ($subtotal < $minOrder) {
        $notice = 'This voucher requires a minimum order of £' . number_format($minOrder, 2) . '.';
        return [null, 0.0];
    }

    $type = strtoupper(trim((string)($voucher['DISCOUNT_TYPE'] ?? '')));
    $value = max(0, (float)($voucher['DISCOUNT_VALUE'] ?? 0));

    if ($type === 'PERCENTAGE') {
        if ($value <= 0 || $value > 100) {
            $notice = 'This voucher has an invalid percentage value.';
            return [null, 0.0];
        }
        $discount = round($subtotal * ($value / 100), 2);
    } elseif ($type === 'FIXED') {
        if ($value <= 0) {
            $notice = 'This voucher has an invalid fixed value.';
            return [null, 0.0];
        }
        $discount = $value;
    } else {
        $notice = 'This voucher has an invalid discount type.';
        return [null, 0.0];
    }

    $discount = min(max(0, $discount), $subtotal);
    $notice = 'Voucher applied.';
    return [$voucher, $discount];
}

function product_image_src($image) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $image = trim(str_replace('\\', '/', (string)$image));
    if ($image === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $image)) return $image;
    if (strpos($image, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $image) ? '../' . $image : $placeholder;
    }
    $file = dirname(__DIR__) . '/uploads/products/' . basename($image);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($image)) : $placeholder;
}

function customer_clean_id($value) {
    return strtoupper(trim((string)$value));
}

function current_customer_id() {
    global $conn;
    $role = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
    if ($role !== '' && $role !== 'CUSTOMER') {
        return null;
    }

    $possible = [
        $_SESSION['customer_id'] ?? null,
        $_SESSION['CUSTOMER_ID'] ?? null,
    ];

    if ($role === 'CUSTOMER') {
        $possible[] = $_SESSION['user_id'] ?? null;
        $possible[] = $_SESSION['USER_ID'] ?? null;
    }

    foreach ($possible as $candidate) {
        $candidate = customer_clean_id($candidate);

        if ($candidate === '') {
            continue;
        }

        try {
            $row = db_one(
                $conn,
                <<<'SQL'
                SELECT c.USER_ID
                FROM CUSTOMER c
                INNER JOIN "USER" u ON u.USER_ID = c.USER_ID
                WHERE c.USER_ID = :user_id
                  AND NVL(UPPER(TRIM(u.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
SQL
                ,
                [':user_id' => $candidate]
            );

            if ($row && !empty($row['USER_ID'])) {
                return $row['USER_ID'];
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

function customer_is_logged_in() {
    return current_customer_id() !== null;
}

function customer_current_relative_url($fallback = 'index.php') {
    $requestUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($requestUri === '') {
        return $fallback;
    }

    $scriptDir = dirname($scriptName);
    if ($scriptDir !== '' && $scriptDir !== '.' && str_starts_with($requestUri, $scriptDir . '/')) {
        $requestUri = substr($requestUri, strlen($scriptDir) + 1);
    } else {
        $requestUri = basename($requestUri);
    }

    $requestUri = str_replace(["\r", "\n"], '', $requestUri);

    if ($requestUri === '' || preg_match('/^https?:\/\//i', $requestUri) || str_starts_with($requestUri, '//')) {
        return $fallback;
    }

    return $requestUri;
}

function customer_is_safe_local_redirect($redirect) {
    $redirect = trim(str_replace(["\r", "\n"], '', (string)$redirect));

    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_starts_with($redirect, '//')) {
        return false;
    }

    if (str_contains($redirect, '..') || str_starts_with($redirect, '/')) {
        return false;
    }

    return true;
}

function customer_public_return_page($fallback = 'index.php') {
    $current = customer_current_relative_url($fallback);
    $page = strtolower(strtok($current, '?') ?: $current);

    $blockedPages = [
        'login.php',
        'register.php',
        'logout.php',
        'verify-email.php',
        'paypal-start.php',
        'paypal-return.php',
        'paypal-cancel.php',
    ];

    if (in_array($page, $blockedPages, true)) {
        return $fallback;
    }

    return customer_is_safe_local_redirect($current) ? $current : $fallback;
}

function customer_remember_return_page() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $page = customer_public_return_page('index.php');
    $plainPage = strtolower(strtok($page, '?') ?: $page);

    if ($plainPage !== 'login.php' && $plainPage !== 'register.php') {
        $_SESSION['customer_last_return_page'] = $page;
    }
}

function customer_login_url($redirect = '') {
    $url = 'login.php';

    if ($redirect === '') {
        $redirect = customer_public_return_page('index.php');
    }

    if ($redirect !== '' && customer_is_safe_local_redirect($redirect)) {
        $url .= '?redirect=' . rawurlencode($redirect);
    }

    return $url;
}

function require_customer_login() {
    if (!customer_is_logged_in()) {
        $current = customer_current_relative_url('index.php');
        header('Location: ' . customer_login_url($current));
        exit;
    }
}

function set_customer_session($user) {
    unset($_SESSION['trader_user_id'], $_SESSION['trader_id'], $_SESSION['trader_email'], $_SESSION['admin_id']);

    $_SESSION['user_id'] = $user['USER_ID'];
    $_SESSION['customer_id'] = $user['USER_ID'];
    $_SESSION['first_name'] = $user['FIRST_NAME'] ?? '';
    $_SESSION['last_name'] = $user['LAST_NAME'] ?? '';
    $_SESSION['email_address'] = $user['EMAIL_ADDRESS'] ?? '';
    $_SESSION['user_role'] = 'CUSTOMER';
    $_SESSION['role'] = 'customer';
}

function customer_password_matches($plainPassword, $storedPassword) {
    if (!$storedPassword) {
        return false;
    }

    return password_verify($plainPassword, $storedPassword);
}

function customer_next_prefixed_id($table, $idColumn, $prefix) {
    global $conn;

    $sql = "
        SELECT :prefix || LPAD(
            NVL(MAX(TO_NUMBER(REGEXP_SUBSTR($idColumn, '[0-9]+'))), 0) + 1,
            8,
            '0'
        ) AS NEXT_ID
        FROM $table
    ";

    $row = db_one($conn, $sql, [':prefix' => $prefix]);
    return $row['NEXT_ID'] ?? ($prefix . str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
}

function customer_add_to_cart($customerId, $productId, $quantity = 1) {
    global $conn;

    $productId = customer_clean_id($productId);
    $quantity = max(1, (int)$quantity);

    if ($productId === '') {
        return false;
    }

    $publicWhere = customer_public_product_filter('p', 's');
    $publicJoin = customer_public_product_join();
    
    $product = db_one(
    $conn,
    "SELECT p.PRODUCT_ID, NVL(p.STOCK_AVAILABLE, 0) AS STOCK_AVAILABLE
     FROM PRODUCT p
     $publicJoin
     WHERE p.PRODUCT_ID = :product_id
       AND $publicWhere",
    [':product_id' => $productId]
);
    
    if (!$product) {
        return false;
    }

    $existingCart = db_one($conn, 'SELECT CART_ID FROM CART WHERE CUSTOMER_ID = :customer_id FETCH FIRST 1 ROWS ONLY', [':customer_id' => $customerId]);
    $cartId = $existingCart['CART_ID'] ?? '';

    if ($cartId === '') {
        db_bind_and_execute(
            $conn,
            'INSERT INTO CART (CUSTOMER_ID, CREATED_TIME) VALUES (:customer_id, SYSTIMESTAMP)',
            [':customer_id' => $customerId]
        );

        $createdCart = db_one($conn, 'SELECT CART_ID FROM CART WHERE CUSTOMER_ID = :customer_id FETCH FIRST 1 ROWS ONLY', [':customer_id' => $customerId]);
        $cartId = $createdCart['CART_ID'] ?? '';

        if ($cartId === '') {
            throw new Exception('Cart could not be created. Please try again.');
        }
    }

        $cartTotalRow = db_one(
        $conn,
        'SELECT NVL(SUM(QUANTITY), 0) AS TOTAL_QTY FROM CART_ITEM WHERE CART_ID = :cart_id',
        [':cart_id' => $cartId]
    );

    $currentCartTotal = (int)($cartTotalRow['TOTAL_QTY'] ?? 0);

    if (($currentCartTotal + $quantity) > 20) {
        return false;
    }

    $existingItem = db_one(
        $conn,
        'SELECT QUANTITY FROM CART_ITEM WHERE CART_ID = :cart_id AND PRODUCT_ID = :product_id',
        [':cart_id' => $cartId, ':product_id' => $productId]
    );

        $existingQty = $existingItem ? (int)($existingItem['QUANTITY'] ?? 0) : 0;
    $availableStock = (int)($product['STOCK_AVAILABLE'] ?? 0);

    if (($existingQty + $quantity) > $availableStock) {
        return false;
    }

    if ($existingItem) {
        db_bind_and_execute(
            $conn,
            'UPDATE CART_ITEM SET QUANTITY = QUANTITY + :quantity WHERE CART_ID = :cart_id AND PRODUCT_ID = :product_id',
            [':quantity' => $quantity, ':cart_id' => $cartId, ':product_id' => $productId]
        );
    } else {
        db_bind_and_execute(
            $conn,
            'INSERT INTO CART_ITEM (CART_ID, PRODUCT_ID, QUANTITY) VALUES (:cart_id, :product_id, :quantity)',
            [':cart_id' => $cartId, ':product_id' => $productId, ':quantity' => $quantity]
        );
    }

    return true;
}

function customer_add_to_wishlist($customerId, $productId) {
    global $conn;

    $productId = customer_clean_id($productId);

    if ($productId === '') {
        return false;
    }

    $publicWhere = customer_public_product_filter('p', 's');
    $publicJoin = customer_public_product_join();
    $product = db_one($conn, "SELECT p.PRODUCT_ID FROM PRODUCT p $publicJoin WHERE p.PRODUCT_ID = :product_id AND $publicWhere", [':product_id' => $productId]);
    if (!$product) {
        return false;
    }

    $existingWishlist = db_one($conn, 'SELECT WISHLIST_ID FROM WISHLIST WHERE CUSTOMER_ID = :customer_id FETCH FIRST 1 ROWS ONLY', [':customer_id' => $customerId]);
    $wishlistId = $existingWishlist['WISHLIST_ID'] ?? '';

    if ($wishlistId === '') {
        db_bind_and_execute(
            $conn,
            'INSERT INTO WISHLIST (CUSTOMER_ID, CREATED_DATE) VALUES (:customer_id, SYSDATE)',
            [':customer_id' => $customerId]
        );

        $createdWishlist = db_one($conn, 'SELECT WISHLIST_ID FROM WISHLIST WHERE CUSTOMER_ID = :customer_id FETCH FIRST 1 ROWS ONLY', [':customer_id' => $customerId]);
        $wishlistId = $createdWishlist['WISHLIST_ID'] ?? '';

        if ($wishlistId === '') {
            throw new Exception('Wishlist could not be created. Please try again.');
        }
    }

    $existingItem = db_one(
        $conn,
        'SELECT PRODUCT_ID FROM WISHLIST_ITEM WHERE WISHLIST_ID = :wishlist_id AND PRODUCT_ID = :product_id',
        [':wishlist_id' => $wishlistId, ':product_id' => $productId]
    );

    if (!$existingItem) {
        db_bind_and_execute(
            $conn,
            'INSERT INTO WISHLIST_ITEM (WISHLIST_ID, PRODUCT_ID) VALUES (:wishlist_id, :product_id)',
            [':wishlist_id' => $wishlistId, ':product_id' => $productId]
        );
    }

    return true;
}

function customer_complete_pending_action($customerId) {
    if (empty($_SESSION['pending_customer_action']) || !is_array($_SESSION['pending_customer_action'])) {
        return '';
    }

    $pending = $_SESSION['pending_customer_action'];
    unset($_SESSION['pending_customer_action']);

    if (($pending['type'] ?? '') === 'cart') {
        customer_add_to_cart($customerId, $pending['product_id'] ?? '', $pending['quantity'] ?? 1);
    }

    if (($pending['type'] ?? '') === 'wishlist') {
        customer_add_to_wishlist($customerId, $pending['product_id'] ?? '');
    }

    return (string)($pending['redirect'] ?? '');
}

function safe_customer_redirect($fallback = 'index.php') {
    $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
    $redirect = str_replace(["\r", "\n"], '', (string)$redirect);

    if ($redirect === 'customer' || $redirect === 'customer/') {
        $redirect = $fallback;
    }

    if (preg_match('#^customer/#i', $redirect)) {
        $redirect = preg_replace('#^customer/#i', '', $redirect);
    }

    if ($redirect !== '' && customer_is_safe_local_redirect($redirect)) {
        return $redirect;
    }

    $lastPage = (string)($_SESSION['customer_last_return_page'] ?? '');

    if ($lastPage === 'customer' || $lastPage === 'customer/') {
        $lastPage = $fallback;
    }

    if (preg_match('#^customer/#i', $lastPage)) {
        $lastPage = preg_replace('#^customer/#i', '', $lastPage);
    }

    if ($lastPage !== '' && customer_is_safe_local_redirect($lastPage)) {
        return $lastPage;
    }

    return $fallback;
}

if (!function_exists('customer_run_order_lifecycle_check')) {
    function customer_run_order_lifecycle_check() {
        $lastCheck = (int)($_SESSION['order_lifecycle_checked_at'] ?? 0);
        if ((time() - $lastCheck) <= 300) {
            return;
        }

        global $conn;
        require_once __DIR__ . '/../config/order_lifecycle.php';
        if ($conn && function_exists('sl_order_auto_cancel_overdue_uncollected')) {
            sl_order_auto_cancel_overdue_uncollected($conn);
            $_SESSION['order_lifecycle_checked_at'] = time();
        }
    }
}

customer_remember_return_page();
customer_run_order_lifecycle_check();

?>

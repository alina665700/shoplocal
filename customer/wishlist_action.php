<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/customer_common.php';

function redirect_back($fallback = 'wishlist.php') {
    $redirect = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? $fallback;
    $redirect = str_replace(["\r", "\n"], '', (string)$redirect);

    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_starts_with($redirect, '//')) {
        $redirect = $fallback;
    }

    header('Location: ' . $redirect);
    exit;
}

function oracle_fail($where, $resource = null) {
    exit(htmlspecialchars(shoplocalfy_db_error_message($resource, $where . '. Please try again.'), ENT_QUOTES, 'UTF-8'));
}

function db_exec($conn, $sql, $binds = [], $commit = true) {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        oracle_fail('Could not prepare wishlist request', $conn);
    }

    $localBinds = [];

    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim($key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    $mode = $commit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT;

    if (!oci_execute($stmt, $mode)) {
        oracle_fail('Could not update wishlist', $stmt);
    }

    return $stmt;
}

function clean_id($value) {
    return strtoupper(trim((string)$value));
}

function next_prefixed_id($conn, $table, $idColumn, $prefix) {
    $sql = "
        SELECT :prefix || LPAD(
            NVL(MAX(TO_NUMBER(REGEXP_SUBSTR($idColumn, '[0-9]+'))), 0) + 1,
            8,
            '0'
        ) AS next_id
        FROM $table
    ";

    $row = db_one($conn, $sql, ['prefix' => $prefix]);

    if ($row && !empty($row['NEXT_ID'])) {
        return $row['NEXT_ID'];
    }

    return $prefix . str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
}

function get_or_create_wishlist_id($conn, $customer_id) {
    $existing = db_one(
        $conn,
        "
        SELECT wishlist_id
        FROM wishlist
        WHERE customer_id = :customer_id
        FETCH FIRST 1 ROWS ONLY
        ",
        ['customer_id' => $customer_id]
    );

    if ($existing && !empty($existing['WISHLIST_ID'])) {
        return $existing['WISHLIST_ID'];
    }

    db_exec(
        $conn,
        "
        INSERT INTO wishlist (
            customer_id,
            created_date
        ) VALUES (
            :customer_id,
            SYSDATE
        )
        ",
        [
            'customer_id' => $customer_id
        ]
    );

    $created = db_one(
        $conn,
        "
        SELECT wishlist_id
        FROM wishlist
        WHERE customer_id = :customer_id
        FETCH FIRST 1 ROWS ONLY
        ",
        ['customer_id' => $customer_id]
    );

    if (!$created || empty($created['WISHLIST_ID'])) {
        exit('Wishlist could not be created. Please try again.');
    }

    return $created['WISHLIST_ID'];
}

$action = $_POST['action'] ?? 'add';
$product_id = clean_id($_POST['product_id'] ?? '');

$customer_id = current_customer_id();

if (!$customer_id) {
    $redirect = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? 'wishlist.php';
    $redirect = str_replace(["\r", "\n"], '', (string)$redirect);

    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_starts_with($redirect, '//')) {
        $redirect = 'wishlist.php';
    }

    if (($action === 'add' || $action === 'toggle') && $product_id !== '') {
        $_SESSION['pending_customer_action'] = [
            'type' => 'wishlist',
            'product_id' => $product_id,
            'quantity' => 1,
            'redirect' => $redirect
        ];
    }

    header('Location: login.php?redirect=' . rawurlencode($redirect));
    exit;
}

if ($product_id === '') {
    redirect_back();
}

$wishlist_id = get_or_create_wishlist_id($conn, $customer_id);

switch ($action) {
    case 'add':
    case 'toggle':
        $publicProductFilter = customer_public_product_filter('p', 's');
        $product = db_one(
            $conn,
            "SELECT p.product_id FROM product p INNER JOIN shop s ON s.shop_id = p.shop_id WHERE p.product_id = :product_id AND $publicProductFilter",
            ['product_id' => $product_id]
        );

        if (!$product) {
            redirect_back();
        }

        $existing = db_one(
            $conn,
            "
            SELECT product_id
            FROM wishlist_item
            WHERE wishlist_id = :wishlist_id
            AND product_id = :product_id
            ",
            [
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id
            ]
        );

        if ($existing && $action === 'toggle') {
            db_exec(
                $conn,
                "
                DELETE FROM wishlist_item
                WHERE wishlist_id = :wishlist_id
                AND product_id = :product_id
                ",
                [
                    'wishlist_id' => $wishlist_id,
                    'product_id' => $product_id
                ]
            );
        } elseif (!$existing) {
            db_exec(
                $conn,
                "
                INSERT INTO wishlist_item (
                    wishlist_id,
                    product_id
                ) VALUES (
                    :wishlist_id,
                    :product_id
                )
                ",
                [
                    'wishlist_id' => $wishlist_id,
                    'product_id' => $product_id
                ]
            );
        }

        redirect_back('wishlist.php');

    case 'remove':
        db_exec(
            $conn,
            "
            DELETE FROM wishlist_item
            WHERE wishlist_id = :wishlist_id
            AND product_id = :product_id
            ",
            [
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id
            ]
        );

        redirect_back('wishlist.php');

    default:
        redirect_back('wishlist.php');
}

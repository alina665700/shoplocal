<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/customer_common.php';

function redirect_back($fallback = 'cart.php') {
    $redirect = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? $fallback;
    $redirect = str_replace(["\r", "\n"], '', (string)$redirect);

    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_starts_with($redirect, '//')) {
        $redirect = $fallback;
    }

    header('Location: ' . $redirect);
    exit;
}

function db_exec($conn, $sql, $binds = []) {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        exit(htmlspecialchars(shoplocalfy_db_error_message($conn, 'Could not prepare cart action.'), ENT_QUOTES, 'UTF-8'));
    }

    $localBinds = [];

    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim($key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
        exit(htmlspecialchars(shoplocalfy_db_error_message($stmt, 'Could not update cart.'), ENT_QUOTES, 'UTF-8'));
    }

    return $stmt;
}

function cart_action_fail($message, $fallback = 'cart.php') {
    $_SESSION['cart_voucher_notice'] = $message;
    redirect_back($fallback);
}

function load_cart_action_product($conn, $product_id) {
    $publicProductFilter = customer_public_product_filter('p', 's');

    return db_one(
        $conn,
        "
        SELECT
            p.product_id,
            p.product_name,
            NVL(p.stock_available, 0) AS stock_available
        FROM product p
        INNER JOIN shop s ON s.shop_id = p.shop_id
        WHERE p.product_id = :product_id
          AND $publicProductFilter
        ",
        ['product_id' => $product_id]
    );
}

function cart_action_existing_product_quantity($conn, $cart_id, $product_id) {
    $row = db_one(
        $conn,
        "
        SELECT NVL(quantity, 0) AS quantity
        FROM cart_item
        WHERE cart_id = :cart_id
        AND product_id = :product_id
        ",
        [
            'cart_id' => $cart_id,
            'product_id' => $product_id
        ]
    );

    return (int)($row['QUANTITY'] ?? 0);
}

function cart_action_enforce_stock_limit($product, $requested_quantity, $current_cart_quantity = 0) {
    $availableStock = max(0, (int)($product['STOCK_AVAILABLE'] ?? 0));
    $requestedQuantity = max(1, (int)$requested_quantity);
    $currentCartQuantity = max(0, (int)$current_cart_quantity);
    $productName = trim((string)($product['PRODUCT_NAME'] ?? 'this product'));

    if ($requestedQuantity > $availableStock) {
        $message = 'Only ' . $availableStock . ' item' . ($availableStock === 1 ? '' : 's') . ' of ' . $productName . ' are available in stock.';

        if ($currentCartQuantity > 0) {
            $message .= ' You already have ' . $currentCartQuantity . ' in your cart.';
        }

        cart_action_fail($message);
    }
}

function cart_action_total_quantity($conn, $cart_id, $exclude_product_id = '') {
    $sql = "
        SELECT NVL(SUM(quantity), 0) AS TOTAL_QTY
        FROM cart_item
        WHERE cart_id = :cart_id
    ";
    $binds = ['cart_id' => $cart_id];

    if (trim((string)$exclude_product_id) !== '') {
        $sql .= " AND product_id <> :product_id";
        $binds['product_id'] = $exclude_product_id;
    }

    $row = db_one($conn, $sql, $binds);
    return (int)($row['TOTAL_QTY'] ?? 0);
}

function cart_action_enforce_20_item_limit($conn, $cart_id, $new_total_quantity) {
    if ((int)$new_total_quantity > 20) {
        cart_action_fail('Your cart cannot contain more than 20 items per order. Please reduce the quantity before checkout.');
    }
}

function get_or_create_cart_id($conn, $customer_id) {
    $existing = db_one(
        $conn,
        "
        SELECT cart_id
        FROM cart
        WHERE customer_id = :customer_id
        FETCH FIRST 1 ROWS ONLY
        ",
        ['customer_id' => $customer_id]
    );

    if ($existing && !empty($existing['CART_ID'])) {
        return $existing['CART_ID'];
    }

    db_exec(
        $conn,
        "
        INSERT INTO cart (
            customer_id,
            created_time
        ) VALUES (
            :customer_id,
            SYSTIMESTAMP
        )
        ",
        [
            'customer_id' => $customer_id
        ]
    );

    $created = db_one(
        $conn,
        "
        SELECT cart_id
        FROM cart
        WHERE customer_id = :customer_id
        FETCH FIRST 1 ROWS ONLY
        ",
        ['customer_id' => $customer_id]
    );

    if (!$created || empty($created['CART_ID'])) {
        exit('Cart could not be created. Please try again.');
    }

    return $created['CART_ID'];
}

$action = $_POST['action'] ?? 'add';
$product_id = trim($_POST['product_id'] ?? '');
$quantity = max(1, (int)($_POST['quantity'] ?? ($_POST['qty'] ?? 1)));

$customer_id = current_customer_id();

if (!$customer_id) {
    $redirect = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? 'cart.php';
    $redirect = str_replace(["\r", "\n"], '', (string)$redirect);

    if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || str_starts_with($redirect, '//')) {
        $redirect = 'cart.php';
    }

    if ($action === 'add' && $product_id !== '') {
        $_SESSION['pending_customer_action'] = [
            'type' => 'cart',
            'product_id' => $product_id,
            'quantity' => $quantity,
            'redirect' => $redirect
        ];
    }

    header('Location: login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$cart_id = get_or_create_cart_id($conn, $customer_id);

switch ($action) {
    case 'add':
        if ($product_id === '') {
            redirect_back();
        }

        $product = load_cart_action_product($conn, $product_id);

        if (!$product) {
            cart_action_fail('This product is not available to add to cart.');
        }

        $existingProductQty = cart_action_existing_product_quantity($conn, $cart_id, $product_id);
        $newProductQty = $existingProductQty + $quantity;
        $newCartTotal = cart_action_total_quantity($conn, $cart_id) + $quantity;
        $cartActionWarnings = [];
        $availableStock = max(0, (int)($product['STOCK_AVAILABLE'] ?? 0));
        $productName = trim((string)($product['PRODUCT_NAME'] ?? 'This product'));

        if ($newCartTotal > 20) {
            $itemsToRemove = $newCartTotal - 20;
            $cartActionWarnings[] = 'Your cart has ' . $newCartTotal . ' items. One order can contain a maximum of 20 items. Remove ' . $itemsToRemove . ' item' . ($itemsToRemove === 1 ? '' : 's') . ' before checkout.';
        }

        if ($newProductQty > $availableStock) {
            $cartActionWarnings[] = $productName . ' only has ' . $availableStock . ' item' . ($availableStock === 1 ? '' : 's') . ' in stock, but your cart has ' . $newProductQty . '.';
        }

        db_exec(
            $conn,
            "
            MERGE INTO cart_item ci
            USING (
                SELECT
                    :cart_id AS cart_id,
                    :product_id AS product_id,
                    :quantity AS quantity
                FROM dual
            ) src
            ON (
                ci.cart_id = src.cart_id
                AND ci.product_id = src.product_id
            )
            WHEN MATCHED THEN
                UPDATE SET ci.quantity = ci.quantity + src.quantity
            WHEN NOT MATCHED THEN
                INSERT (
                    cart_id,
                    product_id,
                    quantity
                )
                VALUES (
                    src.cart_id,
                    src.product_id,
                    src.quantity
                )
            ",
            [
                'cart_id' => $cart_id,
                'product_id' => $product_id,
                'quantity' => $quantity
            ]
        );

        if ($cartActionWarnings) {
            $_SESSION['cart_checkout_warnings'] = $cartActionWarnings;
            header('Location: cart.php');
            exit;
        }

        redirect_back('cart.php');

    case 'update_qty':
        if ($product_id !== '') {
            $product = load_cart_action_product($conn, $product_id);

            if (!$product) {
                cart_action_fail('This product is no longer available.');
            }

            db_exec(
                $conn,
                "
                UPDATE cart_item
                SET quantity = :quantity
                WHERE cart_id = :cart_id
                AND product_id = :product_id
                ",
                [
                    'quantity' => $quantity,
                    'cart_id' => $cart_id,
                    'product_id' => $product_id
                ]
            );
        }

        redirect_back('cart.php');

    case 'remove':
        if ($product_id !== '') {
            db_exec(
                $conn,
                "
                DELETE FROM cart_item
                WHERE cart_id = :cart_id
                AND product_id = :product_id
                ",
                [
                    'cart_id' => $cart_id,
                    'product_id' => $product_id
                ]
            );
        }

        redirect_back('cart.php');

    case 'clear':
        db_exec(
            $conn,
            "
            DELETE FROM cart_item
            WHERE cart_id = :cart_id
            ",
            ['cart_id' => $cart_id]
        );

        redirect_back('cart.php');

    default:
        redirect_back('cart.php');
}

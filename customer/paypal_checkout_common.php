<?php
require_once __DIR__ . '/customer_common.php';
require_once __DIR__ . '/../config/mail_helpers.php';
require_once __DIR__ . '/../config/paypal_config.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

function money_checkout($amount) {
    return '£' . number_format((float)$amount, 2);
}

function checkout_product_image_url($imageValue) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $imageValue = trim(str_replace('\\', '/', (string)$imageValue));

    if ($imageValue === '') {
        return $placeholder;
    }

    if (preg_match('/^(https?:\/\/|data:image\/)/i', $imageValue)) {
        return $imageValue;
    }

    if (strpos($imageValue, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $imageValue) ? '../' . $imageValue : $placeholder;
    }

    $file = dirname(__DIR__) . '/uploads/products/' . basename($imageValue);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($imageValue)) : $placeholder;
}

function checkout_product_image_select() {
    global $conn;

    foreach (['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_PATH', 'PRODUCT_IMAGE_PATH', 'PRODUCT_PHOTO', 'PHOTO', 'PICTURE', 'PRODUCT_PICTURE'] as $column) {
        if (column_exists($conn, 'PRODUCT', $column)) {
            return 'p.' . $column . ' AS PRODUCT_IMAGE';
        }
    }

    return 'NULL AS PRODUCT_IMAGE';
}

function checkout_slot_definitions() {
    return [
        ['day' => 'WEDNESDAY', 'start' => 10, 'end' => 13],
        ['day' => 'WEDNESDAY', 'start' => 13, 'end' => 16],
        ['day' => 'WEDNESDAY', 'start' => 16, 'end' => 19],
        ['day' => 'THURSDAY',  'start' => 10, 'end' => 13],
        ['day' => 'THURSDAY',  'start' => 13, 'end' => 16],
        ['day' => 'THURSDAY',  'start' => 16, 'end' => 19],
        ['day' => 'FRIDAY',    'start' => 10, 'end' => 13],
        ['day' => 'FRIDAY',    'start' => 13, 'end' => 16],
        ['day' => 'FRIDAY',    'start' => 16, 'end' => 19],
    ];
}

function ensure_pickup_slots() {
    global $conn;

    foreach (checkout_slot_definitions() as $slot) {
        $updated = db_bind_and_execute(
            $conn,
            'UPDATE PICKUP_SLOT
             SET MAX_CAPACITY = :max_capacity
             WHERE ALLOWED_DAY = :allowed_day
               AND START_HOUR = :start_hour
               AND END_HOUR = :end_hour',
            [
                ':max_capacity' => 20,
                ':allowed_day' => $slot['day'],
                ':start_hour' => $slot['start'],
                ':end_hour' => $slot['end']
            ]
        );

        if (oci_num_rows($updated) === 0) {
            db_bind_and_execute(
                $conn,
                'INSERT INTO PICKUP_SLOT (ALLOWED_DAY, START_HOUR, END_HOUR, MAX_CAPACITY)
                 VALUES (:allowed_day, :start_hour, :end_hour, :max_capacity)',
                [
                    ':allowed_day' => $slot['day'],
                    ':start_hour' => $slot['start'],
                    ':end_hour' => $slot['end'],
                    ':max_capacity' => 20
                ]
            );
        }
    }
}

function checkout_time_label($hour) {
    $hour = (int)$hour;
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }
    return $displayHour . ':00 ' . $suffix;
}

function checkout_slot_period($startHour) {
    $startHour = (int)$startHour;
    if ($startHour === 10) {
        return ['Morning', '☀'];
    }
    if ($startHour === 13) {
        return ['Afternoon', '◐'];
    }
    return ['Evening', '☾'];
}

function checkout_validate_date($date) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    $errors = DateTime::getLastErrors();

    if (!$dt || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return false;
    }

    return $dt->format('Y-m-d') === $date;
}

function load_checkout_slots(): array {
    global $conn;
    ensure_pickup_slots();

    return db_all(
        $conn,
        "SELECT SLOT_ID, ALLOWED_DAY, START_HOUR, END_HOUR, MAX_CAPACITY
         FROM (
             SELECT
                 SLOT_ID,
                 ALLOWED_DAY,
                 START_HOUR,
                 END_HOUR,
                 NVL(MAX_CAPACITY, 20) AS MAX_CAPACITY,
                 ROW_NUMBER() OVER (
                     PARTITION BY ALLOWED_DAY, START_HOUR, END_HOUR
                     ORDER BY SLOT_ID
                 ) AS RN
             FROM PICKUP_SLOT
             WHERE ALLOWED_DAY IN ('WEDNESDAY', 'THURSDAY', 'FRIDAY')
               AND (
                    (START_HOUR = 10 AND END_HOUR = 13)
                 OR (START_HOUR = 13 AND END_HOUR = 16)
                 OR (START_HOUR = 16 AND END_HOUR = 19)
               )
         )
         WHERE RN = 1
         ORDER BY CASE ALLOWED_DAY
                    WHEN 'WEDNESDAY' THEN 1
                    WHEN 'THURSDAY' THEN 2
                    WHEN 'FRIDAY' THEN 3
                    ELSE 4
                  END,
                  START_HOUR"
    );
}

function load_checkout_cart($customerId) {
    global $conn;

    remove_unavailable_products_from_customer_cart($conn, (string)$customerId);

    $imageSelect = checkout_product_image_select();
    $publicProductFilter = function_exists('customer_public_product_filter') ? customer_public_product_filter('p', 's') : '1 = 1';
    $activeDiscountSubquery = function_exists('customer_active_discount_subquery')
        ? customer_active_discount_subquery('PRODUCT_ID', 'DISCOUNT_PERCENTAGE')
        : "SELECT PRODUCT_ID, MAX(DISCOUNT_PERCENTAGE) AS DISCOUNT_PERCENTAGE FROM DISCOUNT WHERE DISCOUNT_PERCENTAGE > 0 AND DISCOUNT_PERCENTAGE <= 100 AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE) GROUP BY PRODUCT_ID";

    $rows = db_all(
        $conn,
        "
        SELECT
            c.CART_ID,
            ci.PRODUCT_ID,
            ci.QUANTITY,
            p.PRODUCT_NAME,
            $imageSelect,
            p.ITEM_PRICE,
            NVL(p.STOCK_AVAILABLE, 0) AS STOCK_AVAILABLE,
            NVL(p.MIN_ORDER, 1) AS MIN_ORDER,
            NVL(p.MAX_ORDER, 100) AS MAX_ORDER,
            s.TRADER_ID,
            s.SHOP_NAME,
            NVL(d.DISCOUNT_PERCENTAGE, 0) AS DISCOUNT_PERCENTAGE
        FROM CART c
        JOIN CART_ITEM ci ON ci.CART_ID = c.CART_ID
        JOIN PRODUCT p ON p.PRODUCT_ID = ci.PRODUCT_ID
        JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        LEFT JOIN (
            $activeDiscountSubquery
        ) d ON d.PRODUCT_ID = p.PRODUCT_ID
        WHERE c.CUSTOMER_ID = :customer_id
          AND $publicProductFilter
        ORDER BY p.PRODUCT_NAME
        ",
        [':customer_id' => $customerId]
    );

    $items = [];
    $subtotal = 0;
    $cartId = '';

    foreach ($rows as $row) {
        $price = (float)$row['ITEM_PRICE'];
        $discount = max(0, min(100, (float)$row['DISCOUNT_PERCENTAGE']));
        $lockedPrice = round($price - ($price * $discount / 100), 2);
        $quantity = max(1, (int)$row['QUANTITY']);
        $lineTotal = $lockedPrice * $quantity;
        $cartId = $row['CART_ID'];

        $items[] = [
            'product_id' => $row['PRODUCT_ID'],
            'product_name' => $row['PRODUCT_NAME'],
            'image' => checkout_product_image_url($row['PRODUCT_IMAGE'] ?? ''),
            'shop_name' => $row['SHOP_NAME'],
            'trader_id' => $row['TRADER_ID'],
            'quantity' => $quantity,
            'stock_available' => (int)$row['STOCK_AVAILABLE'],
            'min_order' => max(1, (int)($row['MIN_ORDER'] ?? 1)),
            'max_order' => max(1, (int)($row['MAX_ORDER'] ?? 100)),
            'locked_price' => $lockedPrice,
            'line_total' => $lineTotal
        ];

        $subtotal += $lineTotal;
    }

    return [$cartId, $items, $subtotal];
}

function load_checkout_voucher($subtotal) {
    $code = $_SESSION['cart_voucher_code'] ?? '';
    if ($code === '' || $subtotal <= 0) {
        return [null, 0];
    }

    if (function_exists('customer_validate_voucher')) {
        $notice = '';
        [$voucher, $discount] = customer_validate_voucher($GLOBALS['conn'], $code, $subtotal, $notice);
        if (!$voucher) {
            unset($_SESSION['cart_voucher_code']);
            return [null, 0];
        }
        return [$voucher, $discount];
    }

    $voucher = db_one(
        $GLOBALS['conn'],
        'SELECT VOUCHER_ID, VOUCHER_CODE, DISCOUNT_TYPE, DISCOUNT_VALUE, MIN_ORDER_AMOUNT, USED_COUNT, USAGE_LIMIT
         FROM VOUCHER
         WHERE UPPER(VOUCHER_CODE) = :voucher_code
           AND UPPER(STATUS) = :status
           AND START_DATE IS NOT NULL
           AND END_DATE IS NOT NULL
           AND END_DATE > START_DATE
           AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE)
           AND NVL(USED_COUNT, 0) < NVL(USAGE_LIMIT, 0)',
        [':voucher_code' => strtoupper($code), ':status' => 'ACTIVE']
    );

    if (!$voucher || $subtotal < (float)$voucher['MIN_ORDER_AMOUNT']) {
        return [null, 0];
    }

    $discount = strtoupper((string)$voucher['DISCOUNT_TYPE']) === 'PERCENTAGE'
        ? round($subtotal * (max(0, min(100, (float)$voucher['DISCOUNT_VALUE'])) / 100), 2)
        : max(0, (float)$voucher['DISCOUNT_VALUE']);

    return [$voucher, min($discount, $subtotal)];
}

function checkout_slot_text_for_email(?array $selectedSlot): string {
    if (!$selectedSlot) {
        return 'Not selected';
    }

    return ucwords(strtolower((string)($selectedSlot['ALLOWED_DAY'] ?? '')))
        . ' '
        . str_pad((string)($selectedSlot['START_HOUR'] ?? ''), 2, '0', STR_PAD_LEFT)
        . ':00-'
        . str_pad((string)($selectedSlot['END_HOUR'] ?? ''), 2, '0', STR_PAD_LEFT)
        . ':00';
}

function checkout_send_customer_order_email($conn, string $orderId, string $customerId, array $items, string $pickupDate, ?array $selectedSlot, float $subtotal, float $discount, float $total): void {
    if (!$conn || trim($orderId) === '' || trim($customerId) === '' || !$items) {
        return;
    }

    try {
        $customer = db_one($conn, "
            SELECT
                EMAIL_ADDRESS,
                NVL(TRIM(FIRST_NAME || ' ' || LAST_NAME), 'Customer') AS CUSTOMER_NAME
            FROM \"USER\"
            WHERE USER_ID = :customer_id
              AND USER_ROLE = :role
            FETCH FIRST 1 ROWS ONLY
        ", [':customer_id' => $customerId, ':role' => 'CUSTOMER']);

        $email = (string)($customer['EMAIL_ADDRESS'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $body = "Hello " . (string)($customer['CUSTOMER_NAME'] ?? 'Customer') . ",

";
        $body .= "Thank you for your ShopLocalfy order.

";
        $body .= "Order ID: " . $orderId . "
";
        $body .= "Pickup date: " . $pickupDate . "
";
        $body .= "Pickup slot: " . checkout_slot_text_for_email($selectedSlot) . "

";
        $body .= "Items ordered:
";

        foreach ($items as $item) {
            $body .= "- " . (string)($item['product_name'] ?? 'Product')
                . " from " . (string)($item['shop_name'] ?? 'Shop')
                . " x " . (int)($item['quantity'] ?? 0)
                . " @ £" . number_format((float)($item['locked_price'] ?? 0), 2)
                . " = £" . number_format((float)($item['line_total'] ?? 0), 2)
                . "
";
        }

        $body .= "
Subtotal: £" . number_format($subtotal, 2) . "
";
        if ($discount > 0) {
            $body .= "Discount: -£" . number_format($discount, 2) . "
";
        }
        $body .= "Total paid: £" . number_format($total, 2) . "

";
        $body .= "Please collect your order during the selected pickup slot.

";
        $body .= "ShopLocalfy";

        shoplocalfy_send_plain_email(
            $email,
            'Your ShopLocalfy order ' . $orderId,
            $body
        );
    } catch (Throwable $e) {
        shoplocalfy_log_error('Could not email customer order confirmation', $e->getMessage());
    }
}

function checkout_send_trader_order_emails($conn, string $orderId, array $items, string $pickupDate, ?array $selectedSlot): void {
    if (!$conn || !$items || trim($orderId) === '') {
        return;
    }

    $groups = [];

    foreach ($items as $item) {
        $traderId = (string)($item['trader_id'] ?? '');
        if ($traderId === '') {
            continue;
        }

        if (!isset($groups[$traderId])) {
            $groups[$traderId] = [
                'items' => [],
                'total' => 0.0,
            ];
        }

        $groups[$traderId]['items'][] = $item;
        $groups[$traderId]['total'] += (float)($item['line_total'] ?? 0);
    }

    if (!$groups) {
        return;
    }

    $slotText = checkout_slot_text_for_email($selectedSlot);

    foreach ($groups as $traderId => $group) {
        try {
            $trader = db_one($conn, "
                SELECT
                    u.EMAIL_ADDRESS,
                    NVL(TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME), 'Trader') AS TRADER_NAME,
                    NVL(s.SHOP_NAME, 'Your shop') AS SHOP_NAME
                FROM \"USER\" u
                LEFT JOIN SHOP s ON s.TRADER_ID = u.USER_ID
                WHERE u.USER_ID = :trader_id
                FETCH FIRST 1 ROWS ONLY
            ", [':trader_id' => $traderId]);

            $email = (string)($trader['EMAIL_ADDRESS'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $body = "Hello " . (string)($trader['TRADER_NAME'] ?? 'Trader') . ",\n\n";
            $body .= "A new ShopLocalfy order has been placed for " . (string)($trader['SHOP_NAME'] ?? 'your shop') . ".\n\n";
            $body .= "Order ID: " . $orderId . "\n";
            $body .= "Pickup date: " . $pickupDate . "\n";
            $body .= "Pickup slot: " . $slotText . "\n\n";
            $body .= "Items to prepare:\n";

            foreach ($group['items'] as $item) {
                $body .= "- " . (string)($item['product_name'] ?? 'Product')
                    . " x " . (int)($item['quantity'] ?? 0)
                    . " @ £" . number_format((float)($item['locked_price'] ?? 0), 2)
                    . " = £" . number_format((float)($item['line_total'] ?? 0), 2)
                    . "\n";
            }

            $body .= "\nTotal for your shop: £" . number_format((float)$group['total'], 2) . "\n\n";
            $body .= "Please prepare these items for collection.\n\n";
            $body .= "ShopLocalfy";

            shoplocalfy_send_plain_email(
                $email,
                'New ShopLocalfy order ' . $orderId,
                $body
            );
        } catch (Throwable $e) {
            shoplocalfy_log_error('Could not email trader order notification', $e->getMessage());
        }
    }
}

function checkout_validate_snapshot(string $customerId, string $slotId, string $pickupDate): array {
    global $conn;
    [$cartId, $items, $subtotal] = load_checkout_cart($customerId);
    [$voucher, $voucherDiscount] = load_checkout_voucher($subtotal);
    $total = max(0, $subtotal - $voucherDiscount);
    $errors = [];
    $selectedSlot = null;

    $totalCartQuantity = 0;
    foreach ($items as $item) {
        $totalCartQuantity += (int)($item['quantity'] ?? 0);
    }

    if (empty($items)) {
        $errors[] = 'Your cart is empty.';
    }

    if ($totalCartQuantity > 20) {
        $errors[] = 'One order can contain a maximum of 20 items.';
    }

    if ($pickupDate === '') {
        $errors[] = 'Choose a pickup date.';
    } elseif (!checkout_validate_date($pickupDate)) {
        $errors[] = 'Choose a valid pickup date.';
    }

    if ($slotId === '') {
        $errors[] = 'Choose a pickup slot.';
    } else {
        $selectedSlot = db_one(
            $conn,
            "SELECT SLOT_ID, ALLOWED_DAY, START_HOUR, END_HOUR, NVL(MAX_CAPACITY, 20) AS MAX_CAPACITY
             FROM PICKUP_SLOT
             WHERE SLOT_ID = :slot_id
               AND ALLOWED_DAY IN ('WEDNESDAY', 'THURSDAY', 'FRIDAY')
               AND (
                    (START_HOUR = 10 AND END_HOUR = 13)
                 OR (START_HOUR = 13 AND END_HOUR = 16)
                 OR (START_HOUR = 16 AND END_HOUR = 19)
               )",
            [':slot_id' => $slotId]
        );

        if (!$selectedSlot) {
            $errors[] = 'Choose a valid pickup slot.';
        }
    }

    if ($pickupDate !== '' && checkout_validate_date($pickupDate) && $selectedSlot) {
        $pickupDay = strtoupper(date('l', strtotime($pickupDate)));

        if (!in_array($pickupDay, ['WEDNESDAY', 'THURSDAY', 'FRIDAY'], true)) {
            $errors[] = 'Pickup is only available on Wednesday, Thursday, and Friday.';
        }

        if ($pickupDay !== strtoupper($selectedSlot['ALLOWED_DAY'])) {
            $errors[] = 'Pickup date must match the selected slot day: ' . ucwords(strtolower($selectedSlot['ALLOWED_DAY'])) . '.';
        }

        $pickupStartTime = strtotime($pickupDate . ' ' . str_pad((string)$selectedSlot['START_HOUR'], 2, '0', STR_PAD_LEFT) . ':00:00');
        if ($pickupStartTime === false || $pickupStartTime < (time() + 86400)) {
            $errors[] = 'Pickup slot must be at least 24 hours after placing the order.';
        }

        $bookedRow = db_one(
            $conn,
            "SELECT COUNT(*) AS TOTAL
             FROM ORDERS o
             INNER JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
             WHERE ps.ALLOWED_DAY = :allowed_day
               AND ps.START_HOUR = :start_hour
               AND ps.END_HOUR = :end_hour
               AND TRUNC(o.PICKUP_DATE) = TO_DATE(:pickup_date, 'YYYY-MM-DD')
               AND NVL(o.ORDER_STATUS, 'CONFIRMED') NOT IN ('CANCELLED', 'CANCELED', 'REJECTED')",
            [
                ':allowed_day' => $selectedSlot['ALLOWED_DAY'],
                ':start_hour' => $selectedSlot['START_HOUR'],
                ':end_hour' => $selectedSlot['END_HOUR'],
                ':pickup_date' => $pickupDate
            ]
        );

        $bookedCount = (int)($bookedRow['TOTAL'] ?? 0);
        $maxCapacity = (int)($selectedSlot['MAX_CAPACITY'] ?? 20);

        if ($bookedCount >= $maxCapacity) {
            $errors[] = 'This pickup slot is full. Please choose another slot.';
        }
    }

    foreach ($items as $item) {
        if ($item['quantity'] < $item['min_order']) {
            $errors[] = $item['product_name'] . ' has a minimum order quantity of ' . $item['min_order'] . '.';
        }
        if ($item['quantity'] > $item['max_order']) {
            $errors[] = $item['product_name'] . ' has a maximum order quantity of ' . $item['max_order'] . '.';
        }
        if ($item['quantity'] > $item['stock_available']) {
            $errors[] = $item['product_name'] . ' only has ' . $item['stock_available'] . ' in stock.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'cart_id' => $cartId,
        'items' => $items,
        'subtotal' => round((float)$subtotal, 2),
        'voucher' => $voucher,
        'voucher_discount' => round((float)$voucherDiscount, 2),
        'total' => round((float)$total, 2),
        'slot_id' => $slotId,
        'pickup_date' => $pickupDate,
        'selected_slot' => $selectedSlot,
        'customer_id' => $customerId,
    ];
}

function shoplocalfy_absolute_customer_url(string $file): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/main_project/customer'), '/\\');
    return $scheme . '://' . $host . $basePath . '/' . ltrim($file, '/');
}

function paypal_http_request(string $method, string $path, array $headers = [], ?array $payload = null): array {
    $url = rtrim(shoplocalfy_paypal_base_url(), '/') . $path;
    $ch = curl_init($url);

    $defaultHeaders = ['Accept: application/json'];
    if ($payload !== null) {
        $defaultHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($payload !== null) {
        $body = empty($payload) ? '{}' : json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('PayPal connection failed: ' . $curlError);
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = ['raw' => (string)$raw];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $data['message'] ?? $data['error_description'] ?? $data['name'] ?? 'PayPal request failed.';
        throw new RuntimeException('PayPal error: ' . $message);
    }

    return $data;
}

function paypal_get_access_token(): string {
    $credentials = base64_encode(SHOPLOCALFY_PAYPAL_CLIENT_ID . ':' . SHOPLOCALFY_PAYPAL_CLIENT_SECRET);
    $url = rtrim(shoplocalfy_paypal_base_url(), '/') . '/v1/oauth2/token';
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en_US',
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('PayPal OAuth connection failed: ' . $curlError);
    }

    $data = json_decode((string)$raw, true);
    if ($statusCode < 200 || $statusCode >= 300 || empty($data['access_token'])) {
        $message = $data['error_description'] ?? $data['error'] ?? 'Could not get PayPal access token.';
        throw new RuntimeException('PayPal OAuth error: ' . $message);
    }

    return (string)$data['access_token'];
}

function paypal_create_checkout_order(float $amount, string $description): array {
    $token = paypal_get_access_token();
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'description' => substr($description, 0, 120),
            'amount' => [
                'currency_code' => SHOPLOCALFY_PAYPAL_CURRENCY,
                'value' => number_format($amount, 2, '.', ''),
            ],
        ]],
        'application_context' => [
            'brand_name' => 'ShopLocalfy',
            'landing_page' => 'LOGIN',
            'user_action' => 'PAY_NOW',
            'return_url' => shoplocalfy_absolute_customer_url('paypal-return.php'),
            'cancel_url' => shoplocalfy_absolute_customer_url('paypal-cancel.php'),
        ],
    ];

    return paypal_http_request('POST', '/v2/checkout/orders', ['Authorization: Bearer ' . $token], $payload);
}

function paypal_capture_checkout_order(string $paypalOrderId): array {
    $token = paypal_get_access_token();
    return paypal_http_request('POST', '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture', ['Authorization: Bearer ' . $token], []);
}

function paypal_find_approval_url(array $order): string {
    foreach (($order['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
            return (string)$link['href'];
        }
    }
    throw new RuntimeException('PayPal did not return an approval link.');
}

function paypal_capture_is_completed(array $capture): bool {
    if (strtoupper((string)($capture['status'] ?? '')) === 'COMPLETED') {
        return true;
    }

    foreach (($capture['purchase_units'] ?? []) as $unit) {
        foreach (($unit['payments']['captures'] ?? []) as $cap) {
            if (strtoupper((string)($cap['status'] ?? '')) === 'COMPLETED') {
                return true;
            }
        }
    }

    return false;
}


function checkout_send_order_emails_safely($conn, string $orderId, string $customerId, array $items, string $pickupDate, ?array $selectedSlot, float $subtotal, float $voucherDiscount, float $total): void {
    try {
        checkout_send_customer_order_email($conn, $orderId, $customerId, $items, $pickupDate, $selectedSlot, $subtotal, $voucherDiscount, $total);
    } catch (Throwable $e) {
        shoplocalfy_log_error('Customer order confirmation email failed after order creation', $e->getMessage());
    }

    try {
        checkout_send_trader_order_emails($conn, $orderId, $items, $pickupDate, $selectedSlot);
    } catch (Throwable $e) {
        shoplocalfy_log_error('Trader order notification email failed after order creation', $e->getMessage());
    }
}

function checkout_create_local_order(array $snapshot): string {
    global $conn;

    $customerId = (string)$snapshot['customer_id'];
    $slotId = (string)$snapshot['slot_id'];
    $pickupDate = (string)$snapshot['pickup_date'];
    $cartId = (string)$snapshot['cart_id'];
    $items = $snapshot['items'] ?? [];
    $voucher = $snapshot['voucher'] ?? null;
    $subtotal = (float)($snapshot['subtotal'] ?? 0);
    $voucherDiscount = (float)($snapshot['voucher_discount'] ?? 0);
    $total = (float)($snapshot['total'] ?? 0);
    $selectedSlot = $snapshot['selected_slot'] ?? null;

    if (!$items || $total < 0 || $customerId === '' || $slotId === '' || $pickupDate === '') {
        throw new RuntimeException('Checkout session is incomplete. Please try again.');
    }

    foreach ($items as $item) {
        $stockRow = db_one($conn, 'SELECT NVL(STOCK_AVAILABLE, 0) AS STOCK_AVAILABLE FROM PRODUCT WHERE PRODUCT_ID = :product_id', [':product_id' => $item['product_id']]);
        if (!$stockRow || (int)$stockRow['STOCK_AVAILABLE'] < (int)$item['quantity']) {
            throw new RuntimeException($item['product_name'] . ' no longer has enough stock.');
        }
    }

    $orderId = '';
    $paymentId = '';
    $voucherId = is_array($voucher) ? ($voucher['VOUCHER_ID'] ?? null) : null;

    $orderStmt = oci_parse($conn, 'INSERT INTO ORDERS (
            CUSTOMER_ID, SLOT_ID, VOUCHER_ID, PICKUP_DATE,
            ORDER_DATE, DISCOUNT_APPLIED, TOTAL_AMOUNT, ORDER_STATUS
         ) VALUES (
            :customer_id, :slot_id, :voucher_id, TO_DATE(:pickup_date, \'YYYY-MM-DD\'),
            TRUNC(SYSDATE), :discount_applied, :total_amount, :order_status
         ) RETURNING ORDER_ID INTO :order_id');

    if (!$orderStmt) {
        throw new RuntimeException(oracle_error_message($conn));
    }

    $confirmedStatus = 'CONFIRMED';
    oci_bind_by_name($orderStmt, ':customer_id', $customerId);
    oci_bind_by_name($orderStmt, ':slot_id', $slotId);
    oci_bind_by_name($orderStmt, ':voucher_id', $voucherId);
    oci_bind_by_name($orderStmt, ':pickup_date', $pickupDate);
    oci_bind_by_name($orderStmt, ':discount_applied', $voucherDiscount);
    oci_bind_by_name($orderStmt, ':total_amount', $total);
    oci_bind_by_name($orderStmt, ':order_status', $confirmedStatus);
    oci_bind_by_name($orderStmt, ':order_id', $orderId, 20);

    if (!oci_execute($orderStmt, OCI_NO_AUTO_COMMIT)) {
        throw new RuntimeException(oracle_error_message($orderStmt));
    }

    if (trim($orderId) === '') {
        throw new RuntimeException('Order could not be created. Please try again.');
    }

    foreach ($items as $item) {
        db_bind_and_execute(
            $conn,
            'INSERT INTO ORDER_ITEM (ORDER_ID, PRODUCT_ID, TRADER_ID, QUANTITY, LOCKED_PRICE, ITEM_STATUS)
             VALUES (:order_id, :product_id, :trader_id, :quantity, :locked_price, :item_status)',
            [
                ':order_id' => $orderId,
                ':product_id' => $item['product_id'],
                ':trader_id' => $item['trader_id'],
                ':quantity' => $item['quantity'],
                ':locked_price' => $item['locked_price'],
                ':item_status' => 'PENDING'
            ],
            OCI_NO_AUTO_COMMIT
        );

        $stockStmt = db_bind_and_execute(
            $conn,
            'UPDATE PRODUCT
             SET STOCK_AVAILABLE = NVL(STOCK_AVAILABLE, 0) - :quantity
             WHERE PRODUCT_ID = :product_id
               AND NVL(STOCK_AVAILABLE, 0) >= :quantity',
            [
                ':quantity' => $item['quantity'],
                ':product_id' => $item['product_id']
            ],
            OCI_NO_AUTO_COMMIT
        );

        if (oci_num_rows($stockStmt) !== 1) {
            throw new RuntimeException($item['product_name'] . ' no longer has enough stock.');
        }
    }

    $paymentStatus = 'COMPLETED';
    $paymentMethod = 'PAYPAL';
    $paymentStmt = oci_parse($conn, 'INSERT INTO PAYMENT (ORDER_ID, CUSTOMER_ID, AMOUNT_PAID, PAYMENT_METHOD, PAYMENT_STATUS, PAYMENT_DATE)
         VALUES (:order_id, :customer_id, :amount_paid, :payment_method, :payment_status, SYSDATE)
         RETURNING PAYMENT_ID INTO :payment_id');
    if (!$paymentStmt) {
        throw new RuntimeException(oracle_error_message($conn));
    }
    oci_bind_by_name($paymentStmt, ':order_id', $orderId);
    oci_bind_by_name($paymentStmt, ':customer_id', $customerId);
    oci_bind_by_name($paymentStmt, ':amount_paid', $total);
    oci_bind_by_name($paymentStmt, ':payment_method', $paymentMethod);
    oci_bind_by_name($paymentStmt, ':payment_status', $paymentStatus);
    oci_bind_by_name($paymentStmt, ':payment_id', $paymentId, 20);
    if (!oci_execute($paymentStmt, OCI_NO_AUTO_COMMIT)) {
        throw new RuntimeException(oracle_error_message($paymentStmt));
    }

    if (is_array($voucher) && !empty($voucher['VOUCHER_ID'])) {
        $voucherStmt = db_bind_and_execute(
            $conn,
            'UPDATE VOUCHER
             SET USED_COUNT = NVL(USED_COUNT, 0) + 1
             WHERE VOUCHER_ID = :voucher_id
               AND NVL(USED_COUNT, 0) < NVL(USAGE_LIMIT, 0)
               AND UPPER(STATUS) = :status
               AND START_DATE IS NOT NULL
               AND END_DATE IS NOT NULL
               AND END_DATE > START_DATE
               AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE)',
            [':voucher_id' => $voucher['VOUCHER_ID'], ':status' => 'ACTIVE'],
            OCI_NO_AUTO_COMMIT
        );

        if (oci_num_rows($voucherStmt) !== 1) {
            throw new RuntimeException('Voucher usage limit has been reached.');
        }
    }

    if ($cartId !== '') {
        db_bind_and_execute(
            $conn,
            'DELETE FROM CART_ITEM WHERE CART_ID = :cart_id',
            [':cart_id' => $cartId],
            OCI_NO_AUTO_COMMIT
        );
    }

    oci_commit($conn);

    // Email is useful for the demo, but it must never break checkout after payment/order creation.
    checkout_send_order_emails_safely(
        $conn,
        $orderId,
        $customerId,
        $items,
        $pickupDate,
        is_array($selectedSlot) ? $selectedSlot : null,
        $subtotal,
        $voucherDiscount,
        $total
    );

    unset($_SESSION['cart_voucher_code']);

    return $orderId;
}

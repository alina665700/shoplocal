<?php

require_once __DIR__ . '/mail_helpers.php';

if (!function_exists('shoplocalfy_order_email_log')) {
    function shoplocalfy_order_email_log(string $message, string $detail = ''): void {
        if (function_exists('shoplocalfy_log_error')) {
            shoplocalfy_log_error($message, $detail);
        }
    }
}

if (!function_exists('shoplocalfy_order_email_money')) {
    function shoplocalfy_order_email_money($value): string {
        return '£' . number_format((float)$value, 2);
    }
}

if (!function_exists('shoplocalfy_order_cancellation_rows')) {
    function shoplocalfy_order_cancellation_rows($conn, string $orderId, ?string $productId = null): array {
        if (!$conn || $orderId === '' || !function_exists('db_all')) {
            return [];
        }

        $productFilter = '';
        $binds = [':order_id' => $orderId];
        if ($productId !== null && trim($productId) !== '') {
            $productFilter = ' AND oi.PRODUCT_ID = :product_id';
            $binds[':product_id'] = trim($productId);
        }

        try {
            return db_all($conn, "
                SELECT
                    o.ORDER_ID,
                    TO_CHAR(o.PICKUP_DATE, 'YYYY-MM-DD') AS PICKUP_DATE_TEXT,
                    ps.START_HOUR,
                    ps.END_HOUR,
                    p.AMOUNT_PAID,
                    p.PAYMENT_STATUS,
                    cu.EMAIL_ADDRESS AS CUSTOMER_EMAIL,
                    TRIM(cu.FIRST_NAME || ' ' || cu.LAST_NAME) AS CUSTOMER_NAME,
                    tu.EMAIL_ADDRESS AS TRADER_EMAIL,
                    TRIM(tu.FIRST_NAME || ' ' || tu.LAST_NAME) AS TRADER_NAME,
                    NVL(s.SHOP_NAME, 'ShopLocalfy trader') AS SHOP_NAME,
                    oi.TRADER_ID,
                    oi.PRODUCT_ID,
                    pr.PRODUCT_NAME,
                    oi.QUANTITY,
                    oi.LOCKED_PRICE,
                    (oi.QUANTITY * oi.LOCKED_PRICE) AS LINE_TOTAL,
                    (
                        SELECT NVL(SUM(x.QUANTITY * x.LOCKED_PRICE), 0)
                        FROM ORDER_ITEM x
                        WHERE x.ORDER_ID = oi.ORDER_ID
                    ) AS ORDER_GROSS
                FROM ORDERS o
                INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
                INNER JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
                INNER JOIN \"USER\" cu ON cu.USER_ID = o.CUSTOMER_ID
                INNER JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
                INNER JOIN PRODUCT pr ON pr.PRODUCT_ID = oi.PRODUCT_ID
                INNER JOIN TRADER t ON t.USER_ID = oi.TRADER_ID
                INNER JOIN \"USER\" tu ON tu.USER_ID = t.USER_ID
                LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
                WHERE o.ORDER_ID = :order_id
                  $productFilter
                  AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) = 'CANCELLED'
                ORDER BY tu.FIRST_NAME ASC, pr.PRODUCT_NAME ASC
            ", $binds);
        } catch (Throwable $e) {
            shoplocalfy_order_email_log('Could not load cancellation email rows', $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('shoplocalfy_order_estimated_refund')) {
    function shoplocalfy_order_estimated_refund(array $rows): float {
        if (!$rows) {
            return 0.0;
        }

        $amountPaid = (float)($rows[0]['AMOUNT_PAID'] ?? 0);
        $orderGross = (float)($rows[0]['ORDER_GROSS'] ?? 0);
        $cancelledGross = 0.0;

        foreach ($rows as $row) {
            $cancelledGross += (float)($row['LINE_TOTAL'] ?? 0);
        }

        if ($amountPaid > 0 && $orderGross > 0) {
            return round(($cancelledGross / $orderGross) * $amountPaid, 2);
        }

        return round($cancelledGross, 2);
    }
}

if (!function_exists('shoplocalfy_pickup_slot_text_for_order_email')) {
    function shoplocalfy_pickup_slot_text_for_order_email(array $row): string {
        $start = isset($row['START_HOUR']) ? str_pad((string)$row['START_HOUR'], 2, '0', STR_PAD_LEFT) . ':00' : '—';
        $end = isset($row['END_HOUR']) ? str_pad((string)$row['END_HOUR'], 2, '0', STR_PAD_LEFT) . ':00' : '—';
        return $start . '-' . $end;
    }
}

if (!function_exists('shoplocalfy_send_order_cancellation_emails')) {
    function shoplocalfy_send_order_cancellation_emails($conn, string $orderId, ?string $productId = null, string $reason = 'cancelled'): void {
        $rows = shoplocalfy_order_cancellation_rows($conn, $orderId, $productId);
        if (!$rows) {
            return;
        }

        $first = $rows[0];
        $customerEmail = (string)($first['CUSTOMER_EMAIL'] ?? '');
        $customerName = trim((string)($first['CUSTOMER_NAME'] ?? 'Customer')) ?: 'Customer';
        $pickupDate = (string)($first['PICKUP_DATE_TEXT'] ?? '');
        $pickupSlot = shoplocalfy_pickup_slot_text_for_order_email($first);
        $estimatedRefund = shoplocalfy_order_estimated_refund($rows);
        $reasonText = $reason === 'overdue'
            ? 'because the pickup slot has passed and the order was not collected'
            : 'by the ShopLocalfy admin';

        if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $body = "Hello " . $customerName . ",\n\n";
            $body .= "Your ShopLocalfy order " . $orderId . " has been cancelled " . $reasonText . ".\n\n";
            $body .= "Pickup date: " . ($pickupDate ?: 'Not recorded') . "\n";
            $body .= "Pickup slot: " . $pickupSlot . "\n\n";
            $body .= "Cancelled item(s):\n";
            foreach ($rows as $row) {
                $body .= "- " . (string)($row['PRODUCT_NAME'] ?? 'Product')
                    . " from " . (string)($row['SHOP_NAME'] ?? 'Shop')
                    . " x " . (int)($row['QUANTITY'] ?? 0)
                    . " = " . shoplocalfy_order_email_money($row['LINE_TOTAL'] ?? 0)
                    . "\n";
            }
            $body .= "\nEstimated refund owed: " . shoplocalfy_order_email_money($estimatedRefund) . "\n";
            $body .= "Refund status: the PayPal Sandbox refund is handled manually by the admin.\n";
            $body .= "You can check your order history in your ShopLocalfy account.\n\n";
            $body .= "ShopLocalfy";

            if (!shoplocalfy_send_plain_email($customerEmail, 'ShopLocalfy order cancelled: ' . $orderId, $body)) {
                shoplocalfy_order_email_log('Customer cancellation email failed', $orderId . ' / ' . $customerEmail);
            }
        }

        $traderGroups = [];
        foreach ($rows as $row) {
            $traderId = (string)($row['TRADER_ID'] ?? '');
            if ($traderId === '') {
                continue;
            }
            if (!isset($traderGroups[$traderId])) {
                $traderGroups[$traderId] = [
                    'email' => (string)($row['TRADER_EMAIL'] ?? ''),
                    'name' => trim((string)($row['TRADER_NAME'] ?? 'Trader')) ?: 'Trader',
                    'shop' => (string)($row['SHOP_NAME'] ?? 'your shop'),
                    'items' => [],
                ];
            }
            $traderGroups[$traderId]['items'][] = $row;
        }

        foreach ($traderGroups as $group) {
            $traderEmail = (string)$group['email'];
            if (!filter_var($traderEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $body = "Hello " . $group['name'] . ",\n\n";
            $body .= "An item from ShopLocalfy order " . $orderId . " has been cancelled " . $reasonText . ".\n\n";
            $body .= "Shop: " . $group['shop'] . "\n";
            $body .= "Pickup date: " . ($pickupDate ?: 'Not recorded') . "\n";
            $body .= "Pickup slot: " . $pickupSlot . "\n\n";
            $body .= "Cancelled item(s):\n";
            foreach ($group['items'] as $item) {
                $body .= "- " . (string)($item['PRODUCT_NAME'] ?? 'Product')
                    . " x " . (int)($item['QUANTITY'] ?? 0)
                    . " = " . shoplocalfy_order_email_money($item['LINE_TOTAL'] ?? 0)
                    . "\n";
            }
            $body .= "\nThe stock has been restored locally. No collection preparation is required for the cancelled item(s).\n\n";
            $body .= "ShopLocalfy";

            if (!shoplocalfy_send_plain_email($traderEmail, 'ShopLocalfy order item cancelled: ' . $orderId, $body)) {
                shoplocalfy_order_email_log('Trader cancellation email failed', $orderId . ' / ' . $traderEmail);
            }
        }
    }
}

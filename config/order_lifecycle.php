<?php

require_once __DIR__ . '/order_email_notifications.php';

if (!function_exists('sl_order_lifecycle_ready')) {
    function sl_order_lifecycle_ready($conn) {
        return $conn
            && function_exists('table_exists')
            && function_exists('column_exists')
            && function_exists('db_one')
            && function_exists('db_all')
            && function_exists('db_bind_and_execute')
            && table_exists($conn, 'ORDERS')
            && table_exists($conn, 'ORDER_ITEM')
            && table_exists($conn, 'PAYMENT')
            && table_exists($conn, 'PRODUCT')
            && table_exists($conn, 'PICKUP_SLOT')
            && column_exists($conn, 'ORDERS', 'PICKUP_DATE')
            && column_exists($conn, 'ORDERS', 'SLOT_ID')
            && column_exists($conn, 'ORDERS', 'ORDER_STATUS')
            && column_exists($conn, 'ORDER_ITEM', 'ITEM_STATUS')
            && column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE')
            && column_exists($conn, 'PICKUP_SLOT', 'END_HOUR');
    }
}

if (!function_exists('sl_order_sync_parent_status')) {
    function sl_order_sync_parent_status($conn, $orderId, $mode = OCI_COMMIT_ON_SUCCESS) {
        if (!sl_order_lifecycle_ready($conn) || trim((string)$orderId) === '') {
            return;
        }

        $summary = db_one($conn, "
            SELECT
                COUNT(*) AS TOTAL_ITEMS,
                SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'CANCELLED' THEN 1 ELSE 0 END) AS CANCELLED_ITEMS,
                SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'COLLECTED' THEN 1 ELSE 0 END) AS COLLECTED_ITEMS,
                SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) = 'READY' THEN 1 ELSE 0 END) AS READY_ITEMS,
                SUM(CASE WHEN UPPER(NVL(ITEM_STATUS, 'PENDING')) NOT IN ('READY', 'COLLECTED', 'CANCELLED') THEN 1 ELSE 0 END) AS PENDING_ITEMS
            FROM ORDER_ITEM
            WHERE ORDER_ID = :order_id
        ", [':order_id' => $orderId]);

        $total = (int)($summary['TOTAL_ITEMS'] ?? 0);
        $cancelled = (int)($summary['CANCELLED_ITEMS'] ?? 0);
        $collected = (int)($summary['COLLECTED_ITEMS'] ?? 0);
        $ready = (int)($summary['READY_ITEMS'] ?? 0);
        $pending = (int)($summary['PENDING_ITEMS'] ?? 0);

        if ($total <= 0) {
            return;
        }

        $newStatus = 'CONFIRMED';
        if ($cancelled === $total) {
            $newStatus = 'CANCELLED';
        } elseif (($collected + $cancelled) === $total && $collected > 0) {
            $newStatus = 'COLLECTED';
        } elseif ($pending === 0 && $ready > 0) {
            $newStatus = 'READY';
        }

        db_bind_and_execute(
            $conn,
            'UPDATE ORDERS SET ORDER_STATUS = :status WHERE ORDER_ID = :order_id',
            [':status' => $newStatus, ':order_id' => $orderId],
            $mode
        );
    }
}

if (!function_exists('sl_order_auto_cancel_overdue_uncollected')) {
    function sl_order_auto_cancel_overdue_uncollected($conn) {
        $result = [
            'ran' => false,
            'cancelled_items' => 0,
            'affected_orders' => 0,
            'message' => '',
            'cancelled_products_by_order' => [],
        ];

        if (!sl_order_lifecycle_ready($conn)) {
            $result['message'] = 'Order lifecycle check skipped because required tables/columns are missing.';
            return $result;
        }

        $eligible = db_all($conn, "
            SELECT
                oi.ORDER_ID,
                oi.PRODUCT_ID,
                oi.QUANTITY
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            INNER JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
            INNER JOIN PICKUP_SLOT ps ON ps.SLOT_ID = o.SLOT_ID
            WHERE UPPER(NVL(p.PAYMENT_STATUS, 'FAILED')) = 'COMPLETED'
              AND SYSDATE > (TRUNC(o.PICKUP_DATE) + (ps.END_HOUR / 24))
              AND UPPER(NVL(oi.ITEM_STATUS, 'PENDING')) NOT IN ('COLLECTED', 'CANCELLED')
        ");

        if (!$eligible) {
            $result['ran'] = true;
            $result['message'] = 'No overdue uncollected items were found.';
            return $result;
        }

        $orderIds = [];
        try {
            foreach ($eligible as $row) {
                $orderId = (string)($row['ORDER_ID'] ?? '');
                $productId = (string)($row['PRODUCT_ID'] ?? '');
                $qty = max(0, (int)($row['QUANTITY'] ?? 0));
                if ($orderId === '' || $productId === '') {
                    continue;
                }

                $stmt = db_bind_and_execute($conn, "
                    UPDATE ORDER_ITEM
                    SET ITEM_STATUS = 'CANCELLED'
                    WHERE ORDER_ID = :order_id
                      AND PRODUCT_ID = :product_id
                      AND UPPER(NVL(ITEM_STATUS, 'PENDING')) NOT IN ('COLLECTED', 'CANCELLED')
                ", [':order_id' => $orderId, ':product_id' => $productId], OCI_NO_AUTO_COMMIT);

                if (function_exists('oci_num_rows') && oci_num_rows($stmt) > 0) {
                    db_bind_and_execute($conn, "
                        UPDATE PRODUCT
                        SET STOCK_AVAILABLE = NVL(STOCK_AVAILABLE, 0) + :qty
                        WHERE PRODUCT_ID = :product_id
                    ", [':qty' => $qty, ':product_id' => $productId], OCI_NO_AUTO_COMMIT);

                    $result['cancelled_items']++;
                    $orderIds[$orderId] = true;
                    $result['cancelled_products_by_order'][$orderId][] = $productId;
                }
            }

            foreach (array_keys($orderIds) as $orderId) {
                sl_order_sync_parent_status($conn, $orderId, OCI_NO_AUTO_COMMIT);
            }

            oci_commit($conn);

            if (function_exists('shoplocalfy_send_order_cancellation_emails')) {
                foreach ($result['cancelled_products_by_order'] as $cancelledOrderId => $productIds) {
                    foreach (array_unique($productIds) as $cancelledProductId) {
                        shoplocalfy_send_order_cancellation_emails($conn, (string)$cancelledOrderId, (string)$cancelledProductId, 'overdue');
                    }
                }
            }

            $result['ran'] = true;
            $result['affected_orders'] = count($orderIds);
            $result['message'] = $result['cancelled_items'] . ' overdue item(s) auto-cancelled. Admin must still refund customers manually in PayPal Sandbox.';
            return $result;
        } catch (Throwable $e) {
            if (function_exists('oci_rollback')) {
                @oci_rollback($conn);
            }
            throw $e;
        }
    }
}

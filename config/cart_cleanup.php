<?php

if (!function_exists('remove_unavailable_products_from_all_carts')) {
    function remove_unavailable_products_from_all_carts($conn) {
        if (!$conn) {
            return false;
        }

        $sql = <<<'SQL'
            DELETE FROM cart_item
            WHERE product_id IN (
                SELECT p.product_id
                FROM product p
                JOIN shop s ON s.shop_id = p.shop_id
                JOIN trader t ON t.user_id = s.trader_id
                JOIN "USER" u ON u.user_id = t.user_id
                WHERE p.is_active = 0
                   OR p.admin_approval_status <> 'APPROVED'
                   OR p.stock_available <= 0
                   OR s.approval_status <> 'APPROVED'
                   OR t.verified_status <> 'VERIFIED'
                   OR u.active_status <> 'ACTIVE'
            )
SQL;

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            return false;
        }

        return oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
    }
}

if (!function_exists('remove_unavailable_products_from_customer_cart')) {
    function remove_unavailable_products_from_customer_cart($conn, $customerId) {
        $customerId = trim((string)$customerId);

        if (!$conn || $customerId === '') {
            return false;
        }

        $sql = <<<'SQL'
            DELETE FROM cart_item
            WHERE cart_id IN (
                SELECT cart_id
                FROM cart
                WHERE customer_id = :customer_id
            )
            AND product_id IN (
                SELECT p.product_id
                FROM product p
                JOIN shop s ON s.shop_id = p.shop_id
                JOIN trader t ON t.user_id = s.trader_id
                JOIN "USER" u ON u.user_id = t.user_id
                WHERE p.is_active = 0
                   OR p.admin_approval_status <> 'APPROVED'
                   OR p.stock_available <= 0
                   OR s.approval_status <> 'APPROVED'
                   OR t.verified_status <> 'VERIFIED'
                   OR u.active_status <> 'ACTIVE'
            )
SQL;

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            return false;
        }

        oci_bind_by_name($stmt, ':customer_id', $customerId);

        return oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
    }
}

if (!function_exists('remove_product_from_all_carts')) {
    function remove_product_from_all_carts($conn, $productId) {
        $productId = trim((string)$productId);

        if (!$conn || $productId === '') {
            return false;
        }

        $sql = <<<'SQL'
            DELETE FROM cart_item
            WHERE product_id = :product_id
SQL;

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            return false;
        }

        oci_bind_by_name($stmt, ':product_id', $productId);

        return oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
    }
}

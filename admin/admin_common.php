<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

if (!function_exists('admin_h')) {
    function admin_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_money')) {
    function admin_money($value)
    {
        return '£' . number_format((float)$value, 2);
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt($value)
    {
        return '£' . number_format((float)$value, 2);
    }
}

if (!function_exists('int_fmt')) {
    function int_fmt($value)
    {
        return number_format((int)$value);
    }
}

if (!function_exists('admin_db_connection')) {
    function admin_db_connection()
    {
        global $conn, $connection, $db_conn, $db, $oracle_conn;
        global $db_user, $db_password, $db_connection;

        foreach ([$conn ?? null, $connection ?? null, $db_conn ?? null, $db ?? null, $oracle_conn ?? null] as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        if (function_exists('oci_connect') && !empty($db_user) && isset($db_password) && !empty($db_connection)) {
            return oci_connect($db_user, $db_password, $db_connection, 'AL32UTF8');
        }

        return null;
    }
}

if (!function_exists('db_bind_and_execute')) {
    function db_bind_and_execute($conn, $sql, $binds = [], $mode = OCI_COMMIT_ON_SUCCESS)
    {
        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            throw new Exception(oracle_error_message($conn));
        }

        $localBinds = [];
        foreach ($binds as $key => $value) {
            $bindName = str_starts_with((string)$key, ':') ? (string)$key : ':' . (string)$key;
            $localBinds[$bindName] = $value;
            oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
        }

        if (!oci_execute($stmt, $mode)) {
            throw new Exception(oracle_error_message($stmt));
        }

        return $stmt;
    }
}

if (!function_exists('db_all')) {
    function db_all($conn, $sql, $binds = [])
    {
        $stmt = db_bind_and_execute($conn, $sql, $binds, OCI_NO_AUTO_COMMIT);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('db_one')) {
    function db_one($conn, $sql, $binds = [])
    {
        $stmt = db_bind_and_execute($conn, $sql, $binds, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_assoc($stmt);
        return $row ?: null;
    }
}

if (!function_exists('table_exists')) {
    function table_exists($conn, $tableName)
    {
        try {
            $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM USER_TABLES WHERE TABLE_NAME = UPPER(:table_name)', [':table_name' => $tableName]);
            return ((int)($row['TOTAL'] ?? 0)) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('column_exists')) {
    function column_exists($conn, $tableName, $columnName)
    {
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
}

if (!function_exists('admin_current_user_id')) {
    function admin_current_user_id()
    {
        $role = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
        if ($role !== 'ADMIN') {
            return null;
        }

        $candidate = trim((string)($_SESSION['admin_id'] ?? ''));

        if ($candidate === '') {
            $candidate = trim((string)($_SESSION['user_id'] ?? ''));
        }

        if ($candidate === '') {
            return null;
        }

        $conn = admin_db_connection();
        if (!$conn) {
            return null;
        }

        try {
            $row = db_one(
                $conn,
                <<<'SQL'
                SELECT a.USER_ID
                FROM ADMIN a
                INNER JOIN "USER" u ON u.USER_ID = a.USER_ID
                WHERE a.USER_ID = :user_id
                  AND UPPER(TRIM(u.USER_ROLE)) = 'ADMIN'
                  AND NVL(UPPER(TRIM(u.ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
SQL,
                [':user_id' => $candidate]
            );
            return $row && !empty($row['USER_ID']) ? $row['USER_ID'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('require_admin_login')) {
    function require_admin_login()
    {
        $adminId = admin_current_user_id();
        if (!$adminId) {
            $current = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
            if ($current === '' || $current === 'login.php') {
                $current = 'dashboard.php';
            }
            header('Location: login.php?redirect=' . rawurlencode($current));
            exit;
        }
        return $adminId;
    }
}

if (!function_exists('status_class')) {
    function status_class($status)
    {
        $s = strtoupper((string)$status);
        return match ($s) {
            'COLLECTED', 'COMPLETED', 'PAID', 'VERIFIED', 'APPROVED', 'ACTIVE' => 'done',
            'CONFIRMED', 'READY' => 'proc',
            'SHIPPED', 'OUT_FOR_DELIVERY' => 'ship',
            default => 'pend',
        };
    }
}

if (!function_exists('admin_rows')) {
    function admin_rows($sql, $binds = [])
    {
        global $conn;
        return fetch_all($conn, $sql, $binds);
    }
}

if (!function_exists('admin_row')) {
    function admin_row($sql, $binds = [])
    {
        global $conn;
        return fetch_one($conn, $sql, $binds);
    }
}

if (!function_exists('admin_count')) {
    function admin_count($sql, $binds = [])
    {
        $row = admin_row($sql, $binds);
        return (int)($row['TOTAL'] ?? 0);
    }
}

if (!function_exists('admin_customer_rows')) {
    function admin_customer_rows()
    {
        return admin_rows("\n            SELECT\n                u.USER_ID,\n                TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS CUSTOMER_NAME,\n                u.EMAIL_ADDRESS,\n                u.EMAIL_VERIFIED,\n                TO_CHAR(u.DATE_CREATED, 'YYYY-MM-DD') AS JOINED_DATE,\n                (SELECT COUNT(*) FROM ORDERS o WHERE o.CUSTOMER_ID = c.USER_ID) AS ORDER_COUNT\n            FROM CUSTOMER c\n            JOIN \"USER\" u ON u.USER_ID = c.USER_ID\n            ORDER BY u.DATE_CREATED DESC NULLS LAST, u.USER_ID DESC\n        ");
    }
}

if (!function_exists('admin_payment_rows')) {
    function admin_payment_rows()
    {
        return admin_rows("\n            SELECT\n                p.PAYMENT_ID,\n                p.ORDER_ID,\n                p.CUSTOMER_ID,\n                p.AMOUNT_PAID,\n                p.PAYMENT_METHOD,\n                p.PAYMENT_STATUS,\n                TO_CHAR(p.PAYMENT_DATE, 'YYYY-MM-DD HH24:MI') AS PAYMENT_DATE,\n                TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS CUSTOMER_NAME\n            FROM PAYMENT p\n            LEFT JOIN \"USER\" u ON u.USER_ID = p.CUSTOMER_ID\n            ORDER BY p.PAYMENT_DATE DESC NULLS LAST, p.PAYMENT_ID DESC\n        ");
    }
}

if (!function_exists('admin_trader_rows')) {
    function admin_trader_rows($onlyPending = false)
    {
        $where = $onlyPending ? "WHERE UPPER(NVL(t.VERIFIED_STATUS, 'PENDING')) IN ('PENDING', 'UNVERIFIED')" : '';
        return admin_rows("\n            SELECT\n                t.USER_ID,\n                t.PAN_NUMBER,\n                t.VERIFIED_STATUS,\n                TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS TRADER_NAME,\n                u.EMAIL_ADDRESS,\n                TO_CHAR(u.DATE_CREATED, 'YYYY-MM-DD') AS SUBMITTED_DATE,\n                s.SHOP_ID,\n                s.SHOP_NAME,\n                s.LOCATION,\n                s.APPROVAL_STATUS\n            FROM TRADER t\n            JOIN \"USER\" u ON u.USER_ID = t.USER_ID\n            LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID\n            $where\n            ORDER BY u.DATE_CREATED DESC NULLS LAST, t.USER_ID DESC\n        ");
    }
}

if (!function_exists('admin_next_shop_id')) {
    function admin_next_shop_id()
    {
        return 'S' . str_pad((string)(admin_count("SELECT NVL(MAX(TO_NUMBER(REGEXP_SUBSTR(SHOP_ID, '[0-9]+$'))), 0) + 1 AS TOTAL FROM SHOP")), 9, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('admin_first_admin_id')) {
    function admin_first_admin_id()
    {
        $adminId = admin_current_user_id();
        if ($adminId) return $adminId;
        $row = admin_row('SELECT USER_ID FROM ADMIN FETCH FIRST 1 ROWS ONLY');
        return $row['USER_ID'] ?? null;
    }
}

if (!function_exists('admin_create_trader_from_post')) {
    function admin_create_trader_from_post()
    {
        global $conn;

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pan = trim($_POST['pan'] ?? '');
        $shopName = trim($_POST['shop_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = strtoupper(trim($_POST['status'] ?? 'PENDING'));
        $password = trim($_POST['password'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $pan === '' || $shopName === '' || $location === '') {
            throw new Exception('Please fill all required trader details.');
        }

        if ($password === '') {
            $password = 'Trader@123';
        }

        $activeShopCount = admin_count("SELECT COUNT(*) AS TOTAL
            FROM SHOP
            WHERE UPPER(NVL(APPROVAL_STATUS, 'PENDING')) NOT IN ('REJECTED', 'SUSPENDED')");

        if ($activeShopCount >= 10) {
            throw new Exception('ShopLocalfy is limited to 10 shops. New shop creation is currently closed.');
        }

        $userId = create_user_with_role($conn, $firstName, $lastName, $email, $phone, $password, 'TRADER', 1);
        update_trader_details($conn, $userId, $pan, $status, admin_first_admin_id());

        execute_sql($conn, '
            INSERT INTO SHOP (TRADER_ID, SHOP_NAME, LOCATION, APPROVAL_STATUS)
            VALUES (:trader_id, :shop_name, :location, :approval_status)
        ', [
            ':trader_id' => $userId,
            ':shop_name' => $shopName,
            ':location' => $location,
            ':approval_status' => $status === 'VERIFIED' ? 'APPROVED' : ($status === 'REJECTED' ? 'SUSPENDED' : 'PENDING')
        ]);

        return $userId;
    }
}


if (!function_exists('admin_run_order_lifecycle_check')) {
    function admin_run_order_lifecycle_check() {
        $lastCheck = (int)($_SESSION['order_lifecycle_checked_at'] ?? 0);
        if ((time() - $lastCheck) <= 300) {
            return;
        }

        $conn = admin_db_connection();
        require_once __DIR__ . '/../config/order_lifecycle.php';
        if ($conn && function_exists('sl_order_auto_cancel_overdue_uncollected')) {
            sl_order_auto_cancel_overdue_uncollected($conn);
            $_SESSION['order_lifecycle_checked_at'] = time();
        }
    }
}

admin_run_order_lifecycle_check();

if (!defined('ADMIN_COMMON_SKIP_AUTH')) {
    require_admin_login();
}

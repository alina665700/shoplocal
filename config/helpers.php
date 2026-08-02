<?php

require_once __DIR__ . '/db.php';


function h($value) {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        $value = $loaded === false ? '' : $loaded;
    } elseif (is_object($value)) {
        $value = method_exists($value, '__toString') ? (string)$value : '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); // html escape helper
}


function redirect_to($path) {
    header("Location: " . $path);
    exit;
}


function oracle_error_message($stmt = null) {
    return shoplocalfy_db_error_message($stmt, 'A database error occurred. Please try again.');
}


function execute_sql($conn, $sql, $binds = [], $mode = OCI_COMMIT_ON_SUCCESS) {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException(oracle_error_message());
    }

    $boundValues = [];
    foreach ($binds as $key => $value) {
        $boundValues[$key] = $value;
        oci_bind_by_name($stmt, $key, $boundValues[$key]);
    }

    if (!oci_execute($stmt, $mode)) {
        throw new RuntimeException(oracle_error_message($stmt));
    }

    return $stmt;
}


function fetch_one($conn, $sql, $binds = []) {
    $stmt = execute_sql($conn, $sql, $binds, OCI_NO_AUTO_COMMIT);
    $row = oci_fetch_assoc($stmt);
    return $row ?: null;
}


function fetch_all($conn, $sql, $binds = []) {
    $stmt = execute_sql($conn, $sql, $binds, OCI_NO_AUTO_COMMIT);
    $rows = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }
    return $rows;
}


function verify_user_password($plainPassword, $storedPassword) {
    if (!$storedPassword) {
        return false;
    }

    return password_verify($plainPassword, $storedPassword);
}


function login_user_session($user) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['user_id'] = $user['USER_ID'];
    $_SESSION['first_name'] = $user['FIRST_NAME'];
    $_SESSION['last_name'] = $user['LAST_NAME'];
    $_SESSION['email_address'] = $user['EMAIL_ADDRESS'];
    $_SESSION['user_role'] = $user['USER_ROLE'];
}


function require_role($role) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $requiredRole = strtoupper((string)$role);
    $sessionRole = strtoupper((string)($_SESSION['user_role'] ?? ''));
    $userId = strtoupper(trim((string)($_SESSION['user_id'] ?? '')));

    if ($userId === '' || $sessionRole !== $requiredRole) {
        redirect_to('login.php');
    }

    global $conn;
    if ($conn) {
        try {
            $row = fetch_one($conn, <<<'SQL'
                SELECT USER_ID
                FROM "USER"
                WHERE USER_ID = :user_id
                  AND UPPER(TRIM(USER_ROLE)) = :role
                  AND NVL(UPPER(TRIM(ACTIVE_STATUS)), 'ACTIVE') = 'ACTIVE'
SQL,
                [':user_id' => $userId, ':role' => $requiredRole]
            );

            if (!$row) {
                $_SESSION = [];
                redirect_to('login.php');
            }
        } catch (Throwable $e) {
            redirect_to('login.php');
        }
    }
}

// ------------------------------------------------------------
// CREATE USER HELPER
// Inserts only into "USER". Oracle trigger creates ID and role row.
// ------------------------------------------------------------
function create_user_with_role($conn, $firstName, $lastName, $email, $phone, $plainPassword, $role, $emailVerified = 1) {
    $role = strtoupper($role);
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $newUserId = '';

    $sql = '
        INSERT INTO "USER" (
            first_name,
            last_name,
            email_address,
            ph_number,
            password_hash,
            user_role,
            email_verified
        )
        VALUES (
            :first_name,
            :last_name,
            :email_address,
            :ph_number,
            :password_hash,
            :user_role,
            :email_verified
        )
        RETURNING user_id INTO :new_user_id
    ';

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException(oracle_error_message());
    }

    oci_bind_by_name($stmt, ':first_name', $firstName);
    oci_bind_by_name($stmt, ':last_name', $lastName);
    oci_bind_by_name($stmt, ':email_address', $email);
    oci_bind_by_name($stmt, ':ph_number', $phone);
    oci_bind_by_name($stmt, ':password_hash', $passwordHash);
    oci_bind_by_name($stmt, ':user_role', $role);
    oci_bind_by_name($stmt, ':email_verified', $emailVerified);
    oci_bind_by_name($stmt, ':new_user_id', $newUserId, 10);

    if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
        throw new RuntimeException(oracle_error_message($stmt));
    }

    return $newUserId;
}


function update_trader_details($conn, $userId, $panNumber, $verifiedStatus, $adminId = null) {
    execute_sql($conn, '
        UPDATE TRADER
        SET pan_number = :pan_number,
            verified_status = :verified_status,
            admin_id = :admin_id
        WHERE user_id = :user_id
    ', [
        ':pan_number' => $panNumber !== '' ? $panNumber : null,
        ':verified_status' => $verifiedStatus !== '' ? $verifiedStatus : 'PENDING',
        ':admin_id' => $adminId,
        ':user_id' => $userId
    ]);
}

// ------------------------------------------------------------
// ENSURE CUSTOMER DETAILS HELPER
// Creates the CUSTOMER row when a CUSTOMER user exists without one.
// ------------------------------------------------------------
function ensure_customer_details($conn, $userId) {
    $existing = fetch_one($conn, 'SELECT user_id FROM CUSTOMER WHERE user_id = :user_id', [':user_id' => $userId]);

    if (!$existing) {
        execute_sql($conn, 'INSERT INTO CUSTOMER (user_id) VALUES (:user_id)', [':user_id' => $userId]);
    }
}


function delete_user_by_id($conn, $userId) {
    execute_sql($conn, 'DELETE FROM TRADER WHERE user_id = :user_id', [':user_id' => $userId]);
    execute_sql($conn, 'DELETE FROM ADMIN WHERE user_id = :user_id', [':user_id' => $userId]);
    execute_sql($conn, 'DELETE FROM CUSTOMER WHERE user_id = :user_id', [':user_id' => $userId]);
    execute_sql($conn, 'DELETE FROM "USER" WHERE user_id = :user_id', [':user_id' => $userId]);
}
?>

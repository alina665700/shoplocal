<?php

if (!defined('SHOPLOCALFY_DEBUG')) {
    define('SHOPLOCALFY_DEBUG', false);
}

ini_set('log_errors', '1');
if (!SHOPLOCALFY_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

if (!function_exists('shoplocalfy_is_debug')) {
    function shoplocalfy_is_debug(): bool {
        return defined('SHOPLOCALFY_DEBUG') && SHOPLOCALFY_DEBUG === true;
    }
}

if (!function_exists('shoplocalfy_log_error')) {
    function shoplocalfy_log_error(string $context, string $detail = ''): void {
        $context = trim($context);
        $detail = trim($detail);
        $message = '[ShopLocalfy] ' . ($context !== '' ? $context : 'Application error');
        if ($detail !== '') {
            $message .= ' | ' . $detail;
        }
        error_log($message);
    }
}

if (!function_exists('shoplocalfy_db_error_message')) {
    function shoplocalfy_db_error_message($resource = null, string $publicMessage = 'A database error occurred. Please try again.'): string {
        $error = null;

        if (function_exists('oci_error')) {
            $error = $resource ? @oci_error($resource) : @oci_error();
        }

        $detail = is_array($error) && isset($error['message'])
            ? (string)$error['message']
            : 'Unknown Oracle/OCI error.';

        shoplocalfy_log_error($publicMessage, $detail);

        if (shoplocalfy_is_debug()) {
            return $publicMessage . ' ' . $detail;
        }

        return $publicMessage;
    }
}

if (!function_exists('shoplocalfy_public_exception_message')) {
    function shoplocalfy_public_exception_message(Throwable $e, string $publicMessage = 'Something went wrong. Please try again.'): string {
        $detail = trim((string)$e->getMessage());
        shoplocalfy_log_error($publicMessage, $detail);

        if (shoplocalfy_is_debug()) {
            return $detail !== '' ? $detail : $publicMessage;
        }

        if ($e instanceof Error) {
            return $publicMessage;
        }

        $looksInternal = preg_match('/(ORA-|OCI|SQL|PL\/SQL|database|query|parse|execute|constraint|trigger|table|column|stack trace|fatal error|warning|notice|undefined|array key|argument #|must be of type|called in|\.php on line|xampp|htdocs|config\/db|C:\\|\/var\/www)/i', $detail);

        if ($looksInternal) {
            return $publicMessage;
        }

        return $detail !== '' ? $detail : $publicMessage;
    }
}

if (!function_exists('shoplocalfy_run_safely')) {
    function shoplocalfy_run_safely(callable $callback, string $context, $default = null) {
        try {
            return $callback();
        } catch (Throwable $e) {
            shoplocalfy_log_error($context, $e->getMessage());
            return $default;
        }
    }
}

$db_user = 'ECOMMERECE';
$db_password = 'ShopLocalfy2026!';
$db_connection = '//localhost:1521/FREEPDB1';

$conn = @oci_connect($db_user, $db_password, $db_connection, 'AL32UTF8');

if (!$conn) {
    exit(htmlspecialchars(shoplocalfy_db_error_message(null, 'Database connection failed. Please try again later.'), ENT_QUOTES, 'UTF-8'));
}
?>

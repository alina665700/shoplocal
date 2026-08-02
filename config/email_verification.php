<?php

require_once __DIR__ . '/mail_helpers.php';

function shoplocalfy_generate_verification_token(): string {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return sha1(uniqid('shoplocalfy_', true) . microtime(true));
    }
}

function shoplocalfy_absolute_url(string $relativePath): string {
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/main_project/index.php'));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    $projectRoot = preg_replace('#/(customer|trader|admin|config|setup)$#i', '', $scriptDir);
    if ($projectRoot === null || $projectRoot === '') {
        $projectRoot = '';
    }

    return $scheme . '://' . $host . $projectRoot . '/' . ltrim($relativePath, '/');
}

function shoplocalfy_send_verification_email(string $email, string $name, string $role, string $verificationLink): bool {
    $email = shoplocalfy_clean_email_header_value($email);
    $name = trim($name) !== '' ? trim($name) : 'there';
    $role = strtoupper(trim($role));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Verify your ShopLocalfy email';

    $body = "Hello {$name},

"
        . "Please verify your ShopLocalfy email address using this link:
"
        . $verificationLink . "

";

    if ($role === 'TRADER') {
        $body .= "After email verification, your trader account still needs admin approval before you can log in.

";
    } else {
        $body .= "After verification, you can log in and use your customer account.

";
    }

    $body .= "If you did not create this account, you can ignore this email.

";
    $body .= "ShopLocalfy";

    return shoplocalfy_send_plain_email($email, $subject, $body);
}

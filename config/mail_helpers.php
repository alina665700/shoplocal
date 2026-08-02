<?php

if (!defined('SHOPLOCALFY_MAIL_FROM')) {
    define('SHOPLOCALFY_MAIL_FROM', 'shoplocalfy26@gmail.com');
}

if (!defined('SHOPLOCALFY_MAIL_FROM_NAME')) {
    define('SHOPLOCALFY_MAIL_FROM_NAME', 'ShopLocalfy');
}

if (!function_exists('shoplocalfy_clean_email_header_value')) {
    function shoplocalfy_clean_email_header_value(string $value): string {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}

if (!function_exists('shoplocalfy_send_plain_email')) {
    function shoplocalfy_send_plain_email(string $email, string $subject, string $body): bool {
        $email = shoplocalfy_clean_email_header_value($email);
        $subject = shoplocalfy_clean_email_header_value($subject);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '') {
            return false;
        }

        if (!function_exists('mail')) {
            return false;
        }

        $fromEmail = shoplocalfy_clean_email_header_value(SHOPLOCALFY_MAIL_FROM);
        $fromName = shoplocalfy_clean_email_header_value(SHOPLOCALFY_MAIL_FROM_NAME);

        $headers = [];
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        return @mail($email, $subject, $body, implode("\r\n", $headers));
    }
}

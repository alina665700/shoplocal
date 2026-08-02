<?php
// config/paypal_config.php

if (!defined('SHOPLOCALFY_PAYPAL_MODE')) {
    define('SHOPLOCALFY_PAYPAL_MODE', 'sandbox');
}

if (!defined('SHOPLOCALFY_PAYPAL_CLIENT_ID')) {
    define('SHOPLOCALFY_PAYPAL_CLIENT_ID', 'AS81n8A6kDPnXI4P4eK7I4tM4RKJDsc40-Rs30U7Q7cY75IHlQYRtostCwU1Nhfy4MpvLCyZK6XpwLlJ');
}

if (!defined('SHOPLOCALFY_PAYPAL_CLIENT_SECRET')) {
    define('SHOPLOCALFY_PAYPAL_CLIENT_SECRET', 'EGzRro_K6HaAwPrtmVs9S3OFXZLfDcU10_KdLyw496m1CueKw41Rbn3nXW4nVHpYW5mFOoqdEK3SGaS0');
}

if (!defined('SHOPLOCALFY_PAYPAL_CURRENCY')) {
    define('SHOPLOCALFY_PAYPAL_CURRENCY', 'GBP');
}

if (!defined('SHOPLOCALFY_PAYPAL_PLATFORM_FEE_RATE')) {
    define('SHOPLOCALFY_PAYPAL_PLATFORM_FEE_RATE', 0.08);
}

function shoplocalfy_paypal_base_url(): string
{
    return SHOPLOCALFY_PAYPAL_MODE === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}
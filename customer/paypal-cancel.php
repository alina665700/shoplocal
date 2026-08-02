<?php
require_once __DIR__ . '/paypal_checkout_common.php';
require_customer_login();

unset($_SESSION['paypal_checkout']);
$_SESSION['checkout_errors'] = ['PayPal payment was cancelled. No order was created.'];
header('Location: checkout.php');
exit;

<?php
require_once __DIR__ . '/paypal_checkout_common.php';
require_customer_login();

$session = $_SESSION['paypal_checkout'] ?? null;
$token = trim((string)($_GET['token'] ?? ''));
$customerId = current_customer_id();

function checkout_redirect_to_completed_order_if_known(): void {
    $lastOrderId = trim((string)($_SESSION['last_completed_order_id'] ?? ''));
    if ($lastOrderId !== '') {
        header('Location: checkout.php?order_id=' . rawurlencode($lastOrderId));
        exit;
    }
}

try {
    if (!is_array($session) || empty($session['paypal_order_id']) || empty($session['snapshot'])) {
        // This usually means the PayPal return URL was refreshed, opened twice, or visited after the order was already created.
        // Do not show a scary checkout error in that case. Send the customer to the latest known success page when possible.
        checkout_redirect_to_completed_order_if_known();

        $_SESSION['checkout_notice'] = 'Your checkout session was already completed or expired. Please check your order history.';
        header('Location: customer_all_order.php');
        exit;
    }

    $paypalOrderId = (string)$session['paypal_order_id'];
    if ($token !== '' && $token !== $paypalOrderId) {
        throw new RuntimeException('PayPal returned an unexpected order token. Please restart checkout.');
    }

    if (!empty($session['created_at']) && (time() - (int)$session['created_at']) > 1800) {
        throw new RuntimeException('PayPal checkout session expired. Please restart checkout.');
    }

    $capture = paypal_capture_checkout_order($paypalOrderId);
    if (!paypal_capture_is_completed($capture)) {
        throw new RuntimeException('PayPal payment was not completed.');
    }

    $snapshot = $session['snapshot'];
    if ((string)($snapshot['customer_id'] ?? '') !== $customerId) {
        throw new RuntimeException('Customer session changed during checkout. Please restart checkout.');
    }

    $orderId = checkout_create_local_order($snapshot);

    $_SESSION['last_completed_order_id'] = $orderId;
    unset($_SESSION['paypal_checkout'], $_SESSION['checkout_errors'], $_SESSION['checkout_old']);

    header('Location: checkout.php?order_id=' . rawurlencode($orderId));
    exit;
} catch (Throwable $e) {
    if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
        @oci_rollback($GLOBALS['conn']);
    }

    unset($_SESSION['paypal_checkout']);

    // If an order was already completed, never replace the success state with an error on refresh/back-button/demo reload.
    checkout_redirect_to_completed_order_if_known();

    $_SESSION['checkout_errors'] = [shoplocalfy_public_exception_message($e, 'PayPal payment could not be completed. Please try again.')];
    header('Location: checkout.php');
    exit;
}

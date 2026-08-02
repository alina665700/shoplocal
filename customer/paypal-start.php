<?php
require_once __DIR__ . '/paypal_checkout_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';
require_customer_login();

$customerId = current_customer_id();
remove_unavailable_products_from_customer_cart($conn, $customerId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}

$slotId = trim((string)($_POST['slot_id'] ?? ''));
$pickupDate = trim((string)($_POST['pickup_date'] ?? ''));

try {
    $snapshot = checkout_validate_snapshot($customerId, $slotId, $pickupDate);

    if (!$snapshot['valid']) {
        $_SESSION['checkout_errors'] = $snapshot['errors'];
        $_SESSION['checkout_old'] = ['slot_id' => $slotId, 'pickup_date' => $pickupDate];
        header('Location: checkout.php');
        exit;
    }

    $paypalOrder = paypal_create_checkout_order(
        (float)$snapshot['total'],
        'ShopLocalfy order for customer ' . $customerId
    );

    $paypalOrderId = (string)($paypalOrder['id'] ?? '');
    if ($paypalOrderId === '') {
        throw new RuntimeException('PayPal did not return an order ID.');
    }

    $_SESSION['paypal_checkout'] = [
        'paypal_order_id' => $paypalOrderId,
        'created_at' => time(),
        'snapshot' => $snapshot,
    ];

    $approvalUrl = paypal_find_approval_url($paypalOrder);
    header('Location: ' . $approvalUrl);
    exit;
} catch (Throwable $e) {
    $_SESSION['checkout_errors'] = [shoplocalfy_public_exception_message($e, 'Could not start PayPal checkout.')];
    $_SESSION['checkout_old'] = ['slot_id' => $slotId, 'pickup_date' => $pickupDate];
    header('Location: checkout.php');
    exit;
}

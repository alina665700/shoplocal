<?php
require_once __DIR__ . '/paypal_checkout_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';
require_customer_login();

$customerId = current_customer_id();
remove_unavailable_products_from_customer_cart($conn, $customerId);
$successOrderId = trim((string)($_GET['order_id'] ?? ''));
$successOrderValid = false;
$errors = $_SESSION['checkout_errors'] ?? [];
$notice = $_SESSION['checkout_notice'] ?? '';
$old = $_SESSION['checkout_old'] ?? [];
unset($_SESSION['checkout_errors'], $_SESSION['checkout_notice'], $_SESSION['checkout_old']);

$slots = [];
$cartId = '';
$items = [];
$subtotal = 0.0;

try {
    $slots = load_checkout_slots();
    [$cartId, $items, $subtotal] = load_checkout_cart($customerId);
} catch (Throwable $e) {
    $errors[] = shoplocalfy_public_exception_message($e, 'Checkout could not load. Please try again.');
}

$totalCartQuantity = 0;
foreach ($items as $item) {
    $totalCartQuantity += (int)($item['quantity'] ?? 0);
}

// If the browser refreshes an old PayPal return page after a successful order, do not show a red technical session error.
if ($successOrderId !== '') {
    try {
        $orderOwner = db_one(
            $conn,
            'SELECT ORDER_ID FROM ORDERS WHERE ORDER_ID = :order_id AND CUSTOMER_ID = :customer_id',
            [':order_id' => $successOrderId, ':customer_id' => $customerId]
        );
        if ($orderOwner) {
            $successOrderValid = true;
            $errors = [];
        } else {
            $successOrderId = '';
            $notice = 'That order could not be found on your account. Please check your order history.';
        }
    } catch (Throwable $e) {
        $successOrderId = '';
        $notice = 'Your checkout session may already be completed. Please check your order history.';
    }
} else {
    $cleanErrors = [];
    foreach ($errors as $error) {
        $errorText = (string)$error;
        if (stripos($errorText, 'PayPal checkout session was not found') !== false) {
            $notice = 'Your checkout session was already completed or expired. Please check your order history.';
            continue;
        }
        $cleanErrors[] = $errorText;
    }
    $errors = $cleanErrors;
}

if (!$successOrderValid) {
    $cartCheckoutWarnings = [];

    if ($totalCartQuantity > 20) {
        $itemsToRemove = $totalCartQuantity - 20;
        $cartCheckoutWarnings[] = 'Your cart has ' . $totalCartQuantity . ' items. One order can contain a maximum of 20 items. Remove ' . $itemsToRemove . ' item' . ($itemsToRemove === 1 ? '' : 's') . ' before checkout.';
    }

    foreach ($items as $item) {
        $cartQuantity = (int)($item['quantity'] ?? 0);
        $stockAvailable = (int)($item['stock_available'] ?? 0);

        if ($cartQuantity > $stockAvailable) {
            $cartCheckoutWarnings[] = (string)($item['product_name'] ?? 'This product') . ' only has ' . $stockAvailable . ' item' . ($stockAvailable === 1 ? '' : 's') . ' in stock, but your cart has ' . $cartQuantity . '.';
        }
    }

    if ($cartCheckoutWarnings) {
        $_SESSION['cart_checkout_warnings'] = $cartCheckoutWarnings;
        header('Location: cart.php?checkout_blocked=1');
        exit;
    }
}

$voucher = null;
$voucherDiscount = 0.0;
try {
    [$voucher, $voucherDiscount] = load_checkout_voucher($subtotal);
} catch (Throwable $e) {
    $errors[] = shoplocalfy_public_exception_message($e, 'Voucher could not be checked. Please try again.');
}
$total = max(0, $subtotal - $voucherDiscount);

$postedPickupDate = $old['pickup_date'] ?? '';
$postedSlotId = $old['slot_id'] ?? '';
$remainingItemAllowance = max(0, 20 - $totalCartQuantity);
$itemLimitPercent = min(100, ($totalCartQuantity / 20) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout - ShopLocalfy</title>
  <link rel="stylesheet" href="../assets/customer/css/checkout.css?v=20260520-ui2">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="checkout-page">
  <?php if ($successOrderValid): ?>
    <section class="checkout-success-panel">
      <div class="success-brand-side">
        <img src="../config/logos/main_project.svg" alt="ShopLocalfy" class="success-brand-logo">
      </div>
      <div class="success-summary-side">
        <div class="success-pill">Payment complete</div>
        <h1>Your order is confirmed</h1>
        <p class="success-copy">Your PayPal payment was completed and the order has been saved successfully.</p>

        <div class="success-order-card">
          <span>Order ID</span>
          <strong><?php echo e($successOrderId); ?></strong>
        </div>

        <div class="success-actions">
          <a class="success-btn primary" href="customer_all_order.php">View my orders</a>
          <a class="success-btn secondary" href="index.php#productGrid">Continue shopping</a>
        </div>
      </div>
    </section>
  <?php else: ?>
    <section class="checkout-hero">
      <div>
        <a class="back-link" href="cart.php">← Back to cart</a>
        <div class="eyebrow">Secure checkout</div>
        <h1>Confirm collection and payment</h1>
        <p>Pick a valid collection slot, review your order, then continue to PayPal.</p>
      </div>
      <div class="checkout-facts" aria-label="Checkout rules">
        <div><strong><?php echo e($totalCartQuantity); ?>/20</strong><span>basket items</span></div>
        <div><strong>Wed-Fri</strong><span>collection days</span></div>
        <div><strong>PayPal</strong><span> payment</span></div>
      </div>
    </section>

    <?php if ($notice !== ''): ?>
      <div class="alert success"><?php echo e($notice); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert error">
        <?php foreach ($errors as $error): ?><div><?php echo e($error); ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
      <div class="empty-checkout-card">
        <h2>Your cart is empty</h2>
        <p>Add products before checkout.</p>
        <a class="success-btn primary" href="index.php#productGrid">Browse products</a>
      </div>
    <?php else: ?>
      <form method="POST" action="paypal-start.php" class="checkout-grid" id="checkoutForm">
        <div class="checkout-main">
          <section class="checkout-card step-card">
            <div class="step-heading">
              <div class="step-number">1</div>
              <div>
                <h2>Pickup details</h2>
                <p>Collection is available on Wednesday, Thursday, and Friday only.</p>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label" for="pickup_date">Pickup date</label>
              <div class="date-shell">
                <input
                  type="date"
                  id="pickup_date"
                  name="pickup_date"
                  min="<?php echo e(date('Y-m-d', strtotime('+1 day'))); ?>"
                  value="<?php echo e($postedPickupDate); ?>"
                  required
                >
              </div>
              <p class="field-hint">Choose a date at least 24 hours after placing the order.</p>
            </div>

            <div class="field-row">
              <div class="label-line">
                <label class="field-label">Pickup slot</label>
                <span>Max 20 orders per slot</span>
              </div>
              <div class="slot-grid" id="slotGrid">
                <?php foreach ($slots as $slot): ?>
                  <?php [$period, $icon] = checkout_slot_period($slot['START_HOUR']); ?>
                  <?php $slotTime = checkout_time_label($slot['START_HOUR']) . ' - ' . checkout_time_label($slot['END_HOUR']); ?>
                  <label
                    class="slot-card"
                    data-day="<?php echo e(strtoupper($slot['ALLOWED_DAY'])); ?>"
                    data-start="<?php echo e($slot['START_HOUR']); ?>"
                    data-end="<?php echo e($slot['END_HOUR']); ?>"
                  >
                    <input
                      type="radio"
                      name="slot_id"
                      value="<?php echo e($slot['SLOT_ID']); ?>"
                      <?php echo $postedSlotId === $slot['SLOT_ID'] ? 'checked' : ''; ?>
                      required
                    >
                    <span class="slot-check">✓</span>
                    <span class="slot-title"><?php echo e($period); ?></span>
                    <span class="slot-time"><?php echo e($slotTime); ?></span>
                    <span class="slot-day"><?php echo e(ucwords(strtolower($slot['ALLOWED_DAY']))); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="slot-note" id="slotNote">Pick a Wednesday, Thursday, or Friday date to see valid slots.</div>
            </div>
          </section>

          <section class="checkout-card step-card payment-section">
            <div class="step-heading compact">
              <div class="step-number">2</div>
              <div>
                <h2>Payment method</h2>
                <p>The order is created only after PayPal confirms the payment.</p>
              </div>
            </div>

            <div class="paypal-card">
              <div>
                <div class="paypal-logo">PayPal</div>
                <p> payment for testing and demonstration.</p>
              </div>
              <span class="secure-chip">Secure redirect</span>
            </div>

            <button class="checkout-submit" type="submit">
              Continue to PayPal 
              <span>→</span>
            </button>
            <p class="safe-note">Use your PayPal buyer account when PayPal asks you to log in.</p>
          </section>
        </div>

        <aside class="order-summary-card">
          <div class="summary-top">
            <div>
              <span class="summary-label">Order summary</span>
              <h2><?php echo e(money_checkout($total)); ?></h2>
            </div>
            <a href="cart.php">Edit cart</a>
          </div>

          <div class="limit-box">
            <div class="limit-row">
              <span>Basket limit</span>
              <strong><?php echo e($totalCartQuantity); ?> / 20 items</strong>
            </div>
            <div class="limit-track"><span style="width: <?php echo e(number_format($itemLimitPercent, 2)); ?>%;"></span></div>
            <p><?php echo e($remainingItemAllowance); ?> item<?php echo $remainingItemAllowance === 1 ? '' : 's'; ?> remaining before the order limit.</p>
          </div>

          <div class="summary-list">
            <?php foreach ($items as $item): ?>
              <div class="summary-item">
                <div class="item-thumb">
                  <img src="<?php echo e($item['image']); ?>" alt="<?php echo e($item['product_name']); ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';">
                </div>
                <div class="summary-item-body">
                  <div class="item-title-line">
                    <strong><?php echo e($item['product_name']); ?></strong>
                    <span><?php echo e(money_checkout($item['line_total'])); ?></span>
                  </div>
                  <div class="item-meta"><?php echo e($item['shop_name']); ?> · Qty <?php echo e($item['quantity']); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="price-breakdown">
            <div><span>Subtotal</span><strong><?php echo e(money_checkout($subtotal)); ?></strong></div>
            <?php if ($voucherDiscount > 0): ?>
              <div class="discount"><span>Voucher</span><strong>- <?php echo e(money_checkout($voucherDiscount)); ?></strong></div>
            <?php endif; ?>
            <div class="grand-total"><span>Total</span><strong><?php echo e(money_checkout($total)); ?></strong></div>
          </div>

          <div class="account-note">Logged in as <?php echo e($_SESSION['email_address'] ?? 'customer'); ?></div>
        </aside>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>

<script src="../assets/customer/js/checkout.js?v=20260517"></script>
</body>
</html>

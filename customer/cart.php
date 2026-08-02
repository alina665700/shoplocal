<?php
require_once __DIR__ . '/customer_common.php';
require_customer_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    return '£' . number_format((float)$amount, 2);
}

function db_query($sql, array $binds = [], int $mode = OCI_COMMIT_ON_SUCCESS) {
    global $conn;

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        exit(h(shoplocalfy_db_error_message($conn, 'Could not prepare cart request.')));
    }

    $localBinds = [];
    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim((string)$key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!oci_execute($stmt, $mode)) {
        exit(h(shoplocalfy_db_error_message($stmt, 'Could not load cart.')));
    }

    return $stmt;
}

function fetch_one($sql, array $binds = []): ?array {
    $stmt = db_query($sql, $binds);
    $row = oci_fetch_assoc($stmt);
    return $row ?: null;
}

function fetch_all($sql, array $binds = []): array {
    $stmt = db_query($sql, $binds);
    $rows = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }
    return $rows;
}

function cart_total_quantity_excluding_product(string $customerId, string $productId = ''): int {
    $sql = '
        SELECT NVL(SUM(ci.quantity), 0) AS TOTAL_QTY
        FROM CART c
        JOIN CART_ITEM ci ON ci.cart_id = c.cart_id
        WHERE c.customer_id = :customer_id
    ';
    $binds = ['customer_id' => $customerId];

    if ($productId !== '') {
        $sql .= ' AND ci.product_id <> :product_id';
        $binds['product_id'] = $productId;
    }

    $row = fetch_one($sql, $binds);
    return (int)($row['TOTAL_QTY'] ?? 0);
}

function product_image_url(string $imageValue, string $productId = ''): string {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $imageValue = trim(str_replace('\\', '/', $imageValue));

    if ($imageValue === '') {
        return $placeholder;
    }

    if (preg_match('/^(https?:\/\/|data:image\/)/i', $imageValue)) {
        return $imageValue;
    }

    if (strpos($imageValue, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $imageValue) ? '../' . $imageValue : $placeholder;
    }

    $file = dirname(__DIR__) . '/uploads/products/' . basename($imageValue);
    return is_file($file) ? '../uploads/products/' . rawurlencode(basename($imageValue)) : $placeholder;
}

$customerId = current_customer_id();
if (!$customerId) {
    header('Location: login.php');
    exit;
}

// Remove hidden, unapproved, suspended, or out-of-stock products before totals are calculated.
remove_unavailable_products_from_customer_cart($conn, $customerId);

$notice = '';
$action = $_POST['action'] ?? '';
$productId = trim($_POST['product_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'update_qty' && $productId !== '') {
        $qty = max(1, min(100, (int)($_POST['qty'] ?? 1))); 

        db_query(
            'UPDATE CART_ITEM
             SET quantity = :qty
             WHERE product_id = :product_id
               AND cart_id = (SELECT cart_id FROM CART WHERE customer_id = :customer_id)',
            ['qty' => $qty, 'product_id' => $productId, 'customer_id' => $customerId]
        );
        header('Location: cart.php');
        exit;
    }

    if ($action === 'remove' && $productId !== '') {
        db_query(
            'DELETE FROM CART_ITEM
             WHERE product_id = :product_id
               AND cart_id = (SELECT cart_id FROM CART WHERE customer_id = :customer_id)',
            ['product_id' => $productId, 'customer_id' => $customerId]
        );
        unset($_SESSION['cart_voucher_code']);
        header('Location: cart.php');
        exit;
    }

    if ($action === 'clear_cart') {
        db_query(
            'DELETE FROM CART_ITEM
             WHERE cart_id = (SELECT cart_id FROM CART WHERE customer_id = :customer_id)',
            ['customer_id' => $customerId]
        );
        unset($_SESSION['cart_voucher_code']);
        header('Location: cart.php');
        exit;
    }

    if ($action === 'apply_voucher') {
        $code = strtoupper(trim($_POST['voucher_code'] ?? ''));
        if ($code === '') {
            unset($_SESSION['cart_voucher_code']);
            $_SESSION['cart_voucher_notice'] = 'Enter a voucher code first.';
        } else {
            $_SESSION['cart_voucher_code'] = $code;
            $_SESSION['cart_voucher_notice'] = '';
        }
        header('Location: cart.php');
        exit;
    }

    if ($action === 'remove_voucher') {
        unset($_SESSION['cart_voucher_code']);
        $_SESSION['cart_voucher_notice'] = 'Voucher removed.';
        header('Location: cart.php');
        exit;
    }
}

$publicProductFilter = function_exists('customer_public_product_filter') ? customer_public_product_filter('p', 's') : '1 = 1';
$activeDiscountSubquery = function_exists('customer_active_discount_subquery')
    ? customer_active_discount_subquery('PRODUCT_ID', 'DISCOUNT_PERCENTAGE')
    : "SELECT PRODUCT_ID, MAX(DISCOUNT_PERCENTAGE) AS DISCOUNT_PERCENTAGE FROM DISCOUNT WHERE DISCOUNT_PERCENTAGE > 0 AND DISCOUNT_PERCENTAGE <= 100 AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE) AND TRUNC(END_DATE) GROUP BY PRODUCT_ID";

$cartSql = '
    SELECT
        ci.product_id,
        ci.quantity,
        p.product_name,
        p.item_price,
        NVL(p.quantity_per_item, 1) AS quantity_per_item,
        NVL(p.max_order, 99) AS max_order,
        NVL(p.stock_available, 0) AS stock_available,
        p.allergy_info,
        p.product_image,
        s.shop_name,
        NVL(d.discount_percentage, 0) AS discount_percentage
     FROM CART c
     JOIN CART_ITEM ci ON ci.cart_id = c.cart_id
     JOIN PRODUCT p ON p.product_id = ci.product_id
     JOIN SHOP s ON s.shop_id = p.shop_id
     LEFT JOIN (
        ' . $activeDiscountSubquery . '
     ) d ON d.product_id = p.product_id
     WHERE c.customer_id = :customer_id
       AND ' . $publicProductFilter . '
     ORDER BY p.product_name';

$cartRows = fetch_all(
    $cartSql,
    ['customer_id' => $customerId]
);

$cart = [];
$itemCount = 0;
$subtotalBeforeProductDiscount = 0.0;
$subtotal = 0.0;
$productDiscountTotal = 0.0;

foreach ($cartRows as $row) {
    $price = (float)$row['ITEM_PRICE'];
    $discountPercent = max(0, min(100, (float)$row['DISCOUNT_PERCENTAGE']));
    $discountedPrice = round($price - ($price * $discountPercent / 100), 2);
    $qty = (int)$row['QUANTITY'];
    $lineBeforeDiscount = $price * $qty;
    $lineTotal = $discountedPrice * $qty;

    $cart[] = [
        'product_id' => (string)$row['PRODUCT_ID'],
        'name' => (string)$row['PRODUCT_NAME'],
        'shop_name' => (string)$row['SHOP_NAME'],
        'quantity_per_item' => (int)$row['QUANTITY_PER_ITEM'],
        'allergy_info' => (string)($row['ALLERGY_INFO'] ?? ''),
        'price' => $price,
        'discount_percent' => $discountPercent,
        'discounted_price' => $discountedPrice,
        'qty' => $qty,
        'max_order' => max(1, min(100, (int)$row['MAX_ORDER'])),
        'stock_available' => max(0, (int)($row['STOCK_AVAILABLE'] ?? 0)),
        'line_before_discount' => $lineBeforeDiscount,
        'line_total' => $lineTotal,
        'image' => product_image_url((string)($row['PRODUCT_IMAGE'] ?? ''), (string)$row['PRODUCT_ID']),
    ];

    $itemCount += $qty;
    $subtotalBeforeProductDiscount += $lineBeforeDiscount;
    $subtotal += $lineTotal;
}

$productDiscountTotal = max(0, $subtotalBeforeProductDiscount - $subtotal);

$checkoutWarnings = [];

if ($itemCount <= 0) {
    $checkoutWarnings[] = 'Add at least one product before checkout.';
} elseif ($itemCount > 20) {
    $itemsToRemove = $itemCount - 20;
    $checkoutWarnings[] = 'Your cart has ' . $itemCount . ' items. One order can contain a maximum of 20 items. Remove ' . $itemsToRemove . ' item' . ($itemsToRemove === 1 ? '' : 's') . ' before checkout.';
}

foreach ($cart as $item) {
    $stockAvailable = (int)($item['stock_available'] ?? 0);
    $cartQuantity = (int)($item['qty'] ?? 0);

    if ($cartQuantity > $stockAvailable) {
        $checkoutWarnings[] = (string)$item['name'] . ' only has ' . $stockAvailable . ' item' . ($stockAvailable === 1 ? '' : 's') . ' in stock, but your cart has ' . $cartQuantity . '.';
    }
}

$sessionCheckoutWarnings = $_SESSION['cart_checkout_warnings'] ?? [];
unset($_SESSION['cart_checkout_warnings']);

if (is_array($sessionCheckoutWarnings)) {
    foreach ($sessionCheckoutWarnings as $warning) {
        $warning = trim((string)$warning);
        if ($warning !== '' && !in_array($warning, $checkoutWarnings, true)) {
            $checkoutWarnings[] = $warning;
        }
    }
}

$checkoutBlocked = !empty($checkoutWarnings);

$voucher = null;
$voucherDiscount = 0.0;
$voucherCode = $_SESSION['cart_voucher_code'] ?? '';
$voucherNotice = $_SESSION['cart_voucher_notice'] ?? '';
unset($_SESSION['cart_voucher_notice']);

if ($voucherCode !== '' && $subtotal > 0) {
    if (function_exists('customer_validate_voucher')) {
        [$voucher, $voucherDiscount] = customer_validate_voucher($conn, $voucherCode, $subtotal, $voucherNotice);
        if (!$voucher) {
            unset($_SESSION['cart_voucher_code']);
            $voucherCode = '';
        }
    } else {
        $voucher = fetch_one(
            'SELECT voucher_id, voucher_code, discount_type, discount_value, min_order_amount, used_count, usage_limit
             FROM VOUCHER
             WHERE UPPER(voucher_code) = :voucher_code
               AND UPPER(status) = :status
               AND start_date IS NOT NULL
               AND end_date IS NOT NULL
               AND end_date > start_date
               AND TRUNC(SYSDATE) BETWEEN TRUNC(start_date) AND TRUNC(end_date)
               AND NVL(used_count, 0) < NVL(usage_limit, 0)',
            ['voucher_code' => strtoupper($voucherCode), 'status' => 'ACTIVE']
        );

        if (!$voucher) {
            $voucherNotice = 'Invalid or expired voucher code.';
            unset($_SESSION['cart_voucher_code']);
            $voucherCode = '';
        } elseif ($subtotal < (float)$voucher['MIN_ORDER_AMOUNT']) {
            $voucherNotice = 'This voucher requires a minimum order of ' . money($voucher['MIN_ORDER_AMOUNT']) . '.';
            $voucher = null;
        } else {
            if (strtoupper((string)$voucher['DISCOUNT_TYPE']) === 'PERCENTAGE') {
                $voucherPercent = max(0, min(100, (float)$voucher['DISCOUNT_VALUE']));
                $voucherDiscount = round($subtotal * ($voucherPercent / 100), 2);
            } else {
                $voucherDiscount = max(0, (float)$voucher['DISCOUNT_VALUE']);
            }
            $voucherDiscount = min($voucherDiscount, $subtotal);
            $voucherNotice = 'Voucher applied.';
        }
    }
}

$total = max(0, $subtotal - $voucherDiscount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart — ShopLocalfy</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/customer/css/cart.css?v=20260519-cart-warning">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<main class="page-shell">
    <div class="breadcrumb">
        <a href="index.php">Home</a>
        <span>/</span>
        <strong>Shopping Cart</strong>
    </div>

    <h1 class="page-title">Shopping Cart</h1>

    <div class="cart-grid">
        <section class="cart-card">
            <div class="cart-head">
                <span>Item Details</span>
                <span>Total</span>
            </div>

            <?php if (empty($cart)): ?>
                <div class="empty-state">
                    <h2>Your cart is empty</h2>
                    <p>Products added to your cart will show here dynamically.</p>
                    <a href="index.php" class="shop-btn">Continue Shopping</a>
                </div>
            <?php else: ?>
                <?php foreach ($cart as $item): ?>
                    <article class="cart-item">
                        <img class="item-img" src="<?= h($item['image']) ?>" alt="<?= h($item['name']) ?>" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';">

                        <div class="item-info">
                            <h2 class="item-name"><?= h($item['name']) ?></h2>
                            <p class="item-shop">by <?= h($item['shop_name']) ?> · <?= h($item['quantity_per_item']) ?> item<?= $item['quantity_per_item'] === 1 ? '' : 's' ?></p>

                            <?php if ($item['allergy_info'] !== ''): ?>
                                <span class="allergy-pill">Allergy: <?= h($item['allergy_info']) ?></span>
                            <?php endif; ?>

                            <p class="item-price">
                                <?= money($item['discounted_price']) ?> each
                                <?php if ($item['discount_percent'] > 0): ?>
                                    <span class="old-price"><?= money($item['price']) ?></span>
                                    <span class="discount-pill"><?= number_format($item['discount_percent'], 0) ?>% off</span>
                                <?php endif; ?>
                            </p>

                            <?php if ((int)$item['qty'] > (int)$item['stock_available']): ?>
                                <div class="item-stock-warning" role="alert">
                                    Only <?= h($item['stock_available']) ?> item<?= (int)$item['stock_available'] === 1 ? '' : 's' ?> available. Your cart has <?= h($item['qty']) ?>.
                                </div>
                            <?php else: ?>
                                <div class="item-stock-ok">Stock available: <?= h($item['stock_available']) ?></div>
                            <?php endif; ?>

                            <div class="item-actions">
                                <form method="POST" class="qty-form">
                                    <input type="hidden" name="action" value="update_qty">
                                    <input type="hidden" name="product_id" value="<?= h($item['product_id']) ?>">
                                    <span class="qty-label">Qty</span>
                                    <div class="qty-control">
                                        <button type="button" class="qty-btn minus-btn">−</button>
                                        <input class="qty-num" type="number" name="qty" min="1" max="<?= h($item['max_order']) ?>" value="<?= h($item['qty']) ?>">
                                        <button type="button" class="qty-btn plus-btn">+</button>
                                    </div>
                                </form>

                                <form method="POST">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= h($item['product_id']) ?>">
                                    <button type="submit" class="remove-btn">Remove</button>
                                </form>
                            </div>
                        </div>

                        <div class="line-total"><?= money($item['line_total']) ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <aside class="summary-card">
            <h2 class="summary-title">Order Summary</h2>

            <div class="summary-row">
                <span>Items</span>
                <span><?= h($itemCount) ?></span>
            </div>
            <div class="summary-row">
                <span>Subtotal</span>
                <span><?= money($subtotalBeforeProductDiscount) ?></span>
            </div>
            <?php if ($productDiscountTotal > 0): ?>
                <div class="summary-row">
                    <span>Product Discount</span>
                    <span class="save">− <?= money($productDiscountTotal) ?></span>
                </div>
            <?php endif; ?>

            <div class="voucher-box">
                <div class="voucher-title">Voucher</div>
                <p class="voucher-help">Enter an active voucher code from your VOUCHER table. It will be checked against date, status, usage limit, and minimum order.</p>

                <form method="POST" class="voucher-form">
                    <input type="hidden" name="action" value="apply_voucher">
                    <input class="voucher-input" type="text" name="voucher_code" placeholder="Voucher code" value="<?= h($voucherCode) ?>">
                    <button type="submit" class="apply-btn">Apply</button>
                </form>

                <?php if ($voucherNotice !== ''): ?>
                    <div class="voucher-note"><?= h($voucherNotice) ?></div>
                <?php endif; ?>

                <?php if ($voucher && $voucherCode !== ''): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="remove_voucher">
                        <button type="submit" class="remove-voucher">Remove voucher</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($voucherDiscount > 0): ?>
                <div class="summary-row">
                    <span>Voucher Discount</span>
                    <span class="save">− <?= money($voucherDiscount) ?></span>
                </div>
            <?php endif; ?>

            <hr class="summary-divider">

            <div class="total-row">
                <span>Total</span>
                <span><?= money($total) ?></span>
            </div>

            <?php if ($checkoutBlocked): ?>
                <div class="cart-limit-warning" role="alert">
                    <?php foreach ($checkoutWarnings as $warning): ?>
                        <div><?= h($warning) ?></div>
                    <?php endforeach; ?>
                </div>
                <button class="checkout-btn checkout-btn-disabled" type="button" disabled aria-disabled="true">Proceed to Checkout</button>
            <?php else: ?>
                <a class="checkout-btn" href="checkout.php">Proceed to Checkout</a>
            <?php endif; ?>

            <?php if (!empty($cart)): ?>
                <form method="POST" class="clear-form">
                    <input type="hidden" name="action" value="clear_cart">
                    <button type="submit" class="clear-btn">Clear Cart</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
</main>

<script src="../assets/customer/js/cart.js?v=20260519-cart-warning"></script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

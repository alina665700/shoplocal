<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../config/cart_cleanup.php';

date_default_timezone_set('Asia/Kathmandu');
if (session_status() === PHP_SESSION_NONE) session_start();

$adminId = require_admin_login();
$conn = admin_db_connection();
$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');

if (!function_exists('admin_h')) {
    function admin_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_cols')) {
    function admin_cols($conn, $table) {
        if (!$conn || !table_exists($conn, $table)) return [];
        $rows = db_all($conn, "
            SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH
            FROM USER_TAB_COLUMNS
            WHERE TABLE_NAME = :table_name
            ORDER BY COLUMN_ID
        ", [':table_name' => strtoupper($table)]);
        $cols = [];
        foreach ($rows as $row) $cols[strtoupper($row['COLUMN_NAME'])] = $row;
        return $cols;
    }
}

if (!function_exists('admin_pick_col')) {
    function admin_pick_col($cols, $names) {
        foreach ($names as $name) {
            $name = strtoupper($name);
            if (isset($cols[$name])) return $name;
        }
        return null;
    }
}

if (!function_exists('admin_redirect_pending')) {
    function admin_redirect_pending($success = '', $error = '') {
        $params = [];
        if ($success !== '') $params['success'] = $success;
        if ($error !== '') $params['error'] = $error;
        $query = $params ? '?' . http_build_query($params) : '';
        header('Location: pending-requests.php' . $query);
        exit;
    }
}

if (!function_exists('admin_name_expr')) {
    function admin_name_expr($cols) {
        if (isset($cols['FULL_NAME'])) return 'FULL_NAME';
        if (isset($cols['NAME'])) return 'NAME';
        if (isset($cols['USERNAME'])) return 'USERNAME';
        if (isset($cols['FIRST_NAME']) && isset($cols['LAST_NAME'])) return "TRIM(FIRST_NAME || ' ' || LAST_NAME)";
        if (isset($cols['FIRST_NAME'])) return 'FIRST_NAME';
        if (isset($cols['EMAIL_ADDRESS'])) return 'EMAIL_ADDRESS';
        if (isset($cols['EMAIL'])) return 'EMAIL';
        return "'Unknown'";
    }
}

if (!function_exists('admin_user_display')) {
    function admin_user_display($conn, $userId) {
        if ($userId === null || $userId === '') return ['name' => 'Unknown', 'email' => '—'];
        $cols = admin_cols($conn, 'USER');
        $idCol = admin_pick_col($cols, ['USER_ID', 'ID']);
        if (!$idCol) return ['name' => (string)$userId, 'email' => '—'];
        $nameExpr = admin_name_expr($cols);
        $emailCol = admin_pick_col($cols, ['EMAIL_ADDRESS', 'EMAIL', 'USER_EMAIL', 'MAIL']);
        $emailSelect = $emailCol ? "$emailCol AS EMAIL" : "NULL AS EMAIL";
        $row = db_one($conn, "SELECT $nameExpr AS NAME, $emailSelect FROM \"USER\" WHERE $idCol = :id", [':id' => $userId]);
        return ['name' => $row['NAME'] ?? (string)$userId, 'email' => $row['EMAIL'] ?? '—'];
    }
}

if (!function_exists('admin_shop_name_for_trader')) {
    function admin_shop_name_for_trader($conn, $traderId) {
        $cols = admin_cols($conn, 'SHOP');
        if (!$cols) return '—';
        $nameCol = admin_pick_col($cols, ['SHOP_NAME', 'NAME', 'STORE_NAME']);
        $ownerCol = admin_pick_col($cols, ['TRADER_ID', 'USER_ID', 'OWNER_ID']);
        if (!$nameCol || !$ownerCol) return '—';
        $row = db_one($conn, "SELECT $nameCol AS SHOP_NAME FROM SHOP WHERE $ownerCol = :id FETCH FIRST 1 ROWS ONLY", [':id' => $traderId]);
        return $row['SHOP_NAME'] ?? '—';
    }
}

if (!function_exists('admin_pending_traders')) {
    function admin_pending_traders($conn) {
        $cols = admin_cols($conn, 'TRADER');
        if (!$cols) return [];
        $idCol = admin_pick_col($cols, ['USER_ID', 'TRADER_ID', 'ID']);
        if (!$idCol) return [];
        $statusCol = admin_pick_col($cols, ['VERIFIED_STATUS', 'STATUS', 'APPROVAL_STATUS']);
        $panCol = admin_pick_col($cols, ['PAN_NUMBER', 'PAN_NO']);
        $dateCol = admin_pick_col($cols, ['CREATED_AT', 'CREATED_DATE', 'REGISTERED_AT', 'REQUEST_DATE', 'SUBMITTED_AT']);
        $dateSelect = $dateCol ? "TO_CHAR($dateCol, 'DD Mon YYYY') AS SUBMITTED" : "NULL AS SUBMITTED";
        $panSelect = $panCol ? "$panCol AS PAN_NUMBER" : "NULL AS PAN_NUMBER";
        $where = $statusCol ? "WHERE UPPER($statusCol) IN ('PENDING', 'UNVERIFIED')" : '';
        $order = $dateCol ? "ORDER BY $dateCol DESC" : "ORDER BY $idCol DESC";
        $rows = db_all($conn, "SELECT $idCol AS TRADER_ID, " . ($statusCol ? "$statusCol" : "'PENDING'") . " AS STATUS, $panSelect, $dateSelect FROM TRADER $where $order");
        foreach ($rows as &$row) {
            $user = admin_user_display($conn, $row['TRADER_ID'] ?? '');
            $row['TRADER_NAME'] = $user['name'];
            $row['EMAIL'] = $user['email'];
            $row['SHOP_NAME'] = admin_shop_name_for_trader($conn, $row['TRADER_ID'] ?? '');
            $row['SUBMITTED'] = $row['SUBMITTED'] ?: '—';
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('admin_current_admin_id')) {
    function admin_current_admin_id() {
        foreach (['admin_id', 'ADMIN_ID', 'user_id', 'USER_ID'] as $key) {
            if (!empty($_SESSION[$key])) return $_SESSION[$key];
        }
        return null;
    }
}

if (!function_exists('admin_review_text_value')) {
    function admin_review_text_value($value, $fallback = '') {
        if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
            $loaded = $value->load();
            $value = $loaded === false ? '' : $loaded;
        }

        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('admin_review_rating_text')) {
    function admin_review_rating_text($rating) {
        return number_format((float)$rating, 1) . '/5';
    }
}

if (!function_exists('admin_pending_review_reports')) {
    function admin_pending_review_reports($conn) {
        if (!$conn || !table_exists($conn, 'REVIEW')) return [];

        $sql = "
            SELECT
                r.REVIEW_ID,
                r.RATING,
                r.REVIEW_TEXT,
                r.REPORT_REASON,
                TO_CHAR(r.DATE_POSTED, 'DD Mon YYYY') AS DATE_POSTED,
                TO_CHAR(r.REPORTED_DATE, 'DD Mon YYYY') AS REPORTED_DATE,
                p.PRODUCT_NAME,
                s.SHOP_NAME,
                NVL(TRIM(cu.FIRST_NAME || ' ' || cu.LAST_NAME), 'Customer') AS CUSTOMER_NAME,
                NVL(cu.EMAIL_ADDRESS, '') AS CUSTOMER_EMAIL,
                NVL(TRIM(tu.FIRST_NAME || ' ' || tu.LAST_NAME), 'Trader') AS TRADER_NAME,
                NVL(tu.EMAIL_ADDRESS, '') AS TRADER_EMAIL
            FROM REVIEW r
            JOIN PRODUCT p ON p.PRODUCT_ID = r.PRODUCT_ID
            LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            LEFT JOIN \"USER\" cu ON cu.USER_ID = r.CUSTOMER_ID
            LEFT JOIN \"USER\" tu ON tu.USER_ID = r.REPORTED_BY
            WHERE r.REPORTED_BY IS NOT NULL
              AND UPPER(NVL(r.APPROVAL_STATUS, 'YES')) = 'YES'
            ORDER BY r.REPORTED_DATE DESC NULLS LAST, r.DATE_POSTED DESC NULLS LAST, r.REVIEW_ID DESC
        ";

        return db_all($conn, $sql);
    }
}



if (!function_exists('admin_pending_products')) {
    function admin_pending_products($conn) {
        if (!$conn || !table_exists($conn, 'PRODUCT') || !column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS')) return [];

        $imageSelect = column_exists($conn, 'PRODUCT', 'PRODUCT_IMAGE') ? 'p.PRODUCT_IMAGE' : "NULL AS PRODUCT_IMAGE";
        $descriptionSelect = column_exists($conn, 'PRODUCT', 'DESCRIPTION') ? 'p.DESCRIPTION' : "NULL AS DESCRIPTION";
        $stockSelect = column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE') ? 'NVL(p.STOCK_AVAILABLE, 0) AS STOCK_AVAILABLE' : '0 AS STOCK_AVAILABLE';
        $priceSelect = column_exists($conn, 'PRODUCT', 'ITEM_PRICE') ? 'NVL(p.ITEM_PRICE, 0) AS ITEM_PRICE' : '0 AS ITEM_PRICE';

        $shopJoin = table_exists($conn, 'SHOP') ? 'LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID' : '';
        $shopNameSelect = table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'SHOP_NAME') ? 's.SHOP_NAME' : "NULL AS SHOP_NAME";
        $shopStatusSelect = table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'APPROVAL_STATUS') ? "NVL(s.APPROVAL_STATUS, 'PENDING') AS SHOP_STATUS" : "NULL AS SHOP_STATUS";
        $traderIdSelect = table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'TRADER_ID') ? 's.TRADER_ID' : "NULL AS TRADER_ID";

        $categoryJoin = table_exists($conn, 'CATEGORY') ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID' : '';
        $categorySelect = table_exists($conn, 'CATEGORY') && column_exists($conn, 'CATEGORY', 'CATEGORY_NAME') ? 'c.CATEGORY_NAME' : "NULL AS CATEGORY_NAME";

        $userJoin = table_exists($conn, 'USER') && table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'TRADER_ID')
            ? 'LEFT JOIN "USER" tu ON tu.USER_ID = s.TRADER_ID'
            : '';
        $traderNameSelect = table_exists($conn, 'USER') && table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'TRADER_ID')
            ? "NVL(TRIM(tu.FIRST_NAME || ' ' || tu.LAST_NAME), s.TRADER_ID) AS TRADER_NAME"
            : "NULL AS TRADER_NAME";

        return db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                p.SHOP_ID,
                p.CATEGORY_ID,
                $descriptionSelect,
                $imageSelect,
                $priceSelect,
                $stockSelect,
                NVL(UPPER(TRIM(p.ADMIN_APPROVAL_STATUS)), 'PENDING') AS ADMIN_APPROVAL_STATUS,
                $shopNameSelect,
                $shopStatusSelect,
                $traderIdSelect,
                $traderNameSelect,
                $categorySelect
            FROM PRODUCT p
            $shopJoin
            $categoryJoin
            $userJoin
            WHERE UPPER(NVL(TRIM(p.ADMIN_APPROVAL_STATUS), 'PENDING')) = 'PENDING'
            ORDER BY p.PRODUCT_ID DESC
        ");
    }
}

if (!function_exists('admin_pending_money')) {
    function admin_pending_money($amount) {
        if (function_exists('admin_money')) return admin_money($amount);
        return '£' . number_format((float)$amount, 2);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');

        $action = $_POST['action'] ?? '';

        if (in_array($action, ['approve_product', 'reject_product'], true)) {
            if (!table_exists($conn, 'PRODUCT') || !column_exists($conn, 'PRODUCT', 'ADMIN_APPROVAL_STATUS')) {
                throw new RuntimeException('PRODUCT.ADMIN_APPROVAL_STATUS column was not found. Run the product approval ALTER TABLE code first.');
            }

            $productId = trim($_POST['product_id'] ?? '');
            if ($productId === '') throw new RuntimeException('Product ID is missing.');

            $newStatus = $action === 'approve_product' ? 'APPROVED' : 'REJECTED';
            $sets = ['ADMIN_APPROVAL_STATUS = :status'];
            $binds = [':status' => $newStatus, ':product_id' => $productId];

            $currentAdminId = admin_current_admin_id() ?: $adminId;
            if (column_exists($conn, 'PRODUCT', 'ADMIN_ID') && $currentAdminId) {
                $sets[] = 'ADMIN_ID = :admin_id';
                $binds[':admin_id'] = $currentAdminId;
            }

            db_bind_and_execute($conn, 'UPDATE PRODUCT SET ' . implode(', ', $sets) . ' WHERE PRODUCT_ID = :product_id', $binds);
            if ($newStatus !== 'APPROVED') {
                remove_product_from_all_carts($conn, $productId);
            }
            admin_redirect_pending($newStatus === 'APPROVED' ? 'Product approved successfully.' : 'Product rejected successfully.');
        }

        if (in_array($action, ['approve_review_report', 'reject_review_report'], true)) {
            $reviewId = trim($_POST['review_id'] ?? '');
            if ($reviewId === '') throw new RuntimeException('Review ID is missing.');

            if ($action === 'approve_review_report') {
                db_bind_and_execute($conn, "
                    UPDATE REVIEW
                    SET APPROVAL_STATUS = 'NO'
                    WHERE REVIEW_ID = :review_id
                      AND REPORTED_BY IS NOT NULL
                ", [':review_id' => $reviewId]);

                admin_redirect_pending('Review report approved. The review is now hidden.');
            }

            db_bind_and_execute($conn, "
                UPDATE REVIEW
                SET REPORTED_BY = NULL,
                    REPORT_REASON = NULL,
                    REPORTED_DATE = NULL
                WHERE REVIEW_ID = :review_id
                  AND REPORTED_BY IS NOT NULL
            ", [':review_id' => $reviewId]);

            admin_redirect_pending('Review report rejected. The review remains visible.');
        }

        $cols = admin_cols($conn, 'TRADER');
        $idCol = admin_pick_col($cols, ['USER_ID', 'TRADER_ID', 'ID']);
        $statusCol = admin_pick_col($cols, ['VERIFIED_STATUS', 'STATUS', 'APPROVAL_STATUS']);
        if (!$idCol || !$statusCol) throw new RuntimeException('TRADER ID or status column was not found.');

        $traderId = trim($_POST['trader_id'] ?? '');
        if ($traderId === '') throw new RuntimeException('Trader ID is missing.');
        if (!in_array($action, ['approve', 'reject'], true)) throw new RuntimeException('Invalid request action.');

        $newStatus = $action === 'approve' ? 'VERIFIED' : 'REJECTED';

        if ($newStatus === 'VERIFIED') {
            $verifiedCountRow = db_one($conn, "
                SELECT COUNT(*) AS TOTAL
                FROM TRADER
                WHERE UPPER(NVL($statusCol, 'PENDING')) = 'VERIFIED'
                  AND $idCol <> :id
            ", [':id' => $traderId]);
            $verifiedCount = (int)($verifiedCountRow['TOTAL'] ?? 0);

            if ($verifiedCount >= 10) {
                throw new RuntimeException('ShopLocalfy already has 10 approved traders. Reject or remove a trader before approving another one.');
            }
        }

        $sets = ["$statusCol = :status"];
        $binds = [':status' => $newStatus, ':id' => $traderId];
        $adminCol = admin_pick_col($cols, ['ADMIN_ID']);
        $currentAdminId = admin_current_admin_id();
        if ($adminCol && $currentAdminId) {
            $sets[] = "$adminCol = :admin_id";
            $binds[':admin_id'] = $currentAdminId;
        }
        db_bind_and_execute($conn, 'UPDATE TRADER SET ' . implode(', ', $sets) . " WHERE $idCol = :id", $binds);

        if (table_exists($conn, 'SHOP') && column_exists($conn, 'SHOP', 'APPROVAL_STATUS') && column_exists($conn, 'SHOP', 'TRADER_ID')) {
            $shopStatus = $newStatus === 'VERIFIED' ? 'APPROVED' : 'SUSPENDED';
            db_bind_and_execute($conn, 'UPDATE SHOP SET APPROVAL_STATUS = :status WHERE TRADER_ID = :trader_id', [
                ':status' => $shopStatus,
                ':trader_id' => $traderId
            ]);
        }

        if ($newStatus !== 'VERIFIED') {
            remove_unavailable_products_from_all_carts($conn);
        }

        admin_redirect_pending($action === 'approve' ? 'Trader approved successfully.' : 'Trader rejected successfully.');
    } catch (Throwable $e) {
        admin_redirect_pending('', shoplocalfy_public_exception_message($e, 'Could not update pending request.'));
    }
}

$pendingTraders = [];
$pendingProducts = [];
$pendingReviewReports = [];
try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    $pendingTraders = admin_pending_traders($conn);
    $pendingProducts = admin_pending_products($conn);
    $pendingReviewReports = admin_pending_review_reports($conn);
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load pending requests.');
}

$totalPendingRequests = count($pendingTraders) + count($pendingProducts) + count($pendingReviewReports);
$todayLabel = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=10" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=10" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=10" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy – Pending Requests</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/pending-requests.css?v=20260517">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>

    <main class="soft-admin-page">
      <section class="soft-hero">
        <div>
          <p class="soft-kicker">Admin Control Centre</p>
          <h1 class="soft-title">Pending Requests</h1>
        </div>
        <div class="date-pill"><i class="fa-regular fa-calendar"></i> <?= admin_h($todayLabel) ?></div>
      </section>

      <?php if ($message !== ''): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i><?= admin_h($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i><?= admin_h($error) ?></div>
      <?php endif; ?>

      <section class="soft-stats">
        <article class="soft-stat">
          <div class="soft-icon orange"><i class="fa-solid fa-clock"></i></div>
          <div><span class="stat-label">Total Pending</span><span class="stat-value"><?= (int)$totalPendingRequests ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon"><i class="fa-solid fa-store"></i></div>
          <div><span class="stat-label">Trader Requests</span><span class="stat-value"><?= count($pendingTraders) ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon orange"><i class="fa-solid fa-box-open"></i></div>
          <div><span class="stat-label">Product Approvals</span><span class="stat-value"><?= count($pendingProducts) ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon blue"><i class="fa-solid fa-flag"></i></div>
          <div><span class="stat-label">Review Reports</span><span class="stat-value"><?= count($pendingReviewReports) ?></span></div>
        </article>
        <article class="soft-stat">
          <div class="soft-icon"><i class="fa-solid fa-shield-halved"></i></div>
          <div><span class="stat-label">Admin Queue</span><span class="stat-value"><?= $totalPendingRequests > 0 ? 'Open' : 'Clear' ?></span></div>
        </article>
      </section>

      <div class="request-layout">
        <div>
          <section class="soft-card" id="trader-requests">
            <div class="soft-card-header">
              <div>
                <h2 class="soft-card-title">Trader Applications</h2>
              </div>
              <span class="count-pill"><i class="fa-solid fa-user-clock"></i> <?= count($pendingTraders) ?> pending</span>
            </div>
            <div class="soft-card-body">
              <?php if (!$pendingTraders): ?>
                <div class="empty-card">
                  <span class="soft-icon"><i class="fa-solid fa-circle-check"></i></span>
                  <div>
                    <h3 class="request-title">No pending trader requests</h3>
                  </div>
                </div>
              <?php else: ?>
                <div class="request-list">
                  <?php foreach ($pendingTraders as $trader): ?>
                    <article class="request-card">
                      <div class="request-top">
                        <div class="request-heading">
                          <span class="soft-icon orange"><i class="fa-solid fa-store"></i></span>
                          <div>
                            <h3 class="request-title"><?= admin_h($trader['TRADER_NAME'] ?? 'Trader') ?></h3>
                            <p class="request-desc">Trader account waiting for approval.</p>
                          </div>
                        </div>
                        <span class="soft-badge pending"><i class="fa-solid fa-clock"></i><?= admin_h($trader['STATUS'] ?? 'PENDING') ?></span>
                      </div>

                      <div class="info-grid">
                        <div class="info-box"><span class="info-label">Trader ID</span><span class="info-value"><?= admin_h($trader['TRADER_ID'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Shop</span><span class="info-value"><?= admin_h($trader['SHOP_NAME'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Email</span><span class="info-value"><?= admin_h($trader['EMAIL'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">PAN Number</span><span class="info-value"><?= admin_h($trader['PAN_NUMBER'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Submitted</span><span class="info-value"><?= admin_h($trader['SUBMITTED'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Current Status</span><span class="info-value"><?= admin_h($trader['STATUS'] ?? 'PENDING') ?></span></div>
                      </div>

                      <div class="note-box warning">The trader stays pending until this request is approved or rejected by an admin.</div>

                      <div class="action-row">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Approve this trader?');">
                          <input type="hidden" name="trader_id" value="<?= admin_h($trader['TRADER_ID'] ?? '') ?>">
                          <input type="hidden" name="action" value="approve">
                          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Reject this trader?');">
                          <input type="hidden" name="trader_id" value="<?= admin_h($trader['TRADER_ID'] ?? '') ?>">
                          <input type="hidden" name="action" value="reject">
                          <button class="btn btn-danger" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                        </form>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <section class="soft-card" id="product-requests">
            <div class="soft-card-header">
              <div>
                <h2 class="soft-card-title">Product Approvals</h2>
              </div>
              <span class="count-pill"><i class="fa-solid fa-box-open"></i> <?= count($pendingProducts) ?> pending</span>
            </div>
            <div class="soft-card-body">
              <?php if (!$pendingProducts): ?>
                <div class="empty-card">
                  <span class="soft-icon"><i class="fa-solid fa-circle-check"></i></span>
                  <div>
                    <h3 class="request-title">No pending product approvals</h3>
                  </div>
                </div>
              <?php else: ?>
                <div class="request-list">
                  <?php foreach ($pendingProducts as $product): ?>
                    <?php
                      $productId = admin_review_text_value($product['PRODUCT_ID'] ?? '');
                      $description = admin_review_text_value($product['DESCRIPTION'] ?? '', 'No product description added.');
                    ?>
                    <article class="request-card">
                      <div class="request-top">
                        <div class="request-heading">
                          <span class="soft-icon orange"><i class="fa-solid fa-box-open"></i></span>
                          <div>
                            <h3 class="request-title"><?= admin_h($product['PRODUCT_NAME'] ?? 'Product') ?></h3>
                            <p class="request-desc">Product waiting for admin approval before customer display.</p>
                          </div>
                        </div>
                        <span class="soft-badge pending"><i class="fa-solid fa-clock"></i><?= admin_h($product['ADMIN_APPROVAL_STATUS'] ?? 'PENDING') ?></span>
                      </div>

                      <div class="info-grid">
                        <div class="info-box"><span class="info-label">Product ID</span><span class="info-value"><?= admin_h($productId) ?></span></div>
                        <div class="info-box"><span class="info-label">Shop</span><span class="info-value"><?= admin_h($product['SHOP_NAME'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Trader</span><span class="info-value"><?= admin_h($product['TRADER_NAME'] ?? $product['TRADER_ID'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Category</span><span class="info-value"><?= admin_h($product['CATEGORY_NAME'] ?? 'Uncategorised') ?></span></div>
                        <div class="info-box"><span class="info-label">Price</span><span class="info-value"><?= admin_h(admin_pending_money($product['ITEM_PRICE'] ?? 0)) ?></span></div>
                        <div class="info-box"><span class="info-label">Stock</span><span class="info-value"><?= (int)($product['STOCK_AVAILABLE'] ?? 0) ?></span></div>
                      </div>

                      <div class="note-box"><span class="info-label">Description</span><?= admin_h($description) ?></div>
                      <?php if (($product['SHOP_STATUS'] ?? '') && strtoupper((string)$product['SHOP_STATUS']) !== 'APPROVED'): ?>
                        <div class="note-box warning"><span class="info-label">Shop Status</span>This product belongs to a shop currently marked <?= admin_h($product['SHOP_STATUS']) ?>.</div>
                      <?php endif; ?>

                      <div class="action-row">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Approve this product? Customers will be able to see it if the shop is approved, active, and in stock.');">
                          <input type="hidden" name="product_id" value="<?= admin_h($productId) ?>">
                          <input type="hidden" name="action" value="approve_product">
                          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Approve Product</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Reject this product? It will stay hidden from customers.');">
                          <input type="hidden" name="product_id" value="<?= admin_h($productId) ?>">
                          <input type="hidden" name="action" value="reject_product">
                          <button class="btn btn-danger" type="submit"><i class="fa-solid fa-xmark"></i> Reject Product</button>
                        </form>
                        <a class="btn btn-outline" href="manage-products.php"><i class="fa-solid fa-list-check"></i> Manage Products</a>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>


          <section class="soft-card" id="review-reports">
            <div class="soft-card-header">
              <div>
                <h2 class="soft-card-title">Review Reports</h2>
              </div>
              <span class="count-pill"><i class="fa-solid fa-flag"></i> <?= count($pendingReviewReports) ?> pending</span>
            </div>
            <div class="soft-card-body">
              <?php if (!$pendingReviewReports): ?>
                <div class="empty-card">
                  <span class="soft-icon"><i class="fa-solid fa-circle-check"></i></span>
                  <div>
                    <h3 class="request-title">No pending review reports</h3>
                  </div>
                </div>
              <?php else: ?>
                <div class="request-list">
                  <?php foreach ($pendingReviewReports as $review): ?>
                    <?php
                      $reviewId = admin_review_text_value($review['REVIEW_ID'] ?? '');
                      $reviewText = admin_review_text_value($review['REVIEW_TEXT'] ?? '', 'No written review.');
                      $reason = admin_review_text_value($review['REPORT_REASON'] ?? '', 'No reason provided.');
                    ?>
                    <article class="request-card">
                      <div class="request-top">
                        <div class="request-heading">
                          <span class="soft-icon blue"><i class="fa-solid fa-star-half-stroke"></i></span>
                          <div>
                            <h3 class="request-title"><?= admin_h($review['PRODUCT_NAME'] ?? 'Product') ?> review report</h3>
                            <p class="request-desc">A trader has reported this review.</p>
                          </div>
                        </div>
                        <span class="soft-badge pending"><i class="fa-solid fa-flag"></i> Reported</span>
                      </div>

                      <div class="info-grid">
                        <div class="info-box"><span class="info-label">Product</span><span class="info-value"><?= admin_h($review['PRODUCT_NAME'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Shop</span><span class="info-value"><?= admin_h($review['SHOP_NAME'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Customer</span><span class="info-value"><?= admin_h($review['CUSTOMER_NAME'] ?? '—') ?></span></div>
                        <div class="info-box"><span class="info-label">Rating</span><span class="info-value"><?= admin_h(admin_review_rating_text($review['RATING'] ?? 0)) ?></span></div>
                        <div class="info-box"><span class="info-label">Reported By</span><span class="info-value"><?= admin_h($review['TRADER_NAME'] ?? 'Trader') ?></span></div>
                        <div class="info-box"><span class="info-label">Reported Date</span><span class="info-value"><?= admin_h($review['REPORTED_DATE'] ?? '—') ?></span></div>
                      </div>

                      <div class="note-box"><span class="info-label">Review Text</span><?= admin_h($reviewText) ?></div>
                      <div class="note-box warning"><span class="info-label">Trader Report Reason</span><?= admin_h($reason) ?></div>

                      <div class="action-row">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Approve this report and hide the review?');">
                          <input type="hidden" name="review_id" value="<?= admin_h($reviewId) ?>">
                          <input type="hidden" name="action" value="approve_review_report">
                          <button class="btn btn-orange" type="submit"><i class="fa-solid fa-eye-slash"></i> Hide Review</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Reject this report and keep the review visible?');">
                          <input type="hidden" name="review_id" value="<?= admin_h($reviewId) ?>">
                          <input type="hidden" name="action" value="reject_review_report">
                          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Keep Visible</button>
                        </form>
                        <a class="btn btn-outline" href="reviews.php"><i class="fa-solid fa-star"></i> View Reviews</a>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <aside class="summary-card">
          <h2 class="summary-title">Request Summary</h2>
          <ul class="summary-list">
            <li><span>Total pending</span><strong><?= (int)$totalPendingRequests ?></strong></li>
            <li><span>Trader applications</span><strong><?= count($pendingTraders) ?></strong></li>
            <li><span>Product approvals</span><strong><?= count($pendingProducts) ?></strong></li>
            <li><span>Review reports</span><strong><?= count($pendingReviewReports) ?></strong></li>
          </ul>
          <div class="action-row" style="margin-top:18px;">
            <a class="btn btn-outline" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a class="btn btn-outline" href="reviews.php"><i class="fa-solid fa-star"></i> Reviews</a>
          </div>
        </aside>
      </div>
    </main>

    <?php include 'footer.php'; ?>
  </div>
</div>
</body>
</html>

<?php

require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();

$errors = [];
$notices = [];
$pendingFlags = [];
$allReviews = [];
$ratingByUsers = [];
$ratingByProducts = [];
$ratingByTraders = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'visible' => 0,
    'hidden' => 0,
];

$reviewSearch = trim((string)($_GET['review_search'] ?? ''));
$customerFilter = trim((string)($_GET['customer_id'] ?? ''));
$productFilter = trim((string)($_GET['product_id'] ?? ''));
$traderFilter = trim((string)($_GET['trader_id'] ?? ''));

$summaryView = strtolower(trim((string)($_GET['summary'] ?? 'all')));
$allowedSummaryViews = ['all', 'user', 'product', 'trader'];
if (!in_array($summaryView, $allowedSummaryViews, true)) {
    $summaryView = 'all';
}

$pageMode = strtolower(trim((string)($_GET['view'] ?? 'ratings')));
$pageMode = $pageMode === 'reviews' ? 'reviews' : 'ratings';

function admin_review_url(array $params = [], array $keep = []) {
    $query = [];

    foreach ($keep as $key => $value) {
        if ($value !== '' && $value !== null) {
            $query[$key] = $value;
        }
    }

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $queryString = http_build_query($query);
    return 'reviews.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function admin_review_value($value, $fallback = '') {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        $value = $loaded === false ? '' : $loaded;
    }

    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function admin_review_rating($rating) {
    return number_format((float)$rating, 1) . '/5';
}

function admin_review_rows_with_binds($conn, $sql, $binds = []) {
    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(shoplocalfy_db_error_message($conn, 'Could not prepare review query.'));
    }

    $localBinds = [];
    foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim((string)$key, ':');
        $localBinds[$bindName] = $value;
        oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
    }

    if (!oci_execute($stmt)) {
        oci_free_statement($stmt);
        throw new RuntimeException(shoplocalfy_db_error_message($stmt, 'Could not run review query.'));
    }

    $rows = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $rows[] = $row;
    }

    oci_free_statement($stmt);
    return $rows;
}


function admin_review_table_exists($conn, $tableName) {
    try {
        $rows = admin_review_rows_with_binds($conn, "
            SELECT COUNT(*) AS TOTAL
            FROM USER_TABLES
            WHERE TABLE_NAME = UPPER(:table_name)
        ", [':table_name' => $tableName]);
        return (int)($rows[0]['TOTAL'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function admin_review_column_exists($conn, $tableName, $columnName) {
    try {
        $rows = admin_review_rows_with_binds($conn, "
            SELECT COUNT(*) AS TOTAL
            FROM USER_TAB_COLUMNS
            WHERE TABLE_NAME = UPPER(:table_name)
              AND COLUMN_NAME = UPPER(:column_name)
        ", [':table_name' => $tableName, ':column_name' => $columnName]);
        return (int)($rows[0]['TOTAL'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function admin_review_filter_sql($reviewSearch, $customerFilter, $productFilter, $traderFilter, $traderNameExpr, $traderEmailExpr, $traderIdExpr, &$binds) {
    $where = [];
    $binds = [];

    if ($reviewSearch !== '') {
        $binds[':review_search'] = '%' . strtolower($reviewSearch) . '%';
        $where[] = "(
            LOWER(NVL(p.PRODUCT_NAME, '')) LIKE :review_search
            OR LOWER(NVL(s.SHOP_NAME, '')) LIKE :review_search
            OR LOWER(NVL(cu.FIRST_NAME, '') || ' ' || NVL(cu.LAST_NAME, '')) LIKE :review_search
            OR LOWER(NVL(cu.EMAIL_ADDRESS, '')) LIKE :review_search
            OR LOWER(NVL($traderNameExpr, '')) LIKE :review_search
            OR LOWER(NVL($traderEmailExpr, '')) LIKE :review_search
            OR LOWER(NVL(DBMS_LOB.SUBSTR(r.REVIEW_TEXT, 4000, 1), '')) LIKE :review_search
            OR TO_CHAR(r.RATING) LIKE :review_search
            OR LOWER(TO_CHAR(r.PRODUCT_ID)) LIKE :review_search
            OR LOWER(TO_CHAR($traderIdExpr)) LIKE :review_search
        )";
    }

    if ($customerFilter !== '') {
        $binds[':customer_id'] = $customerFilter;
        $where[] = 'r.CUSTOMER_ID = :customer_id';
    }

    if ($productFilter !== '') {
        $binds[':product_id'] = $productFilter;
        $where[] = 'r.PRODUCT_ID = :product_id';
    }

    if ($traderFilter !== '') {
        $binds[':trader_id'] = $traderFilter;
        $where[] = "$traderIdExpr = :trader_id";
    }

    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reviewId = trim((string)($_POST['review_id'] ?? ''));

    if ($reviewId === '') {
        $errors[] = 'Review ID was missing.';
    } else {
        try {
            if ($action === 'approve_flag') {
                execute_sql($conn, "
                    UPDATE REVIEW
                    SET APPROVAL_STATUS = 'NO'
                    WHERE REVIEW_ID = :review_id
                      AND REPORTED_BY IS NOT NULL
                ", [':review_id' => $reviewId]);
                $notices[] = 'Flag approved. Review is now hidden.';
            } elseif ($action === 'reject_flag') {
                execute_sql($conn, "
                    UPDATE REVIEW
                    SET REPORTED_BY = NULL,
                        REPORT_REASON = NULL,
                        REPORTED_DATE = NULL
                    WHERE REVIEW_ID = :review_id
                      AND REPORTED_BY IS NOT NULL
                ", [':review_id' => $reviewId]);
                $notices[] = 'Flag rejected. Review remains visible.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not update review: ' . shoplocalfy_public_exception_message($e, 'Could not update review.');
        }
    }
}

$shopHasTraderId = admin_review_column_exists($conn, 'SHOP', 'TRADER_ID');
$traderTableExists = admin_review_table_exists($conn, 'TRADER');
$traderHasTraderId = $traderTableExists && admin_review_column_exists($conn, 'TRADER', 'TRADER_ID');
$traderHasUserId = $traderTableExists && admin_review_column_exists($conn, 'TRADER', 'USER_ID');

$traderJoins = '';
if ($shopHasTraderId && $traderHasTraderId && $traderHasUserId) {
    $traderJoins = "
        LEFT JOIN TRADER tr ON tr.TRADER_ID = s.TRADER_ID
        LEFT JOIN \"USER\" ou ON ou.USER_ID = tr.USER_ID
    ";
    $traderIdExpr = 'NVL(tr.TRADER_ID, s.TRADER_ID)';
    $traderNameExpr = "NVL(TRIM(ou.FIRST_NAME || ' ' || ou.LAST_NAME), NVL(s.SHOP_NAME, 'Trader'))";
    $traderEmailExpr = "NVL(ou.EMAIL_ADDRESS, '')";
} elseif ($shopHasTraderId) {
    $traderJoins = "
        LEFT JOIN \"USER\" ou ON ou.USER_ID = s.TRADER_ID
    ";
    $traderIdExpr = 's.TRADER_ID';
    $traderNameExpr = "NVL(TRIM(ou.FIRST_NAME || ' ' || ou.LAST_NAME), NVL(s.SHOP_NAME, 'Trader'))";
    $traderEmailExpr = "NVL(ou.EMAIL_ADDRESS, '')";
} else {
    $traderIdExpr = 's.SHOP_ID';
    $traderNameExpr = "NVL(s.SHOP_NAME, 'Trader')";
    $traderEmailExpr = "''";
}

$baseReviewFrom = "
        FROM REVIEW r
        JOIN PRODUCT p ON p.PRODUCT_ID = r.PRODUCT_ID
        LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
        LEFT JOIN \"USER\" cu ON cu.USER_ID = r.CUSTOMER_ID
        $traderJoins
";
$allReviewsFrom = $baseReviewFrom . "
        LEFT JOIN \"USER\" tu ON tu.USER_ID = r.REPORTED_BY
";

try {
    $stats['total'] = admin_count('SELECT COUNT(*) AS TOTAL FROM REVIEW');
    $stats['pending'] = admin_count("SELECT COUNT(*) AS TOTAL FROM REVIEW WHERE REPORTED_BY IS NOT NULL AND UPPER(APPROVAL_STATUS) = 'YES'");
    $stats['visible'] = admin_count("SELECT COUNT(*) AS TOTAL FROM REVIEW WHERE UPPER(APPROVAL_STATUS) = 'YES'");
    $stats['hidden'] = admin_count("SELECT COUNT(*) AS TOTAL FROM REVIEW WHERE UPPER(APPROVAL_STATUS) = 'NO'");

    $pendingFlags = admin_rows("
        SELECT
            r.REVIEW_ID,
            r.RATING,
            r.REVIEW_TEXT,
            r.REPORT_REASON,
            TO_CHAR(r.REPORTED_DATE, 'YYYY-MM-DD') AS REPORTED_DATE,
            TO_CHAR(r.DATE_POSTED, 'YYYY-MM-DD') AS DATE_POSTED,
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
          AND UPPER(r.APPROVAL_STATUS) = 'YES'
        ORDER BY r.REPORTED_DATE DESC NULLS LAST, r.DATE_POSTED DESC NULLS LAST, r.REVIEW_ID DESC
    ");

    $filterBinds = [];
    $filterSql = admin_review_filter_sql($reviewSearch, $customerFilter, $productFilter, $traderFilter, $traderNameExpr, $traderEmailExpr, $traderIdExpr, $filterBinds);

    $ratingByUsers = admin_review_rows_with_binds($conn, "
        SELECT
            r.CUSTOMER_ID,
            NVL(TRIM(cu.FIRST_NAME || ' ' || cu.LAST_NAME), 'Customer') AS CUSTOMER_NAME,
            NVL(cu.EMAIL_ADDRESS, '') AS CUSTOMER_EMAIL,
            COUNT(*) AS REVIEW_COUNT,
            ROUND(AVG(NVL(r.RATING, 0)), 1) AS AVG_RATING,
            MIN(NVL(r.RATING, 0)) AS LOWEST_RATING,
            MAX(NVL(r.RATING, 0)) AS HIGHEST_RATING,
            TO_CHAR(MAX(r.DATE_POSTED), 'YYYY-MM-DD') AS LAST_REVIEW_DATE
        $baseReviewFrom
        $filterSql
        GROUP BY r.CUSTOMER_ID, NVL(TRIM(cu.FIRST_NAME || ' ' || cu.LAST_NAME), 'Customer'), NVL(cu.EMAIL_ADDRESS, '')
        ORDER BY AVG_RATING DESC, REVIEW_COUNT DESC, CUSTOMER_NAME ASC
    ", $filterBinds);

    $ratingByProducts = admin_review_rows_with_binds($conn, "
        SELECT
            r.PRODUCT_ID,
            NVL(p.PRODUCT_NAME, 'Product') AS PRODUCT_NAME,
            NVL(s.SHOP_NAME, 'Shop') AS SHOP_NAME,
            COUNT(*) AS REVIEW_COUNT,
            SUM(CASE WHEN UPPER(NVL(r.APPROVAL_STATUS, 'YES')) = 'YES' THEN 1 ELSE 0 END) AS VISIBLE_COUNT,
            SUM(CASE WHEN UPPER(NVL(r.APPROVAL_STATUS, 'YES')) = 'NO' THEN 1 ELSE 0 END) AS HIDDEN_COUNT,
            ROUND(AVG(NVL(r.RATING, 0)), 1) AS AVG_RATING,
            MIN(NVL(r.RATING, 0)) AS LOWEST_RATING,
            MAX(NVL(r.RATING, 0)) AS HIGHEST_RATING,
            TO_CHAR(MAX(r.DATE_POSTED), 'YYYY-MM-DD') AS LAST_REVIEW_DATE
        $baseReviewFrom
        $filterSql
        GROUP BY r.PRODUCT_ID, NVL(p.PRODUCT_NAME, 'Product'), NVL(s.SHOP_NAME, 'Shop')
        ORDER BY AVG_RATING DESC, REVIEW_COUNT DESC, PRODUCT_NAME ASC
    ", $filterBinds);

    $ratingByTraders = admin_review_rows_with_binds($conn, "
        SELECT
            $traderIdExpr AS TRADER_ID,
            $traderNameExpr AS TRADER_NAME,
            $traderEmailExpr AS TRADER_EMAIL,
            COUNT(DISTINCT p.PRODUCT_ID) AS PRODUCT_COUNT,
            COUNT(DISTINCT s.SHOP_ID) AS SHOP_COUNT,
            COUNT(*) AS REVIEW_COUNT,
            ROUND(AVG(NVL(r.RATING, 0)), 1) AS AVG_RATING,
            MIN(NVL(r.RATING, 0)) AS LOWEST_RATING,
            MAX(NVL(r.RATING, 0)) AS HIGHEST_RATING,
            TO_CHAR(MAX(r.DATE_POSTED), 'YYYY-MM-DD') AS LAST_REVIEW_DATE
        $baseReviewFrom
        $filterSql
        GROUP BY $traderIdExpr, $traderNameExpr, $traderEmailExpr
        ORDER BY AVG_RATING DESC, REVIEW_COUNT DESC, TRADER_NAME ASC
    ", $filterBinds);

    $allReviews = admin_review_rows_with_binds($conn, "
        SELECT
            r.REVIEW_ID,
            r.CUSTOMER_ID,
            r.RATING,
            r.REVIEW_TEXT,
            r.APPROVAL_STATUS,
            r.REPORT_REASON,
            TO_CHAR(r.DATE_POSTED, 'YYYY-MM-DD') AS DATE_POSTED,
            TO_CHAR(r.REPORTED_DATE, 'YYYY-MM-DD') AS REPORTED_DATE,
            p.PRODUCT_NAME,
            s.SHOP_NAME,
            $traderIdExpr AS TRADER_OWNER_ID,
            $traderNameExpr AS TRADER_OWNER_NAME,
            $traderEmailExpr AS TRADER_OWNER_EMAIL,
            NVL(TRIM(cu.FIRST_NAME || ' ' || cu.LAST_NAME), 'Customer') AS CUSTOMER_NAME,
            NVL(cu.EMAIL_ADDRESS, '') AS CUSTOMER_EMAIL,
            NVL(TRIM(tu.FIRST_NAME || ' ' || tu.LAST_NAME), '') AS REPORTED_BY_NAME
        $allReviewsFrom
        $filterSql
        ORDER BY r.DATE_POSTED DESC NULLS LAST, r.REVIEW_ID DESC
    ", $filterBinds);
} catch (Throwable $e) {
    $errors[] = 'Could not load reviews: ' . shoplocalfy_public_exception_message($e, 'Could not load reviews.');
}

$hasActiveReviewFilter = $reviewSearch !== '' || $customerFilter !== '' || $productFilter !== '' || $traderFilter !== '';

$tabKeep = [];
if ($reviewSearch !== '') {
    $tabKeep['review_search'] = $reviewSearch;
}

$reviewListTitle = 'All Reviews';
if ($customerFilter !== '') {
    $name = admin_review_value($allReviews[0]['CUSTOMER_NAME'] ?? '', 'selected user');
    $reviewListTitle = 'Reviews for ' . $name;
} elseif ($productFilter !== '') {
    $name = admin_review_value($allReviews[0]['PRODUCT_NAME'] ?? '', 'selected product');
    $reviewListTitle = 'Reviews for ' . $name;
} elseif ($traderFilter !== '') {
    $name = admin_review_value($allReviews[0]['TRADER_OWNER_NAME'] ?? '', 'selected trader');
    $reviewListTitle = 'Reviews for ' . $name;
} elseif ($reviewSearch !== '') {
    $reviewListTitle = 'Reviews matching "' . $reviewSearch . '"';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy - Reviews</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/reviews.css?v=20260517">
</head>
<body>

<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <div class="page-body">
      <div class="page-heading">
        <h1 class="page-title">Reviews</h1>
      </div>

      <?php foreach ($notices as $notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endforeach; ?>
      <?php foreach ($errors as $error): ?><div class="error"><?= admin_h($error) ?></div><?php endforeach; ?>

      <section class="stat-cards">
        <div class="stat-card"><span class="stat-label">Total Reviews</span><span class="stat-value"><?= (int)$stats['total'] ?></span></div>
        <div class="stat-card"><span class="stat-label">Pending Flags</span><span class="stat-value"><?= (int)$stats['pending'] ?></span></div>
        <div class="stat-card"><span class="stat-label">Visible</span><span class="stat-value"><?= (int)$stats['visible'] ?></span></div>
        <div class="stat-card"><span class="stat-label">Hidden</span><span class="stat-value"><?= (int)$stats['hidden'] ?></span></div>
      </section>

      <section class="filter-card">
        <div class="filter-title">Search reviews and rating summaries</div>
        <form method="GET" class="review-search-form">
          <input
            class="review-search-input"
            type="search"
            name="review_search"
            value="<?= admin_h($reviewSearch) ?>"
            placeholder="Search customer, trader, product, shop, review, or rating..."
          >
          <input type="hidden" name="summary" value="<?= admin_h($summaryView) ?>">
          <?php if ($pageMode === 'reviews'): ?>
            <input type="hidden" name="view" value="reviews">
          <?php endif; ?>
          <?php if ($customerFilter !== ''): ?>
            <input type="hidden" name="customer_id" value="<?= admin_h($customerFilter) ?>">
          <?php endif; ?>
          <?php if ($productFilter !== ''): ?>
            <input type="hidden" name="product_id" value="<?= admin_h($productFilter) ?>">
          <?php endif; ?>
          <?php if ($traderFilter !== ''): ?>
            <input type="hidden" name="trader_id" value="<?= admin_h($traderFilter) ?>">
          <?php endif; ?>
          <button class="search-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <?php if ($hasActiveReviewFilter): ?>
            <a class="clear-btn" href="reviews.php"><i class="fa-solid fa-xmark"></i> Clear</a>
          <?php endif; ?>
        </form>
        <?php if ($hasActiveReviewFilter): ?>
          <div class="filter-note">
            Showing <?= count($allReviews) ?> matching review<?= count($allReviews) === 1 ? '' : 's' ?>
            with <?= count($ratingByUsers) ?> customer<?= count($ratingByUsers) === 1 ? '' : 's' ?>, <?= count($ratingByProducts) ?> product<?= count($ratingByProducts) === 1 ? '' : 's' ?>, and <?= count($ratingByTraders) ?> trader<?= count($ratingByTraders) === 1 ? '' : 's' ?> in the rating summaries.
          </div>
        <?php endif; ?>
      </section>

      <?php if ($pageMode !== 'reviews'): ?>
        <nav class="summary-tabs" aria-label="Review rating summary sections">
          <a class="summary-tab <?= $summaryView === 'all' ? 'active' : '' ?>" href="<?= admin_h(admin_review_url(['summary' => 'all', 'view' => null], $tabKeep)) ?>"><i class="fa-solid fa-layer-group"></i> All ratings</a>
          <a class="summary-tab <?= $summaryView === 'user' ? 'active' : '' ?>" href="<?= admin_h(admin_review_url(['summary' => 'user', 'view' => null], $tabKeep)) ?>"><i class="fa-solid fa-user"></i> Rating per user</a>
          <a class="summary-tab <?= $summaryView === 'product' ? 'active' : '' ?>" href="<?= admin_h(admin_review_url(['summary' => 'product', 'view' => null], $tabKeep)) ?>"><i class="fa-solid fa-box"></i> Rating per product</a>
          <a class="summary-tab <?= $summaryView === 'trader' ? 'active' : '' ?>" href="<?= admin_h(admin_review_url(['summary' => 'trader', 'view' => null], $tabKeep)) ?>"><i class="fa-solid fa-store"></i> Rating per trader</a>
          <a class="summary-tab" href="<?= admin_h(admin_review_url(['view' => 'reviews', 'summary' => null], $tabKeep)) ?>"><i class="fa-solid fa-list"></i> Review list</a>
        </nav>

      <?php endif; ?>

      <?php if ($pageMode !== 'reviews' && ($summaryView === 'all' || $summaryView === 'user')): ?>
      <section class="card" id="rating-per-user">
        <div class="card-header">
          <span>Rating Per User</span>
          <span><?= count($ratingByUsers) ?> customer<?= count($ratingByUsers) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body">
          <table class="data-table user-rating-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Reviews</th>
                <th>Average Rating</th>
                <th>Lowest</th>
                <th>Highest</th>
                <th>Last Review</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ratingByUsers)): ?>
                <tr><td class="empty-row" colspan="7">No customer ratings found.</td></tr>
              <?php else: ?>
                <?php foreach ($ratingByUsers as $userRating): ?>
                  <?php
                    $summaryCustomerId = admin_review_value($userRating['CUSTOMER_ID'] ?? '');
                    $summaryQuery = ['view' => 'reviews', 'customer_id' => $summaryCustomerId];
                    if ($reviewSearch !== '') {
                        $summaryQuery['review_search'] = $reviewSearch;
                    }
                  ?>
                  <tr>
                    <td>
                      <strong><?= admin_h($userRating['CUSTOMER_NAME'] ?? 'Customer') ?></strong>
                      <div class="meta"><?= admin_h($userRating['CUSTOMER_EMAIL'] ?? '') ?></div>
                    </td>
                    <td><?= (int)($userRating['REVIEW_COUNT'] ?? 0) ?></td>
                    <td><span class="rating"><?= admin_h(admin_review_rating($userRating['AVG_RATING'] ?? 0)) ?></span></td>
                    <td><?= admin_h(admin_review_rating($userRating['LOWEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h(admin_review_rating($userRating['HIGHEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h($userRating['LAST_REVIEW_DATE'] ?? '-') ?></td>
                    <td>
                      <?php if ($summaryCustomerId !== ''): ?>
                        <a class="mini-link" href="reviews.php?<?= admin_h(http_build_query($summaryQuery)) ?>"><i class="fa-solid fa-list"></i> View reviews</a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pageMode !== 'reviews' && ($summaryView === 'all' || $summaryView === 'product')): ?>
      <section class="card" id="rating-per-product">
        <div class="card-header">
          <span>Rating Per Product</span>
          <span><?= count($ratingByProducts) ?> product<?= count($ratingByProducts) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body">
          <table class="data-table product-rating-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Reviews</th>
                <th>Visible</th>
                <th>Hidden</th>
                <th>Average Rating</th>
                <th>Lowest</th>
                <th>Highest</th>
                <th>Last Review</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ratingByProducts)): ?>
                <tr><td class="empty-row" colspan="9">No product ratings found.</td></tr>
              <?php else: ?>
                <?php foreach ($ratingByProducts as $productRating): ?>
                  <?php
                    $summaryProductId = admin_review_value($productRating['PRODUCT_ID'] ?? '');
                    $summaryQuery = ['view' => 'reviews', 'product_id' => $summaryProductId];
                    if ($reviewSearch !== '') {
                        $summaryQuery['review_search'] = $reviewSearch;
                    }
                  ?>
                  <tr>
                    <td>
                      <strong><?= admin_h($productRating['PRODUCT_NAME'] ?? 'Product') ?></strong>
                      <div class="meta"><?= admin_h($productRating['SHOP_NAME'] ?? 'Shop') ?></div>
                    </td>
                    <td><?= (int)($productRating['REVIEW_COUNT'] ?? 0) ?></td>
                    <td><?= (int)($productRating['VISIBLE_COUNT'] ?? 0) ?></td>
                    <td><?= (int)($productRating['HIDDEN_COUNT'] ?? 0) ?></td>
                    <td><span class="rating"><?= admin_h(admin_review_rating($productRating['AVG_RATING'] ?? 0)) ?></span></td>
                    <td><?= admin_h(admin_review_rating($productRating['LOWEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h(admin_review_rating($productRating['HIGHEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h($productRating['LAST_REVIEW_DATE'] ?? '-') ?></td>
                    <td>
                      <?php if ($summaryProductId !== ''): ?>
                        <a class="mini-link" href="reviews.php?<?= admin_h(http_build_query($summaryQuery)) ?>"><i class="fa-solid fa-list"></i> View reviews</a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pageMode !== 'reviews' && ($summaryView === 'all' || $summaryView === 'trader')): ?>
      <section class="card" id="rating-per-trader">
        <div class="card-header">
          <span>Rating Per Trader</span>
          <span><?= count($ratingByTraders) ?> trader<?= count($ratingByTraders) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body">
          <table class="data-table trader-rating-table">
            <thead>
              <tr>
                <th>Trader</th>
                <th>Products Reviewed</th>
                <th>Shops</th>
                <th>Reviews</th>
                <th>Average Rating</th>
                <th>Lowest</th>
                <th>Highest</th>
                <th>Last Review</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ratingByTraders)): ?>
                <tr><td class="empty-row" colspan="9">No trader ratings found.</td></tr>
              <?php else: ?>
                <?php foreach ($ratingByTraders as $traderRating): ?>
                  <?php
                    $summaryTraderId = admin_review_value($traderRating['TRADER_ID'] ?? '');
                    $summaryQuery = ['view' => 'reviews', 'trader_id' => $summaryTraderId];
                    if ($reviewSearch !== '') {
                        $summaryQuery['review_search'] = $reviewSearch;
                    }
                  ?>
                  <tr>
                    <td>
                      <strong><?= admin_h($traderRating['TRADER_NAME'] ?? 'Trader') ?></strong>
                      <?php if (!empty($traderRating['TRADER_EMAIL'])): ?>
                        <div class="meta"><?= admin_h($traderRating['TRADER_EMAIL']) ?></div>
                      <?php endif; ?>
                      <?php if ($summaryTraderId !== ''): ?>
                        <div class="meta">ID: <?= admin_h($summaryTraderId) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= (int)($traderRating['PRODUCT_COUNT'] ?? 0) ?></td>
                    <td><?= (int)($traderRating['SHOP_COUNT'] ?? 0) ?></td>
                    <td><?= (int)($traderRating['REVIEW_COUNT'] ?? 0) ?></td>
                    <td><span class="rating"><?= admin_h(admin_review_rating($traderRating['AVG_RATING'] ?? 0)) ?></span></td>
                    <td><?= admin_h(admin_review_rating($traderRating['LOWEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h(admin_review_rating($traderRating['HIGHEST_RATING'] ?? 0)) ?></td>
                    <td><?= admin_h($traderRating['LAST_REVIEW_DATE'] ?? '-') ?></td>
                    <td>
                      <?php if ($summaryTraderId !== ''): ?>
                        <a class="mini-link" href="reviews.php?<?= admin_h(http_build_query($summaryQuery)) ?>"><i class="fa-solid fa-list"></i> View reviews</a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pageMode !== 'reviews' && $summaryView === 'all'): ?>
      <section class="card">
        <div class="card-header">
          <span>Flagged Reviews</span>
          <span><?= count($pendingFlags) ?> pending</span>
        </div>
        <div class="card-body">
          <?php if (empty($pendingFlags)): ?>
            <div class="empty">No pending review flags.</div>
          <?php else: ?>
            <?php foreach ($pendingFlags as $flag): ?>
              <?php
                $reviewId = admin_review_value($flag['REVIEW_ID'] ?? '');
                $reviewText = admin_review_value($flag['REVIEW_TEXT'] ?? '', 'No written review.');
                $reason = admin_review_value($flag['REPORT_REASON'] ?? '', 'No reason provided.');
              ?>
              <article class="flag-card">
                <div class="flag-top">
                  <div>
                    <div class="review-title"><?= admin_h($flag['PRODUCT_NAME'] ?? 'Product') ?> - <?= admin_h($flag['SHOP_NAME'] ?? 'Shop') ?></div>
                    <div class="meta">Written by <?= admin_h($flag['CUSTOMER_NAME'] ?? 'Customer') ?><?= !empty($flag['CUSTOMER_EMAIL']) ? ' | ' . admin_h($flag['CUSTOMER_EMAIL']) : '' ?> on <?= admin_h($flag['DATE_POSTED'] ?? '-') ?></div>
                    <div class="meta">Reported by <?= admin_h($flag['TRADER_NAME'] ?? 'Trader') ?><?= !empty($flag['TRADER_EMAIL']) ? ' | ' . admin_h($flag['TRADER_EMAIL']) : '' ?> on <?= admin_h($flag['REPORTED_DATE'] ?? '-') ?></div>
                  </div>
                  <div class="rating"><?= admin_h(admin_review_rating($flag['RATING'] ?? 0)) ?></div>
                </div>
                <div class="review-text"><?= admin_h($reviewText) ?></div>
                <div class="reason"><strong>Trader reason:</strong> <?= admin_h($reason) ?></div>
                <div class="actions">
                  <form method="POST" onsubmit="return confirm('Approve this flag and hide the review?');">
                    <input type="hidden" name="action" value="approve_flag">
                    <input type="hidden" name="review_id" value="<?= admin_h($reviewId) ?>">
                    <button class="btn btn-danger" type="submit">Hide Review</button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Reject this flag and keep the review visible?');">
                    <input type="hidden" name="action" value="reject_flag">
                    <input type="hidden" name="review_id" value="<?= admin_h($reviewId) ?>">
                    <button class="btn btn-primary" type="submit">Keep Visible</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pageMode === 'reviews'): ?>
      <section class="card" id="review-results">
        <div class="card-header">
          <span><?= admin_h($reviewListTitle) ?></span>
          <span class="header-actions">
            <span><?= count($allReviews) ?> loaded<?= $hasActiveReviewFilter ? ' after search' : '' ?></span>
            <?php if ($pageMode === 'reviews'): ?>
              <a class="back-link" href="<?= admin_h(admin_review_url(['summary' => 'all', 'view' => null, 'customer_id' => null, 'product_id' => null, 'trader_id' => null], $tabKeep)) ?>"><i class="fa-solid fa-arrow-left"></i> Back to ratings</a>
            <?php endif; ?>
          </span>
        </div>
        <div class="card-body">
          <table class="data-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Trader</th>
                <th>Customer</th>
                <th>Rating</th>
                <th>Review</th>
                <th>Date</th>
                <th>Status</th>
                <th>Reported By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($allReviews)): ?>
                <tr><td class="empty-row" colspan="8">No reviews found.</td></tr>
              <?php else: ?>
                <?php foreach ($allReviews as $review): ?>
                  <?php
                    $isHidden = strtoupper((string)($review['APPROVAL_STATUS'] ?? 'YES')) === 'NO';
                    $isFlagged = trim((string)($review['REPORTED_BY_NAME'] ?? '')) !== '';
                    $reviewText = admin_review_value($review['REVIEW_TEXT'] ?? '', 'No written review.');
                  ?>
                  <tr>
                    <td>
                      <strong><?= admin_h($review['PRODUCT_NAME'] ?? 'Product') ?></strong>
                      <div class="meta"><?= admin_h($review['SHOP_NAME'] ?? 'Shop') ?></div>
                    </td>
                    <td>
                      <?= admin_h($review['TRADER_OWNER_NAME'] ?? 'Trader') ?>
                      <?php if (!empty($review['TRADER_OWNER_EMAIL'])): ?><div class="meta"><?= admin_h($review['TRADER_OWNER_EMAIL']) ?></div><?php endif; ?>
                    </td>
                    <td>
                      <?= admin_h($review['CUSTOMER_NAME'] ?? 'Customer') ?>
                      <div class="meta"><?= admin_h($review['CUSTOMER_EMAIL'] ?? '') ?></div>
                    </td>
                    <td><span class="rating"><?= admin_h(admin_review_rating($review['RATING'] ?? 0)) ?></span></td>
                    <td><?= admin_h($reviewText) ?></td>
                    <td><?= admin_h($review['DATE_POSTED'] ?? '-') ?></td>
                    <td>
                      <?php if ($isHidden): ?>
                        <span class="status-badge status-hidden">Hidden</span>
                      <?php elseif ($isFlagged): ?>
                        <span class="status-badge status-flagged">Flagged</span>
                      <?php else: ?>
                        <span class="status-badge status-visible">Visible</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= $isFlagged ? admin_h($review['REPORTED_BY_NAME']) : '-' ?>
                      <?php if (!empty($review['REPORTED_DATE'])): ?><div class="meta"><?= admin_h($review['REPORTED_DATE']) ?></div><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
<script src="../assets/admin/js/reviews.js?v=20260517"></script>
</body>
</html>

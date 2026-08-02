<?php


require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingOrderCount = get_pending_order_count($conn, $traderId);

$errors = [];
$notices = [];
$reviews = [];
$summary = [
    'total' => 0,
    'reported' => 0,
    'average' => 0,
    'five_star' => 0,
];

function first_existing_column($conn, $table, array $candidates) {
    foreach ($candidates as $col) {
        if (column_exists($conn, $table, $col)) {
            return strtoupper($col);
        }
    }
    return null;
}

function review_db_value($value, $fallback = '') {
    if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        $value = $loaded === false ? '' : $loaded;
    }

    if (is_array($value) || is_object($value)) {
        return $fallback;
    }

    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function review_stars($rating) {
    $rating = max(0, min(5, (int)round((float)$rating)));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}

$hasReviewsTable = $conn && table_exists($conn, 'REVIEW');
$hasProductTable = $conn && table_exists($conn, 'PRODUCT');
$hasShopTable = $conn && table_exists($conn, 'SHOP');
$hasUserTable = $conn && table_exists($conn, 'USER');

$reviewIdCol = null;
$productIdCol = null;
$traderIdCol = null;
$customerIdCol = null;
$ratingCol = null;
$textCol = null;
$dateCol = null;
$approvalCol = null;
$reportedByCol = null;
$reportReasonCol = null;
$reportedDateCol = null;
$canFilterByProduct = false;
$canFilterByTrader = false;

if (!$conn) {
    $errors[] = 'Database connection is not available. Please try again later.';
} elseif (!$hasReviewsTable) {
    $errors[] = 'REVIEW table was not found.';
} else {
    $reviewIdCol = first_existing_column($conn, 'REVIEW', ['REVIEW_ID', 'ID']);
    $productIdCol = first_existing_column($conn, 'REVIEW', ['PRODUCT_ID']);
    $traderIdCol = first_existing_column($conn, 'REVIEW', ['TRADER_ID']);
    $customerIdCol = first_existing_column($conn, 'REVIEW', ['CUSTOMER_ID', 'USER_ID']);
    $ratingCol = first_existing_column($conn, 'REVIEW', ['RATING', 'REVIEW_RATING', 'STAR_RATING', 'RATING_VALUE']);
    $textCol = first_existing_column($conn, 'REVIEW', ['REVIEW_TEXT', 'REVIEW_COMMENT', 'COMMENT_TEXT', 'COMMENTS', 'REVIEW', 'DESCRIPTION']);
    $dateCol = first_existing_column($conn, 'REVIEW', ['REVIEW_DATE', 'DATE_CREATED', 'CREATED_AT', 'DATE_POSTED', 'POSTED_DATE']);
    $approvalCol = first_existing_column($conn, 'REVIEW', ['APPROVAL_STATUS', 'APPROVAL_STATU', 'STATUS']);
    $reportedByCol = first_existing_column($conn, 'REVIEW', ['REPORTED_BY', 'REPORT_BY']);
    $reportReasonCol = first_existing_column($conn, 'REVIEW', ['REPORT_REASON', 'REASON']);
    $reportedDateCol = first_existing_column($conn, 'REVIEW', ['REPORTED_DATE', 'REPORT_DATE']);

    $canFilterByProduct = $productIdCol && $hasProductTable && $hasShopTable && column_exists($conn, 'PRODUCT', 'SHOP_ID') && column_exists($conn, 'SHOP', 'TRADER_ID');
    $canFilterByTrader = $traderIdCol !== null;

    if (!$canFilterByProduct && !$canFilterByTrader) {
        $errors[] = 'Reviews cannot be safely filtered for this trader. Add PRODUCT_ID to REVIEW or TRADER_ID to REVIEW.';
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_review') {
            $reviewId = trim((string)($_POST['review_id'] ?? ''));
            $productId = trim((string)($_POST['product_id'] ?? ''));
            $customerId = trim((string)($_POST['customer_id'] ?? ''));
            $reason = trim((string)($_POST['report_reason'] ?? 'Reported by trader'));

            if (!$reportedByCol && !$reportReasonCol && !$reportedDateCol) {
                $errors[] = 'Cannot report review because the report columns were not found in REVIEW table.';
            } elseif (!$reviewIdCol && ($productId === '' || $customerId === '')) {
                $errors[] = 'Cannot report review because review identifier was missing.';
            } else {
                try {
                    $setParts = [];
                    $binds = [':trader_id' => $traderId];

                    if ($reportedByCol) {
                        $setParts[] = "r.$reportedByCol = :reported_by";
                        $binds[':reported_by'] = $traderId;
                    }
                    if ($reportReasonCol) {
                        $setParts[] = "r.$reportReasonCol = :report_reason";
                        $binds[':report_reason'] = $reason !== '' ? $reason : 'Reported by trader';
                    }
                    if ($reportedDateCol) {
                        $setParts[] = "r.$reportedDateCol = SYSDATE";
                    }

                    $whereParts = [];
                    if ($reviewIdCol && $reviewId !== '') {
                        $whereParts[] = "r.$reviewIdCol = :review_id";
                        $binds[':review_id'] = $reviewId;
                    } else {
                        $whereParts[] = "r.$productIdCol = :product_id";
                        $whereParts[] = "r.$customerIdCol = :customer_id";
                        $binds[':product_id'] = $productId;
                        $binds[':customer_id'] = $customerId;
                    }

                    if ($canFilterByProduct) {
                        $whereParts[] = "EXISTS (
                            SELECT 1
                            FROM PRODUCT p
                            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
                            WHERE p.PRODUCT_ID = r.$productIdCol
                              AND s.TRADER_ID = :trader_id
                        )";
                    } else {
                        $whereParts[] = "r.$traderIdCol = :trader_id";
                    }

                    if (empty($setParts)) {
                        throw new Exception('No report fields are available to update.');
                    }

                    $sql = 'UPDATE REVIEW r SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $whereParts);
                    db_bind_and_execute($conn, $sql, $binds);
                    header('Location: reviews.php');
                    exit;
                } catch (Throwable $e) {
                    $errors[] = 'Could not report review: ' . shoplocalfy_public_exception_message($e, 'Could not report review.');
                }
            }
        }

        try {
            $select = [];
            $select[] = $reviewIdCol ? "r.$reviewIdCol AS REVIEW_ID" : "ROWNUM AS REVIEW_ID";
            $select[] = $productIdCol ? "r.$productIdCol AS PRODUCT_ID" : "'' AS PRODUCT_ID";
            $select[] = $customerIdCol ? "r.$customerIdCol AS CUSTOMER_ID" : "'' AS CUSTOMER_ID";
            $select[] = $ratingCol ? "NVL(r.$ratingCol, 0) AS RATING" : "0 AS RATING";
            $select[] = $textCol ? "r.$textCol AS REVIEW_TEXT" : "TO_CLOB('') AS REVIEW_TEXT";
            $select[] = $approvalCol ? "NVL(TO_CHAR(r.$approvalCol), 'YES') AS APPROVAL_STATUS" : "'YES' AS APPROVAL_STATUS";
            $select[] = $reportedByCol ? "NVL(TO_CHAR(r.$reportedByCol), '') AS REPORTED_BY" : "'' AS REPORTED_BY";
            $select[] = $reportReasonCol ? "r.$reportReasonCol AS REPORT_REASON" : "TO_CLOB('') AS REPORT_REASON";
            $select[] = $reportedDateCol ? "TO_CHAR(r.$reportedDateCol, 'DD Mon YYYY') AS REPORTED_DATE_DISPLAY" : "'' AS REPORTED_DATE_DISPLAY";

            if ($dateCol) {
                $select[] = "TO_CHAR(r.$dateCol, 'YYYY-MM-DD') AS REVIEW_DATE_RAW";
                $select[] = "TO_CHAR(r.$dateCol, 'DD Mon YYYY') AS REVIEW_DATE_DISPLAY";
            } else {
                $select[] = "'' AS REVIEW_DATE_RAW";
                $select[] = "'Unknown date' AS REVIEW_DATE_DISPLAY";
            }

            if ($canFilterByProduct && column_exists($conn, 'PRODUCT', 'PRODUCT_NAME')) {
                $select[] = "p.PRODUCT_NAME AS PRODUCT_NAME";
            } elseif ($productIdCol) {
                $select[] = "r.$productIdCol AS PRODUCT_NAME";
            } else {
                $select[] = "'Product' AS PRODUCT_NAME";
            }

            if ($hasUserTable && $customerIdCol) {
                $select[] = "NVL(TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME), 'Customer') AS CUSTOMER_NAME";
                $select[] = "NVL(u.EMAIL_ADDRESS, '') AS CUSTOMER_EMAIL";
            } else {
                $select[] = "'Customer' AS CUSTOMER_NAME";
                $select[] = "'' AS CUSTOMER_EMAIL";
            }

            $sql = "SELECT " . implode(",\n                   ", $select) . "\nFROM REVIEW r\n";

            if ($canFilterByProduct) {
                $sql .= "INNER JOIN PRODUCT p ON p.PRODUCT_ID = r.$productIdCol\n";
                $sql .= "INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID\n";
            }

            if ($hasUserTable && $customerIdCol) {
                $sql .= "LEFT JOIN \"USER\" u ON u.USER_ID = r.$customerIdCol\n";
            }

            $where = [];
            if ($canFilterByProduct) {
                $where[] = "s.TRADER_ID = :trader_id";
            } else {
                $where[] = "r.$traderIdCol = :trader_id";
            }

            if ($approvalCol) {
                $where[] = "(r.$approvalCol IS NULL OR UPPER(TO_CHAR(r.$approvalCol)) IN ('YES', 'Y', 'APPROVED', 'ACTIVE'))";
            }

            $sql .= "WHERE " . implode("\n  AND ", $where) . "\n";

            if ($dateCol) {
                $sql .= "ORDER BY r.$dateCol DESC\n";
            } elseif ($reviewIdCol) {
                $sql .= "ORDER BY r.$reviewIdCol DESC\n";
            }
            $sql .= "FETCH FIRST 100 ROWS ONLY";

            $reviews = db_all($conn, $sql, [':trader_id' => $traderId]);

            $summary['total'] = count($reviews);
            $ratingTotal = 0;
            foreach ($reviews as $row) {
                $rating = (float)($row['RATING'] ?? 0);
                $ratingTotal += $rating;
                if ($rating >= 5) {
                    $summary['five_star']++;
                }

                if (review_db_value($row['REPORTED_BY'] ?? '') !== '') {
                    $summary['reported']++;
                }
            }
            $summary['average'] = $summary['total'] > 0 ? round($ratingTotal / $summary['total'], 1) : 0;
        } catch (Throwable $e) {
            $errors[] = 'Could not load reviews: ' . shoplocalfy_public_exception_message($e, 'Could not load reviews.');
            $reviews = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ShopLocalfy — Reviews</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/reviews.css?v=20260517">
</head>
<body>
  <?php $active = 'reviews'; include __DIR__ . '/sidebar.php'; ?>

  <main class="main">
    <?php render_topbar('Reviews', 'Manage customer reviews and feedback'); ?>

    <section class="body">

      <?php foreach ($errors as $error): ?>
        <div class="notice"><?php echo e($error); ?></div>
      <?php endforeach; ?>

      <div class="notif-header">
        <div class="notif-header-left">
          <h1>⭐ Reviews</h1>
          <p id="reviewSummary"><?php echo e($summary['total']); ?> reviews total · <?php echo e($summary['reported']); ?> reported</p>
        </div>

        <a class="users-tab" href="customer.php">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
          Customers
        </a>
      </div>

      <div class="review-stats">
        <div class="review-stat"><div class="review-stat-label">Total Reviews</div><div class="review-stat-value"><?php echo e($summary['total']); ?></div></div>
        <div class="review-stat"><div class="review-stat-label">Reported</div><div class="review-stat-value"><?php echo e($summary['reported']); ?></div></div>
        <div class="review-stat"><div class="review-stat-label">Average Rating</div><div class="review-stat-value"><?php echo e($summary['average']); ?>/5</div></div>
        <div class="review-stat"><div class="review-stat-label">5-Star Reviews</div><div class="review-stat-value"><?php echo e($summary['five_star']); ?></div></div>
      </div>

      <div class="notif-card">
        <div class="notif-card-head">
          <div class="notif-card-title">
            Reviews List
            <span class="notif-count-badge" title="Reported reviews waiting for admin decision"><?php echo e($summary['reported']); ?></span>
          </div>
        </div>

        <div class="notif-list" id="notifList">
          <?php if (empty($reviews)): ?>
            <div class="notif-empty" id="notifEmpty" style="display:block">
              <div class="emo">⭐</div>
              <p>No reviews yet.</p>
            </div>
          <?php else: ?>
            <?php foreach ($reviews as $review): ?>
              <?php
                $rating = (float)($review['RATING'] ?? 0);
                $customerName = review_db_value($review['CUSTOMER_NAME'] ?? '', 'Customer');
                $productName = review_db_value($review['PRODUCT_NAME'] ?? '', 'Product');
                $customerEmail = review_db_value($review['CUSTOMER_EMAIL'] ?? '');
                $reviewId = review_db_value($review['REVIEW_ID'] ?? '');
                $productId = review_db_value($review['PRODUCT_ID'] ?? '');
                $customerId = review_db_value($review['CUSTOMER_ID'] ?? '');
                $reportedBy = review_db_value($review['REPORTED_BY'] ?? '');
                $reportReason = review_db_value($review['REPORT_REASON'] ?? '');
                $reportedDate = review_db_value($review['REPORTED_DATE_DISPLAY'] ?? '');
                $isFlaggedPending = $reportedBy !== '';
                $title = $customerName . ' reviewed ' . $productName;
                $desc = review_db_value($review['REVIEW_TEXT'] ?? '', 'No written comment provided.');
                $searchText = strtolower($title . ' ' . $desc . ' ' . $customerEmail . ' ' . $reportReason);
              ?>
              <div class="notif-item <?php echo $isFlaggedPending ? 'flagged' : ''; ?>" data-search="<?php echo e($searchText); ?>">
                <div class="notif-icon review">⭐</div>
                <div class="notif-content">
                  <p class="notif-title"><?php echo e($title); ?></p>
                  <p class="notif-desc"><?php echo e($desc); ?></p>
                  <div class="notif-stars"><?php echo e(review_stars($rating)); ?> <span style="color:var(--muted);font-weight:600"> <?php echo e($rating); ?>/5</span></div>
                  <span class="notif-time"><?php echo e(review_db_value($review['REVIEW_DATE_DISPLAY'] ?? '', 'Unknown date')); ?><?php echo $customerEmail !== '' ? ' · ' . e($customerEmail) : ''; ?></span>
                  <?php if ($isFlaggedPending): ?>
                    <div class="review-actions">
                      <span class="flag-status">Reported for admin review<?php echo $reportedDate !== '' ? ' · ' . e($reportedDate) : ''; ?></span>
                    </div>
                  <?php else: ?>
                    <form class="review-actions" method="POST" onsubmit="return confirm('Flag this review for admin review? It will remain visible until an admin approves the flag.');">
                      <input type="hidden" name="action" value="report_review">
                      <input type="hidden" name="review_id" value="<?php echo e($reviewId); ?>">
                      <input type="hidden" name="product_id" value="<?php echo e($productId); ?>">
                      <input type="hidden" name="customer_id" value="<?php echo e($customerId); ?>">
                      <input type="hidden" name="report_reason" value="Reported by trader as fake">
                      <button class="btn-report" type="submit">Report as fake</button>
                    </form>
                  <?php endif; ?>
                </div>
                <div class="notif-dot"></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="notif-footer">
          <span class="review-note">Reported reviews stay visible until an admin approves the report.</span>

          <a class="btn-refresh" href="reviews.php">
            ↻ Refresh Reviews
          </a>
        </div>
      </div>
    </section>
  </main>


<script src="../assets/trader/js/reviews.js?v=20260517"></script>
</body>
</html>

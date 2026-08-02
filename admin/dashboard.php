<?php

require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();

date_default_timezone_set('Asia/Kathmandu');

$conn = admin_db_connection();
$dashboardError = '';

if (!function_exists('admin_h')) {
    function admin_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function admin_short_text($value, $length = 48) {
    $value = (string)$value;

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $length, '...');
    }

    return strlen($value) > $length ? substr($value, 0, $length - 3) . '...' : $value;
}

function admin_cols($conn, $table) {
    if (!$conn || !table_exists($conn, $table)) {
        return [];
    }

    $rows = db_all($conn, "
        SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = :table_name
        ORDER BY COLUMN_ID
    ", [
        ':table_name' => strtoupper($table)
    ]);

    $cols = [];

    foreach ($rows as $row) {
        $cols[strtoupper($row['COLUMN_NAME'])] = $row;
    }

    return $cols;
}

function admin_pick_col($cols, $names) {
    foreach ($names as $name) {
        $key = strtoupper($name);

        if (isset($cols[$key])) {
            return $key;
        }
    }

    return null;
}

function admin_count_rows($conn, $table, $where = '', $binds = []) {
    if (!$conn || !table_exists($conn, $table)) {
        return 0;
    }

    $from = strtoupper($table) === 'USER' ? '"USER"' : strtoupper($table);
    $row = db_one($conn, "SELECT COUNT(*) AS TOTAL FROM $from $where", $binds);

    return (int)($row['TOTAL'] ?? 0);
}

function admin_name_expr($cols, $alias = '') {
    $p = $alias ? $alias . '.' : '';

    if (isset($cols['FULL_NAME'])) {
        return $p . 'FULL_NAME';
    }

    if (isset($cols['NAME'])) {
        return $p . 'NAME';
    }

    if (isset($cols['USERNAME'])) {
        return $p . 'USERNAME';
    }

    if (isset($cols['FIRST_NAME']) && isset($cols['LAST_NAME'])) {
        return "TRIM($p" . "FIRST_NAME || ' ' || $p" . "LAST_NAME)";
    }

    if (isset($cols['FIRST_NAME'])) {
        return $p . 'FIRST_NAME';
    }

    if (isset($cols['EMAIL'])) {
        return $p . 'EMAIL';
    }

    if (isset($cols['EMAIL_ADDRESS'])) {
        return $p . 'EMAIL_ADDRESS';
    }

    return "'Unknown'";
}

function admin_email_col($cols) {
    return admin_pick_col($cols, [
        'EMAIL_ADDRESS',
        'EMAIL',
        'USER_EMAIL',
        'MAIL'
    ]);
}

function admin_user_display($conn, $userId) {
    if ($userId === null || $userId === '') {
        return [
            'name' => 'Unknown',
            'email' => '—'
        ];
    }

    $cols = admin_cols($conn, 'USER');
    $idCol = admin_pick_col($cols, ['USER_ID', 'ID']);

    if (!$idCol) {
        return [
            'name' => (string)$userId,
            'email' => '—'
        ];
    }

    $nameExpr = admin_name_expr($cols);
    $emailCol = admin_email_col($cols);
    $emailSelect = $emailCol ? "$emailCol AS EMAIL" : "NULL AS EMAIL";

    $row = db_one($conn, "
        SELECT $nameExpr AS NAME, $emailSelect
        FROM \"USER\"
        WHERE $idCol = :id
    ", [
        ':id' => $userId
    ]);

    return [
        'name' => $row['NAME'] ?? (string)$userId,
        'email' => $row['EMAIL'] ?? '—'
    ];
}

function admin_shop_name_for_trader($conn, $traderId) {
    $cols = admin_cols($conn, 'SHOP');

    if (!$cols) {
        return '—';
    }

    $nameCol = admin_pick_col($cols, [
        'SHOP_NAME',
        'NAME',
        'STORE_NAME'
    ]);

    $ownerCol = admin_pick_col($cols, [
        'TRADER_ID',
        'USER_ID',
        'OWNER_ID'
    ]);

    if (!$nameCol || !$ownerCol) {
        return '—';
    }

    $row = db_one($conn, "
        SELECT $nameCol AS SHOP_NAME
        FROM SHOP
        WHERE $ownerCol = :id
        FETCH FIRST 1 ROWS ONLY
    ", [
        ':id' => $traderId
    ]);

    return $row['SHOP_NAME'] ?? '—';
}

function admin_pending_traders($conn, $limit = 5) {
    $cols = admin_cols($conn, 'TRADER');

    if (!$cols) {
        return [];
    }

    $idCol = admin_pick_col($cols, [
        'USER_ID',
        'TRADER_ID',
        'ID'
    ]);

    if (!$idCol) {
        return [];
    }

    $statusCol = admin_pick_col($cols, [
        'VERIFIED_STATUS',
        'STATUS',
        'APPROVAL_STATUS'
    ]);

    $dateCol = admin_pick_col($cols, [
        'CREATED_AT',
        'CREATED_DATE',
        'REGISTERED_AT',
        'REQUEST_DATE',
        'SUBMITTED_AT'
    ]);

    $statusSelect = $statusCol ? "$statusCol AS STATUS" : "'PENDING' AS STATUS";
    $dateSelect = $dateCol ? "TO_CHAR($dateCol, 'YYYY-MM-DD') AS SUBMITTED" : "NULL AS SUBMITTED";
    $where = $statusCol ? "WHERE UPPER($statusCol) IN ('PENDING', 'UNVERIFIED')" : '';
    $order = $dateCol ? "ORDER BY $dateCol DESC" : "ORDER BY $idCol DESC";

    $rows = db_all($conn, "
        SELECT $idCol AS TRADER_ID, $statusSelect, $dateSelect
        FROM TRADER
        $where
        $order
        FETCH FIRST $limit ROWS ONLY
    ");

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

function admin_orders_today($conn) {
    $cols = admin_cols($conn, 'ORDERS');

    if (!$cols) {
        return 0;
    }

    $dateCol = admin_pick_col($cols, [
        'ORDER_DATE',
        'CREATED_AT',
        'CREATED_DATE',
        'ORDERED_AT',
        'PLACED_AT'
    ]);

    if (!$dateCol) {
        return admin_count_rows($conn, 'ORDERS');
    }

    $row = db_one($conn, "
        SELECT COUNT(*) AS TOTAL
        FROM ORDERS
        WHERE TRUNC($dateCol) = TRUNC(SYSDATE)
    ");

    return (int)($row['TOTAL'] ?? 0);
}

function admin_active_traders($conn) {
    $cols = admin_cols($conn, 'TRADER');

    if (!$cols) {
        return 0;
    }

    $statusCol = admin_pick_col($cols, [
        'VERIFIED_STATUS',
        'STATUS',
        'APPROVAL_STATUS'
    ]);

    if (!$statusCol) {
        return admin_count_rows($conn, 'TRADER');
    }

    return admin_count_rows($conn, 'TRADER', "
        WHERE UPPER($statusCol) IN ('VERIFIED', 'APPROVED', 'ACTIVE')
    ");
}

function admin_review_where($cols) {
    if (isset($cols['REPORTED_BY'])) {
        if (isset($cols['APPROVAL_STATUS'])) {
            return "WHERE REPORTED_BY IS NOT NULL AND UPPER(NVL(APPROVAL_STATUS, 'YES')) = 'YES'";
        }

        return "WHERE REPORTED_BY IS NOT NULL";
    }

    $flagCol = admin_pick_col($cols, [
        'IS_FLAGGED',
        'FLAGGED',
        'REPORT_STATUS'
    ]);

    if ($flagCol) {
        return "WHERE UPPER(TO_CHAR($flagCol)) IN ('Y', 'YES', '1', 'TRUE', 'FLAGGED', 'PENDING', 'REPORTED')";
    }

    $statusCol = admin_pick_col($cols, [
        'FLAG_STATUS',
        'REVIEW_STATUS',
        'STATUS'
    ]);

    if ($statusCol) {
        return "WHERE UPPER($statusCol) IN ('FLAGGED', 'PENDING', 'REPORTED')";
    }

    return '';
}

function admin_pending_reviews($conn) {
    $cols = admin_cols($conn, 'REVIEW');

    if (!$cols) {
        return 0;
    }

    $where = admin_review_where($cols);

    if ($where === '') {
        return 0;
    }

    return admin_count_rows($conn, 'REVIEW', $where);
}

function admin_recent_flagged_reviews($conn) {
    $cols = admin_cols($conn, 'REVIEW');

    if (!$cols) {
        return [];
    }

    $idCol = admin_pick_col($cols, [
        'REVIEW_ID',
        'ID'
    ]);

    $textCol = admin_pick_col($cols, [
        'REVIEW_TEXT',
        'REVIEW',
        'COMMENT',
        'COMMENTS',
        'DESCRIPTION'
    ]);

    $userCol = admin_pick_col($cols, [
        'CUSTOMER_ID',
        'USER_ID'
    ]);

    $reasonCol = admin_pick_col($cols, [
        'REPORT_REASON',
        'FLAG_REASON',
        'REASON'
    ]);

    $dateCol = admin_pick_col($cols, [
        'REPORTED_DATE',
        'DATE_POSTED',
        'UPDATED_AT',
        'CREATED_AT',
        'REVIEW_DATE',
        'DATE_CREATED'
    ]);

    $reporterCol = admin_pick_col($cols, [
        'REPORTED_BY'
    ]);

    $where = admin_review_where($cols);

    if (!$idCol || $where === '') {
        return [];
    }

    $select = [];
    $select[] = "$idCol AS REVIEW_ID";
    $select[] = $textCol ? "$textCol AS REVIEW_TEXT" : "'Flagged review' AS REVIEW_TEXT";
    $select[] = $userCol ? "$userCol AS USER_ID" : "NULL AS USER_ID";
    $select[] = $reasonCol ? "$reasonCol AS REASON" : "'Reported by trader' AS REASON";
    $select[] = $reporterCol ? "$reporterCol AS REPORTED_BY" : "NULL AS REPORTED_BY";

    $order = $dateCol ? "ORDER BY $dateCol DESC" : "ORDER BY $idCol DESC";

    $rows = db_all($conn, "
        SELECT " . implode(', ', $select) . "
        FROM REVIEW
        $where
        $order
        FETCH FIRST 5 ROWS ONLY
    ");

    foreach ($rows as &$row) {
        $user = admin_user_display($conn, $row['USER_ID'] ?? '');
        $row['USER_NAME'] = $user['name'];

        if (!empty($row['REPORTED_BY'])) {
            $reporter = admin_user_display($conn, $row['REPORTED_BY']);
            $row['REPORTED_BY_NAME'] = $reporter['name'];
        } else {
            $row['REPORTED_BY_NAME'] = 'Trader';
        }
    }

    unset($row);

    return $rows;
}

function admin_recent_users($conn) {
    $cols = admin_cols($conn, 'USER');

    if (!$cols) {
        return [];
    }

    $idCol = admin_pick_col($cols, [
        'USER_ID',
        'ID'
    ]);

    $emailCol = admin_email_col($cols);

    $dateCol = admin_pick_col($cols, [
        'DATE_CREATED',
        'CREATED_AT',
        'CREATED_DATE',
        'REGISTERED_AT',
        'JOINED_AT'
    ]);

    if (!$idCol) {
        return [];
    }

    $nameExpr = admin_name_expr($cols);
    $emailSelect = $emailCol ? "$emailCol AS EMAIL" : "NULL AS EMAIL";
    $dateSelect = $dateCol ? "TO_CHAR($dateCol, 'YYYY-MM-DD') AS JOINED" : "NULL AS JOINED";
    $order = $dateCol ? "ORDER BY $dateCol DESC" : "ORDER BY $idCol DESC";

    return db_all($conn, "
        SELECT $idCol AS USER_ID, $nameExpr AS NAME, $emailSelect, $dateSelect
        FROM \"USER\"
        $order
        FETCH FIRST 5 ROWS ONLY
    ");
}

function admin_sales_by_category($conn) {
    $orderCols = admin_cols($conn, 'ORDERS');
    $itemCols = admin_cols($conn, 'ORDER_ITEM');
    $productCols = admin_cols($conn, 'PRODUCT');
    $catCols = admin_cols($conn, 'CATEGORY');

    if (!$orderCols || !$itemCols || !$productCols || !$catCols) {
        return [];
    }

    $orderId = admin_pick_col($orderCols, [
        'ORDER_ID',
        'ID'
    ]);

    $itemOrderId = admin_pick_col($itemCols, [
        'ORDER_ID'
    ]);

    $itemProductId = admin_pick_col($itemCols, [
        'PRODUCT_ID'
    ]);

    $productId = admin_pick_col($productCols, [
        'PRODUCT_ID',
        'ID'
    ]);

    $productCategoryId = admin_pick_col($productCols, [
        'CATEGORY_ID'
    ]);

    $categoryId = admin_pick_col($catCols, [
        'CATEGORY_ID',
        'ID'
    ]);

    $categoryName = admin_pick_col($catCols, [
        'CATEGORY_NAME',
        'NAME'
    ]);

    if (
        !$orderId ||
        !$itemOrderId ||
        !$itemProductId ||
        !$productId ||
        !$productCategoryId ||
        !$categoryId ||
        !$categoryName
    ) {
        return [];
    }

    $qtyCol = admin_pick_col($itemCols, [
        'QUANTITY',
        'QTY',
        'ORDER_QUANTITY'
    ]);

    $itemPriceCol = admin_pick_col($itemCols, [
        'LOCKED_PRICE',
        'PRICE',
        'UNIT_PRICE',
        'ITEM_PRICE',
        'PRODUCT_PRICE'
    ]);

    $productPriceCol = admin_pick_col($productCols, [
        'ITEM_PRICE',
        'PRICE',
        'PRODUCT_PRICE',
        'UNIT_PRICE'
    ]);

    $dateCol = admin_pick_col($orderCols, [
        'ORDER_DATE',
        'CREATED_AT',
        'CREATED_DATE',
        'ORDERED_AT',
        'PLACED_AT'
    ]);

    $qtyExpr = $qtyCol ? "NVL(oi.$qtyCol, 1)" : '1';

    if ($itemPriceCol) {
        $valueExpr = "NVL(oi.$itemPriceCol, 0) * $qtyExpr";
    } elseif ($productPriceCol) {
        $valueExpr = "NVL(p.$productPriceCol, 0) * $qtyExpr";
    } else {
        $valueExpr = $qtyExpr;
    }

    $where = $dateCol ? "WHERE TRUNC(o.$dateCol) >= TRUNC(SYSDATE, 'IW')" : '';

    $rows = db_all($conn, "
        SELECT
            c.$categoryName AS CATEGORY_NAME,
            SUM($valueExpr) AS TOTAL_VALUE
        FROM ORDER_ITEM oi
        JOIN ORDERS o ON o.$orderId = oi.$itemOrderId
        JOIN PRODUCT p ON p.$productId = oi.$itemProductId
        JOIN CATEGORY c ON c.$categoryId = p.$productCategoryId
        $where
        GROUP BY c.$categoryName
        ORDER BY TOTAL_VALUE DESC
        FETCH FIRST 5 ROWS ONLY
    ");

    $total = 0;

    foreach ($rows as $row) {
        $total += (float)($row['TOTAL_VALUE'] ?? 0);
    }

    foreach ($rows as &$row) {
        $row['PERCENTAGE'] = $total > 0
            ? round(((float)$row['TOTAL_VALUE'] / $total) * 100)
            : 0;
    }

    unset($row);

    return $rows;
}

function admin_customer_growth($conn, $days = 30) {
    $days = max(7, min(180, (int)$days));
    $items = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i days");
        $key = date('Y-m-d', $ts);

        $items[$key] = [
            'date' => $key,
            'label' => date('M j, Y', $ts),
            'axis_label' => date('M j', $ts),
            'short_label' => date('j M', $ts),
            'new_customers' => 0,
            'total_customers' => 0
        ];
    }

    $cols = admin_cols($conn, 'USER');

    if (!$cols) {
        return array_values($items);
    }

    $dateCol = admin_pick_col($cols, [
        'DATE_CREATED',
        'CREATED_AT',
        'CREATED_DATE',
        'REGISTERED_AT',
        'JOINED_AT'
    ]);

    $roleCol = admin_pick_col($cols, [
        'USER_ROLE',
        'ROLE'
    ]);

    if (!$dateCol) {
        return array_values($items);
    }

    $roleWhere = $roleCol ? "AND UPPER(TRIM($roleCol)) = 'CUSTOMER'" : '';

    try {
        $baseRow = db_one($conn, "
            SELECT COUNT(*) AS TOTAL
            FROM \"USER\"
            WHERE TRUNC($dateCol) < TRUNC(SYSDATE) - :days_back
            $roleWhere
        ", [
            ':days_back' => $days - 1
        ]);

        $runningTotal = (int)($baseRow['TOTAL'] ?? 0);

        $rows = db_all($conn, "
            SELECT
                TO_CHAR(TRUNC($dateCol), 'YYYY-MM-DD') AS GROWTH_DAY,
                COUNT(*) AS NEW_CUSTOMERS
            FROM \"USER\"
            WHERE TRUNC($dateCol) >= TRUNC(SYSDATE) - :days_back
            $roleWhere
            GROUP BY TRUNC($dateCol)
            ORDER BY TRUNC($dateCol)
        ", [
            ':days_back' => $days - 1
        ]);

        foreach ($rows as $row) {
            $key = (string)($row['GROWTH_DAY'] ?? '');

            if (isset($items[$key])) {
                $items[$key]['new_customers'] = (int)($row['NEW_CUSTOMERS'] ?? 0);
            }
        }

        foreach ($items as &$item) {
            $runningTotal += (int)$item['new_customers'];
            $item['total_customers'] = $runningTotal;
        }

        unset($item);
    } catch (Throwable $e) {
    }

    return array_values($items);
}

$growthRanges = [
    'week' => [
        'label' => 'Week',
        'days' => 7,
        'sub' => 'Last 7 days'
    ],
    'month' => [
        'label' => 'Month',
        'days' => 30,
        'sub' => 'Last 30 days'
    ],
    '3months' => [
        'label' => '3 Months',
        'days' => 90,
        'sub' => 'Last 90 days'
    ],
    '6months' => [
        'label' => '6 Months',
        'days' => 180,
        'sub' => 'Last 180 days'
    ]
];

$selectedGrowthRange = strtolower(trim($_GET['growth_range'] ?? 'month'));

if (!isset($growthRanges[$selectedGrowthRange])) {
    $selectedGrowthRange = 'month';
}

$selectedGrowthDays = (int)$growthRanges[$selectedGrowthRange]['days'];
$selectedGrowthLabel = $growthRanges[$selectedGrowthRange]['sub'];

$stats = [
    'users' => 0,
    'traders' => 0,
    'ordersToday' => 0,
    'pendingReviews' => 0
];

$pendingTraders = [];
$categorySales = [];
$flaggedReviews = [];
$recentUsers = [];
$customerGrowth = [];

try {
    if (!$conn) {
        throw new RuntimeException('Oracle database connection was not found.');
    }

    $stats['users'] = admin_count_rows($conn, 'USER');
    $stats['traders'] = admin_active_traders($conn);
    $stats['ordersToday'] = admin_orders_today($conn);
    $stats['pendingReviews'] = admin_pending_reviews($conn);

    $pendingTraders = admin_pending_traders($conn, 5);
    $categorySales = admin_sales_by_category($conn);
    $flaggedReviews = admin_recent_flagged_reviews($conn);
    $recentUsers = admin_recent_users($conn);
    $customerGrowth = admin_customer_growth($conn, $selectedGrowthDays);
} catch (Throwable $e) {
    $dashboardError = shoplocalfy_public_exception_message($e, 'Could not load dashboard.');
}

$newCustomersInRange = 0;
$growthMax = 0;
$growthMin = null;

foreach ($customerGrowth as $point) {
    $total = (int)($point['total_customers'] ?? 0);

    $newCustomersInRange += (int)($point['new_customers'] ?? 0);
    $growthMax = max($growthMax, $total);
    $growthMin = $growthMin === null ? $total : min($growthMin, $total);
}

$growthMin = $growthMin ?? 0;
$growthRange = max(1, $growthMax - $growthMin);

$chartW = 900;
$chartH = 260;
$padX = 42;
$padY = 20;
$bottomPad = 46;
$innerW = $chartW - ($padX * 2);
$innerH = $chartH - $padY - $bottomPad;

$linePoints = [];
$dotPoints = [];
$countGrowth = count($customerGrowth);

if ($countGrowth > 0) {
    foreach ($customerGrowth as $i => $point) {
        $x = $countGrowth === 1
            ? ($chartW / 2)
            : $padX + (($innerW / max(1, $countGrowth - 1)) * $i);

        $total = (int)($point['total_customers'] ?? 0);
        $normalised = ($total - $growthMin) / $growthRange;
        $y = $padY + ($innerH - ($normalised * $innerH));

        $linePoints[] = round($x, 2) . ',' . round($y, 2);

        $dotPoints[] = [
            'x' => round($x, 2),
            'y' => round($y, 2),
            'total' => $total,
            'new' => (int)($point['new_customers'] ?? 0),
            'label' => $point['label'] ?? '',
            'axis_label' => $point['axis_label'] ?? '',
            'short_label' => $point['short_label'] ?? ''
        ];
    }
}

$linePointsString = implode(' ', $linePoints);
$areaPointsString = $linePointsString
    ? ($padX . ',' . ($chartH - $bottomPad) . ' ' . $linePointsString . ' ' . ($chartW - $padX) . ',' . ($chartH - $bottomPad))
    : '';

$labelEvery = 1;

if ($selectedGrowthDays > 120) {
    $labelEvery = 30;
} elseif ($selectedGrowthDays > 60) {
    $labelEvery = 14;
} elseif ($selectedGrowthDays > 14) {
    $labelEvery = 5;
}

$todayLabel = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=13" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=13" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=13" type="image/png" sizes="512x512">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>ShopLocalfy — Admin Dashboard</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <link rel="stylesheet" href="../assets/admin/css/dashboard.css?v=20260517">
</head>

<body>
<div class="adm-layout admin-dashboard-soft-v6-growth-toggle">
  <?php
    $active = 'dashboard';
    include __DIR__ . '/sidebar.php';
  ?>

  <div class="adm-main">
    <?php include __DIR__ . '/topbar.php'; ?>

    <main class="adm-page">
      <section class="adm-hero">
        <div>
          <h1 class="adm-title">Dashboard</h1>
          <p class="adm-subtitle">A softer overview of users, traders, orders and review activity.</p>
        </div>

        <div class="adm-date-pill">
          <?php echo admin_h($todayLabel); ?>
        </div>
      </section>

      <?php if ($dashboardError): ?>
        <div class="adm-alert">
          <?php echo admin_h($dashboardError); ?>
        </div>
      <?php endif; ?>

      <section class="adm-stats" aria-label="Admin dashboard statistics">
        <article class="adm-stat">
          <div class="adm-stat-icon">
            <i class="fa-solid fa-users"></i>
          </div>
          <div>
            <span class="adm-stat-label">Total Users</span>
            <span class="adm-stat-value"><?php echo number_format($stats['users']); ?></span>
          </div>
        </article>

        <article class="adm-stat">
          <div class="adm-stat-icon">
            <i class="fa-solid fa-store"></i>
          </div>
          <div>
            <span class="adm-stat-label">Active Traders</span>
            <span class="adm-stat-value"><?php echo number_format($stats['traders']); ?></span>
          </div>
        </article>

        <article class="adm-stat">
          <div class="adm-stat-icon">
            <i class="fa-solid fa-bag-shopping"></i>
          </div>
          <div>
            <span class="adm-stat-label">Orders Today</span>
            <span class="adm-stat-value"><?php echo number_format($stats['ordersToday']); ?></span>
          </div>
        </article>

        <article class="adm-stat">
          <div class="adm-stat-icon orange">
            <i class="fa-solid fa-star-half-stroke"></i>
          </div>
          <div>
            <span class="adm-stat-label">Reported Reviews</span>
            <span class="adm-stat-value"><?php echo number_format($stats['pendingReviews']); ?></span>
          </div>
        </article>
      </section>

      <nav class="adm-actions" aria-label="Quick admin actions">
        <a class="adm-action" href="pending-requests.php">
          <span class="adm-action-icon"><i class="fa-solid fa-clock"></i></span>
          <span>
            <span class="adm-action-title">Approve Traders</span>
            <span class="adm-action-sub">Review pending shops</span>
          </span>
        </a>

        <a class="adm-action" href="user-management.php">
          <span class="adm-action-icon"><i class="fa-solid fa-users-gear"></i></span>
          <span>
            <span class="adm-action-title">User Management</span>
            <span class="adm-action-sub">Activate or suspend users</span>
          </span>
        </a>

        <a class="adm-action" href="order-management.php">
          <span class="adm-action-icon"><i class="fa-solid fa-box"></i></span>
          <span>
            <span class="adm-action-title">Orders</span>
            <span class="adm-action-sub">View platform orders</span>
          </span>
        </a>

        <a class="adm-action" href="reviews.php">
          <span class="adm-action-icon"><i class="fa-solid fa-star"></i></span>
          <span>
            <span class="adm-action-title">Review Reports</span>
            <span class="adm-action-sub">Handle flagged reviews</span>
          </span>
        </a>
      </nav>

      <section class="adm-grid">
        <article class="adm-panel">
          <div class="adm-panel-head">
            <div>
              <h2 class="adm-panel-title">Customer Growth Over Time</h2>
              <span class="adm-panel-sub"><?php echo admin_h($selectedGrowthLabel); ?> · cumulative customer accounts</span>
            </div>

            <div class="adm-chart-controls" aria-label="Customer growth range selector">
              <?php foreach ($growthRanges as $rangeKey => $rangeInfo): ?>
                <a
                  class="adm-range-btn <?php echo $selectedGrowthRange === $rangeKey ? 'active' : ''; ?>"
                  href="dashboard.php?growth_range=<?php echo admin_h($rangeKey); ?>"
                >
                  <?php echo admin_h($rangeInfo['label']); ?>
                </a>
              <?php endforeach; ?>
              <span class="adm-chip">+<?php echo number_format($newCustomersInRange); ?> new</span>
            </div>
          </div>

          <div class="adm-panel-body">
            <div class="adm-line-chart-wrap" aria-label="Customer growth line chart">
              <?php if (!$linePointsString): ?>
                <p class="adm-empty">No customer growth data available.</p>
              <?php else: ?>
                <div id="admGrowthTooltip" class="adm-chart-tooltip" role="status" aria-live="polite"></div>

                <svg
                  class="adm-line-chart"
                  viewBox="0 0 <?php echo $chartW; ?> <?php echo $chartH; ?>"
                  preserveAspectRatio="none"
                  role="img"
                  aria-label="Customer growth line chart"
                >
                  <line class="adm-axis-line" x1="<?php echo $padX; ?>" y1="<?php echo $chartH - $bottomPad; ?>" x2="<?php echo $chartW - $padX; ?>" y2="<?php echo $chartH - $bottomPad; ?>"></line>
                  <line class="adm-grid-line" x1="<?php echo $padX; ?>" y1="58" x2="<?php echo $chartW - $padX; ?>" y2="58"></line>
                  <line class="adm-grid-line" x1="<?php echo $padX; ?>" y1="116" x2="<?php echo $chartW - $padX; ?>" y2="116"></line>
                  <line class="adm-grid-line" x1="<?php echo $padX; ?>" y1="174" x2="<?php echo $chartW - $padX; ?>" y2="174"></line>

                  <polygon class="adm-area" points="<?php echo admin_h($areaPointsString); ?>"></polygon>
                  <polyline class="adm-line" points="<?php echo admin_h($linePointsString); ?>"></polyline>

                  <?php foreach ($dotPoints as $index => $dot): ?>
                    <circle
                      class="adm-hover-dot"
                      cx="<?php echo $dot['x']; ?>"
                      cy="<?php echo $dot['y']; ?>"
                      r="13"
                      data-date="<?php echo admin_h($dot['label']); ?>"
                      data-total="<?php echo number_format($dot['total']); ?>"
                      data-new="<?php echo number_format($dot['new']); ?>"
                      data-dot-id="growthDot<?php echo $index; ?>"
                    ></circle>
                    <circle
                      id="growthDot<?php echo $index; ?>"
                      class="adm-dot"
                      cx="<?php echo $dot['x']; ?>"
                      cy="<?php echo $dot['y']; ?>"
                      r="4.7"
                    ></circle>
                  <?php endforeach; ?>

                  <?php foreach ($dotPoints as $index => $dot): ?>
                    <?php if ($index === 0 || $index === count($dotPoints) - 1 || $index % $labelEvery === 0): ?>
                      <text class="adm-chart-label" x="<?php echo $dot['x']; ?>" y="<?php echo $chartH - 12; ?>" text-anchor="middle">
                        <?php echo admin_h($dot['axis_label']); ?>
                      </text>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </svg>
              <?php endif; ?>
            </div>

            <div class="adm-growth-note">
              <span>Tracking users where role is <strong>CUSTOMER</strong></span>
              <span><?php echo number_format($growthMax); ?> total shown</span>
            </div>
          </div>
        </article>

        <article class="adm-panel">
          <div class="adm-panel-head">
            <div>
              <h2 class="adm-panel-title">Sales by Category</h2>
              <span class="adm-panel-sub">Current week split</span>
            </div>
            <span class="adm-chip">This Week</span>
          </div>

          <div class="adm-panel-body">
            <ul class="adm-category-list">
              <?php if (!$categorySales): ?>
                <li>
                  <span>No sales data</span>
                  <strong>—</strong>
                </li>
              <?php else: ?>
                <?php foreach ($categorySales as $category): ?>
                  <li>
                    <span><?php echo admin_h($category['CATEGORY_NAME'] ?? 'Uncategorized'); ?></span>
                    <strong><?php echo (int)($category['PERCENTAGE'] ?? 0); ?>%</strong>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>
        </article>
      </section>

      <section class="adm-grid equal">
        <article class="adm-panel">
          <div class="adm-panel-head">
            <div>
              <h2 class="adm-panel-title">Pending Trader Approvals</h2>
              <span class="adm-panel-sub">Newest trader requests</span>
            </div>
            <a href="pending-requests.php" class="adm-link">View All</a>
          </div>

          <div class="adm-panel-body scroll-x">
            <table class="adm-table">
              <thead>
                <tr>
                  <th>Trader Name</th>
                  <th>Shop</th>
                  <th>Submitted</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$pendingTraders): ?>
                  <tr>
                    <td colspan="4">
                      <span class="adm-empty">No pending approvals</span>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($pendingTraders as $trader): ?>
                    <tr>
                      <td><?php echo admin_h($trader['TRADER_NAME'] ?? 'Unknown'); ?></td>
                      <td><?php echo admin_h($trader['SHOP_NAME'] ?? '—'); ?></td>
                      <td><?php echo admin_h($trader['SUBMITTED'] ?? '—'); ?></td>
                      <td>
                        <span class="adm-status"><?php echo admin_h($trader['STATUS'] ?? 'PENDING'); ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>

        <article class="adm-panel">
          <div class="adm-panel-head">
            <div>
              <h2 class="adm-panel-title">Recent User Registrations</h2>
              <span class="adm-panel-sub">Latest platform signups</span>
            </div>
            <a href="user-management.php" class="adm-link">View All</a>
          </div>

          <div class="adm-panel-body scroll-x">
            <table class="adm-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Joined</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$recentUsers): ?>
                  <tr>
                    <td colspan="3">
                      <span class="adm-empty">No recent registrations</span>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recentUsers as $user): ?>
                    <tr>
                      <td><?php echo admin_h($user['NAME'] ?? 'Unknown'); ?></td>
                      <td><?php echo admin_h($user['EMAIL'] ?? '—'); ?></td>
                      <td><?php echo admin_h($user['JOINED'] ?? '—'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
      </section>

      <section class="adm-grid equal">
        <article class="adm-panel">
          <div class="adm-panel-head">
            <div>
              <h2 class="adm-panel-title">Recent Reported Reviews</h2>
              <span class="adm-panel-sub">Reported reviews still visible to customers</span>
            </div>
            <a href="reviews.php" class="adm-link">Manage</a>
          </div>

          <div class="adm-panel-body scroll-x">
            <table class="adm-table">
              <thead>
                <tr>
                  <th>Review</th>
                  <th>User</th>
                  <th>Reason</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$flaggedReviews): ?>
                  <tr>
                    <td colspan="4">
                      <span class="adm-empty">No reported reviews</span>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($flaggedReviews as $review): ?>
                    <tr>
                      <td><?php echo admin_h(admin_short_text($review['REVIEW_TEXT'] ?? 'Review')); ?></td>
                      <td><?php echo admin_h($review['USER_NAME'] ?? 'Unknown'); ?></td>
                      <td><?php echo admin_h($review['REASON'] ?? 'Reported'); ?></td>
                      <td>
                        <a href="reviews.php" class="adm-link">Review</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
      </section>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
  </div>
</div>

<script src="../assets/admin/js/dashboard.js?v=20260517c"></script>
</body>
</html>

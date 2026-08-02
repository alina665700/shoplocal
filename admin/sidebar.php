<?php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!function_exists('e')) {
  function e($value)
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('admin_sidebar_initials_from_name')) {
  function admin_sidebar_initials_from_name($name)
  {
    $name = trim((string)$name);

    if ($name === '') {
      return 'AP';
    }

    $parts = preg_split('/\s+/', $name);
    $first = function_exists('mb_substr') ? mb_substr($parts[0] ?? 'A', 0, 1) : substr($parts[0] ?? 'A', 0, 1);
    $last = count($parts) > 1
      ? (function_exists('mb_substr') ? mb_substr(end($parts), 0, 1) : substr(end($parts), 0, 1))
      : '';

    return strtoupper($first . $last);
  }
}

$active = $active ?? '';
$current = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');

$profile = is_array($profile ?? null) ? $profile : [];

$fullName = $profile['FULL_NAME']
  ?? trim(($profile['FIRST_NAME'] ?? '') . ' ' . ($profile['LAST_NAME'] ?? ''))
  ?: trim((string)($_SESSION['admin_name'] ?? $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? ''));

$fullName = $fullName !== '' ? $fullName : 'Admin Profile';
$adminRole = $profile['ROLE_LABEL'] ?? 'Administrator';
$initials = $profile['INITIALS'] ?? admin_sidebar_initials_from_name($fullName);

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$projectBaseUrl = preg_replace('#/admin/.*$#', '', $scriptName);
if ($projectBaseUrl === $scriptName || $projectBaseUrl === '') {
  $projectBaseUrl = '/main_project';
}
$logoSrc = rtrim($projectBaseUrl, '/') . '/config/logos/website_logo.svg';


if (!function_exists('admin_sidebar_db_connection')) {
  function admin_sidebar_db_connection()
  {
    if (function_exists('admin_db_connection')) {
      try {
        $candidate = admin_db_connection();
        if ($candidate) {
          return $candidate;
        }
      } catch (Throwable $e) {
      }
    }

    global $conn, $connection, $db_conn, $db, $oracle_conn;
    foreach ([$conn ?? null, $connection ?? null, $db_conn ?? null, $db ?? null, $oracle_conn ?? null] as $candidate) {
      if ($candidate) {
        return $candidate;
      }
    }

    return null;
  }
}

if (!function_exists('admin_sidebar_fetch_total')) {
  function admin_sidebar_fetch_total($sql, $binds = [])
  {
    $conn = admin_sidebar_db_connection();
    if (!$conn || !function_exists('oci_parse')) {
      return 0;
    }

    try {
      $stmt = @oci_parse($conn, $sql);
      if (!$stmt) {
        return 0;
      }

      $localBinds = [];
      foreach ($binds as $key => $value) {
        $bindName = ':' . ltrim((string)$key, ':');
        $localBinds[$bindName] = $value;
        @oci_bind_by_name($stmt, $bindName, $localBinds[$bindName]);
      }

      if (!@oci_execute($stmt)) {
        @oci_free_statement($stmt);
        return 0;
      }

      $row = @oci_fetch_assoc($stmt);
      @oci_free_statement($stmt);
      return (int)($row['TOTAL'] ?? 0);
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('admin_sidebar_table_exists')) {
  function admin_sidebar_table_exists($tableName)
  {
    return admin_sidebar_fetch_total(
      'SELECT COUNT(*) AS TOTAL FROM USER_TABLES WHERE TABLE_NAME = UPPER(:table_name)',
      [':table_name' => $tableName]
    ) > 0;
  }
}

if (!function_exists('admin_sidebar_column_exists')) {
  function admin_sidebar_column_exists($tableName, $columnName)
  {
    return admin_sidebar_fetch_total(
      'SELECT COUNT(*) AS TOTAL FROM USER_TAB_COLUMNS WHERE TABLE_NAME = UPPER(:table_name) AND COLUMN_NAME = UPPER(:column_name)',
      [':table_name' => $tableName, ':column_name' => $columnName]
    ) > 0;
  }
}

if (!function_exists('admin_sidebar_first_existing_column')) {
  function admin_sidebar_first_existing_column($tableName, array $candidates)
  {
    foreach ($candidates as $candidate) {
      $candidate = strtoupper((string)$candidate);
      if (admin_sidebar_column_exists($tableName, $candidate)) {
        return $candidate;
      }
    }
    return null;
  }
}

if (!function_exists('admin_sidebar_pending_trader_count')) {
  function admin_sidebar_pending_trader_count()
  {
    if (!admin_sidebar_table_exists('TRADER')) {
      return 0;
    }

    $statusCol = admin_sidebar_first_existing_column('TRADER', ['VERIFIED_STATUS', 'APPROVAL_STATUS', 'STATUS']);
    if (!$statusCol) {
      return 0;
    }

    return admin_sidebar_fetch_total(
      "SELECT COUNT(*) AS TOTAL FROM TRADER WHERE UPPER(NVL(TRIM(TO_CHAR($statusCol)), 'PENDING')) IN ('PENDING', 'UNVERIFIED')"
    );
  }
}

if (!function_exists('admin_sidebar_pending_product_count')) {
  function admin_sidebar_pending_product_count()
  {
    if (!admin_sidebar_table_exists('PRODUCT') || !admin_sidebar_column_exists('PRODUCT', 'ADMIN_APPROVAL_STATUS')) {
      return 0;
    }

    return admin_sidebar_fetch_total(
      "SELECT COUNT(*) AS TOTAL FROM PRODUCT WHERE UPPER(NVL(TRIM(TO_CHAR(ADMIN_APPROVAL_STATUS)), 'PENDING')) = 'PENDING'"
    );
  }
}

if (!function_exists('admin_sidebar_review_report_count')) {
  function admin_sidebar_review_report_count()
  {
    if (!admin_sidebar_table_exists('REVIEW')) {
      return 0;
    }

    $reportedCol = admin_sidebar_first_existing_column('REVIEW', ['REPORTED_BY', 'REPORT_BY']);
    if (!$reportedCol) {
      return 0;
    }

    $approvalCol = admin_sidebar_first_existing_column('REVIEW', ['APPROVAL_STATUS', 'APPROVAL_STATU', 'STATUS']);
    $approvalWhere = $approvalCol
      ? " AND UPPER(NVL(TRIM(TO_CHAR($approvalCol)), 'YES')) IN ('YES', 'Y', 'APPROVED', 'ACTIVE')"
      : '';

    return admin_sidebar_fetch_total(
      "SELECT COUNT(*) AS TOTAL FROM REVIEW WHERE $reportedCol IS NOT NULL" . $approvalWhere
    );
  }
}

if (!function_exists('admin_sidebar_active_order_count')) {
  function admin_sidebar_active_order_count()
  {
    if (!admin_sidebar_table_exists('ORDERS')) {
      return 0;
    }

    $statusCol = admin_sidebar_first_existing_column('ORDERS', ['ORDER_STATUS', 'STATUS']);
    if (!$statusCol) {
      return 0;
    }

    $idCol = admin_sidebar_first_existing_column('ORDERS', ['ORDER_ID', 'ID']);
    $countExpr = $idCol ? "COUNT(DISTINCT $idCol)" : 'COUNT(*)';

    return admin_sidebar_fetch_total(
      "SELECT $countExpr AS TOTAL FROM ORDERS WHERE UPPER(NVL(TRIM(TO_CHAR($statusCol)), 'CONFIRMED')) IN ('CONFIRMED', 'READY')"
    );
  }
}



if (!function_exists('admin_sidebar_today_collection_count')) {
  function admin_sidebar_today_collection_count()
  {
    if (!admin_sidebar_table_exists('ORDERS') || !admin_sidebar_table_exists('ORDER_ITEM') || !admin_sidebar_table_exists('PAYMENT')) {
      return 0;
    }

    return admin_sidebar_fetch_total(
      "SELECT COUNT(*) AS TOTAL
       FROM ORDERS o
       JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
       JOIN ORDER_ITEM oi ON oi.ORDER_ID = o.ORDER_ID
       WHERE TRUNC(o.PICKUP_DATE) = TRUNC(SYSDATE)
         AND UPPER(NVL(TRIM(TO_CHAR(p.PAYMENT_STATUS)), 'FAILED')) = 'COMPLETED'
         AND UPPER(NVL(TRIM(TO_CHAR(oi.ITEM_STATUS)), 'PENDING')) NOT IN ('COLLECTED', 'CANCELLED')"
    );
  }
}

if (!function_exists('admin_sidebar_refund_queue_count')) {
  function admin_sidebar_refund_queue_count()
  {
    if (!admin_sidebar_table_exists('ORDERS') || !admin_sidebar_table_exists('ORDER_ITEM') || !admin_sidebar_table_exists('PAYMENT')) {
      return 0;
    }

    return admin_sidebar_fetch_total(
      "SELECT COUNT(*) AS TOTAL
       FROM PAYMENT p
       JOIN ORDER_ITEM oi ON oi.ORDER_ID = p.ORDER_ID
       WHERE UPPER(NVL(TRIM(TO_CHAR(oi.ITEM_STATUS)), 'PENDING')) = 'CANCELLED'
         AND UPPER(NVL(TRIM(TO_CHAR(p.PAYMENT_STATUS)), 'FAILED')) = 'COMPLETED'"
    );
  }
}

$adminPendingTraderCount = admin_sidebar_pending_trader_count();
$adminPendingProductCount = admin_sidebar_pending_product_count();
$adminReviewReportCount = admin_sidebar_review_report_count();
$adminPendingRequestCount = $adminPendingTraderCount + $adminPendingProductCount + $adminReviewReportCount;
$adminActiveOrderCount = admin_sidebar_active_order_count();
$adminTodayCollectionCount = admin_sidebar_today_collection_count();
$adminRefundQueueCount = admin_sidebar_refund_queue_count();

$nav = [
  ['key' => 'dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard', 'group' => 'Overview', 'pages' => ['dashboard.php']],

  ['key' => 'pending', 'href' => 'pending-requests.php', 'icon' => 'clock', 'label' => 'Pending Requests', 'group' => 'Approvals', 'pages' => ['pending-requests.php'], 'badge' => $adminPendingRequestCount],
  ['key' => 'traders', 'href' => 'approve-trader.php', 'icon' => 'approve', 'label' => 'Approve Traders', 'group' => 'Approvals', 'pages' => ['approve-trader.php'], 'badge' => $adminPendingTraderCount],
  ['key' => 'products', 'href' => 'manage-products.php', 'icon' => 'box', 'label' => 'Approve Products', 'group' => 'Approvals', 'pages' => ['manage-products.php'], 'badge' => $adminPendingProductCount],
  ['key' => 'reviews', 'href' => 'reviews.php', 'icon' => 'star', 'label' => 'Review Moderation', 'group' => 'Approvals', 'pages' => ['reviews.php'], 'badge' => $adminReviewReportCount],

  ['key' => 'users', 'href' => 'user-management.php', 'icon' => 'users', 'label' => 'User Management', 'group' => 'Management', 'pages' => ['user-management.php']],
  ['key' => 'categories', 'href' => 'category-management.php', 'icon' => 'layers', 'label' => 'Categories', 'group' => 'Management', 'pages' => ['category-management.php']],
  ['key' => 'vouchers', 'href' => 'voucher-management.php', 'icon' => 'ticket', 'label' => 'Vouchers', 'group' => 'Management', 'pages' => ['voucher-management.php']],

  ['key' => 'orders', 'href' => 'order-management.php', 'icon' => 'orders', 'label' => 'Orders', 'group' => 'Orders', 'pages' => ['order-management.php', 'order-detail.php'], 'badge' => $adminActiveOrderCount],
  ['key' => 'collections', 'href' => 'today-collections.php', 'icon' => 'calendar', 'label' => "Today's Collections", 'group' => 'Orders', 'pages' => ['today-collections.php'], 'badge' => $adminTodayCollectionCount],
  ['key' => 'refunds', 'href' => 'uncollected-orders.php?filter=refund', 'icon' => 'refund', 'label' => 'Refund Queue', 'group' => 'Orders', 'pages' => ['uncollected-orders.php'], 'badge' => $adminRefundQueueCount],

  ['key' => 'transactions', 'href' => 'transactions.php', 'icon' => 'receipt', 'label' => 'Transactions', 'group' => 'Finance', 'pages' => ['transactions.php']],
  ['key' => 'finance', 'href' => 'finance-report.php', 'icon' => 'finance', 'label' => 'Finance Report', 'group' => 'Finance', 'pages' => ['finance-report.php']],

  ['key' => 'profile', 'href' => 'profile.php', 'icon' => 'profile', 'label' => 'Admin Profile', 'group' => 'Account', 'pages' => ['profile.php']],
  ['key' => 'logout', 'href' => 'logout.php', 'icon' => 'logout', 'label' => 'Logout', 'group' => 'Account', 'pages' => ['logout.php']],
];

if (!function_exists('admin_sidebar_icon')) {
  function admin_sidebar_icon($icon)
  {
    $icons = [
      'dashboard' => '<svg viewBox="0 0 24 24"><path d="M4 14a8 8 0 1 1 16 0"></path><path d="M12 14l4-4"></path><path d="M7 14h.01"></path><path d="M17 14h.01"></path><path d="M9 19h6"></path></svg>',
      'clock' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v5l3 2"></path></svg>',
      'ticket' => '<svg viewBox="0 0 24 24"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"></path><path d="M9 9h6"></path><path d="M9 15h6"></path></svg>',
      'orders' => '<svg viewBox="0 0 24 24"><path d="M6 3h12v18H6z"></path><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h4"></path></svg>',
      'layers' => '<svg viewBox="0 0 24 24"><path d="m12 4 8 4-8 4-8-4 8-4Z"></path><path d="m4 12 8 4 8-4"></path><path d="m4 16 8 4 8-4"></path></svg>',
      'box' => '<svg viewBox="0 0 24 24"><path d="m21 8-9-5-9 5 9 5 9-5Z"></path><path d="M3 8v8l9 5 9-5V8"></path><path d="M12 13v8"></path></svg>',
      'approve' => '<svg viewBox="0 0 24 24"><path d="M16 21a6 6 0 0 0-12 0"></path><circle cx="10" cy="8" r="4"></circle><path d="m17 11 2 2 4-4"></path></svg>',
      'users' => '<svg viewBox="0 0 24 24"><path d="M16 21a5 5 0 0 0-10 0"></path><circle cx="11" cy="8" r="4"></circle><path d="M21 21a4 4 0 0 0-4-4"></path><path d="M17 4a3 3 0 0 1 0 6"></path></svg>',
      'receipt' => '<svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 0 1 2-2Z"></path><path d="M8 8h8"></path><path d="M8 12h8"></path><path d="M8 16h5"></path></svg>',
      'calendar' => '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4"></path><path d="M16 3v4"></path><path d="M4 10h16"></path><path d="m9 15 2 2 4-4"></path></svg>',
      'refund' => '<svg viewBox="0 0 24 24"><path d="M7 7h10a4 4 0 0 1 0 8H9"></path><path d="m7 7 4-4"></path><path d="M7 7l4 4"></path><path d="M8 19h8"></path></svg>',
      'finance' => '<svg viewBox="0 0 24 24"><path d="M4 19h16"></path><path d="M7 16V9"></path><path d="M12 16V5"></path><path d="M17 16v-3"></path><path d="M6 9l6-4 6 8"></path></svg>',
      'star' => '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1L12 16.9 6.6 19.8l1-6.1-4.4-4.3 6.1-.9L12 3Z"></path></svg>',
      'profile' => '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="8" r="4"></circle></svg>',
      'logout' => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>',
    ];

    return $icons[$icon] ?? $icons['dashboard'];
  }
}

if (!function_exists('admin_sidebar_is_active')) {
  function admin_sidebar_is_active($item, $current, $active)
  {
    if (!empty($active) && ($item['key'] ?? '') === $active) {
      return true;
    }

    return in_array($current, $item['pages'] ?? [], true);
  }
}

$currentGroup = '';
?>

<link rel="stylesheet" href="../assets/admin/css/sidebar.css?v=20260517b">

<aside class="admin-sidebar sidebar" id="sidebar">
  <div class="sidebar-logo">
    <a class="sidebar-logo-link" href="dashboard.php" aria-label="ShopLocalfy admin dashboard">
      <img
        class="sidebar-logo-img"
        src="<?php echo e($logoSrc); ?>"
        alt="ShopLocalfy"
        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
      <span class="sidebar-logo-text">Shop<em>Localfy</em></span>
    </a>
  </div>

  <nav class="nav" aria-label="Admin navigation">
    <?php foreach ($nav as $item): ?>
      <?php if ($item['group'] !== $currentGroup): ?>
        <?php if ($currentGroup !== ''): ?>
          </div>
        <?php endif; ?>

        <?php $currentGroup = $item['group']; ?>

        <div class="nav-group">
          <div class="nav-label"><?php echo e($currentGroup); ?></div>
        <?php endif; ?>

        <?php $isActive = admin_sidebar_is_active($item, $current, $active); ?>
        <a href="<?php echo e($item['href']); ?>" class="nav-link<?php echo $isActive ? ' active' : ''; ?>">
          <span class="ni" aria-hidden="true"><?php echo admin_sidebar_icon($item['icon']); ?></span>
          <span><?php echo e($item['label']); ?></span>

          <?php if (!empty($item['badge'])): ?>
            <span class="nav-badge"><?php echo e($item['badge']); ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php if ($currentGroup !== ''): ?>
        </div>
      <?php endif; ?>
  </nav>

  <div class="sidebar-foot">
    <a class="profile-btn" href="profile.php" aria-label="Admin profile">
      <div class="avatar"><?php echo e($initials); ?></div>
      <div>
        <div class="pname"><?php echo e($fullName); ?></div>
        <div class="prole"><?php echo e($adminRole); ?></div>
      </div>
    </a>
  </div>
</aside>

<script src="../assets/admin/js/sidebar.js?v=20260517b"></script>


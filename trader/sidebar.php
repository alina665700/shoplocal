<?php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$commonPath = __DIR__ . '/trader_common.php';
if (is_file($commonPath)) {
  require_once $commonPath;
}

if (!function_exists('e')) {
  function e($value)
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('initials_from_name')) {
  function initials_from_name($name)
  {
    $name = trim((string)$name);

    if ($name === '') {
      return 'TP';
    }

    $parts = preg_split('/\s+/', $name);
    $first = function_exists('mb_substr') ? mb_substr($parts[0] ?? 'T', 0, 1) : substr($parts[0] ?? 'T', 0, 1);
    $last = count($parts) > 1
      ? (function_exists('mb_substr') ? mb_substr(end($parts), 0, 1) : substr(end($parts), 0, 1))
      : '';

    return strtoupper($first . $last);
  }
}

$active = $active ?? '';

$profile = is_array($profile ?? null) ? $profile : [];

$fullName = $profile['FULL_NAME']
  ?? trim(($profile['FIRST_NAME'] ?? '') . ' ' . ($profile['LAST_NAME'] ?? ''))
  ?: 'Trader Profile';

$shopName = $profile['SHOP_NAME'] ?? 'Store Owner';
$initials = $profile['INITIALS'] ?? initials_from_name($fullName);
if (!function_exists('trader_sidebar_current_id')) {
  function trader_sidebar_current_id()
  {
    if (function_exists('current_trader_id')) {
      try {
        $candidate = current_trader_id();
        if ($candidate) {
          return $candidate;
        }
      } catch (Throwable $e) {
        // Keep the sidebar loading.
      }
    }

    $role = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
    $candidate = trim((string)($_SESSION['trader_user_id'] ?? $_SESSION['trader_id'] ?? ''));

    if ($candidate === '' && ($role === '' || $role === 'TRADER')) {
      $candidate = trim((string)($_SESSION['user_id'] ?? ''));
    }

    return $candidate !== '' ? $candidate : null;
  }
}

if (!function_exists('trader_sidebar_first_existing_column')) {
  function trader_sidebar_first_existing_column($conn, $tableName, array $candidates)
  {
    if (!$conn || !function_exists('column_exists')) {
      return null;
    }

    foreach ($candidates as $candidate) {
      $candidate = strtoupper((string)$candidate);
      if (column_exists($conn, $tableName, $candidate)) {
        return $candidate;
      }
    }

    return null;
  }
}

if (!function_exists('trader_sidebar_db_connection')) {
  function trader_sidebar_db_connection()
  {
    if (function_exists('trader_db_connection')) {
      try {
        $candidate = trader_db_connection();
        if ($candidate) {
          return $candidate;
        }
      } catch (Throwable $e) {
        // Keep the sidebar loading.
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

if (!function_exists('trader_sidebar_pending_order_count')) {
  function trader_sidebar_pending_order_count()
  {
    $conn = trader_sidebar_db_connection();
    $traderId = trader_sidebar_current_id();

    if (!$conn || !$traderId || !function_exists('get_pending_order_count')) {
      return 0;
    }

    try {
      return (int)get_pending_order_count($conn, $traderId);
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('trader_sidebar_new_review_count')) {
  /**
   * Counts review reports that this trader has submitted and that still need an admin decision.
   * The name is kept for backwards compatibility with older pages, but the badge is now based
   * on real REVIEW.REPORTED_BY data instead of fake unread/recent-review logic.
   */
  function trader_sidebar_new_review_count()
  {
    $conn = trader_sidebar_db_connection();
    $traderId = trader_sidebar_current_id();

    if (!$conn || !$traderId || !function_exists('table_exists') || !function_exists('column_exists') || !function_exists('db_one')) {
      return 0;
    }

    if (!table_exists($conn, 'REVIEW')) {
      return 0;
    }

    try {
      $productIdCol = trader_sidebar_first_existing_column($conn, 'REVIEW', ['PRODUCT_ID']);
      $traderIdCol = trader_sidebar_first_existing_column($conn, 'REVIEW', ['TRADER_ID']);
      $reportedCol = trader_sidebar_first_existing_column($conn, 'REVIEW', ['REPORTED_BY', 'REPORT_BY']);
      $approvalCol = trader_sidebar_first_existing_column($conn, 'REVIEW', ['APPROVAL_STATUS', 'APPROVAL_STATU', 'STATUS']);

      if (!$reportedCol) {
        return 0;
      }

      $from = 'REVIEW r';
      $where = [];
      $binds = [':trader_id' => $traderId];

      if ($productIdCol && table_exists($conn, 'PRODUCT') && table_exists($conn, 'SHOP') && column_exists($conn, 'PRODUCT', 'SHOP_ID') && column_exists($conn, 'SHOP', 'TRADER_ID')) {
        $from .= " INNER JOIN PRODUCT p ON p.PRODUCT_ID = r.$productIdCol INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID";
        $where[] = 's.TRADER_ID = :trader_id';
      } elseif ($traderIdCol) {
        $where[] = "r.$traderIdCol = :trader_id";
      } else {
        return 0;
      }

      // Only count flags submitted by this trader that admin has not approved/rejected yet.
      $where[] = "r.$reportedCol = :trader_id";

      if ($approvalCol) {
        $where[] = "UPPER(NVL(TRIM(TO_CHAR(r.$approvalCol)), 'YES')) IN ('YES', 'Y', 'APPROVED', 'ACTIVE')";
      }

      $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM ' . $from . ' WHERE ' . implode(' AND ', $where), $binds);
      return (int)($row['TOTAL'] ?? 0);
    } catch (Throwable $e) {
      return 0;
    }
  }
}

$pendingOrderCount = (int)($pendingOrderCount ?? $pendingCount ?? trader_sidebar_pending_order_count());
$reviewReportCount = (int)($reviewReportCount ?? $newReviewCount ?? $reviewNotificationCount ?? trader_sidebar_new_review_count());

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$projectBaseUrl = preg_replace('#/trader/.*$#', '', $scriptName);
if ($projectBaseUrl === $scriptName || $projectBaseUrl === '') {
  $projectBaseUrl = '/main_project';
}
$logoSrc = rtrim($projectBaseUrl, '/') . '/config/logos/website_logo.svg';

$nav = [
  ['key' => 'dashboard', 'href' => 'index.php', 'icon' => 'dashboard', 'label' => 'Dashboard', 'group' => 'Overview'],
  ['key' => 'analytics', 'href' => 'analytics.php', 'icon' => 'chart', 'label' => 'Analytics', 'group' => 'Overview'],

  ['key' => 'products', 'href' => 'products.php', 'icon' => 'box', 'label' => 'Products', 'group' => 'Management'],
  ['key' => 'customers', 'href' => 'customer.php', 'icon' => 'users', 'label' => 'Customers', 'group' => 'Management'],
  ['key' => 'warehouse', 'href' => 'warehouse.php', 'icon' => 'warehouse', 'label' => 'Warehouse', 'group' => 'Management'],
  ['key' => 'orders', 'href' => 'orders.php', 'icon' => 'orders', 'label' => 'Orders', 'group' => 'Management', 'badge' => $pendingOrderCount],
  ['key' => 'ready', 'href' => 'ready-items.php', 'icon' => 'orders', 'label' => 'Get Items Ready', 'group' => 'Management'],
  ['key' => 'discounts', 'href' => 'discount.php', 'icon' => 'tag', 'label' => 'Discounts', 'group' => 'Management'],
  ['key' => 'reviews', 'href' => 'reviews.php', 'icon' => 'star', 'label' => 'Reviews', 'group' => 'Management', 'badge' => $reviewReportCount],

  ['key' => 'profile', 'href' => 'profile.php', 'icon' => 'profile', 'label' => 'Trader Profile', 'group' => 'Account'],
  ['key' => 'logout', 'href' => 'logout.php', 'icon' => 'logout', 'label' => 'Logout', 'group' => 'Account'],
];

if (!function_exists('trader_sidebar_icon')) {
  function trader_sidebar_icon($icon)
  {
    $icons = [
      'dashboard' => '<svg viewBox="0 0 24 24"><path d="M4 14a8 8 0 1 1 16 0"></path><path d="M12 14l4-4"></path><path d="M7 14h.01"></path><path d="M17 14h.01"></path><path d="M9 19h6"></path></svg>',
      'chart' => '<svg viewBox="0 0 24 24"><path d="M4 19V5"></path><path d="M4 19h16"></path><path d="M8 16v-5"></path><path d="M12 16V8"></path><path d="M16 16v-3"></path></svg>',
      'box' => '<svg viewBox="0 0 24 24"><path d="M4 8.5 12 4l8 4.5-8 4.5L4 8.5Z"></path><path d="M4 8.5v7L12 20l8-4.5v-7"></path><path d="M12 13v7"></path></svg>',
      'users' => '<svg viewBox="0 0 24 24"><path d="M16 21a5 5 0 0 0-10 0"></path><circle cx="11" cy="8" r="4"></circle><path d="M21 21a4 4 0 0 0-4-4"></path><path d="M17 4a3 3 0 0 1 0 6"></path></svg>',
      'warehouse' => '<svg viewBox="0 0 24 24"><path d="M3 10 12 4l9 6"></path><path d="M5 10v10h14V10"></path><path d="M8 20v-6h8v6"></path><path d="M8 14h8"></path></svg>',
      'orders' => '<svg viewBox="0 0 24 24"><path d="M6 3h12v18H6z"></path><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h4"></path></svg>',
      'tag' => '<svg viewBox="0 0 24 24"><path d="M20 13 13 20 4 11V4h7l9 9Z"></path><circle cx="8.5" cy="8.5" r="1.2"></circle></svg>',
      'star' => '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1L12 16.9 6.6 19.8l1-6.1-4.4-4.3 6.1-.9L12 3Z"></path></svg>',
      'profile' => '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="8" r="4"></circle></svg>',
      'logout' => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>',
    ];

    return $icons[$icon] ?? $icons['dashboard'];
  }
}

$currentGroup = '';
?>

<link rel="stylesheet" href="../assets/trader/css/sidebar.css?v=20260517">

<aside class="trader-sidebar sidebar" id="sidebar">
  <div class="sidebar-logo">
    <a class="sidebar-logo-link" href="index.php" aria-label="ShopLocalfy trader dashboard">
      <img
        class="sidebar-logo-img"
        src="<?php echo e($logoSrc); ?>"
        alt="ShopLocalfy"
        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
      <span class="sidebar-logo-text">Shop<em>Localfy</em></span>
    </a>
  </div>

  <nav class="nav" aria-label="Trader navigation">
    <?php foreach ($nav as $item): ?>
      <?php if ($item['group'] !== $currentGroup): ?>
        <?php if ($currentGroup !== ''): ?>
          </div>
        <?php endif; ?>

        <?php $currentGroup = $item['group']; ?>

        <div class="nav-group">
          <div class="nav-label"><?php echo e($currentGroup); ?></div>
        <?php endif; ?>

        <a href="<?php echo e($item['href']); ?>" class="nav-link<?php echo $item['key'] === $active ? ' active' : ''; ?>">
          <span class="ni" aria-hidden="true"><?php echo trader_sidebar_icon($item['icon']); ?></span>
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
    <div class="profile-btn">
      <div class="avatar"><?php echo e($initials); ?></div>
      <div>
        <div class="pname"><?php echo e($fullName); ?></div>
        <div class="prole"><?php echo e($shopName); ?></div>
      </div>
    </div>
  </div>
</aside>

<script src="../assets/trader/js/sidebar.js?v=20260517"></script>
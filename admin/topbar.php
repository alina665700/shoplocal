<?php
// admin/topbar.php
require_once __DIR__ . '/admin_ui.php';
admin_ui_output_assets();

$current = basename($_SERVER['PHP_SELF']);
$pageTitles = [
    'dashboard.php' => 'Dashboard',
    'pending-requests.php' => 'Pending Requests',
    'voucher-management.php' => 'Vouchers',
    'order-management.php' => 'Order Management',
    'today-collections.php' => "Today's Collections",
    'uncollected-orders.php' => 'Refund Queue',
    'order-detail.php' => 'Order Detail',
    'category-management.php' => 'Categories',
    'manage-products.php' => 'Product Approval',
    'approve-trader.php' => 'Approve Traders',
    'user-management.php' => 'User Management',
    'transactions.php' => 'Transactions',
    'finance-report.php' => 'Finance Report',
    'reviews.php' => 'Review Reports',
    'profile.php' => 'Admin Profile',
];
$currentTitle = $pageTitles[$current] ?? 'Dashboard';
?>
<link rel="stylesheet" href="../assets/admin/css/topbar.css?v=20260517b">

<header class="admin-topbar topbar">
  <div class="admin-topbar-left topbar-left">
    <button class="admin-sidebar-toggle sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
    </button>
    <nav class="admin-breadcrumb-nav breadcrumb-nav" aria-label="Breadcrumb">
      <span>Admin</span>
      <svg class="breadcrumb-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
      <span class="admin-breadcrumb-current breadcrumb-current"><?= admin_ui_h($currentTitle) ?></span>
    </nav>
  </div>

  <div class="admin-topbar-right topbar-right">
    <a class="admin-topbar-action" href="dashboard.php" title="Dashboard" aria-label="Dashboard">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14a8 8 0 1 1 16 0"></path><path d="M12 14l4-4"></path><path d="M9 19h6"></path></svg>
    </a>
    <a class="admin-topbar-action" href="logout.php" title="Logout" aria-label="Logout">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>
    </a>
    <a class="admin-avatar-wrap avatar-wrap" href="profile.php" title="Admin profile" aria-label="Admin profile">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="8" r="4"></circle></svg>
    </a>
  </div>
</header>

<script src="../assets/admin/js/topbar.js?v=20260517b"></script>

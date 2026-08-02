<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentSearch = trim((string)($_GET['q'] ?? ''));

// Build a root-relative URL so the logo works from /customer, /trader, /admin, or root pages.
// For XAMPP path C:\xampp\htdocs\main_project, this becomes /main_project/config/logos/website_logo.svg.
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$pathParts = array_values(array_filter(explode('/', $scriptName)));
$projectBaseUrl = isset($pathParts[0]) ? '/' . $pathParts[0] : '';
$logoSrc = $projectBaseUrl . '/config/logos/website_logo.svg';

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
$isLoggedIn = $sessionRole !== '' && $sessionRole !== 'CUSTOMER'
    ? false
    : (
        !empty($_SESSION['customer_id']) ||
        !empty($_SESSION['CUSTOMER_ID']) ||
        ($sessionRole === 'CUSTOMER' && (!empty($_SESSION['user_id']) || !empty($_SESSION['USER_ID'])))
    );

$currentReturnUrl = basename((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
$currentReturnUrl = str_replace(["\r", "\n"], '', $currentReturnUrl);
if ($currentReturnUrl === '' || preg_match('/^https?:\/\//i', $currentReturnUrl) || str_starts_with($currentReturnUrl, '//')) {
    $currentReturnUrl = 'index.php';
}
$profileHref = $isLoggedIn ? 'profile.php' : 'login.php?redirect=' . rawurlencode($currentReturnUrl);

function nav_active($pageNames) {
    global $currentPage;
    foreach ((array)$pageNames as $pageName) {
        if ($currentPage === $pageName) {
            return ' active';
        }
    }
    return '';
}
?>

<link rel="stylesheet" href="../assets/customer/css/navbar.css?v=20260517">

<header class="site-nav-wrap" id="customerNavbar">
  <nav class="site-nav" aria-label="Main navigation">

    <a class="site-logo" href="index.php" aria-label="ShopLocalfy Home">
      <img
        class="site-logo-img"
        src="<?php echo e($logoSrc); ?>"
        alt="ShopLocalfy"
        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
      >
      <span class="site-logo-fallback">ShopLocalfy</span>
    </a>

    <button class="site-nav-toggle" id="customerNavToggle" type="button" aria-label="Toggle customer menu" aria-expanded="false" aria-controls="customerNavPanel">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M4 7h16"></path>
        <path d="M4 12h16"></path>
        <path d="M4 17h16"></path>
      </svg>
    </button>

    <form class="site-search-form" action="search.php" method="GET" role="search">
      <span class="site-search-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="7"></circle>
          <path d="M20 20l-3.5-3.5"></path>
        </svg>
      </span>

      <input
        id="siteSearch"
        class="search-input"
        type="search"
        name="q"
        value="<?php echo e($currentSearch); ?>"
        placeholder="Search products, shops, categories..."
        aria-label="Search products"
      />

      <button class="site-search-btn" type="submit">Search</button>
    </form>

    <div class="site-nav-right" id="customerNavPanel">
      <div class="site-nav-links">
        <a class="site-nav-link<?php echo nav_active(['index.php', 'customer.php']); ?>" href="index.php">Home</a>
        <a class="site-nav-link<?php echo nav_active('categories.php'); ?>" href="categories.php">Categories</a>
        <a class="site-nav-link<?php echo nav_active('customer_all_order.php'); ?>" href="customer_all_order.php">Orders</a>
        <a class="site-nav-link<?php echo nav_active('about.php'); ?>" href="about.php">About</a>
        <a class="site-nav-link<?php echo nav_active('contact.php'); ?>" href="contact.php">Contact</a>
      </div>

      <div class="site-nav-icons" aria-label="Customer shortcuts">

        <a class="site-icon-link" href="cart.php" aria-label="Cart" title="Cart">
          <svg viewBox="0 0 24 24">
            <circle cx="9" cy="20" r="1.6"></circle>
            <circle cx="18" cy="20" r="1.6"></circle>
            <path d="M3 4h2l2.2 10.5a2 2 0 0 0 2 1.5h7.9a2 2 0 0 0 1.9-1.4L21 8H7"></path>
          </svg>
        </a>

        <a class="site-icon-link" href="wishlist.php" aria-label="Wishlist" title="Wishlist">
          <svg viewBox="0 0 24 24">
            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"></path>
          </svg>
        </a>

        <a class="site-icon-link" href="<?php echo e($profileHref); ?>" aria-label="Profile" title="Profile">
          <svg viewBox="0 0 24 24">
            <path d="M20 21a8 8 0 0 0-16 0"></path>
            <circle cx="12" cy="8" r="4"></circle>
          </svg>
        </a>

        <?php if ($isLoggedIn): ?>
          <a class="site-icon-link" href="../customer/logout.php" aria-label="Logout" title="Logout">
            <svg viewBox="0 0 24 24">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <path d="M16 17l5-5-5-5"></path>
              <path d="M21 12H9"></path>
            </svg>
          </a>
        <?php endif; ?>

      </div>
    </div>

  </nav>
</header>

<script src="../assets/customer/js/navbar.js?v=20260517"></script>

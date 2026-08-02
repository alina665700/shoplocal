<?php
require_once __DIR__ . '/customer_common.php';

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$slider_rows = [
    [
        'IMAGE' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?q=80&w=1400&auto=format&fit=crop',
        'TITLE' => 'Fresh Local Produce',
        'SUBTITLE' => 'Support local shops and farmers'
    ],
    [
        'IMAGE' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?q=80&w=1400&auto=format&fit=crop',
        'TITLE' => 'Bakery and Fresh Goods',
        'SUBTITLE' => 'Freshly baked every morning'
    ],
    [
        'IMAGE' => 'https://images.unsplash.com/photo-1506617564039-2f3b650b7010?q=80&w=1400&auto=format&fit=crop',
        'TITLE' => 'Daily Essentials',
        'SUBTITLE' => 'Everything you need in one place'
    ]
];

$products = [];
$categories = [];
$db_error = '';
$searchQuery = trim((string)($_GET['q'] ?? ''));

function customer_has_table($conn, $tableName) {
    return function_exists('table_exists') && table_exists($conn, strtoupper($tableName));
}

function customer_has_column($conn, $tableName, $columnName) {
    return function_exists('column_exists') && column_exists($conn, strtoupper($tableName), strtoupper($columnName));
}

function customer_column_select($conn, $tableName, $alias, $possibleColumns, $resultAlias, $fallbackSql = 'NULL') {
    foreach ($possibleColumns as $column) {
        if (customer_has_column($conn, $tableName, $column)) {
            return $alias . '.' . strtoupper($column) . ' AS ' . $resultAlias;
        }
    }

    return $fallbackSql . ' AS ' . $resultAlias;
}

function money_format_customer($amount) {
    return '£' . number_format(max(0, (float)$amount), 2);
}

function customer_db_value($value) {
    if ($value instanceof OCILob || (is_object($value) && method_exists($value, 'load'))) {
        $loaded = $value->load();
        return $loaded === false ? '' : (string)$loaded;
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return (string)($value ?? '');
}

function customer_text($value, $fallback = '') {
    $text = trim(customer_db_value($value));
    return $text !== '' ? $text : $fallback;
}

function customer_normalize_product_image_path($src) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $src = trim(str_replace('\\', '/', $src));
    if ($src === '') return $placeholder;
    if (preg_match('/^(https?:\/\/|data:image\/)/i', $src)) return $src;
    $filename = basename($src);
    if ($filename === '' || $filename === '.' || $filename === '..') return $placeholder;
    if (strpos($src, 'uploads/products/') === 0) {
        return is_file(dirname(__DIR__) . '/' . $src) ? '../' . $src : $placeholder;
    }
    $file = dirname(__DIR__) . '/uploads/products/' . $filename;
    return is_file($file) ? '../uploads/products/' . rawurlencode($filename) : $placeholder;
}

function customer_product_image_src($value) {
    if ($value instanceof OCILob || (is_object($value) && method_exists($value, 'load'))) {
        $raw = $value->load();

        if ($raw === false || $raw === '') {
            return '../uploads/products/product-placeholder.svg';
        }

        $asText = trim((string)$raw);
        $looksLikeTextPath = $asText !== ''
            && strlen($asText) < 500
            && !preg_match('/[\x00-\x08\x0E-\x1F]/', $asText);

        if ($looksLikeTextPath) {
            return customer_normalize_product_image_path($asText);
        }

        $mime = 'image/jpeg';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_buffer($finfo, $raw);
                finfo_close($finfo);
                if (is_string($detected) && strpos($detected, 'image/') === 0) {
                    $mime = $detected;
                }
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    $src = trim(customer_db_value($value));

    if ($src === '') {
        return '../uploads/products/product-placeholder.svg';
    }

    if (preg_match('/^data:image\//i', $src)) {
        return $src;
    }

    if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $src) && strlen($src) > 120) {
        return 'data:image/jpeg;base64,' . $src;
    }

    return customer_normalize_product_image_path($src);
}

try {
    if ($conn && customer_has_table($conn, 'PRODUCT')) {
        $hasShop = customer_has_table($conn, 'SHOP');
        $hasCategory = customer_has_table($conn, 'CATEGORY');
        $hasDiscount = customer_has_table($conn, 'DISCOUNT');

        $imageSelect = customer_column_select($conn, 'PRODUCT', 'p', [
            'PRODUCT_IMAGE',
            'IMAGE',
            'IMAGE_URL',
            'PRODUCT_PHOTO',
            'PHOTO'
        ], 'PRODUCT_IMAGE', 'NULL');

        $descriptionSelect = customer_column_select($conn, 'PRODUCT', 'p', ['DESCRIPTION', 'PRODUCT_DESCRIPTION'], 'DESCRIPTION', 'NULL');
        $quantitySelect = customer_column_select($conn, 'PRODUCT', 'p', ['QUANTITY_PER_ITEM', 'UNIT', 'UNIT_SIZE'], 'QUANTITY_PER_ITEM', 'NULL');
        $stockSelect = customer_column_select($conn, 'PRODUCT', 'p', ['STOCK_AVAILABLE', 'STOCK', 'QUANTITY'], 'STOCK_AVAILABLE', '0');
        $minOrderSelect = customer_column_select($conn, 'PRODUCT', 'p', ['MIN_ORDER'], 'MIN_ORDER', 'NULL');
        $maxOrderSelect = customer_column_select($conn, 'PRODUCT', 'p', ['MAX_ORDER'], 'MAX_ORDER', 'NULL');
        $allergySelect = customer_column_select($conn, 'PRODUCT', 'p', ['ALLERGY_INFO'], 'ALLERGY_INFO', 'NULL');

        $shopJoin = $hasShop ? 'LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID' : '';
        $shopSelect = $hasShop
            ? "p.SHOP_ID, NVL(s.SHOP_NAME, 'Unknown shop') AS SHOP_NAME, s.LOCATION AS SHOP_LOCATION"
            : "p.SHOP_ID, 'Unknown shop' AS SHOP_NAME, NULL AS SHOP_LOCATION";

        $categoryJoin = $hasCategory ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID' : '';
        $categorySelect = $hasCategory
            ? "NVL(c.CATEGORY_NAME, 'Uncategorized') AS CATEGORY_NAME"
            : "'Uncategorized' AS CATEGORY_NAME";

        $publicProductFilter = function_exists('customer_public_product_filter')
            ? customer_public_product_filter('p', 's')
            : '1 = 1';

        $discountJoin = '';
        $discountSelect = "
                0 AS DISCOUNT_PERCENTAGE,
                NULL AS DISCOUNT_START_DATE,
                NULL AS DISCOUNT_END_DATE,
                p.ITEM_PRICE AS FINAL_PRICE,
                0 AS HAS_DISCOUNT";

        if ($hasDiscount) {
            $discountJoin = "
            LEFT JOIN (
                SELECT
                    product_id,
                    MAX(discount_percentage) AS discount_percentage,
                    MIN(start_date) AS start_date,
                    MAX(end_date) AS end_date
                FROM DISCOUNT
                WHERE discount_percentage IS NOT NULL
                  AND discount_percentage > 0
                  AND discount_percentage <= 100
                  AND start_date IS NOT NULL
                  AND end_date IS NOT NULL
                  AND end_date >= start_date
                  AND TRUNC(SYSDATE) BETWEEN TRUNC(start_date) AND TRUNC(end_date)
                GROUP BY product_id
            ) d ON d.PRODUCT_ID = p.PRODUCT_ID";

            $discountSelect = "
                NVL(d.DISCOUNT_PERCENTAGE, 0) AS DISCOUNT_PERCENTAGE,
                d.START_DATE AS DISCOUNT_START_DATE,
                d.END_DATE AS DISCOUNT_END_DATE,
                CASE
                    WHEN NVL(d.DISCOUNT_PERCENTAGE, 0) > 0 THEN
                        GREATEST(0, ROUND(p.ITEM_PRICE - (p.ITEM_PRICE * d.DISCOUNT_PERCENTAGE / 100), 2))
                    ELSE
                        p.ITEM_PRICE
                END AS FINAL_PRICE,
                CASE
                    WHEN NVL(d.DISCOUNT_PERCENTAGE, 0) > 0 THEN 1
                    ELSE 0
                END AS HAS_DISCOUNT";
        }

        // Only customer-visible products are shown: approved by admin, active, in stock, and from approved shops.
        $products = db_all($conn, "
            SELECT
                p.PRODUCT_ID,
                p.PRODUCT_NAME,
                $descriptionSelect,
                p.ITEM_PRICE,
                $quantitySelect,
                $stockSelect,
                $minOrderSelect,
                $maxOrderSelect,
                $allergySelect,
                $imageSelect,
                $shopSelect,
                $categorySelect,
                $discountSelect
            FROM PRODUCT p
            $shopJoin
            $categoryJoin
            $discountJoin
            WHERE p.ITEM_PRICE IS NOT NULL
              AND $publicProductFilter
              AND p.ITEM_PRICE > 0
            ORDER BY p.PRODUCT_ID DESC
        ");

        $categoryMap = [];
        foreach ($products as $productRow) {
            $catName = customer_text($productRow['CATEGORY_NAME'] ?? '', 'Uncategorized');
            $categoryMap[strtolower($catName)] = $catName;
        }
        $categories = array_values($categoryMap);
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    }
} catch (Throwable $e) {
    $db_error = shoplocalfy_public_exception_message($e, 'Could not load search results.');
}

if ($searchQuery !== '' && !empty($products)) {
    $tokens = preg_split('/\s+/', strtolower($searchQuery), -1, PREG_SPLIT_NO_EMPTY);

    $products = array_values(array_filter($products, function ($row) use ($tokens) {
        $productName = strtolower(customer_text($row['PRODUCT_NAME'] ?? ''));
        $shopName = strtolower(customer_text($row['SHOP_NAME'] ?? ''));
        $categoryName = strtolower(customer_text($row['CATEGORY_NAME'] ?? '', 'Uncategorized'));
        $haystack = $productName . ' ' . $shopName . ' ' . $categoryName;

        foreach ($tokens as $token) {
            if ($token !== '' && strpos($haystack, $token) !== false) {
                return true;
            }
        }

        return false;
    }));
}

$categoryMap = [];
foreach ($products as $productRow) {
    $catName = customer_text($productRow['CATEGORY_NAME'] ?? '', 'Uncategorized');
    $categoryMap[strtolower($catName)] = $catName;
}
$categories = array_values($categoryMap);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ShopLocalfy</title>

  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="../assets/customer/css/search.css?v=20260517">
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<section class="section">
  <div class="divider"></div>
  <h2 class="section-title">Search results</h2>
  <p class="section-sub">
    <?php if ($searchQuery !== '') : ?>
      Showing products matching "<?php echo e($searchQuery); ?>" in product name, shop name, or category.
    <?php else : ?>
      Type a product, shop, or category in the search bar.
    <?php endif; ?>
  </p>

  <div class="cat-bar" id="categoryBar">
    <button type="button" class="active" data-category="all">All Products</button>
    <?php foreach ($categories as $categoryName) : ?>
      <button type="button" data-category="<?php echo e(strtolower($categoryName)); ?>"><?php echo e($categoryName); ?></button>
    <?php endforeach; ?>
  </div>

  <div class="product-grid" id="productGrid">
    <?php if (!empty($products)) : ?>
      <?php foreach ($products as $row) : ?>
        <?php
          $basePrice = max(0, (float)($row['ITEM_PRICE'] ?? 0));
          $finalPrice = max(0, (float)($row['FINAL_PRICE'] ?? $basePrice));
          $discountPercent = max(0, min(100, (float)($row['DISCOUNT_PERCENTAGE'] ?? 0)));
          $hasDiscount = ((int)($row['HAS_DISCOUNT'] ?? 0) === 1) && $discountPercent > 0 && $finalPrice < $basePrice;
          $categoryName = customer_text($row['CATEGORY_NAME'] ?? '', 'Uncategorized');
          $description = customer_text($row['DESCRIPTION'] ?? '');
          $productName = customer_text($row['PRODUCT_NAME'] ?? '', 'Product');
          $shopName = customer_text($row['SHOP_NAME'] ?? '', 'Unknown shop');
          $shopLocation = customer_text($row['SHOP_LOCATION'] ?? '');
          $quantity = customer_text($row['QUANTITY_PER_ITEM'] ?? '');
          $stock = (int)($row['STOCK_AVAILABLE'] ?? 0);
          $image = customer_product_image_src($row['PRODUCT_IMAGE'] ?? '');
          $searchText = strtolower($productName . ' ' . $shopName . ' ' . $categoryName);
        ?>

        <a
          class="product-card"
          href="product-detail.php?id=<?php echo urlencode((string)$row['PRODUCT_ID']); ?>"
          data-category="<?php echo e(strtolower($categoryName)); ?>"
          data-search="<?php echo e($searchText); ?>"
          aria-label="View <?php echo e($productName); ?>"
        >
          <?php if ($hasDiscount) : ?>
            <div class="discount-badge"><?php echo e(rtrim(rtrim(number_format($discountPercent, 2), '0'), '.')); ?>% OFF</div>
          <?php endif; ?>

          <?php if ($stock <= 0) : ?>
            <div class="stock-badge">Out of stock</div>
          <?php endif; ?>

          <div class="product-img">
            <?php if ($image !== '') : ?>
              <img src="<?php echo e($image); ?>" alt="<?php echo e($productName); ?>" loading="lazy" onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';" />
            <?php else : ?>
              <span class="no-img">🛒</span>
            <?php endif; ?>
          </div>

          <div class="product-info">
            <div class="product-name"><?php echo e($productName); ?></div>
            <div class="product-shop">Sold by <?php echo e($shopName); ?></div>

            <div class="product-category">
              <?php echo e($categoryName); ?>
              <?php if ($shopLocation !== '') : ?> · <?php echo e($shopLocation); ?><?php endif; ?>
            </div>

            <?php if ($description !== '') : ?>
              <div class="product-desc"><?php echo e($description); ?></div>
            <?php endif; ?>

            <?php if ($quantity !== '') : ?>
              <div class="product-quantity">Unit: <?php echo e($quantity); ?></div>
            <?php endif; ?>

            <div class="product-stock">Stock: <?php echo e($stock); ?> available</div>

            <div class="price-row">
              <span class="product-price"><?php echo e(money_format_customer($finalPrice)); ?></span>
              <?php if ($hasDiscount) : ?>
                <span class="product-price-old"><?php echo e(money_format_customer($basePrice)); ?></span>
                <span class="discount-note">Discount applied</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php else : ?>
      <div class="no-products">
        <div class="no-products-icon">🛍️</div>
        <h3>No matching products found</h3>
        <p><?php echo $db_error ? e($db_error) : 'Search checks product name, shop name, and category only.'; ?></p>
      </div>
    <?php endif; ?>

    <div class="no-products no-search-results" id="noSearchResults">
      <div class="no-products-icon">🔎</div>
      <h3>No matching products</h3>
      <p>Try a different search term or category.</p>
    </div>
  </div>

  <?php if (!empty($products)) : ?>
    <div class="view-all">
      <span id="productCount">Showing <?php echo count($products); ?> product<?php echo count($products) === 1 ? '' : 's'; ?></span>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>

<script>
(function () {
  const slides = document.getElementById('slides');
  const dots = document.querySelectorAll('.slider-dot');
  const prevBtn = document.getElementById('sliderPrev');
  const nextBtn = document.getElementById('sliderNext');

  if (!slides) return;

  const total = dots.length || 0;
  let current = 0;
  let timer;

  if (total <= 1) return;

  function goTo(index) {
    current = (index + total) % total;
    slides.style.transform = `translateX(-${current * 100}%)`;
    dots.forEach((dot, i) => dot.classList.toggle('active', i === current));
  }

  function startAuto() {
    timer = setInterval(() => goTo(current + 1), 4500);
  }

  function stopAuto() {
    clearInterval(timer);
  }

  if (prevBtn) prevBtn.addEventListener('click', () => { stopAuto(); goTo(current - 1); startAuto(); });
  if (nextBtn) nextBtn.addEventListener('click', () => { stopAuto(); goTo(current + 1); startAuto(); });

  dots.forEach(dot => {
    dot.addEventListener('click', () => {
      stopAuto();
      goTo(parseInt(dot.dataset.index, 10));
      startAuto();
    });
  });

  let touchStartX = 0;
  slides.addEventListener('touchstart', event => { touchStartX = event.touches[0].clientX; }, { passive: true });
  slides.addEventListener('touchend', event => {
    const diff = touchStartX - event.changedTouches[0].clientX;
    if (Math.abs(diff) > 50) {
      stopAuto();
      goTo(diff > 0 ? current + 1 : current - 1);
      startAuto();
    }
  }, { passive: true });

  startAuto();
})();

(function () {
  const categoryBar = document.getElementById('categoryBar');
  const cards = Array.from(document.querySelectorAll('.product-card'));
  const productCount = document.getElementById('productCount');
  const noSearchResults = document.getElementById('noSearchResults');
  const searchInput = document.querySelector('#siteSearch, .search-input, input[type="search"], input[placeholder*="Search"], input[placeholder*="search"]');
  const currentSearchValue = <?php echo json_encode($searchQuery ?? ''); ?>;

  let selectedCategory = 'all';

  function setupSearchForm() {
    if (!searchInput) return;

    if (!searchInput.name) {
      searchInput.name = 'q';
    }

    if (currentSearchValue && searchInput.value.trim() === '') {
      searchInput.value = currentSearchValue;
    }

    let form = searchInput.closest('form');

    if (!form) {
      form = document.createElement('form');
      form.className = 'customer-search-form';
      form.method = 'GET';
      form.action = 'search.php';

      const parent = searchInput.parentNode;
      parent.insertBefore(form, searchInput);
      form.appendChild(searchInput);
    }

    form.method = 'GET';
    form.action = 'search.php';
    form.classList.add('customer-search-form');

    let button = form.querySelector('.site-search-btn');
    if (!button) {
      button = document.createElement('button');
      button.type = 'submit';
      button.className = 'site-search-btn';
      button.textContent = 'Search';
      searchInput.insertAdjacentElement('afterend', button);
    }

    form.addEventListener('submit', function (event) {
      const term = searchInput.value.trim();
      if (term === '') {
        event.preventDefault();
        searchInput.focus();
      }
    });
  }

  function applyCategoryFilter() {
    let visibleCount = 0;

    cards.forEach(card => {
      const matchesCategory = selectedCategory === 'all' || card.dataset.category === selectedCategory;
      card.style.display = matchesCategory ? '' : 'none';
      if (matchesCategory) visibleCount++;
    });

    if (productCount) {
      productCount.textContent = `Showing ${visibleCount} product${visibleCount === 1 ? '' : 's'}`;
    }

    if (noSearchResults) {
      noSearchResults.style.display = cards.length > 0 && visibleCount === 0 ? 'block' : 'none';
    }
  }

  if (categoryBar) {
    categoryBar.addEventListener('click', function (event) {
      const button = event.target.closest('button[data-category]');
      if (!button) return;

      selectedCategory = button.dataset.category;

      categoryBar.querySelectorAll('button').forEach(btn => {
        btn.classList.toggle('active', btn === button);
      });

      applyCategoryFilter();
    });
  }

  setupSearchForm();
})();
</script>

</body>
</html>

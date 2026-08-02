  <?php
  require_once __DIR__ . '/customer_common.php';

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

  try {
      if ($conn && table_exists($conn, 'PRODUCT') && table_exists($conn, 'SHOP')) {
          $imageSelect = "NULL AS PRODUCT_IMAGE";
          foreach (['PRODUCT_IMAGE', 'IMAGE', 'IMAGE_PATH', 'PRODUCT_IMAGE_PATH', 'PRODUCT_PHOTO', 'PHOTO', 'PICTURE', 'PRODUCT_PICTURE'] as $imageColumn) {
              if (column_exists($conn, 'PRODUCT', $imageColumn)) {
                  $imageSelect = 'p.' . $imageColumn . ' AS PRODUCT_IMAGE';
                  break;
              }
          }

          $hasCategory = table_exists($conn, 'CATEGORY');
          $categoryJoin = $hasCategory
              ? 'LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID'
              : '';
          $categorySelect = $hasCategory
              ? "c.CATEGORY_NAME AS CATEGORY_NAME"
              : "NULL AS CATEGORY_NAME";

          $discountJoin = '';
          $discountSelect = "
                  0 AS DISCOUNT_PERCENTAGE,
                  NULL AS DISCOUNT_START_DATE,
                  NULL AS DISCOUNT_END_DATE,
                  p.ITEM_PRICE AS FINAL_PRICE,
                  0 AS HAS_DISCOUNT";

          if (table_exists($conn, 'DISCOUNT')) {
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
                    AND end_date > start_date
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

          $reviewAvailable = table_exists($conn, 'REVIEW')
              && column_exists($conn, 'REVIEW', 'PRODUCT_ID')
              && column_exists($conn, 'REVIEW', 'RATING');

          $reviewApprovalFilter = '';
          if ($reviewAvailable && column_exists($conn, 'REVIEW', 'APPROVAL_STATUS')) {
              $reviewApprovalFilter = " AND NVL(r.APPROVAL_STATUS, 'YES') = 'YES'";
          }

          $productRatingSelect = $reviewAvailable
              ? "(SELECT NVL(ROUND(AVG(r.RATING), 1), 0) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID" . $reviewApprovalFilter . ") AS PRODUCT_RATING,
                (SELECT COUNT(r.RATING) FROM REVIEW r WHERE r.PRODUCT_ID = p.PRODUCT_ID" . $reviewApprovalFilter . ") AS PRODUCT_REVIEW_COUNT"
              : "0 AS PRODUCT_RATING,
                0 AS PRODUCT_REVIEW_COUNT";

          $publicProductFilter = function_exists('customer_public_product_filter')
              ? customer_public_product_filter('p', 's')
              : '1 = 1';

          $products = db_all($conn, "
              SELECT
                  p.PRODUCT_ID,
                  p.PRODUCT_NAME,
                  p.DESCRIPTION,
                  p.ITEM_PRICE,
                  p.QUANTITY_PER_ITEM,
                  p.STOCK_AVAILABLE,
                  p.MIN_ORDER,
                  p.MAX_ORDER,
                  p.ALLERGY_INFO,
                  $imageSelect,
                  s.SHOP_ID,
                  s.SHOP_NAME,
                  $categorySelect,
                  $discountSelect,
                  $productRatingSelect
              FROM PRODUCT p
              INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
              $categoryJoin
              $discountJoin
              WHERE p.ITEM_PRICE IS NOT NULL
                AND $publicProductFilter
                AND p.ITEM_PRICE >= 0
                AND (p.MIN_ORDER IS NULL OR p.MAX_ORDER IS NULL OR p.MIN_ORDER <= p.MAX_ORDER)
              ORDER BY p.PRODUCT_ID DESC
          ");

          if ($hasCategory) {
              $categories = db_all($conn, "
                  SELECT DISTINCT
                      c.CATEGORY_NAME AS CATEGORY_NAME
                  FROM PRODUCT p
                  INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
                  LEFT JOIN CATEGORY c ON c.CATEGORY_ID = p.CATEGORY_ID
                  WHERE p.ITEM_PRICE IS NOT NULL
                    AND $publicProductFilter
                    AND p.ITEM_PRICE >= 0
                    AND c.CATEGORY_NAME IS NOT NULL
                    AND (p.MIN_ORDER IS NULL OR p.MAX_ORDER IS NULL OR p.MIN_ORDER <= p.MAX_ORDER)
                  ORDER BY c.CATEGORY_NAME
              ");
          }
      }
  } catch (Throwable $e) {
      $db_error = shoplocalfy_public_exception_message($e, 'Could not load products.');
  }

  function money_format_customer($amount) {
      return '£' . number_format(max(0, (float)$amount), 2);
  }

  function customer_db_value($value) {
      if ((class_exists('OCILob') && $value instanceof OCILob) || (is_object($value) && method_exists($value, 'load'))) {
          $loaded = $value->load();
          return $loaded === false ? '' : $loaded;
      }

      if (is_array($value) || is_object($value)) {
          return '';
      }

      return (string)($value ?? '');
  }

  function customer_clean_product_text($value, $fallback = '') {
      $text = customer_db_value($value);
      $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = trim(strip_tags($text));

      if ($text === '') {
          return $fallback;
      }

      if (stripos($text, 'Fatal error') !== false || stripos($text, 'Stack trace') !== false || stripos($text, 'OCILob') !== false) {
          return $fallback;
      }

      return $text;
  }

  function customer_text($value, $fallback = '') {
      $text = trim((string)customer_db_value($value));
      return $text !== '' ? $text : $fallback;
  }

  function customer_detect_image_mime($bytes) {
      if ($bytes === '' || $bytes === null) {
          return '';
      }

      if (substr($bytes, 0, 2) === "\xFF\xD8") return 'image/jpeg';
      if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
      if (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a') return 'image/gif';
      if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';

      if (function_exists('finfo_open')) {
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          if ($finfo) {
              $detected = finfo_buffer($finfo, $bytes);
              finfo_close($finfo);
              if (is_string($detected) && preg_match('/^image\//', $detected)) {
                  return $detected;
              }
          }
      }

      return '';
  }

  function customer_encode_url_path($path) {
      $path = str_replace('\\', '/', (string)$path);
      if (preg_match('/^https?:\/\//i', $path) || preg_match('/^data:image\//i', $path)) {
          return $path;
      }

      $prefix = '';
      if (strpos($path, '/') === 0) {
          $prefix = '/';
          $path = ltrim($path, '/');
      }

      $parts = array_map('rawurlencode', array_filter(explode('/', $path), 'strlen'));
      return $prefix . implode('/', $parts);
  }

  function customer_file_to_url($filePath) {
      $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
      $realPath = realpath($filePath);

      if (!$docRoot || !$realPath) {
          return '';
      }

      $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
      $realPath = str_replace('\\', '/', $realPath);

      if (stripos($realPath, $docRoot) === 0) {
          $relative = substr($realPath, strlen($docRoot));
          return customer_encode_url_path('/' . ltrim($relative, '/'));
      }

      return '';
  }

  function customer_project_url_root() {
      $root = customer_file_to_url(dirname(__DIR__));
      return $root !== '' ? rtrim($root, '/') . '/' : '';
  }

  function customer_product_image_src($value) {
      $raw = customer_db_value($value);

      if ($raw === '' || $raw === null) {
          return '../uploads/products/product-placeholder.svg';
      }

      $mime = customer_detect_image_mime($raw);
      if ($mime !== '') {
          return 'data:' . $mime . ';base64,' . base64_encode($raw);
      }

      $src = trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $src = trim($src, " \t\n\r\0\x0B\"'");

      if ($src === '') {
          return '../uploads/products/product-placeholder.svg';
      }

      if (preg_match('/^data:image\//i', $src) || preg_match('/^https?:\/\//i', $src)) {
          return $src;
      }

      $compactBase64 = preg_replace('/\s+/', '', $src);
      if (strlen($compactBase64) > 80 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compactBase64)) {
          $decoded = base64_decode($compactBase64, true);
          if ($decoded !== false) {
              $decodedMime = customer_detect_image_mime($decoded);
              if ($decodedMime !== '') {
                  return 'data:' . $decodedMime . ';base64,' . base64_encode($decoded);
              }
          }
      }

      $normalized = str_replace('\\', '/', $src);
      $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? ''));

      if ($docRoot !== '' && stripos($normalized, $docRoot) === 0) {
          return customer_encode_url_path('/' . ltrim(substr($normalized, strlen($docRoot)), '/'));
      }

      $htdocsPos = stripos($normalized, '/htdocs/');
      if ($htdocsPos !== false) {
          return customer_encode_url_path('/' . substr($normalized, $htdocsPos + 8));
      }

      $baseDir = dirname(__DIR__);
      $clean = ltrim(preg_replace('#^(\.\./)+#', '', $normalized), '/');
      $baseName = basename($clean);

      $candidates = [
          __DIR__ . '/' . $normalized,
          $baseDir . '/' . $clean,
          $baseDir . '/customer/' . $clean,
          $baseDir . '/trader/' . $clean,
          $baseDir . '/uploads/' . $baseName,
          $baseDir . '/uploads/products/' . $baseName,
          $baseDir . '/product_images/' . $baseName,
          $baseDir . '/assets/images/' . $baseName,
          $baseDir . '/assets/images/products/' . $baseName,
          $baseDir . '/customer/assets/images/' . $baseName,
          $baseDir . '/customer/assets/images/products/' . $baseName,
          $baseDir . '/trader/uploads/' . $baseName,
          $baseDir . '/trader/uploads/products/' . $baseName,
          $baseDir . '/trader/assets/images/' . $baseName,
          $baseDir . '/trader/assets/images/products/' . $baseName,
      ];

      foreach ($candidates as $candidate) {
          if (is_file($candidate)) {
              $url = customer_file_to_url($candidate);
              if ($url !== '') {
                  return $url;
              }
          }
      }

      $projectRoot = customer_project_url_root();
      if ($projectRoot !== '' && preg_match('#^(trader|customer|assets|uploads)/#i', $clean)) {
          return $projectRoot . customer_encode_url_path($clean);
      }

      return '../uploads/products/product-placeholder.svg';
  }

  function customer_render_stars(float $rating, int $size = 13): string {
      $rating = max(0, min(5, $rating));
      $full = (int)floor($rating);
      $half = ($rating - $full) >= 0.5;
      $empty = 5 - $full - ($half ? 1 : 0);
      $id = 'cg' . uniqid();
      $out = '';

      for ($i = 0; $i < $full; $i++) {
          $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='#f5a623' stroke='#f5a623' stroke-width='1'><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
      }

      if ($half) {
          $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='url(#$id)' stroke='#f5a623' stroke-width='1'><defs><linearGradient id='$id'><stop offset='50%' stop-color='#f5a623'/><stop offset='50%' stop-color='transparent'/></linearGradient></defs><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
      }

      for ($i = 0; $i < $empty; $i++) {
          $out .= "<svg width='$size' height='$size' viewBox='0 0 24 24' fill='none' stroke='#f5a623' stroke-width='1'><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
      }

      return $out;
  }

  ?>

  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ShopLocalfy</title>

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="icon" href="../config/logos/favicon.ico?v=8" sizes="any">
    <link rel="icon" href="../config/logos/favicon.svg?v=8" type="image/svg+xml">
    <link rel="stylesheet" href="../assets/customer/css/index.css?v=20260517">
  </head>
  <body>

  <?php include __DIR__ . '/navbar.php'; ?>

  <!-- ─── HERO SLIDER ──────────────────────────────────────── -->

  <?php if (!empty($slider_rows)) : ?>

  <div class="slider-wrapper">

    <div class="slides" id="slides">

      <?php foreach ($slider_rows as $slide) : ?>

        <div class="slide">

          <img
            src="<?php echo e($slide['IMAGE'] ?? ''); ?>"
            alt="<?php echo e($slide['TITLE'] ?? ''); ?>"
            onerror="this.onerror=null;this.src='../uploads/products/product-placeholder.svg';"
          />

          <?php if (!empty($slide['TITLE'])) : ?>
            <div class="slide-caption">
              <h3><?php echo e($slide['TITLE']); ?></h3>
              <?php if (!empty($slide['SUBTITLE'])) : ?>
                <p><?php echo e($slide['SUBTITLE']); ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </div>

      <?php endforeach; ?>

    </div><!-- .slides -->

    <?php if (count($slider_rows) > 1) : ?>

      <button class="slider-btn prev" id="sliderPrev" aria-label="Previous slide">&#8592;</button>
      <button class="slider-btn next" id="sliderNext" aria-label="Next slide">&#8594;</button>

      <div class="slider-dots" id="sliderDots">
        <?php foreach ($slider_rows as $i => $slide) : ?>
          <button
            class="slider-dot <?php echo $i === 0 ? 'active' : ''; ?>"
            data-index="<?php echo $i; ?>"
            aria-label="Go to slide <?php echo $i + 1; ?>"
          ></button>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

  </div><!-- .slider-wrapper -->

  <?php else : ?>

    <div class="slider-empty">
      <div class="empty-icon">🖼️</div>
      <h3>No banner images yet</h3>
      <p>Add slides from the admin panel to display them here.</p>
    </div>

  <?php endif; ?>

  <!-- ─── PRODUCTS SECTION ─────────────────────────────────── -->

  <section class="section">

    <div class="divider"></div>

    <h2 class="section-title">Discover our products</h2>

    <p class="section-sub">
      Browse fresh products from approved local traders.

    <div class="cat-bar" id="categoryBar">
      <button type="button" class="active" data-category="all">All Products</button>
      <?php foreach ($categories as $cat) : ?>
        <?php $catName = customer_text($cat['CATEGORY_NAME'] ?? ''); ?>
        <?php if ($catName !== '') : ?>
          <button type="button" data-category="<?php echo e(strtolower($catName)); ?>">
            <?php echo e($catName); ?>
          </button>
        <?php endif; ?>
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
            $categoryName = customer_text($row['CATEGORY_NAME'] ?? '', '');
            $description = customer_clean_product_text($row['DESCRIPTION'] ?? '', '');
            $image = customer_product_image_src($row['PRODUCT_IMAGE'] ?? '');
            $productId = customer_text($row['PRODUCT_ID'] ?? '', '');
            $shopId = customer_text($row['SHOP_ID'] ?? '', '');
            $shopName = customer_text($row['SHOP_NAME'] ?? '', 'Unknown shop');
            $productUrl = 'product-detail.php?id=' . rawurlencode($productId);
            $shopUrl = 'shop_details.php?id=' . rawurlencode($shopId);
            $productRating = (float)($row['PRODUCT_RATING'] ?? 0);
            $productReviewCount = (int)($row['PRODUCT_REVIEW_COUNT'] ?? 0);
          ?>

          <div
            class="product-card"
            role="link"
            tabindex="0"
            data-href="<?php echo e($productUrl); ?>"
            data-category="<?php echo e(strtolower($categoryName)); ?>"
            onclick="window.location=this.dataset.href;"
            onkeydown="if(event.key === 'Enter' || event.key === ' '){event.preventDefault(); window.location=this.dataset.href;}"
          >

            <?php if ($hasDiscount) : ?>
              <div class="discount-badge"><?php echo e(rtrim(rtrim(number_format($discountPercent, 2), '0'), '.')); ?>% OFF</div>
            <?php endif; ?>

            <div class="product-img">
              <?php if ($image !== '') : ?>
                <img
                  src="<?php echo e($image); ?>"
                  alt="<?php echo e(customer_text($row['PRODUCT_NAME'] ?? '', 'Product')); ?>"
                  loading="lazy"
                  onerror="this.style.display='none'; var p=this.parentElement.querySelector('.no-img'); if(p){p.style.display='inline';}"
                />
              <?php endif; ?>
              <span class="no-img" style="<?php echo $image !== '' ? 'display:none;' : ''; ?>">🛒</span>
            </div>

            <div class="product-info">

              <div class="product-name">
                <?php echo e(customer_text($row['PRODUCT_NAME'] ?? '', 'Product')); ?>
              </div>

              <div class="product-shop">
                Sold by
                <?php if ($shopId !== '') : ?>
                  <a class="shop-link" href="<?php echo e($shopUrl); ?>" onclick="event.stopPropagation();" onkeydown="event.stopPropagation();">
                    <?php echo e($shopName); ?>
                  </a>
                <?php else : ?>
                  <?php echo e($shopName); ?>
                <?php endif; ?>
              </div>

              <div class="product-rating">
                <span class="stars"><?php echo customer_render_stars($productRating, 13); ?></span>
                <span><?php echo e(number_format($productRating, 1)); ?> (<?php echo e($productReviewCount); ?> review<?php echo $productReviewCount === 1 ? '' : 's'; ?>)</span>
              </div>

              <?php if ($categoryName !== '') : ?>
                <div class="product-category">
                  <?php echo e($categoryName); ?>
                </div>
              <?php endif; ?>

              <?php if ($description !== '') : ?>
                <div class="product-desc">
                  <?php echo e($description); ?>
                </div>
              <?php endif; ?>

              <div class="price-row">
                <span class="product-price"><?php echo e(money_format_customer($finalPrice)); ?></span>
                <?php if ($hasDiscount) : ?>
                  <span class="product-price-old"><?php echo e(money_format_customer($basePrice)); ?></span>
                  <span class="discount-note">Discount applied</span>
                <?php endif; ?>
              </div>

            </div>

          </div>

        <?php endforeach; ?>

      <?php else : ?>

        <div class="no-products">
          <div class="no-products-icon">🛍️</div>
          <h3>No products have been added yet</h3>
          <p><?php echo $db_error ? e($db_error) : "We're stocking up! Check back soon for fresh products."; ?></p>
        </div>

      <?php endif; ?>

    </div><!-- .product-grid -->

    <?php if (!empty($products)) : ?>

    <?php endif; ?>

  </section>

  <?php include __DIR__ . '/footer.php'; ?>

  <!-- ─── JAVASCRIPT ─────────────────────────────────── -->

  <script src="../assets/customer/js/index.js?v=20260517"></script>

  </body>
  </html>

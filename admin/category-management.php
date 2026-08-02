<?php

require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();

date_default_timezone_set('Asia/Kathmandu');

$conn = admin_db_connection();
$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');
$readCategoryId = trim($_GET['read'] ?? '');
$editCategoryId = trim($_GET['edit'] ?? '');

function admin_category_ready($conn) {
    return $conn && table_exists($conn, 'CATEGORY') && column_exists($conn, 'CATEGORY', 'CATEGORY_ID');
}

function admin_category_name_column($conn) {
    if (!$conn || !table_exists($conn, 'CATEGORY')) return null;
    if (column_exists($conn, 'CATEGORY', 'CATEGORY_NAME')) return 'CATEGORY_NAME';
    if (column_exists($conn, 'CATEGORY', 'NAME')) return 'NAME';
    return null;
}

function admin_category_description_column($conn) {
    if (!$conn || !table_exists($conn, 'CATEGORY')) return null;
    foreach (['DESCRIPTION', 'CATEGORY_DESCRIPTION'] as $column) {
        if (column_exists($conn, 'CATEGORY', $column)) return $column;
    }
    return null;
}

function admin_category_image_column($conn) {
    if (!$conn || !table_exists($conn, 'CATEGORY')) return null;
    foreach (['CATEGORY_IMAGE', 'IMAGE_PATH', 'IMAGE'] as $column) {
        if (column_exists($conn, 'CATEGORY', $column)) return $column;
    }
    return null;
}

function admin_category_upload_dir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'category';
}

function admin_category_image_src($imagePath) {
    $placeholder = '../uploads/products/product-placeholder.svg';
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') return $placeholder;

    $imagePath = str_replace('\\', '/', $imagePath);

    if (preg_match('/^(https?:)?\/\//i', $imagePath) || str_starts_with($imagePath, 'data:')) {
        return $imagePath;
    }

    if (str_starts_with($imagePath, '../')) {
        $checkPath = dirname(__DIR__) . '/' . ltrim(substr($imagePath, 3), '/');
        return is_file($checkPath) ? $imagePath : $placeholder;
    }

    if (str_starts_with($imagePath, '/')) {
        return $imagePath;
    }

    $relative = ltrim($imagePath, '/');
    $checkPath = dirname(__DIR__) . '/' . $relative;
    return is_file($checkPath) ? '../' . $relative : $placeholder;
}

function admin_category_upload_image($fieldName = 'category_image') {
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return null;

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Category image upload failed. Please choose the image again.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Category image must be 5MB or smaller.');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Category image must be JPG, PNG, WEBP, or GIF.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid category image upload.');
    }

    $allowedMime = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif'
    ];

    $mime = '';
    if (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($tmpName);
    }

    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Category image file type is not allowed.');
    }

    $uploadDir = admin_category_upload_dir();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('Could not create uploads/category folder.');
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('uploads/category folder is not writable.');
    }

    $safeName = 'category_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save category image.');
    }

    return 'uploads/category/' . $safeName;
}

function admin_category_delete_image($imagePath) {
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') return;

    $normalised = str_replace('\\', '/', $imagePath);

    if (preg_match('/^(https?:)?\/\//i', $normalised) || str_starts_with($normalised, 'data:')) {
        return;
    }

    $fileName = basename($normalised);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') return;

    $fullPath = admin_category_upload_dir() . DIRECTORY_SEPARATOR . $fileName;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function admin_category_column_info($conn, $column) {
    return db_one($conn, "
        SELECT DATA_TYPE, DATA_LENGTH
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = 'CATEGORY'
          AND COLUMN_NAME = UPPER(:column_name)
    ", [':column_name' => $column]) ?: [];
}

function admin_category_next_id($conn) {
    $info = admin_category_column_info($conn, 'CATEGORY_ID');
    $type = strtoupper((string)($info['DATA_TYPE'] ?? 'VARCHAR2'));

    if (str_contains($type, 'NUMBER')) {
        $row = db_one($conn, 'SELECT NVL(MAX(CATEGORY_ID), 0) + 1 AS NEXT_ID FROM CATEGORY');
        return (string)($row['NEXT_ID'] ?? '1');
    }

    $length = max(2, (int)($info['DATA_LENGTH'] ?? 10));
    $pad = max(1, $length - 1);
    $row = db_one($conn, "
        SELECT NVL(MAX(TO_NUMBER(REGEXP_SUBSTR(CATEGORY_ID, '[0-9]+$'))), 0) + 1 AS NEXT_NUM
        FROM CATEGORY
        WHERE REGEXP_LIKE(CATEGORY_ID, '^[A-Za-z]+[0-9]+$')
    ");

    $num = (int)($row['NEXT_NUM'] ?? 1);
    do {
        $candidate = 'C' . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
        $exists = db_one($conn, 'SELECT CATEGORY_ID FROM CATEGORY WHERE CATEGORY_ID = :category_id', [':category_id' => $candidate]);
        $num++;
    } while ($exists);

    return $candidate;
}

function admin_redirect_category($message = '', $error = '', $extra = '') {
    $url = 'category-management.php';
    $params = [];
    if ($message !== '') $params['success'] = $message;
    if ($error !== '') $params['error'] = $error;
    if ($params) $url .= '?' . http_build_query($params);
    if ($extra !== '') $url .= ($params ? '&' : '?') . ltrim($extra, '?&');
    header('Location: ' . $url);
    exit;
}

function admin_product_count_for_category($conn, $categoryId) {
    if (!$conn || !table_exists($conn, 'PRODUCT') || !column_exists($conn, 'PRODUCT', 'CATEGORY_ID')) return 0;
    $row = db_one($conn, 'SELECT COUNT(*) AS TOTAL FROM PRODUCT WHERE CATEGORY_ID = :category_id', [':category_id' => $categoryId]);
    return (int)($row['TOTAL'] ?? 0);
}
function admin_default_category_id($conn) {
    $fallbackId = 'CAT0000000';
    if (!$conn || !admin_category_ready($conn)) return $fallbackId;

    try {
        $exists = db_one($conn, 'SELECT CATEGORY_ID FROM CATEGORY WHERE CATEGORY_ID = :category_id', [':category_id' => $fallbackId]);
        if (!$exists) {
            $nameCol = admin_category_name_column($conn) ?: 'CATEGORY_NAME';
            $descCol = admin_category_description_column($conn);
            $imageCol = admin_category_image_column($conn);
            $cols = ['CATEGORY_ID', $nameCol];
            $vals = [':category_id', ':category_name'];
            $binds = [':category_id' => $fallbackId, ':category_name' => 'Others'];
            if ($descCol) {
                $cols[] = $descCol;
                $vals[] = ':description';
                $binds[':description'] = 'Default fallback category for products without a specific category.';
            }
            if ($imageCol) {
                $cols[] = $imageCol;
                $vals[] = 'NULL';
            }
            db_bind_and_execute($conn, 'INSERT INTO CATEGORY (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')', $binds);
        }
    } catch (Throwable $e) {
        // If the fallback insert fails, return the ID anyway so the caller can fail clearly on FK validation.
    }

    return $fallbackId;
}


function admin_get_categories($conn) {
    if (!admin_category_ready($conn)) return [];
    $nameCol = admin_category_name_column($conn);
    if (!$nameCol) return [];
    $descCol = admin_category_description_column($conn);
    $descSelect = $descCol ? "$descCol AS DESCRIPTION" : "'' AS DESCRIPTION";
    $imageCol = admin_category_image_column($conn);
    $imageSelect = $imageCol ? "c.$imageCol AS CATEGORY_IMAGE" : "'' AS CATEGORY_IMAGE";

    return db_all($conn, "
        SELECT c.CATEGORY_ID, c.$nameCol AS CATEGORY_NAME, $descSelect, $imageSelect,
               (SELECT COUNT(*) FROM PRODUCT p WHERE p.CATEGORY_ID = c.CATEGORY_ID) AS PRODUCT_COUNT
        FROM CATEGORY c
        ORDER BY c.$nameCol
    ");
}

function admin_get_category($conn, $categoryId) {
    if (!admin_category_ready($conn) || $categoryId === '') return null;
    $nameCol = admin_category_name_column($conn);
    if (!$nameCol) return null;
    $descCol = admin_category_description_column($conn);
    $descSelect = $descCol ? "$descCol AS DESCRIPTION" : "'' AS DESCRIPTION";
    $imageCol = admin_category_image_column($conn);
    $imageSelect = $imageCol ? "$imageCol AS CATEGORY_IMAGE" : "'' AS CATEGORY_IMAGE";

    return db_one($conn, "
        SELECT CATEGORY_ID, $nameCol AS CATEGORY_NAME, $descSelect, $imageSelect
        FROM CATEGORY
        WHERE CATEGORY_ID = :category_id
    ", [':category_id' => $categoryId]);
}

function admin_get_category_products($conn, $categoryId) {
    if (!$conn || $categoryId === '' || !table_exists($conn, 'PRODUCT') || !column_exists($conn, 'PRODUCT', 'CATEGORY_ID')) return [];

    $select = ['p.PRODUCT_ID'];
    $select[] = 'p.CATEGORY_ID';
    $select[] = column_exists($conn, 'PRODUCT', 'PRODUCT_NAME') ? 'p.PRODUCT_NAME' : "p.PRODUCT_ID AS PRODUCT_NAME";
    $select[] = column_exists($conn, 'PRODUCT', 'ITEM_PRICE') ? 'p.ITEM_PRICE' : '0 AS ITEM_PRICE';
    $select[] = column_exists($conn, 'PRODUCT', 'STOCK_AVAILABLE') ? 'p.STOCK_AVAILABLE' : '0 AS STOCK_AVAILABLE';

    $join = '';
    if (table_exists($conn, 'SHOP') && column_exists($conn, 'PRODUCT', 'SHOP_ID') && column_exists($conn, 'SHOP', 'SHOP_ID') && column_exists($conn, 'SHOP', 'SHOP_NAME')) {
        $select[] = 's.SHOP_NAME';
        $join = ' LEFT JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID';
    } else {
        $select[] = "'' AS SHOP_NAME";
    }

    return db_all($conn, '
        SELECT ' . implode(', ', $select) . '
        FROM PRODUCT p' . $join . '
        WHERE p.CATEGORY_ID = :category_id
        ORDER BY p.PRODUCT_NAME
    ', [':category_id' => $categoryId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if (!admin_category_ready($conn)) throw new RuntimeException('CATEGORY table or CATEGORY_ID column was not found.');

        $nameCol = admin_category_name_column($conn);
        if (!$nameCol) throw new RuntimeException('CATEGORY_NAME column was not found.');
        $descCol = admin_category_description_column($conn);
        $imageCol = admin_category_image_column($conn);

        if ($action === 'change_product_category') {
            $productId = trim($_POST['product_id'] ?? '');
            $newCategoryId = trim($_POST['new_category_id'] ?? '');
            $currentReadCategoryId = trim($_POST['current_read_category_id'] ?? '');

            if ($productId === '') throw new RuntimeException('Product ID is missing.');
            if (!table_exists($conn, 'PRODUCT') || !column_exists($conn, 'PRODUCT', 'PRODUCT_ID') || !column_exists($conn, 'PRODUCT', 'CATEGORY_ID')) {
                throw new RuntimeException('PRODUCT table, PRODUCT_ID column, or CATEGORY_ID column was not found.');
            }

            $productExists = db_one($conn, 'SELECT PRODUCT_ID FROM PRODUCT WHERE PRODUCT_ID = :product_id', [':product_id' => $productId]);
            if (!$productExists) throw new RuntimeException('Product was not found.');

            if ($newCategoryId !== '') {
                $targetCategory = admin_get_category($conn, $newCategoryId);
                if (!$targetCategory) throw new RuntimeException('Selected category was not found.');

                db_bind_and_execute(
                    $conn,
                    'UPDATE PRODUCT SET CATEGORY_ID = :new_category_id WHERE PRODUCT_ID = :product_id',
                    [':new_category_id' => $newCategoryId, ':product_id' => $productId]
                );

                admin_redirect_category('Product category updated successfully.', '', 'read=' . urlencode($newCategoryId));
            }

            $fallbackCategoryId = admin_default_category_id($conn);
            db_bind_and_execute(
                $conn,
                'UPDATE PRODUCT SET CATEGORY_ID = :fallback_category_id WHERE PRODUCT_ID = :product_id',
                [':fallback_category_id' => $fallbackCategoryId, ':product_id' => $productId]
            );

            admin_redirect_category('Product moved to Others category successfully.', '', 'read=' . urlencode($fallbackCategoryId));
        }

        if ($action === 'create') {
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if ($name === '') throw new RuntimeException('Category name is required.');

            $columns = [$nameCol];
            $values = [':category_name'];
            $binds = [':category_name' => $name];

            if ($descCol) {
                $columns[] = $descCol;
                $values[] = ':description';
                $binds[':description'] = $description;
            }

            $uploadedImage = admin_category_upload_image('category_image');
            if ($uploadedImage !== null) {
                if (!$imageCol) {
                    admin_category_delete_image($uploadedImage);
                    throw new RuntimeException('CATEGORY_IMAGE column was not found. Run the ALTER TABLE query first.');
                }
                $columns[] = $imageCol;
                $values[] = ':category_image';
                $binds[':category_image'] = $uploadedImage;
            }

            db_bind_and_execute($conn, 'INSERT INTO CATEGORY (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')', $binds);
            admin_redirect_category('Category created successfully.');
        }

        if ($action === 'update') {
            $categoryId = trim($_POST['category_id'] ?? '');
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if ($categoryId === '') throw new RuntimeException('Category ID is missing.');
            if ($name === '') throw new RuntimeException('Category name is required.');

            $currentCategory = admin_get_category($conn, $categoryId);
            $oldImage = (string)($currentCategory['CATEGORY_IMAGE'] ?? '');

            $sets = ["$nameCol = :category_name"];
            $binds = [':category_name' => $name, ':category_id' => $categoryId];
            if ($descCol) {
                $sets[] = "$descCol = :description";
                $binds[':description'] = $description;
            }

            $uploadedImage = admin_category_upload_image('category_image');
            $removeCurrentImage = isset($_POST['remove_category_image']) && $_POST['remove_category_image'] === '1';

            if ($uploadedImage !== null) {
                if (!$imageCol) {
                    admin_category_delete_image($uploadedImage);
                    throw new RuntimeException('CATEGORY_IMAGE column was not found. Run the ALTER TABLE query first.');
                }
                $sets[] = "$imageCol = :category_image";
                $binds[':category_image'] = $uploadedImage;
            } elseif ($removeCurrentImage) {
                if (!$imageCol) throw new RuntimeException('CATEGORY_IMAGE column was not found. Run the ALTER TABLE query first.');
                $sets[] = "$imageCol = NULL";
            }

            db_bind_and_execute($conn, 'UPDATE CATEGORY SET ' . implode(', ', $sets) . ' WHERE CATEGORY_ID = :category_id', $binds);

            if ($uploadedImage !== null || $removeCurrentImage) {
                admin_category_delete_image($oldImage);
            }

            admin_redirect_category('Category updated successfully.');
        }

        if ($action === 'delete') {
            $categoryId = trim($_POST['category_id'] ?? '');
            if ($categoryId === '') throw new RuntimeException('Category ID is missing.');
            if ($categoryId === admin_default_category_id($conn)) {
                throw new RuntimeException('The Others fallback category cannot be deleted.');
            }
            $productCount = admin_product_count_for_category($conn, $categoryId);
            $fallbackCategoryId = admin_default_category_id($conn);
            $categoryForDelete = admin_get_category($conn, $categoryId);
            $imageForDelete = (string)($categoryForDelete['CATEGORY_IMAGE'] ?? '');

            if ($productCount > 0) {
                db_bind_and_execute(
                    $conn,
                    'UPDATE PRODUCT SET CATEGORY_ID = :fallback_category_id WHERE CATEGORY_ID = :category_id',
                    [':fallback_category_id' => $fallbackCategoryId, ':category_id' => $categoryId]
                );
            }

            db_bind_and_execute($conn, 'DELETE FROM CATEGORY WHERE CATEGORY_ID = :category_id', [':category_id' => $categoryId]);
            admin_category_delete_image($imageForDelete);
            admin_redirect_category($productCount > 0 ? 'Category deleted. Existing products were moved to Others.' : 'Category deleted successfully.');
        }
    } catch (Throwable $e) {
        admin_redirect_category('', shoplocalfy_public_exception_message($e, 'Could not update category.'));
    }
}

$categories = [];
$editCategory = null;
$readCategory = null;
$categoryProducts = [];

try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    if (!admin_category_ready($conn)) throw new RuntimeException('CATEGORY table or CATEGORY_ID column was not found.');
    if (!admin_category_name_column($conn)) throw new RuntimeException('CATEGORY_NAME column was not found.');

    $categories = admin_get_categories($conn);
    if ($editCategoryId !== '') $editCategory = admin_get_category($conn, $editCategoryId);
    if ($readCategoryId !== '') {
        $readCategory = admin_get_category($conn, $readCategoryId);
        $categoryProducts = admin_get_category_products($conn, $readCategoryId);
    }
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load categories.');
}

$totalCategories = count($categories);
$totalProducts = 0;
foreach ($categories as $category) {
    $totalProducts += (int)($category['PRODUCT_COUNT'] ?? 0);
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy - Category Management</title>
<link rel="stylesheet" href="../assets/admin/css/category-management.css?v=20260517">
  
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    

  
</head>
<body>

<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <div class="page-body">
      <div class="page-heading">
        <h1 class="page-title">Category Management</h1>
      </div>

      <?php if ($message !== ''): ?><div class="message-box success"><?= e($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="message-box error"><?= e($error) ?></div><?php endif; ?>

      <div class="stat-cards" style="grid-template-columns:repeat(3,1fr);margin-bottom:22px;">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div><div class="stat-info"><span class="stat-label">Total Categories</span>                          <span class="stat-value"><?= e($totalCategories) ?> </span></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-boxes-stacked"></i></div><div class="stat-info"><span class="stat-label">Products Assigned</span><span class="stat-value"><?= e($totalProducts) ?></span></div></div>
        <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-eye"></i></div><div class="stat-info"><span class="stat-label">Reading</span><span class="stat-value"><?= $readCategory ? e($readCategory['CATEGORY_NAME']) : '—' ?></span></div></div>
      </div>

      <div class="category-layout">
        <div class="card category-create-card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-folder-plus"></i><?= $editCategory ? 'Edit Category' : 'Create Category' ?></span></div>
          <div class="card-body" style="padding:22px;">
            <form method="POST" enctype="multipart/form-data" class="category-form" style="display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:18px;align-items:start;width:100%;">
              <input type="hidden" name="action" value="<?= $editCategory ? 'update' : 'create' ?>"/>
              <?php if ($editCategory): ?><input type="hidden" name="category_id" value="<?= e($editCategory['CATEGORY_ID']) ?>"/><?php endif; ?>
              <div class="form-row" style="display:flex!important;flex-direction:column!important;gap:8px!important;margin:0!important;">
                <label style="display:block!important;font-size:11px;font-weight:900;color:#6b9e88;text-transform:uppercase;letter-spacing:.07em;margin:0!important;">Category Name</label>
                <input style="width:100%;height:50px;border:1.5px solid #d6ece2;border-radius:14px;background:#fff;padding:0 14px;font-family:inherit;font-size:13px;font-weight:700;color:#1a3d2e;outline:none;" type="text" name="category_name" value="<?= e($editCategory['CATEGORY_NAME'] ?? '') ?>" required/>
              </div>
              <div class="form-row" style="display:flex!important;flex-direction:column!important;gap:8px!important;margin:0!important;">
                <label style="display:block!important;font-size:11px;font-weight:900;color:#6b9e88;text-transform:uppercase;letter-spacing:.07em;margin:0!important;">Category Image</label>
                <input style="width:100%;height:50px;border:1.5px solid #d6ece2;border-radius:14px;background:#fff;padding:11px 14px;font-family:inherit;font-size:13px;font-weight:700;color:#1a3d2e;outline:none;" type="file" name="category_image" accept="image/png,image/jpeg,image/webp,image/gif"/>
                <?php if ($editCategory && !empty($editCategory['CATEGORY_IMAGE'])): ?>
                  <div class="current-image-wrap">
                    <img src="<?= e(admin_category_image_src($editCategory['CATEGORY_IMAGE'])) ?>" alt="<?= e($editCategory['CATEGORY_NAME'] ?? 'Category image') ?>">
                    <label class="remove-image-check"><input type="checkbox" name="remove_category_image" value="1"> Remove current image</label>
                  </div>
                <?php endif; ?>
              </div>
              <div class="form-row" style="display:flex!important;flex-direction:column!important;gap:8px!important;margin:0!important;grid-column:1 / -1;">
                <label style="display:block!important;font-size:11px;font-weight:900;color:#6b9e88;text-transform:uppercase;letter-spacing:.07em;margin:0!important;">Description</label>
                <textarea style="width:100%;min-height:90px;border:1.5px solid #d6ece2;border-radius:14px;background:#fff;padding:12px 14px;font-family:inherit;font-size:13px;font-weight:700;color:#1a3d2e;outline:none;resize:vertical;" name="description" placeholder="Optional short note"><?= e($editCategory['DESCRIPTION'] ?? '') ?></textarea>
              </div>
              <div class="form-actions" style="grid-column:1 / -1;display:flex!important;gap:10px;align-items:center;justify-content:flex-start;margin:0!important;padding-top:6px!important;border-top:0!important;">
                <button class="btn-small btn-primary" style="height:46px;padding:0 18px;border-radius:13px;" type="submit"><i class="fa-solid fa-floppy-disk"></i><?= $editCategory ? 'Update Category' : 'Create Category' ?></button>
                <?php if ($editCategory): ?><a class="btn-small btn-light" href="category-management.php">Cancel</a><?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <div class="card category-table-card" style="margin-top:4px;">
          <div class="card-header"><span class="card-title">All Categories</span></div>
          <div class="card-body" style="padding:16px;overflow-x:auto;">
            <table class="data-table">
              <thead><tr><th>Image</th><th>Category</th><th>Description</th><th>Products</th><th>Actions</th></tr></thead>
              <tbody>
              <?php if (!$categories): ?>
                <tr class="empty-row"><td colspan="5"><span class="empty-text">No categories found</span></td></tr>
              <?php else: ?>
                <?php foreach ($categories as $category): ?>
                  <tr>
                    <td>
                      <?php if (!empty($category['CATEGORY_IMAGE'])): ?>
                        <img class="category-thumb" src="<?= e(admin_category_image_src($category['CATEGORY_IMAGE'])) ?>" alt="<?= e($category['CATEGORY_NAME']) ?>">
                      <?php else: ?>
                        <span class="category-thumb-placeholder"><i class="fa-solid fa-image"></i></span>
                      <?php endif; ?>
                    </td>
                    <td><span class="category-name-cell"><?= e($category['CATEGORY_NAME']) ?></span><br><small><?= e($category['CATEGORY_ID']) ?></small></td>
                    <td><?= e($category['DESCRIPTION'] ?: '—') ?></td>
                    <td><span class="count-pill"><?= e($category['PRODUCT_COUNT']) ?></span></td>
                    <td>
                      <div class="inline-actions">
                        <a class="btn-small btn-light" href="category-management.php?read=<?= urlencode($category['CATEGORY_ID']) ?>"><i class="fa-solid fa-eye"></i>Read</a>
                        <a class="btn-small btn-light" href="category-management.php?edit=<?= urlencode($category['CATEGORY_ID']) ?>"><i class="fa-solid fa-pen"></i>Edit</a>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this category? Products under it will be moved to Others.');">
                          <input type="hidden" name="action" value="delete"/>
                          <input type="hidden" name="category_id" value="<?= e($category['CATEGORY_ID']) ?>"/>
                          <button class="btn-small btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card read-panel">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-arrow-right-arrow-left" style="margin-right:8px;"></i>Products Under Category<?= $readCategory ? ': ' . e($readCategory['CATEGORY_NAME']) : '' ?></span></div>
        <div class="card-body" style="padding:16px;overflow-x:auto;">
          <?php if (!$readCategory): ?>
            <div class="empty-note">Click Read beside a category to show its products.</div>
          <?php else: ?>
            <table class="data-table">
              <thead><tr><th>Product ID</th><th>Product Name</th><th>Shop</th><th>Price</th><th>Stock</th><th>Change Category</th></tr></thead>
              <tbody>
              <?php if (!$categoryProducts): ?>
                <tr class="empty-row"><td colspan="6"><span class="empty-text">No products found under this category</span></td></tr>
              <?php else: ?>
                <?php foreach ($categoryProducts as $product): ?>
                  <tr>
                    <td><?= e($product['PRODUCT_ID']) ?></td>
                    <td class="category-name-cell"><?= e($product['PRODUCT_NAME']) ?></td>
                    <td><?= e($product['SHOP_NAME'] ?: '—') ?></td>
                    <td><?= e(admin_money($product['ITEM_PRICE'] ?? 0)) ?></td>
                    <td><?= e($product['STOCK_AVAILABLE']) ?></td>
                    <td>
                      <form method="POST" class="product-category-form">
                        <input type="hidden" name="action" value="change_product_category"/>
                        <input type="hidden" name="product_id" value="<?= e($product['PRODUCT_ID']) ?>"/>
                        <input type="hidden" name="current_read_category_id" value="<?= e($readCategory['CATEGORY_ID'] ?? '') ?>"/>
                        <select name="new_category_id" aria-label="Change product category">
                          <option value="CAT0000000" <?= ((string)($product['CATEGORY_ID'] ?? '') === 'CAT0000000') ? 'selected' : '' ?>>Others</option>
                          <?php foreach ($categories as $categoryOption): ?>
                            <?php if ((string)$categoryOption['CATEGORY_ID'] === 'CAT0000000') continue; ?>
                            <option value="<?= e($categoryOption['CATEGORY_ID']) ?>" <?= ((string)($product['CATEGORY_ID'] ?? '') === (string)$categoryOption['CATEGORY_ID']) ? 'selected' : '' ?>>
                              <?= e($categoryOption['CATEGORY_NAME']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn-small btn-primary" type="submit"><i class="fa-solid fa-rotate"></i>Save</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>

<script src="../assets/admin/js/category-management.js?v=20260517"></script>
</body>
</html>
<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$pendingCount = get_pending_order_count($conn, $traderId);
$errors = [];
$flash = '';

function table_columns_meta($conn, $tableName) {
    if (!$conn) return [];
    try {
        $rows = db_all($conn, '
            SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE, DATA_DEFAULT
            FROM USER_TAB_COLUMNS
            WHERE TABLE_NAME = UPPER(:table_name)
            ORDER BY COLUMN_ID
        ', [':table_name' => $tableName]);
        $out = [];
        foreach ($rows as $r) {
            $out[strtoupper($r['COLUMN_NAME'])] = $r;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function pick_col($cols, $candidates) {
    foreach ($candidates as $c) {
        $u = strtoupper($c);
        if (isset($cols[$u])) return $u;
    }
    return null;
}

function date_expr($alias, $col, $as, $cols) {
    if (!$col) return "NULL AS {$as}";
    $type = strtoupper($cols[$col]['DATA_TYPE'] ?? '');
    if (str_contains($type, 'DATE') || str_contains($type, 'TIMESTAMP')) {
        return "TO_CHAR({$alias}.{$col}, 'YYYY-MM-DD') AS {$as}";
    }
    return "{$alias}.{$col} AS {$as}";
}

function discount_config($conn) {
    $cols = table_columns_meta($conn, 'DISCOUNT');
    return [
        'cols' => $cols,
        'id' => pick_col($cols, ['DISCOUNT_ID', 'OFFER_ID', 'ID']),
        'name' => pick_col($cols, ['DISCOUNT_NAME', 'NAME', 'TITLE']),
        'type' => pick_col($cols, ['DISCOUNT_TYPE', 'TYPE']),
        'value' => pick_col($cols, ['DISCOUNT_VALUE', 'VALUE', 'PERCENTAGE', 'DISCOUNT_PERCENTAGE']),
        'product' => pick_col($cols, ['PRODUCT_ID']),
        'shop' => pick_col($cols, ['SHOP_ID']),
        'trader' => pick_col($cols, ['TRADER_ID']),
        'start' => pick_col($cols, ['START_DATE', 'VALID_FROM', 'DATE_FROM']),
        'end' => pick_col($cols, ['END_DATE', 'VALID_TO', 'EXPIRY_DATE', 'EXPIRES_AT']),
        'status' => pick_col($cols, ['STATUS', 'IS_ACTIVE', 'ACTIVE_STATUS']),
    ];
}

function get_trader_products_for_discount($conn, $traderId) {
    if (!$conn || !table_exists($conn, 'PRODUCT') || !table_exists($conn, 'SHOP')) return [];
    try {
        return db_all($conn, '
            SELECT p.PRODUCT_ID, p.PRODUCT_NAME, p.SHOP_ID, s.SHOP_NAME
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            WHERE s.TRADER_ID = :trader_id
            ORDER BY p.PRODUCT_NAME
        ', [':trader_id' => $traderId]);
    } catch (Throwable $e) {
        return [];
    }
}

function get_product_for_trader($conn, $traderId, $productId) {
    if (!$conn || !$productId || !table_exists($conn, 'PRODUCT') || !table_exists($conn, 'SHOP')) return null;
    try {
        return db_one($conn, '
            SELECT p.PRODUCT_ID, p.SHOP_ID
            FROM PRODUCT p
            INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID
            WHERE p.PRODUCT_ID = :product_id AND s.TRADER_ID = :trader_id
        ', [':product_id' => $productId, ':trader_id' => $traderId]);
    } catch (Throwable $e) {
        return null;
    }
}

function uses_trader_bind($cfg) {
    return (bool)($cfg['trader'] || $cfg['shop'] || $cfg['product']);
}

function discount_owner_condition($cfg) {
    if ($cfg['trader']) {
        return "d.{$cfg['trader']} = :trader_id";
    }
    if ($cfg['shop']) {
        return "EXISTS (SELECT 1 FROM SHOP s WHERE s.SHOP_ID = d.{$cfg['shop']} AND s.TRADER_ID = :trader_id)";
    }
    if ($cfg['product']) {
        return "EXISTS (SELECT 1 FROM PRODUCT p INNER JOIN SHOP s ON s.SHOP_ID = p.SHOP_ID WHERE p.PRODUCT_ID = d.{$cfg['product']} AND s.TRADER_ID = :trader_id)";
    }
    return '1 = 1';
}

function generate_discount_id($conn, $cfg) {
    $idCol = $cfg['id'];
    if (!$idCol) return null;
    $len = (int)($cfg['cols'][$idCol]['DATA_LENGTH'] ?? 10);
    $digits = max(2, min(6, $len - 1));
    $prefix = 'D';
    try {
        $row = db_one($conn, "SELECT COUNT(*) + 1 AS NEXT_NO FROM DISCOUNT");
        $base = max(1, (int)($row['NEXT_NO'] ?? 1));
    } catch (Throwable $e) {
        $base = random_int(1, 9999);
    }
    for ($i = 0; $i < 50; $i++) {
        $num = $base + $i;
        $id = $prefix . str_pad((string)$num, $digits, '0', STR_PAD_LEFT);
        if (strlen($id) > $len) $id = substr($id, 0, $len);
        try {
            $exists = db_one($conn, "SELECT COUNT(*) AS TOTAL FROM DISCOUNT WHERE {$idCol} = :id", [':id' => $id]);
            if ((int)($exists['TOTAL'] ?? 0) === 0) return $id;
        } catch (Throwable $e) {
            return $id;
        }
    }
    return $prefix . substr(bin2hex(random_bytes(4)), 0, max(1, $len - 1));
}

function bind_date_or_text($cfg, $col, $placeholder) {
    $type = strtoupper($cfg['cols'][$col]['DATA_TYPE'] ?? '');
    if (str_contains($type, 'DATE') || str_contains($type, 'TIMESTAMP')) {
        return "TO_DATE({$placeholder}, 'YYYY-MM-DD')";
    }
    return $placeholder;
}

function get_discounts($conn, $traderId, $cfg, &$errors) {
    if (!$conn || !table_exists($conn, 'DISCOUNT')) return [];
    $idSelect = $cfg['id'] ? "d.{$cfg['id']}" : "ROWIDTOCHAR(d.ROWID)";
    $nameSelect = $cfg['name'] ? "d.{$cfg['name']}" : "'Discount'";
    $typeSelect = $cfg['type'] ? "d.{$cfg['type']}" : "'%'";
    $valueSelect = $cfg['value'] ? "d.{$cfg['value']}" : "0";
    $productSelect = $cfg['product'] ? "d.{$cfg['product']}" : "NULL";
    $statusSelect = $cfg['status'] ? "d.{$cfg['status']}" : "NULL";
    $startSelect = date_expr('d', $cfg['start'], 'START_DATE', $cfg['cols']);
    $endSelect = date_expr('d', $cfg['end'], 'END_DATE', $cfg['cols']);
    $joinProduct = $cfg['product'] ? "LEFT JOIN PRODUCT p ON p.PRODUCT_ID = d.{$cfg['product']}" : "LEFT JOIN PRODUCT p ON 1 = 0";
    $condition = discount_owner_condition($cfg);
    $binds = [];
    if (uses_trader_bind($cfg)) $binds[':trader_id'] = $traderId;

    try {
        return db_all($conn, "
            SELECT
                {$idSelect} AS DISCOUNT_ID,
                {$nameSelect} AS DISCOUNT_NAME,
                {$typeSelect} AS DISCOUNT_TYPE,
                {$valueSelect} AS DISCOUNT_VALUE,
                {$productSelect} AS PRODUCT_ID,
                {$startSelect},
                {$endSelect},
                {$statusSelect} AS RAW_STATUS,
                NVL(p.PRODUCT_NAME, 'All / Unassigned') AS PRODUCT_NAME
            FROM DISCOUNT d
            {$joinProduct}
            WHERE {$condition}
            ORDER BY END_DATE NULLS LAST, DISCOUNT_NAME
        ", $binds);
    } catch (Throwable $e) {
        $errors[] = 'Discount query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load discounts.');
        return [];
    }
}

function discount_status_label($row) {
    $raw = strtoupper(trim((string)($row['RAW_STATUS'] ?? '')));
    if (in_array($raw, ['0', 'N', 'NO', 'INACTIVE', 'DISABLED'], true)) return 'inactive';
    $end = trim((string)($row['END_DATE'] ?? ''));
    if ($end !== '') {
        $endTs = strtotime($end);
        if ($endTs !== false && $endTs < strtotime(date('Y-m-d'))) return 'expired';
    }
    return 'active';
}

$discountAvailable = $conn && table_exists($conn, 'DISCOUNT');
$cfg = $discountAvailable ? discount_config($conn) : ['cols'=>[], 'id'=>null, 'name'=>null, 'type'=>null, 'value'=>null, 'product'=>null, 'shop'=>null, 'trader'=>null, 'start'=>null, 'end'=>null, 'status'=>null];
$products = get_trader_products_for_discount($conn, $traderId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $discountAvailable) {
    $action = $_POST['action'] ?? '';
    $discountId = trim($_POST['discount_id'] ?? '');

    try {
        if ($action === 'delete' && $cfg['id'] && $discountId !== '') {
            $condition = discount_owner_condition($cfg);
            $binds = [':discount_id' => $discountId];
            if (uses_trader_bind($cfg)) $binds[':trader_id'] = $traderId;
            db_bind_and_execute($conn, "DELETE FROM DISCOUNT d WHERE d.{$cfg['id']} = :discount_id AND {$condition}", $binds);
            $flash = 'Discount deleted.';
        }

        if ($action === 'save') {
            if (!$cfg['value']) {
                throw new Exception('Your DISCOUNT table needs a DISCOUNT_VALUE or VALUE column for this page to save discounts.');
            }
            $name = trim($_POST['discount_name'] ?? '');
            $type = trim($_POST['discount_type'] ?? '%');
            $value = trim($_POST['discount_value'] ?? '');
            $productId = trim($_POST['product_id'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            if ($name === '' && $cfg['name']) throw new Exception('Discount name is required.');
            if ($value === '' || !is_numeric($value)) throw new Exception('Discount value must be a number.');

            $numericValue = (float)$value;
            // Current schema uses DISCOUNT_PERCENTAGE, so keep values within Oracle's CHECK constraint.
            if (strtoupper((string)$cfg['value']) === 'DISCOUNT_PERCENTAGE' || !$cfg['type'] || strtoupper($type) === '%') {
                if ($numericValue <= 0 || $numericValue > 100) {
                    throw new Exception('Discount percentage must be greater than 0 and no more than 100.');
                }
            } elseif ($numericValue <= 0) {
                throw new Exception('Discount value must be greater than 0.');
            }

            if ($cfg['start'] && $startDate === '') throw new Exception('Start date is required.');
            if ($cfg['end'] && $endDate === '') throw new Exception('End date is required.');
            if ($startDate !== '' && $endDate !== '') {
                $startTs = strtotime($startDate);
                $endTs = strtotime($endDate);
                if ($startTs === false || $endTs === false) throw new Exception('Please enter valid start and end dates.');
                if ($endTs <= $startTs) throw new Exception('End date must be after start date.');
            }

            if ($cfg['product'] && $productId === '') throw new Exception('Please choose a product for this discount.');
            $productRow = $productId !== '' ? get_product_for_trader($conn, $traderId, $productId) : null;
            if ($cfg['product'] && !$productRow) throw new Exception('Selected product does not belong to your shop.');

            if ($cfg['product'] && $cfg['start'] && $cfg['end'] && $productId !== '') {
                $overlapSql = "
                    SELECT COUNT(*) AS TOTAL
                    FROM DISCOUNT d
                    WHERE d.{$cfg['product']} = :product_id
                      AND TRUNC(d.{$cfg['start']}) <= TO_DATE(:end_date_overlap, 'YYYY-MM-DD')
                      AND TRUNC(d.{$cfg['end']}) >= TO_DATE(:start_date_overlap, 'YYYY-MM-DD')
                ";
                $overlapBinds = [
                    ':product_id' => $productId,
                    ':start_date_overlap' => $startDate,
                    ':end_date_overlap' => $endDate,
                ];
                if ($discountId !== '' && $cfg['id']) {
                    $overlapSql .= " AND d.{$cfg['id']} <> :current_discount_id";
                    $overlapBinds[':current_discount_id'] = $discountId;
                }
                if (uses_trader_bind($cfg)) {
                    $overlapSql .= " AND " . discount_owner_condition($cfg);
                    $overlapBinds[':trader_id'] = $traderId;
                }
                $overlap = db_one($conn, $overlapSql, $overlapBinds);
                if ((int)($overlap['TOTAL'] ?? 0) > 0) {
                    throw new Exception('This product already has a discount in that date range. Edit the existing discount or choose different dates.');
                }
            }

            $setPairs = [];
            $binds = [];
            $insertCols = [];
            $insertVals = [];

            $put = function($col, $placeholder, $valueForBind, $forDate = false) use (&$setPairs, &$binds, &$insertCols, &$insertVals, $cfg) {
                if (!$col) return;
                $expr = $forDate ? bind_date_or_text($cfg, $col, $placeholder) : $placeholder;
                $setPairs[] = "{$col} = {$expr}";
                $insertCols[] = $col;
                $insertVals[] = $expr;
                $binds[$placeholder] = $valueForBind;
            };

            if ($cfg['name']) $put($cfg['name'], ':discount_name', $name);
            if ($cfg['type']) $put($cfg['type'], ':discount_type', $type);
            if ($cfg['value']) $put($cfg['value'], ':discount_value', $value);
            if ($cfg['product']) $put($cfg['product'], ':product_id', $productId);
            if ($cfg['start']) $put($cfg['start'], ':start_date', $startDate, true);
            if ($cfg['end']) $put($cfg['end'], ':end_date', $endDate, true);
            if ($cfg['trader']) $put($cfg['trader'], ':row_trader_id', $traderId);
            if ($cfg['shop'] && $productRow) $put($cfg['shop'], ':shop_id', $productRow['SHOP_ID']);
            if ($cfg['status']) $put($cfg['status'], ':discount_status', 'ACTIVE');

            if ($discountId !== '' && $cfg['id']) {
                $condition = discount_owner_condition($cfg);
                $updateBinds = $binds;
                $updateBinds[':discount_id'] = $discountId;
                if (uses_trader_bind($cfg)) $updateBinds[':trader_id'] = $traderId;
                db_bind_and_execute($conn, "UPDATE DISCOUNT d SET " . implode(', ', $setPairs) . " WHERE d.{$cfg['id']} = :discount_id AND {$condition}", $updateBinds);
                $flash = 'Discount updated.';
            } else {
                // DISCOUNT_ID is generated by Oracle trigger trg_generate_discount_id.
                // Do not generate it in PHP; that avoids duplicate ID assumptions after test resets.
                db_bind_and_execute($conn, "INSERT INTO DISCOUNT (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")", $binds);
                $flash = 'Discount created.';
            }
        }
    } catch (Throwable $e) {
        $errors[] = shoplocalfy_public_exception_message($e, 'Could not save discount.');
    }
}

$discounts = get_discounts($conn, $traderId, $cfg, $errors);
$activeCount = 0; $expiredCount = 0;
foreach ($discounts as $d) { $s = discount_status_label($d); if ($s === 'expired') $expiredCount++; if ($s === 'active') $activeCount++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ShopLocalfy — Discounts</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/discount.css?v=20260517">
</head>
<body>
<?php $active = 'discounts'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Discounts', 'Manage coupons and offers for your own products'); ?>
  <div class="body">
    <?php if ($flash): ?><div class="notice" style="background:#ecfdf5;border-color:#bbf7d0;color:#047857"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="dc-header"><h2 class="dc-title">Manage Discount</h2><a class="btn-create" href="discount.php">+ Create discount</a></div>

    <div class="dc-stats">
      <div class="dc-stat"><div class="dc-stat-lbl">Total Discounts</div><div class="dc-stat-val"><?php echo int_fmt(count($discounts)); ?></div></div>
      <div class="dc-stat"><div class="dc-stat-lbl">Active</div><div class="dc-stat-val"><?php echo int_fmt($activeCount); ?></div></div>
      <div class="dc-stat"><div class="dc-stat-lbl">Expired</div><div class="dc-stat-val"><?php echo int_fmt($expiredCount); ?></div></div>
    </div>

    <?php if (!$discountAvailable): ?>
      <div class="disabled-box">Your DISCOUNT table was not found. Create the DISCOUNT table first, then this page will start reading and saving discounts automatically.</div>
    <?php else: ?>
      <form method="POST" class="dc-form-card" id="discountForm">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="discount_id" id="discountId" value="">
        <p class="dc-form-label">Create / Edit Discount</p>
        <div class="dc-grid">
          <div class="dc-field"><label for="discountName">Discount Name</label><input type="text" name="discount_name" id="discountName" placeholder="Discount Name" <?php echo $cfg['name'] ? '' : 'disabled'; ?>></div>
          <div class="dc-field"><label for="discountType">Discount Type</label><select name="discount_type" id="discountType" <?php echo $cfg['type'] ? '' : 'disabled'; ?>><option value="%">Percentage (%)</option><option value="FLAT">Flat Amount</option></select></div>
          <div class="dc-field"><label for="discountValue">Discount Percentage</label><input type="number" step="0.01" min="0.01" max="100" name="discount_value" id="discountValue" placeholder="Example: 10" required></div>
          <div class="dc-field"><label for="productId">Apply to Product</label><select name="product_id" id="productId" <?php echo $cfg['product'] ? 'required' : 'disabled'; ?>><option value="">Select product</option><?php foreach ($products as $p): ?><option value="<?php echo e($p['PRODUCT_ID']); ?>"><?php echo e($p['PRODUCT_NAME'] . ' · ' . $p['SHOP_NAME']); ?></option><?php endforeach; ?></select></div>
          <div class="dc-field"><label for="startDate">Start Date</label><input type="date" name="start_date" id="startDate" <?php echo $cfg['start'] ? 'required' : 'disabled'; ?>></div>
          <div class="dc-field"><label for="endDate">End Date</label><input type="date" name="end_date" id="endDate" <?php echo $cfg['end'] ? 'required' : 'disabled'; ?>></div>
        </div>
        <button class="btn-save" id="btnSave">Save discount</button>
        <button type="button" class="btn-reset" id="btnReset">Clear</button>
        <?php if (!$cfg['product']): ?><p class="muted-note" style="margin-top:10px">This table does not have PRODUCT_ID, so discounts cannot be assigned to individual products from this page.</p><?php endif; ?>
      </form>
    <?php endif; ?>

    <div class="dc-table-wrap">
      <table class="dc-table">
        <thead><tr><th>Name</th><th>Value</th><th>Applied to</th><th>Starts</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="discountTableBody">
          <?php if (!$discounts): ?>
            <tr><td colspan="7">No discounts yet. Create one above and it will appear here.</td></tr>
          <?php else: ?>
            <?php foreach ($discounts as $d):
              $status = discount_status_label($d);
              $valueText = (string)($d['DISCOUNT_VALUE'] ?? '0');
              $type = strtoupper((string)($d['DISCOUNT_TYPE'] ?? '%'));
              $valueDisplay = ($type === '%' || str_contains($type, 'PERCENT')) ? $valueText . '%' : money_fmt($valueText);
            ?>
              <tr>
                <td><?php echo e($d['DISCOUNT_NAME'] ?: 'Discount'); ?></td>
                <td><?php echo e($valueDisplay); ?></td>
                <td><?php echo e($d['PRODUCT_NAME'] ?: '—'); ?></td>
                <td><?php echo e($d['START_DATE'] ?: '—'); ?></td>
                <td><?php echo e($d['END_DATE'] ?: '—'); ?></td>
                <td><span class="dc-pill <?php echo e($status); ?>"><?php echo e($status); ?></span></td>
                <td>
                  <button type="button" class="dc-act edit" title="Edit" data-id="<?php echo e($d['DISCOUNT_ID']); ?>" data-name="<?php echo e($d['DISCOUNT_NAME']); ?>" data-type="<?php echo e($d['DISCOUNT_TYPE']); ?>" data-value="<?php echo e($d['DISCOUNT_VALUE']); ?>" data-product="<?php echo e($d['PRODUCT_ID']); ?>" data-start="<?php echo e($d['START_DATE']); ?>" data-end="<?php echo e($d['END_DATE']); ?>">✏️</button>
                  <?php if ($cfg['id']): ?>
                    <form method="POST" class="actions-inline" onsubmit="return confirm('Delete this discount?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="discount_id" value="<?php echo e($d['DISCOUNT_ID']); ?>"><button class="dc-act del" title="Delete">✕</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="../assets/trader/js/discount.js?v=20260517"></script>
</body>
</html>


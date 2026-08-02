<?php
// voucher-management.php
// Uses the same Oracle connection/helpers as the trader product pages.
require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();

date_default_timezone_set('Asia/Kathmandu');

$conn = admin_db_connection();
$message = trim($_GET['success'] ?? '');
$error = trim($_GET['error'] ?? '');
$editVoucherId = trim($_GET['edit'] ?? '');
$viewVoucherId = trim($_GET['view'] ?? '');

function admin_voucher_table_columns($conn) {
    if (!$conn || !table_exists($conn, 'VOUCHER')) return [];
    $rows = db_all($conn, "
        SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
        FROM USER_TAB_COLUMNS
        WHERE TABLE_NAME = 'VOUCHER'
        ORDER BY COLUMN_ID
    ");
    $columns = [];
    foreach ($rows as $row) {
        $columns[strtoupper($row['COLUMN_NAME'])] = $row;
    }
    return $columns;
}

function admin_voucher_pick_col($columns, $names) {
    foreach ($names as $name) {
        $upper = strtoupper($name);
        if (isset($columns[$upper])) return $upper;
    }
    return null;
}

function admin_voucher_config($conn) {
    $cols = admin_voucher_table_columns($conn);
    return [
        'cols' => $cols,
        'id' => admin_voucher_pick_col($cols, ['VOUCHER_ID', 'ID']),
        'code' => admin_voucher_pick_col($cols, ['VOUCHER_CODE', 'CODE', 'COUPON_CODE']),
        'name' => admin_voucher_pick_col($cols, ['VOUCHER_NAME', 'NAME', 'TITLE']),
        'description' => admin_voucher_pick_col($cols, ['DESCRIPTION', 'VOUCHER_DESCRIPTION']),
        'type' => admin_voucher_pick_col($cols, ['VOUCHER_TYPE', 'DISCOUNT_TYPE', 'TYPE']),
        'value' => admin_voucher_pick_col($cols, ['DISCOUNT_PERCENTAGE', 'DISCOUNT_VALUE', 'VOUCHER_VALUE', 'VALUE', 'PERCENTAGE', 'AMOUNT']),
        'start' => admin_voucher_pick_col($cols, ['START_DATE', 'VALID_FROM', 'DATE_FROM']),
        'end' => admin_voucher_pick_col($cols, ['END_DATE', 'VALID_TO', 'EXPIRY_DATE', 'EXPIRES_AT']),
        'status' => admin_voucher_pick_col($cols, ['STATUS', 'IS_ACTIVE', 'ACTIVE_STATUS']),
        'min_order' => admin_voucher_pick_col($cols, ['MIN_ORDER_AMOUNT', 'MINIMUM_ORDER', 'MIN_ORDER']),
        'usage_limit' => admin_voucher_pick_col($cols, ['USAGE_LIMIT', 'MAX_USES', 'LIMIT_PER_USER']),
        'used_count' => admin_voucher_pick_col($cols, ['USED_COUNT', 'USES_COUNT', 'TIMES_USED']),
    ];
}

function admin_voucher_ready($conn, $cfg) {
    return $conn && table_exists($conn, 'VOUCHER') && ($cfg['id'] || $cfg['code']) && $cfg['value'];
}

function admin_voucher_date_select($cfg, $col, $alias) {
    if (!$col) return "NULL AS $alias";
    $type = strtoupper((string)($cfg['cols'][$col]['DATA_TYPE'] ?? ''));
    if (str_contains($type, 'DATE') || str_contains($type, 'TIMESTAMP')) {
        return "TO_CHAR($col, 'YYYY-MM-DD') AS $alias";
    }
    return "$col AS $alias";
}

function admin_voucher_date_value($cfg, $col, $placeholder) {
    if (!$col) return $placeholder;
    $type = strtoupper((string)($cfg['cols'][$col]['DATA_TYPE'] ?? ''));
    if (str_contains($type, 'DATE') || str_contains($type, 'TIMESTAMP')) {
        return "TO_DATE($placeholder, 'YYYY-MM-DD')";
    }
    return $placeholder;
}

function admin_voucher_new_id($conn, $cfg) {
    $idCol = $cfg['id'];
    if (!$idCol) return null;
    $type = strtoupper((string)($cfg['cols'][$idCol]['DATA_TYPE'] ?? 'VARCHAR2'));
    if (str_contains($type, 'NUMBER')) {
        $row = db_one($conn, "SELECT NVL(MAX($idCol), 0) + 1 AS NEXT_ID FROM VOUCHER");
        return (string)($row['NEXT_ID'] ?? '1');
    }
    $length = max(2, (int)($cfg['cols'][$idCol]['DATA_LENGTH'] ?? 10));
    $pad = max(1, $length - 1);
    $row = db_one($conn, "
        SELECT NVL(MAX(TO_NUMBER(REGEXP_SUBSTR($idCol, '[0-9]+$'))), 0) + 1 AS NEXT_NUM
        FROM VOUCHER
        WHERE REGEXP_LIKE($idCol, '^[A-Za-z]+[0-9]+$')
    ");
    $num = (int)($row['NEXT_NUM'] ?? 1);
    do {
        $candidate = 'V' . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
        $exists = db_one($conn, "SELECT $idCol FROM VOUCHER WHERE $idCol = :id", [':id' => $candidate]);
        $num++;
    } while ($exists);
    return $candidate;
}

function admin_voucher_status_value($cfg, $active) {
    $col = $cfg['status'];
    if (!$col) return $active ? 'ACTIVE' : 'INACTIVE';
    $type = strtoupper((string)($cfg['cols'][$col]['DATA_TYPE'] ?? 'VARCHAR2'));
    return str_contains($type, 'NUMBER') ? ($active ? '1' : '0') : ($active ? 'ACTIVE' : 'INACTIVE');
}

function admin_voucher_status_label($row) {
    $raw = strtoupper(trim((string)($row['RAW_STATUS'] ?? '')));
    if (in_array($raw, ['0', 'N', 'NO', 'INACTIVE', 'DISABLED', 'DEACTIVE', 'DEACTIVATED'], true)) return 'inactive';
    $end = trim((string)($row['END_DATE'] ?? ''));
    if ($end !== '' && strtotime($end) !== false && strtotime($end) < strtotime(date('Y-m-d'))) return 'expired';
    return 'active';
}

function admin_voucher_redirect($message = '', $error = '', $extra = '') {
    $url = 'voucher-management.php';
    $params = [];
    if ($message !== '') $params['success'] = $message;
    if ($error !== '') $params['error'] = $error;
    if ($params) $url .= '?' . http_build_query($params);
    if ($extra !== '') $url .= ($params ? '&' : '?') . ltrim($extra, '?&');
    header('Location: ' . $url);
    exit;
}

function admin_get_vouchers($conn, $cfg) {
    if (!admin_voucher_ready($conn, $cfg)) return [];
    $idSelect = $cfg['id'] ? $cfg['id'] : $cfg['code'];
    $codeSelect = $cfg['code'] ? $cfg['code'] : $idSelect;
    $nameSelect = $cfg['name'] ? $cfg['name'] : "'Voucher'";
    $descSelect = $cfg['description'] ? $cfg['description'] : "''";
    $typeSelect = $cfg['type'] ? $cfg['type'] : "'PERCENT'";
    $statusSelect = $cfg['status'] ? $cfg['status'] : "'ACTIVE'";
    $minOrderSelect = $cfg['min_order'] ? $cfg['min_order'] : '0';
    $limitSelect = $cfg['usage_limit'] ? $cfg['usage_limit'] : '0';
    $usedCountSelect = $cfg['used_count'] ? $cfg['used_count'] : '0';
    $startSelect = admin_voucher_date_select($cfg, $cfg['start'], 'START_DATE');
    $endSelect = admin_voucher_date_select($cfg, $cfg['end'], 'END_DATE');

    return db_all($conn, "
        SELECT
            $idSelect AS VOUCHER_ID,
            $codeSelect AS VOUCHER_CODE,
            $nameSelect AS VOUCHER_NAME,
            $descSelect AS DESCRIPTION,
            $typeSelect AS VOUCHER_TYPE,
            {$cfg['value']} AS VOUCHER_VALUE,
            $startSelect,
            $endSelect,
            $statusSelect AS RAW_STATUS,
            $minOrderSelect AS MIN_ORDER_AMOUNT,
            $limitSelect AS USAGE_LIMIT,
            $usedCountSelect AS USED_COUNT
        FROM VOUCHER
        ORDER BY END_DATE NULLS LAST, VOUCHER_CODE
    ");
}

function admin_get_voucher($conn, $cfg, $voucherId) {
    if (!admin_voucher_ready($conn, $cfg) || $voucherId === '') return null;
    $idCol = $cfg['id'] ?: $cfg['code'];
    $rows = admin_get_vouchers($conn, $cfg);
    foreach ($rows as $row) {
        if ((string)$row['VOUCHER_ID'] === (string)$voucherId) return $row;
    }
    return db_one($conn, "SELECT $idCol AS VOUCHER_ID FROM VOUCHER WHERE $idCol = :id", [':id' => $voucherId]);
}

$cfg = admin_voucher_config($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $voucherId = trim($_POST['voucher_id'] ?? '');

    try {
        if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
        if (!table_exists($conn, 'VOUCHER')) throw new RuntimeException('VOUCHER table was not found.');
        if (!admin_voucher_ready($conn, $cfg)) throw new RuntimeException('VOUCHER table needs an ID or code column and a discount value column.');

        $idCol = $cfg['id'] ?: $cfg['code'];

        if ($action === 'delete') {
            if ($voucherId === '') throw new RuntimeException('Voucher ID is missing.');
            db_bind_and_execute($conn, "DELETE FROM VOUCHER WHERE $idCol = :id", [':id' => $voucherId]);
            admin_voucher_redirect('Voucher deleted successfully.');
        }

        if ($action === 'toggle') {
            if (!$cfg['status']) throw new RuntimeException('This VOUCHER table has no STATUS or IS_ACTIVE column.');
            if ($voucherId === '') throw new RuntimeException('Voucher ID is missing.');
            $newStatus = admin_voucher_status_value($cfg, ($_POST['new_status'] ?? '') === 'active');
            db_bind_and_execute($conn, "UPDATE VOUCHER SET {$cfg['status']} = :status WHERE $idCol = :id", [':status' => $newStatus, ':id' => $voucherId]);
            admin_voucher_redirect('Voucher status updated successfully.');
        }

        if ($action === 'save') {
            $code = strtoupper(trim($_POST['voucher_code'] ?? ''));
            $name = trim($_POST['voucher_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $type = strtoupper(trim($_POST['voucher_type'] ?? 'PERCENTAGE'));
            if ($type === 'PERCENT') $type = 'PERCENTAGE';
            if ($type === 'FLAT') $type = 'FIXED';
            $value = trim($_POST['voucher_value'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            $minOrder = trim($_POST['min_order_amount'] ?? '0');
            $usageLimit = trim($_POST['usage_limit'] ?? '0');
            $status = trim($_POST['status'] ?? 'ACTIVE');

            if ($cfg['code'] && $code === '') throw new RuntimeException('Voucher code is required.');
            if ($value === '' || !is_numeric($value)) throw new RuntimeException('Discount value must be a number.');
            if ($cfg['type'] && !in_array($type, ['PERCENTAGE', 'FIXED'], true)) throw new RuntimeException('Discount type must be Percentage or Fixed.');

            $numericValue = (float)$value;
            if ($type === 'PERCENTAGE' && ($numericValue <= 0 || $numericValue > 100)) {
                throw new RuntimeException('Percentage voucher value must be greater than 0 and no more than 100.');
            }
            if ($type === 'FIXED' && $numericValue <= 0) {
                throw new RuntimeException('Fixed voucher value must be greater than 0.');
            }

            if ($cfg['start'] && $startDate === '') throw new RuntimeException('Start date is required.');
            if ($cfg['end'] && $endDate === '') throw new RuntimeException('End date is required.');
            if ($startDate !== '' && $endDate !== '') {
                $startTs = strtotime($startDate);
                $endTs = strtotime($endDate);
                if ($startTs === false || $endTs === false) throw new RuntimeException('Please enter valid start and end dates.');
                if ($endTs <= $startTs) throw new RuntimeException('End date must be after start date.');
            }

            if ($minOrder !== '' && !is_numeric($minOrder)) throw new RuntimeException('Minimum order must be a number.');
            if ((float)($minOrder === '' ? 0 : $minOrder) < 0) throw new RuntimeException('Minimum order cannot be negative.');
            if ($usageLimit !== '' && !is_numeric($usageLimit)) throw new RuntimeException('Usage limit must be a number.');
            if ((int)($usageLimit === '' ? 0 : $usageLimit) < 1) throw new RuntimeException('Usage limit must be at least 1.');

            $minOrder = number_format((float)($minOrder === '' ? 0 : $minOrder), 2, '.', '');
            $usageLimit = (string)max(1, (int)$usageLimit);
            $value = number_format($numericValue, 2, '.', '');

            if ($cfg['code']) {
                $duplicateSql = "SELECT $idCol AS VOUCHER_ID FROM VOUCHER WHERE UPPER(TRIM({$cfg['code']})) = :voucher_code";
                $duplicateBinds = [':voucher_code' => $code];
                if ($voucherId !== '') {
                    $duplicateSql .= " AND $idCol <> :voucher_id";
                    $duplicateBinds[':voucher_id'] = $voucherId;
                }
                $duplicate = db_one($conn, $duplicateSql, $duplicateBinds);
                if ($duplicate) {
                    throw new RuntimeException('That voucher code already exists. Use a different code.');
                }
            }

            $sets = [];
            $cols = [];
            $vals = [];
            $binds = [];
            $put = function($col, $placeholder, $valueForBind, $date = false) use (&$sets, &$cols, &$vals, &$binds, $cfg) {
                if (!$col) return;
                $expr = $date ? admin_voucher_date_value($cfg, $col, $placeholder) : $placeholder;
                $sets[] = "$col = $expr";
                $cols[] = $col;
                $vals[] = $expr;
                $binds[$placeholder] = $valueForBind;
            };

            $put($cfg['code'], ':voucher_code', $code);
            if (isset($_POST['voucher_name'])) $put($cfg['name'], ':voucher_name', $name);
            if (isset($_POST['description'])) $put($cfg['description'], ':description', $description);
            $put($cfg['type'], ':voucher_type', $type);
            $put($cfg['value'], ':voucher_value', $value);
            $put($cfg['start'], ':start_date', $startDate, true);
            $put($cfg['end'], ':end_date', $endDate, true);
            $put($cfg['min_order'], ':min_order_amount', $minOrder === '' ? '0' : $minOrder);
            $put($cfg['usage_limit'], ':usage_limit', $usageLimit === '' ? '0' : $usageLimit);
            $put($cfg['status'], ':status', admin_voucher_status_value($cfg, strtoupper($status) === 'ACTIVE'));
            if ($voucherId === '') {
                $put($cfg['used_count'], ':used_count', '0');
            }

            if ($voucherId !== '') {
                $binds[':id'] = $voucherId;
                db_bind_and_execute($conn, 'UPDATE VOUCHER SET ' . implode(', ', $sets) . " WHERE $idCol = :id", $binds);
                admin_voucher_redirect('Voucher updated successfully.');
            }

            // VOUCHER_ID is generated by Oracle trigger trg_generate_voucher_id.
            db_bind_and_execute($conn, 'INSERT INTO VOUCHER (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')', $binds);
            admin_voucher_redirect('Voucher created successfully.');
        }
    } catch (Throwable $e) {
        admin_voucher_redirect('', shoplocalfy_public_exception_message($e, 'Could not update voucher.'));
    }
}

$vouchers = [];
$editVoucher = null;
$viewVoucher = null;
try {
    if (!$conn) throw new RuntimeException('Oracle database connection was not found.');
    if (!table_exists($conn, 'VOUCHER')) throw new RuntimeException('VOUCHER table was not found.');
    if (!admin_voucher_ready($conn, $cfg)) throw new RuntimeException('VOUCHER table needs an ID or code column and a discount value column.');
    $vouchers = admin_get_vouchers($conn, $cfg);
    if ($editVoucherId !== '') $editVoucher = admin_get_voucher($conn, $cfg, $editVoucherId);
    if ($viewVoucherId !== '') $viewVoucher = admin_get_voucher($conn, $cfg, $viewVoucherId);
} catch (Throwable $e) {
    $error = $error ?: shoplocalfy_public_exception_message($e, 'Could not load vouchers.');
}

$activeCount = 0;
$inactiveCount = 0;
$expiredCount = 0;
foreach ($vouchers as $voucher) {
    $status = admin_voucher_status_label($voucher);
    if ($status === 'active') $activeCount++;
    if ($status === 'inactive') $inactiveCount++;
    if ($status === 'expired') $expiredCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy - Voucher Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>    <link rel="stylesheet" href="../assets/admin/css/voucher-management.css?v=20260517">
  
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>
    <div class="page-body">
      <div class="page-heading">
        <h1 class="page-title">Voucher Management</h1>
        <p class="page-subtitle">Add, edit, view, activate and deactivate vouchers</p>
      </div>

      <?php if ($message !== ''): ?><div class="message-box success"><?= e($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="message-box error"><?= e($error) ?></div><?php endif; ?>

      <div class="stat-cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:22px;">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-ticket"></i></div><div class="stat-info"><span class="stat-label">Total Vouchers</span><span class="stat-value"><?= e(count($vouchers)) ?></span></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-toggle-on"></i></div><div class="stat-info"><span class="stat-label">Active</span><span class="stat-value"><?= e($activeCount) ?></span></div></div>
        <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-toggle-off"></i></div><div class="stat-info"><span class="stat-label">Inactive</span><span class="stat-value"><?= e($inactiveCount) ?></span></div></div>
        <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-calendar-xmark"></i></div><div class="stat-info"><span class="stat-label">Expired</span><span class="stat-value"><?= e($expiredCount) ?></span></div></div>
      </div>

      <div class="voucher-layout">
        <div class="card">
          <div class="card-header"><span class="card-title"><?= $editVoucher ? 'Edit Voucher' : 'Add Voucher' ?></span></div>
          <div class="card-body" style="padding:16px;">
            <form method="POST" class="voucher-form">
              <input type="hidden" name="action" value="save"/>
              <?php if ($editVoucher): ?><input type="hidden" name="voucher_id" value="<?= e($editVoucher['VOUCHER_ID']) ?>"/><?php endif; ?>
              <div class="form-two">
                <div class="form-row"><label>Voucher Code</label><input type="text" name="voucher_code" value="<?= e($editVoucher['VOUCHER_CODE'] ?? '') ?>" <?= $cfg['code'] ? 'required' : 'disabled' ?>/></div>
                <div class="form-row"><label>Status</label><select name="status" <?= $cfg['status'] ? '' : 'disabled' ?>><option value="ACTIVE" <?= admin_voucher_status_label($editVoucher ?? []) === 'active' ? 'selected' : '' ?>>Active</option><option value="INACTIVE" <?= admin_voucher_status_label($editVoucher ?? []) === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
              </div>
              <div class="form-two">
                <div class="form-row"><label>Type</label><select name="voucher_type" <?= $cfg['type'] ? '' : 'disabled' ?>><option value="PERCENTAGE" <?= strtoupper((string)($editVoucher['VOUCHER_TYPE'] ?? 'PERCENTAGE')) !== 'FIXED' ? 'selected' : '' ?>>Percentage</option><option value="FIXED" <?= strtoupper((string)($editVoucher['VOUCHER_TYPE'] ?? '')) === 'FIXED' ? 'selected' : '' ?>>Fixed Amount</option></select></div>
                <div class="form-row"><label>Value</label><input type="number" step="0.01" min="0.01" name="voucher_value" value="<?= e($editVoucher['VOUCHER_VALUE'] ?? '') ?>" required/></div>
              </div>
              <div class="form-two">
                <div class="form-row"><label>Start Date</label><input type="date" name="start_date" value="<?= e($editVoucher['START_DATE'] ?? '') ?>" <?= $cfg['start'] ? 'required' : 'disabled' ?>/></div>
                <div class="form-row"><label>End Date</label><input type="date" name="end_date" value="<?= e($editVoucher['END_DATE'] ?? '') ?>" <?= $cfg['end'] ? 'required' : 'disabled' ?>/></div>
              </div>
              <div class="form-two">
                <div class="form-row"><label>Minimum Order</label><input type="number" step="0.01" name="min_order_amount" value="<?= e($editVoucher['MIN_ORDER_AMOUNT'] ?? '0') ?>" <?= $cfg['min_order'] ? '' : 'disabled' ?>/></div>
                <div class="form-row"><label>Usage Limit</label><input type="number" min="1" name="usage_limit" value="<?= e($editVoucher['USAGE_LIMIT'] ?? '1') ?>" <?= $cfg['usage_limit'] ? 'required' : 'disabled' ?>/></div>
              </div>
              <div class="form-actions">
                <button class="btn-small btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i><?= $editVoucher ? 'Update Voucher' : 'Add Voucher' ?></button>
                <?php if ($editVoucher): ?><a class="btn-small btn-light" href="voucher-management.php">Cancel</a><?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title">All Vouchers</span></div>
          <div class="card-body" style="padding:16px;overflow-x:auto;">
            <table class="data-table">
              <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Used</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
              <?php if (!$vouchers): ?>
                <tr class="empty-row"><td colspan="8"><span class="empty-text">No vouchers found</span></td></tr>
              <?php else: ?>
                <?php foreach ($vouchers as $voucher): $status = admin_voucher_status_label($voucher); $next = $status === 'active' ? 'inactive' : 'active'; ?>
                  <tr>
                    <td><span class="voucher-code"><?= e($voucher['VOUCHER_CODE']) ?></span></td>
                    <td><?= e($voucher['VOUCHER_TYPE']) ?></td>
                    <td><?= strtoupper((string)$voucher['VOUCHER_TYPE']) === 'FIXED' ? e(admin_money($voucher['VOUCHER_VALUE'] ?? 0)) : e($voucher['VOUCHER_VALUE']) . '%' ?></td>
                    <td><?= e(admin_money($voucher['MIN_ORDER_AMOUNT'] ?? 0)) ?></td>
                    <td><?= e($voucher['USED_COUNT']) ?> / <?= e($voucher['USAGE_LIMIT']) ?></td>
                    <td><?= e($voucher['END_DATE'] ?: '—') ?></td>
                    <td><span class="status-pill <?= e($status) ?>"><?= e($status) ?></span></td>
                    <td>
                      <div class="inline-actions">
                        <a class="btn-small btn-light" href="voucher-management.php?view=<?= urlencode($voucher['VOUCHER_ID']) ?>"><i class="fa-solid fa-eye"></i>View</a>
                        <a class="btn-small btn-light" href="voucher-management.php?edit=<?= urlencode($voucher['VOUCHER_ID']) ?>"><i class="fa-solid fa-pen"></i>Edit</a>
                        <?php if ($cfg['status']): ?>
                          <form method="POST" class="inline-form"><input type="hidden" name="action" value="toggle"/><input type="hidden" name="voucher_id" value="<?= e($voucher['VOUCHER_ID']) ?>"/><input type="hidden" name="new_status" value="<?= e($next) ?>"/><button class="btn-small btn-light" type="submit"><?= $next === 'active' ? 'Activate' : 'Deactivate' ?></button></form>
                        <?php endif; ?>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this voucher?');"><input type="hidden" name="action" value="delete"/><input type="hidden" name="voucher_id" value="<?= e($voucher['VOUCHER_ID']) ?>"/><button class="btn-small btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Delete</button></form>
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

      <div class="card view-panel">
        <div class="card-header"><span class="card-title">Voucher Details<?= $viewVoucher ? ': ' . e($viewVoucher['VOUCHER_CODE']) : '' ?></span></div>
        <div class="card-body" style="padding:16px;">
          <?php if (!$viewVoucher): ?>
            <div class="empty-note">Click View beside a voucher to read its full details.</div>
          <?php else: ?>
            <div class="detail-grid">
              <div class="detail-box"><span class="detail-label">Code</span><span class="detail-value"><?= e($viewVoucher['VOUCHER_CODE']) ?></span></div>
              <div class="detail-box"><span class="detail-label">Type</span><span class="detail-value"><?= e($viewVoucher['VOUCHER_TYPE']) ?></span></div>
              <div class="detail-box"><span class="detail-label">Status</span><span class="detail-value"><?= e(admin_voucher_status_label($viewVoucher)) ?></span></div>
              <div class="detail-box"><span class="detail-label">Value</span><span class="detail-value"><?= strtoupper((string)$viewVoucher['VOUCHER_TYPE']) === 'FIXED' ? e(admin_money($viewVoucher['VOUCHER_VALUE'] ?? 0)) : e($viewVoucher['VOUCHER_VALUE']) . '%' ?></span></div>
              <div class="detail-box"><span class="detail-label">Start Date</span><span class="detail-value"><?= e($viewVoucher['START_DATE'] ?: '—') ?></span></div>
              <div class="detail-box"><span class="detail-label">End Date</span><span class="detail-value"><?= e($viewVoucher['END_DATE'] ?: '—') ?></span></div>
              <div class="detail-box"><span class="detail-label">Minimum Order</span><span class="detail-value"><?= e(admin_money($viewVoucher['MIN_ORDER_AMOUNT'] ?? 0)) ?></span></div>
              <div class="detail-box"><span class="detail-label">Usage Limit</span><span class="detail-value"><?= e($viewVoucher['USAGE_LIMIT']) ?></span></div>
              <div class="detail-box"><span class="detail-label">Used Count</span><span class="detail-value"><?= e($viewVoucher['USED_COUNT']) ?></span></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();
$conn = admin_db_connection();
date_default_timezone_set('Asia/Kathmandu');

if (!function_exists('finance_h')) {
    function finance_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('finance_money')) {
    function finance_money($value) {
        return '£' . number_format((float)$value, 2);
    }
}

if (!function_exists('finance_period')) {
    function finance_period(): array {
        $period = strtolower(trim((string)($_GET['period'] ?? $_POST['period'] ?? 'last7')));
        $from = trim((string)($_GET['from'] ?? $_POST['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? $_POST['to'] ?? ''));
        $today = new DateTimeImmutable('today');
        $label = 'Last 7 days';
        $start = $today->modify('-7 days')->format('Y-m-d');
        $end = $today->modify('+1 day')->format('Y-m-d');
        $useRange = true;

        switch ($period) {
            case 'today':
                $label = 'Today';
                $start = $today->format('Y-m-d');
                $end = $today->modify('+1 day')->format('Y-m-d');
                break;
            case 'month':
                $label = 'This month';
                $start = $today->modify('first day of this month')->format('Y-m-d');
                $end = $today->modify('first day of next month')->format('Y-m-d');
                break;
            case 'all':
                $label = 'All time';
                $useRange = false;
                break;
            case 'custom':
                $label = 'Custom range';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                    $start = $from;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
                }
                break;
            default:
                $period = 'last7';
        }

        return [$period, $label, $from, $to, $start, $end, $useRange];
    }
}

if (!function_exists('finance_next_id')) {
    function finance_next_id($conn, string $sequenceName, string $prefix, int $digits): string {
        $row = db_one($conn, "SELECT :prefix || LPAD($sequenceName.NEXTVAL, :digits, '0') AS NEW_ID FROM DUAL", [
            ':prefix' => $prefix,
            ':digits' => $digits,
        ]);
        return (string)($row['NEW_ID'] ?? '');
    }
}

if (!function_exists('finance_schema')) {
    function finance_schema($conn): array {
        return [
            'ready' => table_exists($conn, 'TRADER_PAYOUT') && table_exists($conn, 'TRADER_PAYOUT_ITEM'),
            'tp_item_rows' => column_exists($conn, 'TRADER_PAYOUT', 'ITEM_ROWS'),
            'tp_item_units' => column_exists($conn, 'TRADER_PAYOUT', 'ITEM_UNITS'),
            'tpi_payout_item_id' => column_exists($conn, 'TRADER_PAYOUT_ITEM', 'PAYOUT_ITEM_ID'),
            'tpi_line_total' => column_exists($conn, 'TRADER_PAYOUT_ITEM', 'LINE_TOTAL'),
            'tpi_gross' => column_exists($conn, 'TRADER_PAYOUT_ITEM', 'GROSS_AMOUNT'),
            'tpi_fee' => column_exists($conn, 'TRADER_PAYOUT_ITEM', 'PLATFORM_FEE'),
            'tpi_payout' => column_exists($conn, 'TRADER_PAYOUT_ITEM', 'PAYOUT_AMOUNT'),
        ];
    }
}

if (!function_exists('finance_filter_parts')) {
    function finance_filter_parts(): array {
        [$period, $periodLabel, $customFrom, $customTo, $startDate, $endDate, $useDateRange] = finance_period();
        $view = strtolower(trim((string)($_GET['view'] ?? $_POST['view'] ?? 'due')));
        $trader = trim((string)($_GET['trader'] ?? $_POST['trader'] ?? $_POST['trader_id'] ?? ''));

        if (!in_array($view, ['due', 'paid', 'all'], true)) {
            $view = 'due';
        }

        $binds = [];
        $dateSql = '';
        if ($useDateRange) {
            $dateSql = "AND TRUNC(o.PICKUP_DATE) >= TO_DATE(:start_date, 'YYYY-MM-DD')
                        AND TRUNC(o.PICKUP_DATE) < TO_DATE(:end_date, 'YYYY-MM-DD')";
            $binds[':start_date'] = $startDate;
            $binds[':end_date'] = $endDate;
        }

        $traderSql = '';
        if ($trader !== '') {
            $traderSql = 'AND oi.TRADER_ID = :trader_id';
            $binds[':trader_id'] = $trader;
        }

        return [
            'period' => $period,
            'periodLabel' => $periodLabel,
            'customFrom' => $customFrom,
            'customTo' => $customTo,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'useDateRange' => $useDateRange,
            'displayEnd' => $useDateRange ? (new DateTimeImmutable($endDate))->modify('-1 day')->format('Y-m-d') : 'All dates',
            'view' => $view,
            'trader' => $trader,
            'paymentSql' => "UPPER(NVL(TRIM(p.PAYMENT_STATUS), 'FAILED')) = 'COMPLETED'",
            'itemSql' => "UPPER(NVL(TRIM(oi.ITEM_STATUS), 'PENDING')) = 'COLLECTED'",
            'dateSql' => $dateSql,
            'traderSql' => $traderSql,
            'binds' => $binds,
        ];
    }
}

if (!function_exists('finance_unpaid_items_sql')) {
    function finance_unpaid_items_sql(array $schema): string {
        $payoutJoin = $schema['ready']
            ? 'LEFT JOIN TRADER_PAYOUT_ITEM tpi ON tpi.ORDER_ID = oi.ORDER_ID AND tpi.PRODUCT_ID = oi.PRODUCT_ID AND tpi.TRADER_ID = oi.TRADER_ID'
            : '';
        $unpaidWhere = $schema['ready'] ? 'AND tpi.PAYOUT_ID IS NULL' : '';

        return "
            SELECT
                oi.ORDER_ID,
                oi.PRODUCT_ID,
                NVL(pr.PRODUCT_NAME, oi.PRODUCT_ID) AS PRODUCT_NAME,
                oi.TRADER_ID,
                TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS TRADER_NAME,
                NVL(MAX(s.SHOP_NAME), 'No shop recorded') AS SHOP_NAME,
                oi.QUANTITY,
                oi.LOCKED_PRICE,
                NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0) AS LINE_TOTAL,
                ROUND(NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0) * 0.08, 2) AS LINE_FEE,
                ROUND(NVL(oi.QUANTITY, 0) * NVL(oi.LOCKED_PRICE, 0) * 0.92, 2) AS LINE_PAYOUT,
                oi.ITEM_STATUS,
                o.ORDER_STATUS,
                p.PAYMENT_STATUS,
                TO_CHAR(o.PICKUP_DATE, 'YYYY-MM-DD') AS PICKUP_DATE,
                TO_CHAR(o.ORDER_DATE, 'YYYY-MM-DD') AS ORDER_DATE
            FROM ORDER_ITEM oi
            JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            JOIN PAYMENT p ON p.ORDER_ID = o.ORDER_ID
            JOIN TRADER t ON t.USER_ID = oi.TRADER_ID
            JOIN \"USER\" u ON u.USER_ID = t.USER_ID
            LEFT JOIN PRODUCT pr ON pr.PRODUCT_ID = oi.PRODUCT_ID
            LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
            $payoutJoin
            WHERE %PAYMENT_SQL%
              AND %ITEM_SQL%
              %DATE_SQL%
              %TRADER_SQL%
              $unpaidWhere
            GROUP BY
                oi.ORDER_ID, oi.PRODUCT_ID, pr.PRODUCT_NAME, oi.TRADER_ID,
                u.FIRST_NAME, u.LAST_NAME, oi.QUANTITY, oi.LOCKED_PRICE,
                oi.ITEM_STATUS, o.ORDER_STATUS, p.PAYMENT_STATUS, o.PICKUP_DATE, o.ORDER_DATE
        ";
    }
}

if (!function_exists('finance_build_unpaid_sql')) {
    function finance_build_unpaid_sql(array $schema, array $filters, string $extraTraderSql = ''): string {
        $sql = finance_unpaid_items_sql($schema);
        $traderSql = $extraTraderSql !== '' ? $extraTraderSql : $filters['traderSql'];
        return str_replace(
            ['%PAYMENT_SQL%', '%ITEM_SQL%', '%DATE_SQL%', '%TRADER_SQL%'],
            [$filters['paymentSql'], $filters['itemSql'], $filters['dateSql'], $traderSql],
            $sql
        );
    }
}

if (!function_exists('finance_mark_paid')) {
    function finance_mark_paid($conn, string $adminId, string $traderId, array $filters, array $schema): string {
        if (!$schema['ready']) {
            throw new Exception('Trader payout tables are missing. Run the trader payout SQL script first.');
        }
        if ($traderId === '') {
            throw new Exception('Trader ID is missing.');
        }

        $itemSql = finance_build_unpaid_sql($schema, $filters, 'AND oi.TRADER_ID = :trader_id') . ' ORDER BY o.PICKUP_DATE ASC NULLS LAST, oi.ORDER_ID ASC, pr.PRODUCT_NAME ASC';
        $binds = $filters['binds'];
        $binds[':trader_id'] = $traderId;
        $items = db_all($conn, $itemSql, $binds);

        if (!$items) {
            throw new Exception('No payment due items were found for this trader and filter.');
        }

        $gross = 0.0;
        $fee = 0.0;
        $payout = 0.0;
        $units = 0;
        foreach ($items as $item) {
            $gross += (float)($item['LINE_TOTAL'] ?? 0);
            $fee += (float)($item['LINE_FEE'] ?? 0);
            $payout += (float)($item['LINE_PAYOUT'] ?? 0);
            $units += (int)($item['QUANTITY'] ?? 0);
        }
        $gross = round($gross, 2);
        $fee = round($fee, 2);
        $payout = round($payout, 2);

        if ($payout <= 0) {
            throw new Exception('Calculated payout is zero. Nothing was recorded.');
        }

        $periodStart = $filters['useDateRange'] ? $filters['startDate'] : '1900-01-01';
        $periodEnd = $filters['useDateRange'] ? $filters['displayEnd'] : '2999-12-31';
        $payoutId = finance_next_id($conn, 'SEQ_TRADER_PAYOUT_ID', 'TPAY', 8);

        $columns = ['PAYOUT_ID', 'TRADER_ID', 'PERIOD_START', 'PERIOD_END', 'GROSS_AMOUNT', 'PLATFORM_FEE', 'PAYOUT_AMOUNT', 'PAID_BY', 'NOTE'];
        $values = [':payout_id', ':trader_id', "TO_DATE(:period_start, 'YYYY-MM-DD')", "TO_DATE(:period_end, 'YYYY-MM-DD')", ':gross_amount', ':platform_fee', ':payout_amount', ':paid_by', ':note'];
        $payoutBinds = [
            ':payout_id' => $payoutId,
            ':trader_id' => $traderId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':gross_amount' => $gross,
            ':platform_fee' => $fee,
            ':payout_amount' => $payout,
            ':paid_by' => $adminId,
            ':note' => 'Manual trader payout recorded from finance report.',
        ];

        if ($schema['tp_item_rows']) {
            $columns[] = 'ITEM_ROWS';
            $values[] = ':item_rows';
            $payoutBinds[':item_rows'] = count($items);
        }
        if ($schema['tp_item_units']) {
            $columns[] = 'ITEM_UNITS';
            $values[] = ':item_units';
            $payoutBinds[':item_units'] = $units;
        }

        db_bind_and_execute($conn, 'INSERT INTO TRADER_PAYOUT (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')', $payoutBinds, OCI_NO_AUTO_COMMIT);

        foreach ($items as $item) {
            $itemColumns = ['PAYOUT_ID', 'ORDER_ID', 'PRODUCT_ID', 'TRADER_ID', 'QUANTITY', 'LOCKED_PRICE'];
            $itemValues = [':payout_id', ':order_id', ':product_id', ':trader_id', ':quantity', ':locked_price'];
            $itemBinds = [
                ':payout_id' => $payoutId,
                ':order_id' => $item['ORDER_ID'],
                ':product_id' => $item['PRODUCT_ID'],
                ':trader_id' => $item['TRADER_ID'],
                ':quantity' => (int)($item['QUANTITY'] ?? 0),
                ':locked_price' => (float)($item['LOCKED_PRICE'] ?? 0),
            ];

            if ($schema['tpi_line_total']) {
                $itemColumns[] = 'LINE_TOTAL';
                $itemValues[] = ':line_total';
                $itemBinds[':line_total'] = (float)($item['LINE_TOTAL'] ?? 0);
            }
            if ($schema['tpi_gross']) {
                $itemColumns[] = 'GROSS_AMOUNT';
                $itemValues[] = ':gross_amount';
                $itemBinds[':gross_amount'] = (float)($item['LINE_TOTAL'] ?? 0);
            }
            if ($schema['tpi_fee']) {
                $itemColumns[] = 'PLATFORM_FEE';
                $itemValues[] = ':platform_fee';
                $itemBinds[':platform_fee'] = (float)($item['LINE_FEE'] ?? 0);
            }
            if ($schema['tpi_payout']) {
                $itemColumns[] = 'PAYOUT_AMOUNT';
                $itemValues[] = ':payout_amount';
                $itemBinds[':payout_amount'] = (float)($item['LINE_PAYOUT'] ?? 0);
            }

            db_bind_and_execute($conn, 'INSERT INTO TRADER_PAYOUT_ITEM (' . implode(', ', $itemColumns) . ') VALUES (' . implode(', ', $itemValues) . ')', $itemBinds, OCI_NO_AUTO_COMMIT);
        }

        oci_commit($conn);
        return true;
    }
}

$filters = finance_filter_parts();
extract($filters);

$schema = ['ready' => false];
$traderOptions = [];
$dueRows = [];
$dueItems = [];
$paidRows = [];
$message = '';
$error = '';

try {
    if (!$conn) {
        throw new Exception('Database connection is not available.');
    }

    $schema = finance_schema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
        finance_mark_paid($conn, $adminId, trim((string)($_POST['trader_id'] ?? '')), $filters, $schema);

        $redirectParams = [
            'view' => $view === 'all' ? 'all' : 'paid',
            'period' => $period,
            'from' => $customFrom,
            'to' => $customTo,
            'trader' => trim((string)($_POST['trader_id'] ?? $trader)),
        ];
        header('Location: finance-report.php?' . http_build_query($redirectParams));
        exit;
    }

    $traderOptions = db_all($conn, "
        SELECT
            t.USER_ID AS TRADER_ID,
            TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS TRADER_NAME,
            NVL(MAX(s.SHOP_NAME), 'No shop recorded') AS SHOP_NAME
        FROM TRADER t
        JOIN \"USER\" u ON u.USER_ID = t.USER_ID
        LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
        GROUP BY t.USER_ID, u.FIRST_NAME, u.LAST_NAME
        ORDER BY TRADER_NAME ASC
    ");

    $unpaidBase = finance_build_unpaid_sql($schema, $filters);
    $dueSql = "
        SELECT
            q.TRADER_ID,
            q.TRADER_NAME,
            MAX(q.SHOP_NAME) AS SHOP_NAME,
            COUNT(DISTINCT q.ORDER_ID) AS ORDER_COUNT,
            COUNT(*) AS ITEM_ROWS,
            SUM(q.QUANTITY) AS ITEM_UNITS,
            SUM(q.LINE_TOTAL) AS GROSS_AMOUNT,
            SUM(q.LINE_FEE) AS PLATFORM_FEE,
            SUM(q.LINE_PAYOUT) AS PAYOUT_AMOUNT
        FROM ($unpaidBase) q
        GROUP BY q.TRADER_ID, q.TRADER_NAME
        ORDER BY PAYOUT_AMOUNT DESC, TRADER_NAME ASC
    ";
    $dueRows = db_all($conn, $dueSql, $binds);

    $dueItems = db_all($conn, $unpaidBase . ' ORDER BY PICKUP_DATE DESC NULLS LAST, ORDER_ID DESC, TRADER_NAME ASC', $binds);

    if ($schema['ready']) {
        $historyWhere = [];
        $historyBinds = [];
        if ($trader !== '') {
            $historyWhere[] = 'tp.TRADER_ID = :history_trader_id';
            $historyBinds[':history_trader_id'] = $trader;
        }
        if ($useDateRange) {
            $historyWhere[] = "TRUNC(tp.PAID_DATE) >= TO_DATE(:history_start, 'YYYY-MM-DD')";
            $historyWhere[] = "TRUNC(tp.PAID_DATE) < TO_DATE(:history_end, 'YYYY-MM-DD')";
            $historyBinds[':history_start'] = $startDate;
            $historyBinds[':history_end'] = $endDate;
        }
        $historySql = $historyWhere ? 'WHERE ' . implode(' AND ', $historyWhere) : '';
        $paidRows = db_all($conn, "
            SELECT
                tp.PAYOUT_ID,
                tp.TRADER_ID,
                TRIM(u.FIRST_NAME || ' ' || u.LAST_NAME) AS TRADER_NAME,
                NVL(MAX(s.SHOP_NAME), 'No shop recorded') AS SHOP_NAME,
                TO_CHAR(tp.PAID_DATE, 'YYYY-MM-DD HH24:MI') AS PAID_DATE,
                TO_CHAR(tp.PERIOD_START, 'YYYY-MM-DD') AS PERIOD_START,
                TO_CHAR(tp.PERIOD_END, 'YYYY-MM-DD') AS PERIOD_END,
                COUNT(tpi.ORDER_ID) AS ITEM_ROWS,
                NVL(SUM(tpi.QUANTITY), 0) AS ITEM_UNITS,
                tp.GROSS_AMOUNT,
                tp.PLATFORM_FEE,
                tp.PAYOUT_AMOUNT,
                tp.PAID_BY,
                tp.NOTE
            FROM TRADER_PAYOUT tp
            JOIN TRADER t ON t.USER_ID = tp.TRADER_ID
            JOIN \"USER\" u ON u.USER_ID = t.USER_ID
            LEFT JOIN SHOP s ON s.TRADER_ID = t.USER_ID
            LEFT JOIN TRADER_PAYOUT_ITEM tpi ON tpi.PAYOUT_ID = tp.PAYOUT_ID
            $historySql
            GROUP BY tp.PAYOUT_ID, tp.TRADER_ID, u.FIRST_NAME, u.LAST_NAME, tp.PAID_DATE,
                     tp.PERIOD_START, tp.PERIOD_END, tp.GROSS_AMOUNT, tp.PLATFORM_FEE,
                     tp.PAYOUT_AMOUNT, tp.PAID_BY, tp.NOTE
            ORDER BY tp.PAID_DATE DESC, tp.PAYOUT_ID DESC
        ", $historyBinds);
    }
} catch (Throwable $e) {
    if ($conn) {
        @oci_rollback($conn);
    }
    $error = shoplocalfy_public_exception_message($e, 'Could not load finance report.');
}

$totalDueGross = 0.0;
$totalDueFee = 0.0;
$totalDuePayout = 0.0;
$totalDueItems = 0;
$totalDueOrders = 0;
foreach ($dueRows as $row) {
    $totalDueGross += (float)($row['GROSS_AMOUNT'] ?? 0);
    $totalDueFee += (float)($row['PLATFORM_FEE'] ?? 0);
    $totalDuePayout += (float)($row['PAYOUT_AMOUNT'] ?? 0);
    $totalDueItems += (int)($row['ITEM_UNITS'] ?? 0);
    $totalDueOrders += (int)($row['ORDER_COUNT'] ?? 0);
}

$totalPaid = 0.0;
foreach ($paidRows as $row) {
    $totalPaid += (float)($row['PAYOUT_AMOUNT'] ?? 0);
}

$showDue = $view === 'due' || $view === 'all';
$showPaid = $view === 'paid' || $view === 'all';
$todayLabel = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=10" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=10" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=10" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy – Trader Payouts</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin/css/finance-report.css?v=20260520b">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>

    <main class="finance-page">
      <section class="finance-hero">
        <div>
          <p class="finance-kicker">Trader settlement</p>
          <h1>Finance Report</h1>
          <p>Review trader payment due, record manual payouts, and keep a payment history for future weekly settlements.</p>
        </div>
        <div class="finance-date-pill"><i class="fa-regular fa-calendar"></i> <?= finance_h($todayLabel) ?></div>
      </section>

      <form class="finance-filters" method="get" action="finance-report.php">
        <label>
          <span>View</span>
          <select name="view" onchange="this.form.submit()">
            <option value="due" <?= $view === 'due' ? 'selected' : '' ?>>Payment due</option>
            <option value="paid" <?= $view === 'paid' ? 'selected' : '' ?>>Payment done</option>
            <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>All payments</option>
          </select>
        </label>
        <label>
          <span>Period</span>
          <select name="period" onchange="this.form.submit()">
            <option value="last7" <?= $period === 'last7' ? 'selected' : '' ?>>Last 7 days</option>
            <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This month</option>
            <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All time</option>
            <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom</option>
          </select>
        </label>
        <label>
          <span>From</span>
          <input type="date" name="from" value="<?= finance_h($customFrom !== '' ? $customFrom : $startDate) ?>">
        </label>
        <label>
          <span>To</span>
          <input type="date" name="to" value="<?= finance_h($customTo !== '' ? $customTo : ($useDateRange ? $displayEnd : date('Y-m-d'))) ?>">
        </label>
        <label>
          <span>Trader</span>
          <select name="trader" onchange="this.form.submit()">
            <option value="">All traders</option>
            <?php foreach ($traderOptions as $option): ?>
              <?php $tid = (string)($option['TRADER_ID'] ?? ''); ?>
              <option value="<?= finance_h($tid) ?>" <?= $trader === $tid ? 'selected' : '' ?>>
                <?= finance_h(($option['SHOP_NAME'] ?? 'No shop') . ' — ' . ($option['TRADER_NAME'] ?? $tid)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
        <button type="button" class="ghost" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
      </form>

      <section class="finance-note">
        <strong><?= finance_h($periodLabel) ?></strong>
        <?php if ($useDateRange): ?>
          <span><?= finance_h($startDate) ?> to <?= finance_h($displayEnd) ?></span>
        <?php else: ?>
          <span>All dates</span>
        <?php endif; ?>
        <span>Only collected order items are included in trader payouts.</span>
      </section>

      <section class="finance-stats">
        <article><span>Payment due</span><strong><?= finance_money($totalDuePayout) ?></strong></article>
        <article><span>Payment done</span><strong><?= finance_money($totalPaid) ?></strong></article>
        <article><span>Platform revenue due</span><strong><?= finance_money($totalDueFee) ?></strong></article>
        <article><span>Orders / units due</span><strong><?= (int)$totalDueOrders ?> / <?= (int)$totalDueItems ?></strong></article>
      </section>

      <?php if ($showDue): ?>
        <section class="finance-card">
          <div class="finance-card-head">
            <div>
              <h2>Payment due</h2>
              <p>Collected order items that still need manual trader payment.</p>
            </div>
            <span><?= count($dueRows) ?> trader<?= count($dueRows) === 1 ? '' : 's' ?></span>
          </div>

          <?php if (!$dueRows): ?>
            <div class="finance-empty">No trader payments are due for the selected filters.</div>
          <?php else: ?>
            <div class="finance-table-wrap">
              <table class="finance-table payout-table">
                <thead>
                  <tr>
                    <th>Trader</th>
                    <th>Shop</th>
                    <th>Orders</th>
                    <th>Units</th>
                    <th>Gross</th>
                    <th>Platform revenue</th>
                    <th>Payment due</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($dueRows as $row): ?>
                    <tr>
                      <td><strong><?= finance_h($row['TRADER_NAME'] ?? $row['TRADER_ID'] ?? 'Trader') ?></strong><small><?= finance_h($row['TRADER_ID'] ?? '') ?></small></td>
                      <td><?= finance_h($row['SHOP_NAME'] ?? 'No shop recorded') ?></td>
                      <td><?= (int)($row['ORDER_COUNT'] ?? 0) ?></td>
                      <td><?= (int)($row['ITEM_UNITS'] ?? 0) ?></td>
                      <td><?= finance_money($row['GROSS_AMOUNT'] ?? 0) ?></td>
                      <td><?= finance_money($row['PLATFORM_FEE'] ?? 0) ?></td>
                      <td><strong><?= finance_money($row['PAYOUT_AMOUNT'] ?? 0) ?></strong></td>
                      <td>
                        <?php if ($schema['ready'] && (float)($row['PAYOUT_AMOUNT'] ?? 0) > 0): ?>
                          <form method="post" class="inline-pay-form" onsubmit="return confirm('Record this trader payment as done?');">
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="trader_id" value="<?= finance_h($row['TRADER_ID'] ?? '') ?>">
                            <input type="hidden" name="view" value="<?= finance_h($view) ?>">
                            <input type="hidden" name="period" value="<?= finance_h($period) ?>">
                            <input type="hidden" name="from" value="<?= finance_h($customFrom) ?>">
                            <input type="hidden" name="to" value="<?= finance_h($customTo) ?>">
                            <button type="submit" class="pay-btn">Mark payment done</button>
                          </form>
                        <?php else: ?>
                          <span class="muted-action">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($showPaid): ?>
        <section class="finance-card">
          <div class="finance-card-head">
            <div>
              <h2>Payment done</h2>
              <p>Manual trader payments recorded by the admin.</p>
            </div>
            <span><?= count($paidRows) ?> record<?= count($paidRows) === 1 ? '' : 's' ?></span>
          </div>

          <?php if (!$schema['ready']): ?>
            <div class="finance-empty">Payout history requires the payout tracking tables.</div>
          <?php elseif (!$paidRows): ?>
            <div class="finance-empty">No completed trader payments found for the selected filters.</div>
          <?php else: ?>
            <div class="finance-table-wrap">
              <table class="finance-table history-table">
                <thead>
                  <tr>
                    <th>Payout ID</th>
                    <th>Paid date</th>
                    <th>Trader</th>
                    <th>Period</th>
                    <th>Items</th>
                    <th>Gross</th>
                    <th>Platform revenue</th>
                    <th>Payment done</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paidRows as $row): ?>
                    <tr>
                      <td><strong><?= finance_h($row['PAYOUT_ID'] ?? '-') ?></strong></td>
                      <td><?= finance_h($row['PAID_DATE'] ?? '-') ?></td>
                      <td><?= finance_h($row['TRADER_NAME'] ?? '-') ?><small><?= finance_h($row['SHOP_NAME'] ?? '-') ?></small></td>
                      <td><?= finance_h(($row['PERIOD_START'] ?? '-') . ' to ' . ($row['PERIOD_END'] ?? '-')) ?></td>
                      <td><?= (int)($row['ITEM_ROWS'] ?? 0) ?> rows / <?= (int)($row['ITEM_UNITS'] ?? 0) ?> units</td>
                      <td><?= finance_money($row['GROSS_AMOUNT'] ?? 0) ?></td>
                      <td><?= finance_money($row['PLATFORM_FEE'] ?? 0) ?></td>
                      <td><strong><?= finance_money($row['PAYOUT_AMOUNT'] ?? 0) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($showDue): ?>
        <section class="finance-card">
          <div class="finance-card-head">
            <div>
              <h2>Payment due evidence</h2>
              <p>Collected order items currently included in the payment due calculation.</p>
            </div>
            <span><?= count($dueItems) ?> item row<?= count($dueItems) === 1 ? '' : 's' ?></span>
          </div>

          <?php if (!$dueItems): ?>
            <div class="finance-empty">No due item evidence for this filter.</div>
          <?php else: ?>
            <div class="finance-table-wrap">
              <table class="finance-table compact">
                <thead>
                  <tr>
                    <th>Order</th>
                    <th>Pickup</th>
                    <th>Trader / shop</th>
                    <th>Product</th>
                    <th>Status</th>
                    <th>Qty</th>
                    <th>Gross</th>
                    <th>Due</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($dueItems as $row): ?>
                    <tr>
                      <td><strong><?= finance_h($row['ORDER_ID'] ?? '-') ?></strong><small>Ordered <?= finance_h($row['ORDER_DATE'] ?? '-') ?></small></td>
                      <td><?= finance_h($row['PICKUP_DATE'] ?? '-') ?></td>
                      <td><?= finance_h($row['TRADER_NAME'] ?? '-') ?><small><?= finance_h($row['SHOP_NAME'] ?? '-') ?></small></td>
                      <td><?= finance_h($row['PRODUCT_NAME'] ?? $row['PRODUCT_ID'] ?? '-') ?></td>
                      <td><span class="status-pill"><?= finance_h($row['ITEM_STATUS'] ?? '-') ?></span></td>
                      <td><?= (int)($row['QUANTITY'] ?? 0) ?></td>
                      <td><?= finance_money($row['LINE_TOTAL'] ?? 0) ?></td>
                      <td><strong><?= finance_money($row['LINE_PAYOUT'] ?? 0) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>

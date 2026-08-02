<?php
require_once __DIR__ . '/trader_common.php';

$conn = trader_db_connection();
$traderId = require_trader_login();
$profile = get_trader_profile($conn, $traderId);
$errors = [];
$pendingCount = get_pending_order_count($conn, $traderId);

function analytics_pick_column($conn, $table, array $columns) {
    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return strtoupper($column);
        }
    }
    return null;
}

function analytics_pick_status_expression($statusCol): string {
    return $statusCol ? "UPPER(NVL(o.$statusCol, 'PENDING'))" : "'PENDING'";
}

function analytics_time_series_data($conn, $traderId, string $period, &$errors): array {
    $period = strtolower($period);
    $config = [
        '7d' => ['count' => 7, 'step' => 'day', 'label' => 'j M', 'displayEvery' => 1],
        '30d' => ['count' => 30, 'step' => 'day', 'label' => 'j M', 'displayEvery' => 7],
        '90d' => ['count' => 13, 'step' => 'week', 'label' => 'j M', 'displayEvery' => 1],
        '1y' => ['count' => 12, 'step' => 'month', 'label' => 'M', 'displayEvery' => 1],
    ][$period] ?? ['count' => 7, 'step' => 'day', 'label' => 'j M', 'displayEvery' => 1];

    $series = [];
    $today = new DateTimeImmutable('today');

    for ($i = $config['count'] - 1; $i >= 0; $i--) {
        if ($config['step'] === 'month') {
            $date = $today->modify('first day of this month')->modify("-$i months");
            $key = $date->format('Y-m-d');
        } elseif ($config['step'] === 'week') {
            $date = $today->modify('monday this week')->modify("-$i weeks");
            $key = $date->format('Y-m-d');
        } else {
            $date = $today->modify("-$i days");
            $key = $date->format('Y-m-d');
        }

        $index = count($series);
        $displayLabel = ($config['displayEvery'] <= 1 || $index % $config['displayEvery'] === 0 || $i === 0)
            ? $date->format($config['label'])
            : '';

        $series[$key] = [
            'label' => $date->format($config['label']),
            'display_label' => $displayLabel,
            'sales' => 0,
            'revenue' => 0,
            'orders' => 0,
            'customers' => 0,
        ];
    }

    if (!$conn) {
        $errors[] = 'Database connection is not available.';
        return $series;
    }

    if (!table_exists($conn, 'ORDER_ITEM') || !table_exists($conn, 'ORDERS')) {
        $errors[] = 'ORDER_ITEM or ORDERS table was not found.';
        return $series;
    }

    $orderDateCol = analytics_pick_column($conn, 'ORDERS', ['ORDER_DATE', 'CREATED_AT', 'CREATED_DATE', 'PLACED_AT']);
    if (!$orderDateCol) {
        $errors[] = 'ORDERS table needs an order date column for analytics.';
        return $series;
    }

    $statusCol = analytics_pick_column($conn, 'ORDERS', ['ORDER_STATUS', 'STATUS']);
    $qtyCol = analytics_pick_column($conn, 'ORDER_ITEM', ['QUANTITY', 'QTY', 'ORDER_QUANTITY']);
    $priceCol = analytics_pick_column($conn, 'ORDER_ITEM', ['LOCKED_PRICE', 'UNIT_PRICE', 'ITEM_PRICE', 'PRICE']);
    $customerCol = analytics_pick_column($conn, 'ORDERS', ['CUSTOMER_ID', 'USER_ID']);

    $statusExpr = analytics_pick_status_expression($statusCol);
    $qtyExpr = $qtyCol ? "NVL(oi.$qtyCol, 0)" : '1';
    $priceExpr = $priceCol ? "NVL(oi.$priceCol, 0)" : '0';
    $customerExpr = $customerCol ? "o.$customerCol" : 'NULL';

    if (!$qtyCol) {
        $errors[] = 'ORDER_ITEM quantity column was not found. Sales count is estimated by item row count.';
    }

    if (!$priceCol) {
        $errors[] = 'ORDER_ITEM price column was not found. Revenue is shown as £0.';
    }

    if ($config['step'] === 'month') {
        $bucketExpr = "TRUNC(o.$orderDateCol, 'MM')";
    } elseif ($config['step'] === 'week') {
        $bucketExpr = "TRUNC(o.$orderDateCol, 'IW')";
    } else {
        $bucketExpr = "TRUNC(o.$orderDateCol)";
    }

    $fromDate = array_key_first($series);

    try {
        $rows = db_all($conn, "
            SELECT
                TO_CHAR($bucketExpr, 'YYYY-MM-DD') AS BUCKET_KEY,
                NVL(SUM(CASE WHEN $statusExpr <> 'CANCELLED' THEN $qtyExpr ELSE 0 END), 0) AS SALES,
                NVL(SUM(CASE WHEN $statusExpr <> 'CANCELLED' THEN $qtyExpr * $priceExpr ELSE 0 END), 0) * :net_multiplier AS REVENUE,
                COUNT(DISTINCT CASE WHEN $statusExpr <> 'CANCELLED' THEN o.ORDER_ID END) AS ORDERS,
                COUNT(DISTINCT CASE WHEN $statusExpr <> 'CANCELLED' THEN $customerExpr END) AS CUSTOMERS
            FROM ORDER_ITEM oi
            INNER JOIN ORDERS o ON o.ORDER_ID = oi.ORDER_ID
            WHERE oi.TRADER_ID = :trader_id
              AND o.$orderDateCol >= TO_DATE(:from_date, 'YYYY-MM-DD')
            GROUP BY $bucketExpr
            ORDER BY $bucketExpr
        ", [
            ':trader_id' => $traderId,
            ':net_multiplier' => trader_net_multiplier(),
            ':from_date' => $fromDate,
        ]);

        foreach ($rows as $row) {
            $key = $row['BUCKET_KEY'] ?? '';
            if (isset($series[$key])) {
                $series[$key]['sales'] = (int)$row['SALES'];
                $series[$key]['revenue'] = (float)$row['REVENUE'];
                $series[$key]['orders'] = (int)$row['ORDERS'];
                $series[$key]['customers'] = (int)$row['CUSTOMERS'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Analytics query failed: ' . shoplocalfy_public_exception_message($e, 'Could not load analytics.');
    }

    return $series;
}

function analytics_package_series(array $series): array {
    return [
        'labels' => array_column($series, 'label'),
        'displayLabels' => array_column($series, 'display_label'),
        'sales' => array_column($series, 'sales'),
        'revenue' => array_map(fn($v) => round((float)$v, 2), array_column($series, 'revenue')),
        'orders' => array_column($series, 'orders'),
        'customers' => array_column($series, 'customers'),
    ];
}


$periodSeries = [
    '7d' => analytics_time_series_data($conn, $traderId, '7d', $errors),
    '30d' => analytics_time_series_data($conn, $traderId, '30d', $errors),
    '90d' => analytics_time_series_data($conn, $traderId, '90d', $errors),
    '1y' => analytics_time_series_data($conn, $traderId, '1y', $errors),
];

$chartData = [
    'periods' => [
        '7d' => analytics_package_series($periodSeries['7d']),
        '30d' => analytics_package_series($periodSeries['30d']),
        '90d' => analytics_package_series($periodSeries['90d']),
        '1y' => analytics_package_series($periodSeries['1y']),
    ],
];

$summarySeries = $periodSeries['1y'];
$totalSales = array_sum(array_column($summarySeries, 'revenue'));
$totalOrders = array_sum(array_column($summarySeries, 'orders'));
$avgOrder = $totalOrders > 0 ? $totalSales / $totalOrders : 0;
$totalCustomers = array_sum(array_column($summarySeries, 'customers'));
$ordersPerCustomer = $totalCustomers > 0 ? $totalOrders / $totalCustomers : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ShopLocalfy — Analytics</title>
  <?php render_base_css(); ?>
  <link rel="stylesheet" href="../assets/trader/css/analytics.css?v=20260519-trader-analytics-cleanup">
</head>
<body>
<?php $active = 'analytics'; $pendingOrderCount = $pendingCount; include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <?php render_topbar('Analytics', 'Track performance across your store'); ?>
  <div class="body">
    <?php if ($errors): ?><div class="notice"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

    <div class="analytics-header analytics-header-compact"><div class="period-tabs"><div class="period-tab active" data-period="7d">7D</div><div class="period-tab" data-period="30d">30D</div><div class="period-tab" data-period="90d">90D</div><div class="period-tab" data-period="1y">1Y</div></div></div>


    <div class="summary-section summary-section-top">
      <div class="summary-section-label">Performance Summary</div>
      <div class="summary-cards">
        <div class="summary-card">
          <div class="sc-icon money-icon">💷</div>
          <div class="sc-label">Net Revenue</div>
          <div class="sc-value"><?php echo money_fmt($totalSales); ?></div>
        </div>
        <div class="summary-card">
          <div class="sc-icon">🧾</div>
          <div class="sc-label">Total Orders</div>
          <div class="sc-value"><?php echo int_fmt($totalOrders); ?></div>
        </div>
        <div class="summary-card">
          <div class="sc-icon text-icon">📊</div>
          <div class="sc-label">Average Order Value</div>
          <div class="sc-value"><?php echo money_fmt($avgOrder); ?></div>
        </div>
        <div class="summary-card">
          <div class="sc-icon">👥</div>
          <div class="sc-label">Orders / Customer</div>
          <div class="sc-value"><?php echo number_format($ordersPerCustomer, 1); ?></div>
        </div>
      </div>
    </div>

    <div class="charts-grid charts-grid-two">
      <div class="chart-card emphasis-card">
        <div class="chart-card-head">
          <div>
            <div class="chart-card-title">Units Sold Trend</div>
            <div class="chart-card-sub">Shows product demand volume over time</div>
          </div>
          <span class="chart-card-type">Demand</span>
        </div>
        <div class="chart-card-body">
          <div class="chart-svg-wrap"><svg id="salesChartSvg"></svg><div class="chart-tooltip"></div></div>
          <div class="chart-x-labels" id="salesXLabels"></div>
          <div class="chart-legend"><div class="legend-item"><div class="legend-dot" style="background:#1D9E75"></div> Units sold</div></div>
        </div>
      </div>
      <div class="chart-card emphasis-card">
        <div class="chart-card-head">
          <div>
            <div class="chart-card-title">Net Revenue Trend</div>
            <div class="chart-card-sub">Shows revenue movement over time</div>
          </div>
          <span class="chart-card-type">Payout</span>
        </div>
        <div class="chart-card-body">
          <div class="bar-chart-wrap" id="revenueBarChart"></div><div class="chart-tooltip"></div>
          <div class="chart-legend"><div class="legend-item"><div class="legend-dot" style="background:#3DBFA4"></div> Net revenue</div></div>
        </div>
      </div>
    </div>

  </div>
</div>
<div class="toast" id="toast"></div>
<script>
const data = <?php echo json_encode($chartData, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES); ?>;
function safeMax(values){ return Math.max(1, ...values.map(v => Number(v) || 0)); }
function activePeriodData(period){ return data.periods?.[period] || data.periods?.['7d'] || {labels:[],displayLabels:[],sales:[],revenue:[]}; }
function formatMoney(v){ return '£' + (Number(v) || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); }

function placeChartTooltip(tooltip, wrap, html, x, y) {
  if (!tooltip || !wrap) return;
  tooltip.innerHTML = html;
  tooltip.classList.add('show');

  requestAnimationFrame(() => {
    const pad = 8;
    const tipW = tooltip.offsetWidth || 90;
    const tipH = tooltip.offsetHeight || 36;
    const wrapW = wrap.clientWidth || 0;
    const wrapH = wrap.clientHeight || 0;
    const left = Math.max(pad, Math.min(x + 10, Math.max(pad, wrapW - tipW - pad)));
    const top = Math.max(pad, Math.min(y - tipH - 8, Math.max(pad, wrapH - tipH - pad)));
    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
  });
}
function drawLineChart(svgId, values, color, fill, labels) {
  const svg = document.getElementById(svgId); if (!svg) return;
  values = values.map(v => Number(v) || 0);
  const W = svg.clientWidth || 400, H = svg.clientHeight || 150, pad = {top:20,right:10,bottom:10,left:10};
  const iW = W - pad.left - pad.right, iH = H - pad.top - pad.bottom, max = safeMax(values) * 1.15, n = Math.max(1, values.length);
  const xPos = i => n === 1 ? pad.left + iW / 2 : pad.left + (i / (n - 1)) * iW;
  const yPos = v => pad.top + iH - (v / max) * iH;
  const pts = values.map((v,i) => `${xPos(i)},${yPos(v)}`).join(' L ');
  let html = [0,.25,.5,.75,1].map(f => { const y = pad.top + iH - f * iH; return `<line x1="${pad.left}" y1="${y}" x2="${pad.left+iW}" y2="${y}" stroke="var(--border)" stroke-width="1" stroke-dasharray="3,3"/>`; }).join('');
  html += `<path d="M ${pts} L ${xPos(n-1)},${pad.top+iH} ${xPos(0)},${pad.top+iH} Z" fill="${fill}" opacity="0.10"/>`;
  html += `<path d="M ${pts}" fill="none" stroke="${color}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>`;
  html += values.map((v,i) => `<circle cx="${xPos(i)}" cy="${yPos(v)}" r="3.5" fill="${color}" stroke="white" stroke-width="1.5" class="chart-dot" data-label="${labels[i] || ''}" data-val="${v.toLocaleString()} units"/>`).join('');
  svg.innerHTML = html;
  svg.querySelectorAll('.chart-dot').forEach(dot => {
    dot.addEventListener('mouseenter', function(){
      const wrap = svg.parentElement;
      const tooltip = wrap?.querySelector('.chart-tooltip');
      if(!tooltip || !wrap) return;
      const x = parseFloat(this.getAttribute('cx')) || 0;
      const y = parseFloat(this.getAttribute('cy')) || 0;
      placeChartTooltip(tooltip, wrap, `<strong>${this.dataset.label}</strong><br>${this.dataset.val}`, x, y);
    });
    dot.addEventListener('mouseleave', () => svg.parentElement?.querySelector('.chart-tooltip')?.classList.remove('show'));
  });
}
function drawBarChart(containerId, values, labels, color1) {
  const container = document.getElementById(containerId); if (!container) return;
  values = values.map(v => Number(v) || 0); const max = safeMax(values), maxH = 110;
  container.innerHTML = values.map((v,i) => {
    const label = labels[i] || '';
    const valueText = formatMoney(v);
    return `<div class="bar-col-a ${label ? 'has-label' : 'no-label'}"><div class="bar-a" data-label="${label}" data-v="${valueText}" style="height:${Math.max(4,Math.round((v/max)*maxH))}px;background:linear-gradient(180deg,${color1},${color1}cc)"></div><span class="bar-lbl">${label}</span></div>`;
  }).join('');
  const wrap = container.closest('.chart-card-body');
  container.querySelectorAll('.bar-a').forEach(bar => {
    bar.addEventListener('mouseenter', function(){
      const tooltip = wrap?.querySelector('.chart-tooltip');
      if(!tooltip || !wrap) return;
      const label = this.dataset.label || 'Selected period';
      const x = container.offsetLeft + this.offsetLeft + (this.offsetWidth / 2);
      const y = container.offsetTop + this.offsetTop;
      placeChartTooltip(tooltip, wrap, `<strong>${label}</strong><br>${this.dataset.v}`, x, y);
    });
    bar.addEventListener('mouseleave', () => wrap?.querySelector('.chart-tooltip')?.classList.remove('show'));
  });
}
function drawAllCharts(period){
  const selected = activePeriodData(period);
  drawLineChart('salesChartSvg', selected.sales || [], '#1D9E75', '#1D9E75', selected.labels || []);
  drawBarChart('revenueBarChart', selected.revenue || [], selected.displayLabels || selected.labels || [], '#3DBFA4');
  const x=document.getElementById('salesXLabels'); if(x) x.innerHTML = (selected.displayLabels || selected.labels || []).map(m=>`<span>${m}</span>`).join('');
}
function initDateRange(){ const from=document.getElementById('dateFrom'); const to=document.getElementById('dateTo'); if(!from || !to) return; const today=new Date(); const monthAgo=new Date(today); monthAgo.setMonth(today.getMonth()-1); const fmt=d=>d.toISOString().split('T')[0]; from.value=fmt(monthAgo); to.value=fmt(today); }
function showToast(msg){ const t=document.getElementById('toast'); if(!t)return; t.textContent=msg; t.classList.add('show'); clearTimeout(t._timer); t._timer=setTimeout(()=>t.classList.remove('show'),2800); }
document.querySelectorAll('.period-tab').forEach(tab => tab.addEventListener('click', function(){ document.querySelectorAll('.period-tab').forEach(t=>t.classList.remove('active')); this.classList.add('active'); drawAllCharts(this.dataset.period); }));
window.addEventListener('load', () => { initDateRange(); drawAllCharts('7d'); });
window.addEventListener('resize', () => { const active=document.querySelector('.period-tab.active'); drawAllCharts(active ? active.dataset.period : '1y'); });
</script>
</body>
</html>


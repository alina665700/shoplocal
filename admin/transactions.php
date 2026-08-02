<?php
// admin/transactions.php
require_once __DIR__ . '/admin_common.php';

$adminId = require_admin_login();

date_default_timezone_set('Asia/Kathmandu');

if (!function_exists('admin_h')) {
    function admin_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_money')) {
    function admin_money($value) {
        return '£' . number_format((float)$value, 2);
    }
}

$transactions = [];
$transactionError = '';

try {
    $transactions = admin_payment_rows();
} catch (Throwable $e) {
    $transactionError = shoplocalfy_public_exception_message($e, 'Could not load transactions.');
}

$totalTransactions = count($transactions);
$totalAmount = 0;
$completedTransactions = 0;
$refundedTransactions = 0;
$failedTransactions = 0;

foreach ($transactions as $transaction) {
    $totalAmount += (float)($transaction['AMOUNT_PAID'] ?? 0);
    $status = strtolower(trim((string)($transaction['PAYMENT_STATUS'] ?? 'failed')));

    if (in_array($status, ['completed', 'complete', 'paid', 'success', 'successful'], true)) {
        $completedTransactions++;
    } elseif (in_array($status, ['refunded', 'refund'], true)) {
        $refundedTransactions++;
    } else {
        $failedTransactions++;
    }
}

$todayLabel = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=10" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=10" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=10" type="image/png" sizes="512x512">
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ShopLocalfy – Transactions</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/admin/css/transactions.css?v=20260517">
</head>
<body>
<div class="layout-wrapper">
  <?php include 'sidebar.php'; ?>
  <div class="main-content">
    <?php include 'topbar.php'; ?>

    <main class="transaction-page">
      <section class="txn-hero">
        <div>
          <p class="txn-kicker">Admin Control Centre</p>
          <h1 class="txn-title">Transactions</h1>
          <p class="txn-subtitle">A clean overview of all payment records, customers, payment methods and transaction references.</p>
        </div>
        <div class="date-pill"><i class="fa-regular fa-calendar"></i> <?= admin_h($todayLabel) ?></div>
      </section>

      <section class="txn-stats" aria-label="Transaction filters">
        <button class="txn-stat is-active" type="button" data-status-filter="ALL">
          <span class="txn-icon-box blue"><i class="fa-solid fa-receipt"></i></span>
          <span><span class="stat-label">Total Transactions</span><span class="stat-value"><?= (int)$totalTransactions ?></span></span>
        </button>
        <button class="txn-stat" type="button" data-status-filter="COMPLETED">
          <span class="txn-icon-box"><i class="fa-solid fa-circle-check"></i></span>
          <span><span class="stat-label">Completed</span><span class="stat-value"><?= (int)$completedTransactions ?></span></span>
        </button>
        <button class="txn-stat" type="button" data-status-filter="REFUNDED">
          <span class="txn-icon-box orange"><i class="fa-solid fa-rotate-left"></i></span>
          <span><span class="stat-label">Refunded</span><span class="stat-value"><?= (int)$refundedTransactions ?></span></span>
        </button>
        <button class="txn-stat" type="button" data-status-filter="FAILED">
          <span class="txn-icon-box red"><i class="fa-solid fa-circle-xmark"></i></span>
          <span><span class="stat-label">Failed</span><span class="stat-value"><?= (int)$failedTransactions ?></span></span>
        </button>
      </section>

      <section class="txn-card-shell">
        <div class="txn-card-header">
          <div>
            <h2 class="txn-section-title">Payment Transactions</h2>
            <p class="txn-section-note">Search by order, customer, payment ID, method, amount or status.</p>
          </div>
          <span class="count-pill"><i class="fa-solid fa-sterling-sign"></i> <?= admin_money($totalAmount) ?> total</span>
        </div>

        <div class="txn-tools">
          <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="transactionSearch" placeholder="Search payment ID, order, customer or method" autocomplete="off">
          </div>
          <select class="soft-select" id="transactionStatus" aria-label="Transaction status filter">
            <option value="ALL">All statuses</option>
            <option value="COMPLETED">Completed</option>
            <option value="REFUNDED">Refunded</option>
            <option value="FAILED">Failed</option>
          </select>
        </div>

        <?php if ($transactionError !== ''): ?>
          <div class="txn-empty-state">
            <i class="fa-solid fa-triangle-exclamation txn-empty-icon"></i>
            <p class="txn-empty-text">Could not load transactions: <?= admin_h($transactionError) ?></p>
          </div>
        <?php elseif (empty($transactions)): ?>
          <div class="txn-empty-state">
            <i class="fa-solid fa-receipt txn-empty-icon"></i>
            <p class="txn-empty-text">No transactions to display.</p>
          </div>
        <?php else: ?>
          <div class="txn-list" id="transactionList">
            <?php foreach ($transactions as $txn): ?>
              <?php
                $rawStatus = strtolower(trim((string)($txn['PAYMENT_STATUS'] ?? 'failed')));
                $filterStatus = 'FAILED';
                if (in_array($rawStatus, ['completed', 'complete', 'paid', 'success', 'successful'], true)) {
                    $filterStatus = 'COMPLETED';
                    $statusClass = 'status-approved';
                    $statusIcon = 'fa-circle-check';
                } elseif (in_array($rawStatus, ['refunded', 'refund'], true)) {
                    $filterStatus = 'REFUNDED';
                    $statusClass = 'status-pending';
                    $statusIcon = 'fa-rotate-left';
                } else {
                    $filterStatus = 'FAILED';
                    $statusClass = 'status-failed';
                    $statusIcon = 'fa-circle-xmark';
                }
              ?>
              <article class="txn-card" data-status="<?= admin_h($filterStatus) ?>">
                <div class="txn-header">
                  <div class="txn-header-left">
                    <div class="txn-id"><?= admin_h($txn['PAYMENT_ID'] ?? '-') ?></div>
                    <div class="txn-meta">Order #<?= admin_h($txn['ORDER_ID'] ?? '-') ?> &nbsp;·&nbsp; <?= admin_h($txn['PAYMENT_DATE'] ?? '-') ?></div>
                  </div>
                  <div class="txn-header-right">
                    <div class="txn-amount"><?= admin_money($txn['AMOUNT_PAID'] ?? 0) ?></div>
                    <span class="txn-status <?= admin_h($statusClass) ?>">
                      <i class="fa-solid <?= admin_h($statusIcon) ?>"></i> <?= admin_h($txn['PAYMENT_STATUS'] ?? 'FAILED') ?>
                    </span>
                  </div>
                </div>

                <div class="txn-divider"></div>

                <div class="txn-body">
                  <div class="txn-row">
                    <div class="txn-icon"><i class="fa-solid fa-user"></i></div>
                    <span class="txn-label">Customer</span>
                    <span class="txn-value"><?= admin_h($txn['CUSTOMER_NAME'] ?? $txn['CUSTOMER_ID'] ?? '-') ?></span>
                  </div>
                  <div class="txn-row">
                    <div class="txn-icon"><i class="fa-solid fa-credit-card"></i></div>
                    <span class="txn-label">Payment Method</span>
                    <span class="txn-value"><?= admin_h($txn['PAYMENT_METHOD'] ?? '-') ?></span>
                  </div>
                  <div class="txn-row">
                    <div class="txn-icon"><i class="fa-solid fa-hashtag"></i></div>
                    <span class="txn-label">Reference No.</span>
                    <span class="txn-value"><?= admin_h($txn['PAYMENT_ID'] ?? '-') ?></span>
                  </div>
                  <div class="txn-row">
                    <div class="txn-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                    <span class="txn-label">Amount</span>
                    <span class="txn-value"><?= admin_money($txn['AMOUNT_PAID'] ?? 0) ?></span>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <?php include 'footer.php'; ?>
  </div>
</div>
<script src="../assets/admin/js/transactions.js?v=20260517"></script>
</body>
</html>

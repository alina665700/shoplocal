<?php

session_start();
require_once __DIR__ . '/../config/helpers.php';

$message = '';
$error = '';

try {
    $existing = fetch_one(
        $conn,
        'SELECT COUNT(*) AS TOTAL FROM "USER" WHERE user_role = :role',
        [':role' => 'ADMIN']
    );

    $adminCount = (int)($existing['TOTAL'] ?? 0);
} catch (Exception $e) {
    $adminCount = 0;
    $error = shoplocalfy_public_exception_message($e, 'Could not complete setup.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCount === 0) {
    try {
        $newUserId = create_user_with_role(
            $conn,
            trim($_POST['first_name'] ?? ''),
            trim($_POST['last_name'] ?? ''),
            trim($_POST['email_address'] ?? ''),
            trim($_POST['ph_number'] ?? ''),
            $_POST['password'] ?? '',
            'ADMIN',
            1
        );

        $message = 'Admin created successfully. Generated admin ID: ' . $newUserId;
        $adminCount++;
    } catch (Exception $e) {
        $error = shoplocalfy_public_exception_message($e, 'Could not complete setup.');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCount > 0) {
    $error = 'Setup is locked because an admin already exists.';
}

?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="../config/logos/favicon.ico?v=9" sizes="any">
  <link rel="icon" href="../config/logos/favicon.svg?v=9" type="image/svg+xml">
  <link rel="icon" href="../config/logos/favicon.png?v=9" type="image/png" sizes="512x512">

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create First Admin</title>

  <link rel="stylesheet" href="../assets/shared/css/setup-create_first_admin.css?v=20260517">
</head>

<body>
  <div class="container narrow">
    <h1>Create First Admin</h1>

    <?php if ($message): ?>
      <div class="alert success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($adminCount > 0): ?>
      <div class="alert info">At least one admin already exists.</div>
    <?php endif; ?>

    <?php if ($adminCount === 0): ?>
      <form method="post" class="card">
        <label>First name</label>
        <input name="first_name" value="Admin" required>

        <label>Last name</label>
        <input name="last_name" value="User" required>

        <label>Email address</label>
        <input type="email" name="email_address" value="admin@example.com" required>

        <label>Phone number</label>
        <input name="ph_number" value="9800000000" required>

        <label>Password</label>
        <input type="password" name="password" value="Admin123!" required>

        <button type="submit">Create Admin</button>
      </form>
    <?php else: ?>
      <div class="card">
        <p>Setup is locked because an admin account already exists.</p>
      </div>
    <?php endif; ?>

    <p><a href="../admin/login.php">Go to admin login</a></p>
  </div>
</body>
</html>
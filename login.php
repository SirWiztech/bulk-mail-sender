<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (hash_equals(ADMIN_USER, $u) && hash_equals(ADMIN_PASS, $p)) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Log in - Bulk Mailer</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <h1>📧 Bulk Mailer</h1>
    <p class="subtitle">Sign in to continue</p>
    <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <label>Username</label>
      <input type="text" name="username" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <button class="btn" type="submit" style="width:100%">Log in</button>
    </form>
  </div>
</div>
</body>
</html>

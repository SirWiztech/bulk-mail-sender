<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();

$token = $_GET['token'] ?? '';
$contactId = verify_unsub_token($token);
$message = '';
$success = false;

if ($contactId) {
    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id=?");
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch();

    if ($contact) {
        $pdo->prepare("UPDATE contacts SET status='unsubscribed' WHERE id=?")->execute([$contactId]);
        $success = true;
        $message = "You (" . $contact['email'] . ") have been unsubscribed and will no longer receive emails from us.";
    } else {
        $message = "We couldn't find that subscription. It may have already been removed.";
    }
} else {
    $message = "This unsubscribe link is invalid or has expired.";
}
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Unsubscribe</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box" style="width:420px; text-align:center;">
    <h1><?= $success ? '✅ Unsubscribed' : '⚠️ Oops' ?></h1>
    <p class="subtitle"><?= h($message) ?></p>
  </div>
</div>
</body>
</html>

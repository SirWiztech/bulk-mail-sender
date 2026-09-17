<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_login();
$flashes = get_flashes();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? h($pageTitle) . ' - ' : '' ?>Bulk Mailer</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
  <nav class="sidebar">
    <div class="brand">📧 Bulk Mailer</div>
    <a href="index.php" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="contacts.php" class="<?= ($active ?? '') === 'contacts' ? 'active' : '' ?>">Contacts</a>
    <a href="lists.php" class="<?= ($active ?? '') === 'lists' ? 'active' : '' ?>">Lists</a>
    <a href="campaigns.php" class="<?= ($active ?? '') === 'campaigns' ? 'active' : '' ?>">Campaigns</a>
    <a href="campaign_new.php" class="<?= ($active ?? '') === 'new_campaign' ? 'active' : '' ?>">+ New Campaign</a>
    <a href="logout.php" class="logout">Log out</a>
  </nav>
  <main class="content">
    <?php foreach ($flashes as $f): ?>
      <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
    <?php endforeach; ?>

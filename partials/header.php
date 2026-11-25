<?php
/**
 * Shared page header.
 * Expects: $pageTitle (string), $root (relative path to app root, e.g. "../"), optional $showLogout (bool)
 */
$showLogout = $showLogout ?? true;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · <?= e(config('app_name')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e($root) ?>assets/css/app.css">
  <script>window.APP_ROOT = <?= json_encode($root) ?>; window.API_BASE = <?= json_encode($root . 'api/') ?>;</script>
  <script src="<?= e($root) ?>assets/js/api.js" defer></script>
</head>
<body>
<header class="topbar">
  <div class="brand">
    <div class="brand-logo">ECD</div>
    <div>
      <div class="brand-title"><?= e(config('app_name')) ?></div>
      <div class="brand-sub"><?= e(config('university')) ?></div>
    </div>
  </div>
  <?php if ($showLogout && current_user()): ?>
    <a class="btn-ghost-light" href="<?= e($root) ?>api/logout.php">Log out</a>
  <?php endif; ?>
</header>

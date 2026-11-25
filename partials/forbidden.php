<?php
// Shown when a logged-in user opens another role's page (Requirement 5).
$pageTitle = 'Access denied';
$root = $rootPrefix;
require __DIR__ . '/header.php';
?>
<main class="page">
  <div class="card center" style="margin-top:32px">
    <h1>Access denied</h1>
    <p class="muted">This page is for <?= e($role) ?> accounts only. You are logged in as a <?= e($user['role']) ?>.</p>
    <a class="btn btn-primary" href="<?= e($home) ?>">Go to my page</a>
  </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>

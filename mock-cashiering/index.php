<?php
/**
 * MOCK cashier screen — a stand-in for the real Cashiering System so the
 * Registration Portal can be tested end-to-end. Replace with the real
 * Cashiering System (cashier view) when it is ready.
 */
require __DIR__ . '/store.php';

$message = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle'])) {
        mock_store(function ($d) { $d['online'] = !$d['online']; return $d; });
    } elseif (isset($_POST['pay'])) {
        [$a, $err] = mock_record_payment((string)$_POST['ref'], $_POST['amount'] ?? 0, (string)($_POST['method'] ?? 'Cash'));
        if ($err) {
            $error = $err;
        } else {
            $message = "Payment recorded for {$a['enrollment_ref']} · {$a['or_number']}";
        }
    }
}
$store = mock_store();
$list = array_reverse($store['assessments']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mock Cashiering System</title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<header class="topbar" style="background:#4d5d73">
  <div class="brand"><div class="brand-logo" style="color:#4d5d73">₱</div>
    <div><div class="brand-title">Cashiering System (MOCK)</div><div class="brand-sub">For testing the Registration Portal only — simulated payments</div></div></div>
  <a class="btn-ghost-light" href="../api/logout.php">Log out</a>
</header>
<main class="page page-wide">
  <div class="row" style="margin-bottom:14px">
    <h1 style="margin:0">Fee assessments</h1><span class="spacer"></span>
    <form method="post">
      <span class="chip <?= $store['online'] ? 'chip-success' : 'chip-danger' ?>"><?= $store['online'] ? '● Online' : '✕ Offline' ?></span>
      <button class="btn btn-outline btn-sm" name="toggle" value="1"><?= $store['online'] ? 'Simulate outage' : 'Bring back online' ?></button>
    </form>
  </div>
  <?php if ($message): ?><div class="alert alert-note"><?= $h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger">⚠ <?= $h($error) ?></div><?php endif; ?>
  <div class="card">
    <table class="table">
      <thead><tr><th>Assessment</th><th>Student</th><th>Enrollment ref</th><th>Items</th><th class="num">Total</th><th>Status</th><th>Record payment</th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="7" class="muted">No assessments yet. Submit an enrollment in the portal.</td></tr><?php endif; ?>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><strong><?= $h($a['assessment_no']) ?></strong></td>
          <td><?= $h($a['student_name']) ?><div class="muted small"><?= $h($a['student_no']) ?> · <?= (int)$a['total_units'] ?> units</div></td>
          <td class="small"><?= $h($a['enrollment_ref']) ?></td>
          <td class="small"><?php foreach ($a['items'] as $i) echo $h($i['label']) . ': ₱' . number_format($i['amount'], 2) . '<br>'; ?></td>
          <td class="num">₱<?= number_format($a['total'], 2) ?></td>
          <td><?= $a['status'] === 'paid'
              ? '<span class="chip chip-success">● Paid</span><div class="muted small">' . $h($a['or_number']) . '</div>'
              : '<span class="chip">○ Unpaid</span>' ?></td>
          <td>
            <?php if ($a['status'] !== 'paid'): ?>
            <form method="post" class="row" style="flex-wrap:nowrap">
              <input type="hidden" name="ref" value="<?= $h($a['enrollment_ref']) ?>">
              <input class="input" name="amount" type="number" step="0.01" value="<?= $h($a['total']) ?>" style="width:110px">
              <button class="btn btn-primary btn-sm" name="pay" value="1">Record</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>

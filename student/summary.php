<?php
// Printable enrollment summary ("Download summary" → Save as PDF from the print dialog).
require __DIR__ . '/../lib/bootstrap.php';

$root = '../';
$user = page_require_role('student', $root);
$enr = student_enrollment_view($user);
if ($enr['stage'] !== 'paid') {
    header('Location: enroll.php');
    exit;
}
$pageTitle = 'Enrollment summary';
require __DIR__ . '/../partials/header.php';
?>
<main class="page">
  <div class="card">
    <h1>Certificate of Registration</h1>
    <div class="kv" style="grid-template-columns:130px 1fr;margin-bottom:16px">
      <div class="muted">Name</div><div><?= e($user['last_name'] . ', ' . $user['first_name']) ?></div>
      <div class="muted">Student ID</div><div><?= e($user['student_no']) ?> · <?= e($user['program']) ?></div>
      <div class="muted">Term</div><div><?= e($enr['term']) ?></div>
      <div class="muted">Reference no.</div><div><?= e($enr['reference_no']) ?></div>
      <div class="muted">Assessment no.</div><div><?= e($enr['payment']['assessment_no']) ?></div>
      <div class="muted">OR number</div><div><?= e($enr['payment']['or_number']) ?></div>
      <div class="muted">Amount paid</div><div>₱<?= number_format((float)$enr['payment']['amount_paid'], 2) ?></div>
      <div class="muted">Status</div><div>Enrolled · Paid</div>
    </div>
    <table class="table">
      <thead><tr><th>Code</th><th>Title</th><th>Section</th><th>Schedule</th><th class="num">Units</th></tr></thead>
      <tbody>
      <?php foreach ($enr['subjects'] as $s): ?>
        <tr><td><?= e($s['code']) ?></td><td><?= e($s['title']) ?></td><td><?= e($s['section_code']) ?></td><td><?= e($s['schedule']) ?></td><td class="num"><?= (int)$s['units'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total units</td><td class="num"><?= (int)$enr['total_units'] ?></td></tr></tfoot>
    </table>
    <h2 style="margin-top:20px">Fees</h2>
    <table class="table">
      <tbody>
      <?php foreach ($enr['payment']['items'] as $i): ?>
        <tr><td><?= e($i['label']) ?></td><td class="num">₱<?= number_format($i['amount'], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="num">₱<?= number_format((float)$enr['payment']['total'], 2) ?></td></tr></tfoot>
    </table>
    <div class="row row-end no-print" style="margin-top:16px">
      <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    </div>
  </div>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>

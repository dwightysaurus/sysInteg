<?php
require __DIR__ . '/../lib/bootstrap.php';

$root = '../';
$role = 'student';
$user = page_require_role('student', $root);
$enr = student_enrollment_view($user);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// Status chip, message and button for each stage
switch ($enr['stage']) {
    case 'selecting':
        $chip = '<span class="chip">Draft</span>';
        $msg = sprintf('You selected %d subject(s), %d units. Submit your enrollment to get your fee assessment.', count($enr['subjects']), $enr['total_units']);
        $btn = ['Continue enrollment', 'enroll.php'];
        break;
    case 'pending_sync':
        $chip = '<span class="chip chip-pending">• Pending</span>';
        $msg = 'Your enrollment is saved. Payment status will update once the Cashiering System is available.';
        $btn = ['View payment status', 'enroll.php?step=4'];
        break;
    case 'assessed':
        $chip = '<span class="chip chip-pending">• Pending</span>';
        $msg = 'Waiting for the cashier to record your payment of ' . '₱' . number_format((float)$enr['payment']['total'], 2) . '.';
        $btn = ['View payment status', 'enroll.php?step=4'];
        break;
    case 'paid':
        $chip = '<span class="chip chip-success">✓ Enrolled</span> <span class="chip chip-success">● Paid</span>';
        $msg = 'OR number ' . $enr['payment']['or_number'] . ' · ' . $enr['total_units'] . ' units';
        $btn = ['View enrollment', 'enroll.php'];
        break;
    default:
        $chip = '<span class="chip">Unpaid</span>';
        $msg = 'You have not started enrollment yet.';
        $btn = ['Start enrollment', 'enroll.php'];
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../partials/header.php';
?>
<main class="page" style="padding-top:48px">
  <h1 style="margin-bottom:6px"><?= e($greeting) ?>, <?= e($user['first_name']) ?>!</h1>
  <p class="muted small" style="margin:0 0 14px">Student ID <?= e($user['student_no']) ?> · <?= e($user['program']) ?></p>
  <div class="card">
    <div class="muted small">Term</div>
    <h2 style="margin:2px 0 14px"><?= e($enr['term']) ?></h2>
    <div class="row" style="margin-bottom:12px">Enrollment status <?= $chip ?></div>
    <p class="small" style="margin:0 0 14px"><?= e($msg) ?></p>
    <a class="btn btn-primary" style="background:#2b4a30;border-color:#2b4a30" href="<?= e($btn[1]) ?>"><?= e($btn[0]) ?></a>
  </div>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>

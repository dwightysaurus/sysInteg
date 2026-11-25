<?php
// POST -> "Retry" button.
//   - If the enrollment was never received by the Cashiering System, send it again.
//   - If it was, ask the Cashiering System for the latest payment status again.
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST');
    $user = api_require_role('student');
    $view = student_enrollment_view($user);
    if (!$view['id'] || $view['status'] !== 'submitted') {
        throw new ApiError('NOT_SUBMITTED', 'Submit your enrollment first.', 409);
    }
    if ($view['sync']['status'] !== 'synced') {
        $reachable = sync_enrollment($view['id']);
    } else {
        $reachable = refresh_from_cashiering($view['id']);
    }
    $view = student_enrollment_view($user);
    $view['cashiering_reachable'] = $reachable;
    $view['demo_mode'] = (bool)config('demo_mode');
    ok($view);
});

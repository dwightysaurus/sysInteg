<?php
// POST -> submit the enrollment (step 2 -> 3).
// The enrollment is saved first; then it is sent to the Cashiering System.
// If Cashiering is down the response still succeeds with stage "pending_sync" (Requirement 4).
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST');
    $user = api_require_role('student');
    $id = submit_enrollment($user);
    $synced = sync_enrollment($id);
    $view = student_enrollment_view($user);
    $view['cashiering_reachable'] = $synced;
    $view['demo_mode'] = (bool)config('demo_mode');
    ok($view);
});

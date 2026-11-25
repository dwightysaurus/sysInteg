<?php
// GET            -> the student's enrollment for the current term
// GET ?refresh=1 -> also asks the Cashiering System for the latest payment status
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('GET');
    $user = api_require_role('student');
    $view = student_enrollment_view($user);

    $reachable = null;
    if (!empty($_GET['refresh']) && $view['id'] && $view['stage'] === 'assessed') {
        $reachable = refresh_from_cashiering($view['id']);
        $view = student_enrollment_view($user);
    }
    $view['cashiering_reachable'] = $reachable;
    $view['demo_mode'] = (bool)config('demo_mode');
    ok($view);
});

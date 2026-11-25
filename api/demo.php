<?php
/**
 * DEMO ONLY (config demo_mode = true). Powers the two "Demo:" buttons on the
 * Payment status screen by talking to the bundled mock Cashiering System.
 *   POST {action: "pay"}      -> mock cashier records payment for my enrollment
 *   POST {action: "offline"}  -> mock Cashiering System goes "down"
 *   POST {action: "online"}   -> mock Cashiering System comes back
 */
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST');
    if (!config('demo_mode')) {
        throw new ApiError('FORBIDDEN', 'Demo mode is off.', 403);
    }
    $user = api_require_role('student');
    $action = (string)(input()['action'] ?? '');
    $view = student_enrollment_view($user);

    try {
        if ($action === 'pay') {
            if (!$view['reference_no'] || $view['payment']['total'] === null) {
                throw new ApiError('NOT_ASSESSED', 'There is no fee assessment to pay yet.', 409);
            }
            cashiering_request('POST', 'demo.php', [
                'action' => 'pay', 'enrollment_ref' => $view['reference_no'], 'amount' => $view['payment']['total'],
            ]);
            refresh_from_cashiering($view['id']);
        } elseif ($action === 'offline' || $action === 'online') {
            cashiering_request('POST', 'demo.php', ['action' => $action]);
        } else {
            throw new ApiError('VALIDATION', 'Unknown action.', 422);
        }
    } catch (CashieringUnavailable $e) {
        throw new ApiError('CASHIERING_UNAVAILABLE', $e->getMessage(), 503);
    }
    ok(student_enrollment_view($user));
});

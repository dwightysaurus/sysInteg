<?php
/**
 * Called BY the Cashiering System when the cashier records a payment
 * (or when an assessment changes). Authenticated with the shared X-API-Key.
 *
 * POST {
 *   "enrollment_ref": "EN-2026-000001",
 *   "assessment_no": "AS-7001",          (optional)
 *   "status": "paid",
 *   "or_number": "OR-2026-004217",
 *   "amount_paid": 5300.00,
 *   "paid_at": "2026-11-25 10:15:00"     (optional)
 * }
 */
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST');
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals((string)config('cashiering.api_key'), (string)$key)) {
        throw new ApiError('UNAUTHENTICATED', 'Invalid API key.', 401);
    }
    $in = input();
    $ref = trim((string)($in['enrollment_ref'] ?? ''));
    $enr = $ref !== '' ? find_enrollment_by_ref($ref) : null;
    if (!$enr) {
        throw new ApiError('NOT_FOUND', 'Unknown enrollment_ref.', 404);
    }
    if (($in['status'] ?? '') !== 'paid') {
        throw new ApiError('VALIDATION', 'Only status "paid" is accepted.', 422);
    }
    if (!empty($in['assessment_no']) && $enr['assessment_no'] && $in['assessment_no'] !== $enr['assessment_no']) {
        throw new ApiError('MISMATCH', 'assessment_no does not match this enrollment.', 409);
    }
    apply_payment((int)$enr['id'], $in);
    $enr = find_enrollment_by_id((int)$enr['id']);
    ok([
        'enrollment_ref' => $enr['reference_no'],
        'payment_status' => $enr['payment_status'],
        'or_number'      => $enr['or_number'],
    ]);
});

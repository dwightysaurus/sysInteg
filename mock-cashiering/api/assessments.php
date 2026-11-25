<?php
/**
 * MOCK Cashiering API
 *   POST /assessments.php                       create (or return existing) assessment for an enrollment
 *   GET  /assessments.php?enrollment_ref=REF    get assessment and payment status
 */
require __DIR__ . '/../store.php';

mock_require_key();
if (!mock_store()['online']) {
    mock_json(['ok' => false, 'error' => ['code' => 'UNAVAILABLE', 'message' => 'Cashiering System is offline (demo).']], 503);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ref = (string)($_GET['enrollment_ref'] ?? '');
    $a = mock_store()['assessments'][$ref] ?? null;
    if (!$a) {
        mock_json(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'No assessment for that enrollment_ref.']], 404);
    }
    mock_json(['ok' => true, 'data' => ['assessment' => mock_public($a)]]);
}

if ($method === 'POST') {
    $p = mock_input();
    if (empty($p['enrollment_ref']) || empty($p['student']['student_no']) || empty($p['subjects']) || !is_array($p['subjects'])) {
        mock_json(['ok' => false, 'error' => ['code' => 'VALIDATION', 'message' => 'enrollment_ref, student and subjects are required.']], 422);
    }
    $created = false;
    $d = mock_store(function ($d) use ($p, &$created) {
        $ref = $p['enrollment_ref'];
        if (!isset($d['assessments'][$ref])) {           // idempotent: same ref -> same assessment
            $fees = mock_assess($p);
            $d['assessments'][$ref] = [
                'assessment_no'  => 'AS-' . $d['next_assessment']++,
                'enrollment_ref' => $ref,
                'student_no'     => $p['student']['student_no'],
                'student_name'   => trim(($p['student']['last_name'] ?? '') . ', ' . ($p['student']['first_name'] ?? ''), ', '),
                'term'           => $p['term'] ?? '',
                'total_units'    => (int)$p['total_units'],
                'items'          => $fees['items'],
                'total'          => $fees['total'],
                'status'         => 'unpaid',
                'amount_paid'    => null,
                'or_number'      => null,
                'paid_at'        => null,
                'created_at'     => date('Y-m-d H:i:s'),
                'payload'        => $p,
            ];
            $created = true;
        }
        return $d;
    });
    mock_json(['ok' => true, 'data' => ['assessment' => mock_public($d['assessments'][$p['enrollment_ref']])]], $created ? 201 : 200);
}

mock_json(['ok' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']], 405);

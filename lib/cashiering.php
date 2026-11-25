<?php
/**
 * Client for the University Cashiering System (JSON REST API).
 * The full contract is in API.md — share it with the Cashiering back-end.
 *
 *   POST {base}/assessments.php                    send a submitted enrollment, get the fee assessment
 *   GET  {base}/assessments.php?enrollment_ref=... get the latest assessment / payment status
 *
 * Every request carries the header  X-API-Key: <shared key>.
 */

class CashieringUnavailable extends Exception {}

/**
 * Send a request to the Cashiering System. Returns the "data" part of the reply.
 * Throws CashieringUnavailable if it is down, times out, or answers with an error.
 */
function cashiering_request(string $method, string $path, ?array $body = null): array
{
    $url = rtrim(config('cashiering.base_url'), '/') . '/' . ltrim($path, '/');
    $timeout = (int)config('cashiering.timeout', 5);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-Key: ' . config('cashiering.api_key'),
    ];
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new CashieringUnavailable('Cannot reach the Cashiering System (' . ($err ?: 'no response') . ').');
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $payload ?? '', 'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new CashieringUnavailable('Cannot reach the Cashiering System.');
        }
        preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m);
        $status = (int)($m[1] ?? 0);
    }

    $json = json_decode($raw, true);
    if ($status >= 500 || $status === 0 || !is_array($json)) {
        throw new CashieringUnavailable("Cashiering System error (HTTP {$status}).");
    }
    if (empty($json['ok'])) {
        $msg = $json['error']['message'] ?? "HTTP {$status}";
        throw new CashieringUnavailable('Cashiering System rejected the request: ' . $msg);
    }
    return $json['data'] ?? [];
}

/** Build the enrollment payload the Cashiering System needs to make an assessment. */
function cashiering_payload(array $enr): array
{
    $student = db_one(
        'SELECT u.first_name, u.last_name, u.email, s.student_no, s.program, s.year_level
           FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?',
        [$enr['student_id']]
    );
    $term = db_one('SELECT name FROM terms WHERE id = ?', [$enr['term_id']]);
    $subjects = array_map(fn($s) => [
        'code'     => $s['code'],
        'title'    => $s['title'],
        'units'    => (int)$s['units'],
        'has_lab'  => (bool)$s['has_lab'],
        'section'  => $s['section_code'],
        'schedule' => $s['schedule'],
    ], enrollment_subjects((int)$enr['id']));

    return [
        'enrollment_ref' => $enr['reference_no'],
        'term'           => $term['name'],
        'submitted_at'   => $enr['submitted_at'],
        'student' => [
            'student_no' => $student['student_no'],
            'last_name'  => $student['last_name'],
            'first_name' => $student['first_name'],
            'email'      => $student['email'],
            'program'    => $student['program'],
            'year_level' => (int)$student['year_level'],
        ],
        'total_units' => (int)$enr['total_units'],
        'subjects'    => $subjects,
    ];
}

/**
 * Send a submitted enrollment to the Cashiering System (first try or Retry).
 * Safe to call many times: the Cashiering System must return the same
 * assessment for the same enrollment_ref.
 * Returns true if the assessment was received.
 */
function sync_enrollment(int $enrollmentId): bool
{
    $enr = find_enrollment_by_id($enrollmentId);
    if (!$enr || $enr['status'] !== 'submitted') {
        return false;
    }
    db_query('UPDATE enrollments SET sync_attempts = sync_attempts + 1, last_sync_at = NOW() WHERE id = ?', [$enrollmentId]);

    try {
        $data = cashiering_request('POST', 'assessments.php', cashiering_payload($enr));
        save_assessment($enrollmentId, $data['assessment'] ?? $data);
        return true;
    } catch (CashieringUnavailable | ApiError $e) {
        db_query(
            "UPDATE enrollments SET sync_status = 'failed', last_sync_error = ? WHERE id = ?",
            [mb_substr($e->getMessage(), 0, 255), $enrollmentId]
        );
        audit('Cashiering sync failed', $enr['reference_no'] . ' · ' . $e->getMessage(), ['id' => null, 'role' => 'system']);
        return false;
    }
}

/** Store the itemized assessment returned by the Cashiering System. */
function save_assessment(int $enrollmentId, array $a): void
{
    $items = $a['items'] ?? null;
    if (empty($a['assessment_no']) || !is_array($items) || !$items || !isset($a['total'])) {
        throw new ApiError('BAD_ASSESSMENT', 'Invalid assessment received from the Cashiering System.', 502);
    }
    $sum = 0.0;
    foreach ($items as $it) {
        if (!isset($it['label'], $it['amount']) || !is_numeric($it['amount'])) {
            throw new ApiError('BAD_ASSESSMENT', 'Invalid assessment item received from the Cashiering System.', 502);
        }
        $sum += (float)$it['amount'];
    }
    if (abs($sum - (float)$a['total']) > 0.009) {
        throw new ApiError('BAD_ASSESSMENT', 'Assessment total does not match its items.', 502);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_query('DELETE FROM assessment_items WHERE enrollment_id = ?', [$enrollmentId]);
        foreach (array_values($items) as $i => $it) {
            db_query(
                'INSERT INTO assessment_items (enrollment_id, label, amount, sort_order) VALUES (?, ?, ?, ?)',
                [$enrollmentId, mb_substr((string)$it['label'], 0, 100), round((float)$it['amount'], 2), $i]
            );
        }
        db_query(
            "UPDATE enrollments
                SET assessment_no = ?, total_amount = ?, sync_status = 'synced', last_sync_error = NULL, last_sync_at = NOW()
              WHERE id = ?",
            [$a['assessment_no'], round((float)$a['total'], 2), $enrollmentId]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (($a['status'] ?? '') === 'paid') {
        apply_payment($enrollmentId, $a);
    }
}

/**
 * Mark an enrollment as Paid (Requirement 3). Called when we poll the
 * Cashiering System or when it calls our callback endpoint.
 */
function apply_payment(int $enrollmentId, array $p): void
{
    $or = trim((string)($p['or_number'] ?? ''));
    $amount = $p['amount_paid'] ?? null;
    if ($or === '') {
        throw new ApiError('INVALID_PAYMENT', 'or_number is required.', 422);
    }
    if (!is_numeric($amount) || (float)$amount <= 0) {
        throw new ApiError('INVALID_PAYMENT', 'amount_paid must be greater than zero.', 422);
    }
    $paidAt = !empty($p['paid_at']) ? date('Y-m-d H:i:s', strtotime((string)$p['paid_at'])) : date('Y-m-d H:i:s');

    $stmt = db_query(
        "UPDATE enrollments
            SET payment_status = 'paid', or_number = ?, amount_paid = ?, paid_at = ?
          WHERE id = ? AND status = 'submitted' AND payment_status <> 'paid'",
        [mb_substr($or, 0, 30), round((float)$amount, 2), $paidAt, $enrollmentId]
    );
    if ($stmt->rowCount() > 0) {
        $enr = find_enrollment_by_id($enrollmentId);
        audit('Payment received', $enr['reference_no'] . ' · ' . $or, ['id' => null, 'role' => 'system']);
    }
}

/**
 * Ask the Cashiering System for the latest status (used by the Payment status
 * screen, which refreshes automatically). Returns false if it is unreachable.
 */
function refresh_from_cashiering(int $enrollmentId): bool
{
    $enr = find_enrollment_by_id($enrollmentId);
    if (!$enr || $enr['status'] !== 'submitted' || $enr['payment_status'] === 'paid') {
        return true;
    }
    if ($enr['sync_status'] !== 'synced') {
        return false; // must be sent first (Retry)
    }
    try {
        $data = cashiering_request('GET', 'assessments.php?enrollment_ref=' . rawurlencode($enr['reference_no']));
        $a = $data['assessment'] ?? $data;
        if (($a['status'] ?? '') === 'paid') {
            apply_payment($enrollmentId, $a);
        }
        return true;
    } catch (CashieringUnavailable $e) {
        return false;
    }
}

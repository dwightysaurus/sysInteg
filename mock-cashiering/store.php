<?php
/**
 * MOCK Cashiering System — tiny JSON-file store and helpers.
 * Not part of the Registration Portal; it only exists so the portal can be
 * built and demonstrated before the real Cashiering System is connected.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

$MOCK = require __DIR__ . '/config.php';
const MOCK_STORE = __DIR__ . '/data/store.json';

/** Read-modify-write the store under an exclusive lock. */
function mock_store(?callable $change = null): array
{
    if (!is_dir(dirname(MOCK_STORE))) {
        mkdir(dirname(MOCK_STORE), 0777, true);
    }
    $fh = fopen(MOCK_STORE, 'c+');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $data = ['online' => true, 'next_assessment' => 7001, 'next_or' => 4217, 'assessments' => []];
    }
    if ($change) {
        $data = $change($data);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

function mock_json(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mock_require_key(): void
{
    global $MOCK;
    if (!hash_equals($MOCK['api_key'], (string)($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
        mock_json(['ok' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Invalid API key.']], 401);
    }
}

function mock_input(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : $_POST;
}

/** Build the itemized assessment for an enrollment payload. */
function mock_assess(array $p): array
{
    global $MOCK;
    $units = (int)$p['total_units'];
    $hasLab = false;
    foreach ($p['subjects'] as $s) {
        $hasLab = $hasLab || !empty($s['has_lab']);
    }
    $items = [
        ['label' => sprintf('Tuition (₱%s × %d units)', number_format($MOCK['tuition_per_unit'], 0), $units),
         'amount' => round($MOCK['tuition_per_unit'] * $units, 2)],
        ['label' => 'Laboratory fee', 'amount' => $hasLab ? $MOCK['laboratory_fee'] : 0.0],
        ['label' => 'Miscellaneous fees', 'amount' => $MOCK['miscellaneous_fee']],
    ];
    return ['items' => $items, 'total' => round(array_sum(array_column($items, 'amount')), 2)];
}

/** Record a payment; returns [assessment, error]. Rejects zero/negative amounts. */
function mock_record_payment(string $ref, $amount, string $method = 'Simulated'): array
{
    if (!is_numeric($amount) || (float)$amount <= 0) {
        return [null, 'Amount must be greater than zero.'];
    }
    $error = null;
    $result = null;
    mock_store(function ($d) use ($ref, $amount, $method, &$error, &$result) {
        if (!isset($d['assessments'][$ref])) {
            $error = 'Assessment not found.';
            return $d;
        }
        $a = $d['assessments'][$ref];
        if ($a['status'] === 'paid') {
            $result = $a;
            return $d;
        }
        if ((float)$amount < (float)$a['total']) {
            $error = 'Amount is less than the total assessment (₱' . number_format($a['total'], 2) . ').';
            return $d;
        }
        $a['status'] = 'paid';
        $a['amount_paid'] = round((float)$amount, 2);
        $a['or_number'] = sprintf('OR-%s-%06d', date('Y'), $d['next_or']++);
        $a['payment_method'] = $method;
        $a['paid_at'] = date('Y-m-d H:i:s');
        $d['assessments'][$ref] = $a;
        $result = $a;
        return $d;
    });
    if ($result && empty($error)) {
        mock_notify_portal($result);
    }
    return [$result, $error];
}

/** Push the payment to the Registration Portal (it also polls, so failure is OK). */
function mock_notify_portal(array $a): void
{
    global $MOCK;
    $ch = curl_init($MOCK['portal_callback_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $MOCK['timeout'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $MOCK['api_key']],
        CURLOPT_POSTFIELDS => json_encode([
            'enrollment_ref' => $a['enrollment_ref'],
            'assessment_no'  => $a['assessment_no'],
            'status'         => 'paid',
            'or_number'      => $a['or_number'],
            'amount_paid'    => $a['amount_paid'],
            'paid_at'        => $a['paid_at'],
        ]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function mock_public(array $a): array
{
    unset($a['payload']);
    return $a;
}

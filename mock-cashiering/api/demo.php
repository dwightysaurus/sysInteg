<?php
/**
 * MOCK only — used by the portal's "Demo:" buttons.
 *   {action: "pay", enrollment_ref, amount}  record a simulated payment
 *   {action: "offline"} / {action: "online"} simulate the Cashiering System being down / back
 * Always answers, even while "offline", so the demo can switch it back on.
 */
require __DIR__ . '/../store.php';
mock_require_key();
$in = mock_input();

switch ($in['action'] ?? '') {
    case 'pay':
        [$a, $err] = mock_record_payment((string)($in['enrollment_ref'] ?? ''), $in['amount'] ?? 0);
        if ($err) {
            mock_json(['ok' => false, 'error' => ['code' => 'INVALID_PAYMENT', 'message' => $err]], 422);
        }
        mock_json(['ok' => true, 'data' => ['assessment' => mock_public($a)]]);
    case 'offline':
    case 'online':
        $online = $in['action'] === 'online';
        mock_store(function ($d) use ($online) { $d['online'] = $online; return $d; });
        mock_json(['ok' => true, 'data' => ['online' => $online]]);
}
mock_json(['ok' => false, 'error' => ['code' => 'VALIDATION', 'message' => 'Unknown action.']], 422);

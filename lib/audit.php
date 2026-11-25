<?php
/**
 * Simple audit trail. Never let a logging failure break the request.
 */
function audit(string $action, ?string $details = null, ?array $user = null): void
{
    $user = $user ?? current_user();
    try {
        db_query(
            'INSERT INTO audit_log (user_id, role, action, details) VALUES (?, ?, ?, ?)',
            [$user['id'] ?? null, $user['role'] ?? 'system', $action, $details]
        );
    } catch (Throwable $e) {
        error_log('[portal] audit failed: ' . $e->getMessage());
    }
}

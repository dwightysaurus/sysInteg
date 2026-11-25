<?php
/**
 * JSON API helpers.
 *
 * Every API endpoint answers with one of:
 *   { "ok": true,  "data": { ... } }
 *   { "ok": false, "error": { "code": "UNITS_LIMIT", "message": "..." } }
 */

class ApiError extends Exception
{
    public string $errorCode;
    public int $status;

    public function __construct(string $errorCode, string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }
}

function json_response(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = null, int $status = 200): void
{
    json_response(['ok' => true, 'data' => $data], $status);
}

function fail(string $code, string $message, int $status = 400): void
{
    json_response(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

/** Read the JSON request body (falls back to form fields). */
function input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : $_POST;
    }
    return $data;
}

function require_method(string ...$methods): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        fail('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
    }
    return $method;
}

/**
 * Wrap an endpoint so ApiError and unexpected errors become JSON responses.
 */
function api(callable $handler): void
{
    try {
        $handler();
    } catch (ApiError $e) {
        fail($e->errorCode, $e->getMessage(), $e->status);
    } catch (Throwable $e) {
        error_log('[portal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        fail('SERVER_ERROR', 'Something went wrong on the server. Please try again.', 500);
    }
}

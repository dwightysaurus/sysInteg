<?php
/**
 * Login, session and role checks (Requirement 5: each role sees only its own screens).
 */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function attempt_login(string $email, string $password): ?array
{
    $user = db_one(
        'SELECT u.id, u.email, u.password_hash, u.first_name, u.last_name, u.role, u.is_active,
                s.student_no, s.program, s.year_level
           FROM users u
           LEFT JOIN students s ON s.user_id = u.id
          WHERE u.email = ?',
        [strtolower(trim($email))]
    );
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    unset($user['password_hash'], $user['is_active']);

    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    return $user;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Where each role lands after logging in (relative to the app root). */
function home_for_role(string $role): string
{
    switch ($role) {
        case 'student':   return 'student/dashboard.php';
        case 'registrar': return 'registrar/index.php';
        case 'cashier':   return config('cashier_app_url');
        default:          return 'index.php';
    }
}

/** For API endpoints: stop with 401/403 JSON if the user is not allowed. */
function api_require_role(string ...$roles): array
{
    $user = current_user();
    if (!$user) {
        throw new ApiError('UNAUTHENTICATED', 'Please log in first.', 401);
    }
    if (!in_array($user['role'], $roles, true)) {
        throw new ApiError('FORBIDDEN', 'Your account does not have access to this page.', 403);
    }
    return $user;
}

/** For pages: redirect to login, or show 403 if the role is wrong. */
function page_require_role(string $role, string $rootPrefix = '../'): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . $rootPrefix . 'index.php');
        exit;
    }
    if ($user['role'] !== $role) {
        http_response_code(403);
        $home = home_for_role($user['role']);
        if (strpos($home, 'http') !== 0) {
            $home = $rootPrefix . $home;
        }
        require __DIR__ . '/../partials/forbidden.php';
        exit;
    }
    return $user;
}

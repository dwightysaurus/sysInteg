<?php
// POST {email, password}  ->  { user, redirect }
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST');
    $in = input();
    $email = trim((string)($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    if ($email === '' || $password === '') {
        throw new ApiError('VALIDATION', 'Enter your email and password.', 422);
    }
    $user = attempt_login($email, $password);
    if (!$user) {
        throw new ApiError('INVALID_CREDENTIALS', 'Invalid email or password. Please try again.', 401);
    }
    audit('Logged in', $user['role'] . ' portal', $user);
    ok(['user' => $user, 'redirect' => home_for_role($user['role'])]);
});

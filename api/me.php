<?php
// GET -> the logged-in user, or 401
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('GET');
    $user = current_user();
    if (!$user) {
        throw new ApiError('UNAUTHENTICATED', 'Please log in first.', 401);
    }
    ok(['user' => $user, 'term' => current_term()['name']]);
});

<?php
// POST -> logs out. (GET also works so a plain link can be used.)
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('POST', 'GET');
    logout();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Location: ../index.php');
        exit;
    }
    ok(['redirect' => 'index.php']);
});

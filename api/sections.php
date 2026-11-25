<?php
// GET ?q=search  -> sections offered in the current term (students and registrars)
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    require_method('GET');
    api_require_role('student', 'registrar');
    $term = current_term();
    $q = trim((string)($_GET['q'] ?? ''));
    ok(['term' => $term['name'], 'max_units' => max_units(), 'sections' => list_sections((int)$term['id'], $q)]);
});

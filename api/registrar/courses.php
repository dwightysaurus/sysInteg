<?php
// Registrar: courses
//   GET                                   -> all courses
//   POST {code, title, units, has_lab}    -> add a course
require __DIR__ . '/../../lib/bootstrap.php';

api(function () {
    $method = require_method('GET', 'POST');
    $user = api_require_role('registrar');

    if ($method === 'POST') {
        $in = input();
        $code  = strtoupper(trim((string)($in['code'] ?? '')));
        $title = trim((string)($in['title'] ?? ''));
        $units = (int)($in['units'] ?? 0);
        $lab   = !empty($in['has_lab']) ? 1 : 0;
        if ($code === '' || $title === '') {
            throw new ApiError('VALIDATION', 'Course code and title are required.', 422);
        }
        if ($units < 1 || $units > 6) {
            throw new ApiError('VALIDATION', 'Units must be from 1 to 6.', 422);
        }
        if (db_one('SELECT 1 FROM courses WHERE code = ?', [$code])) {
            throw new ApiError('DUPLICATE', "Course {$code} already exists.", 409);
        }
        db_query('INSERT INTO courses (code, title, units, has_lab) VALUES (?, ?, ?, ?)', [$code, $title, $units, $lab]);
        audit('Added course', "{$code} · {$title}", $user);
    }

    $rows = db_all('SELECT id, code, title, units, has_lab FROM courses ORDER BY code');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['units'] = (int)$r['units']; $r['has_lab'] = (bool)$r['has_lab'];
    }
    ok(['courses' => $rows]);
});

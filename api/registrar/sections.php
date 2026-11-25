<?php
// Registrar: sections for the current term
//   GET                                                        -> all sections
//   POST  {course_id, section_code, schedule, capacity}        -> add a section
//   PATCH {id, schedule?, capacity?, is_open?}                 -> edit a section
require __DIR__ . '/../../lib/bootstrap.php';

api(function () {
    $method = require_method('GET', 'POST', 'PATCH');
    $user = api_require_role('registrar');
    $term = current_term();
    $in = input();

    if ($method === 'POST') {
        $courseId = (int)($in['course_id'] ?? 0);
        $code     = strtoupper(trim((string)($in['section_code'] ?? '')));
        $schedule = trim((string)($in['schedule'] ?? ''));
        $capacity = (int)($in['capacity'] ?? 0);
        $course = db_one('SELECT code FROM courses WHERE id = ?', [$courseId]);
        if (!$course) {
            throw new ApiError('VALIDATION', 'Choose a course.', 422);
        }
        if ($code === '' || $schedule === '') {
            throw new ApiError('VALIDATION', 'Section code and schedule are required.', 422);
        }
        if ($capacity < 1 || $capacity > 500) {
            throw new ApiError('VALIDATION', 'Capacity must be from 1 to 500.', 422);
        }
        if (db_one('SELECT 1 FROM sections WHERE course_id = ? AND term_id = ? AND section_code = ?', [$courseId, $term['id'], $code])) {
            throw new ApiError('DUPLICATE', "{$course['code']} {$code} already exists this term.", 409);
        }
        db_query(
            'INSERT INTO sections (course_id, term_id, section_code, schedule, capacity) VALUES (?, ?, ?, ?, ?)',
            [$courseId, $term['id'], $code, $schedule, $capacity]
        );
        audit('Added section', "{$course['code']} {$code} · {$schedule}", $user);
    }

    if ($method === 'PATCH') {
        $id = (int)($in['id'] ?? 0);
        $s = db_one(
            'SELECT s.*, c.code FROM sections s JOIN courses c ON c.id = s.course_id WHERE s.id = ? AND s.term_id = ?',
            [$id, $term['id']]
        );
        if (!$s) {
            throw new ApiError('NOT_FOUND', 'Section not found.', 404);
        }
        $changes = [];
        if (isset($in['capacity'])) {
            $cap = (int)$in['capacity'];
            if ($cap < (int)$s['enrolled'] || $cap < 1 || $cap > 500) {
                throw new ApiError('VALIDATION', "Capacity must be from {$s['enrolled']} (students already enrolled) to 500.", 422);
            }
            db_query('UPDATE sections SET capacity = ? WHERE id = ?', [$cap, $id]);
            $changes[] = "capacity {$s['capacity']} → {$cap}";
        }
        if (isset($in['schedule']) && trim((string)$in['schedule']) !== '') {
            db_query('UPDATE sections SET schedule = ? WHERE id = ?', [trim((string)$in['schedule']), $id]);
            $changes[] = 'schedule → ' . trim((string)$in['schedule']);
        }
        if (isset($in['is_open'])) {
            $open = !empty($in['is_open']) ? 1 : 0;
            db_query('UPDATE sections SET is_open = ? WHERE id = ?', [$open, $id]);
            $changes[] = $open ? 'opened' : 'closed';
        }
        if ($changes) {
            audit('Edited section', $s['code'] . ' ' . implode(', ', $changes), $user);
        }
    }

    ok(['term' => $term['name'], 'sections' => list_sections((int)$term['id'], trim((string)($_GET['q'] ?? '')))]);
});

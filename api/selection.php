<?php
// Student's subject selection (step 1).
//   GET                         -> current enrollment
//   POST   {section_id}         -> add a section   (blocks > max units and duplicates)
//   DELETE ?section_id=ID       -> remove a section
// All return the updated enrollment.
require __DIR__ . '/../lib/bootstrap.php';

api(function () {
    $method = require_method('GET', 'POST', 'DELETE');
    $user = api_require_role('student');

    if ($method === 'POST') {
        $sectionId = (int)(input()['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new ApiError('VALIDATION', 'section_id is required.', 422);
        }
        add_subject($user, $sectionId);
    } elseif ($method === 'DELETE') {
        $sectionId = (int)($_GET['section_id'] ?? input()['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new ApiError('VALIDATION', 'section_id is required.', 422);
        }
        remove_subject($user, $sectionId);
    }
    ok(student_enrollment_view($user));
});

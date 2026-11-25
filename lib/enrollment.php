<?php
/**
 * Enrollment business rules for the Registration Portal.
 *
 *  - A student has one enrollment per term.
 *  - While "draft", subjects can be added/removed:
 *      * max units (settings.max_units, default 21)        -> Requirement 1
 *      * no duplicate section / no two sections of a course -> Requirement 1
 *      * section must be open and have free slots
 *  - "Submit" locks the enrollment, takes the slots, and saves it BEFORE
 *    contacting the Cashiering System, so nothing is lost if it is down.  -> Requirement 4
 */

function find_enrollment(int $studentId, int $termId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM enrollments WHERE student_id = ? AND term_id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    return db_one($sql, [$studentId, $termId]);
}

function find_enrollment_by_id(int $id, bool $forUpdate = false): ?array
{
    return db_one('SELECT * FROM enrollments WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
}

function find_enrollment_by_ref(string $ref): ?array
{
    return db_one('SELECT * FROM enrollments WHERE reference_no = ?', [$ref]);
}

/** Get the student's enrollment for the current term, creating a draft if needed (must be inside a transaction). */
function lock_or_create_draft(int $studentId, int $termId): array
{
    $enr = find_enrollment($studentId, $termId, true);
    if (!$enr) {
        db_query('INSERT INTO enrollments (student_id, term_id) VALUES (?, ?)', [$studentId, $termId]);
        $enr = find_enrollment($studentId, $termId, true);
    }
    return $enr;
}

function enrollment_subjects(int $enrollmentId): array
{
    return db_all(
        'SELECT es.section_id, c.code, c.title, c.units, c.has_lab, s.section_code, s.schedule
           FROM enrollment_subjects es
           JOIN sections s ON s.id = es.section_id
           JOIN courses  c ON c.id = es.course_id
          WHERE es.enrollment_id = ?
          ORDER BY es.added_at, es.id',
        [$enrollmentId]
    );
}

function recount_units(int $enrollmentId): int
{
    $row = db_one(
        'SELECT COALESCE(SUM(c.units), 0) AS units
           FROM enrollment_subjects es JOIN courses c ON c.id = es.course_id
          WHERE es.enrollment_id = ?',
        [$enrollmentId]
    );
    $units = (int)$row['units'];
    db_query('UPDATE enrollments SET total_units = ? WHERE id = ?', [$units, $enrollmentId]);
    return $units;
}

/** Sections offered this term, as shown on "Select subjects". */
function list_sections(int $termId, string $search = ''): array
{
    $sql = 'SELECT s.id AS section_id, c.code, c.title, c.units, c.has_lab,
                   s.section_code, s.schedule, s.capacity, s.enrolled, s.is_open
              FROM sections s JOIN courses c ON c.id = s.course_id
             WHERE s.term_id = ?';
    $params = [$termId];
    if ($search !== '') {
        $sql .= ' AND (c.code LIKE ? OR c.title LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY s.id';

    return array_map(function ($r) {
        $r['section_id'] = (int)$r['section_id'];
        $r['units']      = (int)$r['units'];
        $r['has_lab']    = (bool)$r['has_lab'];
        $r['capacity']   = (int)$r['capacity'];
        $r['enrolled']   = (int)$r['enrolled'];
        $r['is_open']    = (bool)$r['is_open'];
        $r['available']  = $r['is_open'] && $r['enrolled'] < $r['capacity'];
        return $r;
    }, db_all($sql, $params));
}

// ---------------------------------------------------------------------
// Select subjects
// ---------------------------------------------------------------------

function add_subject(array $user, int $sectionId): void
{
    $term = current_term();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $enr = lock_or_create_draft((int)$user['id'], (int)$term['id']);
        if ($enr['status'] !== 'draft') {
            throw new ApiError('ENROLLMENT_LOCKED', 'Your enrollment is already submitted and can no longer be edited.', 409);
        }

        $section = db_one(
            'SELECT s.id, s.course_id, s.capacity, s.enrolled, s.is_open, c.units, c.code
               FROM sections s JOIN courses c ON c.id = s.course_id
              WHERE s.id = ? AND s.term_id = ?',
            [$sectionId, $term['id']]
        );
        if (!$section) {
            throw new ApiError('SECTION_NOT_FOUND', 'That section is not offered this term.', 404);
        }

        $dupSection = db_one('SELECT 1 FROM enrollment_subjects WHERE enrollment_id = ? AND section_id = ?', [$enr['id'], $sectionId]);
        if ($dupSection) {
            throw new ApiError('DUPLICATE_SECTION', 'This section is already added.', 409);
        }
        $dupCourse = db_one('SELECT 1 FROM enrollment_subjects WHERE enrollment_id = ? AND course_id = ?', [$enr['id'], $section['course_id']]);
        if ($dupCourse) {
            throw new ApiError('DUPLICATE_COURSE', 'You already added a section of ' . $section['code'] . '.', 409);
        }
        if (!$section['is_open'] || (int)$section['enrolled'] >= (int)$section['capacity']) {
            throw new ApiError('SECTION_FULL', 'This section is unavailable (no slots left).', 409);
        }

        $max = max_units();
        if ((int)$enr['total_units'] + (int)$section['units'] > $max) {
            throw new ApiError('UNITS_LIMIT', "Maximum of {$max} units", 422);
        }

        db_query(
            'INSERT INTO enrollment_subjects (enrollment_id, section_id, course_id) VALUES (?, ?, ?)',
            [$enr['id'], $sectionId, $section['course_id']]
        );
        recount_units((int)$enr['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function remove_subject(array $user, int $sectionId): void
{
    $term = current_term();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $enr = find_enrollment((int)$user['id'], (int)$term['id'], true);
        if (!$enr) {
            throw new ApiError('NOT_FOUND', 'You have no subjects selected.', 404);
        }
        if ($enr['status'] !== 'draft') {
            throw new ApiError('ENROLLMENT_LOCKED', 'Your enrollment is already submitted and can no longer be edited.', 409);
        }
        $stmt = db_query('DELETE FROM enrollment_subjects WHERE enrollment_id = ? AND section_id = ?', [$enr['id'], $sectionId]);
        if ($stmt->rowCount() === 0) {
            throw new ApiError('NOT_FOUND', 'That subject is not in your selection.', 404);
        }
        recount_units((int)$enr['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------

/**
 * Lock the enrollment and take the section slots. Returns the enrollment id.
 * Does NOT contact the Cashiering System (call sync_enrollment() after).
 */
function submit_enrollment(array $user): int
{
    $term = current_term();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $enr = find_enrollment((int)$user['id'], (int)$term['id'], true);
        if (!$enr || $enr['status'] !== 'draft') {
            if ($enr && $enr['status'] === 'submitted') {
                throw new ApiError('ALREADY_SUBMITTED', 'Your enrollment was already submitted.', 409);
            }
            throw new ApiError('EMPTY_SELECTION', 'Add at least one subject before submitting.', 422);
        }

        $rows = db_all(
            'SELECT s.id, s.capacity, s.enrolled, s.is_open, c.code, c.units
               FROM enrollment_subjects es
               JOIN sections s ON s.id = es.section_id
               JOIN courses  c ON c.id = es.course_id
              WHERE es.enrollment_id = ?
              FOR UPDATE',
            [$enr['id']]
        );
        if (!$rows) {
            throw new ApiError('EMPTY_SELECTION', 'Add at least one subject before submitting.', 422);
        }
        $units = array_sum(array_map(fn($r) => (int)$r['units'], $rows));
        if ($units > max_units()) {
            throw new ApiError('UNITS_LIMIT', 'Maximum of ' . max_units() . ' units', 422);
        }
        foreach ($rows as $r) {
            if (!$r['is_open'] || (int)$r['enrolled'] >= (int)$r['capacity']) {
                throw new ApiError('SECTION_FULL', $r['code'] . ' just became full. Please remove it and choose another section.', 409);
            }
        }
        foreach ($rows as $r) {
            db_query('UPDATE sections SET enrolled = enrolled + 1 WHERE id = ?', [$r['id']]);
        }

        $ref = sprintf('EN-%s-%06d', date('Y'), $enr['id']);
        db_query(
            "UPDATE enrollments
                SET status = 'submitted', reference_no = ?, total_units = ?, submitted_at = NOW(),
                    payment_status = 'pending', sync_status = 'not_sent'
              WHERE id = ?",
            [$ref, $units, $enr['id']]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('Submitted enrollment', sprintf('%s · %d units · %d subjects', $ref, $units, count($rows)), $user);
    return (int)$enr['id'];
}

// ---------------------------------------------------------------------
// Output for the UI
// ---------------------------------------------------------------------

/**
 * Which screen the student should be on:
 *   not_started  - nothing selected yet
 *   selecting    - draft with subjects
 *   pending_sync - submitted, but the Cashiering System has not answered yet (show Retry)
 *   assessed     - fee assessment received, waiting for payment
 *   paid         - cashier recorded the payment (enrolled)
 */
function enrollment_stage(?array $enr, int $subjectCount): string
{
    if (!$enr || ($enr['status'] === 'draft' && $subjectCount === 0)) return 'not_started';
    if ($enr['status'] === 'draft')            return 'selecting';
    if ($enr['payment_status'] === 'paid')     return 'paid';
    if ($enr['sync_status'] !== 'synced')      return 'pending_sync';
    return 'assessed';
}

function enrollment_view(?array $enr, array $term): array
{
    $subjects = $enr ? enrollment_subjects((int)$enr['id']) : [];
    $subjects = array_map(function ($s) {
        $s['section_id'] = (int)$s['section_id'];
        $s['units'] = (int)$s['units'];
        $s['has_lab'] = (bool)$s['has_lab'];
        return $s;
    }, $subjects);

    $items = $enr ? db_all(
        'SELECT label, amount FROM assessment_items WHERE enrollment_id = ? ORDER BY sort_order, id',
        [$enr['id']]
    ) : [];
    $items = array_map(fn($i) => ['label' => $i['label'], 'amount' => (float)$i['amount']], $items);

    return [
        'id'           => $enr ? (int)$enr['id'] : null,
        'reference_no' => $enr['reference_no'] ?? null,
        'term'         => $term['name'],
        'status'       => $enr['status'] ?? 'draft',
        'stage'        => enrollment_stage($enr, count($subjects)),
        'total_units'  => array_sum(array_column($subjects, 'units')),
        'max_units'    => max_units(),
        'subjects'     => $subjects,
        'submitted_at' => $enr['submitted_at'] ?? null,
        'sync' => [
            'status'   => $enr['sync_status'] ?? 'not_sent',
            'attempts' => (int)($enr['sync_attempts'] ?? 0),
            'error'    => $enr['last_sync_error'] ?? null,
            'last_at'  => $enr['last_sync_at'] ?? null,
        ],
        'payment' => [
            'status'        => $enr['payment_status'] ?? 'pending',
            'assessment_no' => $enr['assessment_no'] ?? null,
            'items'         => $items,
            'total'         => isset($enr['total_amount']) ? (float)$enr['total_amount'] : null,
            'amount_paid'   => isset($enr['amount_paid']) ? (float)$enr['amount_paid'] : null,
            'or_number'     => $enr['or_number'] ?? null,
            'paid_at'       => $enr['paid_at'] ?? null,
        ],
    ];
}

function student_enrollment_view(array $user): array
{
    $term = current_term();
    $enr = find_enrollment((int)$user['id'], (int)$term['id']);
    return enrollment_view($enr, $term);
}

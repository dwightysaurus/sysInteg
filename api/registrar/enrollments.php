<?php
// Registrar: monitor enrollments for the current term
//   GET ?filter=all|paid|pending|failed&q=name-or-student-no  -> list + counts
//   GET ?id=ID                                                -> one enrollment with subjects and fees
//   POST {id}                                                 -> retry sending to the Cashiering System
require __DIR__ . '/../../lib/bootstrap.php';

api(function () {
    $method = require_method('GET', 'POST');
    $user = api_require_role('registrar');
    $term = current_term();

    if ($method === 'POST') {
        $id = (int)(input()['id'] ?? 0);
        $enr = find_enrollment_by_id($id);
        if (!$enr || (int)$enr['term_id'] !== (int)$term['id'] || $enr['status'] !== 'submitted') {
            throw new ApiError('NOT_FOUND', 'Submitted enrollment not found.', 404);
        }
        $ok = $enr['sync_status'] === 'synced' ? refresh_from_cashiering($id) : sync_enrollment($id);
        audit('Retried cashiering sync', $enr['reference_no'] . ($ok ? ' · ok' : ' · failed'), $user);
        $_GET['id'] = $id;
    }

    if (!empty($_GET['id'])) {
        $enr = find_enrollment_by_id((int)$_GET['id']);
        if (!$enr || (int)$enr['term_id'] !== (int)$term['id']) {
            throw new ApiError('NOT_FOUND', 'Enrollment not found.', 404);
        }
        $view = enrollment_view($enr, $term);
        $view['student'] = db_one(
            'SELECT s.student_no, u.first_name, u.last_name, s.program, s.year_level
               FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?',
            [$enr['student_id']]
        );
        ok($view);
    }

    $filter = (string)($_GET['filter'] ?? 'all');
    $q = trim((string)($_GET['q'] ?? ''));
    $where = "e.term_id = ? AND e.status = 'submitted'";
    $params = [$term['id']];
    if ($filter === 'paid')    { $where .= " AND e.payment_status = 'paid'"; }
    if ($filter === 'pending') { $where .= " AND e.payment_status = 'pending' AND e.sync_status = 'synced'"; }
    if ($filter === 'failed')  { $where .= " AND e.sync_status <> 'synced'"; }
    if ($q !== '') {
        $where .= ' AND (s.student_no LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ? OR e.reference_no LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
    }

    $rows = db_all(
        "SELECT e.id, e.reference_no, s.student_no, CONCAT(u.last_name, ', ', u.first_name) AS student_name,
                s.program, e.total_units, e.submitted_at, e.sync_status, e.last_sync_error,
                e.payment_status, e.assessment_no, e.total_amount, e.or_number
           FROM enrollments e
           JOIN students s ON s.user_id = e.student_id
           JOIN users u    ON u.id = e.student_id
          WHERE $where
          ORDER BY e.submitted_at DESC",
        $params
    );
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['total_units'] = (int)$r['total_units'];
        $r['total_amount'] = $r['total_amount'] === null ? null : (float)$r['total_amount'];
    }

    $c = db_one(
        "SELECT COUNT(*) AS total,
                SUM(payment_status = 'paid') AS paid,
                SUM(payment_status = 'pending' AND sync_status = 'synced') AS pending,
                SUM(sync_status <> 'synced') AS failed
           FROM enrollments WHERE term_id = ? AND status = 'submitted'",
        [$term['id']]
    );
    $drafts = db_one("SELECT COUNT(*) AS n FROM enrollments WHERE term_id = ? AND status = 'draft'", [$term['id']]);

    ok([
        'term' => $term['name'],
        'counts' => [
            'total'   => (int)$c['total'],
            'paid'    => (int)$c['paid'],
            'pending' => (int)$c['pending'],
            'failed'  => (int)$c['failed'],
            'drafts'  => (int)$drafts['n'],
        ],
        'enrollments' => $rows,
    ]);
});

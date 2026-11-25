<?php
// Registrar view: monitor enrollments, manage sections and courses.
// (No Figma screen yet — styled to match the student portal.)
require __DIR__ . '/../lib/bootstrap.php';

$root = '../';
$user = page_require_role('registrar', $root);
$pageTitle = 'Registrar';
require __DIR__ . '/../partials/header.php';
?>
<main class="page page-wide">
  <div class="row" style="margin-bottom:8px">
    <h1 style="margin:0">Registrar</h1>
    <span class="spacer"></span>
    <span class="muted"><?= e($user['first_name'] . ' ' . $user['last_name']) ?> · <span id="term"></span></span>
  </div>

  <div class="tabs" role="tablist">
    <button class="tab active" data-tab="enrollments">Enrollments</button>
    <button class="tab" data-tab="sections">Sections</button>
    <button class="tab" data-tab="courses">Courses</button>
  </div>
  <div id="alert"></div>

  <!-- Enrollments -->
  <section id="tab-enrollments">
    <div class="stats" id="stats"></div>
    <div class="card">
      <div class="row" style="margin-bottom:10px">
        <input class="input" id="enr-q" placeholder="Search name, student no. or reference" style="max-width:320px">
        <select class="input" id="enr-filter" style="max-width:200px">
          <option value="all">All submitted</option>
          <option value="paid">Enrolled and paid</option>
          <option value="pending">Pending payment</option>
          <option value="failed">Failed syncs</option>
        </select>
      </div>
      <table class="table">
        <thead><tr><th>Reference</th><th>Student</th><th>Units</th><th>Submitted</th><th>Cashiering</th><th>Payment</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody id="enr-body"></tbody>
      </table>
    </div>
  </section>

  <!-- Sections -->
  <section id="tab-sections" class="hidden">
    <div class="card" style="margin-bottom:16px">
      <h2>Add section</h2>
      <form class="row" id="section-form">
        <select class="input" name="course_id" id="course-select" style="max-width:280px" required></select>
        <input class="input" name="section_code" placeholder="Section (e.g. BSIT 3-2)" style="max-width:170px" required>
        <input class="input" name="schedule" placeholder="Schedule (e.g. MWF 8:00–9:00)" style="max-width:220px" required>
        <input class="input" name="capacity" type="number" min="1" max="500" placeholder="Capacity" style="max-width:110px" required>
        <button class="btn btn-primary" type="submit">Add section</button>
      </form>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>Course</th><th>Section</th><th>Schedule</th><th>Slots</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
        <tbody id="sec-body"></tbody>
      </table>
    </div>
  </section>

  <!-- Courses -->
  <section id="tab-courses" class="hidden">
    <div class="card" style="margin-bottom:16px">
      <h2>Add course</h2>
      <form class="row" id="course-form">
        <input class="input" name="code" placeholder="Code (e.g. ITEC 112)" style="max-width:160px" required>
        <input class="input" name="title" placeholder="Title" style="max-width:300px" required>
        <input class="input" name="units" type="number" min="1" max="6" value="3" style="max-width:90px" required>
        <label class="row" style="margin:0;font-weight:500"><input type="checkbox" name="has_lab"> Has laboratory</label>
        <button class="btn btn-primary" type="submit">Add course</button>
      </form>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>Code</th><th>Title</th><th class="num">Units</th><th>Lab</th></tr></thead>
        <tbody id="course-body"></tbody>
      </table>
    </div>
  </section>
</main>

<div class="overlay hidden" id="detail-modal" role="dialog" aria-modal="true">
  <div class="modal" style="width:min(620px,94vw)"><div id="detail"></div>
    <div class="modal-actions"><button class="btn btn-outline" id="detail-close">Close</button></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = (id) => document.getElementById(id);
  const alertBox = $('alert');
  const showError = (e) => { alertBox.innerHTML = `<div class="alert alert-danger">⚠ ${esc(e.message)}</div>`; };
  const clearError = () => { alertBox.innerHTML = ''; };

  const payChip = (r) => r.payment_status === 'paid'
    ? '<span class="chip chip-success">● Paid</span>'
    : '<span class="chip chip-pending">• Pending</span>';
  const syncChip = (s) => s === 'synced'
    ? '<span class="chip chip-success">Synced</span>'
    : `<span class="chip chip-danger">${s === 'failed' ? 'Failed' : 'Not sent'}</span>`;

  // Tabs
  document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach((x) => x.classList.toggle('active', x === t));
    ['enrollments', 'sections', 'courses'].forEach((n) => $('tab-' + n).classList.toggle('hidden', n !== t.dataset.tab));
    clearError();
    ({ enrollments: loadEnrollments, sections: loadSections, courses: loadCourses })[t.dataset.tab]();
  }));

  // ----- Enrollments -----
  async function loadEnrollments() {
    try {
      const q = encodeURIComponent($('enr-q').value.trim());
      const d = await Api.get(`registrar/enrollments.php?filter=${$('enr-filter').value}&q=${q}`);
      $('term').textContent = d.term;
      const c = d.counts;
      $('stats').innerHTML = [
        ['Total enrollments', c.total, `${c.drafts} still selecting`],
        ['Enrolled and paid', c.paid, '<span class="chip chip-success">✓ Enrolled</span>'],
        ['Pending payment', c.pending, '<span class="chip chip-pending">◔ Pending</span>'],
        ['Failed syncs', c.failed, '<span class="chip chip-danger">✕ Failed</span>'],
      ].map(([l, v, s]) => `<div class="card stat"><div class="muted small">${l}</div><div class="value">${v}</div><div class="small">${s}</div></div>`).join('');

      $('enr-body').innerHTML = d.enrollments.map((r) => `<tr>
          <td><a href="#" data-view="${r.id}">${esc(r.reference_no)}</a></td>
          <td>${esc(r.student_name)}<div class="muted small">${esc(r.student_no)} · ${esc(r.program)}</div></td>
          <td>${r.total_units}</td>
          <td class="small">${esc(r.submitted_at)}</td>
          <td>${syncChip(r.sync_status)}</td>
          <td>${payChip(r)}${r.or_number ? `<div class="muted small">${esc(r.or_number)}</div>` : ''}</td>
          <td class="num">${r.total_amount === null ? '—' : peso(r.total_amount)}</td>
          <td>${r.sync_status !== 'synced' ? `<button class="btn btn-outline btn-sm" data-retry="${r.id}">Retry</button>` : ''}</td>
        </tr>`).join('') || '<tr><td colspan="8" class="muted">No submitted enrollments yet.</td></tr>';

      document.querySelectorAll('[data-retry]').forEach((b) => b.addEventListener('click', async () => {
        b.disabled = true;
        try {
          const r = await Api.post('registrar/enrollments.php', { id: +b.dataset.retry });
          toast(r.sync.status === 'synced' ? 'Sent to the Cashiering System.' : 'Cashiering System is still unavailable.');
          loadEnrollments();
        } catch (e) { showError(e); b.disabled = false; }
      }));
      document.querySelectorAll('[data-view]').forEach((a) => a.addEventListener('click', (ev) => { ev.preventDefault(); viewEnrollment(+a.dataset.view); }));
    } catch (e) { showError(e); }
  }
  let t;
  $('enr-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(loadEnrollments, 250); });
  $('enr-filter').addEventListener('change', loadEnrollments);

  async function viewEnrollment(id) {
    try {
      const d = await Api.get('registrar/enrollments.php?id=' + id);
      const st = d.student;
      $('detail').innerHTML = `
        <h2>${esc(d.reference_no)} · ${esc(st.last_name)}, ${esc(st.first_name)}</h2>
        <p class="muted small" style="margin-top:0">${esc(st.student_no)} · ${esc(st.program)} ${st.year_level} · ${esc(d.term)}</p>
        <table class="table"><thead><tr><th>Code</th><th>Title</th><th>Section</th><th>Schedule</th><th class="num">Units</th></tr></thead>
          <tbody>${d.subjects.map((s) => `<tr><td>${esc(s.code)}</td><td>${esc(s.title)}</td><td>${esc(s.section_code)}</td><td>${esc(s.schedule)}</td><td class="num">${s.units}</td></tr>`).join('')}</tbody>
          <tfoot><tr><td colspan="4">Total units</td><td class="num">${d.total_units}</td></tr></tfoot></table>
        <h2 style="margin-top:16px">Fee assessment ${d.payment.assessment_no ? '· ' + esc(d.payment.assessment_no) : ''}</h2>
        ${d.payment.items.length ? `<table class="table"><tbody>${d.payment.items.map((i) => `<tr><td>${esc(i.label)}</td><td class="num">${peso(i.amount)}</td></tr>`).join('')}</tbody>
          <tfoot><tr><td>Total</td><td class="num">${peso(d.payment.total)}</td></tr></tfoot></table>`
          : `<p class="muted">Not yet received from the Cashiering System.${d.sync.error ? ' Last error: ' + esc(d.sync.error) : ''}</p>`}
        <p>Payment: ${d.payment.status === 'paid' ? `<span class="chip chip-success">● Paid</span> ${esc(d.payment.or_number)} · ${peso(d.payment.amount_paid)}` : '<span class="chip chip-pending">• Pending</span>'}</p>`;
      $('detail-modal').classList.remove('hidden');
    } catch (e) { showError(e); }
  }
  $('detail-close').addEventListener('click', () => $('detail-modal').classList.add('hidden'));

  // ----- Sections -----
  async function loadSections() {
    try {
      const [d, cs] = await Promise.all([Api.get('registrar/sections.php'), Api.get('registrar/courses.php')]);
      $('course-select').innerHTML = '<option value="">Choose course…</option>' +
        cs.courses.map((c) => `<option value="${c.id}">${esc(c.code)} · ${esc(c.title)}</option>`).join('');
      $('sec-body').innerHTML = d.sections.map((s) => `<tr>
          <td><strong>${esc(s.code)}</strong><div class="muted small">${esc(s.title)} · ${s.units} units</div></td>
          <td>${esc(s.section_code)}</td>
          <td>${esc(s.schedule)}</td>
          <td>${s.enrolled}/${s.capacity}</td>
          <td><input class="input" type="number" min="${s.enrolled}" max="500" value="${s.capacity}" style="width:90px" data-cap="${s.section_id}"></td>
          <td>${s.is_open ? (s.available ? '<span class="chip chip-success">Open</span>' : '<span class="chip chip-danger">Full</span>') : '<span class="chip">Closed</span>'}</td>
          <td><button class="btn btn-outline btn-sm" data-toggle="${s.section_id}" data-open="${s.is_open ? 0 : 1}">${s.is_open ? 'Close' : 'Open'}</button></td>
        </tr>`).join('');
      document.querySelectorAll('[data-cap]').forEach((i) => i.addEventListener('change', () => patchSection({ id: +i.dataset.cap, capacity: +i.value })));
      document.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => patchSection({ id: +b.dataset.toggle, is_open: +b.dataset.open })));
    } catch (e) { showError(e); }
  }
  async function patchSection(body) {
    try { await Api.patch('registrar/sections.php', body); clearError(); toast('Section updated.'); } catch (e) { showError(e); }
    loadSections();
  }
  $('section-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target;
    try {
      await Api.post('registrar/sections.php', { course_id: +f.course_id.value, section_code: f.section_code.value, schedule: f.schedule.value, capacity: +f.capacity.value });
      f.reset(); clearError(); toast('Section added.'); loadSections();
    } catch (e) { showError(e); }
  });

  // ----- Courses -----
  async function loadCourses() {
    try {
      const d = await Api.get('registrar/courses.php');
      $('course-body').innerHTML = d.courses.map((c) => `<tr><td><strong>${esc(c.code)}</strong></td><td>${esc(c.title)}</td><td class="num">${c.units}</td><td>${c.has_lab ? 'Yes' : '—'}</td></tr>`).join('');
    } catch (e) { showError(e); }
  }
  $('course-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target;
    try {
      await Api.post('registrar/courses.php', { code: f.code.value, title: f.title.value, units: +f.units.value, has_lab: f.has_lab.checked });
      f.reset(); clearError(); toast('Course added.'); loadCourses();
    } catch (e) { showError(e); }
  });

  loadEnrollments();
});
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>

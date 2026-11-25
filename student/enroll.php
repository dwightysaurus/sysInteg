<?php
// Student enrollment — the 4 steps from the Figma design:
//   1 Select subjects  ·  2 Review  ·  3 Fee assessment  ·  4 Payment status  (+ "You're enrolled!")
require __DIR__ . '/../lib/bootstrap.php';

$root = '../';
$user = page_require_role('student', $root);
$pageTitle = 'Enrollment';
require __DIR__ . '/../partials/header.php';
?>
<main class="page">
  <nav class="stepper" id="stepper" aria-label="Enrollment steps"></nav>
  <div id="view"><p class="muted">Loading…</p></div>
</main>

<div class="overlay hidden" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
  <div class="modal">
    <h2 id="confirm-title">Submit your enrollment?</h2>
    <p style="margin:0">You can't edit after submitting.</p>
    <div class="modal-actions">
      <button class="btn btn-outline" id="confirm-cancel">Cancel</button>
      <button class="btn btn-primary" id="confirm-submit">Submit</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const view = document.getElementById('view');
  const stepper = document.getElementById('stepper');
  const STEPS = ['Select subjects', 'Review', 'Fee assessment', 'Payment status'];

  const state = {
    enr: null,          // enrollment from the API
    sections: [],       // sections offered this term
    search: '',
    step: 1,
    alert: null,        // message shown above the step
    busy: false,
  };
  let pollTimer = null;

  // ---------- helpers ----------
  function renderStepper(current) {
    stepper.innerHTML = STEPS.map((label, i) => {
      const n = i + 1;
      let cls = 'step';
      if (n < current) cls += ' done';
      if (n === current) cls += ' current' + (n === 1 ? ' first-current' : '');
      const dot = n < current ? '✓' : n;
      return `<div class="${cls}"><div class="dot">${dot}</div><div class="label">${label}</div></div>`;
    }).join('');
    stepper.classList.toggle('hidden', current === 0);
  }

  function alertHtml() {
    if (!state.alert) return '';
    return `<div class="alert ${state.alert.cls || ''}">${state.alert.html}</div>`;
  }

  function showError(e) {
    state.alert = { html: '⚠ ' + esc(e.message) };
    render();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function stepFromUrl() {
    const s = parseInt(new URLSearchParams(location.search).get('step'), 10);
    return Number.isInteger(s) ? s : null;
  }

  function setStep(n) {
    state.step = n;
    state.alert = null;
    const url = new URL(location.href);
    url.searchParams.set('step', n);
    history.replaceState(null, '', url);
    route();
    window.scrollTo({ top: 0 });
  }

  // Decide which step the student may see based on the saved enrollment.
  function allowedStep(requested) {
    const stage = state.enr.stage;
    if (stage === 'not_started' || stage === 'selecting') return requested === 2 && state.enr.subjects.length ? 2 : 1;
    if (stage === 'pending_sync') return 4;
    if (stage === 'assessed') return requested === 4 ? 4 : 3;
    return 5; // paid -> "You're enrolled!"
  }

  // ---------- step 1: select subjects ----------
  function renderSelect() {
    const enr = state.enr;
    const selectedIds = new Set(enr.subjects.map((s) => s.section_id));
    const q = state.search.trim().toLowerCase();
    const list = state.sections.filter((s) => !q || s.code.toLowerCase().includes(q) || s.title.toLowerCase().includes(q));

    const items = list.map((s) => {
      let action;
      if (selectedIds.has(s.section_id)) action = `<button class="btn btn-outline btn-sm" data-add="${s.section_id}">Added</button>`;
      else if (!s.available) action = `<span class="chip chip-danger">✕ Unavailable</span>`;
      else action = `<button class="btn btn-primary btn-sm" data-add="${s.section_id}">Add</button>`;
      return `<li class="section-item">
          <div><div class="name">${esc(s.code)} · ${esc(s.title)}</div>
          <div class="meta">${esc(s.schedule)} · ${s.units} units · Slots ${s.enrolled}/${s.capacity}</div></div>
          ${action}</li>`;
    }).join('') || '<li class="section-item muted">No subjects match your search.</li>';

    const selection = enr.subjects.length
      ? enr.subjects.map((s) => `<div class="selection-item">
          <div><div class="code">${esc(s.code)}</div><div class="meta">${esc(s.title)} · ${s.units} units</div></div>
          <button class="btn-icon" title="Remove ${esc(s.code)}" aria-label="Remove ${esc(s.code)}" data-remove="${s.section_id}">✕</button>
        </div>`).join('')
      : '<p class="muted small" style="border-top:1px solid var(--row);padding-top:10px;margin:0">No subjects yet. Add a section from the list.</p>';

    view.innerHTML = `
      <h1>Select subjects</h1>
      ${alertHtml()}
      <div class="grid-enroll">
        <div class="card">
          <input class="input" id="search" placeholder="Search course code or title" value="${esc(state.search)}">
          <ul class="section-list">${items}</ul>
        </div>
        <div class="card">
          <h2>Your selection</h2>
          ${selection}
          <div class="units-total ${enr.total_units > enr.max_units ? 'over' : ''}">Total units: ${enr.total_units} / ${enr.max_units}</div>
          <button class="btn btn-primary btn-block" id="to-review" ${enr.subjects.length ? '' : 'disabled'}>Continue to review</button>
        </div>
      </div>`;

    const search = document.getElementById('search');
    search.addEventListener('input', () => {
      state.search = search.value;
      const pos = search.selectionStart;
      renderSelect();
      const again = document.getElementById('search');
      again.focus();
      again.setSelectionRange(pos, pos);
    });
    view.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => addSection(+b.dataset.add)));
    view.querySelectorAll('[data-remove]').forEach((b) => b.addEventListener('click', () => removeSection(+b.dataset.remove)));
    document.getElementById('to-review').addEventListener('click', () => setStep(2));
  }

  async function addSection(id) {
    if (state.busy) return;
    state.busy = true;
    try {
      state.enr = await Api.post('selection.php', { section_id: id });
      state.alert = null;
      await loadSections();
      render();
    } catch (e) {
      showError(e); // e.g. "This section is already added." / "Maximum of 21 units"
    } finally {
      state.busy = false;
    }
  }

  async function removeSection(id) {
    if (state.busy) return;
    state.busy = true;
    try {
      state.enr = await Api.del('selection.php?section_id=' + id);
      state.alert = null;
      render();
    } catch (e) {
      showError(e);
    } finally {
      state.busy = false;
    }
  }

  // ---------- step 2: review ----------
  function renderReview() {
    const enr = state.enr;
    const rows = enr.subjects.map((s) => `<tr><td>${esc(s.code)}</td><td>${esc(s.title)}</td><td>${esc(s.schedule)}</td><td class="num">${s.units}</td></tr>`).join('');
    view.innerHTML = `
      <h1>Review</h1>
      ${alertHtml()}
      <div class="card">
        <table class="table">
          <thead><tr><th>Code</th><th>Title</th><th>Schedule</th><th class="num">Units</th></tr></thead>
          <tbody>${rows}</tbody>
          <tfoot><tr><td colspan="3">Total units</td><td class="num">${enr.total_units}</td></tr></tfoot>
        </table>
        <div class="row row-end" style="margin-top:16px">
          <button class="btn btn-outline" id="back">Back</button>
          <button class="btn btn-primary" id="submit">Submit enrollment</button>
        </div>
      </div>`;
    document.getElementById('back').addEventListener('click', () => setStep(1));
    document.getElementById('submit').addEventListener('click', () => toggleModal(true));
  }

  function toggleModal(show) {
    document.getElementById('confirm-modal').classList.toggle('hidden', !show);
    if (show) document.getElementById('confirm-submit').focus();
  }
  document.getElementById('confirm-cancel').addEventListener('click', () => toggleModal(false));
  document.getElementById('confirm-submit').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      state.enr = await Api.post('submit.php');
      toggleModal(false);
      // Saved either way; go to Fee assessment if Cashiering answered, else Payment status (Pending + Retry).
      setStep(state.enr.stage === 'assessed' ? 3 : 4);
    } catch (e) {
      toggleModal(false);
      showError(e);
    } finally {
      btn.disabled = false;
    }
  });

  // ---------- step 3: fee assessment ----------
  function renderAssessment() {
    const p = state.enr.payment;
    const rows = p.items.map((i) => `<tr><td>${esc(i.label)}</td><td class="num">${peso(i.amount)}</td></tr>`).join('');
    view.innerHTML = `
      <h1>Fee assessment</h1>
      <div class="card">
        <table class="table">
          <thead><tr><th>Item</th><th class="num">Amount</th></tr></thead>
          <tbody>${rows}</tbody>
          <tfoot><tr><td>Total amount</td><td class="num">${peso(p.total)}</td></tr></tfoot>
        </table>
        <div class="row" style="margin:14px 0">Status <span class="chip">○ Unpaid</span></div>
        <div class="alert alert-note">Assessment received from the Cashiering System.</div>
        <div class="row row-end"><button class="btn btn-primary" id="to-payment">Proceed to payment status</button></div>
      </div>`;
    document.getElementById('to-payment').addEventListener('click', () => setStep(4));
  }

  // ---------- step 4: payment status ----------
  function renderPayment() {
    const enr = state.enr;
    const offline = enr.stage === 'pending_sync' || enr.cashiering_reachable === false;

    if (offline) {
      view.innerHTML = `
        <div class="alert">ℹ <strong>Your enrollment is saved.</strong><br>Payment status will update once the Cashiering System is available.</div>
        <div class="card">
          <h2>Payment status</h2>
          <p style="margin:0 0 14px"><span class="chip chip-pending">• Pending</span></p>
          <button class="btn btn-primary" id="retry">Retry</button>
          ${enr.demo_mode ? '<button class="btn btn-outline" id="demo-online" style="margin-left:8px">Demo: Cashiering back online</button>' : ''}
        </div>`;
      document.getElementById('retry').addEventListener('click', retry);
      if (enr.demo_mode) document.getElementById('demo-online').addEventListener('click', () => demo('online'));
      return;
    }

    view.innerHTML = `
      <h1>Payment status</h1>
      ${alertHtml()}
      <div class="card">
        <p style="margin:0 0 10px"><span class="chip chip-pending">• Pending</span></p>
        <p style="margin:0 0 14px">Waiting for the cashier to record your payment of <strong>${peso(enr.payment.total)}</strong>.
          Pay at the Cashier's window and this page updates automatically.</p>
        <p class="muted small" style="margin:0 0 14px">Reference ${esc(enr.reference_no)} · Assessment ${esc(enr.payment.assessment_no)}</p>
        ${enr.demo_mode ? `<div class="row">
          <button class="btn btn-warn" id="demo-pay">Demo: cashier recorded payment</button>
          <button class="btn btn-outline" id="demo-offline">Demo: Cashiering unavailable</button></div>` : ''}
      </div>`;
    if (enr.demo_mode) {
      document.getElementById('demo-pay').addEventListener('click', () => demo('pay'));
      document.getElementById('demo-offline').addEventListener('click', () => demo('offline'));
    }
  }

  async function retry(ev) {
    const btn = ev?.currentTarget;
    if (btn) btn.disabled = true;
    try {
      state.enr = await Api.post('retry.php');
      if (state.enr.cashiering_reachable === false) toast('Cashiering System is still unavailable. Your enrollment stays saved.');
      route();
    } catch (e) {
      showError(e);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function demo(action) {
    try {
      await Api.post('demo.php', { action });
      state.enr = await Api.get('enrollment.php?refresh=1');
      if (action === 'offline') toast('Mock Cashiering System is now offline.');
      if (action === 'online') { state.enr = await Api.post('retry.php'); }
      route();
    } catch (e) {
      showError(e);
    }
  }

  // Refresh the payment status automatically while waiting.
  function startPolling() {
    stopPolling();
    pollTimer = setInterval(async () => {
      try {
        const before = state.enr.stage + '|' + state.enr.cashiering_reachable;
        state.enr = state.enr.stage === 'pending_sync'
          ? await Api.post('retry.php')                // keep trying to send it
          : await Api.get('enrollment.php?refresh=1'); // ask Cashiering for payment status
        if (state.enr.stage + '|' + state.enr.cashiering_reachable !== before) route();
      } catch (e) { /* ignore and try again next tick */ }
    }, state.enr.stage === 'pending_sync' ? 15000 : 5000);
  }
  function stopPolling() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

  // ---------- enrolled ----------
  function renderEnrolled() {
    const enr = state.enr;
    const subjects = enr.subjects.map((s) => `<div class="selection-item">
        <div><div class="code">${esc(s.code)}</div><div class="meta">${esc(s.title)}</div></div>
        <div class="muted small">${s.units} u</div></div>`).join('');
    view.innerHTML = `
      <div class="card">
        <div class="big-check">✓</div>
        <h1 class="center" style="margin-bottom:8px">You're enrolled!</h1>
        <div class="row" style="justify-content:center;margin-bottom:14px">
          <span class="chip chip-success">✓ Enrolled</span><span class="chip chip-success">● Paid</span></div>
        <div class="kv" style="margin-bottom:18px">
          <div class="muted">Term</div><div>${esc(enr.term)}</div>
          <div class="muted">Amount paid</div><div>${peso(enr.payment.amount_paid)}</div>
          <div class="muted">OR number</div><div>${esc(enr.payment.or_number)}</div>
        </div>
        <h2>Subjects</h2>
        ${subjects}
        <div class="row" style="justify-content:center;margin-top:18px">
          <a class="btn btn-primary" href="summary.php" target="_blank">Download summary</a>
          <a class="btn btn-outline" href="dashboard.php">Back to dashboard</a>
        </div>
      </div>`;
  }

  // ---------- routing ----------
  function render() {
    const s = state.step;
    renderStepper(s === 5 ? 0 : s);
    if (s === 1) renderSelect();
    else if (s === 2) renderReview();
    else if (s === 3) renderAssessment();
    else if (s === 4) renderPayment();
    else renderEnrolled();
  }

  function route() {
    const step = allowedStep(state.step);
    if (step !== state.step) {
      state.step = step;
      const url = new URL(location.href);
      url.searchParams.set('step', step);
      history.replaceState(null, '', url);
    }
    render();
    if (step === 4) startPolling(); else stopPolling();
  }

  async function loadSections() {
    const data = await Api.get('sections.php');
    state.sections = data.sections;
  }

  (async () => {
    try {
      state.enr = await Api.get('enrollment.php');
      if (state.enr.stage === 'not_started' || state.enr.stage === 'selecting') await loadSections();
      state.step = stepFromUrl() || (state.enr.stage === 'assessed' ? 3 : 1);
      route();
    } catch (e) {
      view.innerHTML = `<div class="alert alert-danger">⚠ ${esc(e.message)}</div>`;
    }
  })();
});
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>

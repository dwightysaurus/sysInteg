<?php
// Login page for all roles. Redirects to the right page for the role (Requirement 5).
require __DIR__ . '/lib/bootstrap.php';

$root = '';
if ($u = current_user()) {
    header('Location: ' . home_for_role($u['role']));
    exit;
}
$pageTitle = 'Log in';
$showLogout = false;
require __DIR__ . '/partials/header.php';
?>
<main class="login-wrap">
  <form class="card login-card" id="login-form" novalidate>
    <div class="login-logo">EcD</div>
    <h1 class="center" style="margin-bottom:6px">Welcome !</h1>
    <p class="center muted small" style="margin:0 0 18px">Sign in with your university email</p>

    <div class="field">
      <label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" placeholder="student@ecd.edu.ph" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input class="input" id="password" name="password" type="password" placeholder="password" autocomplete="current-password" required>
      <div class="field-error hidden" id="login-error"></div>
    </div>
    <button class="btn btn-primary btn-block" style="background:var(--login-btn);border-color:var(--login-btn);margin-top:6px" type="submit" id="login-btn">Log in</button>
  </form>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('login-form');
  const err = document.getElementById('login-error');
  const inputs = form.querySelectorAll('.input');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.classList.add('hidden');
    inputs.forEach((i) => i.classList.remove('invalid'));
    const btn = document.getElementById('login-btn');
    btn.disabled = true;
    try {
      const data = await Api.post('login.php', { email: form.email.value, password: form.password.value });
      window.location.href = data.redirect;
    } catch (e) {
      err.textContent = '⚠ ' + e.message;
      err.classList.remove('hidden');
      inputs.forEach((i) => i.classList.add('invalid'));
      btn.disabled = false;
    }
  });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>

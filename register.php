<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/staff_self_register.php';
pcvc_ensure_staff_account_columns($conn);

if (empty($_SESSION['pcvc_reg_csrf'])) {
    $_SESSION['pcvc_reg_csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = pcvc_self_register_staff($conn, $_POST, (string) $_SESSION['pcvc_reg_csrf']);
    if (!empty($result['rotate_csrf'])) {
        $_SESSION['pcvc_reg_csrf'] = bin2hex(random_bytes(16));
    }
    $_SESSION['pcvc_reg_flash'] = $result;
    header('Location: register.php');
    exit;
}

$flash = $_SESSION['pcvc_reg_flash'] ?? null;
unset($_SESSION['pcvc_reg_flash']);
$csrf = (string) $_SESSION['pcvc_reg_csrf'];
$old = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$sentEmail = (is_array($flash) && !empty($flash['ok'])) ? (string) ($flash['email'] ?? '') : '';
$error = (is_array($flash) && empty($flash['ok'])) ? (string) ($flash['error'] ?? '') : '';
$fieldErrors = (is_array($flash) && is_array($flash['fields'] ?? null)) ? $flash['fields'] : [];

$val = static function (string $key) use ($old): string {
    return htmlspecialchars((string) ($old[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
$bad = static function (string $key) use ($fieldErrors): string {
    return trim((string) ($fieldErrors[$key] ?? '')) !== '' ? ' is-missing' : '';
};
$why = static function (string $key) use ($fieldErrors): string {
    $msg = trim((string) ($fieldErrors[$key] ?? ''));
    if ($msg === '') {
        return '';
    }

    return '<div class="field-error">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="Create a Parrot Canada staff account. Your sign-in link and password are emailed to you.">
<title>Create account | Parrot Canada Visa Consultant</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --green: #427431;
  --green-deep: #2f5a26;
  --green-hover: #356a2a;
  --red: #E21D1E;
  --blue: #3661B9;
  --text: #1e293b;
  --muted: #64748b;
  --line: #e2e8f0;
  --card: rgba(255,255,255,.94);
  --danger: #dc2626;
  --danger-bg: #fef2f2;
  --ok: #166534;
  --ok-bg: #f0fdf4;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif;
  color: var(--text);
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: max(16px, env(safe-area-inset-top)) 16px max(16px, env(safe-area-inset-bottom));
  background:
    radial-gradient(ellipse 90% 70% at 12% 8%, rgba(66,116,49,.28), transparent 55%),
    radial-gradient(ellipse 70% 50% at 92% 88%, rgba(226,29,30,.14), transparent 50%),
    linear-gradient(168deg, #e8edf4 0%, #f4f7f4 48%, #e7eef8 100%);
}
.shell {
  width: min(980px, 100%);
  background: var(--card);
  border-radius: 24px;
  overflow: hidden;
  display: grid;
  grid-template-columns: 1fr;
  box-shadow: 0 28px 60px -18px rgba(15,23,42,.28), 0 0 0 1px rgba(255,255,255,.6);
}
.brand {
  background: linear-gradient(165deg, var(--green) 0%, var(--green-deep) 58%, #1e3d18 100%);
  color: #fff;
  padding: 36px 28px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.brand img {
  width: 92px;
  height: 92px;
  object-fit: contain;
  background: rgba(255,255,255,.14);
  border: 2px solid rgba(255,255,255,.45);
  border-radius: 50%;
  padding: 8px;
}
.brand h1 {
  margin: 18px 0 8px;
  font-size: clamp(1.45rem, 3vw, 1.85rem);
  letter-spacing: -.02em;
  line-height: 1.2;
}
.brand p { margin: 0; max-width: 36ch; line-height: 1.6; color: rgba(255,255,255,.92); }
.steps { list-style: none; margin: 26px 0 0; padding: 0; }
.steps li {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-top: 14px;
  font-size: .95rem;
  line-height: 1.45;
}
.num {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: rgba(255,255,255,.16);
  display: grid;
  place-items: center;
  font-size: .8rem;
  font-weight: 700;
  flex: 0 0 auto;
}
.panel { padding: 32px 22px 28px; background: #fff; }
.panel h2 { margin: 0 0 6px; font-size: 1.45rem; letter-spacing: -.02em; }
.sub { margin: 0 0 22px; color: var(--muted); line-height: 1.5; }
.notice, .error {
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 16px;
  font-size: .92rem;
  line-height: 1.5;
}
.error { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }
.notice { background: var(--ok-bg); color: var(--ok); border: 1px solid #bbf7d0; }
form { display: grid; gap: 14px; }
.choice {
  display: block;
  border: 1.5px solid #d5e0d8;
  border-radius: 12px;
  padding: 12px 10px;
  text-align: center;
  font-weight: 700;
  cursor: pointer;
  background: #f7faf8;
  color: var(--text);
}
.choice input { position: absolute; opacity: 0; pointer-events: none; }
.choice.is-on {
  border-color: var(--green);
  background: #eef6ea;
  color: var(--green-deep);
  box-shadow: 0 0 0 3px rgba(66,116,49,.12);
}
.company-box {
  border: 1px solid #d9e6d4;
  background: #f8faf8;
  border-radius: 14px;
  padding: 14px;
  display: grid;
  gap: 14px;
}
.company-box.is-off { display: none; }
.company-box h3 { margin: 0; font-size: 1rem; }
.company-box .lead { margin: -6px 0 0; color: var(--muted); font-size: .8rem; line-height: 1.45; }
fieldset.kind { border: 0; margin: 0; padding: 0; }
fieldset.kind legend { font-size: .82rem; font-weight: 600; margin-bottom: 8px; }
.choices { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.row { display: grid; gap: 14px; }
label { display: block; font-size: .82rem; font-weight: 600; margin-bottom: 6px; }
input[type="text"], input[type="email"], input[type="tel"] {
  width: 100%;
  border: 1.5px solid #d5e0d8;
  background: #f7faf8;
  border-radius: 12px;
  padding: 13px 14px;
  font: inherit;
  font-size: 16px;
  color: var(--text);
}
input.is-missing {
  border-color: var(--danger) !important;
  background: #fff5f5 !important;
  box-shadow: 0 0 0 4px rgba(220, 38, 38, .12);
}
.field-error {
  color: var(--danger);
  font-size: .78rem;
  font-weight: 600;
  margin-top: 6px;
}
input:focus {
  outline: none;
  border-color: var(--green);
  background: #fff;
  box-shadow: 0 0 0 4px rgba(66,116,49,.12);
}
.hint { margin-top: 6px; color: var(--muted); font-size: .78rem; min-height: 1em; }
.hint.is-bad { color: var(--danger); }
.hint.is-ok { color: var(--ok); }
.hp {
  position: absolute;
  left: -10000px;
  width: 1px;
  height: 1px;
  overflow: hidden;
}
button {
  margin-top: 6px;
  width: 100%;
  border: 0;
  border-radius: 12px;
  background: var(--green);
  color: #fff;
  font: inherit;
  font-weight: 700;
  font-size: 1rem;
  padding: 14px 16px;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(66,116,49,.28);
}
button:hover { background: var(--green-hover); }
button:disabled { opacity: .65; cursor: progress; }
.signin { margin: 18px 0 0; text-align: center; color: var(--muted); font-size: .92rem; }
.signin a { color: var(--green); font-weight: 700; text-decoration: none; }
.signin a:hover { text-decoration: underline; }
.fine {
  margin: 18px 0 0;
  padding-top: 16px;
  border-top: 1px solid var(--line);
  color: #94a3b8;
  font-size: .75rem;
  text-align: center;
  line-height: 1.5;
}
@media (min-width: 860px) {
  .shell { grid-template-columns: .92fr 1.08fr; min-height: 620px; }
  .brand { padding: 48px 40px; }
  .panel { padding: 48px 40px; display: flex; flex-direction: column; justify-content: center; }
  .row { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="shell">
  <aside class="brand">
    <img src="parrot-canada-logo.png" alt="Parrot Canada Visa Consultant" onerror="this.style.display='none'">
    <h1>Join the Parrot Canada team</h1>
    <p>Tell us who you are. A superadmin approves the account, then we email your password and sign-in link.</p>
    <ol class="steps">
      <li><span class="num">1</span><span>Tell us who you are and which email you will use.</span></li>
      <li><span class="num">2</span><span>A superadmin reviews the account. You cannot sign in yet.</span></li>
      <li><span class="num">3</span><span>After approval, we email your password and sign-in link.</span></li>
    </ol>
  </aside>
  <section class="panel">
    <h2>Create your account</h2>
    <p class="sub">New accounts sign in with email. Existing staff can still sign in with a username or an email.</p>

    <?php if ($sentEmail !== ''): ?>
      <div class="notice" role="status">
        Your account was submitted. A superadmin must approve it before you can sign in. We will email the sign-in link and password to <strong><?= htmlspecialchars($sentEmail, ENT_QUOTES, 'UTF-8') ?></strong> after approval.
      </div>
      <p class="signin">You can open the sign-in page after the approval email arrives.</p>
    <?php else: ?>
      <?php if ($error !== ''): ?>
        <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post" action="register.php" id="staffForm" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="hp" aria-hidden="true">
          <label for="leave_blank">Leave blank</label>
          <input type="text" name="leave_blank" id="leave_blank" tabindex="-1" autocomplete="off">
        </div>
        <?php $isCompany = $val('account_type') === 'company'; ?>
        <fieldset class="kind">
          <legend>Account type</legend>
          <div class="choices">
            <label class="choice<?= $isCompany ? '' : ' is-on' ?>" id="choicePersonal">
              <input type="radio" name="account_type" value="personal" <?= $isCompany ? '' : 'checked' ?>>
              Personal
            </label>
            <label class="choice<?= $isCompany ? ' is-on' : '' ?>" id="choiceCompany">
              <input type="radio" name="account_type" value="company" <?= $isCompany ? 'checked' : '' ?>>
              Company
            </label>
          </div>
        </fieldset>
        <div class="company-box<?= $isCompany ? '' : ' is-off' ?>" id="companyFields">
          <h3>Company details</h3>
          <p class="lead">All company fields are required. Website is the only optional one.</p>
          <div>
            <label for="company_name">Company name</label>
            <input type="text" id="company_name" name="company_name" maxlength="190" autocomplete="organization" placeholder="Registered company name" value="<?= $val('company_name') ?>" class="<?= trim($bad('company_name')) ?>">
            <?= $why('company_name') ?>
          </div>
          <div>
            <label for="company_registration">Registration number</label>
            <input type="text" id="company_registration" name="company_registration" maxlength="80" placeholder="Business or tax registration number" value="<?= $val('company_registration') ?>" class="<?= trim($bad('company_registration')) ?>">
            <?= $why('company_registration') ?>
          </div>
          <div>
            <label for="company_address">Company address</label>
            <input type="text" id="company_address" name="company_address" maxlength="255" autocomplete="street-address" placeholder="Street, city, country" value="<?= $val('company_address') ?>" class="<?= trim($bad('company_address')) ?>">
            <?= $why('company_address') ?>
          </div>
          <div class="row">
            <div>
              <label for="company_phone">Company phone</label>
              <input type="tel" id="company_phone" name="company_phone" maxlength="24" autocomplete="tel" placeholder="Include the country code" value="<?= $val('company_phone') ?>" class="<?= trim($bad('company_phone')) ?>">
              <?= $why('company_phone') ?>
            </div>
            <div>
              <label for="company_website_url">Website <span style="font-weight:500;color:#64748b">(optional)</span></label>
              <input type="text" id="company_website_url" name="company_website_url" maxlength="190" inputmode="url" placeholder="Optional" value="<?= $val('company_website_url') ?>" class="<?= trim($bad('company_website_url')) ?>">
              <?= $why('company_website_url') ?>
            </div>
          </div>
        </div>
        <div class="row">
          <div>
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" required maxlength="80" autocomplete="given-name" placeholder="First name" value="<?= $val('first_name') ?>" class="<?= trim($bad('first_name')) ?>">
            <?= $why('first_name') ?>
          </div>
          <div>
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" required maxlength="80" autocomplete="family-name" placeholder="Last name" value="<?= $val('last_name') ?>" class="<?= trim($bad('last_name')) ?>">
            <?= $why('last_name') ?>
          </div>
        </div>
        <div>
          <label for="phone_number">Phone number</label>
          <input type="tel" id="phone_number" name="phone_number" required maxlength="24" autocomplete="tel" placeholder="Include the country code" value="<?= $val('phone_number') ?>" class="<?= trim($bad('phone_number')) ?>">
          <?= $why('phone_number') ?>
        </div>
        <div>
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" required maxlength="190" autocomplete="email" inputmode="email" placeholder="name@email.com" value="<?= $val('email') ?>" class="<?= trim($bad('email')) ?>">
          <?= $why('email') ?>
          <div class="hint" id="emailHint">This email is your sign-in name. The password is emailed after a superadmin approves the account.</div>
        </div>
        <button type="submit" id="submitBtn">Create account</button>
      </form>
      <p class="signin">Already have an account? <a href="admin-login.php">Sign in</a></p>
    <?php endif; ?>
    <p class="fine">Parrot Canada Visa Consultant · Staff registration</p>
  </section>
</div>
<script>
(function () {
  var email = document.getElementById('email');
  var hint = document.getElementById('emailHint');
  var button = document.getElementById('submitBtn');
  var form = document.getElementById('staffForm');
  var companyBox = document.getElementById('companyFields');
  var personalChoice = document.getElementById('choicePersonal');
  var companyChoice = document.getElementById('choiceCompany');
  if (!form) return;
  function syncAccountType() {
    var company = form.querySelector('input[name="account_type"]:checked');
    var on = company && company.value === 'company';
    if (companyBox) companyBox.classList.toggle('is-off', !on);
    if (personalChoice) personalChoice.classList.toggle('is-on', !on);
    if (companyChoice) companyChoice.classList.toggle('is-on', on);
    ['company_name', 'company_registration', 'company_address', 'company_phone'].forEach(function (id) {
      var field = document.getElementById(id);
      if (field) field.required = !!on;
    });
  }
  form.querySelectorAll('input[name="account_type"]').forEach(function (radio) {
    radio.addEventListener('change', syncAccountType);
  });
  syncAccountType();
  if (!email || !hint) return;
  var timer;
  var base = 'This email is your sign-in name. The password is emailed after a superadmin approves the account.';
  email.addEventListener('input', function () {
    clearTimeout(timer);
    hint.className = 'hint';
    hint.textContent = base;
    button.disabled = false;
    if (email.value.trim().length < 5) return;
    timer = setTimeout(function () {
      hint.textContent = 'Checking this email…';
      fetch('check_user.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=email&value=' + encodeURIComponent(email.value.trim())
      }).then(function (r) { return r.text(); }).then(function (data) {
        if (data === 'exists') {
          hint.className = 'hint is-bad';
          hint.textContent = 'This email already has an account. Sign in instead.';
          button.disabled = true;
        } else if (data === 'ok') {
          hint.className = 'hint is-ok';
          hint.textContent = 'Available. The password is emailed after approval.';
          button.disabled = false;
        } else {
          hint.className = 'hint';
          hint.textContent = base;
        }
      }).catch(function () {
        hint.className = 'hint';
        hint.textContent = base;
        button.disabled = false;
      });
    }, 400);
  });
  function clearMissing(field) {
    field.classList.remove('is-missing');
    var note = field.parentElement && field.parentElement.querySelector('.field-error');
    if (note) note.remove();
  }
  form.querySelectorAll('input').forEach(function (field) {
    field.addEventListener('input', function () { clearMissing(field); });
  });
  form.addEventListener('submit', function (event) {
    var company = form.querySelector('input[name="account_type"]:checked');
    var on = company && company.value === 'company';
    var required = ['first_name', 'last_name', 'phone_number', 'email'];
    if (on) required = required.concat(['company_name', 'company_registration', 'company_address', 'company_phone']);
    var first = null;
    form.querySelectorAll('input.is-missing').forEach(function (field) { clearMissing(field); });
    required.forEach(function (id) {
      var field = document.getElementById(id);
      if (!field || field.value.trim() !== '') return;
      field.classList.add('is-missing');
      var note = document.createElement('div');
      note.className = 'field-error';
      note.textContent = 'This field is required.';
      field.parentElement.appendChild(note);
      if (!first) first = field;
    });
    if (first) {
      event.preventDefault();
      button.disabled = false;
      button.textContent = 'Create account';
      var banner = form.parentElement.querySelector('.error');
      if (!banner) {
        banner = document.createElement('div');
        banner.className = 'error';
        banner.setAttribute('role', 'alert');
        form.parentElement.insertBefore(banner, form);
      }
      banner.textContent = 'Fill in the highlighted fields.';
      first.focus();
      first.scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    button.disabled = true;
    button.textContent = 'Creating your account…';
  });
})();
</script>
</body>
</html>

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/self_registration.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access.');
}

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

pcvc_self_reg_ensure_schema($conn);

$adminId = !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : (int) ($_SESSION['id'] ?? 0);
$issuedLink = null;
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'issue') {
    try {
        $created = pcvc_self_reg_issue_link($conn, $adminId > 0 ? $adminId : null);
        $issuedLink = pcvc_self_reg_public_url($created['token']);
        $message = 'Private self-registration link created. Copy and send it to the student after payment.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$recent = pcvc_self_reg_recent($conn, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Self Registration Links</title>
<style>
:root {
  --green: #1b7a3a;
  --green-deep: #14532d;
  --ink: #0f172a;
  --muted: #64748b;
  --line: #dbe7df;
  --card: #ffffff;
  --bg: #f3f7f4;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: "Segoe UI", system-ui, sans-serif;
  background:
    radial-gradient(900px 280px at 10% -10%, rgba(34,197,94,.16), transparent 60%),
    linear-gradient(180deg, #eef8f1 0%, var(--bg) 40%, #f8fafc 100%);
  color: var(--ink);
}
.wrap { max-width: 920px; margin: 28px auto; padding: 0 16px 40px; }
.card {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: 18px;
  padding: 22px;
  box-shadow: 0 14px 34px rgba(20, 83, 45, 0.08);
}
h1 { margin: 0 0 6px; font-size: 1.45rem; color: var(--green-deep); }
.sub { margin: 0 0 18px; color: var(--muted); font-size: .95rem; }
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  border: 0; border-radius: 12px; padding: 13px 18px; font-weight: 700;
  cursor: pointer; font-size: .98rem;
}
.btn-primary { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; width: 100%; }
.btn-copy { background: #0ea5e9; color: #fff; width: 100%; margin-top: 10px; }
.alert { padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-weight: 650; }
.alert.ok { background: #ecfdf5; color: #14532d; border: 1px solid #86efac; }
.alert.bad { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.link-box { margin-top: 16px; padding: 14px; border-radius: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
.link-box input {
  width: 100%; padding: 12px 14px; border-radius: 10px; border: 2px solid #86efac;
  font-size: .95rem; background: #fff;
}
.table-wrap { margin-top: 22px; overflow: auto; border-radius: 14px; border: 1px solid var(--line); background: #fff; }
table { width: 100%; border-collapse: collapse; min-width: 720px; }
th, td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #eef2f0; font-size: .9rem; }
th { background: #f0fdf4; color: #14532d; position: sticky; top: 0; }
.badge {
  display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: .75rem; font-weight: 750;
}
.badge.draft { background: #fff7ed; color: #9a3412; }
.badge.submitted { background: #dcfce7; color: #14532d; }
.copy-mini {
  border: 0; background: #e0f2fe; color: #075985; border-radius: 8px;
  padding: 6px 10px; font-weight: 700; cursor: pointer;
}
@media (max-width: 640px) {
  .wrap { margin-top: 14px; }
  .card { padding: 16px; border-radius: 14px; }
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Self Registration</h1>
    <p class="sub">Issue a private link for a student who has already paid. They fill the Canada study application form, upload documents (payment proof required), and submit.</p>

    <?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="issue">
      <button class="btn btn-primary" type="submit">Create private self-registration link</button>
    </form>

    <?php if ($issuedLink): ?>
    <div class="link-box">
      <label for="srLink" style="display:block;font-weight:750;margin-bottom:8px;color:#14532d;">Private link</label>
      <input id="srLink" type="text" readonly value="<?= htmlspecialchars($issuedLink) ?>">
      <button class="btn btn-copy" type="button" id="copyBtn">Copy private link</button>
      <div id="copyMsg" style="display:none;margin-top:8px;color:#15803d;font-weight:700;text-align:center;">Link copied</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-top:18px;">
    <h2 style="margin:0 0 12px;font-size:1.1rem;color:#14532d;">Recent links & submissions</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Status</th>
            <th>Destination / Intake</th>
            <th>Created</th>
            <th>Link</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="5" style="color:#64748b;">No self-registration links yet.</td></tr>
        <?php else: foreach ($recent as $row):
          $link = pcvc_self_reg_public_url((string) $row['token']);
          $name = trim((string) ($row['full_name'] ?? ''));
          $email = trim((string) ($row['email'] ?? ''));
          $label = $name !== '' ? $name : ($email !== '' ? $email : 'Not filled yet');
          $status = (string) ($row['status'] ?? 'draft');
        ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($label) ?></strong>
              <?php if ($email !== '' && $name !== ''): ?><div style="color:#64748b;font-size:.82rem;"><?= htmlspecialchars($email) ?></div><?php endif; ?>
            </td>
            <td><span class="badge <?= $status === 'submitted' ? 'submitted' : 'draft' ?>"><?= htmlspecialchars($status) ?></span></td>
            <td>
              <?= htmlspecialchars(trim((string) ($row['destination_country'] ?? '')) ?: '—') ?><br>
              <span style="color:#64748b;font-size:.82rem;"><?= htmlspecialchars(trim((string) ($row['preferred_intake'] ?? '')) ?: '') ?></span>
            </td>
            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
            <td><button type="button" class="copy-mini" data-link="<?= htmlspecialchars($link) ?>">Copy</button></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function () {
  function copyText(text, onOk) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onOk).catch(function () {
        fallback(text); onOk();
      });
      return;
    }
    fallback(text); onOk();
  }
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  var btn = document.getElementById('copyBtn');
  var input = document.getElementById('srLink');
  var msg = document.getElementById('copyMsg');
  if (btn && input) {
    btn.addEventListener('click', function () {
      copyText(input.value, function () {
        if (msg) { msg.style.display = 'block'; setTimeout(function () { msg.style.display = 'none'; }, 1800); }
      });
    });
  }
  document.querySelectorAll('.copy-mini').forEach(function (el) {
    el.addEventListener('click', function () {
      var link = el.getAttribute('data-link') || '';
      copyText(link, function () {
        el.textContent = 'Copied';
        setTimeout(function () { el.textContent = 'Copy'; }, 1400);
      });
    });
  });
})();
</script>
</body>
</html>

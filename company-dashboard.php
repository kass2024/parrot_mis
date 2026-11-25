<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/companies_management.php';

pcvc_ensure_staff_account_columns($conn);

if (!isset($_SESSION['id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$isSuperAdmin = pcvc_is_superadmin_role($_SESSION['role'] ?? '');
$viewerId = (int) ($_SESSION['id'] ?? 0);
$companyId = (int) ($_GET['id'] ?? 0);

function cd_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$company = null;
if ($companyId > 0) {
    $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $company = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
}

if (!$company || !pcvc_admin_is_company_account($company)) {
    http_response_code(404);
    exit('Company account not found.');
}

// Superadmins monitor any company. The company itself can open its own dashboard.
$canView = $isSuperAdmin || $viewerId === $companyId;
if (!$canView) {
    http_response_code(403);
    exit('You can only open your own company dashboard.');
}

$email = strtolower(trim((string) ($company['email'] ?? '')));
$status = strtolower(trim((string) ($company['account_status'] ?? 'active')));
if ($status === '') {
    $status = 'active';
}
$companyName = trim((string) ($company['company_name'] ?? ''));
$contact = trim((string) ($company['full_name'] ?? ''));
if ($contact === '') {
    $contact = trim((string) (($company['first_name'] ?? '') . ' ' . ($company['last_name'] ?? '')));
}

$counts = pcvc_company_activity_counts($conn, $companyId, $email);
$apps = pcvc_company_applications($conn, $companyId, $email, 150);
$selfRegs = [];
if (function_exists('pcvc_self_reg_list_applications')) {
    $selfRegs = pcvc_self_reg_list_applications($conn, $companyId, 'all', 80, $companyId);
}

$reportUrl = 'application-list.php';
if ($email !== '') {
    $reportUrl .= '?agent_email=' . rawurlencode($email);
}
$selfRegUrl = 'admin-self-registration.php';
$justApproved = isset($_GET['approved']) && (string) $_GET['approved'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= cd_h($companyName !== '' ? $companyName : 'Company') ?> — Dashboard</title>
<style>
:root {
  --green:#15803d; --deep:#14532d; --sky:#0284c7; --ink:#0f172a; --muted:#64748b;
  --line:#d7e7dc; --card:#fff;
}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",system-ui,sans-serif;color:var(--ink);background:radial-gradient(900px 260px at 0% -10%,rgba(34,197,94,.14),transparent 55%),linear-gradient(180deg,#eef8f1,#f8fafc 40%,#fff)}
.wrap{max-width:1100px;margin:0 auto;padding:18px 14px 40px}
.hero{background:linear-gradient(135deg,#14532d,#15803d 55%,#0284c7);color:#fff;border-radius:20px;padding:20px 18px;box-shadow:0 16px 36px rgba(20,83,45,.16);margin-bottom:14px}
.hero h1{margin:0 0 4px;font-size:clamp(1.15rem,3vw,1.5rem)}
.hero p{margin:0;opacity:.92;font-size:.95rem}
.hero-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:14px 0}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.04)}
.kpi .n{font-size:1.45rem;font-weight:800;color:var(--deep)}
.kpi .l{font-size:.78rem;color:var(--muted);font-weight:700;margin-top:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
.card h2{margin:0 0 12px;font-size:1.05rem;color:var(--deep)}
.muted{color:var(--muted);font-size:.86rem}
.btn{border:0;border-radius:12px;padding:10px 13px;font-weight:750;cursor:pointer;font-size:.88rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-sky{background:#0ea5e9;color:#fff}
.btn-green{background:linear-gradient(135deg,#15803d,#16a34a);color:#fff}
.btn-ghost{background:#f1f5f9;color:#0f172a}
.btn-amber{background:#f59e0b;color:#111}
.btn-sm{padding:7px 10px;font-size:.8rem;border-radius:9px}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.72rem;font-weight:800}
.badge.pending{background:#fff7ed;color:#9a3412}
.badge.active{background:#dcfce7;color:#14532d}
.badge.inactive{background:#f1f5f9;color:#475569}
.dl{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;font-size:.9rem}
.dl dt{color:var(--muted);font-weight:700}
.dl dd{margin:0;font-weight:650}
.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}
table{width:100%;border-collapse:collapse;min-width:780px}
th,td{padding:10px 11px;border-bottom:1px solid #eef2f0;text-align:left;font-size:.88rem;vertical-align:top}
th{background:#f0fdf4;color:#14532d;position:sticky;top:0}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.dl{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <?php if ($justApproved): ?>
  <div style="padding:12px 14px;border-radius:12px;background:#ecfdf5;border:1px solid #86efac;color:#14532d;font-weight:700;margin-bottom:12px">
    Company approved. Sign-in details were emailed. You are now on this company’s activity dashboard.
  </div>
  <?php endif; ?>
  <div class="hero">
    <h1><?= cd_h($companyName !== '' ? $companyName : 'Company dashboard') ?></h1>
    <p>
      Monitoring applications and self-registrations for this company partner.
      Contact: <?= cd_h($contact !== '' ? $contact : '—') ?>
      <?php if ($email !== ''): ?> · <?= cd_h($email) ?><?php endif; ?>
      · <span class="badge <?= cd_h($status) ?>" style="background:rgba(255,255,255,.2);color:#fff"><?= cd_h(ucfirst($status)) ?></span>
    </p>
    <div class="hero-actions">
      <?php if ($isSuperAdmin): ?>
      <a class="btn btn-ghost" href="companies-management.php?view=<?= (int)$companyId ?>">Back to companies</a>
      <a class="btn btn-ghost" href="companies-management.php?view=<?= (int)$companyId ?>">Edit / approve</a>
      <?php endif; ?>
      <a class="btn btn-sky" href="<?= cd_h($reportUrl) ?>">Open application report</a>
      <a class="btn btn-green" href="<?= cd_h($selfRegUrl) ?>">Self registrations</a>
    </div>
  </div>

  <div class="kpi-grid">
    <div class="kpi"><div class="n"><?= (int)$counts['applications'] ?></div><div class="l">Applications</div></div>
    <div class="kpi"><div class="n"><?= (int)$counts['assigned'] ?></div><div class="l">In charge</div></div>
    <div class="kpi"><div class="n"><?= (int)$counts['recruited'] ?></div><div class="l">Recruited</div></div>
    <div class="kpi"><div class="n"><?= (int)$counts['self_regs'] ?></div><div class="l">Self registrations</div></div>
    <div class="kpi"><div class="n"><?= (int)$counts['admitted'] ?></div><div class="l">Admitted / enrolled</div></div>
  </div>

  <div class="card">
    <h2>Company profile</h2>
    <dl class="dl">
      <dt>Registration</dt><dd><?= cd_h((string)($company['company_registration'] ?? '')) ?: '—' ?></dd>
      <dt>Address</dt><dd><?= cd_h((string)($company['company_address'] ?? '')) ?: '—' ?></dd>
      <dt>Company phone</dt><dd><?= cd_h((string)($company['company_phone'] ?? '')) ?: '—' ?></dd>
      <dt>Website</dt><dd><?php
        $web = trim((string)($company['company_website_url'] ?? ''));
        if ($web === '') { echo '—'; }
        else {
          $href = preg_match('#^https?://#i', $web) ? $web : ('https://' . $web);
          echo '<a href="' . cd_h($href) . '" target="_blank" rel="noopener">' . cd_h($web) . '</a>';
        }
      ?></dd>
      <dt>Created</dt><dd><?= cd_h((string)($company['created_at'] ?? '')) ?: '—' ?></dd>
    </dl>
  </div>

  <div class="card">
    <h2>Student applications</h2>
    <p class="muted" style="margin:0 0 10px">Files assigned to this company or recruited under its email.</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Relation</th>
            <th>Destination / Intake</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$apps): ?>
          <tr><td colspan="5" class="muted">No linked applications yet.</td></tr>
        <?php else: foreach ($apps as $app): ?>
          <tr>
            <td>
              <strong><?= cd_h((string)$app['student_name']) ?></strong>
              <?php if (trim((string)($app['email'] ?? '')) !== ''): ?>
              <div class="muted"><?= cd_h((string)$app['email']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= cd_h((string)($app['relation'] ?? 'Linked')) ?></td>
            <td>
              <?= cd_h(trim((string)($app['destination_country'] ?? '')) ?: '—') ?>
              <div class="muted"><?= cd_h((string)($app['preferred_intake'] ?? '')) ?></div>
            </td>
            <td class="muted"><?= cd_h((string)($app['created_at'] ?? '')) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="<?= cd_h($reportUrl) ?>">View in report</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Self registrations</h2>
    <p class="muted" style="margin:0 0 10px">Submitted forms referred by or assigned to this company.</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Follow-up</th>
            <th>Destination / Intake</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$selfRegs): ?>
          <tr><td colspan="5" class="muted">No submitted self registrations yet.</td></tr>
        <?php else: foreach ($selfRegs as $sr):
          $srName = trim((string)($sr['full_name'] ?? ''));
          if ($srName === '') { $srName = trim((string)($sr['email'] ?? '')) ?: 'Not filled yet'; }
        ?>
          <tr>
            <td>
              <strong><?= cd_h($srName) ?></strong>
              <?php if (trim((string)($sr['email'] ?? '')) !== '' && $srName !== (string)$sr['email']): ?>
              <div class="muted"><?= cd_h((string)$sr['email']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= cd_h((string)($sr['followup_status'] ?? 'new')) ?></td>
            <td>
              <?= cd_h(trim((string)($sr['destination_country'] ?? '')) ?: '—') ?>
              <div class="muted"><?= cd_h((string)($sr['preferred_intake'] ?? '')) ?></div>
            </td>
            <td class="muted"><?= cd_h((string)($sr['created_at'] ?? '')) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="admin-self-registration.php?view=<?= (int)$sr['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>

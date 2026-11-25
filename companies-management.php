<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/company_branding.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/staff_self_register.php';
require_once __DIR__ . '/helpers/companies_management.php';
require_once __DIR__ . '/helpers/company_view_as.php';
require_once __DIR__ . '/helpers/profile_photo_upload.php';
require_once __DIR__ . '/helpers/secure_file.php';

pcvc_ensure_staff_account_columns($conn);

if (!isset($_SESSION['id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$isSuperAdmin = pcvc_is_superadmin_role($_SESSION['role'] ?? '');
$currentUserId = (int) ($_SESSION['id'] ?? 0);
if (empty($_SESSION['company_csrf'])) {
    $_SESSION['company_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string) $_SESSION['company_csrf'];

function cm_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function cm_redirect(string $extra = ''): void
{
    $q = $extra !== '' ? ('?' . ltrim($extra, '?')) : '';
    header('Location: companies-management.php' . $q);
    exit;
}

function cm_csrf_ok(string $posted, string $expected): bool
{
    return $posted !== '' && hash_equals($expected, $posted);
}

$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'pending', 'active', 'inactive'], true)) {
    $filter = 'all';
}
$viewId = (int) ($_GET['view'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuperAdmin) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!cm_csrf_ok($postedToken, $csrf)) {
        $_SESSION['error'] = 'This request expired. Refresh the page and try again.';
        cm_redirect('filter=' . rawurlencode($filter));
    }

    if (isset($_POST['approve_company'])) {
        $approveId = (int) ($_POST['id'] ?? 0);
        $result = pcvc_approve_staff_account($conn, $approveId);
        if (!empty($result['ok'])) {
            $_SESSION['success'] = 'Approved '
                . (string) ($result['name'] ?? 'the company')
                . '. Sign-in details were emailed to '
                . (string) ($result['email'] ?? 'the contact')
                . '. Opening the company dashboard…';
            header('Location: company-dashboard.php?id=' . $approveId . '&approved=1');
            exit;
        }
        $_SESSION['error'] = (string) ($result['error'] ?? 'Approval failed.');
        cm_redirect('filter=pending');
    }

    if (isset($_POST['deactivate_company'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1 || $id === $currentUserId) {
            $_SESSION['error'] = 'You cannot deactivate your own account.';
            cm_redirect('filter=' . rawurlencode($filter));
        }
        $row = null;
        $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
        if (!$row || !pcvc_admin_is_company_account($row)) {
            $_SESSION['error'] = 'That company account was not found.';
            cm_redirect('filter=' . rawurlencode($filter));
        }
        $inactive = 'inactive';
        $upd = $conn->prepare('UPDATE admins SET account_status = ? WHERE id = ? LIMIT 1');
        if ($upd) {
            $upd->bind_param('si', $inactive, $id);
            $upd->execute();
            $upd->close();
            $_SESSION['success'] = 'Company account deactivated.';
        } else {
            $_SESSION['error'] = 'Could not deactivate that company.';
        }
        cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
    }

    if (isset($_POST['reactivate_company'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $active = 'active';
        $onlyInactive = 'inactive';
        $upd = $conn->prepare(
            'UPDATE admins SET account_status = ?
             WHERE id = ? AND account_status = ?
               AND (LOWER(TRIM(COALESCE(account_type,\'\'))) = \'company\' OR TRIM(COALESCE(company_name,\'\')) <> \'\')
             LIMIT 1'
        );
        if ($upd && $id > 0) {
            $upd->bind_param('sis', $active, $id, $onlyInactive);
            $upd->execute();
            $changed = $upd->affected_rows > 0;
            $upd->close();
            $_SESSION['success'] = $changed
                ? 'Company account reactivated.'
                : 'That company was not inactive.';
        } else {
            $_SESSION['error'] = 'Could not reactivate that company.';
        }
        cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
    }

    if (isset($_POST['update_company'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
        $row = null;
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
        if (!$row || !pcvc_admin_is_company_account($row)) {
            $_SESSION['error'] = 'That company account was not found.';
            cm_redirect('filter=' . rawurlencode($filter));
        }

        $old = [
            'account_type' => 'company',
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'phone_number' => trim((string) ($_POST['phone_number'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'company_registration' => trim((string) ($_POST['company_registration'] ?? '')),
            'company_address' => trim((string) ($_POST['company_address'] ?? '')),
            'company_phone' => trim((string) ($_POST['company_phone'] ?? '')),
            'company_website_url' => trim((string) ($_POST['company_website_url'] ?? '')),
        ];
        if ($old['company_website_url'] !== '' && !preg_match('#^https?://#i', $old['company_website_url'])) {
            $old['company_website_url'] = 'https://' . $old['company_website_url'];
        }
        $fields = pcvc_register_field_errors($old);
        if ($fields !== []) {
            $_SESSION['error'] = reset($fields);
            cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
        }

        $dup = $conn->prepare('SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('si', $old['email'], $id);
            $dup->execute();
            $exists = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($exists) {
                $_SESSION['error'] = 'Another account already uses that email.';
                cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
            }
        }

        $fullName = $old['first_name'] . ' ' . $old['last_name'];
        $upd = $conn->prepare(
            'UPDATE admins SET
                first_name = ?, last_name = ?, full_name = ?, phone_number = ?, email = ?,
                account_type = \'company\',
                company_name = ?, company_registration = ?, company_address = ?,
                company_phone = ?, company_website_url = ?
             WHERE id = ? LIMIT 1'
        );
        if (!$upd) {
            $_SESSION['error'] = 'Could not save company details.';
            cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
        }
        $upd->bind_param(
            'ssssssssssi',
            $old['first_name'],
            $old['last_name'],
            $fullName,
            $old['phone_number'],
            $old['email'],
            $old['company_name'],
            $old['company_registration'],
            $old['company_address'],
            $old['company_phone'],
            $old['company_website_url'],
            $id
        );
        $ok = $upd->execute();
        $upd->close();
        // Optional company logo (shown top-right in company dashboard view).
        if ($ok && isset($_FILES['company_logo']) && (int) ($_FILES['company_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = pcvc_profile_photo_store($_FILES['company_logo']);
            if (!empty($stored['ok']) && !empty($stored['filename'])) {
                $logoName = (string) $stored['filename'];
                $logoUp = $conn->prepare('UPDATE admins SET company_logo = ? WHERE id = ? LIMIT 1');
                if ($logoUp) {
                    $logoUp->bind_param('si', $logoName, $id);
                    $logoUp->execute();
                    $logoUp->close();
                }
            } else {
                $_SESSION['error'] = (string) ($stored['error'] ?? 'Logo upload failed.');
                cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
            }
        }
        if ($ok && isset($_POST['remove_company_logo'])) {
            $empty = '';
            $logoUp = $conn->prepare('UPDATE admins SET company_logo = ? WHERE id = ? LIMIT 1');
            if ($logoUp) {
                $logoUp->bind_param('si', $empty, $id);
                $logoUp->execute();
                $logoUp->close();
            }
        }
        $_SESSION[$ok ? 'success' : 'error'] = $ok
            ? 'Company details saved.'
            : 'Could not save company details.';
        if ($ok && isset($_POST['save_and_dashboard'])) {
            cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id . '&enter_dashboard=1');
        }
        cm_redirect('filter=' . rawurlencode($filter) . '&view=' . $id);
    }
}

$kpis = pcvc_company_account_kpis($conn);
$companies = pcvc_list_company_accounts($conn, $filter);
$signupLink = pcvc_company_registration_url();
$viewRow = null;
if ($viewId > 0) {
    foreach ($companies as $c) {
        if ((int) ($c['id'] ?? 0) === $viewId) {
            $viewRow = $c;
            break;
        }
    }
    if (!$viewRow) {
        $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $viewId);
            $st->execute();
            $tmp = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
            if ($tmp && pcvc_admin_is_company_account($tmp)) {
                $viewRow = $tmp;
            }
        }
    }
}

$success = (string) ($_SESSION['success'] ?? '');
$error = (string) ($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Companies Management</title>
<style>
:root {
  --green:#15803d; --deep:#14532d; --sky:#0284c7; --ink:#0f172a; --muted:#64748b;
  --line:#d7e7dc; --bg:#f3f7f4; --card:#fff; --warn:#9a3412; --red:#dc2626;
}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",system-ui,sans-serif;color:var(--ink);background:radial-gradient(900px 260px at 0% -10%,rgba(34,197,94,.14),transparent 55%),linear-gradient(180deg,#eef8f1,#f8fafc 40%,#fff)}
.wrap{max-width:1100px;margin:0 auto;padding:18px 14px 40px}
.hero{background:linear-gradient(135deg,#14532d,#15803d 55%,#0284c7);color:#fff;border-radius:20px;padding:20px 18px;box-shadow:0 16px 36px rgba(20,83,45,.16);margin-bottom:14px}
.hero h1{margin:0 0 4px;font-size:clamp(1.2rem,3vw,1.55rem)}
.hero p{margin:0;opacity:.92;font-size:.95rem}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.04)}
.kpi .n{font-size:1.55rem;font-weight:800;color:var(--deep)}
.kpi .l{font-size:.82rem;color:var(--muted);font-weight:700;margin-top:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
.card h2{margin:0 0 12px;font-size:1.05rem;color:var(--deep)}
.muted{color:var(--muted);font-size:.86rem}
.actions{display:flex;flex-wrap:wrap;gap:8px}
.btn{border:0;border-radius:12px;padding:11px 14px;font-weight:750;cursor:pointer;font-size:.92rem;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn-sky{background:#0ea5e9;color:#fff}
.btn-green{background:linear-gradient(135deg,#15803d,#16a34a);color:#fff}
.btn-ghost{background:#f1f5f9;color:#0f172a}
.btn-amber{background:#f59e0b;color:#111}
.btn-red{background:var(--red);color:#fff}
.btn-sm{padding:7px 10px;font-size:.8rem;border-radius:9px}
.row-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.row-actions form{display:inline;margin:0}
.link-box{margin-top:8px;padding:12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}
.link-box input{width:100%;padding:11px 12px;border:2px solid #86efac;border-radius:10px;font-size:.9rem}
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.filters a{text-decoration:none;padding:7px 11px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:.82rem;font-weight:700}
.filters a.active{background:#14532d;color:#fff}
.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}
table{width:100%;border-collapse:collapse;min-width:860px}
th,td{padding:10px 11px;border-bottom:1px solid #eef2f0;text-align:left;font-size:.88rem;vertical-align:top}
th{background:#f0fdf4;color:#14532d;position:sticky;top:0}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.72rem;font-weight:800}
.badge.pending{background:#fff7ed;color:#9a3412}
.badge.active{background:#dcfce7;color:#14532d}
.badge.inactive{background:#f1f5f9;color:#475569}
.flash{padding:12px 14px;border-radius:12px;font-weight:700;margin-bottom:12px}
.flash.ok{background:#ecfdf5;border:1px solid #86efac;color:#14532d}
.flash.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.grid2{display:grid;grid-template-columns:1.1fr .9fr;gap:12px}
.dl{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;font-size:.9rem}
.dl dt{color:var(--muted);font-weight:700}
.dl dd{margin:0;font-weight:650}
label{display:block;font-weight:700;font-size:.82rem;color:#334155;margin:8px 0 4px}
input[type=text],input[type=email],input[type=tel],input[type=url]{width:100%;padding:10px 12px;border:2px solid #dbe7df;border-radius:10px;font-size:.92rem}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.okmsg{padding:10px 12px;border-radius:10px;background:#ecfdf5;border:1px solid #86efac;color:#14532d;font-weight:700;margin-top:10px;display:none}
.notice{padding:12px 14px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:700;margin-bottom:12px}
@media(max-width:900px){
  .kpi-grid,.grid2,.row2{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <h1>Companies Management</h1>
    <p>Approve company partner accounts, copy the company-only signup link, and manage active or inactive companies — separate from staff and individual agents.</p>
  </div>

  <?php if ($success !== ''): ?><div class="flash ok"><?= cm_h($success) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash err"><?= cm_h($error) ?></div><?php endif; ?>
  <?php if (!$isSuperAdmin): ?>
  <div class="notice">You can view company accounts. Only a superadmin can approve, edit, deactivate, or reactivate them.</div>
  <?php endif; ?>

  <div class="kpi-grid">
    <div class="kpi"><div class="n"><?= (int)$kpis['total'] ?></div><div class="l">All companies</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['pending'] ?></div><div class="l">Waiting approval</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['active'] ?></div><div class="l">Active</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['inactive'] ?></div><div class="l">Inactive</div></div>
  </div>

  <div class="card">
    <h2>Company signup link</h2>
    <p class="muted" style="margin:0 0 8px">Share this link with partner companies. It opens the registration form locked to <strong>Company</strong> (not personal agent/staff). New companies wait for superadmin approval before they can sign in.</p>
    <div class="link-box">
      <label for="companyLink">Smart company registration link</label>
      <input id="companyLink" type="text" readonly value="<?= cm_h($signupLink) ?>">
      <div class="actions" style="margin-top:10px">
        <button type="button" class="btn btn-sky" id="btnCopy">Copy link</button>
        <a class="btn btn-ghost" href="<?= cm_h($signupLink) ?>" target="_blank" rel="noopener">Open form</a>
      </div>
      <div class="okmsg" id="copyMsg">Link copied</div>
    </div>
  </div>

  <?php if ($viewRow):
    $status = strtolower(trim((string) ($viewRow['account_status'] ?? 'active')));
    if ($status === '') { $status = 'active'; }
    $contact = trim((string) ($viewRow['full_name'] ?? ''));
    if ($contact === '') {
      $contact = trim((string) (($viewRow['first_name'] ?? '') . ' ' . ($viewRow['last_name'] ?? '')));
    }
  ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <h2 style="margin:0"><?= cm_h(trim((string)($viewRow['company_name'] ?? '')) ?: 'Company #' . (int)$viewRow['id']) ?></h2>
      <a class="btn btn-ghost btn-sm" href="companies-management.php?filter=<?= rawurlencode($filter) ?>">Back to list</a>
    </div>
    <div class="grid2">
      <div>
        <dl class="dl">
          <dt>Status</dt><dd><span class="badge <?= cm_h($status) ?>"><?= cm_h(ucfirst($status)) ?></span></dd>
          <dt>Contact</dt><dd><?= cm_h($contact !== '' ? $contact : '—') ?></dd>
          <dt>Email</dt><dd><?= cm_h((string)($viewRow['email'] ?? '')) ?: '—' ?></dd>
          <dt>Phone</dt><dd><?= cm_h((string)($viewRow['phone_number'] ?? '')) ?: '—' ?></dd>
          <dt>Registration</dt><dd><?= cm_h((string)($viewRow['company_registration'] ?? '')) ?: '—' ?></dd>
          <dt>Address</dt><dd><?= cm_h((string)($viewRow['company_address'] ?? '')) ?: '—' ?></dd>
          <dt>Company phone</dt><dd><?= cm_h((string)($viewRow['company_phone'] ?? '')) ?: '—' ?></dd>
          <dt>Website</dt><dd><?php
            $web = trim((string)($viewRow['company_website_url'] ?? ''));
            if ($web === '') { echo '—'; }
            else {
              $href = preg_match('#^https?://#i', $web) ? $web : ('https://' . $web);
              echo '<a href="' . cm_h($href) . '" target="_blank" rel="noopener">' . cm_h($web) . '</a>';
            }
          ?></dd>
          <dt>Created</dt><dd><?= cm_h((string)($viewRow['created_at'] ?? '')) ?: '—' ?></dd>
        </dl>
        <?php if ($isSuperAdmin && $status === 'pending'): ?>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
          <input type="hidden" name="approve_company" value="1">
          <input type="hidden" name="id" value="<?= (int)$viewRow['id'] ?>">
          <button type="submit" class="btn btn-green" style="width:100%">Approve and email sign-in</button>
        </form>
        <?php endif; ?>
        <?php if ($isSuperAdmin && $status !== 'inactive' && (int)$viewRow['id'] !== $currentUserId): ?>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('Deactivate this company account?');">
          <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
          <input type="hidden" name="deactivate_company" value="1">
          <input type="hidden" name="id" value="<?= (int)$viewRow['id'] ?>">
          <button type="submit" class="btn btn-amber" style="width:100%">Deactivate</button>
        </form>
        <?php elseif ($isSuperAdmin && $status === 'inactive'): ?>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
          <input type="hidden" name="reactivate_company" value="1">
          <input type="hidden" name="id" value="<?= (int)$viewRow['id'] ?>">
          <button type="submit" class="btn btn-sky" style="width:100%">Reactivate</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($isSuperAdmin): ?>
      <div>
        <strong style="display:block;margin-bottom:6px;color:#14532d">Edit company details</strong>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
          <input type="hidden" name="update_company" value="1">
          <input type="hidden" name="id" value="<?= (int)$viewRow['id'] ?>">
          <label for="company_name">Company name</label>
          <input type="text" id="company_name" name="company_name" required maxlength="190" value="<?= cm_h((string)($viewRow['company_name'] ?? '')) ?>">
          <label for="company_registration">Registration number</label>
          <input type="text" id="company_registration" name="company_registration" required maxlength="80" value="<?= cm_h((string)($viewRow['company_registration'] ?? '')) ?>">
          <label for="company_address">Company address</label>
          <input type="text" id="company_address" name="company_address" required maxlength="255" value="<?= cm_h((string)($viewRow['company_address'] ?? '')) ?>">
          <div class="row2">
            <div>
              <label for="company_phone">Company phone</label>
              <input type="tel" id="company_phone" name="company_phone" required maxlength="24" value="<?= cm_h((string)($viewRow['company_phone'] ?? '')) ?>">
            </div>
            <div>
              <label for="company_website_url">Website</label>
              <input type="url" id="company_website_url" name="company_website_url" maxlength="190" value="<?= cm_h((string)($viewRow['company_website_url'] ?? '')) ?>">
            </div>
          </div>
          <div class="row2">
            <div>
              <label for="first_name">Contact first name</label>
              <input type="text" id="first_name" name="first_name" required maxlength="80" value="<?= cm_h((string)($viewRow['first_name'] ?? '')) ?>">
            </div>
            <div>
              <label for="last_name">Contact last name</label>
              <input type="text" id="last_name" name="last_name" required maxlength="80" value="<?= cm_h((string)($viewRow['last_name'] ?? '')) ?>">
            </div>
          </div>
          <label for="email">Sign-in email</label>
          <input type="email" id="email" name="email" required maxlength="190" value="<?= cm_h((string)($viewRow['email'] ?? '')) ?>">
          <label for="phone_number">Contact phone</label>
          <input type="tel" id="phone_number" name="phone_number" required maxlength="24" value="<?= cm_h((string)($viewRow['phone_number'] ?? '')) ?>">
          <label for="company_logo">Company logo (top-right corner)</label>
          <?php
            $logoFile = trim((string) ($viewRow['company_logo'] ?? ''));
            $logoUrl = $logoFile !== '' ? pcvc_company_logo_url($logoFile) : '';
          ?>
          <?php if ($logoUrl !== ''): ?>
          <div style="display:flex;align-items:center;gap:10px;margin:6px 0 8px">
            <img src="<?= cm_h($logoUrl) ?>" alt="Logo" style="width:56px;height:56px;object-fit:contain;border:1px solid #d7e7dc;border-radius:12px;background:#fff">
            <label style="font-weight:650;display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="remove_company_logo" value="1"> Remove logo</label>
          </div>
          <?php endif; ?>
          <input type="file" id="company_logo" name="company_logo" accept="image/*">
          <div class="actions" style="margin-top:12px">
            <button type="submit" class="btn btn-green" style="flex:1">Save company</button>
            <button type="submit" name="save_and_dashboard" value="1" class="btn btn-sky" style="flex:1">Save & open dashboard</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($status !== 'pending'): ?>
    <div class="actions" style="margin-top:14px">
      <button type="button" class="btn btn-sky btn-enter-company" data-id="<?= (int)$viewRow['id'] ?>" data-name="<?= cm_h(trim((string)($viewRow['company_name'] ?? '')) ?: ('Company #' . (int)$viewRow['id'])) ?>">Open company dashboard</button>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Company accounts</h2>
    <div class="filters">
      <?php
        $filterLinks = [
          'all' => 'All',
          'pending' => 'Waiting approval',
          'active' => 'Active',
          'inactive' => 'Inactive',
        ];
        foreach ($filterLinks as $fk => $fl):
      ?>
      <a class="<?= $filter === $fk ? 'active' : '' ?>" href="?filter=<?= rawurlencode($fk) ?>"><?= cm_h($fl) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Company</th>
            <th>Contact</th>
            <th>Activity</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$companies): ?>
          <tr><td colspan="6" class="muted">No company accounts yet. Share the company signup link above.</td></tr>
        <?php else: foreach ($companies as $row):
          $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
          if ($status === '') { $status = 'active'; }
          $company = trim((string) ($row['company_name'] ?? ''));
          $contact = trim((string) ($row['full_name'] ?? ''));
          if ($contact === '') {
            $contact = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
          }
          $email = trim((string) ($row['email'] ?? ''));
          $activity = pcvc_company_activity_counts($conn, (int) $row['id'], strtolower($email));
          $dashUrl = pcvc_company_dashboard_url((int) $row['id']);
        ?>
          <tr>
            <td>
              <strong><?= cm_h($company !== '' ? $company : 'Unnamed company') ?></strong>
              <?php if (trim((string)($row['company_registration'] ?? '')) !== ''): ?>
              <div class="muted"><?= cm_h((string)$row['company_registration']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= cm_h($contact !== '' ? $contact : '—') ?>
              <?php if ($email !== ''): ?><div class="muted"><?= cm_h($email) ?></div><?php endif; ?>
            </td>
            <td>
              <strong><?= (int)$activity['applications'] ?></strong> apps
              <div class="muted"><?= (int)$activity['self_regs'] ?> self-reg · <?= (int)$activity['admitted'] ?> admitted</div>
            </td>
            <td><span class="badge <?= cm_h($status) ?>"><?= cm_h(ucfirst($status)) ?></span></td>
            <td class="muted"><?= cm_h((string)($row['created_at'] ?? '')) ?></td>
            <td>
              <div class="row-actions">
                <?php if ($status === 'pending'): ?>
                  <?php if ($isSuperAdmin): ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
                    <input type="hidden" name="approve_company" value="1">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-green btn-sm">Approve</button>
                  </form>
                  <?php endif; ?>
                  <a class="btn btn-ghost btn-sm" href="?view=<?= (int)$row['id'] ?>&filter=<?= rawurlencode($filter) ?>">Review</a>
                <?php else: ?>
                  <button type="button" class="btn btn-sky btn-sm btn-enter-company" data-id="<?= (int)$row['id'] ?>" data-name="<?= cm_h($company !== '' ? $company : ('Company #' . (int)$row['id'])) ?>">Dashboard</button>
                  <a class="btn btn-ghost btn-sm" href="?view=<?= (int)$row['id'] ?>&filter=<?= rawurlencode($filter) ?>">Open</a>
                  <?php if ($isSuperAdmin && $status !== 'inactive' && (int)$row['id'] !== $currentUserId): ?>
                  <form method="post" onsubmit="return confirm('Deactivate this company account?');">
                    <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
                    <input type="hidden" name="deactivate_company" value="1">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-amber btn-sm">Deactivate</button>
                  </form>
                  <?php elseif ($isSuperAdmin && $status === 'inactive'): ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= cm_h($csrf) ?>">
                    <input type="hidden" name="reactivate_company" value="1">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-green btn-sm">Reactivate</button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function () {
  function copyText(text, done) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text); done(); });
      return;
    }
    fallback(text); done();
  }
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  var input = document.getElementById('companyLink');
  var msg = document.getElementById('copyMsg');
  document.getElementById('btnCopy')?.addEventListener('click', function () {
    if (!input || !input.value) return;
    copyText(input.value, function () {
      if (msg) { msg.style.display = 'block'; setTimeout(function () { msg.style.display = 'none'; }, 1600); }
    });
  });

  function enterCompanyDashboard(id, name) {
    if (!id) return;
    const fd = new FormData();
    fd.append('action', 'enter');
    fd.append('company_id', String(id));
    fetch('admin-company-view.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.ok) {
          alert((data && data.error) || 'Could not open company dashboard.');
          return;
        }
        const target = data.redirect || 'admin-dashboard.php?company_home=1';
        if (window.top && window.top !== window) {
          window.top.location.href = target;
        } else {
          window.location.href = target;
        }
      })
      .catch(() => alert('Could not open company dashboard.'));
  }
  document.querySelectorAll('.btn-enter-company').forEach(btn => {
    btn.addEventListener('click', function () {
      enterCompanyDashboard(this.getAttribute('data-id'), this.getAttribute('data-name') || '');
    });
  });
  <?php if (!empty($_GET['enter_dashboard']) && $viewRow): ?>
  enterCompanyDashboard(<?= (int)$viewRow['id'] ?>, <?= json_encode(trim((string)($viewRow['company_name'] ?? '')) ?: ('Company #' . (int)$viewRow['id'])) ?>);
  <?php endif; ?>
})();
</script>
</body>
</html>

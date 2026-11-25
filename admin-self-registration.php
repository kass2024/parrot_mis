<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
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

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
$isSuper = pcvc_current_user_is_superadmin($conn);
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$viewId = (int) ($_GET['view'] ?? 0);

$kpis = $isSuper ? pcvc_self_reg_kpis($conn) : null;
$assignable = $isSuper ? pcvc_self_reg_assignable_users($conn) : [];
$statuses = pcvc_self_reg_followup_statuses();
$fixedLink = pcvc_self_reg_fixed_public_url();

// Only submitted applications appear in the list.
if ($filter === 'draft' || $filter === 'submitted') {
    $filter = 'all';
}

if ($isSuper) {
    $apps = pcvc_self_reg_list_applications($conn, null, $filter, 300);
} else {
    // Staff/agents only see submitted apps transferred to them
    $apps = pcvc_self_reg_list_applications($conn, $adminId, 'all', 300);
}

$viewRow = null;
if ($viewId > 0) {
    $viewRow = pcvc_self_reg_find_by_id($conn, $viewId);
    if ($viewRow) {
        if (!$isSuper && (int) ($viewRow['assigned_to_admin_id'] ?? 0) !== $adminId) {
            $viewRow = null;
        } else {
            $assigneeId = (int) ($viewRow['assigned_to_admin_id'] ?? 0);
            $viewRow['assignee_name'] = '';
            if ($assigneeId > 0) {
                $stA = $conn->prepare('SELECT full_name, username, role FROM admins WHERE id = ? LIMIT 1');
                if ($stA) {
                    $stA->bind_param('i', $assigneeId);
                    $stA->execute();
                    $a = $stA->get_result()->fetch_assoc();
                    $stA->close();
                    if ($a) {
                        $nm = trim((string) ($a['full_name'] ?? ''));
                        if ($nm === '') {
                            $nm = trim((string) ($a['username'] ?? ''));
                        }
                        $viewRow['assignee_name'] = $nm;
                        $viewRow['assignee_role'] = (string) ($a['role'] ?? '');
                    }
                }
            }
        }
    }
}
$viewDocs = $viewRow ? pcvc_self_reg_documents_map($conn, (int) $viewRow['id']) : [];

function sr_admin_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Self Registration Dashboard</title>
<style>
:root {
  --green:#15803d; --deep:#14532d; --sky:#0284c7; --ink:#0f172a; --muted:#64748b;
  --line:#d7e7dc; --bg:#f3f7f4; --card:#fff; --warn:#9a3412;
}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",system-ui,sans-serif;color:var(--ink);background:radial-gradient(900px 260px at 0% -10%,rgba(34,197,94,.14),transparent 55%),linear-gradient(180deg,#eef8f1,#f8fafc 40%,#fff)}
.wrap{max-width:1180px;margin:0 auto;padding:18px 14px 40px}
.hero{background:linear-gradient(135deg,#14532d,#15803d 55%,#0284c7);color:#fff;border-radius:20px;padding:20px 18px;box-shadow:0 16px 36px rgba(20,83,45,.16);margin-bottom:14px}
.hero h1{margin:0 0 4px;font-size:clamp(1.2rem,3vw,1.55rem)}
.hero p{margin:0;opacity:.92;font-size:.95rem}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.04)}
.kpi .n{font-size:1.55rem;font-weight:800;color:var(--deep)}
.kpi .l{font-size:.82rem;color:var(--muted);font-weight:700;margin-top:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
.card h2{margin:0 0 12px;font-size:1.05rem;color:var(--deep)}
.actions{display:flex;flex-wrap:wrap;gap:8px}
.btn{border:0;border-radius:12px;padding:11px 14px;font-weight:750;cursor:pointer;font-size:.92rem}
.btn-green{background:linear-gradient(135deg,#15803d,#16a34a);color:#fff}
.btn-sky{background:#0ea5e9;color:#fff}
.btn-amber{background:#f59e0b;color:#111}
.btn-ghost{background:#f1f5f9;color:#0f172a}
.btn-sm{padding:7px 10px;font-size:.8rem;border-radius:9px}
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.filters a{text-decoration:none;padding:7px 11px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:.82rem;font-weight:700}
.filters a.active{background:#14532d;color:#fff}
.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}
table{width:100%;border-collapse:collapse;min-width:980px}
th,td{padding:10px 11px;border-bottom:1px solid #eef2f0;text-align:left;font-size:.88rem;vertical-align:top}
th{background:#f0fdf4;color:#14532d;position:sticky;top:0}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.72rem;font-weight:800}
.badge.draft{background:#fff7ed;color:#9a3412}
.badge.submitted{background:#dcfce7;color:#14532d}
.badge.fu{background:#e0f2fe;color:#075985}
.muted{color:var(--muted);font-size:.8rem}
.link-box{margin-top:12px;padding:12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}
.link-box input{width:100%;padding:11px 12px;border:2px solid #86efac;border-radius:10px;font-size:.9rem}
.manual-panel{display:none;margin-top:12px;border:1px solid #bbf7d0;border-radius:14px;overflow:hidden;background:#fff}
.manual-panel.open{display:block}
.manual-panel .bar{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 12px;background:#ecfdf5;border-bottom:1px solid #bbf7d0}
.manual-panel iframe{width:100%;height:min(78vh,820px);border:0;background:#fff}
.detail-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}
.dl{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;font-size:.9rem}
.dl dt{color:var(--muted);font-weight:700}
.dl dd{margin:0;font-weight:650}
.select,textarea,input[type=text]{width:100%;padding:10px 12px;border:2px solid #dbe7df;border-radius:10px;font-size:.92rem}
textarea{min-height:70px;resize:vertical}
.notice{padding:12px 14px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:700;margin-bottom:12px}
.okmsg{padding:10px 12px;border-radius:10px;background:#ecfdf5;border:1px solid #86efac;color:#14532d;font-weight:700;margin-top:10px;display:none}
.btn-red{background:#dc2626;color:#fff}
.btn-amber{background:#f59e0b;color:#111}
.row-actions{display:flex;flex-wrap:wrap;gap:6px}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:80;display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex}
.modal{width:min(440px,100%);background:#fff;border-radius:16px;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.2)}
.modal h3{margin:0 0 10px;color:#14532d;font-size:1.05rem}
.modal .actions{margin-top:12px;justify-content:flex-end}
@media(max-width:900px){
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .detail-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <h1>Self Registration Dashboard</h1>
    <p><?= $isSuper
      ? 'Superadmin view — copy the fixed student link, add applications manually, transfer to staff/agents, and track submitted follow-ups.'
      : 'Your assigned self-registration follow-ups. Mark status as you work each file.' ?></p>
  </div>

  <?php if ($isSuper && $kpis): ?>
  <div class="kpi-grid">
    <div class="kpi"><div class="n"><?= (int)$kpis['submitted'] ?></div><div class="l">Submitted applications</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['unassigned'] ?></div><div class="l">Unassigned</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['assigned'] ?></div><div class="l">Transferred</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['in_progress'] ?></div><div class="l">In progress</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['waiting_documents'] ?></div><div class="l">Waiting documents</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['closed'] ?></div><div class="l">Closed / cancelled</div></div>
  </div>
  <?php endif; ?>

  <?php if ($isSuper): ?>
  <div class="card">
    <h2>Student registration link</h2>
    <p class="muted" style="margin:0 0 12px">This link is always the same. Copy and send it to students after payment. Incomplete drafts never appear in the applications list — only submitted forms do.</p>
    <div class="link-box" id="linkBox">
      <label for="srLink" style="display:block;font-weight:750;margin-bottom:6px;color:#14532d">Fixed link</label>
      <input id="srLink" type="text" readonly value="<?= sr_admin_h($fixedLink) ?>">
      <div class="actions" style="margin-top:10px">
        <button type="button" class="btn btn-sky" id="btnCopy">Copy link</button>
        <a class="btn btn-ghost" id="btnOpenLink" href="<?= sr_admin_h($fixedLink) ?>" target="_blank" rel="noopener">Open form</a>
        <button type="button" class="btn btn-amber" id="btnManual">Add manually (same form)</button>
      </div>
      <div class="okmsg" id="copyMsg">Link copied</div>
    </div>
    <div class="manual-panel" id="manualPanel">
      <div class="bar">
        <strong>Manual entry — same student form</strong>
        <button type="button" class="btn btn-ghost btn-sm" id="btnCloseManual">Close</button>
      </div>
      <iframe id="manualFrame" title="Manual self registration form" src="about:blank"></iframe>
    </div>
  </div>
  <?php else: ?>
  <div class="notice">You can only view and update applications transferred to you by a superadmin.</div>
  <?php endif; ?>

  <?php if ($viewRow): ?>
  <div class="card" id="detailCard">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <h2 style="margin:0">Application #<?= (int)$viewRow['id'] ?> — <?= sr_admin_h(trim((string)($viewRow['full_name'] ?? '')) ?: 'Not filled yet') ?></h2>
      <a class="btn btn-ghost btn-sm" href="admin-self-registration.php<?= $filter !== 'all' ? ('?filter=' . rawurlencode($filter)) : '' ?>">Back to list</a>
    </div>
    <div class="detail-grid">
      <div>
        <dl class="dl">
          <dt>Form status</dt><dd><span class="badge <?= ($viewRow['status'] ?? '') === 'submitted' ? 'submitted' : 'draft' ?>"><?= sr_admin_h((string)$viewRow['status']) ?></span></dd>
          <dt>Follow-up</dt><dd><span class="badge fu"><?= sr_admin_h($statuses[(string)($viewRow['followup_status'] ?? 'new')] ?? (string)$viewRow['followup_status']) ?></span></dd>
          <dt>Source</dt><dd><?= sr_admin_h((string)($viewRow['source'] ?? 'link')) ?></dd>
          <dt>Email</dt><dd><?= sr_admin_h((string)($viewRow['email'] ?? '')) ?: '—' ?></dd>
          <dt>WhatsApp</dt><dd><?= sr_admin_h((string)($viewRow['whatsapp'] ?? '')) ?: '—' ?></dd>
          <dt>Nationality</dt><dd><?= sr_admin_h((string)($viewRow['nationality'] ?? '')) ?: '—' ?></dd>
          <dt>Residence</dt><dd><?= sr_admin_h((string)($viewRow['country_of_residence'] ?? '')) ?: '—' ?></dd>
          <dt>Destination</dt><dd><?= sr_admin_h((string)($viewRow['destination_country'] ?? '')) ?: '—' ?></dd>
          <dt>Level</dt><dd><?= sr_admin_h((string)($viewRow['preferred_level'] ?? '')) ?: '—' ?></dd>
          <dt>Intake</dt><dd><?= sr_admin_h((string)($viewRow['preferred_intake'] ?? '')) ?: '—' ?></dd>
          <dt>Fields</dt>
          <dd><?php
            $chs = array_filter([
              $viewRow['field_choice_1'] ?? '',
              $viewRow['field_choice_2'] ?? '',
              $viewRow['field_choice_3'] ?? '',
              $viewRow['field_choice_4'] ?? '',
              $viewRow['field_choice_5'] ?? '',
            ]);
            echo $chs ? sr_admin_h(implode(' · ', $chs)) : '—';
          ?></dd>
          <dt>Sponsor</dt><dd><?= sr_admin_h((string)($viewRow['sponsor'] ?? '')) ?: '—' ?></dd>
          <dt>Assignee</dt><dd><?= sr_admin_h(trim((string)($viewRow['assignee_name'] ?? '')) ?: 'Unassigned') ?></dd>
        </dl>
        <p style="margin:12px 0 6px;font-weight:750;color:#14532d">Documents</p>
        <ul style="margin:0;padding-left:18px">
          <?php if (!$viewDocs): ?>
            <li class="muted">No documents uploaded yet</li>
          <?php else: foreach ($viewDocs as $k => $d): ?>
            <li><strong><?= sr_admin_h($k) ?></strong> — <?= sr_admin_h((string)($d['original_name'] ?? '')) ?></li>
          <?php endforeach; endif; ?>
        </ul>
        <?php if (!empty($viewRow['followup_notes'])): ?>
        <p style="margin:12px 0 6px;font-weight:750;color:#14532d">Notes</p>
        <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;font-size:.85rem"><?= sr_admin_h((string)$viewRow['followup_notes']) ?></pre>
        <?php endif; ?>
        <p style="margin-top:12px"><a class="btn btn-sky btn-sm" href="<?= sr_admin_h(pcvc_self_reg_public_url((string)$viewRow['token'])) ?>" target="_blank" rel="noopener">Open student form</a></p>
      </div>
      <div>
        <?php if ($isSuper): ?>
        <div style="margin-bottom:14px;padding:12px;border:1px solid #bbf7d0;border-radius:12px;background:#f0fdf4">
          <strong style="display:block;margin-bottom:8px;color:#14532d">Transfer to staff / agent</strong>
          <label class="muted">Assignee</label>
          <select class="select" id="transferUser">
            <option value="">Select staff or agent…</option>
            <?php foreach ($assignable as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= ((int)($viewRow['assigned_to_admin_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
              <?= sr_admin_h($u['full_name'] . ' (' . $u['role'] . ')') ?>
            </option>
            <?php endforeach; ?>
          </select>
          <label class="muted" style="display:block;margin-top:8px">Note (optional)</label>
          <textarea id="transferNote" placeholder="Why / what to follow up…"></textarea>
          <button type="button" class="btn btn-green" style="margin-top:8px;width:100%" id="btnTransfer" data-id="<?= (int)$viewRow['id'] ?>">Transfer for follow-up</button>
          <button type="button" class="btn btn-red" style="margin-top:8px;width:100%" id="btnDeleteDetail" data-id="<?= (int)$viewRow['id'] ?>" data-label="<?= sr_admin_h(trim((string)($viewRow['full_name'] ?? '')) ?: ('Application #' . (int)$viewRow['id'])) ?>">Delete application</button>
        </div>
        <?php endif; ?>
        <div style="padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc">
          <strong style="display:block;margin-bottom:8px;color:#14532d">Mark follow-up status</strong>
          <select class="select" id="statusSelect">
            <?php foreach ($statuses as $k => $lab): ?>
            <option value="<?= sr_admin_h($k) ?>" <?= ((string)($viewRow['followup_status'] ?? '') === $k) ? 'selected' : '' ?>><?= sr_admin_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="muted" style="display:block;margin-top:8px">Note (optional)</label>
          <textarea id="statusNote" placeholder="Update note…"></textarea>
          <button type="button" class="btn btn-sky" style="margin-top:8px;width:100%" id="btnStatus" data-id="<?= (int)$viewRow['id'] ?>">Save status</button>
          <div class="okmsg" id="actionMsg"></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= $isSuper ? 'Submitted applications' : 'My assigned applications' ?></h2>
    <?php if ($isSuper): ?>
    <div class="filters">
      <?php
        $filterLinks = [
          'all' => 'All submitted',
          'unassigned' => 'Unassigned',
          'assigned' => 'Transferred',
          'in_progress' => 'In progress',
          'waiting_documents' => 'Waiting docs',
          'closed' => 'Closed',
        ];
        foreach ($filterLinks as $fk => $fl):
          $active = ($filter === $fk);
      ?>
      <a class="<?= $active ? 'active' : '' ?>" href="?filter=<?= rawurlencode($fk) ?>"><?= sr_admin_h($fl) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Form</th>
            <th>Follow-up</th>
            <th>Destination / Intake</th>
            <th>Assignee</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$apps): ?>
          <tr><td colspan="7" class="muted"><?= $isSuper ? 'No applications yet.' : 'No applications transferred to you yet.' ?></td></tr>
        <?php else: foreach ($apps as $row):
          $name = trim((string)($row['full_name'] ?? ''));
          $email = trim((string)($row['email'] ?? ''));
          $label = $name !== '' ? $name : ($email !== '' ? $email : 'Not filled yet');
          $fu = (string)($row['followup_status'] ?? 'new');
          $assignee = trim((string)($row['assignee_name'] ?? ''));
        ?>
          <tr>
            <td>
              <strong><?= sr_admin_h($label) ?></strong>
              <?php if ($email !== '' && $name !== ''): ?><div class="muted"><?= sr_admin_h($email) ?></div><?php endif; ?>
              <div class="muted"><?= sr_admin_h((string)($row['source'] ?? 'link')) ?></div>
            </td>
            <td><span class="badge <?= ($row['status'] ?? '') === 'submitted' ? 'submitted' : 'draft' ?>"><?= sr_admin_h((string)$row['status']) ?></span></td>
            <td><span class="badge fu"><?= sr_admin_h($statuses[$fu] ?? $fu) ?></span></td>
            <td>
              <?= sr_admin_h(trim((string)($row['destination_country'] ?? '')) ?: '—') ?>
              <div class="muted"><?= sr_admin_h((string)($row['preferred_intake'] ?? '')) ?></div>
            </td>
            <td><?= sr_admin_h($assignee !== '' ? $assignee : 'Unassigned') ?></td>
            <td class="muted"><?= sr_admin_h((string)($row['created_at'] ?? '')) ?></td>
            <td>
              <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="?view=<?= (int)$row['id'] ?>&filter=<?= rawurlencode($filter) ?>">Open</a>
                <?php if ($isSuper): ?>
                <button type="button" class="btn btn-sky btn-sm copy-row" data-link="<?= sr_admin_h($fixedLink) ?>">Copy link</button>
                <button
                  type="button"
                  class="btn btn-amber btn-sm btn-transfer-row"
                  data-id="<?= (int)$row['id'] ?>"
                  data-label="<?= sr_admin_h($label) ?>"
                  data-assigned="<?= (int)($row['assigned_to_admin_id'] ?? 0) ?>"
                >Transfer</button>
                <button
                  type="button"
                  class="btn btn-red btn-sm btn-delete-row"
                  data-id="<?= (int)$row['id'] ?>"
                  data-label="<?= sr_admin_h($label) ?>"
                >Delete</button>
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

<?php if ($isSuper): ?>
<div class="modal-backdrop" id="transferModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-labelledby="transferModalTitle">
    <h3 id="transferModalTitle">Transfer application</h3>
    <p class="muted" id="transferModalLabel" style="margin:0 0 10px"></p>
    <input type="hidden" id="transferModalId" value="">
    <label class="muted">Staff / agent</label>
    <select class="select" id="transferModalUser">
      <option value="">Select staff or agent…</option>
      <?php foreach ($assignable as $u): ?>
      <option value="<?= (int)$u['id'] ?>"><?= sr_admin_h($u['full_name'] . ' (' . $u['role'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
    <label class="muted" style="display:block;margin-top:8px">Note (optional)</label>
    <textarea id="transferModalNote" placeholder="Why / what to follow up…"></textarea>
    <div class="actions">
      <button type="button" class="btn btn-ghost" id="transferModalCancel">Cancel</button>
      <button type="button" class="btn btn-green" id="transferModalSave">Transfer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    return fetch('admin_self_registration_api.php', { method: 'POST', body: fd }).then(r => r.json());
  }
  function copyText(text, done) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => { fallback(text); done(); });
      return;
    }
    fallback(text); done();
  }
  function fallback(text) {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  function deleteApplication(id, label, btn) {
    if (!id) return;
    if (!confirm('Delete application "' + label + '"?\n\nThis permanently removes the form and uploaded documents.')) {
      return;
    }
    if (btn) btn.disabled = true;
    post('delete', { id: id }).then(data => {
      if (btn) btn.disabled = false;
      if (!data || !data.ok) {
        alert((data && data.error) || 'Delete failed');
        return;
      }
      location.href = 'admin-self-registration.php';
    }).catch(() => {
      if (btn) btn.disabled = false;
      alert('Delete failed');
    });
  }
  const transferModal = document.getElementById('transferModal');
  const transferModalId = document.getElementById('transferModalId');
  const transferModalLabel = document.getElementById('transferModalLabel');
  const transferModalUser = document.getElementById('transferModalUser');
  const transferModalNote = document.getElementById('transferModalNote');
  function openTransferModal(id, label, assigned) {
    if (!transferModal) return;
    if (transferModalId) transferModalId.value = id;
    if (transferModalLabel) transferModalLabel.textContent = label || ('Application #' + id);
    if (transferModalUser) transferModalUser.value = assigned ? String(assigned) : '';
    if (transferModalNote) transferModalNote.value = '';
    transferModal.classList.add('open');
    transferModal.setAttribute('aria-hidden', 'false');
  }
  function closeTransferModal() {
    if (!transferModal) return;
    transferModal.classList.remove('open');
    transferModal.setAttribute('aria-hidden', 'true');
  }

  const linkBox = document.getElementById('linkBox');
  const srLink = document.getElementById('srLink');
  const btnOpen = document.getElementById('btnOpenLink');
  const copyMsg = document.getElementById('copyMsg');
  const manualPanel = document.getElementById('manualPanel');
  const manualFrame = document.getElementById('manualFrame');

  document.getElementById('btnManual')?.addEventListener('click', function () {
    const manualUrl = <?= json_encode($fixedLink . '?manual=1', JSON_UNESCAPED_SLASHES) ?>;
    if (manualFrame) manualFrame.src = manualUrl + '&t=' + Date.now();
    if (manualPanel) manualPanel.classList.add('open');
    manualPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.getElementById('btnCloseManual')?.addEventListener('click', function () {
    if (manualPanel) manualPanel.classList.remove('open');
    if (manualFrame) manualFrame.src = 'about:blank';
  });

  document.getElementById('btnCopy')?.addEventListener('click', function () {
    if (!srLink || !srLink.value) return;
    copyText(srLink.value, function () {
      if (copyMsg) { copyMsg.style.display = 'block'; setTimeout(() => copyMsg.style.display = 'none', 1600); }
    });
  });

  document.querySelectorAll('.copy-row').forEach(btn => {
    btn.addEventListener('click', function () {
      const link = btn.getAttribute('data-link') || '';
      copyText(link, function () {
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = 'Copy link'; }, 1200);
      });
    });
  });

  const actionMsg = document.getElementById('actionMsg');
  function showAction(msg, ok) {
    if (!actionMsg) return;
    actionMsg.style.display = 'block';
    actionMsg.style.background = ok ? '#ecfdf5' : '#fef2f2';
    actionMsg.style.borderColor = ok ? '#86efac' : '#fecaca';
    actionMsg.style.color = ok ? '#14532d' : '#991b1b';
    actionMsg.textContent = msg;
  }

  document.getElementById('btnTransfer')?.addEventListener('click', function () {
    const id = this.getAttribute('data-id');
    const assigned = document.getElementById('transferUser')?.value || '';
    const notes = document.getElementById('transferNote')?.value || '';
    if (!assigned) { alert('Select a staff or agent'); return; }
    this.disabled = true;
    post('transfer', { id, assigned_to_admin_id: assigned, notes }).then(data => {
      this.disabled = false;
      if (!data || !data.ok) { showAction((data && data.error) || 'Transfer failed', false); return; }
      showAction('Transferred. Reloading…', true);
      setTimeout(() => location.reload(), 700);
    }).catch(() => { this.disabled = false; showAction('Transfer failed', false); });
  });

  document.getElementById('btnStatus')?.addEventListener('click', function () {
    const id = this.getAttribute('data-id');
    const followup_status = document.getElementById('statusSelect')?.value || '';
    const notes = document.getElementById('statusNote')?.value || '';
    this.disabled = true;
    post('set_status', { id, followup_status, notes }).then(data => {
      this.disabled = false;
      if (!data || !data.ok) { showAction((data && data.error) || 'Update failed', false); return; }
      showAction('Status saved. Reloading…', true);
      setTimeout(() => location.reload(), 700);
    }).catch(() => { this.disabled = false; showAction('Update failed', false); });
  });

  document.querySelectorAll('.btn-transfer-row').forEach(btn => {
    btn.addEventListener('click', function () {
      openTransferModal(
        this.getAttribute('data-id') || '',
        this.getAttribute('data-label') || '',
        this.getAttribute('data-assigned') || ''
      );
    });
  });

  document.getElementById('transferModalCancel')?.addEventListener('click', closeTransferModal);
  transferModal?.addEventListener('click', function (e) {
    if (e.target === transferModal) closeTransferModal();
  });

  document.getElementById('transferModalSave')?.addEventListener('click', function () {
    const id = transferModalId?.value || '';
    const assigned = transferModalUser?.value || '';
    const notes = transferModalNote?.value || '';
    if (!id) return;
    if (!assigned) { alert('Select a staff or agent'); return; }
    this.disabled = true;
    post('transfer', { id, assigned_to_admin_id: assigned, notes }).then(data => {
      this.disabled = false;
      if (!data || !data.ok) { alert((data && data.error) || 'Transfer failed'); return; }
      closeTransferModal();
      location.reload();
    }).catch(() => { this.disabled = false; alert('Transfer failed'); });
  });

  document.querySelectorAll('.btn-delete-row').forEach(btn => {
    btn.addEventListener('click', function () {
      deleteApplication(this.getAttribute('data-id') || '', this.getAttribute('data-label') || 'this application', this);
    });
  });

  document.getElementById('btnDeleteDetail')?.addEventListener('click', function () {
    deleteApplication(this.getAttribute('data-id') || '', this.getAttribute('data-label') || 'this application', this);
  });
})();
</script>
</body>
</html>

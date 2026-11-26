<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/self_registration.php';
require_once __DIR__ . '/helpers/company_view_as.php';

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
$companyCtx = pcvc_effective_company_context($conn);
$dataAdminId = pcvc_effective_data_admin_id($conn);
// While switched into a company, never use HQ-wide superadmin lists.
$isSuper = pcvc_current_user_is_superadmin($conn) && !pcvc_company_view_limits_data();
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$viewId = (int) ($_GET['view'] ?? 0);

$kpis = $isSuper ? pcvc_self_reg_kpis($conn) : pcvc_self_reg_kpis($conn, $dataAdminId);
$assignable = $isSuper ? pcvc_self_reg_assignable_users($conn) : [];
$statuses = pcvc_self_reg_followup_statuses();
$fixedLink = pcvc_self_reg_fixed_public_url();
// Company view uses that company's referral link; otherwise the signed-in account.
$linkAdminId = $dataAdminId > 0 ? $dataAdminId : $adminId;
$shareLink = $linkAdminId > 0 ? pcvc_self_reg_referral_url($linkAdminId) : $fixedLink;
$issuerLabel = $linkAdminId > 0 ? pcvc_self_reg_admin_label($conn, $linkAdminId) : '';
$manualLink = $shareLink . (str_contains($shareLink, '?') ? '&' : '?') . 'manual=1';

// Only submitted applications appear in the list.
if ($filter === 'draft' || $filter === 'submitted') {
    $filter = 'all';
}

if ($isSuper) {
    $apps = pcvc_self_reg_list_applications($conn, null, $filter, 300);
} else {
    // Staff / agents / companies: apps they referred or that were transferred to them
    $apps = pcvc_self_reg_list_applications($conn, $dataAdminId, $filter, 300, $dataAdminId);
}

$viewRow = null;
if ($viewId > 0) {
    $viewRow = pcvc_self_reg_find_by_id($conn, $viewId);
    if ($viewRow) {
        if (!pcvc_self_reg_can_access_row($viewRow, $dataAdminId, $isSuper)) {
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
$docSlots = pcvc_self_reg_document_slots();

function sr_admin_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function sr_admin_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / 1048576, 1) . ' MB';
}

function sr_admin_doc_kind(string $name, string $mime = ''): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' && $mime !== '') {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return 'image';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }

    return 'file';
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
.btn{border:0;border-radius:12px;padding:11px 14px;font-weight:750;cursor:pointer;font-size:.92rem;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
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
.detail-card{padding:0;overflow:hidden}
.detail-hero{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start;padding:18px 18px 16px;background:linear-gradient(135deg,#ecfdf5 0%,#f0f9ff 55%,#fff 100%);border-bottom:1px solid var(--line)}
.detail-hero h2{margin:0 0 6px;font-size:clamp(1.1rem,2.6vw,1.35rem);color:var(--deep)}
.detail-hero .meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.detail-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.75fr);gap:0}
.detail-main{padding:16px 18px 20px;border-right:1px solid var(--line)}
.detail-side{padding:16px 18px 20px;background:#f8fafc}
.section-title{margin:0 0 10px;font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:#64748b;font-weight:800}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:18px}
.info-tile{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px}
.info-tile .k{font-size:.72rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b;margin-bottom:4px}
.info-tile .v{font-size:.92rem;font-weight:650;color:#0f172a;word-break:break-word}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.doc-card{display:flex;flex-direction:column;gap:10px;background:#fff;border:1px solid #dbe7df;border-radius:14px;padding:12px;box-shadow:0 6px 16px rgba(15,23,42,.03);min-height:128px}
.doc-card .top{display:flex;gap:10px;align-items:flex-start}
.doc-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:900;letter-spacing:.02em;flex:0 0 auto}
.doc-icon.pdf{background:#fee2e2;color:#b91c1c}
.doc-icon.image{background:#dbeafe;color:#1d4ed8}
.doc-icon.file{background:#e2e8f0;color:#334155}
.doc-card .label{font-size:.78rem;font-weight:800;color:#14532d;margin:0 0 2px}
.doc-card .fname{font-size:.82rem;font-weight:650;color:#0f172a;line-height:1.35;word-break:break-word}
.doc-card .size{font-size:.72rem;color:#64748b;margin-top:2px}
.doc-actions{display:flex;gap:6px;margin-top:auto}
.doc-actions .btn{flex:1}
.doc-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;font-weight:650}
.notes-box{white-space:pre-wrap;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px;font-size:.85rem;margin:0}
.side-panel{margin-bottom:12px;padding:14px;border:1px solid #bbf7d0;border-radius:14px;background:#fff}
.side-panel.status{border-color:#e2e8f0;background:#fff}
.side-panel strong{display:block;margin-bottom:8px;color:#14532d}
.select,textarea,input[type=text],input[type=search]{width:100%;padding:10px 12px;border:2px solid #dbe7df;border-radius:10px;font-size:.92rem}
textarea{min-height:70px;resize:vertical}
.notice{padding:12px 14px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:700;margin-bottom:12px}
.okmsg{padding:10px 12px;border-radius:10px;background:#ecfdf5;border:1px solid #86efac;color:#14532d;font-weight:700;margin-top:10px;display:none}
.btn-red{background:#dc2626;color:#fff}
.row-actions{display:flex;flex-wrap:wrap;gap:6px}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:80;display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex}
.modal{width:min(460px,100%);background:#fff;border-radius:16px;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.2)}
.modal.wide{width:min(920px,100%);padding:0;overflow:hidden;max-height:min(92vh,900px);display:flex;flex-direction:column}
.modal h3{margin:0 0 10px;color:#14532d;font-size:1.05rem}
.modal .actions{margin-top:12px;justify-content:flex-end}
.ss{position:relative}
.ss-input-wrap{position:relative}
.ss-input-wrap input{padding-right:36px}
.ss-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#64748b;font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:8px}
.ss-clear:hover{background:#f1f5f9;color:#0f172a}
.ss-list{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:240px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 16px 36px rgba(15,23,42,.14);z-index:20;padding:6px}
.ss-list.open{display:block}
.ss-option{display:flex;justify-content:space-between;gap:8px;align-items:center;width:100%;border:0;background:transparent;text-align:left;padding:9px 10px;border-radius:9px;cursor:pointer;font-size:.9rem}
.ss-option:hover,.ss-option.active{background:#ecfdf5}
.ss-option .name{font-weight:700;color:#0f172a}
.ss-option .role{font-size:.75rem;font-weight:750;color:#047857;background:#d1fae5;padding:2px 7px;border-radius:999px;text-transform:capitalize}
.ss-empty{padding:12px;color:#64748b;font-size:.85rem;font-weight:650}
.preview-head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.preview-head h3{margin:0;font-size:1rem}
.preview-body{flex:1;min-height:360px;background:#0f172a}
.preview-body iframe,.preview-body img{width:100%;height:min(72vh,760px);border:0;display:block;background:#0f172a;object-fit:contain}
.preview-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #e2e8f0}
@media(max-width:900px){
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .detail-grid{grid-template-columns:1fr}
  .detail-main{border-right:0;border-bottom:1px solid var(--line)}
  .info-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <h1>Self Registration Dashboard</h1>
    <p><?= $isSuper
      ? 'Copy your student link, add applications manually, transfer to staff/agents, and track submitted follow-ups. Your link auto-fills your name as the referrer.'
      : 'Copy your personal student link (it auto-fills your name as the referring agent/staff/company), add applications manually, and track submitted follow-ups from your link or transfers.' ?></p>
  </div>

  <?php if ($kpis): ?>
  <div class="kpi-grid">
    <div class="kpi"><div class="n"><?= (int)$kpis['submitted'] ?></div><div class="l">Submitted applications</div></div>
    <?php if ($isSuper): ?>
    <div class="kpi"><div class="n"><?= (int)$kpis['unassigned'] ?></div><div class="l">Unassigned</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['assigned'] ?></div><div class="l">Transferred</div></div>
    <?php else: ?>
    <div class="kpi"><div class="n"><?= (int)$kpis['assigned'] ?></div><div class="l">Assigned to me</div></div>
    <?php endif; ?>
    <div class="kpi"><div class="n"><?= (int)$kpis['in_progress'] ?></div><div class="l">In progress</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['waiting_documents'] ?></div><div class="l">Waiting documents</div></div>
    <div class="kpi"><div class="n"><?= (int)$kpis['closed'] ?></div><div class="l">Closed / cancelled</div></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Student registration link</h2>
    <p class="muted" style="margin:0 0 12px">
      This link is always the same for your account.
      <?php if ($issuerLabel !== ''): ?>
      Students who open it will see <strong><?= sr_admin_h($issuerLabel) ?></strong> filled in as the referring agent or staff member.
      <?php endif; ?>
      Incomplete drafts never appear in the applications list — only submitted forms do.
    </p>
    <div class="link-box" id="linkBox">
      <label for="srLink" style="display:block;font-weight:750;margin-bottom:6px;color:#14532d">Your registration link</label>
      <input id="srLink" type="text" readonly value="<?= sr_admin_h($shareLink) ?>">
      <div class="actions" style="margin-top:10px">
        <button type="button" class="btn btn-sky" id="btnCopy">Copy link</button>
        <a class="btn btn-ghost" id="btnOpenLink" href="<?= sr_admin_h($shareLink) ?>" target="_blank" rel="noopener">Open form</a>
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

  <?php if ($viewRow):
    $viewName = trim((string)($viewRow['full_name'] ?? '')) ?: 'Not filled yet';
    $viewEmail = trim((string)($viewRow['email'] ?? ''));
    $viewFields = array_values(array_filter([
      $viewRow['field_choice_1'] ?? '',
      $viewRow['field_choice_2'] ?? '',
      $viewRow['field_choice_3'] ?? '',
      $viewRow['field_choice_4'] ?? '',
      $viewRow['field_choice_5'] ?? '',
    ]));
    $infoTiles = [
      ['Email', $viewEmail !== '' ? $viewEmail : '—'],
      ['WhatsApp', trim((string)($viewRow['whatsapp'] ?? '')) ?: '—'],
      ['Nationality', trim((string)($viewRow['nationality'] ?? '')) ?: '—'],
      ['Residence', trim((string)($viewRow['country_of_residence'] ?? '')) ?: '—'],
      ['Destination', trim((string)($viewRow['destination_country'] ?? '')) ?: '—'],
      ['Level', trim((string)($viewRow['preferred_level'] ?? '')) ?: '—'],
      ['Intake', trim((string)($viewRow['preferred_intake'] ?? '')) ?: '—'],
      ['Fields', $viewFields ? implode(' · ', $viewFields) : '—'],
      ['Sponsor', trim((string)($viewRow['sponsor'] ?? '')) ?: '—'],
      ['Referring', trim((string)($viewRow['referring_agent'] ?? '')) ?: '—'],
      ['Assignee', trim((string)($viewRow['assignee_name'] ?? '')) ?: 'Unassigned'],
      ['Source', trim((string)($viewRow['source'] ?? 'link')) ?: '—'],
    ];
    $orderedDocs = [];
    foreach ($docSlots as $slotKey => $_slot) {
      if (isset($viewDocs[$slotKey])) {
        $orderedDocs[$slotKey] = $viewDocs[$slotKey];
      }
    }
    foreach ($viewDocs as $k => $d) {
      if (!isset($orderedDocs[$k])) {
        $orderedDocs[$k] = $d;
      }
    }
  ?>
  <div class="card detail-card" id="detailCard">
    <div class="detail-hero">
      <div>
        <h2>Application #<?= (int)$viewRow['id'] ?> — <?= sr_admin_h($viewName) ?></h2>
        <div class="meta">
          <span class="badge <?= ($viewRow['status'] ?? '') === 'submitted' ? 'submitted' : 'draft' ?>"><?= sr_admin_h((string)$viewRow['status']) ?></span>
          <span class="badge fu"><?= sr_admin_h($statuses[(string)($viewRow['followup_status'] ?? 'new')] ?? (string)$viewRow['followup_status']) ?></span>
          <?php if ($viewEmail !== ''): ?><span class="muted"><?= sr_admin_h($viewEmail) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="actions">
        <a class="btn btn-sky btn-sm" href="<?= sr_admin_h(pcvc_self_reg_public_url((string)$viewRow['token'])) ?>" target="_blank" rel="noopener">Open student form</a>
        <a class="btn btn-ghost btn-sm" href="admin-self-registration.php<?= $filter !== 'all' ? ('?filter=' . rawurlencode($filter)) : '' ?>">Back to list</a>
      </div>
    </div>
    <div class="detail-grid">
      <div class="detail-main">
        <p class="section-title">Applicant details</p>
        <div class="info-grid">
          <?php foreach ($infoTiles as [$k, $v]): ?>
          <div class="info-tile"><div class="k"><?= sr_admin_h($k) ?></div><div class="v"><?= sr_admin_h($v) ?></div></div>
          <?php endforeach; ?>
        </div>

        <p class="section-title">Documents</p>
        <?php if (!$orderedDocs): ?>
          <div class="doc-empty">No documents uploaded yet.</div>
        <?php else: ?>
        <div class="docs-grid">
          <?php foreach ($orderedDocs as $docKey => $doc):
            $origName = (string)($doc['original_name'] ?? 'file');
            $mime = (string)($doc['mime'] ?? '');
            $kind = sr_admin_doc_kind($origName, $mime);
            $label = (string)(($docSlots[$docKey]['label'] ?? '') ?: ucwords(str_replace('_', ' ', $docKey)));
            $size = (int)($doc['size_bytes'] ?? 0);
            $viewUrl = 'admin_self_registration_doc.php?id=' . (int)$viewRow['id'] . '&doc=' . rawurlencode($docKey) . '&inline=1';
            $dlUrl = 'admin_self_registration_doc.php?id=' . (int)$viewRow['id'] . '&doc=' . rawurlencode($docKey);
            $canPreview = in_array($kind, ['pdf', 'image'], true);
          ?>
          <div class="doc-card">
            <div class="top">
              <div class="doc-icon <?= sr_admin_h($kind) ?>"><?= $kind === 'pdf' ? 'PDF' : ($kind === 'image' ? 'IMG' : 'DOC') ?></div>
              <div>
                <div class="label"><?= sr_admin_h($label) ?></div>
                <div class="fname"><?= sr_admin_h($origName) ?></div>
                <?php if ($size > 0): ?><div class="size"><?= sr_admin_h(sr_admin_bytes($size)) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="doc-actions">
              <?php if ($canPreview): ?>
              <button type="button" class="btn btn-sky btn-sm btn-preview-doc" data-url="<?= sr_admin_h($viewUrl) ?>" data-download="<?= sr_admin_h($dlUrl) ?>" data-title="<?= sr_admin_h($label . ' — ' . $origName) ?>" data-kind="<?= sr_admin_h($kind) ?>">View</button>
              <?php else: ?>
              <a class="btn btn-ghost btn-sm" href="<?= sr_admin_h($viewUrl) ?>" target="_blank" rel="noopener">Open</a>
              <?php endif; ?>
              <a class="btn btn-ghost btn-sm" href="<?= sr_admin_h($dlUrl) ?>">Download</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($viewRow['followup_notes'])): ?>
        <p class="section-title" style="margin-top:18px">Notes</p>
        <pre class="notes-box"><?= sr_admin_h((string)$viewRow['followup_notes']) ?></pre>
        <?php endif; ?>
      </div>

      <div class="detail-side">
        <?php if ($isSuper): ?>
        <div class="side-panel">
          <strong>Transfer to staff / agent</strong>
          <label class="muted">Search assignee</label>
          <div class="ss" id="detailTransferSearch" data-selected="<?= (int)($viewRow['assigned_to_admin_id'] ?? 0) ?>"></div>
          <input type="hidden" id="transferUser" value="<?= (int)($viewRow['assigned_to_admin_id'] ?? 0) ?>">
          <label class="muted" style="display:block;margin-top:8px">Note (optional)</label>
          <textarea id="transferNote" placeholder="Why / what to follow up…"></textarea>
          <button type="button" class="btn btn-green" style="margin-top:8px;width:100%" id="btnTransfer" data-id="<?= (int)$viewRow['id'] ?>">Transfer for follow-up</button>
          <button type="button" class="btn btn-red" style="margin-top:8px;width:100%" id="btnDeleteDetail" data-id="<?= (int)$viewRow['id'] ?>" data-label="<?= sr_admin_h($viewName) ?>">Delete application</button>
        </div>
        <?php endif; ?>
        <div class="side-panel status">
          <strong>Change follow-up status</strong>
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
    <h2><?= $isSuper ? 'Submitted applications' : 'My referrals & assigned applications' ?></h2>
    <div class="filters">
      <?php
        $filterLinks = $isSuper
          ? [
              'all' => 'All submitted',
              'unassigned' => 'Unassigned',
              'assigned' => 'Transferred',
              'in_progress' => 'In progress',
              'waiting_documents' => 'Waiting docs',
              'closed' => 'Closed',
            ]
          : [
              'all' => 'All mine',
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

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Form</th>
            <th>Follow-up</th>
            <th>Destination / Intake</th>
            <th>Referring</th>
            <th>Assignee</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$apps): ?>
          <tr><td colspan="8" class="muted"><?= $isSuper ? 'No applications yet.' : 'No applications from your link or transferred to you yet.' ?></td></tr>
        <?php else: foreach ($apps as $row):
          $name = trim((string)($row['full_name'] ?? ''));
          $email = trim((string)($row['email'] ?? ''));
          $label = $name !== '' ? $name : ($email !== '' ? $email : 'Not filled yet');
          $fu = (string)($row['followup_status'] ?? 'new');
          $assignee = trim((string)($row['assignee_name'] ?? ''));
          $referring = trim((string)($row['referring_agent'] ?? ''));
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
            <td><?= sr_admin_h($referring !== '' ? $referring : '—') ?></td>
            <td><?= sr_admin_h($assignee !== '' ? $assignee : 'Unassigned') ?></td>
            <td class="muted"><?= sr_admin_h((string)($row['created_at'] ?? '')) ?></td>
            <td>
              <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="?view=<?= (int)$row['id'] ?>&filter=<?= rawurlencode($filter) ?>">Open</a>
                <button type="button" class="btn btn-sky btn-sm copy-row" data-link="<?= sr_admin_h($shareLink) ?>">Copy link</button>
                <?php if ($isSuper): ?>
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
    <label class="muted">Search staff / agent</label>
    <div class="ss" id="modalTransferSearch"></div>
    <input type="hidden" id="transferModalUser" value="">
    <label class="muted" style="display:block;margin-top:8px">Note (optional)</label>
    <textarea id="transferModalNote" placeholder="Why / what to follow up…"></textarea>
    <div class="actions">
      <button type="button" class="btn btn-ghost" id="transferModalCancel">Cancel</button>
      <button type="button" class="btn btn-green" id="transferModalSave">Transfer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="docPreviewModal" aria-hidden="true">
  <div class="modal wide" role="dialog" aria-labelledby="docPreviewTitle">
    <div class="preview-head">
      <h3 id="docPreviewTitle">Document</h3>
      <button type="button" class="btn btn-ghost btn-sm" id="docPreviewClose">Close</button>
    </div>
    <div class="preview-body" id="docPreviewBody"></div>
    <div class="preview-foot">
      <a class="btn btn-sky btn-sm" id="docPreviewDownload" href="#" download>Download</a>
      <button type="button" class="btn btn-ghost btn-sm" id="docPreviewClose2">Close</button>
    </div>
  </div>
</div>

<script>
(function () {
  const assignableUsers = <?= json_encode(array_values(array_map(static function (array $u): array {
      return [
          'id' => (int) ($u['id'] ?? 0),
          'name' => (string) ($u['full_name'] ?? ''),
          'role' => (string) ($u['role'] ?? ''),
      ];
  }, $assignable)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

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

  function createSmartSearch(root, hiddenInput, options) {
    if (!root) return { setValue() {}, getValue() { return ''; } };
    const opts = options || {};
    const users = Array.isArray(opts.users) ? opts.users : [];
    root.innerHTML = ''
      + '<div class="ss-input-wrap">'
      + '<input type="search" autocomplete="off" placeholder="Type a name or role…">'
      + '<button type="button" class="ss-clear" title="Clear" aria-label="Clear" hidden>&times;</button>'
      + '</div>'
      + '<div class="ss-list" role="listbox"></div>';
    const input = root.querySelector('input');
    const clearBtn = root.querySelector('.ss-clear');
    const list = root.querySelector('.ss-list');
    let activeIndex = -1;
    let filtered = users.slice();

    function findUser(id) {
      const sid = String(id || '');
      return users.find(u => String(u.id) === sid) || null;
    }
    function setHidden(id, label) {
      if (hiddenInput) hiddenInput.value = id ? String(id) : '';
      if (input) input.value = label || '';
      if (clearBtn) clearBtn.hidden = !(id || (input && input.value));
    }
    function closeList() {
      list.classList.remove('open');
      activeIndex = -1;
    }
    function render() {
      if (!filtered.length) {
        list.innerHTML = '<div class="ss-empty">No matching staff or agent</div>';
        list.classList.add('open');
        return;
      }
      list.innerHTML = filtered.map((u, i) => (
        '<button type="button" class="ss-option' + (i === activeIndex ? ' active' : '') + '" data-id="' + u.id + '" data-index="' + i + '">'
        + '<span class="name">' + escapeHtml(u.name || ('User #' + u.id)) + '</span>'
        + '<span class="role">' + escapeHtml(u.role || 'staff') + '</span>'
        + '</button>'
      )).join('');
      list.classList.add('open');
    }
    function filter(q) {
      const needle = String(q || '').trim().toLowerCase();
      filtered = !needle
        ? users.slice(0, 80)
        : users.filter(u => {
            const hay = ((u.name || '') + ' ' + (u.role || '') + ' ' + u.id).toLowerCase();
            return hay.includes(needle);
          }).slice(0, 80);
      activeIndex = filtered.length ? 0 : -1;
      render();
    }
    function pick(user) {
      if (!user) return;
      setHidden(user.id, (user.name || ('User #' + user.id)) + ' (' + (user.role || 'staff') + ')');
      closeList();
    }
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[ch]));
    }

    input.addEventListener('focus', () => filter(input.value));
    input.addEventListener('input', () => {
      if (hiddenInput) hiddenInput.value = '';
      if (clearBtn) clearBtn.hidden = !input.value;
      filter(input.value);
    });
    input.addEventListener('keydown', (e) => {
      if (!list.classList.contains('open')) {
        if (e.key === 'ArrowDown') { filter(input.value); e.preventDefault(); }
        return;
      }
      if (e.key === 'ArrowDown') {
        activeIndex = Math.min(activeIndex + 1, filtered.length - 1);
        render(); e.preventDefault();
      } else if (e.key === 'ArrowUp') {
        activeIndex = Math.max(activeIndex - 1, 0);
        render(); e.preventDefault();
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && filtered[activeIndex]) {
          pick(filtered[activeIndex]); e.preventDefault();
        }
      } else if (e.key === 'Escape') {
        closeList();
      }
    });
    list.addEventListener('mousedown', (e) => {
      const btn = e.target.closest('.ss-option');
      if (!btn) return;
      e.preventDefault();
      pick(findUser(btn.getAttribute('data-id')));
    });
    clearBtn.addEventListener('click', () => {
      setHidden('', '');
      input.focus();
      filter('');
    });
    document.addEventListener('click', (e) => {
      if (!root.contains(e.target)) closeList();
    });

    const initialId = opts.selectedId || (root.getAttribute('data-selected') || '') || (hiddenInput && hiddenInput.value) || '';
    const initial = findUser(initialId);
    if (initial) pick(initial);

    return {
      setValue(id) {
        const u = findUser(id);
        if (u) pick(u);
        else setHidden('', '');
      },
      getValue() {
        return hiddenInput ? String(hiddenInput.value || '') : '';
      },
      focus() { input.focus(); }
    };
  }

  const detailSearch = createSmartSearch(
    document.getElementById('detailTransferSearch'),
    document.getElementById('transferUser'),
    { users: assignableUsers }
  );
  const modalSearch = createSmartSearch(
    document.getElementById('modalTransferSearch'),
    document.getElementById('transferModalUser'),
    { users: assignableUsers }
  );

  const transferModal = document.getElementById('transferModal');
  const transferModalId = document.getElementById('transferModalId');
  const transferModalLabel = document.getElementById('transferModalLabel');
  const transferModalUser = document.getElementById('transferModalUser');
  const transferModalNote = document.getElementById('transferModalNote');
  function openTransferModal(id, label, assigned) {
    if (!transferModal) return;
    if (transferModalId) transferModalId.value = id;
    if (transferModalLabel) transferModalLabel.textContent = label || ('Application #' + id);
    modalSearch.setValue(assigned || '');
    if (transferModalNote) transferModalNote.value = '';
    transferModal.classList.add('open');
    transferModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => modalSearch.focus(), 40);
  }
  function closeTransferModal() {
    if (!transferModal) return;
    transferModal.classList.remove('open');
    transferModal.setAttribute('aria-hidden', 'true');
  }

  const previewModal = document.getElementById('docPreviewModal');
  const previewBody = document.getElementById('docPreviewBody');
  const previewTitle = document.getElementById('docPreviewTitle');
  const previewDownload = document.getElementById('docPreviewDownload');
  function openDocPreview(url, title, kind, downloadUrl) {
    if (!previewModal || !previewBody) return;
    if (previewTitle) previewTitle.textContent = title || 'Document';
    previewBody.innerHTML = '';
    if (kind === 'image') {
      const img = document.createElement('img');
      img.src = url;
      img.alt = title || 'Document';
      previewBody.appendChild(img);
    } else {
      const iframe = document.createElement('iframe');
      iframe.src = url;
      iframe.title = title || 'Document';
      previewBody.appendChild(iframe);
    }
    if (previewDownload) {
      previewDownload.href = downloadUrl || url;
    }
    previewModal.classList.add('open');
    previewModal.setAttribute('aria-hidden', 'false');
  }
  function closeDocPreview() {
    if (!previewModal) return;
    previewModal.classList.remove('open');
    previewModal.setAttribute('aria-hidden', 'true');
    if (previewBody) previewBody.innerHTML = '';
  }
  document.querySelectorAll('.btn-preview-doc').forEach(btn => {
    btn.addEventListener('click', function () {
      openDocPreview(
        this.getAttribute('data-url') || '',
        this.getAttribute('data-title') || 'Document',
        this.getAttribute('data-kind') || 'pdf',
        this.getAttribute('data-download') || ''
      );
    });
  });
  document.getElementById('docPreviewClose')?.addEventListener('click', closeDocPreview);
  document.getElementById('docPreviewClose2')?.addEventListener('click', closeDocPreview);
  previewModal?.addEventListener('click', function (e) {
    if (e.target === previewModal) closeDocPreview();
  });

  const srLink = document.getElementById('srLink');
  const copyMsg = document.getElementById('copyMsg');
  const manualPanel = document.getElementById('manualPanel');
  const manualFrame = document.getElementById('manualFrame');

  document.getElementById('btnManual')?.addEventListener('click', function () {
    const manualUrl = <?= json_encode($manualLink, JSON_UNESCAPED_SLASHES) ?>;
    const sep = manualUrl.indexOf('?') >= 0 ? '&' : '?';
    if (manualFrame) manualFrame.src = manualUrl + sep + 't=' + Date.now();
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
    const assigned = detailSearch.getValue() || document.getElementById('transferUser')?.value || '';
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
    const assigned = modalSearch.getValue() || transferModalUser?.value || '';
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

  if (document.getElementById('detailCard')) {
    document.getElementById('detailCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
})();
</script>
</body>
</html>

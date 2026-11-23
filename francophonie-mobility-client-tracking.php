<?php
/**
 * Client Tracking checklist report.
 * Lists FM clients vs Clients tracking.xlsx checklist; green = found (clickable), red = missing.
 * Folder files are mapped to checklist items (document groups unchanged).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';
require_once __DIR__ . '/helpers/fm_client_tracking.php';
require_once __DIR__ . '/helpers/fm_payment_records.php';

fm_ensure_schema($conn);
fm_ensure_documents_schema($conn);
fm_ensure_tracking_schema($conn);
fm_ensure_payment_records_schema($conn);

fm_require_staff_or_manager_portal($conn);

$isPortal = fm_manager_portal_logged_in();
$staffSession = !empty($_SESSION['id']) || !empty($_SESSION['admin_id']);
$inAdminFrame = (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe')
    || isset($_GET['staff'])
    || (isset($_SERVER['HTTP_REFERER']) && str_contains((string) $_SERVER['HTTP_REFERER'], 'admin-dashboard.php'));
$showPortalNav = $isPortal && !$inAdminFrame;
$canSendEmail = !$showPortalNav && ($staffSession || !$isPortal);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$checklist = fm_tracking_checklist();
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$q = trim((string) ($_GET['q'] ?? ''));
$forceScan = isset($_GET['rescan']) && $_GET['rescan'] === '1';
$scanOne = (int) ($_GET['scan_id'] ?? 0);
$scanReady = pcvc_docvision_is_configured();
$scanErrors = [];
if ($forceScan || $scanOne > 0) {
    @set_time_limit(300);
}

$reportRecipients = fm_payment_report_recipients();

$where = '1=1';
$types = '';
$params = [];
if ($statusFilter !== 'all' && fm_is_valid_application_status($statusFilter)) {
    $where .= ' AND status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= ' AND (
        first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR reference_id LIKE ?
        OR CONCAT(TRIM(first_name), \' \', TRIM(last_name)) LIKE ?
        OR CONCAT(TRIM(last_name), \' \', TRIM(first_name)) LIKE ?
    )';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql = "SELECT id, reference_id, first_name, last_name, email, status, source,
               cv_file, french_cert_file, english_cert_file, academic_docs_file, updated_at
        FROM francophonie_mobility_applications
        WHERE {$where}
        ORDER BY updated_at DESC, id DESC
        LIMIT 500";
$st = $conn->prepare($sql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$apps = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$st->close();

$sourceLabel = static function (string $source): string {
    return match ($source) {
        'gemini' => 'scanned',
        'heuristic' => 'folders',
        'cache' => 'saved',
        default => $source !== '' ? 'checked' : '',
    };
};

$rows = [];
foreach ($apps as $app) {
    $appId = (int) $app['id'];
    $force = $forceScan || ($scanOne > 0 && $scanOne === $appId);
    $resolved = fm_tracking_resolve_for_app($conn, $app, $force);
    if (!empty($resolved['error'])) {
        $scanErrors[$appId] = (string) $resolved['error'];
    }
    $matches = $resolved['matches'];
    $present = 0;
    $cells = [];
    foreach ($checklist as $key => $label) {
        $file = $matches[$key] ?? null;
        $ok = is_array($file) && !empty($file['path']);
        if ($ok) {
            $present++;
        }
        $urls = fm_tracking_cell_urls($ok ? $file : null);
        $cells[$key] = [
            'ok' => $ok,
            'label' => $label,
            'name' => $ok ? (string) ($file['name'] ?? $label) : '',
            'group' => $ok ? (string) ($file['group'] ?? '') : '',
            'view' => $urls['view'],
            'download' => $urls['download'],
        ];
    }
    $total = count($checklist);
    $name = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
    $rows[] = [
        'id' => $appId,
        'name' => $name,
        'email' => (string) ($app['email'] ?? ''),
        'ref' => (string) ($app['reference_id'] ?? ''),
        'status' => (string) ($app['status'] ?? ''),
        'status_label' => fm_application_status_label((string) ($app['status'] ?? '')),
        'source' => $sourceLabel((string) ($resolved['source'] ?? '')),
        'present' => $present,
        'total' => $total,
        'cells' => $cells,
        'search' => strtolower($name . ' ' . ($app['email'] ?? '') . ' ' . ($app['reference_id'] ?? '') . ' ' . ($app['status'] ?? '')),
    ];
}

$statuses = fm_application_statuses();
$queryBase = 'status=' . rawurlencode($statusFilter) . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Tracking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f3; color: #1e293b; }
        .page-head {
            background: linear-gradient(135deg, #1e4d2b, #3661B9);
            color: #fff;
            padding: 1.1rem 0;
            margin-bottom: 1rem;
        }
        .card-soft { border: 0; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); background: #fff; }
        .hint { color: #64748b; font-size: .85rem; }
        .search-wrap { position: relative; }
        .search-wrap i {
            position: absolute; left: .75rem; top: 50%; transform: translateY(-50%);
            color: #94a3b8; pointer-events: none;
        }
        .search-wrap input { padding-left: 2.15rem; }
        /* Horizontal scroll only — no second vertical scrollbar inside the card */
        .table-wrap { overflow-x: auto; overflow-y: visible; }
        table.track-table { border-collapse: separate; border-spacing: 0; min-width: 1400px; }
        table.track-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: #1e4d2b; color: #fff; font-size: .72rem;
            font-weight: 600; white-space: nowrap; vertical-align: middle;
            padding: .55rem .45rem;
        }
        table.track-table thead th.sticky-col,
        table.track-table tbody td.sticky-col {
            position: sticky; left: 0; z-index: 1; background: #fff;
            min-width: 200px; max-width: 240px;
        }
        table.track-table thead th.sticky-col { z-index: 3; background: #1e4d2b; }
        table.track-table tbody td {
            padding: .35rem .3rem; vertical-align: middle; text-align: center;
            border-bottom: 1px solid #e2e8f0; font-size: .75rem;
        }
        table.track-table tbody td.sticky-col { text-align: left; background: #fff; }
        table.track-table tbody tr.is-hidden { display: none; }
        .cell-ok {
            background: #dcfce7 !important;
            color: #166534;
            font-weight: 600;
        }
        .cell-ok a {
            color: #166534;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .cell-ok a:hover { color: #14532d; }
        .cell-miss {
            background: #fee2e2 !important;
            color: #991b1b;
            font-weight: 600;
        }
        .pct { font-size: .72rem; color: #64748b; }
        .badge-src { font-size: .65rem; }
        mark.hit { background: #fef08a; padding: 0 .1rem; border-radius: 2px; }
        .send-box { border: 1px solid #e2e8f0; border-radius: 12px; padding: .85rem 1rem; margin-top: .85rem; background: #fff; }
        .send-progress { display: none; margin-top: .75rem; }
        .send-progress.active { display: block; }
        .send-progress .bar-track {
            height: 10px; border-radius: 999px; background: #e2e8f0; overflow: hidden;
        }
        .send-progress .bar-fill {
            height: 100%; width: 8%; border-radius: 999px;
            background: linear-gradient(90deg, #1e4d2b, #3661B9, #1e4d2b);
            background-size: 200% 100%;
            animation: sendSlide 1.1s linear infinite, sendGrow 12s ease-out forwards;
        }
        .send-progress.done .bar-fill {
            width: 100% !important; animation: none; background: #16a34a;
        }
        .send-progress.fail .bar-fill {
            width: 100% !important; animation: none; background: #dc2626;
        }
        @keyframes sendSlide {
            0% { background-position: 0% 0; }
            100% { background-position: -200% 0; }
        }
        @keyframes sendGrow {
            0% { width: 8%; }
            20% { width: 28%; }
            50% { width: 55%; }
            80% { width: 78%; }
            100% { width: 92%; }
        }
        .send-status { font-size: .82rem; color: #64748b; margin-top: .4rem; }
    </style>
</head>
<body>
<?= $showPortalNav ? fm_manager_portal_nav_html('report') : '' ?>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>Client Tracking</h1>
        <p class="mb-0 opacity-75 small">
            Checklist matches <code>Clients tracking.xlsx</code>. Files live in existing Visa submission folders (groups unchanged; multiple files per folder allowed).
            Green = found (preview / download). Red = missing. No document is mandatory.
        </p>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-4">
    <div class="card card-soft p-3 mb-3">
        <form method="get" class="row g-2 align-items-end" id="trackFilterForm">
            <div class="col-lg-5">
                <label class="form-label small fw-semibold mb-1" for="smartSearch">Smart search</label>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input
                        type="search"
                        class="form-control form-control-sm"
                        id="smartSearch"
                        name="q"
                        value="<?= htmlspecialchars($q) ?>"
                        placeholder="Name, email, reference, status…"
                        autocomplete="off"
                        autofocus
                    >
                </div>
            </div>
            <div class="col-md-3 col-lg-3">
                <label class="form-label small fw-semibold mb-1">Filter by status</label>
                <select name="status" class="form-select form-select-sm" id="statusFilter">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All clients</option>
                    <?php foreach ($statuses as $slug => $lab): ?>
                    <option value="<?= htmlspecialchars($slug) ?>" <?= $statusFilter === $slug ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-4 d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
                <button type="button" class="btn btn-sm btn-outline-success" id="exportExcelBtn">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars($queryBase) ?>&amp;rescan=1">
                    <i class="fas fa-sync-alt me-1"></i> Refresh check
                </a>
            </div>
            <div class="col-12">
                <span class="hint" id="resultHint">
                    <?= $scanReady
                        ? '<i class="fas fa-check-circle text-success"></i> Document check ready'
                        : '<i class="fas fa-exclamation-triangle text-warning"></i> Using folder names only' ?>
                    · <span id="visibleCount"><?= count($rows) ?></span> of <?= count($rows) ?> client<?= count($rows) === 1 ? '' : 's' ?>
                </span>
            </div>
        </form>
        <?php if ($canSendEmail): ?>
        <div class="send-box">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                <strong class="small">Send email</strong>
                <span class="hint mb-0">Builds Excel (Client tracking + Payment records) then emails selected addresses</span>
            </div>
            <form id="sendReportForm" class="d-flex flex-wrap gap-3 align-items-center" onsubmit="return false;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php foreach ($reportRecipients as $email => $meta): ?>
                    <label class="form-check form-check-inline mb-0 small">
                        <input
                            class="form-check-input report-to-check"
                            type="checkbox"
                            name="report_to[]"
                            value="<?= htmlspecialchars($email) ?>"
                            checked
                        >
                        <span class="form-check-label"><?= htmlspecialchars($email) ?></span>
                    </label>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-success ms-auto" id="sendEmailBtn">
                    <i class="fas fa-paper-plane me-1"></i> Send email
                </button>
            </form>
            <div class="send-progress" id="sendProgress">
                <div class="bar-track"><div class="bar-fill" id="sendBarFill"></div></div>
                <div class="send-status" id="sendStatusText">Preparing report…</div>
            </div>
            <div id="sendAlert" class="mt-2" hidden></div>
        </div>
        <?php endif; ?>
        <?php if ($scanErrors !== []): ?>
        <div class="alert alert-warning mt-3 mb-0 py-2 small">
            Some rows used folder names when a full document check was not available.
        </div>
        <?php endif; ?>
    </div>

    <div class="card card-soft">
        <div class="table-wrap">
            <table class="table track-table mb-0" id="trackTable">
                <thead>
                    <tr>
                        <th class="sticky-col">Client name</th>
                        <th>Status</th>
                        <th>Done</th>
                        <?php foreach ($checklist as $lab): ?>
                        <th><?= htmlspecialchars($lab) ?></th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr id="emptyServerRow"><td colspan="<?= 4 + count($checklist) ?>" class="text-muted p-4">No clients found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <?php
                    $exportDocs = [];
                    foreach ($checklist as $key => $lab) {
                        $exportDocs[$lab] = !empty($r['cells'][$key]['ok']) ? 'Available' : 'Missing';
                    }
                    $exportPayload = [
                        'name' => $r['name'] !== '' ? $r['name'] : '—',
                        'ref' => $r['ref'],
                        'status' => $r['status_label'],
                        'done' => $r['present'] . '/' . $r['total'],
                        'pct' => ($r['total'] > 0 ? (int) round(100 * $r['present'] / $r['total']) : 0) . '%',
                        'docs' => $exportDocs,
                    ];
                    ?>
                    <tr
                        class="track-row"
                        data-search="<?= htmlspecialchars($r['search'], ENT_QUOTES, 'UTF-8') ?>"
                        data-export="<?= htmlspecialchars(json_encode($exportPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <td class="sticky-col">
                            <div class="fw-semibold text-truncate js-name" title="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name'] !== '' ? $r['name'] : '—') ?></div>
                            <div class="pct"><code class="js-ref"><?= htmlspecialchars($r['ref']) ?></code>
                                <?php if ($r['source'] !== ''): ?>
                                <span class="badge text-bg-light badge-src"><?= htmlspecialchars($r['source']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="small js-status"><?= htmlspecialchars($r['status_label']) ?></span></td>
                        <td>
                            <strong><?= (int) $r['present'] ?>/<?= (int) $r['total'] ?></strong>
                            <div class="pct"><?= $r['total'] > 0 ? (int) round(100 * $r['present'] / $r['total']) : 0 ?>%</div>
                        </td>
                        <?php foreach ($checklist as $key => $_lab): ?>
                            <?php $c = $r['cells'][$key]; ?>
                            <?php if ($c['ok']): ?>
                            <td class="cell-ok" title="<?= htmlspecialchars($c['name'] . ($c['group'] !== '' ? ' · ' . $c['group'] : '')) ?>">
                                <a href="<?= htmlspecialchars($c['view']) ?>" target="_blank" rel="noopener">View</a>
                                ·
                                <a href="<?= htmlspecialchars($c['download']) ?>">DL</a>
                            </td>
                            <?php else: ?>
                            <td class="cell-miss">Missing</td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td>
                            <a class="btn btn-outline-secondary btn-sm" href="?<?= htmlspecialchars($queryBase) ?>&amp;scan_id=<?= (int) $r['id'] ?>" title="Refresh this client">
                                <i class="fas fa-sync-alt"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="emptyFilterRow" class="is-hidden">
                        <td colspan="<?= 4 + count($checklist) ?>" class="text-muted p-4">No clients match your search.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('smartSearch');
    const rows = Array.from(document.querySelectorAll('.track-row'));
    const visibleCount = document.getElementById('visibleCount');
    const emptyFilterRow = document.getElementById('emptyFilterRow');
    const exportBtn = document.getElementById('exportExcelBtn');
    const total = rows.length;
    let timer = null;

    function normalize(s) {
        return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function tokens(s) {
        return normalize(s).split(' ').filter(Boolean);
    }

    function visibleRows() {
        return rows.filter(function (row) { return !row.classList.contains('is-hidden'); });
    }

    function applyFilter() {
        const toks = tokens(input ? input.value : '');
        let shown = 0;
        rows.forEach(function (row) {
            const hay = row.getAttribute('data-search') || '';
            const ok = toks.every(function (t) { return hay.indexOf(t) !== -1; });
            row.classList.toggle('is-hidden', !ok);
            if (ok) shown++;
        });
        if (visibleCount) visibleCount.textContent = String(shown);
        if (emptyFilterRow) emptyFilterRow.classList.toggle('is-hidden', shown > 0 || total === 0);
    }

    function xmlEscape(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function exportExcel() {
        const visible = visibleRows();
        if (!visible.length) {
            alert('No clients match the current filter.');
            return;
        }
        const docHeaders = <?= json_encode(array_values($checklist), JSON_UNESCAPED_UNICODE) ?>;
        const headers = ['Client name', 'Reference', 'Status', 'Done', 'Percent'].concat(docHeaders);
        let rowsXml = '<Row>';
        headers.forEach(function (h) {
            rowsXml += '<Cell ss:StyleID="Header"><Data ss:Type="String">' + xmlEscape(h) + '</Data></Cell>';
        });
        rowsXml += '</Row>';

        visible.forEach(function (row) {
            let payload = {};
            try { payload = JSON.parse(row.getAttribute('data-export') || '{}'); } catch (e) { payload = {}; }
            const docs = payload.docs || {};
            const values = [
                payload.name || '',
                payload.ref || '',
                payload.status || '',
                payload.done || '',
                payload.pct || ''
            ].concat(docHeaders.map(function (h) { return docs[h] || 'Missing'; }));
            rowsXml += '<Row>';
            values.forEach(function (v, idx) {
                const isDoc = idx >= 5;
                const style = !isDoc ? 'Text' : (String(v) === 'Available' ? 'Ok' : 'Miss');
                rowsXml += '<Cell ss:StyleID="' + style + '"><Data ss:Type="String">' + xmlEscape(v) + '</Data></Cell>';
            });
            rowsXml += '</Row>';
        });

        const xml = '<?xml version="1.0"?>\n'
            + '<?mso-application progid="Excel.Sheet"?>\n'
            + '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"\n'
            + ' xmlns:o="urn:schemas-microsoft-com:office:office"\n'
            + ' xmlns:x="urn:schemas-microsoft-com:office:excel"\n'
            + ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n'
            + '<Styles>\n'
            + '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E4D2B" ss:Pattern="Solid"/></Style>\n'
            + '<Style ss:ID="Text"/>\n'
            + '<Style ss:ID="Ok"><Font ss:Color="#166534"/><Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/></Style>\n'
            + '<Style ss:ID="Miss"><Font ss:Color="#991B1B"/><Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/></Style>\n'
            + '</Styles>\n'
            + '<Worksheet ss:Name="Client tracking"><Table>'
            + rowsXml
            + '</Table></Worksheet></Workbook>';

        const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const stamp = new Date().toISOString().slice(0, 10);
        a.href = url;
        a.download = 'client-tracking-' + stamp + '.xls';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    if (input) {
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(applyFilter, 120);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                input.value = '';
                applyFilter();
            }
        });
        applyFilter();
    }
    if (exportBtn) exportBtn.addEventListener('click', exportExcel);

    const sendBtn = document.getElementById('sendEmailBtn');
    const sendForm = document.getElementById('sendReportForm');
    const sendProgress = document.getElementById('sendProgress');
    const sendStatusText = document.getElementById('sendStatusText');
    const sendAlert = document.getElementById('sendAlert');
    let sending = false;

    function showSendAlert(ok, msg) {
        if (!sendAlert) return;
        sendAlert.hidden = false;
        sendAlert.className = 'alert mt-2 mb-0 py-2 small ' + (ok ? 'alert-success' : 'alert-danger');
        sendAlert.textContent = msg;
    }

    function setProgressState(state, text) {
        if (!sendProgress) return;
        sendProgress.classList.add('active');
        sendProgress.classList.remove('done', 'fail');
        if (state === 'run') {
            const track = sendProgress.querySelector('.bar-track');
            const oldFill = document.getElementById('sendBarFill');
            if (track && oldFill) {
                const fresh = oldFill.cloneNode(true);
                fresh.removeAttribute('style');
                oldFill.replaceWith(fresh);
            }
        }
        if (state === 'done') sendProgress.classList.add('done');
        if (state === 'fail') sendProgress.classList.add('fail');
        if (sendStatusText) sendStatusText.textContent = text || '';
    }

    async function sendReportEmail() {
        if (sending || !sendForm || !sendBtn) return;
        const checks = Array.from(sendForm.querySelectorAll('.report-to-check'));
        const selected = checks.filter(function (el) { return el.checked; }).map(function (el) { return el.value; });
        if (!selected.length) {
            showSendAlert(false, 'Select at least one email address.');
            return;
        }

        sending = true;
        sendBtn.disabled = true;
        const oldHtml = sendBtn.innerHTML;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
        if (sendAlert) sendAlert.hidden = true;
        setProgressState('run', 'Building Excel report…');

        const steps = [
            'Building Excel report…',
            'Attaching Client tracking + Payment records…',
            'Connecting mail server…',
            'Delivering to selected addresses…'
        ];
        let step = 0;
        const stepTimer = setInterval(function () {
            step = Math.min(step + 1, steps.length - 1);
            if (sendStatusText && sending) sendStatusText.textContent = steps[step];
        }, 2200);

        try {
            const fd = new FormData();
            const csrf = sendForm.querySelector('input[name="csrf_token"]');
            if (csrf) fd.append('csrf_token', csrf.value);
            selected.forEach(function (email) { fd.append('report_to[]', email); });

            const res = await fetch('fm_send_tracking_report.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            let data = null;
            try { data = await res.json(); } catch (e) { data = null; }
            clearInterval(stepTimer);

            if (data && data.success) {
                setProgressState('done', 'Email sent successfully.');
                showSendAlert(true, data.message || 'Report emailed successfully.');
            } else {
                const msg = (data && data.message) ? data.message : ('Send failed (HTTP ' + res.status + ').');
                setProgressState('fail', 'Send failed.');
                showSendAlert(false, msg);
            }
        } catch (err) {
            clearInterval(stepTimer);
            setProgressState('fail', 'Send failed.');
            showSendAlert(false, err && err.message ? err.message : 'Network error while sending.');
        } finally {
            sending = false;
            sendBtn.disabled = false;
            sendBtn.innerHTML = oldHtml;
        }
    }

    if (sendBtn) sendBtn.addEventListener('click', sendReportEmail);
})();
</script>
</body>
</html>


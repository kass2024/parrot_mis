<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/caq_applications.php';

pcvc_caq_ensure_schema($conn);

$docs = pcvc_caq_missing_doc_options();
$formOptions = pcvc_caq_form_options();
$signedOptions = pcvc_caq_signed_options();
$submittedOptions = pcvc_caq_submitted_options();
$receivedOptions = pcvc_caq_received_options();
$irccOptions = pcvc_caq_ircc_options();

$rows = [];
$res = $conn->query('SELECT * FROM caq_applications ORDER BY full_name ASC');
if ($res) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$kpi = ['all' => count($rows), 'received' => 0, 'not_yet' => 0, 'missing' => 0, 'ircc_yes' => 0];
foreach ($rows as $row) {
    $received = strtolower((string) $row['caq_received']);
    if (str_starts_with($received, 'received')) {
        $kpi['received']++;
    } elseif ($received !== '') {
        $kpi['not_yet']++;
    }
    $keys = pcvc_caq_docs_to_keys((string) $row['missing_docs']);
    if ($keys !== [] && $keys !== ['na']) {
        $kpi['missing']++;
    }
    if (strcasecmp((string) $row['ircc_account'], 'Yes') === 0) {
        $kpi['ircc_yes']++;
    }
}

function caq_select(string $field, array $options, string $current, int $id): string
{
    $html = '<select class="caq-field" data-id="' . $id . '" data-field="' . htmlspecialchars($field) . '">';
    foreach ($options as $value => $label) {
        $sel = ((string) $value === $current) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars((string) $value) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAQ Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: #f8fafc; color: #14532d; }
        .wrap { padding: 14px; max-width: 1400px; margin: 0 auto; }
        .head { background: #fff; border-left: 5px solid #E21D1E; border-radius: 10px; padding: 12px 14px; display: flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap; }
        .head h1 { margin: 0; font-size: 1.2rem; }
        .head p { margin: 2px 0 0; color: #64748b; font-size: 0.85rem; }
        .kpis { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
        .kpi { border: 1px solid #e2e8f0; background: #fff; border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 700; color: #475569; cursor: pointer; }
        .kpi strong { color: #14532d; margin-right: 4px; }
        .kpi.active { background: #f0fdf4; border-color: #166534; color: #166534; }
        .add { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; margin-bottom: 8px; }
        .add summary { cursor: pointer; font-weight: 700; }
        .add-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; margin-top: 10px; }
        .add-grid label, .facts label, .caq-comment-label { display: flex; flex-direction: column; gap: 3px; font-size: 11px; font-weight: 700; color: #334155; }
        input, select { font: inherit; font-size: 14px; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 8px; }
        .add button { background: #166534; color: #fff; border: 0; border-radius: 8px; padding: 8px 12px; font-weight: 700; cursor: pointer; }
        .search { width: 100%; margin: 0 0 10px; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        @media (min-width: 900px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1400px) { .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .card h2 { margin: 0; font-size: 0.98rem; background: #166534; color: #fff; padding: 8px 12px; }
        .card-body { padding: 8px 10px 10px; display: grid; gap: 8px; }
        .facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
        @media (min-width: 520px) { .facts { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .docs { display: flex; flex-wrap: wrap; gap: 6px; }
        .doc { border: 1px solid #cbd5e1; background: #fff; color: #475569; border-radius: 999px; padding: 4px 8px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .doc.on.cv_resume { background: #e7d7c1; border-color: #c4a484; color: #5c4033; }
        .doc.on.passport { background: #dbeafe; border-color: #93c5fd; color: #1e3a8a; }
        .doc.on.degree_transcript { background: #e7d3c4; border-color: #c4a484; color: #6b3e26; }
        .doc.on.loa { background: #d5e4e1; border-color: #7f9c96; color: #1f3d38; }
        .doc.on.personal_statement { background: #dbe7f5; border-color: #93b4d6; color: #1e3a5f; }
        .doc.on.signed_declaration { background: #e5e7eb; border-color: #9ca3af; color: #374151; }
        .doc.on.na { background: #f1f5f9; border-color: #94a3b8; color: #334155; }
        .msg { font-size: 13px; font-weight: 700; margin: 6px 0 0; }
        .pdf-btn { background: #166534; color: #fff; border: 0; border-radius: 8px; padding: 8px 12px; font-weight: 700; cursor: pointer; }
        #caqEmpty { display: none; text-align: center; color: #64748b; padding: 20px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-file-signature"></i> CAQ Applications</h1>
            <p>CAQ and QS applicant documents. Click a missing document to select it.</p>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong id="caqCount"><?= (int) $kpi['all'] ?> application<?= $kpi['all'] === 1 ? '' : 's' ?></strong>
            <button type="button" class="pdf-btn" id="caqPdf"><i class="fas fa-file-pdf"></i> Export PDF</button>
        </div>
    </div>
    <div class="kpis">
        <button type="button" class="kpi active" data-kpi="all"><strong><?= (int) $kpi['all'] ?></strong>All</button>
        <button type="button" class="kpi" data-kpi="received"><strong><?= (int) $kpi['received'] ?></strong>CAQ received</button>
        <button type="button" class="kpi" data-kpi="not_yet"><strong><?= (int) $kpi['not_yet'] ?></strong>Not yet</button>
        <button type="button" class="kpi" data-kpi="missing"><strong><?= (int) $kpi['missing'] ?></strong>Missing documents</button>
        <button type="button" class="kpi" data-kpi="ircc_yes"><strong><?= (int) $kpi['ircc_yes'] ?></strong>IRCC account</button>
    </div>
    <details class="add">
        <summary>Add an applicant</summary>
        <form id="caqAdd">
            <div class="add-grid">
                <label>Full name<input name="full_name" required maxlength="200"></label>
                <label>Form of CAQ<select name="caq_form"><?php foreach ($formOptions as $v => $l): ?><option value="<?= htmlspecialchars((string) $v) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
                <label>Signed form<select name="signed_form"><?php foreach ($signedOptions as $v => $l): ?><option value="<?= htmlspecialchars((string) $v) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
                <label>Submitted<select name="submitted_status"><?php foreach ($submittedOptions as $v => $l): ?><option value="<?= htmlspecialchars((string) $v) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
                <label>CAQ received<select name="caq_received"><?php foreach ($receivedOptions as $v => $l): ?><option value="<?= htmlspecialchars((string) $v) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
                <label>IRCC account<select name="ircc_account"><?php foreach ($irccOptions as $v => $l): ?><option value="<?= htmlspecialchars((string) $v) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
                <label>Comment<input name="comment" maxlength="255"></label>
            </div>
            <div class="docs" style="margin-top:8px;">
                <?php foreach ($docs as $key => $label): ?>
                <label class="doc"><input type="checkbox" name="missing_docs[]" value="<?= htmlspecialchars($key) ?>"> <?= htmlspecialchars($label) ?></label>
                <?php endforeach; ?>
            </div>
            <button type="submit" style="margin-top:8px;">Add</button>
            <p class="msg" id="caqAddMsg"></p>
        </form>
    </details>
    <input class="search" id="caqSearch" type="search" placeholder="Search by name, comment, or document..." aria-label="Search CAQ applicants">
    <div class="grid" id="caqGrid">
        <?php foreach ($rows as $row):
            $id = (int) $row['id'];
            $keys = pcvc_caq_docs_to_keys((string) $row['missing_docs']);
            $received = strtolower((string) $row['caq_received']);
            $flags = ['all'];
            if (str_starts_with($received, 'received')) {
                $flags[] = 'received';
            } elseif ($received !== '') {
                $flags[] = 'not_yet';
            }
            if ($keys !== [] && $keys !== ['na']) {
                $flags[] = 'missing';
            }
            if (strcasecmp((string) $row['ircc_account'], 'Yes') === 0) {
                $flags[] = 'ircc_yes';
            }
            $blob = mb_strtolower(trim($row['full_name'] . ' ' . $row['comment'] . ' ' . $row['caq_received'] . ' ' . implode(' ', array_map(static fn($k) => $docs[$k] ?? '', $keys))));
        ?>
        <article class="card" data-flags="<?= htmlspecialchars(implode(' ', $flags)) ?>" data-search="<?= htmlspecialchars($blob) ?>">
            <h2><?= htmlspecialchars($row['full_name']) ?></h2>
            <div class="card-body">
                <div class="facts">
                    <label>Form of CAQ<?= caq_select('caq_form', $formOptions, (string) $row['caq_form'], $id) ?></label>
                    <label>Signed form<?= caq_select('signed_form', $signedOptions, (string) $row['signed_form'], $id) ?></label>
                    <label>Submitted<?= caq_select('submitted_status', $submittedOptions, (string) $row['submitted_status'], $id) ?></label>
                    <label>CAQ received<?= caq_select('caq_received', $receivedOptions, (string) $row['caq_received'], $id) ?></label>
                    <label>IRCC account<?= caq_select('ircc_account', $irccOptions, (string) $row['ircc_account'], $id) ?></label>
                    <label class="caq-comment-label">Comment<input class="caq-comment" data-id="<?= $id ?>" value="<?= htmlspecialchars((string) $row['comment']) ?>" maxlength="255"></label>
                </div>
                <div class="docs" data-id="<?= $id ?>">
                    <?php foreach ($docs as $key => $label): ?>
                    <button type="button" class="doc <?= htmlspecialchars($key) ?><?= in_array($key, $keys, true) ? ' on' : '' ?>" data-doc="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <div id="caqEmpty"><h3>No applications match</h3></div>
</div>
<script>
(function () {
    var kpi = 'all';
    function apply() {
        var q = (document.getElementById('caqSearch').value || '').trim().toLowerCase();
        var shown = 0;
        document.querySelectorAll('#caqGrid .card').forEach(function (card) {
            var flags = (card.getAttribute('data-flags') || '').split(' ');
            var hay = (card.getAttribute('data-search') || '').toLowerCase();
            var ok = (kpi === 'all' || flags.indexOf(kpi) !== -1) && (q === '' || hay.indexOf(q) !== -1);
            card.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        document.getElementById('caqEmpty').style.display = shown === 0 ? 'block' : 'none';
        document.getElementById('caqCount').textContent = shown + ' application' + (shown === 1 ? '' : 's');
    }
    document.getElementById('caqSearch').addEventListener('input', apply);
    document.querySelectorAll('.kpi').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.kpi').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            kpi = btn.getAttribute('data-kpi') || 'all';
            apply();
        });
    });
    function post(body) {
        return fetch('api/caq-application.php', { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    document.addEventListener('change', function (e) {
        var sel = e.target.closest('.caq-field');
        if (!sel) return;
        var fd = new FormData();
        fd.append('action', 'field');
        fd.append('id', sel.getAttribute('data-id'));
        fd.append('field', sel.getAttribute('data-field'));
        fd.append('value', sel.value);
        post(fd).then(function (json) { if (!json.success) alert(json.message || 'Could not save'); });
    });
    document.addEventListener('change', function (e) {
        var input = e.target.closest('.caq-comment');
        if (!input) return;
        var fd = new FormData();
        fd.append('action', 'field');
        fd.append('id', input.getAttribute('data-id'));
        fd.append('field', 'comment');
        fd.append('value', input.value);
        post(fd).then(function (json) { if (!json.success) alert(json.message || 'Could not save'); });
    });
    document.addEventListener('click', function (e) {
        var chip = e.target.closest('.doc[data-doc]');
        if (!chip) return;
        var box = chip.parentElement;
        var key = chip.getAttribute('data-doc');
        if (key === 'na') {
            box.querySelectorAll('.doc').forEach(function (el) { el.classList.toggle('on', el === chip ? !chip.classList.contains('on') : false); });
        } else {
            var na = box.querySelector('.doc[data-doc="na"]');
            if (na) na.classList.remove('on');
            chip.classList.toggle('on');
        }
        var fd = new FormData();
        fd.append('action', 'docs');
        fd.append('id', box.getAttribute('data-id'));
        box.querySelectorAll('.doc.on').forEach(function (el) { fd.append('missing_docs[]', el.getAttribute('data-doc')); });
        post(fd).then(function (json) { if (!json.success) alert(json.message || 'Could not save documents'); });
    });
    document.getElementById('caqPdf').addEventListener('click', function () {
        var params = new URLSearchParams();
        params.set('kpi', kpi);
        var q = (document.getElementById('caqSearch').value || '').trim();
        if (q !== '') params.set('search', q);
        var frame = document.getElementById('caqExportFrame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'caqExportFrame';
            frame.hidden = true;
            document.body.appendChild(frame);
        }
        frame.src = 'caq-export.php?' + params.toString();
    });
    var add = document.getElementById('caqAdd');
    if (add) {
        add.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var msg = document.getElementById('caqAddMsg');
            var fd = new FormData(add);
            fd.append('action', 'add');
            if (msg) msg.textContent = 'Saving…';
            post(fd).then(function (json) {
                if (!json.success) { if (msg) msg.textContent = json.message || 'Could not add.'; return; }
                window.location.reload();
            });
        });
    }
})();
</script>
</body>
</html>

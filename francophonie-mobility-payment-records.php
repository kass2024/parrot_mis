<?php
/**
 * Francophonie Mobility payment records (Sultan fees).
 * Superadmin edits; manager portal views. Clients picked from FM applications DB.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/fm_payment_records.php';

fm_ensure_schema($conn);
fm_ensure_payment_records_schema($conn);

$isPortal = fm_manager_portal_logged_in();
$isSuper = false;
if (!empty($_SESSION['id']) || !empty($_SESSION['admin_id'])) {
    $isSuper = pcvc_current_user_is_superadmin($conn);
}

if ($isPortal && fm_manager_portal_script_allowed()) {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
} elseif ($isSuper) {
    // ok
} else {
    if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
        header('Location: admin-login.php');
        exit;
    }
    pcvc_require_superadmin($conn);
}

$canEdit = $isSuper && (!empty($_SESSION['id']) || !empty($_SESSION['admin_id']));
$message = '';
$error = '';
$editId = (int) ($_GET['edit'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($canEdit && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $error = 'Session expired. Refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        try {
            if ($action === 'delete') {
                $delId = (int) ($_POST['id'] ?? 0);
                if (!fm_payment_record_delete($conn, $delId)) {
                    throw new RuntimeException('Could not delete that record.');
                }
                $message = 'Payment record removed.';
                $editId = 0;
            } elseif ($action === 'save' || $action === 'add') {
                $id = (int) ($_POST['id'] ?? 0);
                $clientMode = trim((string) ($_POST['client_mode'] ?? 'existing'));
                $appId = (int) ($_POST['application_id'] ?? 0);
                $newName = trim((string) ($_POST['new_full_name'] ?? ''));
                $job = trim((string) ($_POST['job_position'] ?? ''));

                if ($clientMode === 'new') {
                    if ($newName === '') {
                        throw new RuntimeException('Enter the new client full name.');
                    }
                    $appId = fm_payment_create_stub_application($conn, $newName, $job);
                } elseif ($appId <= 0 && $id <= 0) {
                    throw new RuntimeException('Pick an existing client from the list.');
                }

                $payload = [
                    'application_id' => $appId,
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                    'full_name' => $newName,
                    'job_position' => $job,
                    'total_fees' => (float) ($_POST['total_fees'] ?? 0),
                    'upfront_fees_paid' => (float) ($_POST['upfront_fees_paid'] ?? 0),
                    'upfront_fees_paid_2' => (float) ($_POST['upfront_fees_paid_2'] ?? 0),
                    'paid_after_medical' => (float) ($_POST['paid_after_medical'] ?? 0),
                    'balance_to_sultan' => '',
                    'visa_status' => trim((string) ($_POST['visa_status'] ?? '')),
                    'comment' => trim((string) ($_POST['comment'] ?? '')),
                    'highlighted' => !empty($_POST['highlighted']) ? 1 : 0,
                ];
                if (!fm_payment_record_save($conn, $action === 'add' ? 0 : $id, $payload)) {
                    throw new RuntimeException('Could not save the payment record.');
                }
                $message = $action === 'add'
                    ? 'Payment record saved. Client stays in applications; payment stays only here.'
                    : 'Payment record updated.';
                $editId = 0;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$rows = fm_payment_records_list($conn);
$editing = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int) $r['id'] === $editId) {
            $editing = $r;
            break;
        }
    }
}
$showForm = $canEdit && ($editing !== null || isset($_GET['add']));
$pickerKeep = $editing ? (int) ($editing['application_id'] ?? 0) : null;
$clients = $canEdit ? fm_payment_clients_for_picker($conn, $pickerKeep) : [];
$nextSn = 1;
foreach ($rows as $r) {
    $nextSn = max($nextSn, (int) ($r['sort_order'] ?? 0) + 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment records — Francophonie Mobility</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --pay-ink:#1e293b; --pay-muted:#64748b; --pay-line:#e2e8f0; --pay-green:#1e4d2b; --pay-cyan:#0e7490; }
        body { background:#f4f6f3; color:var(--pay-ink); }
        .page-head { background:linear-gradient(135deg,#1e4d2b,#3661B9); color:#fff; padding:1.15rem 0; margin-bottom:1rem; }
        .card-soft { border:0; border-radius:14px; box-shadow:0 1px 8px rgba(15,23,42,.07); background:#fff; }
        .hint { color:var(--pay-muted); font-size:.85rem; }
        .toolbar { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:space-between; margin-bottom:1rem; }
        .table-wrap { overflow-x:auto; }
        table.pay-table { width:100%; min-width:980px; border-collapse:separate; border-spacing:0; font-size:.86rem; }
        table.pay-table thead th {
            background:var(--pay-cyan); color:#fff; font-size:.72rem; font-weight:650;
            padding:.65rem .55rem; white-space:nowrap; border:0;
        }
        table.pay-table tbody td { padding:.7rem .55rem; border-bottom:1px solid var(--pay-line); vertical-align:middle; }
        table.pay-table tbody tr:hover { background:#f8fafc; }
        tr.row-hi { background:#fef9c3 !important; }
        .name-cell { font-weight:650; }
        .meta { font-size:.72rem; color:var(--pay-muted); }
        .money { font-variant-numeric:tabular-nums; white-space:nowrap; }
        .bal { font-weight:700; color:#9a3412; }
        .bal.zero { color:#166534; }
        .pill { display:inline-block; padding:.15rem .45rem; border-radius:999px; background:#ecfeff; color:#155e75; font-size:.7rem; font-weight:600; }
        .editor-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:.75rem; }
        @media (max-width:900px){ .editor-grid{ grid-template-columns:1fr 1fr; } }
        .editor-grid .span-6{ grid-column:span 6; }
        .editor-grid .span-5{ grid-column:span 5; }
        .editor-grid .span-4{ grid-column:span 4; }
        .editor-grid .span-3{ grid-column:span 3; }
        .editor-grid .span-2{ grid-column:span 2; }
        .editor-grid .span-12{ grid-column:1 / -1; }
        @media (max-width:900px){
            .editor-grid .span-6,.editor-grid .span-5,.editor-grid .span-4,.editor-grid .span-3,.editor-grid .span-2{ grid-column:span 1; }
            .editor-grid .span-12{ grid-column:1 / -1; }
        }
        .mode-tabs { display:flex; gap:.35rem; flex-wrap:wrap; }
        .mode-tabs label {
            border:1px solid var(--pay-line); border-radius:999px; padding:.35rem .8rem;
            font-size:.82rem; cursor:pointer; background:#fff;
        }
        .mode-tabs input { display:none; }
        .mode-tabs label:has(input:checked) { border-color:var(--pay-green); background:#eef6f0; color:var(--pay-green); font-weight:600; }
        .client-panel[hidden], .new-panel[hidden] { display:none !important; }
    </style>
</head>
<body>
<?= $isPortal ? fm_manager_portal_nav_html('payments') : '' ?>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4" style="max-width:1180px">
        <h1 class="h4 mb-1"><i class="fas fa-money-bill-wave me-2"></i>Payment records</h1>
        <p class="mb-0 opacity-75 small">
            Pick clients from the Francophonie Mobility database. Payment figures stay only on this sheet — not on Record invoice or the client form.
        </p>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-5" style="max-width:1180px">
    <div class="toolbar">
        <div>
            <?php if (!$isPortal): ?>
            <a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <?php endif; ?>
            <span class="hint ms-2"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($canEdit && !$showForm): ?>
        <a class="btn btn-success btn-sm" href="?add=1"><i class="fas fa-plus me-1"></i> Add payment record</a>
        <?php endif; ?>
    </div>

    <?php if ($message !== ''): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($showForm): ?>
    <?php
        $isAdd = $editing === null;
        $formAppId = (int) ($editing['application_id'] ?? 0);
        $formJob = (string) ($editing['job_position'] ?? '');
        $formTotal = (string) ($editing['total_fees'] ?? '5000');
        $formUp1 = (string) ($editing['upfront_fees_paid'] ?? '0');
        $formUp2 = (string) ($editing['upfront_fees_paid_2'] ?? '0');
        $formMed = (string) ($editing['paid_after_medical'] ?? '0');
        $formBal = fm_payment_record_recalc_balance((float)$formTotal, (float)$formUp1, (float)$formUp2, (float)$formMed);
        $formVisa = (string) ($editing['visa_status'] ?? '');
        $formComment = (string) ($editing['comment'] ?? '');
        $formHi = !empty($editing['highlighted']);
        $formSn = (int) ($editing['sort_order'] ?? $nextSn);
    ?>
    <div class="card card-soft p-3 p-md-4 mb-3">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1"><?= $isAdd ? 'Add payment record' : 'Edit payment record' ?></h2>
                <p class="hint mb-0">Choose an existing client, or add a missing client to the applications DB (without storing payment there).</p>
            </div>
            <a href="francophonie-mobility-payment-records.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
        </div>
        <form method="post" id="payForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $isAdd ? 'add' : 'save' ?>">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="mode-tabs mb-3">
                <label><input type="radio" name="client_mode" value="existing" <?= $isAdd || $formAppId > 0 ? 'checked' : '' ?>> Existing client</label>
                <label><input type="radio" name="client_mode" value="new" id="modeNew"> Not in list — add client</label>
            </div>

            <div class="editor-grid">
                <div class="span-6 client-panel" id="existingPanel">
                    <label class="form-label fw-semibold">Client from database *</label>
                    <select class="form-select" name="application_id" id="applicationSelect">
                        <option value="">Select client…</option>
                        <?php if ($formAppId > 0 && $editing): ?>
                        <option value="<?= $formAppId ?>" selected>
                            <?= htmlspecialchars((string) ($editing['app_name'] ?: $editing['full_name'])) ?>
                            <?php if (!empty($editing['app_reference'])): ?> · <?= htmlspecialchars((string) $editing['app_reference']) ?><?php endif; ?>
                        </option>
                        <?php endif; ?>
                        <?php foreach ($clients as $c): ?>
                        <option
                            value="<?= (int) $c['id'] ?>"
                            data-job="<?= htmlspecialchars($c['profession'] !== '' && strtoupper($c['profession']) !== 'N/A' ? $c['profession'] : fm_job_offer_label($c['job_offer']), ENT_QUOTES, 'UTF-8') ?>"
                            <?= $formAppId === (int) $c['id'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($c['name']) ?> · <?= htmlspecialchars($c['reference_id']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="span-6 new-panel" id="newPanel" hidden>
                    <label class="form-label fw-semibold">New client full name *</label>
                    <input class="form-control" name="new_full_name" id="newFullName" maxlength="200" placeholder="As on passport">
                    <div class="hint mt-1">Creates a stub application only. Payment amounts are saved here, not on the application.</div>
                </div>

                <div class="span-4">
                    <label class="form-label fw-semibold">Job position</label>
                    <input class="form-control" name="job_position" id="jobPosition" value="<?= htmlspecialchars($formJob) ?>">
                </div>
                <div class="span-2" style="grid-column:span 2">
                    <label class="form-label fw-semibold">S/N</label>
                    <input class="form-control" name="sort_order" type="number" value="<?= $formSn ?>">
                </div>
                <div class="span-3">
                    <label class="form-label fw-semibold">Total fees</label>
                    <input class="form-control money-in" name="total_fees" type="number" step="0.01" value="<?= htmlspecialchars($formTotal) ?>">
                </div>
                <div class="span-3">
                    <label class="form-label fw-semibold">Upfront paid</label>
                    <input class="form-control money-in" name="upfront_fees_paid" type="number" step="0.01" value="<?= htmlspecialchars($formUp1) ?>">
                </div>
                <div class="span-3">
                    <label class="form-label fw-semibold">Upfront paid (2)</label>
                    <input class="form-control money-in" name="upfront_fees_paid_2" type="number" step="0.01" value="<?= htmlspecialchars($formUp2) ?>">
                </div>
                <div class="span-3">
                    <label class="form-label fw-semibold">After medical</label>
                    <input class="form-control money-in" name="paid_after_medical" type="number" step="0.01" value="<?= htmlspecialchars($formMed) ?>">
                </div>
                <div class="span-3">
                    <label class="form-label fw-semibold">Balance (auto)</label>
                    <input class="form-control" id="balancePreview" value="<?= number_format($formBal, 2, '.', '') ?>" readonly>
                </div>
                <div class="span-4">
                    <label class="form-label fw-semibold">Visa status</label>
                    <input class="form-control" name="visa_status" value="<?= htmlspecialchars($formVisa) ?>" placeholder="Approved / …">
                </div>
                <div class="span-5" style="grid-column:span 5">
                    <label class="form-label fw-semibold">Comment</label>
                    <input class="form-control" name="comment" value="<?= htmlspecialchars($formComment) ?>">
                </div>
                <div class="span-12 d-flex flex-wrap gap-3 align-items-center">
                    <label class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="highlighted" value="1" <?= $formHi ? 'checked' : '' ?>>
                        Highlight this row
                    </label>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Save payment record</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card card-soft">
        <div class="table-wrap">
            <table class="pay-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Client</th>
                        <th>Job</th>
                        <th>Total</th>
                        <th>Upfront</th>
                        <th>Upfront 2</th>
                        <th>After medical</th>
                        <th>Balance</th>
                        <th>Visa</th>
                        <th>Comment</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $canEdit ? 11 : 10 ?>" class="text-muted p-4">No payment records yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <?php
                        $bal = (float) ($r['balance_to_sultan'] ?? 0);
                        $displayName = trim((string) ($r['app_name'] ?? '')) ?: (string) ($r['full_name'] ?? '');
                    ?>
                    <tr class="<?= !empty($r['highlighted']) ? 'row-hi' : '' ?>">
                        <td><?= (int) ($r['sort_order'] ?? 0) ?></td>
                        <td>
                            <div class="name-cell"><?= htmlspecialchars($displayName) ?></div>
                            <?php if (!empty($r['app_reference'])): ?>
                            <div class="meta"><code><?= htmlspecialchars((string) $r['app_reference']) ?></code>
                                <?php if (!empty($r['app_status'])): ?>
                                <span class="pill"><?= htmlspecialchars(fm_application_status_label((string) $r['app_status'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php elseif (empty($r['application_id'])): ?>
                            <div class="meta text-warning">Not linked to applications DB</div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($r['job_position'] ?? '')) ?></td>
                        <td class="money"><?= number_format((float) $r['total_fees'], 2) ?></td>
                        <td class="money"><?= number_format((float) $r['upfront_fees_paid'], 2) ?></td>
                        <td class="money"><?= number_format((float) $r['upfront_fees_paid_2'], 2) ?></td>
                        <td class="money"><?= number_format((float) $r['paid_after_medical'], 2) ?></td>
                        <td class="money bal<?= abs($bal) < 0.001 ? ' zero' : '' ?>"><?= number_format($bal, 2) ?></td>
                        <td><?= htmlspecialchars((string) ($r['visa_status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['comment'] ?? '')) ?></td>
                        <?php if ($canEdit): ?>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int) $r['id'] ?>"><i class="fas fa-pen"></i></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this payment record? The client application is kept.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php if ($showForm): ?>
<script>
(function () {
    const modes = document.querySelectorAll('input[name="client_mode"]');
    const existingPanel = document.getElementById('existingPanel');
    const newPanel = document.getElementById('newPanel');
    const select = document.getElementById('applicationSelect');
    const job = document.getElementById('jobPosition');
    const moneyIns = document.querySelectorAll('.money-in');
    const bal = document.getElementById('balancePreview');

    function syncMode() {
        const mode = (document.querySelector('input[name="client_mode"]:checked') || {}).value || 'existing';
        const isNew = mode === 'new';
        existingPanel.hidden = isNew;
        newPanel.hidden = !isNew;
        if (select) select.required = !isNew;
        const nn = document.getElementById('newFullName');
        if (nn) nn.required = isNew;
    }
    modes.forEach(function (el) { el.addEventListener('change', syncMode); });
    syncMode();

    if (select && job) {
        select.addEventListener('change', function () {
            const opt = select.options[select.selectedIndex];
            if (opt && opt.dataset.job && !job.value) job.value = opt.dataset.job;
        });
    }

    function recalc() {
        const nums = Array.from(moneyIns).map(function (el) { return parseFloat(el.value || '0') || 0; });
        const total = nums[0] || 0, up1 = nums[1] || 0, up2 = nums[2] || 0, med = nums[3] || 0;
        if (bal) bal.value = (total - up1 - up2 - med).toFixed(2);
    }
    moneyIns.forEach(function (el) { el.addEventListener('input', recalc); });
    recalc();
})();
</script>
<?php endif; ?>
</body>
</html>

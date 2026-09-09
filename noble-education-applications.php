<?php
/**
 * noble-education-applications.php — Admin management for Noble Education Group — Canada.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/noble_education_schema.php';
require_once __DIR__ . '/helpers/noble_education_files.php';
require_once __DIR__ . '/helpers/noble_education_notify.php';
require_once __DIR__ . '/helpers/secure_file.php';

neg_ensure_schema($conn);

$adminId = $_SESSION['id'] ?? $_SESSION['admin_id'] ?? null;
$roleRaw = trim((string) ($_SESSION['role'] ?? ''));
$roleKey = strtolower(preg_replace('/\s+/', ' ', $roleRaw) ?? $roleRaw);
$roleOk = in_array($roleKey, ['superadmin', 'staff'], true)
    || in_array($roleRaw, ['superadmin', 'staff'], true);

if (empty($adminId) || !$roleOk) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Please refresh and log in again.']);
        exit;
    }
    header('Location: admin-login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $respond = static function (bool $ok, array $extra = [], int $code = 200): void {
        http_response_code($code);
        echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])
        || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $respond(false, ['message' => 'Invalid CSRF token. Refresh the page and try again.'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
    if ($appId <= 0) {
        $respond(false, ['message' => 'Invalid application ID']);
    }

    $stmt = $conn->prepare('SELECT * FROM noble_education_applications WHERE id = ? LIMIT 1');
    if (!$stmt) {
        $respond(false, ['message' => 'Database error'], 500);
    }
    $stmt->bind_param('i', $appId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$app) {
        $respond(false, ['message' => 'Application not found']);
    }

    if ($action === 'set_status') {
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['pending', 'under_review', 'approved', 'rejected'], true)) {
            $respond(false, ['message' => 'Invalid status']);
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        $emailSent = false;
        $emailError = '';

        $upd = $conn->prepare('UPDATE noble_education_applications SET status = ?, admin_notes = ? WHERE id = ?');
        if (!$upd) {
            $respond(false, ['message' => 'Could not prepare status update'], 500);
        }
        $upd->bind_param('ssi', $status, $note, $appId);
        if (!$upd->execute()) {
            $upd->close();
            $respond(false, ['message' => 'Could not update status: ' . $conn->error], 500);
        }
        $upd->close();

        if ($status === 'approved') {
            $result = neg_send_approval_email($app);
            $emailSent = !empty($result['ok']);
            if ($emailSent) {
                $conn->query('UPDATE noble_education_applications SET approval_email_sent_at = NOW() WHERE id = ' . (int) $appId);
            } else {
                $emailError = (string) ($result['error'] ?? 'Email send failed');
            }
        }

        $msg = 'Status updated successfully.';
        if ($status === 'approved') {
            $msg = $emailSent
                ? 'Approved and admissions email sent to ujeanmethode@gmail.com (CC included).'
                : ('Approved, but email failed: ' . ($emailError !== '' ? $emailError : 'unknown error'));
        }

        $respond(true, [
            'message' => $msg,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]);
    }

    if ($action === 'resend_approval_email') {
        $result = neg_send_approval_email($app);
        if (!empty($result['ok'])) {
            $conn->query('UPDATE noble_education_applications SET approval_email_sent_at = NOW() WHERE id = ' . (int) $appId);
            $respond(true, ['message' => 'Admissions email resent successfully.']);
        }
        $respond(false, ['message' => 'Email failed: ' . ($result['error'] ?? 'unknown error')], 500);
    }

    if ($action === 'delete_application') {
        $typed = trim((string) ($_POST['confirm_reference'] ?? ''));
        if ($typed === '' || $typed !== (string) ($app['reference_id'] ?? '')) {
            $respond(false, ['message' => 'Reference ID does not match. Deletion cancelled.']);
        }

        foreach (['passport_file', 'high_school_certificate_file', 'transcripts_file'] as $col) {
            $abs = neg_abs_upload_path((string) ($app[$col] ?? ''));
            if ($abs !== null) {
                @unlink($abs);
            }
        }
        foreach (neg_decode_other_docs(isset($app['other_docs_json']) ? (string) $app['other_docs_json'] : null) as $rel) {
            $abs = neg_abs_upload_path($rel);
            if ($abs !== null) {
                @unlink($abs);
            }
        }

        $del = $conn->prepare('DELETE FROM noble_education_applications WHERE id = ? LIMIT 1');
        if (!$del) {
            $respond(false, ['message' => 'Could not delete application'], 500);
        }
        $del->bind_param('i', $appId);
        $del->execute();
        $del->close();
        $respond(true, ['message' => 'Application deleted.']);
    }

    $respond(false, ['message' => 'Unknown action']);
}

$status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];
$types = '';

if ($status_filter !== 'all' && in_array($status_filter, ['pending', 'under_review', 'approved', 'rejected'], true)) {
    $where[] = 'status = ?';
    $params[] = $status_filter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR reference_id LIKE ? OR agent_name LIKE ? OR program_of_interest LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$sql = 'SELECT * FROM noble_education_applications WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 300';
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['pending' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
$cr = $conn->query('SELECT status, COUNT(*) c FROM noble_education_applications GROUP BY status');
if ($cr) {
    while ($r = $cr->fetch_assoc()) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $counts['total'] += (int) $r['c'];
    }
}

$viewModel = [];
foreach ($apps as $a) {
    $passportRel = pcvc_norm_upload_rel_path((string) ($a['passport_file'] ?? ''));
    $hsRel = pcvc_norm_upload_rel_path((string) ($a['high_school_certificate_file'] ?? ''));
    $trRel = pcvc_norm_upload_rel_path((string) ($a['transcripts_file'] ?? ''));
    $others = neg_decode_other_docs(isset($a['other_docs_json']) ? (string) $a['other_docs_json'] : null);
    $otherLinks = [];
    foreach ($others as $i => $rel) {
        $otherLinks[] = [
            'label' => 'Other document ' . ($i + 1),
            'view' => pcvc_secure_file_url($rel, ['inline' => true]),
            'dl' => pcvc_secure_file_url($rel),
        ];
    }

    $viewModel[(int) $a['id']] = [
        'id' => (int) $a['id'],
        'full_name' => $a['full_name'] ?? '',
        'reference_id' => $a['reference_id'] ?? '',
        'email' => $a['email'] ?? '',
        'phone' => trim('+' . ($a['phone_area_code'] ?? '') . ' ' . ($a['phone_number'] ?? '')),
        'level' => neg_level_label((string) ($a['level_of_study'] ?? '')),
        'program' => $a['program_of_interest'] ?? '',
        'level_program' => neg_level_program_label($a),
        'agent_name' => $a['agent_name'] ?? '',
        'status' => $a['status'] ?? 'pending',
        'notes' => $a['admin_notes'] ?? '',
        'created' => $a['created_at'] ?? '',
        'approval_email_sent_at' => $a['approval_email_sent_at'] ?? '',
        'passport_view' => $passportRel !== '' ? pcvc_secure_file_url($passportRel, ['inline' => true]) : '',
        'passport_dl' => $passportRel !== '' ? pcvc_secure_file_url($passportRel) : '',
        'hs_view' => $hsRel !== '' ? pcvc_secure_file_url($hsRel, ['inline' => true]) : '',
        'hs_dl' => $hsRel !== '' ? pcvc_secure_file_url($hsRel) : '',
        'tr_view' => $trRel !== '' ? pcvc_secure_file_url($trRel, ['inline' => true]) : '',
        'tr_dl' => $trRel !== '' ? pcvc_secure_file_url($trRel) : '',
        'other_docs' => $otherLinks,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Noble Education Group — Canada Applications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f4f6f8; font-family:system-ui,sans-serif; }
        .stat-card { background:#fff; border-radius:10px; padding:12px; text-align:center; border:1px solid #e2e8f0; }
        .badge-pending { background:#fef3c7; color:#92400e; }
        .badge-under_review { background:#dbeafe; color:#1e40af; }
        .badge-approved { background:#d1fae5; color:#065f46; }
        .badge-rejected { background:#fee2e2; color:#991b1b; }
        .table-card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
        .kv td:first-child { width:38%; color:#64748b; }
    </style>
</head>
<body class="p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Noble Education Group — Canada</h1>
            <p class="text-muted small mb-0">Approving an application emails admissions with documents attached.</p>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="noble-education-request.php" target="_blank"><i class="fas fa-external-link-alt me-1"></i> Open form</a>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= (int) $counts['pending'] ?></strong><div class="small text-muted">Pending</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= (int) $counts['under_review'] ?></strong><div class="small text-muted">Under review</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= (int) $counts['approved'] ?></strong><div class="small text-muted">Approved</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= (int) $counts['total'] ?></strong><div class="small text-muted">Total</div></div></div>
    </div>

    <form class="row g-2 mb-3" method="get">
        <div class="col-md-3">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All statuses</option>
                <?php foreach (['pending','under_review','approved','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-7">
            <input type="search" name="search" class="form-control" placeholder="Search name, email, reference, agent, program…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
    </form>

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Program</th>
                        <th>Agent</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$apps): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No applications yet.</td></tr>
                <?php else: foreach ($apps as $a): $id = (int) $a['id']; ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) $a['full_name']) ?></strong><br>
                            <span class="small text-muted"><?= htmlspecialchars((string) $a['email']) ?></span><br>
                            <code class="small"><?= htmlspecialchars((string) $a['reference_id']) ?></code>
                        </td>
                        <td class="small"><?= htmlspecialchars(neg_level_program_label($a)) ?></td>
                        <td class="small"><?= htmlspecialchars((string) $a['agent_name']) ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars((string) $a['status']) ?>"><?= htmlspecialchars(str_replace('_',' ', (string) $a['status'])) ?></span></td>
                        <td class="small"><?= htmlspecialchars((string) $a['created_at']) ?></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" onclick="viewApp(<?= $id ?>)">View</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteApplication(<?= $id ?>, <?= json_encode((string) $a['reference_id']) ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Application details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody"></div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const APPS = <?= json_encode($viewModel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const modal = new bootstrap.Modal(document.getElementById('detailModal'));

function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}
function docRow(label, view, dl) {
    return '<div class="border rounded p-2 mb-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">'
        + '<span class="text-break"><i class="fas fa-file me-2"></i>' + esc(label) + '</span>'
        + '<span class="d-flex gap-1">'
        + '<a href="' + esc(view) + '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>'
        + '<a href="' + esc(dl) + '" class="btn btn-sm btn-primary">Download</a>'
        + '</span></div>';
}
function statusBtn(id, status, label, color) {
    return '<button class="btn btn-sm btn-' + color + '" onclick="setStatus(' + id + ',\'' + status + '\')">' + esc(label) + '</button>';
}
function viewApp(id) {
    const a = APPS[id];
    if (!a) return;
    let docsHtml = '';
    if (a.passport_view) docsHtml += docRow('Passport', a.passport_view, a.passport_dl);
    if (a.hs_view) docsHtml += docRow('High School Certificate', a.hs_view, a.hs_dl);
    if (a.tr_view) docsHtml += docRow('Transcripts', a.tr_view, a.tr_dl);
    (a.other_docs || []).forEach(d => { docsHtml += docRow(d.label, d.view, d.dl); });
    if (!docsHtml) docsHtml = '<p class="text-muted small">No documents on file.</p>';

    const emailNote = a.approval_email_sent_at
        ? '<tr><td>Approval email sent</td><td>' + esc(a.approval_email_sent_at) + '</td></tr>'
        : '';

    document.getElementById('detailBody').innerHTML =
        '<div class="mb-2"><span class="badge badge-' + esc(a.status) + '">' + esc(a.status.replace(/_/g,' ')) + '</span></div>'
        + '<table class="table table-sm kv"><tbody>'
        + '<tr><td>Full name</td><td>' + esc(a.full_name) + '</td></tr>'
        + '<tr><td>Reference</td><td><code>' + esc(a.reference_id) + '</code></td></tr>'
        + '<tr><td>Email</td><td>' + esc(a.email) + '</td></tr>'
        + '<tr><td>Mobile</td><td>' + esc(a.phone) + '</td></tr>'
        + '<tr><td>Level & program</td><td>' + esc(a.level_program) + '</td></tr>'
        + '<tr><td>Agent name</td><td>' + esc(a.agent_name) + '</td></tr>'
        + '<tr><td>Submitted</td><td>' + esc(a.created) + '</td></tr>'
        + emailNote
        + (a.notes ? '<tr><td>Admin notes</td><td>' + esc(a.notes) + '</td></tr>' : '')
        + '</tbody></table>'
        + '<h6 class="mt-3">Documents</h6>' + docsHtml
        + '<h6 class="mt-3">Update status</h6>'
        + '<div class="d-flex flex-wrap gap-2 mb-2">'
        + statusBtn(a.id, 'under_review', 'Under Review', 'warning')
        + statusBtn(a.id, 'approved', 'Approve & Email', 'success')
        + statusBtn(a.id, 'rejected', 'Reject', 'danger')
        + statusBtn(a.id, 'pending', 'Reset to Pending', 'secondary')
        + '</div>'
        + (a.status === 'approved'
            ? '<button class="btn btn-sm btn-outline-success" onclick="resendEmail(' + a.id + ')"><i class="fas fa-envelope me-1"></i> Resend admissions email</button>'
            : '');
    modal.show();
}
function setStatus(id, status) {
    let note = '';
    if (status === 'rejected' || status === 'under_review') {
        const typed = prompt('Optional internal note:', '');
        if (typed === null) return;
        note = typed;
    }
    const label = status === 'approved'
        ? 'Approve and send admissions email to ujeanmethode@gmail.com (CC: toukipi2023@gmail.com, infos@visaconsultantcanada.com)?'
        : ('Set status to "' + status.replace(/_/g, ' ') + '"?');
    if (!confirm(label)) return;
    postAction({ action: 'set_status', application_id: id, status: status, note: note }).then(d => {
        alert(d.message || 'Updated');
        location.reload();
    }).catch(e => alert(e.message || 'Action failed'));
}
function resendEmail(id) {
    if (!confirm('Resend admissions email with attachments?')) return;
    postAction({ action: 'resend_approval_email', application_id: id }).then(d => {
        alert(d.message || 'Sent');
        location.reload();
    }).catch(e => alert(e.message || 'Failed'));
}
function deleteApplication(id, referenceId) {
    const typed = prompt('Delete application ' + referenceId + '?\nType the reference ID to confirm:');
    if (typed === null) return;
    postAction({ action: 'delete_application', application_id: id, confirm_reference: typed }).then(d => {
        alert(d.message || 'Deleted');
        location.reload();
    }).catch(e => alert(e.message || 'Delete failed'));
}
function postAction(data) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    return fetch('noble-education-applications.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    }).then(async r => {
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.success) throw new Error(d.message || 'Request failed');
        return d;
    });
}
</script>
</body>
</html>

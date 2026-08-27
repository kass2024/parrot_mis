<?php
/**
 * korea-event-applications.php — Admin management for South Korea Event Participation.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/korea_event_schema.php';
require_once __DIR__ . '/helpers/korea_event_files.php';
require_once __DIR__ . '/helpers/korea_event_notify.php';
require_once __DIR__ . '/helpers/secure_file.php';
require_once __DIR__ . '/helpers/env_load.php';

kep_ensure_schema($conn);
xander_load_env_file();

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
    $appId  = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
    if ($appId <= 0) {
        $respond(false, ['message' => 'Invalid application ID']);
    }

    $stmt = $conn->prepare('SELECT * FROM korea_event_applications WHERE id = ? LIMIT 1');
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

        $upd = $conn->prepare('UPDATE korea_event_applications SET status = ?, admin_notes = ? WHERE id = ?');
        if (!$upd) {
            $respond(false, ['message' => 'Could not prepare status update'], 500);
        }
        $upd->bind_param('ssi', $status, $note, $appId);
        if (!$upd->execute()) {
            $upd->close();
            $respond(false, ['message' => 'Could not update status: ' . $conn->error], 500);
        }
        $upd->close();
        $respond(true, ['message' => 'Status updated successfully.']);
    }

    if ($action === 'delete_application') {
        $typed = trim((string) ($_POST['confirm_reference'] ?? ''));
        if ($typed === '' || $typed !== (string) ($app['reference_id'] ?? '')) {
            $respond(false, ['message' => 'Reference ID does not match. Deletion cancelled.']);
        }

        foreach (['passport_file', 'cv_file'] as $col) {
            $abs = kep_abs_upload_path((string) ($app[$col] ?? ''));
            if ($abs !== null) {
                @unlink($abs);
            }
        }

        $del = $conn->prepare('DELETE FROM korea_event_applications WHERE id = ? LIMIT 1');
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

$notifyEmail = kep_notify_recipient_email();
$notifyEmailOk = $notifyEmail !== '';

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
    $where[] = '(full_name LIKE ? OR email LIKE ? OR reference_id LIKE ? OR passport_number LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$sql = 'SELECT * FROM korea_event_applications WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 300';
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['pending' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
$cr = $conn->query('SELECT status, COUNT(*) c FROM korea_event_applications GROUP BY status');
if ($cr) {
    while ($r = $cr->fetch_assoc()) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $counts['total'] += (int) $r['c'];
    }
}

function kep_status_badge(string $s): string
{
    return 'badge-' . preg_replace('/[^a-z_]/', '', $s);
}

$viewModel = [];
foreach ($apps as $a) {
    $passportRel = pcvc_norm_upload_rel_path((string) ($a['passport_file'] ?? ''));
    $cvRel = pcvc_norm_upload_rel_path((string) ($a['cv_file'] ?? ''));
    $dob = (string) ($a['date_of_birth'] ?? '');
    if ($dob !== '' && $dob !== '0000-00-00') {
        $ts = strtotime($dob);
        $dob = $ts ? date('j M Y', $ts) : $dob;
    } else {
        $dob = '';
    }
    $viewModel[(int) $a['id']] = [
        'id'            => (int) $a['id'],
        'reference_id'  => $a['reference_id'] ?? '',
        'full_name'     => $a['full_name'] ?? '',
        'email'         => $a['email'] ?? '',
        'phone'         => trim('+' . ($a['phone_area_code'] ?? '') . ' ' . ($a['phone_number'] ?? '')),
        'messaging_app' => ucfirst((string) ($a['messaging_app'] ?? 'whatsapp')),
        'passport'      => $a['passport_number'] ?? '',
        'dob'           => $dob,
        'gender'        => kep_gender_label((string) ($a['gender'] ?? '')),
        'nationality'   => $a['nationality'] ?? '',
        'residence'     => $a['country_of_residence'] ?? '',
        'occupation'    => $a['occupation'] ?? '',
        'organization'  => $a['organization'] ?? '',
        'event_name'    => $a['event_name'] ?? '',
        'purpose'       => $a['participation_purpose'] ?? '',
        'status'        => $a['status'] ?? 'pending',
        'notes'         => $a['admin_notes'] ?? '',
        'created'       => !empty($a['created_at']) ? date('M j, Y H:i', strtotime($a['created_at'])) : '',
        'passport_view' => $passportRel !== '' ? pcvc_secure_file_url($passportRel, ['inline' => true]) : '',
        'passport_dl'   => $passportRel !== '' ? pcvc_secure_file_url($passportRel) : '',
        'cv_view'       => $cvRel !== '' ? pcvc_secure_file_url($cvRel, ['inline' => true]) : '',
        'cv_dl'         => $cvRel !== '' ? pcvc_secure_file_url($cvRel) : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>South Korea Event Participation Applications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --kep-red:#CD2E3A; --kep-blue:#0047A0; }
        body { background:#f4f6f8; -webkit-text-size-adjust:100%; }
        .page-head {
            background:linear-gradient(135deg,var(--kep-red),var(--kep-blue));
            color:#fff; padding:clamp(1rem,4vw,1.75rem) 0; margin-bottom:1rem;
        }
        .stat-card {
            background:#fff; border-radius:10px; padding:.85rem; text-align:center;
            border:1px solid #e2e8f0; height:100%;
        }
        .stat-card strong { font-size:clamp(1.25rem,4vw,1.5rem); color:var(--kep-blue); display:block; }
        .badge-pending { background:#fef3c7; color:#92400e; }
        .badge-under_review { background:#dbeafe; color:#1e40af; }
        .badge-approved { background:#d1fae5; color:#065f46; }
        .badge-rejected { background:#fee2e2; color:#991b1b; }
        .app-card {
            background:#fff; border:1px solid #e2e8f0; border-radius:12px;
            padding:1rem; margin-bottom:.75rem; box-shadow:0 1px 4px rgba(0,0,0,.04);
        }
        .app-card .meta { font-size:.85rem; color:#64748b; }
        .table-desktop { display:none; }
        .cards-mobile { display:block; }
        @media (min-width: 768px) {
            .table-desktop { display:block; }
            .cards-mobile { display:none; }
        }
        @media (max-width: 575.98px) { .modal-dialog { margin:.5rem; } }
        .kv td { padding:6px 8px; border-bottom:1px solid #eef2f6; font-size:.9rem; vertical-align:top; }
        .kv td:first-child { color:#64748b; width:42%; }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <h1 class="h4 h3-md mb-1"><i class="fas fa-flag me-2"></i>South Korea Event Participation</h1>
        <p class="mb-0 opacity-75 small">Review applications, passport scans, and CVs</p>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-5">
    <?php if (!$notifyEmailOk): ?>
    <div class="alert alert-warning py-2 small">
        <i class="fas fa-exclamation-triangle me-1"></i>
        Optional: set <code>KOREA_EVENT_NOTIFY_EMAIL</code> in <code>.env</code> to receive new applications with passport and CV attached.
    </div>
    <?php endif; ?>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= $counts['total'] ?></strong><span class="small text-muted">Total</span></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= $counts['pending'] ?></strong><span class="small text-muted">Pending</span></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= $counts['under_review'] ?></strong><span class="small text-muted">Review</span></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><strong><?= $counts['approved'] ?></strong><span class="small text-muted">Approved</span></div></div>
    </div>

    <form class="row g-2 mb-3" method="get">
        <div class="col-12 col-md-4">
            <select name="status" class="form-select">
                <option value="all">All statuses</option>
                <?php foreach (['pending','under_review','approved','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <input type="search" name="search" class="form-control" placeholder="Search name, email, reference, passport…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
        </div>
    </form>

    <div class="cards-mobile">
        <?php if (!$apps): ?>
            <p class="text-center text-muted py-4">No applications found.</p>
        <?php else: foreach ($apps as $a): ?>
            <div class="app-card">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars((string) $a['full_name']) ?></div>
                        <code class="small"><?= htmlspecialchars((string) $a['reference_id']) ?></code>
                    </div>
                    <span class="badge <?= kep_status_badge((string) $a['status']) ?>"><?= ucwords(str_replace('_',' ',(string) $a['status'])) ?></span>
                </div>
                <div class="meta mb-2">
                    <?= htmlspecialchars((string) $a['email']) ?><br>
                    <?= htmlspecialchars((string) $a['event_name']) ?> · <?= date('M j, Y', strtotime((string) $a['created_at'])) ?>
                </div>
                <button class="btn btn-outline-primary btn-sm w-100" onclick="viewApp(<?= (int)$a['id'] ?>)">
                    <i class="fas fa-eye me-1"></i> View &amp; manage
                </button>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card border-0 shadow-sm table-desktop">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th>
                        <th>Candidate</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$apps): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No applications found.</td></tr>
                <?php else: foreach ($apps as $a): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $a['reference_id']) ?></code></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) $a['full_name']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string) $a['email']) ?></div>
                        </td>
                        <td class="small"><?= htmlspecialchars((string) $a['event_name']) ?></td>
                        <td><span class="badge <?= kep_status_badge((string) $a['status']) ?>"><?= ucwords(str_replace('_',' ',(string) $a['status'])) ?></span></td>
                        <td class="small"><?= date('M j, Y', strtotime((string) $a['created_at'])) ?></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" onclick="viewApp(<?= (int)$a['id'] ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" title="Delete application"
                                    onclick="deleteApplication(<?= (int)$a['id'] ?>, <?= htmlspecialchars(json_encode($a['reference_id']), ENT_QUOTES) ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Application</h5>
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

function viewApp(id) {
    const a = APPS[id];
    if (!a) return;
    let docsHtml = '';
    if (a.passport_view) docsHtml += docRow('Passport Scan', a.passport_view, a.passport_dl);
    if (a.cv_view) docsHtml += docRow('CV / Resume', a.cv_view, a.cv_dl);
    if (!docsHtml) docsHtml = '<p class="text-muted small">No documents on file.</p>';

    const notes = a.notes ? '<tr><td>Admin Notes</td><td>' + esc(a.notes) + '</td></tr>' : '';
    const purpose = a.purpose ? '<tr><td>Purpose</td><td>' + esc(a.purpose) + '</td></tr>' : '';

    document.getElementById('detailBody').innerHTML =
        '<div class="mb-2"><span class="badge badge-' + esc(a.status) + '">' + esc(a.status.replace(/_/g,' ')) + '</span></div>'
        + '<table class="table table-sm kv"><tbody>'
        + '<tr><td>Full Name</td><td>' + esc(a.full_name) + '</td></tr>'
        + '<tr><td>Reference</td><td><code>' + esc(a.reference_id) + '</code></td></tr>'
        + '<tr><td>Date of Birth</td><td>' + esc(a.dob) + '</td></tr>'
        + '<tr><td>Gender</td><td>' + esc(a.gender) + '</td></tr>'
        + '<tr><td>Nationality</td><td>' + esc(a.nationality) + '</td></tr>'
        + '<tr><td>Residence</td><td>' + esc(a.residence) + '</td></tr>'
        + '<tr><td>Passport No.</td><td>' + esc(a.passport) + '</td></tr>'
        + '<tr><td>Phone</td><td>' + esc(a.phone) + ' (' + esc(a.messaging_app) + ')</td></tr>'
        + '<tr><td>Email</td><td>' + esc(a.email) + '</td></tr>'
        + '<tr><td>Occupation</td><td>' + esc(a.occupation) + '</td></tr>'
        + '<tr><td>Organization</td><td>' + esc(a.organization) + '</td></tr>'
        + '<tr><td>Event</td><td>' + esc(a.event_name) + '</td></tr>'
        + purpose
        + '<tr><td>Submitted</td><td>' + esc(a.created) + '</td></tr>'
        + notes
        + '</tbody></table>'
        + '<h6 class="mt-3">Documents</h6>' + docsHtml
        + '<h6 class="mt-3">Update Status</h6>'
        + '<div class="d-flex flex-wrap gap-2">'
        + statusBtn(a.id, 'under_review', 'Under Review', 'warning')
        + statusBtn(a.id, 'approved', 'Approve', 'success')
        + statusBtn(a.id, 'rejected', 'Reject', 'danger')
        + statusBtn(a.id, 'pending', 'Reset to Pending', 'secondary')
        + '</div>';
    modal.show();
}

function docRow(label, view, dl) {
    return '<div class="border rounded p-2 mb-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">'
        + '<span class="text-break"><i class="fas fa-file me-2"></i>' + esc(label) + '</span>'
        + '<span class="d-flex gap-1">'
        + '<a href="' + esc(view) + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View</a>'
        + '<a href="' + esc(dl) + '" class="btn btn-sm btn-primary"><i class="fas fa-download"></i> Download</a>'
        + '</span></div>';
}

function statusBtn(id, status, label, color) {
    return '<button class="btn btn-sm btn-' + color + '" onclick="setStatus(' + id + ',\'' + status + '\')">' + esc(label) + '</button>';
}

function setStatus(id, status) {
    let note = '';
    if (status === 'rejected' || status === 'under_review') {
        const typed = prompt('Optional internal note (saved on the application):', '');
        if (typed === null) return;
        note = typed;
    }
    if (!confirm('Set status to "' + status.replace(/_/g, ' ') + '"?')) return;

    postAction({ action: 'set_status', application_id: id, status: status, note: note }).then(d => {
        alert(d.message || 'Status updated successfully.');
        location.reload();
    }).catch(e => alert(e.message || 'Action failed'));
}

function deleteApplication(id, referenceId) {
    const typed = prompt('Delete application ' + referenceId + '?\n\nThis removes the application and its documents.\nType the reference ID to confirm:');
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
    return fetch('korea-event-applications.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
        .then(async r => {
            const text = await r.text();
            let d;
            try {
                d = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    r.status === 403 || /login/i.test(text)
                        ? 'Session expired. Refresh the dashboard and log in again.'
                        : 'Server returned an invalid response. Please refresh and try again.'
                );
            }
            if (!d.success) throw new Error(d.message || 'Request failed');
            return d;
        });
}
</script>
</body>
</html>

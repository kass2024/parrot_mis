<?php
/**
 * noble-education-applications.php — Admin management for Noble Education Group — Canada.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/noble_education_schema.php';
require_once __DIR__ . '/helpers/noble_education_files.php';
require_once __DIR__ . '/helpers/noble_education_notify.php';
require_once __DIR__ . '/helpers/secure_file.php';

neg_ensure_schema($conn);

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
$roleOk = $adminId > 0 && pcvc_can_access_sensitive_menus($conn);
if ($adminId > 0 && !$roleOk) {
    require_once __DIR__ . '/helpers/staff_level_clearance.php';
    $roleStmt = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if ($roleStmt) {
        $roleStmt->bind_param('i', $adminId);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if ($roleRow && pcvc_staff_level_clearance_is_target((string) ($roleRow['role'] ?? ''))) {
            $cleared = pcvc_staff_level_clearance_apply($conn, $adminId);
            $roleOk = in_array('noble_education', $cleared['menus'], true);
        }
    }
}

if ($adminId <= 0 || !$roleOk) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Please refresh and log in again.']);
        exit;
    }
    header('Location: ' . ($adminId <= 0 ? 'admin-login.php' : 'admin-dashboard.php'));
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
                ? 'Approved and admissions email sent to admissionspublic@nobleedugroup.ca (CC included).'
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

    if ($action === 'update_application') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $area = preg_replace('/\D+/', '', (string) ($_POST['phone_area_code'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone_number'] ?? '')) ?? '';
        $level = trim((string) ($_POST['level_of_study'] ?? ''));
        $program = trim((string) ($_POST['program_of_interest'] ?? ''));
        $agent = trim((string) ($_POST['agent_name'] ?? ''));
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));

        if ($fullName === '' || mb_strlen($fullName) > 200) {
            $respond(false, ['message' => 'Enter the student full name.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $respond(false, ['message' => 'Enter a valid email address.']);
        }
        if ($phone === '' || strlen($area) > 10 || strlen($phone) > 40) {
            $respond(false, ['message' => 'Enter the mobile number.']);
        }
        $levels = neg_level_options();
        $currentLevel = (string) ($app['level_of_study'] ?? '');
        if (!isset($levels[$level]) && $level !== $currentLevel) {
            $respond(false, ['message' => 'Choose a level of study.']);
        }
        if ($program === '' || mb_strlen($program) > 255) {
            $respond(false, ['message' => 'Enter the program of interest.']);
        }
        if (mb_strlen($agent) > 200) {
            $respond(false, ['message' => 'Agent name is too long.']);
        }

        $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = (string) ($nameParts[0] ?? $fullName);
        $lastName = (string) ($nameParts[1] ?? '');

        $owned = [];
        foreach (['passport_file', 'high_school_certificate_file', 'transcripts_file'] as $col) {
            $rel = neg_safe_rel_path((string) ($app[$col] ?? ''));
            if ($rel !== '') {
                $owned[$rel] = true;
            }
        }
        $otherExisting = neg_list_other_doc_paths(isset($app['other_docs_json']) ? (string) $app['other_docs_json'] : null);
        foreach ($otherExisting as $rel) {
            $owned[$rel] = true;
        }

        $created = [];
        $unlink = [];
        try {
            $slot = static function (string $column, string $fileKey, string $removeKey) use ($app, &$created, &$unlink): string {
                $current = neg_safe_rel_path((string) ($app[$column] ?? ''));
                $upload = $_FILES[$fileKey] ?? null;
                if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $stored = neg_store_uploaded_file($upload, $fileKey);
                    $created[] = $stored;
                    if ($current !== '' && $current !== $stored) {
                        $unlink[] = $current;
                    }
                    return $stored;
                }
                if ((string) ($_POST[$removeKey] ?? '') === '1') {
                    if ($current !== '') {
                        $unlink[] = $current;
                    }
                    return '';
                }
                return $current;
            };

            $passport = $slot('passport_file', 'passport_file', 'remove_passport');
            $hs = $slot('high_school_certificate_file', 'high_school_certificate_file', 'remove_high_school');
            $transcripts = $slot('transcripts_file', 'transcripts_file', 'remove_transcripts');

            $removeOther = array_fill_keys(array_map('strval', (array) ($_POST['remove_other'] ?? [])), true);
            $keptOther = [];
            foreach ($otherExisting as $rel) {
                if (isset($removeOther[$rel])) {
                    $unlink[] = $rel;
                    continue;
                }
                $keptOther[] = $rel;
            }

            $otherUploads = $_FILES['other_files'] ?? null;
            if (is_array($otherUploads) && isset($otherUploads['name']) && is_array($otherUploads['name'])) {
                $count = count($otherUploads['name']);
                for ($i = 0; $i < $count; $i++) {
                    $one = [
                        'name' => $otherUploads['name'][$i] ?? '',
                        'type' => $otherUploads['type'][$i] ?? '',
                        'tmp_name' => $otherUploads['tmp_name'][$i] ?? '',
                        'error' => $otherUploads['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $otherUploads['size'][$i] ?? 0,
                    ];
                    if ((int) $one['error'] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $stored = neg_store_uploaded_file($one, 'other');
                    $created[] = $stored;
                    $keptOther[] = $stored;
                }
            }
        } catch (Throwable $e) {
            foreach ($created as $rel) {
                $abs = neg_abs_upload_path($rel);
                if ($abs !== null) {
                    @unlink($abs);
                }
            }
            $respond(false, ['message' => $e->getMessage()]);
        }

        $otherJson = $keptOther !== [] ? json_encode(array_values(array_unique($keptOther)), JSON_UNESCAPED_UNICODE) : '';
        $upd = $conn->prepare(
            'UPDATE noble_education_applications SET
                full_name = ?, first_name = ?, last_name = ?, email = ?,
                phone_area_code = ?, phone_number = ?, level_of_study = ?, program_of_interest = ?,
                agent_name = ?, admin_notes = ?,
                passport_file = ?, high_school_certificate_file = ?, transcripts_file = ?, other_docs_json = ?
             WHERE id = ?'
        );
        if (!$upd) {
            $respond(false, ['message' => 'Could not prepare the update'], 500);
        }
        $upd->bind_param(
            'ssssssssssssssi',
            $fullName,
            $firstName,
            $lastName,
            $email,
            $area,
            $phone,
            $level,
            $program,
            $agent,
            $notes,
            $passport,
            $hs,
            $transcripts,
            $otherJson,
            $appId
        );
        if (!$upd->execute()) {
            $upd->close();
            foreach ($created as $rel) {
                $abs = neg_abs_upload_path($rel);
                if ($abs !== null) {
                    @unlink($abs);
                }
            }
            $respond(false, ['message' => 'Could not save the application'], 500);
        }
        $upd->close();

        foreach (array_unique($unlink) as $rel) {
            if (!isset($owned[$rel])) {
                continue;
            }
            if (in_array($rel, [$passport, $hs, $transcripts], true) || in_array($rel, $keptOther, true)) {
                continue;
            }
            $abs = neg_abs_upload_path($rel);
            if ($abs !== null) {
                @unlink($abs);
            }
        }

        $respond(true, ['message' => 'Application updated.']);
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
    $others = neg_list_other_doc_paths(isset($a['other_docs_json']) ? (string) $a['other_docs_json'] : null);
    $otherLinks = [];
    foreach ($others as $i => $rel) {
        $exists = neg_abs_upload_path($rel) !== null;
        $otherLinks[] = [
            'label' => 'Other document ' . ($i + 1),
            'path' => $rel,
            'view' => $exists ? pcvc_secure_file_url($rel, ['inline' => true]) : '',
            'dl' => $exists ? pcvc_secure_file_url($rel) : '',
        ];
    }
    $docLink = static function (string $rel): array {
        $rel = neg_safe_rel_path($rel);
        $exists = $rel !== '' && neg_abs_upload_path($rel) !== null;
        return [
            'path' => $rel,
            'view' => $exists ? pcvc_secure_file_url($rel, ['inline' => true]) : '',
            'dl' => $exists ? pcvc_secure_file_url($rel) : '',
        ];
    };
    $passportDoc = $docLink((string) ($a['passport_file'] ?? ''));
    $hsDoc = $docLink((string) ($a['high_school_certificate_file'] ?? ''));
    $trDoc = $docLink((string) ($a['transcripts_file'] ?? ''));

    $viewModel[(int) $a['id']] = [
        'id' => (int) $a['id'],
        'full_name' => $a['full_name'] ?? '',
        'reference_id' => $a['reference_id'] ?? '',
        'email' => $a['email'] ?? '',
        'phone' => trim('+' . ($a['phone_area_code'] ?? '') . ' ' . ($a['phone_number'] ?? '')),
        'level_key' => (string) ($a['level_of_study'] ?? ''),
        'program' => $a['program_of_interest'] ?? '',
        'level_program' => neg_level_program_label($a),
        'agent_name' => $a['agent_name'] ?? '',
        'phone_area_code' => (string) ($a['phone_area_code'] ?? ''),
        'phone_number' => (string) ($a['phone_number'] ?? ''),
        'status' => $a['status'] ?? 'pending',
        'notes' => $a['admin_notes'] ?? '',
        'created' => $a['created_at'] ?? '',
        'approval_email_sent_at' => $a['approval_email_sent_at'] ?? '',
        'passport_view' => $passportDoc['view'],
        'passport_dl' => $passportDoc['dl'],
        'passport_path' => $passportDoc['path'],
        'hs_view' => $hsDoc['view'],
        'hs_dl' => $hsDoc['dl'],
        'hs_path' => $hsDoc['path'],
        'tr_view' => $trDoc['view'],
        'tr_dl' => $trDoc['dl'],
        'tr_path' => $trDoc['path'],
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
                    <h5 class="modal-title">View and edit application</h5>
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
const LEVELS = <?= json_encode(neg_level_options(), JSON_UNESCAPED_UNICODE) ?>;
const modal = new bootstrap.Modal(document.getElementById('detailModal'));

function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}
function docActions(view, dl) {
    if (!view && !dl) return '<span class="text-muted small">File missing</span>';
    return '<span class="d-flex gap-1">'
        + (view ? '<a href="' + esc(view) + '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>' : '')
        + (dl ? '<a href="' + esc(dl) + '" class="btn btn-sm btn-primary">Download</a>' : '')
        + '</span>';
}
function fileSlot(label, view, dl, fileName, removeName) {
    const current = (view || dl)
        ? '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' + docActions(view, dl)
            + '<label class="small mb-0"><input type="checkbox" name="' + removeName + '" value="1"> Remove</label></div>'
        : '<p class="text-muted small mb-2">No file on record.</p>';
    return '<div class="border rounded p-2 mb-2"><div class="fw-semibold mb-1">' + esc(label) + '</div>'
        + current
        + '<input type="file" class="form-control form-control-sm" name="' + fileName + '" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">'
        + '<div class="form-text">Add or replace. PDF, JPG, PNG, WEBP, DOC, DOCX — max 15MB.</div></div>';
}
function statusBtn(id, status, label, color) {
    return '<button type="button" class="btn btn-sm btn-' + color + '" onclick="setStatus(' + id + ',\'' + status + '\')">' + esc(label) + '</button>';
}
function levelOptions(selected) {
    const map = Object.assign({}, LEVELS);
    if (selected && !map[selected]) map[selected] = selected;
    return Object.keys(map).map(key => '<option value="' + esc(key) + '"' + (key === selected ? ' selected' : '') + '>' + esc(map[key]) + '</option>').join('');
}
function viewApp(id) {
    const a = APPS[id];
    if (!a) return;
    let others = '';
    (a.other_docs || []).forEach(d => {
        others += '<div class="border rounded p-2 mb-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">'
            + '<span class="text-break"><i class="fas fa-file me-2"></i>' + esc(d.label) + '</span>'
            + '<span class="d-flex gap-2 align-items-center">' + docActions(d.view, d.dl)
            + '<label class="small mb-0"><input type="checkbox" name="remove_other[]" value="' + esc(d.path) + '"> Remove</label></span></div>';
    });
    if (!others) others = '<p class="text-muted small">No extra documents.</p>';

    const emailNote = a.approval_email_sent_at
        ? '<p class="small text-muted mb-2">Approval email sent ' + esc(a.approval_email_sent_at) + '</p>'
        : '';

    document.getElementById('detailBody').innerHTML =
        '<div class="mb-2"><span class="badge badge-' + esc(a.status) + '">' + esc(String(a.status).replace(/_/g,' ')) + '</span>'
        + ' <code>' + esc(a.reference_id) + '</code></div>'
        + '<form id="negEditForm">'
        + '<input type="hidden" name="application_id" value="' + esc(a.id) + '">'
        + '<div class="row g-2">'
        + '<div class="col-md-6"><label class="form-label">Full name</label><input class="form-control" name="full_name" required maxlength="200" value="' + esc(a.full_name) + '"></div>'
        + '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required maxlength="150" value="' + esc(a.email) + '"></div>'
        + '<div class="col-md-3"><label class="form-label">Area code</label><input class="form-control" name="phone_area_code" maxlength="10" value="' + esc(a.phone_area_code) + '"></div>'
        + '<div class="col-md-3"><label class="form-label">Mobile number</label><input class="form-control" name="phone_number" required maxlength="40" value="' + esc(a.phone_number) + '"></div>'
        + '<div class="col-md-6"><label class="form-label">Level of study</label><select class="form-select" name="level_of_study" required>' + levelOptions(a.level_key) + '</select></div>'
        + '<div class="col-12"><label class="form-label">Program of interest</label><input class="form-control" name="program_of_interest" required maxlength="255" value="' + esc(a.program) + '"></div>'
        + '<div class="col-md-6"><label class="form-label">Agent name</label><input class="form-control" name="agent_name" maxlength="200" value="' + esc(a.agent_name) + '"></div>'
        + '<div class="col-md-6"><label class="form-label">Submitted</label><input class="form-control" value="' + esc(a.created) + '" readonly></div>'
        + '<div class="col-12"><label class="form-label">Admin notes</label><textarea class="form-control" name="admin_notes" rows="2">' + esc(a.notes) + '</textarea></div>'
        + '</div>'
        + '<h6 class="mt-3">Documents</h6>'
        + fileSlot('Passport', a.passport_view, a.passport_dl, 'passport_file', 'remove_passport')
        + fileSlot('High school certificate', a.hs_view, a.hs_dl, 'high_school_certificate_file', 'remove_high_school')
        + fileSlot('Transcripts', a.tr_view, a.tr_dl, 'transcripts_file', 'remove_transcripts')
        + '<div class="fw-semibold mb-1">Other documents</div>' + others
        + '<label class="form-label mt-2">Add documents</label>'
        + '<input type="file" class="form-control" name="other_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">'
        + '<div class="mt-3"><button type="submit" class="btn btn-primary" id="negSaveBtn">Save changes</button></div>'
        + '</form>'
        + emailNote
        + '<h6 class="mt-3">Update status</h6>'
        + '<div class="d-flex flex-wrap gap-2 mb-2">'
        + statusBtn(a.id, 'under_review', 'Under Review', 'warning')
        + statusBtn(a.id, 'approved', 'Approve & Email', 'success')
        + statusBtn(a.id, 'rejected', 'Reject', 'danger')
        + statusBtn(a.id, 'pending', 'Reset to Pending', 'secondary')
        + '</div>'
        + (a.status === 'approved'
            ? '<button type="button" class="btn btn-sm btn-outline-success" onclick="resendEmail(' + a.id + ')"><i class="fas fa-envelope me-1"></i> Resend admissions email</button>'
            : '');
    modal.show();
}
document.getElementById('detailBody').addEventListener('submit', function (ev) {
    if (ev.target.id !== 'negEditForm') return;
    ev.preventDefault();
    const btn = document.getElementById('negSaveBtn');
    btn.disabled = true;
    const fd = new FormData(ev.target);
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_application');
    fetch('noble-education-applications.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    }).then(async r => {
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.success) throw new Error(d.message || 'Request failed');
        alert(d.message || 'Saved');
        location.reload();
    }).catch(e => {
        btn.disabled = false;
        alert(e.message || 'Save failed');
    });
});
function setStatus(id, status) {
    let note = '';
    if (status === 'rejected' || status === 'under_review') {
        const typed = prompt('Optional internal note:', '');
        if (typed === null) return;
        note = typed;
    }
    const label = status === 'approved'
        ? 'Approve and send admissions email to admissionspublic@nobleedugroup.ca (CC: lydia@nobleedugroup.ca, infos@visaconsultantcanada.com)?'
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

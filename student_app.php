<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/job_admission_details.php';

header('Cache-Control: private, no-store');
$savingDraft = $_SERVER['REQUEST_METHOD'] === 'POST';
$draftResponse = static function (bool $success, string $message = '', int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
};
if (!isset($_SESSION['id'], $_SESSION['role'])) {
    if ($savingDraft) {
        $draftResponse(false, 'Sign in again to save your edits.', 403);
    }
    header("Location: admin-login.php");
    exit;
}

$job_id = filter_var($_GET['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$job_id) {
    if ($savingDraft) {
        $draftResponse(false, 'Job not specified.', 400);
    }
    die("Invalid access: job not specified.");
}

$adminId = (int) $_SESSION['id'];
$superadmin = (int) pcvc_current_user_is_superadmin($conn);
$_SESSION['job_admission_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['job_admission_csrf'];
session_write_close();
$job = pcvc_job_admission_load($conn, $job_id);
if (!pcvc_job_admission_can_edit($job, $adminId, (bool) $superadmin)) {
    if ($savingDraft) {
        $draftResponse(false, 'This job is no longer open for editing.', 409);
    }
    header("Location: job_todo_list.php");
    exit;
}

if ($savingDraft) {
    $request = json_decode(file_get_contents('php://input', false, null, 0, 131072), true);
    if (!is_string($request['csrf_token'] ?? null) || !hash_equals($csrf, $request['csrf_token'])) {
        $draftResponse(false, 'Reload the form to continue saving.', 403);
    }
    if (!is_array($request['fields'] ?? null) || !$request['fields']) {
        $draftResponse(false, 'No valid edits were received.', 400);
    }
    try {
        $patch = json_encode(pcvc_job_admission_validate_fields($request['fields']), JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare("UPDATE job_list SET admission_draft = JSON_MERGE_PATCH(COALESCE(admission_draft, '{}'), ?)
            WHERE id = ? AND (status IS NULL OR status = 'not_completed') AND (admin_id = ? OR ? = 1)");
        $stmt->bind_param('siii', $patch, $job_id, $adminId, $superadmin);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed === 0 && !pcvc_job_admission_can_edit(pcvc_job_admission_load($conn, $job_id), $adminId, (bool) $superadmin)) {
            $draftResponse(false, 'This job is no longer open for editing.', 409);
        }
        $draftResponse(true);
    } catch (InvalidArgumentException $error) {
        $draftResponse(false, $error->getMessage(), 422);
    } catch (Throwable $error) {
        error_log('Admission draft save failed: ' . $error->getCode());
        $draftResponse(false, 'Could not save your edits. Try again.', 500);
    }
}

$fields = pcvc_job_admission_form($job);
$limits = pcvc_job_admission_fields();
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$universities = $conn->query("SELECT id, name FROM universities ORDER BY name ASC");
$platforms = $conn->query("SELECT DISTINCT platform_name FROM platforms WHERE status='Active' ORDER BY platform_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($job['job_type']) ?></title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<style>
body{background:#f9fdf9}
.excel-header{background:#198754;color:#fff;font-weight:700;text-align:center}
.excel-cell{border:1px solid #ccc;padding:6px;min-width:180px}
.excel-form{border:2px solid #198754;background:#fff}
.draft-save{min-height:1.5rem}

/* Screenshot Area */
.paste-zone{
    border:2px dashed #198754;
    border-radius:8px;
    padding:30px;
    text-align:center;
    background:#f6fffa;
    cursor:pointer;
}
.paste-zone img{
    max-width:100%;
    margin-top:15px;
    border-radius:6px;
}
.error-text{
    color:#dc3545;
    font-weight:600;
}
</style>
</head>

<body>
<div class="container-fluid my-4">

<div class="d-flex justify-content-between mb-3">
  <h4 class="text-success">📋 <?= htmlspecialchars($job['job_type']) ?></h4>
  <a href="job_todo_list.php" id="backToJobs" class="btn btn-secondary">⬅ Back</a>
</div>

<div class="alert alert-info">
Edits are saved as a draft. A screenshot is <strong>required</strong> to complete the job.
</div>

<form id="studentAppForm" method="post" action="submit_student_app.php" enctype="multipart/form-data">
<input type="hidden" name="job_id" value="<?= $job_id ?>">
<input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

<!-- ================= TABLE ================= -->
<div class="table-responsive">
<table class="table table-bordered excel-form">
<thead>
<tr>
<?php
$headers=[
 'Applicant','Application ID','Email','Platform','Applied Country',
 'University','WhatsApp','Country','City','Status','Remarks','Subagent'
];
foreach($headers as $heading){
    echo "<th class='excel-header'>$heading</th>";
}
?>
</tr>
</thead>
<tbody>
<tr>

<td class="excel-cell">
<input name="applicant_name" class="form-control" aria-label="Applicant" maxlength="<?= $limits['applicant_name'] ?>" value="<?= $h($fields['applicant_name']) ?>" required data-draft-field>
</td>

<td class="excel-cell">
<input name="application_id" class="form-control" aria-label="Application ID" maxlength="<?= $limits['application_id'] ?>" value="<?= $h($fields['application_id']) ?>" placeholder="APP-XXXX (optional)" data-draft-field>
</td>

<td class="excel-cell">
<input type="email" name="email" class="form-control" aria-label="Email" maxlength="<?= $limits['email'] ?>" value="<?= $h($fields['email']) ?>" required data-draft-field>
</td>

<td class="excel-cell">
<input name="platform" class="form-control" aria-label="Platform" list="admissionPlatforms" maxlength="<?= $limits['platform'] ?>" value="<?= $h($fields['platform']) ?>" data-draft-field>
<datalist id="admissionPlatforms">
<?php while($p=$platforms->fetch_assoc()): ?>
<option value="<?= $h($p['platform_name']) ?>">
<?php endwhile; ?>
</datalist>
</td>

<td class="excel-cell">
<input name="destination" class="form-control" aria-label="Applied country" maxlength="<?= $limits['destination'] ?>" value="<?= $h($fields['destination']) ?>" data-draft-field>
</td>

<td class="excel-cell">
<select name="university_id" class="form-select select2" aria-label="University" data-draft-field>
<option value="">-- Select University --</option>
<?php while($u=$universities->fetch_assoc()): ?>
<option value="<?= (int) $u['id'] ?>" <?= (string) $u['id'] === $fields['university_id'] ? 'selected' : '' ?>>
<?= htmlspecialchars($u['name']) ?>
</option>
<?php endwhile; ?>
</select>
</td>

<td class="excel-cell"><input name="phone_number" type="tel" class="form-control" aria-label="WhatsApp" maxlength="<?= $limits['phone_number'] ?>" value="<?= $h($fields['phone_number']) ?>" placeholder="+250..." data-draft-field></td>
<td class="excel-cell"><input name="country" class="form-control" aria-label="Country" maxlength="<?= $limits['country'] ?>" value="<?= $h($fields['country']) ?>" data-draft-field></td>
<td class="excel-cell"><input name="city" class="form-control" aria-label="City" maxlength="<?= $limits['city'] ?>" value="<?= $h($fields['city']) ?>" data-draft-field></td>

<td class="excel-cell">
<select name="status" class="form-select" aria-label="Status" data-draft-field>
<?php foreach (pcvc_job_admission_statuses() as $status): ?>
<option <?= $status === $fields['status'] ? 'selected' : '' ?>><?= $h($status) ?></option>
<?php endforeach; ?>
</select>
</td>

<td class="excel-cell"><input name="application_remarks" class="form-control" aria-label="Remarks" maxlength="<?= $limits['application_remarks'] ?>" value="<?= $h($fields['application_remarks']) ?>" data-draft-field></td>
<td class="excel-cell"><input name="subagent" class="form-control" aria-label="Subagent" maxlength="<?= $limits['subagent'] ?>" value="<?= $h($fields['subagent']) ?>" data-draft-field></td>

</tr>
</tbody>
</table>
</div>
<div class="draft-save small mt-2">
  <span id="draftStatus" role="status" class="text-muted"><?= empty($job['admission_draft']) ? '' : 'Draft saved.' ?></span>
  <button type="button" id="retryDraftSave" class="btn btn-link btn-sm p-0 ms-2" hidden>Retry save</button>
</div>

<!-- ================= SCREENSHOT (REQUIRED) ================= -->
<div class="mt-4">
<div class="paste-zone" id="pasteZone">
<h5 class="text-success">📸 Paste or Upload Screenshot (Required)</h5>
<p class="mb-1">Press <strong>CTRL + V</strong> or click to upload</p>
<small class="text-muted">PNG / JPG only</small>
<img id="screenshotPreview" style="display:none;">
<p id="shotError" class="error-text mt-2 d-none">⚠ Screenshot is required</p>
</div>
<input type="file" id="fileInput" name="screenshot" accept="image/png,image/jpeg" hidden>
</div>

<!-- ================= SUBMIT ================= -->
<div class="text-center mt-4">
<button type="submit" id="completeJob" class="btn btn-success btn-lg px-5">
✅ Submit & Complete Job
</button>
</div>

</form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const form = document.getElementById('studentAppForm');
const pasteZone = document.getElementById('pasteZone');
const preview   = document.getElementById('screenshotPreview');
const fileInput = document.getElementById('fileInput');
const errorMsg  = document.getElementById('shotError');
const completeButton = document.getElementById('completeJob');
const draftStatus = document.getElementById('draftStatus');
const retryDraftSave = document.getElementById('retryDraftSave');
const changedFields = new Map();
const csrfToken = form.elements.csrf_token.value;

let screenshotFile = null;
let screenshotUrl = '';
let saveTimer;
let saving = null;
let submitting = false;

function showDraftStatus(message, failed = false) {
    draftStatus.textContent = message;
    draftStatus.className = failed ? 'text-danger' : 'text-muted';
    retryDraftSave.hidden = !failed;
}

function saveDraft() {
    clearTimeout(saveTimer);
    if (saving) return saving.then(ok => ok && saveDraft());
    if (changedFields.size === 0) return Promise.resolve(true);

    const fields = Object.fromEntries(changedFields);
    changedFields.clear();
    showDraftStatus('Saving draft…');

    saving = fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: csrfToken, fields})
    }).then(async response => {
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Could not save your edits.');
        }
        showDraftStatus(changedFields.size ? 'Saving draft…' : 'Draft saved.');
        return true;
    }).catch(error => {
        for (const [name, value] of Object.entries(fields)) {
            if (!changedFields.has(name)) changedFields.set(name, value);
        }
        showDraftStatus(error.message || 'Could not save your edits.', true);
        return false;
    }).finally(() => {
        saving = null;
    });
    return saving.then(ok => ok && saveDraft());
}

function queueField(input) {
    changedFields.set(input.name, input.value);
    showDraftStatus('Saving draft…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, 400);
}

form.addEventListener('input', event => {
    if (event.target.matches('[data-draft-field]')) queueField(event.target);
});
form.addEventListener('change', event => {
    if (event.target.matches('[data-draft-field]')) queueField(event.target);
});
form.addEventListener('focusout', event => {
    if (event.target.matches('[data-draft-field]')) saveDraft();
});
if (window.jQuery?.fn?.select2) {
    window.jQuery('.select2').select2({width: '100%'}).on('change', function () {
        queueField(this);
    });
}
retryDraftSave.addEventListener('click', saveDraft);
document.getElementById('backToJobs').addEventListener('click', async event => {
    const target = event.currentTarget.href;
    event.preventDefault();
    if (!submitting && await saveDraft()) location.href = target;
});
window.addEventListener('beforeunload', event => {
    if (changedFields.size || saving || submitting) {
        event.preventDefault();
        event.returnValue = '';
    }
});

function showScreenshot(file) {
    if (screenshotUrl) URL.revokeObjectURL(screenshotUrl);
    screenshotFile = file;
    screenshotUrl = URL.createObjectURL(file);
    preview.src = screenshotUrl;
    preview.style.display = 'block';
    errorMsg.classList.add('d-none');
}

document.addEventListener('paste', event => {
    if (submitting) return;
    for (const item of event.clipboardData?.items || []) {
        if (item.type.startsWith('image/')) {
            const file = item.getAsFile();
            if (file) {
                event.preventDefault();
                showScreenshot(file);
                break;
            }
        }
    }
});

/* Click to upload fallback */
pasteZone.addEventListener('click', () => {
    if (!submitting) fileInput.click();
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) showScreenshot(fileInput.files[0]);
});

/* Submit validation */
form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    if (!screenshotFile) {
        errorMsg.classList.remove('d-none');
        return;
    }

    submitting = true;
    completeButton.disabled = true;
    completeButton.textContent = 'Submitting…';
    const inputs = form.querySelectorAll('[data-draft-field]');
    try {
        if (!await saveDraft()) return;
        const formData = new FormData();
        formData.set('job_id', form.elements.job_id.value);
        formData.set('csrf_token', csrfToken);
        formData.set('screenshot', screenshotFile);
        inputs.forEach(input => { input.disabled = true; });
        const response = await fetch('submit_student_app.php', {method: 'POST', body: formData});
        const message = (await response.text()).trim();
        if (!response.ok || message !== 'success') throw new Error(message || 'Server error');
        submitting = false;
        location.href = 'job_todo_list.php';
    } catch (error) {
        alert(error.message);
    } finally {
        submitting = false;
        inputs.forEach(input => { input.disabled = false; });
        completeButton.disabled = false;
        completeButton.textContent = '✅ Submit & Complete Job';
    }
});
</script>

</body>
</html>

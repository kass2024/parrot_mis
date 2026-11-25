<?php
/**
 * Staff Smart Add — create a Francophonie Mobility candidate from the dashboard.
 * Documents can be filed into Attached or any Visa submission group at create time.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';

fm_ensure_schema($conn);
fm_ensure_documents_schema($conn);

fm_require_staff_or_manager_portal($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
$jobOffers = fm_job_offer_choices();
$pipelineStatuses = fm_client_pipeline_statuses();
$visaGroups = fm_visa_submission_groups();
$docBuckets = array_merge(
    [fm_doc_attached_group() => 'Attached (inbox)'],
    $visaGroups
);
$error = '';
$doneRef = '';
$doneId = 0;
$doneStatus = '';
$doneDocCount = 0;

/**
 * Collect uploaded files for one document group from $_FILES.
 *
 * @return list<array{path:string,name:string,size:int,mime:string,group:string}>
 */
function fm_staff_collect_group_uploads(string $group, array $fileBag): array
{
    if (!fm_is_valid_doc_group($group)) {
        throw new RuntimeException('Invalid document group.');
    }
    if (!isset($fileBag['name']) || !is_array($fileBag['name'])) {
        return [];
    }
    $out = [];
    $count = count($fileBag['name']);
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    $permDir = __DIR__ . '/uploads/francophonie_mobility';
    for ($i = 0; $i < $count; $i++) {
        $err = (int) ($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the documents in ' . fm_doc_group_label($group) . ' failed to upload.');
        }
        $tmp = (string) ($fileBag['tmp_name'][$i] ?? '');
        $orig = (string) ($fileBag['name'][$i] ?? 'document');
        $size = (int) ($fileBag['size'][$i] ?? 0);
        if ($size <= 0 || $size > 12 * 1024 * 1024) {
            throw new RuntimeException('Each document must be between 1 byte and 12 MB.');
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Documents must be PDF, JPG, PNG, DOC, or DOCX.');
        }
        if (!is_dir($permDir) && !mkdir($permDir, 0755, true) && !is_dir($permDir)) {
            throw new RuntimeException('Cannot create upload directory.');
        }
        $tag = preg_replace('/[^a-z0-9_]/', '', $group) ?: 'doc';
        $filename = 'fm_' . date('Ymd') . '_' . uniqid() . '_' . $tag . ($ext !== '' ? '.' . $ext : '');
        $dest = $permDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not store an uploaded document.');
        }
        $out[] = [
            'path' => 'uploads/francophonie_mobility/' . $filename,
            'name' => $orig,
            'size' => $size,
            'mime' => (string) ($fileBag['type'][$i] ?? ''),
            'group' => $group,
        ];
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
            throw new RuntimeException('Session expired. Refresh and try again.');
        }

        $fullName = preg_replace('/\s+/u', ' ', trim((string) ($_POST['full_name'] ?? '')));
        $emailRaw = trim((string) ($_POST['email'] ?? ''));
        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        $phone = preg_replace('/\s+/', '', trim((string) ($_POST['phone'] ?? '')));
        $dobRaw = trim((string) ($_POST['date_of_birth'] ?? ''));
        $passport = strtoupper(trim((string) ($_POST['passport_number'] ?? '')));
        $address = trim((string) ($_POST['address'] ?? ''));
        $nationality = trim((string) ($_POST['nationality'] ?? ''));
        $residence = trim((string) ($_POST['country_of_residence'] ?? ''));
        $profession = trim((string) ($_POST['profession'] ?? ''));
        $jobOffer = trim((string) ($_POST['job_offer'] ?? ''));
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        $status = trim((string) ($_POST['client_status'] ?? ''));

        if ($fullName === '' || mb_strlen($fullName) < 2) {
            throw new RuntimeException('Enter the candidate full name.');
        }
        if ($email === false) {
            throw new RuntimeException('Enter a valid email address.');
        }
        $email = strtolower((string) $email);
        if ($passport === '') {
            throw new RuntimeException('Enter the passport number.');
        }
        if ($address === '') {
            throw new RuntimeException('Enter the address.');
        }
        if ($nationality === '') {
            throw new RuntimeException('Enter nationality.');
        }
        if ($jobOffer === '' || !isset($jobOffers[$jobOffer])) {
            throw new RuntimeException('Choose a preferred job offer.');
        }
        if (!isset($pipelineStatuses[$status])) {
            throw new RuntimeException('Choose a client status: Waiting List, Ready2start, New clients, or Cancelled.');
        }

        $dob = null;
        if ($dobRaw !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $dobRaw);
            if (!$dt || $dt->format('Y-m-d') !== $dobRaw) {
                throw new RuntimeException('Enter a valid date of birth.');
            }
            $dob = $dobRaw;
        }

        $chk = $conn->prepare('SELECT id FROM francophonie_mobility_applications WHERE LOWER(email) = ? LIMIT 1');
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            throw new RuntimeException('An application with this email already exists.');
        }
        $chk->close();

        $uploadedMeta = [];
        $groupFiles = $_FILES['group_docs'] ?? null;
        if (is_array($groupFiles) && isset($groupFiles['name']) && is_array($groupFiles['name'])) {
            foreach ($docBuckets as $slug => $_lab) {
                if (!isset($groupFiles['name'][$slug])) {
                    continue;
                }
                $bag = [
                    'name' => $groupFiles['name'][$slug] ?? [],
                    'type' => $groupFiles['type'][$slug] ?? [],
                    'tmp_name' => $groupFiles['tmp_name'][$slug] ?? [],
                    'error' => $groupFiles['error'][$slug] ?? [],
                    'size' => $groupFiles['size'][$slug] ?? [],
                ];
                // Normalize single-file inputs into array shape.
                if (!is_array($bag['name'])) {
                    $bag = [
                        'name' => [$bag['name']],
                        'type' => [is_array($bag['type']) ? '' : (string) $bag['type']],
                        'tmp_name' => [is_array($bag['tmp_name']) ? '' : (string) $bag['tmp_name']],
                        'error' => [is_array($bag['error']) ? UPLOAD_ERR_NO_FILE : (int) $bag['error']],
                        'size' => [is_array($bag['size']) ? 0 : (int) $bag['size']],
                    ];
                }
                foreach (fm_staff_collect_group_uploads($slug, $bag) as $meta) {
                    $uploadedMeta[] = $meta;
                }
            }
        }

        $parts = preg_split('/\s+/u', $fullName, 2) ?: [$fullName];
        $first = (string) ($parts[0] ?? $fullName);
        $last = (string) ($parts[1] ?? '');
        $userId = 'fm_staff_' . bin2hex(random_bytes(6)) . '_' . time();
        $reference = 'FM' . date('Y') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $phoneArea = '';
        $phoneNum = $phone !== '' ? $phone : '0';
        $residence = $residence !== '' ? $residence : 'N/A';
        $profession = $profession !== '' ? $profession : 'N/A';
        $na = 'N/A';
        $zero = '0';
        $year = '0000';
        $empty = '';
        $beginner = 'beginner';
        $no = 'no';
        $zeroInt = 0;
        $source = 'staff';
        $age = 0;
        if ($dob !== null) {
            try {
                $born = new DateTime($dob);
                $age = (int) $born->diff(new DateTime('today'))->y;
            } catch (Throwable) {
                $age = 0;
            }
        }

        $conn->begin_transaction();
        $sql = 'INSERT INTO francophonie_mobility_applications (
            user_id, reference_id, first_name, last_name, email,
            phone_area_code, phone_number, date_of_birth, passport_number, address,
            age, nationality, country_of_residence,
            profession, years_experience, highest_degree, field_of_study, university_name,
            country_of_study, graduation_year, other_certifications,
            french_level, french_tef, french_tcf, french_professional,
            english_level, english_toefl, english_ielts, english_professional,
            has_wes, job_offer, cv_file, french_cert_file, english_cert_file, academic_docs_file,
            admin_notes, status, source, created_at
        ) VALUES (
            ?,?,?,?,?,
            ?,?,NULLIF(?,\'\'),?,?,
            ?,?,?,
            ?,?,?,?,?,
            ?,?,?,
            ?,?,?,?,
            ?,?,?,?,
            ?,?,\'\',\'\',\'\',\'\',
            ?,?,?, NOW()
        )';
        $st = $conn->prepare($sql);
        if (!$st) {
            throw new RuntimeException('Could not save the application: ' . $conn->error);
        }
        $dobBind = $dob ?? '';
        $type = 'ssssssssssisssssssssssiissiissssss';
        $st->bind_param(
            $type,
            $userId, $reference, $first, $last, $email,
            $phoneArea, $phoneNum, $dobBind, $passport, $address,
            $age, $nationality, $residence,
            $profession, $zero, $na, $na, $na,
            $na, $year, $empty,
            $beginner, $zeroInt, $zeroInt, $no,
            $beginner, $zeroInt, $zeroInt, $no,
            $no, $jobOffer, $notes, $status, $source
        );
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException('Failed to save: ' . $err);
        }
        $appId = (int) $st->insert_id;
        $st->close();

        foreach ($uploadedMeta as $meta) {
            fm_insert_document(
                $conn,
                $appId,
                (string) $meta['path'],
                (string) $meta['name'],
                (string) $meta['group'],
                'staff',
                $adminId > 0 ? $adminId : null,
                (string) ($meta['mime'] ?? '') ?: null,
                (int) ($meta['size'] ?? 0)
            );
        }

        $conn->commit();
        $doneRef = $reference;
        $doneId = $appId;
        $doneStatus = $status;
        $doneDocCount = count($uploadedMeta);
    } catch (Throwable $e) {
        @$conn->rollback();
        $error = $e->getMessage();
    }
}

$postedStatus = (string) ($_POST['client_status'] ?? 'new_clients');
if (!isset($pipelineStatuses[$postedStatus])) {
    $postedStatus = 'new_clients';
}

$statusIcons = [
    'waiting_list' => 'fa-hourglass-split',
    'ready2start' => 'fa-bolt',
    'new_clients' => 'fa-user-check',
    'cancelled' => 'fa-ban',
];
$statusHints = [
    'waiting_list' => 'Low budget but skilled',
    'ready2start' => 'Payment confirmed',
    'new_clients' => 'Processing now',
    'cancelled' => 'Do not pursue',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Francophonie Mobility candidate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --fm-green: #1e4d2b;
            --fm-blue: #3661B9;
            --fm-ink: #1e293b;
            --fm-muted: #64748b;
            --fm-line: #e2e8f0;
            --fm-soft: #f4f6f3;
        }
        body { background: var(--fm-soft); color: var(--fm-ink); }
        .page-head {
            background: linear-gradient(135deg, var(--fm-green), var(--fm-blue));
            color: #fff;
            padding: 1.35rem 0 1.2rem;
            margin-bottom: 1.25rem;
        }
        .card-soft {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 1px 8px rgba(15, 23, 42, .07);
            background: #fff;
        }
        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0 0 .35rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .section-title .num {
            width: 1.55rem;
            height: 1.55rem;
            border-radius: 999px;
            background: #e8f0ea;
            color: var(--fm-green);
            font-size: .8rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .hint { font-size: .85rem; color: var(--fm-muted); }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }
        @media (min-width: 768px) {
            .status-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .status-card {
            position: relative;
            border: 1.5px solid var(--fm-line);
            border-radius: 12px;
            padding: .85rem .75rem;
            cursor: pointer;
            background: #fff;
            transition: border-color .15s, box-shadow .15s, background .15s;
            height: 100%;
        }
        .status-card:hover { border-color: #94a3b8; }
        .status-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .status-card .icon {
            width: 2rem;
            height: 2rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: .45rem;
            background: #f1f5f9;
            color: #334155;
        }
        .status-card .lab { font-weight: 650; font-size: .92rem; line-height: 1.2; display: block; }
        .status-card .sub { font-size: .75rem; color: var(--fm-muted); margin-top: .2rem; display: block; }
        .status-card:has(input:checked) {
            border-color: var(--fm-green);
            background: #f3faf5;
            box-shadow: 0 0 0 3px rgba(30, 77, 43, .12);
        }
        .status-card:has(input:checked) .icon {
            background: #dcefe2;
            color: var(--fm-green);
        }
        .doc-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
            gap: .65rem .85rem;
            align-items: center;
            padding: .7rem 0;
            border-bottom: 1px solid var(--fm-line);
        }
        .doc-row:last-child { border-bottom: 0; }
        @media (max-width: 700px) {
            .doc-row { grid-template-columns: 1fr; }
        }
        .doc-row .folder {
            display: flex;
            align-items: flex-start;
            gap: .55rem;
        }
        .doc-row .folder i {
            color: #64748b;
            margin-top: .15rem;
        }
        .doc-row.attached .folder i { color: var(--fm-blue); }
        .doc-row .folder-name {
            font-weight: 600;
            font-size: .9rem;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: #334155;
        }
        .doc-panel {
            border: 1px solid var(--fm-line);
            border-radius: 12px;
            padding: .35rem 1rem .15rem;
            background: #fafbfc;
        }
        .form-label { color: #334155; }
        .sticky-actions {
            position: sticky;
            bottom: 0;
            background: linear-gradient(180deg, transparent, var(--fm-soft) 28%);
            padding: 1rem 0 .5rem;
            margin-top: .5rem;
        }
    </style>
</head>
<body>
<?= function_exists('fm_manager_portal_nav_html') ? fm_manager_portal_nav_html('add') : '' ?>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4" style="max-width:980px">
        <h1 class="h4 mb-1"><i class="fas fa-user-plus me-2"></i>Smart Add — Francophonie Mobility</h1>
        <p class="mb-0 opacity-75 small">Create a candidate, set their pipeline status, and file documents into the right Visa submission groups in one step.</p>
    </div>
</div>
<div class="container-fluid px-3 px-md-4 pb-5" style="max-width:980px">
    <p class="mb-3"><a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to applications</a></p>

    <?php if ($doneRef !== ''): ?>
    <div class="alert alert-success card-soft border-0">
        <h2 class="h5 mb-2">Candidate saved</h2>
        <p class="mb-1">Reference <code><?= htmlspecialchars($doneRef) ?></code></p>
        <p class="mb-2">
            Status: <strong><?= htmlspecialchars(fm_application_status_label($doneStatus)) ?></strong>
            <?php if ($doneDocCount > 0): ?>
            · <?= (int) $doneDocCount ?> document<?= $doneDocCount === 1 ? '' : 's' ?> filed
            <?php else: ?>
            · No documents uploaded
            <?php endif; ?>
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm" href="francophonie-mobility-applications.php?status=<?= urlencode($doneStatus) ?>">Open in <?= htmlspecialchars(fm_application_status_label($doneStatus)) ?></a>
            <a class="btn btn-outline-primary btn-sm" href="francophonie-mobility-applications.php">All applications</a>
            <a class="btn btn-outline-secondary btn-sm" href="francophonie-mobility-staff-add.php">Add another</a>
        </div>
    </div>
    <?php else: ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card card-soft p-3 p-md-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="mb-4">
            <h2 class="section-title"><span class="num">1</span> Client status *</h2>
            <p class="hint mb-3">Choose where this candidate starts in the pipeline.</p>
            <div class="status-grid" role="radiogroup" aria-label="Client status">
                <?php foreach ($pipelineStatuses as $slug => $label): ?>
                <label class="status-card">
                    <input type="radio" name="client_status" value="<?= htmlspecialchars($slug) ?>" <?= $postedStatus === $slug ? 'checked' : '' ?> required>
                    <span class="icon"><i class="fas <?= htmlspecialchars($statusIcons[$slug] ?? 'fa-circle') ?>"></i></span>
                    <span class="lab"><?= htmlspecialchars(preg_replace('/\s*\(.+\)$/', '', $label) ?: $label) ?></span>
                    <span class="sub"><?= htmlspecialchars($statusHints[$slug] ?? '') ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-4">
            <h2 class="section-title"><span class="num">2</span> Candidate details</h2>
            <p class="hint mb-3">Core identity fields. Name and passport as on travel documents.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full name *</label>
                    <input class="form-control" name="full_name" required maxlength="200" value="<?= htmlspecialchars((string) ($_POST['full_name'] ?? '')) ?>" placeholder="As on passport">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email *</label>
                    <input class="form-control" type="email" name="email" required maxlength="150" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Phone</label>
                    <input class="form-control" name="phone" maxlength="20" value="<?= htmlspecialchars((string) ($_POST['phone'] ?? '')) ?>" placeholder="Optional">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Date of birth</label>
                    <input class="form-control" type="date" name="date_of_birth" value="<?= htmlspecialchars((string) ($_POST['date_of_birth'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Passport number *</label>
                    <input class="form-control" name="passport_number" required maxlength="64" value="<?= htmlspecialchars((string) ($_POST['passport_number'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nationality *</label>
                    <input class="form-control" name="nationality" required maxlength="100" value="<?= htmlspecialchars((string) ($_POST['nationality'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address *</label>
                    <textarea class="form-control" name="address" required rows="2"><?= htmlspecialchars((string) ($_POST['address'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Country of residence</label>
                    <input class="form-control" name="country_of_residence" maxlength="100" value="<?= htmlspecialchars((string) ($_POST['country_of_residence'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Profession</label>
                    <input class="form-control" name="profession" maxlength="200" value="<?= htmlspecialchars((string) ($_POST['profession'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Preferred job offer *</label>
                    <select class="form-select" name="job_offer" required>
                        <option value="">Select…</option>
                        <?php foreach ($jobOffers as $slug => $label): ?>
                        <option value="<?= htmlspecialchars($slug) ?>" <?= (((string) ($_POST['job_offer'] ?? '')) === $slug) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Admin notes</label>
                    <input class="form-control" name="admin_notes" maxlength="500" value="<?= htmlspecialchars((string) ($_POST['admin_notes'] ?? '')) ?>" placeholder="Optional">
                </div>
            </div>
        </div>

        <div class="mb-2">
            <h2 class="section-title"><span class="num">3</span> Documents by group</h2>
            <p class="hint mb-3">All uploads are optional — nothing here is mandatory. Choose files into a Visa submission folder (or <strong>Attached</strong>); they are saved when you click <strong>Save candidate</strong>. Each folder can hold multiple files. PDF, JPG, PNG, DOC, DOCX — max 12 MB each.</p>
            <div class="doc-panel">
                <?php foreach ($docBuckets as $slug => $lab): ?>
                <?php $isAttached = $slug === fm_doc_attached_group(); ?>
                <div class="doc-row<?= $isAttached ? ' attached' : '' ?>">
                    <div class="folder">
                        <i class="fas <?= $isAttached ? 'fa-paperclip' : 'fa-folder' ?>"></i>
                        <div>
                            <div class="folder-name"><?= htmlspecialchars($lab) ?></div>
                            <div class="hint mb-0"><?= $isAttached ? 'Inbox — sort later if needed' : 'Goes straight into this visa folder' ?></div>
                        </div>
                    </div>
                    <input
                        class="form-control form-control-sm"
                        type="file"
                        name="group_docs[<?= htmlspecialchars($slug) ?>][]"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*"
                    >
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sticky-actions d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success px-4"><i class="fas fa-save me-1"></i> Save candidate</button>
            <a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>

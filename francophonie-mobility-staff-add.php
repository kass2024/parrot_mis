<?php
/**
 * Staff Smart Add — create a Francophonie Mobility candidate from the dashboard.
 * Uploaded files go into the Attached group (manager transfers into Visa submission docs).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';

fm_ensure_schema($conn);
fm_ensure_documents_schema($conn);

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_require_francophonie_mobility($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
$jobOffers = fm_job_offer_choices();
$error = '';
$doneRef = '';
$doneId = 0;

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

        $files = $_FILES['documents'] ?? null;
        $uploadedMeta = [];
        if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('One of the documents failed to upload.');
                }
                $tmp = (string) ($files['tmp_name'][$i] ?? '');
                $orig = (string) ($files['name'][$i] ?? 'document');
                $size = (int) ($files['size'][$i] ?? 0);
                if ($size <= 0 || $size > 12 * 1024 * 1024) {
                    throw new RuntimeException('Each document must be between 1 byte and 12 MB.');
                }
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
                if (!in_array($ext, $allowed, true)) {
                    throw new RuntimeException('Documents must be PDF, JPG, PNG, DOC, or DOCX.');
                }
                $permDir = __DIR__ . '/uploads/francophonie_mobility';
                if (!is_dir($permDir) && !mkdir($permDir, 0755, true) && !is_dir($permDir)) {
                    throw new RuntimeException('Cannot create upload directory.');
                }
                $filename = 'fm_' . date('Ymd') . '_' . uniqid() . '_attached' . ($ext !== '' ? '.' . $ext : '');
                $dest = $permDir . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($tmp, $dest)) {
                    throw new RuntimeException('Could not store an uploaded document.');
                }
                $uploadedMeta[] = [
                    'path' => 'uploads/francophonie_mobility/' . $filename,
                    'name' => $orig,
                    'size' => $size,
                    'mime' => (string) ($files['type'][$i] ?? ''),
                ];
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
        $status = 'pending';
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
                fm_doc_attached_group(),
                'staff',
                $adminId > 0 ? $adminId : null,
                (string) ($meta['mime'] ?? '') ?: null,
                (int) ($meta['size'] ?? 0)
            );
        }

        $conn->commit();
        $doneRef = $reference;
        $doneId = $appId;
    } catch (Throwable $e) {
        if ($conn->errno || true) {
            @$conn->rollback();
        }
        $error = $e->getMessage();
    }
}
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
        body { background:#f4f6f3; }
        .page-head { background:linear-gradient(135deg,#1e4d2b,#3661B9); color:#fff; padding:1.25rem 0; margin-bottom:1rem; }
        .card-soft { border:0; border-radius:12px; box-shadow:0 1px 6px rgba(0,0,0,.06); }
        .hint { font-size:.85rem; color:#64748b; }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <h1 class="h4 mb-1"><i class="fas fa-user-plus me-2"></i>Smart Add — Francophonie Mobility</h1>
        <p class="mb-0 opacity-75 small">Create a candidate from the dashboard. Documents go into <strong>Attached</strong> for manager filing under Visa submission documents.</p>
    </div>
</div>
<div class="container-fluid px-3 px-md-4 pb-5" style="max-width:920px">
    <p class="mb-3"><a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to applications</a></p>

    <?php if ($doneRef !== ''): ?>
    <div class="alert alert-success card-soft">
        <h2 class="h5">Candidate saved</h2>
        <p class="mb-2">Reference <code><?= htmlspecialchars($doneRef) ?></code>. Staff documents are in the <strong>Attached</strong> group.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm" href="francophonie-mobility-applications.php">Open applications list</a>
            <a class="btn btn-outline-primary btn-sm" href="francophonie-mobility-staff-add.php">Add another</a>
        </div>
    </div>
    <?php else: ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card card-soft p-3 p-md-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <h2 class="h5 mb-3">Candidate details</h2>
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

        <hr class="my-4">
        <h2 class="h5 mb-2">Documents → Attached</h2>
        <p class="hint mb-3">Upload any supporting files now. They are stored in <strong>Attached</strong>. The public client form is unchanged — managers later move files into Visa submission documents (Passport, Resume, IMM forms, etc.).</p>
        <input class="form-control" type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*">
        <p class="hint mt-2 mb-0">PDF, JPG, PNG, DOC, DOCX. Max 12 MB each. Multiple files allowed.</p>

        <div class="mt-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Save candidate</button>
            <a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>

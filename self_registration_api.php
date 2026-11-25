<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/self_registration.php';

header('Content-Type: application/json; charset=utf-8');

function pcvc_self_reg_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    pcvc_self_reg_json(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

pcvc_self_reg_ensure_schema($conn);

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));
$reg = pcvc_self_reg_find_by_token($conn, $token);
if (!$reg) {
    pcvc_self_reg_json(['ok' => false, 'error' => 'Invalid or expired registration link.'], 404);
}

$regId = (int) $reg['id'];
$isSubmitted = (($reg['status'] ?? '') === 'submitted');

if ($action === 'save_fields') {
    if ($isSubmitted) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'This form is already submitted and locked.'], 409);
    }

    $fields = pcvc_self_reg_writable_fields();
    $sets = [];
    $types = '';
    $values = [];
    // Keep attribution from the issued referral link.
    $lockReferrer = ((int) ($reg['referred_by_admin_id'] ?? 0) > 0)
        && trim((string) ($reg['referring_agent'] ?? '')) !== '';

    foreach ($fields as $col => $type) {
        if (!array_key_exists($col, $_POST)) {
            continue;
        }
        if ($lockReferrer && $col === 'referring_agent') {
            continue;
        }
        $san = pcvc_self_reg_sanitize_value($type, $_POST[$col]);
        if ($type === 'date') {
            if ($san === '') {
                $sets[] = "`{$col}` = NULL";
            } else {
                $sets[] = "`{$col}` = ?";
                $types .= 's';
                $values[] = $san;
            }
            continue;
        }
        if ($type === 'bool') {
            $sets[] = "`{$col}` = ?";
            $types .= 'i';
            $values[] = (int) $san;
            continue;
        }
        $sets[] = "`{$col}` = ?";
        $types .= 's';
        $values[] = $san;
    }

    if ($sets === []) {
        pcvc_self_reg_json(['ok' => true, 'saved' => false, 'message' => 'Nothing to save.']);
    }

    // Validate controlled selects when present
    if (isset($_POST['preferred_level'])) {
        $lvl = pcvc_self_reg_sanitize_value('string', $_POST['preferred_level']);
        if ($lvl !== '' && !in_array($lvl, pcvc_self_reg_study_levels(), true)) {
            pcvc_self_reg_json(['ok' => false, 'error' => 'Invalid preferred level of study.'], 422);
        }
    }
    if (isset($_POST['preferred_intake'])) {
        $intake = pcvc_self_reg_sanitize_value('string', $_POST['preferred_intake']);
        if ($intake !== '' && !xander_is_valid_visa_intake($intake)) {
            pcvc_self_reg_json(['ok' => false, 'error' => 'Invalid preferred intake.'], 422);
        }
    }
    $allowedFields = pcvc_self_reg_fields_of_study();
    foreach (['field_choice_1', 'field_choice_2', 'field_choice_3', 'field_choice_4', 'field_choice_5'] as $fc) {
        if (!isset($_POST[$fc])) {
            continue;
        }
        $v = pcvc_self_reg_sanitize_value('string', $_POST[$fc]);
        if ($v !== '' && !in_array($v, $allowedFields, true)) {
            pcvc_self_reg_json(['ok' => false, 'error' => 'Invalid field of study selection.'], 422);
        }
    }

    $sql = 'UPDATE self_registrations SET ' . implode(', ', $sets) . ' WHERE id = ? AND status = \'draft\' LIMIT 1';
    $types .= 'i';
    $values[] = $regId;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Save failed to prepare.'], 500);
    }

    // bind_param with dynamic refs
    $bind = [];
    $bind[] = &$types;
    foreach ($values as $k => $v) {
        $bind[] = &$values[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if (!$ok) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Save failed: ' . $err], 500);
    }

    pcvc_self_reg_json(['ok' => true, 'saved' => true, 'message' => 'Saved']);
}

if ($action === 'upload') {
    if ($isSubmitted) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'This form is already submitted and locked.'], 409);
    }

    $docKey = trim((string) ($_POST['doc_key'] ?? ''));
    $slots = pcvc_self_reg_document_slots();
    if ($docKey === '' || !isset($slots[$docKey])) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Unknown document type.'], 422);
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'No file received.'], 422);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Upload failed (error ' . (int) ($file['error'] ?? 0) . ').'], 422);
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 12 * 1024 * 1024) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'File must be between 1 byte and 12 MB.'], 422);
    }

    $orig = (string) ($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    if (!in_array($ext, $allowedExt, true)) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'File type not allowed.'], 422);
    }

    $dir = pcvc_self_reg_upload_root() . DIRECTORY_SEPARATOR . $token;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Could not create upload folder.'], 500);
    }
    // Deny web listing
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');

    $stored = $docKey . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Could not store uploaded file.'], 500);
    }

    $mime = (string) ($file['type'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    // Replace previous file for this slot
    $existing = pcvc_self_reg_documents_map($conn, $regId);
    if (isset($existing[$docKey]['stored_name'])) {
        $old = $dir . DIRECTORY_SEPARATOR . $existing[$docKey]['stored_name'];
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO self_registration_documents
            (registration_id, doc_key, original_name, stored_name, mime, size_bytes, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            original_name = VALUES(original_name),
            stored_name = VALUES(stored_name),
            mime = VALUES(mime),
            size_bytes = VALUES(size_bytes),
            uploaded_at = NOW()'
    );
    if (!$stmt) {
        @unlink($dest);
        pcvc_self_reg_json(['ok' => false, 'error' => 'Could not save document record.'], 500);
    }
    $stmt->bind_param('issssi', $regId, $docKey, $orig, $stored, $mime, $size);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) {
        @unlink($dest);
        pcvc_self_reg_json(['ok' => false, 'error' => 'Document save failed: ' . $err], 500);
    }

    pcvc_self_reg_json([
        'ok' => true,
        'doc_key' => $docKey,
        'original_name' => $orig,
        'size_bytes' => $size,
        'message' => 'Uploaded',
    ]);
}

if ($action === 'submit') {
    if ($isSubmitted) {
        pcvc_self_reg_json(['ok' => true, 'already' => true, 'message' => 'Already submitted.']);
    }

    // Refresh row
    $reg = pcvc_self_reg_find_by_token($conn, $token) ?: $reg;
    $docs = pcvc_self_reg_documents_map($conn, $regId);

    $required = [
        'full_name' => 'Full name',
        'email' => 'Email address',
        'whatsapp' => 'WhatsApp number',
        'nationality' => 'Nationality',
        'country_of_residence' => 'Country of residence',
        'destination_country' => 'Preferred destination country',
        'preferred_level' => 'Preferred level of study',
        'preferred_intake' => 'Preferred intake',
        'sponsor' => 'Sponsor',
        'ready_to_pay' => 'Tuition readiness',
        'visa_refused' => 'Visa refusal answer',
        'travel_with_family' => 'Travel with family answer',
    ];
    foreach ($required as $col => $label) {
        if (trim((string) ($reg[$col] ?? '')) === '') {
            pcvc_self_reg_json(['ok' => false, 'error' => $label . ' is required before submit.'], 422);
        }
    }
    if (!(int) ($reg['declaration_accepted'] ?? 0)) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Please accept the declaration before submit.'], 422);
    }
    if (!isset($docs['payment_proof'])) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Payment proof attachment is mandatory.'], 422);
    }
    $email = trim((string) $reg['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Please enter a valid email address.'], 422);
    }

    $stmt = $conn->prepare(
        "UPDATE self_registrations
         SET status = 'submitted', submitted_at = NOW()
         WHERE id = ? AND status = 'draft' LIMIT 1"
    );
    if (!$stmt) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Submit failed.'], 500);
    }
    $stmt->bind_param('i', $regId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        pcvc_self_reg_json(['ok' => false, 'error' => 'Could not submit form.'], 500);
    }

    pcvc_self_reg_json(['ok' => true, 'submitted' => true, 'message' => 'Application submitted successfully.']);
}

pcvc_self_reg_json(['ok' => false, 'error' => 'Unknown action.'], 400);

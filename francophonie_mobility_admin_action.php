<?php
/**
 * francophonie_mobility_admin_action.php
 * Admin JSON actions: create_video_invite | delete_application
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';
require_once __DIR__ . '/helpers/fm_mobility_contract_schema.php';

fm_ensure_schema($conn);
fm_ensure_documents_schema($conn);

header('Content-Type: application/json; charset=utf-8');

fm_require_staff_or_manager_portal($conn, true);

if (!fm_manager_portal_session_or_admin_id() || empty($_POST['csrf_token'])
    || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$applicationId = (int) ($_POST['application_id'] ?? 0);

if ($applicationId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$st = $conn->prepare('SELECT * FROM francophonie_mobility_applications WHERE id = ? LIMIT 1');
$st->bind_param('i', $applicationId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found']);
    exit;
}

function fm_admin_absolute_url(string $scriptRelative): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $basePath . '/' . ltrim($scriptRelative, '/');
}

function fm_admin_whatsapp_digits(array $row): string
{
    $digits = preg_replace('/\D+/', '', (string) (($row['phone_area_code'] ?? '') . ($row['phone_number'] ?? ''))) ?: '';
    return $digits;
}

function fm_admin_unlink_stored(string $rel): void
{
    $abs = fm_abs_upload_path($rel);
    if ($abs && is_file($abs)) {
        @unlink($abs);
    }
}

if ($action === 'create_video_invite') {
    $hasVideo = trim((string) ($row['video_pcloud_link'] ?? '')) !== ''
        || trim((string) ($row['video_file'] ?? '')) !== '';
    if ($hasVideo) {
        echo json_encode(['success' => false, 'message' => 'This application already has a video.']);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $upd = $conn->prepare(
        'UPDATE francophonie_mobility_applications
         SET video_invite_token = ?, video_invite_created_at = NOW(),
             video_invite_opened_at = NULL, video_invite_used_at = NULL
         WHERE id = ? LIMIT 1'
    );
    $upd->bind_param('si', $token, $applicationId);
    if (!$upd->execute()) {
        echo json_encode(['success' => false, 'message' => 'Could not create invite link']);
        exit;
    }
    $upd->close();

    $inviteUrl = fm_admin_absolute_url('fm-video-invite.php?t=' . rawurlencode($token));
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $ref = (string) ($row['reference_id'] ?? '');
    $waText = "Hello {$name},\n\n"
        . "Please upload or record your introduction video for Canada Francophonie Mobility.\n"
        . "Reference: {$ref}\n"
        . "One-time link (upload/record only): {$inviteUrl}\n\n"
        . "This link can be used once. Thank you.";
    $phoneDigits = fm_admin_whatsapp_digits($row);
    $waUrl = $phoneDigits !== ''
        ? 'https://wa.me/' . $phoneDigits . '?text=' . rawurlencode($waText)
        : 'https://wa.me/?text=' . rawurlencode($waText);

    echo json_encode([
        'success' => true,
        'message' => 'Video invite link created (one-time use after upload).',
        'invite_url' => $inviteUrl,
        'whatsapp_url' => $waUrl,
        'whatsapp_text' => $waText,
        'reference_id' => $ref,
        'phone_digits' => $phoneDigits,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_application') {
    if (fm_manager_portal_logged_in() && !pcvc_can_access_francophonie_mobility($conn)) {
        echo json_encode(['success' => false, 'message' => 'Managers cannot delete applications from the private portal.']);
        exit;
    }
    $confirm = trim((string) ($_POST['confirm_reference'] ?? ''));
    $ref = (string) ($row['reference_id'] ?? '');
    if ($confirm === '' || strcasecmp($confirm, $ref) !== 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Type the exact reference ID to confirm delete: ' . $ref,
        ]);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Local document files (video is pCloud-only).
        foreach (['cv_file', 'french_cert_file', 'english_cert_file'] as $col) {
            $rel = trim((string) ($row[$col] ?? ''));
            if ($rel !== '') {
                fm_admin_unlink_stored($rel);
            }
        }
        foreach (fm_parse_stored_files((string) ($row['academic_docs_file'] ?? '')) as $apath) {
            fm_admin_unlink_stored($apath);
        }
        $videoLocal = trim((string) ($row['video_file'] ?? ''));
        if ($videoLocal !== '') {
            fm_admin_unlink_stored($videoLocal);
        }
        fm_delete_all_documents_for_application($conn, $applicationId);

        $logDel = $conn->prepare('DELETE FROM francophonie_mobility_status_logs WHERE application_id = ?');
        $logDel->bind_param('i', $applicationId);
        $logDel->execute();
        $logDel->close();

        // Linked e-sign contracts (best-effort).
        fm_contract_ensure_schema($conn);
        $cSt = $conn->prepare('SELECT id, pdf_path FROM fm_mobility_contracts WHERE application_id = ?');
        $cSt->bind_param('i', $applicationId);
        $cSt->execute();
        $contracts = $cSt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cSt->close();
        foreach ($contracts as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            if (!empty($c['pdf_path'])) {
                $filePath = realpath(__DIR__ . '/' . $c['pdf_path']);
                $baseDir = realpath(__DIR__ . '/uploads/fm_contracts');
                if ($filePath && $baseDir && strpos($filePath, $baseDir) === 0 && is_file($filePath)) {
                    @unlink($filePath);
                }
            }
            $sDel = $conn->prepare('DELETE FROM fm_mobility_signatures WHERE contract_id = ?');
            $sDel->bind_param('i', $cid);
            $sDel->execute();
            $sDel->close();
            $cDel = $conn->prepare('DELETE FROM fm_mobility_contracts WHERE id = ? LIMIT 1');
            $cDel->bind_param('i', $cid);
            $cDel->execute();
            $cDel->close();
        }

        $del = $conn->prepare('DELETE FROM francophonie_mobility_applications WHERE id = ? LIMIT 1');
        $del->bind_param('i', $applicationId);
        if (!$del->execute() || $del->affected_rows < 1) {
            throw new RuntimeException('Delete failed');
        }
        $del->close();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Application ' . $ref . ' deleted.',
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'upload_attached' || $action === 'upload_to_group') {
    if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Choose a file to upload.']);
        exit;
    }
    $targetGroup = trim((string) ($_POST['doc_group'] ?? fm_doc_attached_group()));
    if ($action === 'upload_attached') {
        $targetGroup = fm_doc_attached_group();
    }
    if (!fm_is_valid_doc_group($targetGroup)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid document group.']);
        exit;
    }
    $file = $_FILES['file'];
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File must be 12 MB or smaller.']);
        exit;
    }
    $orig = (string) ($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
        echo json_encode(['success' => false, 'message' => 'Allowed: PDF, JPG, PNG, DOC, DOCX.']);
        exit;
    }
    $permDir = __DIR__ . '/uploads/francophonie_mobility';
    if (!is_dir($permDir) && !mkdir($permDir, 0755, true) && !is_dir($permDir)) {
        echo json_encode(['success' => false, 'message' => 'Cannot create upload directory.']);
        exit;
    }
    $tag = preg_replace('/[^a-z0-9_]/', '', $targetGroup) ?: 'doc';
    $filename = 'fm_' . date('Ymd') . '_' . uniqid() . '_' . $tag . ($ext !== '' ? '.' . $ext : '');
    $dest = $permDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Could not store the file.']);
        exit;
    }
    $rel = 'uploads/francophonie_mobility/' . $filename;
    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    try {
        $docId = fm_insert_document(
            $conn,
            $applicationId,
            $rel,
            $orig,
            $targetGroup,
            'manager',
            $adminPk > 0 ? $adminPk : null,
            (string) ($file['type'] ?? ''),
            $size
        );
    } catch (Throwable $e) {
        @unlink($dest);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    require_once __DIR__ . '/helpers/secure_file.php';
    $visaGroups = fm_visa_submission_groups();
    $groupOpts = '<option value="">Move to group…</option>'
        . '<optgroup label="Inbox"><option value="attached"'
        . ($targetGroup === 'attached' ? ' selected' : '') . '>Attached</option></optgroup>'
        . '<optgroup label="Visa submission documents">';
    foreach ($visaGroups as $slug => $lab) {
        $groupOpts .= '<option value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"'
            . ($targetGroup === $slug ? ' selected' : '') . '>'
            . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $groupOpts .= '</optgroup>';
    $safeName = htmlspecialchars($orig !== '' ? $orig : basename($rel), ENT_QUOTES, 'UTF-8');
    $links = pcvc_secure_file_links_html($rel, $safeName);
    $cardHtml = '<div class="border rounded p-2 mb-2 bg-white" data-fm-doc-id="' . (int) $docId . '">'
        . '<div class="small fw-semibold mb-1">' . $safeName . '</div>'
        . $links
        . '<div class="d-flex flex-wrap gap-1 mt-2 align-items-center">'
        . '<select class="form-select form-select-sm" style="max-width:240px" data-fm-transfer="' . (int) $docId . '" '
        . 'onchange="fmTransferDoc(' . (int) $applicationId . ',' . (int) $docId . ', this)">'
        . $groupOpts
        . '</select>'
        . '<button type="button" class="btn btn-sm btn-outline-primary" onclick="fmTransferDoc('
        . (int) $applicationId . ',' . (int) $docId . ', this)">Move</button>'
        . '<button type="button" class="btn btn-sm btn-outline-danger" onclick="fmDeleteDoc('
        . (int) $applicationId . ',' . (int) $docId . ')">Delete</button>'
        . '</div></div>';

    echo json_encode([
        'success' => true,
        'message' => 'Document added to ' . fm_doc_group_label($targetGroup) . '.',
        'document_id' => $docId,
        'doc_group' => $targetGroup,
        'original_name' => $orig,
        'card_html' => $cardHtml,
        'reload' => false,
    ]);
    exit;
}

if ($action === 'file_client_document') {
    $field = trim((string) ($_POST['field'] ?? ''));
    $targetGroup = trim((string) ($_POST['doc_group'] ?? ''));
    $academicIndex = (int) ($_POST['academic_index'] ?? -1);
    if (!fm_is_valid_doc_group($targetGroup) || $targetGroup === '') {
        echo json_encode(['success' => false, 'message' => 'Choose a destination group.']);
        exit;
    }
    $path = '';
    $label = 'Document';
    if ($field === 'cv_file') {
        $path = trim((string) ($row['cv_file'] ?? ''));
        $label = 'CV';
    } elseif ($field === 'french_cert_file') {
        $path = trim((string) ($row['french_cert_file'] ?? ''));
        $label = 'French Certificate';
    } elseif ($field === 'english_cert_file') {
        $path = trim((string) ($row['english_cert_file'] ?? ''));
        $label = 'English Certificate';
    } elseif ($field === 'academic_docs_file') {
        $list = fm_parse_stored_files((string) ($row['academic_docs_file'] ?? ''));
        if ($academicIndex < 0 || $academicIndex >= count($list)) {
            echo json_encode(['success' => false, 'message' => 'Academic document not found.']);
            exit;
        }
        $path = trim((string) $list[$academicIndex]);
        $label = count($list) > 1 ? 'Academic Document ' . ($academicIndex + 1) : 'Academic Documents';
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown client document field.']);
        exit;
    }
    if ($path === '' || !fm_abs_upload_path($path)) {
        echo json_encode(['success' => false, 'message' => 'That client document file is missing.']);
        exit;
    }
    // Avoid duplicate filing of the same path into the same group.
    $dup = $conn->prepare(
        'SELECT id FROM francophonie_mobility_documents
         WHERE application_id = ? AND stored_path = ? AND doc_group = ? LIMIT 1'
    );
    if ($dup) {
        $dup->bind_param('iss', $applicationId, $path, $targetGroup);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            echo json_encode([
                'success' => true,
                'message' => $label . ' is already in ' . fm_doc_group_label($targetGroup) . '.',
                'reload' => true,
            ]);
            exit;
        }
        $dup->close();
    }
    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    $abs = fm_abs_upload_path($path);
    $size = ($abs && is_file($abs)) ? (int) filesize($abs) : 0;
    try {
        $docId = fm_insert_document(
            $conn,
            $applicationId,
            $path,
            $label,
            $targetGroup,
            'client',
            $adminPk > 0 ? $adminPk : null,
            null,
            $size
        );
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => $label . ' filed into ' . fm_doc_group_label($targetGroup) . '. Original client upload kept.',
        'document_id' => $docId,
        'reload' => true,
    ]);
    exit;
}

if ($action === 'transfer_document') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    $newGroup = trim((string) ($_POST['doc_group'] ?? ''));
    if ($docId <= 0 || !fm_is_valid_doc_group($newGroup)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid destination group.']);
        exit;
    }
    if (!fm_transfer_document($conn, $docId, $applicationId, $newGroup)) {
        echo json_encode(['success' => false, 'message' => 'Could not move the document.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => 'Moved to ' . fm_doc_group_label($newGroup) . '.',
        'reload' => true,
    ]);
    exit;
}

if ($action === 'delete_document') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        exit;
    }
    if (!fm_delete_document_row($conn, $docId, $applicationId, true)) {
        echo json_encode(['success' => false, 'message' => 'Could not delete the document.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Document deleted.', 'reload' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);

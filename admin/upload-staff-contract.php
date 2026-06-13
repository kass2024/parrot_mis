<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';
require_once __DIR__ . '/../helpers/staff_contract_word.php';

header('Content-Type: application/json; charset=utf-8');

pcvc_require_superadmin($conn, true);

$staffId = (int) ($_POST['staff_id'] ?? 0);
if ($staffId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing staff member']);
    exit;
}

$stmt = $conn->prepare('SELECT id, full_name, role FROM admins WHERE id = ? LIMIT 1');
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$stmt->bind_param('i', $staffId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff) {
    echo json_encode(['success' => false, 'message' => 'Staff member not found']);
    exit;
}

$fileKey = isset($_FILES['contract_docx']) ? 'contract_docx' : 'contract_pdf';
if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please choose a Word contract file (.docx)']);
    exit;
}

$ext = strtolower(pathinfo((string) $_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
if ($ext !== 'docx') {
    echo json_encode(['success' => false, 'message' => 'Only Word .docx contract templates are allowed']);
    exit;
}
if ((int) $_FILES[$fileKey]['size'] > 25 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Contract file must be 25MB or less']);
    exit;
}

try {
    pcvc_staff_contract_ensure_schema($conn);
    pcvc_staff_contract_ensure_dirs();

    $safeName = preg_replace('/[^A-Za-z0-9.\-_]+/', '_', basename((string) $_FILES[$fileKey]['name']));
    $stored = 'staff_' . $staffId . '_' . time() . '_' . $safeName;
    $docxRel = 'uploads/staff_contracts/source/' . $stored;
    $docxAbs = pcvc_staff_contract_abs_path($docxRel);

    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $docxAbs)) {
        throw new RuntimeException('Could not save uploaded Word contract');
    }

    $title = trim((string) ($_POST['contract_title'] ?? ''));
    if ($title === '') {
        $title = pathinfo($safeName, PATHINFO_FILENAME);
    }

    $uploaderId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    $existing = pcvc_staff_contract_for_admin($conn, $staffId);

    if ($existing) {
        pcvc_staff_contract_remove_files($existing);

        $sql = "UPDATE employment_contracts
                SET source_docx_path = ?, filled_docx_path = NULL, source_pdf_path = NULL,
                    signed_pdf_path = NULL, pdf_path = NULL,
                    contract_title = ?, status = 'pending_signature',
                    staff_typed_name = NULL, signature_file = NULL, field_layout = NULL,
                    signed_at = NULL, signed_ip = NULL,
                    uploaded_by = NULLIF(?, 0), uploaded_at = NOW()
                WHERE admin_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('ssii', $docxRel, $title, $uploaderId, $staffId);
        $stmt->execute();
        $stmt->close();
        $contract = pcvc_staff_contract_for_admin($conn, $staffId);
    } else {
        $sql = "INSERT INTO employment_contracts
                (admin_id, status, source_docx_path, contract_title, uploaded_by, uploaded_at)
                VALUES (?, 'pending_signature', ?, ?, NULLIF(?, 0), NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('issi', $staffId, $docxRel, $title, $uploaderId);
        $stmt->execute();
        $stmt->close();
        $contract = pcvc_staff_contract_for_admin($conn, $staffId);
    }

    if (!$contract) {
        throw new RuntimeException('Could not save contract record');
    }

    $preview = pcvc_staff_contract_generate_preview($conn, $staffId, $contract);
    $message = 'Word contract uploaded for ' . ($staff['full_name'] ?? 'staff')
        . '. Employee details were auto-filled. Staff can review and e-sign when they log in.';
    if (!empty($preview['position_warning'])) {
        $message .= $preview['position_warning'];
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';
require_once __DIR__ . '/../helpers/staff_contract_word.php';

$viewerId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($viewerId <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

$staffId = (int) ($_GET['staff_id'] ?? $viewerId);
$type = ($_GET['type'] ?? 'signed') === 'source' ? 'source' : 'signed';
$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$isSuper = pcvc_current_user_is_superadmin($conn);

if (!$isSuper && $staffId !== $viewerId) {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = $conn->prepare('SELECT full_name FROM admins WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $staffId);
$stmt->execute();
$stmt->bind_result($fullName);
$stmt->fetch();
$stmt->close();

$contract = pcvc_staff_contract_for_admin($conn, $staffId);
if (!$contract) {
    http_response_code(404);
    exit('Contract not found');
}

if ($format === 'docx') {
    $rel = $type === 'signed'
        ? pcvc_staff_contract_signed_docx_path($contract)
        : pcvc_staff_contract_preview_docx_path($contract);
    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $ext = 'docx';
} else {
    $rel = $type === 'signed'
        ? pcvc_staff_contract_signed_path($contract)
        : trim((string) ($contract['source_pdf_path'] ?? ''));
    $mime = 'application/pdf';
    $ext = 'pdf';
}

if ($rel === '' && $format === 'docx' && $type === 'source') {
    require_once __DIR__ . '/../helpers/staff_contract_word.php';
    try {
        pcvc_staff_contract_generate_preview($conn, $staffId, $contract, null, false);
        $contract = pcvc_staff_contract_for_admin($conn, $staffId);
        if ($contract) {
            $rel = pcvc_staff_contract_preview_docx_path($contract);
        }
    } catch (Throwable $e) {
        http_response_code(503);
        exit('Contract not ready');
    }
}

if ($rel === '') {
    http_response_code(404);
    exit('File not available');
}

$abs = pcvc_staff_contract_abs_path($rel);
if (!is_file($abs)) {
    http_response_code(404);
    exit('File missing');
}

$safe = preg_replace('/[^\w\- ]+/', '_', trim((string) $fullName));
if ($safe === '') {
    $safe = 'staff_contract';
}
$filename = $safe . ($type === 'signed' ? '_signed_contract.' : '_contract.') . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
exit;

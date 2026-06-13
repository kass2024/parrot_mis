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
$type = ($_GET['type'] ?? 'source') === 'signed' ? 'signed' : 'source';
$isSuper = pcvc_current_user_is_superadmin($conn);

if (!$isSuper && $staffId !== $viewerId) {
    http_response_code(403);
    exit('Forbidden');
}

$contract = pcvc_staff_contract_for_admin($conn, $staffId);
if (!$contract) {
    http_response_code(404);
    exit('Contract not found');
}

if ($type === 'signed') {
    $rel = pcvc_staff_contract_signed_docx_path($contract);
} else {
    $rel = pcvc_staff_contract_preview_docx_path($contract);
    if ($rel === '' && trim((string) ($contract['source_docx_path'] ?? '')) !== '') {
        @set_time_limit(120);
        try {
            pcvc_staff_contract_generate_preview($conn, $staffId, $contract, null, false);
            $contract = pcvc_staff_contract_for_admin($conn, $staffId);
            if ($contract) {
                $rel = pcvc_staff_contract_preview_docx_path($contract);
            }
        } catch (Throwable $e) {
            http_response_code(503);
            exit('Contract not ready: ' . $e->getMessage());
        }
    }
}

if ($rel === '') {
    http_response_code(404);
    exit('Word contract not available');
}

$abs = pcvc_staff_contract_abs_path($rel);
if (!is_file($abs)) {
    http_response_code(404);
    exit('Word contract file missing');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: inline; filename="staff-contract.docx"');
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
exit;

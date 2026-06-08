<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';

$viewerId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($viewerId <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

$staffId = (int) ($_GET['staff_id'] ?? $viewerId);
$type = ($_GET['type'] ?? 'signed') === 'source' ? 'source' : 'signed';
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

$rel = $type === 'signed'
    ? pcvc_staff_contract_signed_path($contract)
    : trim((string) ($contract['source_pdf_path'] ?? ''));

if ($rel === '') {
    http_response_code(404);
    exit('PDF not available');
}

$abs = pcvc_staff_contract_abs_path($rel);
if (!is_file($abs)) {
    http_response_code(404);
    exit('PDF file missing');
}

$safe = preg_replace('/[^\w\- ]+/', '_', trim((string) $fullName));
if ($safe === '') {
    $safe = 'staff_contract';
}
$filename = $safe . ($type === 'signed' ? '_signed_contract.pdf' : '_contract.pdf');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
exit;

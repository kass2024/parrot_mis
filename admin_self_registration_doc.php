<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/self_registration.php';
require_once __DIR__ . '/helpers/company_view_as.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized.');
}
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database unavailable.');
}

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
$dataAdminId = pcvc_effective_data_admin_id($conn);
$isSuper = pcvc_current_user_is_superadmin($conn) && !pcvc_company_view_limits_data();
$regId = (int) ($_GET['id'] ?? 0);
$docKey = trim((string) ($_GET['doc'] ?? ''));
$inline = ((string) ($_GET['inline'] ?? '')) === '1';

if ($regId < 1 || $docKey === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $docKey)) {
    http_response_code(400);
    exit('Invalid request.');
}

$reg = pcvc_self_reg_find_by_id($conn, $regId);
if (!$reg || !pcvc_self_reg_can_access_row($reg, $dataAdminId, $isSuper)) {
    http_response_code(403);
    exit('Access denied.');
}

$docs = pcvc_self_reg_documents_map($conn, $regId);
if (!isset($docs[$docKey]['stored_name'])) {
    http_response_code(404);
    exit('Document not found.');
}

$token = (string) ($reg['token'] ?? '');
$stored = (string) $docs[$docKey]['stored_name'];
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^[A-Za-z0-9._-]{1,180}$/', $stored)) {
    http_response_code(404);
    exit('Document not found.');
}

$path = pcvc_self_reg_upload_root() . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . $stored;
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing.');
}

$orig = (string) ($docs[$docKey]['original_name'] ?? $stored);
$mime = trim((string) ($docs[$docKey]['mime'] ?? ''));
$ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
if ($mime === '') {
    $mime = match ($ext) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        default => 'application/octet-stream',
    };
}

$safeDownload = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $orig) ?: $stored;
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
if ($inline && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $safeDownload) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $safeDownload) . '"');
}
readfile($path);
exit;

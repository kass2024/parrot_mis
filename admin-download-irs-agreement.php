<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized');
}
pcvc_require_francophonie_mobility($conn);
irs_agreement_ensure_schema($conn);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid agreement.');
}

$stmt = $conn->prepare("SELECT id, pdf_path, agreement_reference, status FROM irs_agreements WHERE id = ? AND status = 'signed' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    http_response_code(404);
    exit('Agreement not found.');
}

$relative = trim((string) ($row['pdf_path'] ?? ''));
$absolute = $relative !== '' ? __DIR__ . '/' . ltrim($relative, '/') : '';
if ($absolute === '' || !is_file($absolute)) {
    require_once __DIR__ . '/generate-irs-agreement-pdf.php';
    $relative = generateIrsAgreementPDF($id);
    $absolute = __DIR__ . '/' . $relative;
    $upd = $conn->prepare('UPDATE irs_agreements SET pdf_path = ? WHERE id = ?');
    $upd->bind_param('si', $relative, $id);
    $upd->execute();
    $upd->close();
}

$filename = ($row['agreement_reference'] ?: ('irs_agreement_' . $id)) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($absolute));
readfile($absolute);
exit;

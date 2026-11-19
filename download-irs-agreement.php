<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

irs_agreement_ensure_schema($conn);
$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

$stmt = $conn->prepare("SELECT id, pdf_path, agreement_reference, status FROM irs_agreements WHERE contract_token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row || ($row['status'] ?? '') !== 'signed') {
    http_response_code(404);
    exit('The signed agreement is not available.');
}

$id = (int) $row['id'];
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
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($absolute));
readfile($absolute);
exit;

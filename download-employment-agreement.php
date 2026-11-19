<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/employment_agreement_schema.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

employment_agreement_ensure_schema($conn);

$stmt = $conn->prepare('SELECT id, pdf_path FROM employment_agreements WHERE contract_token = ? AND status = \'signed\' LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Signed agreement not found.');
}

$contractId = (int) $row['id'];
$pdfPath = trim((string) ($row['pdf_path'] ?? ''));
if ($pdfPath === '' || !is_file($pdfPath)) {
    require_once __DIR__ . '/generate-employment-agreement-pdf.php';
    try {
        $pdfPath = generateEmploymentAgreementPDF($contractId);
        $update = $conn->prepare('UPDATE employment_agreements SET pdf_path = ? WHERE id = ?');
        $update->bind_param('si', $pdfPath, $contractId);
        $update->execute();
        $update->close();
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Unable to open the signed agreement.');
    }
}

if (!is_file($pdfPath)) {
    http_response_code(404);
    exit('Signed agreement not found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="employment_agreement.pdf"');
header('Content-Length: ' . (string) filesize($pdfPath));
readfile($pdfPath);
exit;

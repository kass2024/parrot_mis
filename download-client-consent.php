<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

client_consent_ensure_schema($conn);

$stmt = $conn->prepare('SELECT id, pdf_path, status FROM client_consent_agreements WHERE contract_token = ? AND status = \'signed\' LIMIT 1');
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
    require_once __DIR__ . '/generate-client-consent-pdf.php';
    try {
        $pdfPath = generateClientConsentPDF($contractId);
        $update = $conn->prepare('UPDATE client_consent_agreements SET pdf_path = ? WHERE id = ?');
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
header('Content-Disposition: inline; filename="client_consent_agreement.pdf"');
header('Content-Length: ' . (string) filesize($pdfPath));
readfile($pdfPath);
exit;

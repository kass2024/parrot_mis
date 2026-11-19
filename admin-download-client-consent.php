<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

client_consent_ensure_schema($conn);

if (!isset($_GET['id']) || !ctype_digit((string) $_GET['id'])) {
    http_response_code(400);
    exit('Invalid agreement');
}

$contractId = (int) $_GET['id'];
$stmt = $conn->prepare('SELECT id, pdf_path FROM client_consent_agreements WHERE id = ? AND status = \'signed\' LIMIT 1');
$stmt->bind_param('i', $contractId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Agreement not found');
}

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
    exit('PDF not found');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="client_consent_' . $contractId . '.pdf"');
header('Content-Length: ' . (string) filesize($pdfPath));
readfile($pdfPath);
exit;

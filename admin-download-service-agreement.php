<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit('Not found');
}

pcvc_service_agreement_ensure_schema($conn);
$stmt = $conn->prepare('SELECT * FROM service_agreements WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row) || ($row['status'] ?? '') !== 'signed') {
    http_response_code(404);
    exit('This agreement is not signed yet.');
}

$meta = ($row['kind'] ?? '') === 'special'
    ? (pcvc_special_contract_catalog()[(string) ($row['catalog_key'] ?? '')] ?? [])
    : [];
$rebuild = ($meta['layout'] ?? '') === 'document' || ($row['kind'] ?? '') === 'german_language';
$path = (string) ($row['pdf_path'] ?? '');
$absolute = $path !== '' ? __DIR__ . '/' . ltrim($path, '/\\') : '';
if ($rebuild || $absolute === '' || !is_file($absolute)) {
    $path = pcvc_service_agreement_pdf($conn, $id);
    $absolute = $path !== '' ? __DIR__ . '/' . ltrim($path, '/\\') : '';
}
if ($absolute === '' || !is_file($absolute)) {
    http_response_code(500);
    exit('The PDF could not be created.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="service-agreement-' . $id . '.pdf"');
header('Content-Length: ' . (string) filesize($absolute));
readfile($absolute);

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_work_contract.php';

if (empty($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit('Not found');
}

czech_work_contract_ensure_schema($conn);
$stmt = $conn->prepare('SELECT pdf_path, status, reference_number FROM czech_work_contracts WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row) || ($row['status'] ?? '') !== 'signed') {
    http_response_code(404);
    exit('This agreement is not signed yet.');
}

$path = (string) ($row['pdf_path'] ?? '');
$absolute = $path !== '' ? __DIR__ . '/' . ltrim($path, '/\\') : '';
if ($absolute === '' || !is_file($absolute)) {
    $path = czech_work_contract_pdf($conn, $id);
    $absolute = $path !== '' ? __DIR__ . '/' . ltrim($path, '/\\') : '';
}
if ($absolute === '' || !is_file($absolute)) {
    http_response_code(500);
    exit('The PDF could not be created.');
}

$name = trim((string) ($row['reference_number'] ?? ''));
if ($name === '') {
    $name = 'czech-work-' . $id;
}
$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: ('czech-work-' . $id);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '.pdf"');
header('Content-Length: ' . (string) filesize($absolute));
readfile($absolute);

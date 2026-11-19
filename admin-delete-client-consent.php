<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
    http_response_code(403);
    exit('Invalid request');
}

if (!isset($_POST['contract_id']) || !ctype_digit((string) $_POST['contract_id'])) {
    http_response_code(400);
    exit('Invalid request');
}

client_consent_ensure_schema($conn);
$contractId = (int) $_POST['contract_id'];

$stmt = $conn->prepare('SELECT pdf_path FROM client_consent_agreements WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $contractId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header('Location: admin-client-consents.php?error=' . urlencode('Agreement not found'));
    exit;
}

if (!empty($row['pdf_path']) && is_file($row['pdf_path'])) {
    $filePath = realpath($row['pdf_path']);
    $baseDir = realpath(client_consent_upload_dir());
    if ($filePath && $baseDir && strpos($filePath, $baseDir) === 0) {
        @unlink($filePath);
    }
}

$delete = $conn->prepare('DELETE FROM client_consent_agreements WHERE id = ? LIMIT 1');
$delete->bind_param('i', $contractId);
$delete->execute();
$delete->close();

header('Location: admin-client-consents.php?deleted=1');
exit;

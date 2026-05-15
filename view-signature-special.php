<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/contract_signature_image.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('Missing token.');
}

$stmt = $conn->prepare("
    SELECT c.id
    FROM student_contracts_special c
    WHERE c.contract_token = ?
      AND c.status = 'signed'
    LIMIT 1
");
$stmt->bind_param('s', $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit('Not found.');
}

$contractId = (int) $contract['id'];
$stmt = $conn->prepare("
    SELECT signature_image
    FROM student_signatures_special
    WHERE contract_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param('i', $contractId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (empty($row['signature_image'])) {
    http_response_code(404);
    exit('No signature.');
}

$png = contract_signature_to_display_png((string) $row['signature_image']);
if ($png === null) {
    http_response_code(500);
    exit('Invalid signature image.');
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $png;

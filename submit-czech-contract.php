<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_work_contract.php';

header('Content-Type: application/json');

function czech_fail(string $message, int $code = 400): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    czech_fail('Invalid request.');
}

$token = trim((string) ($data['token'] ?? ''));
czech_work_contract_ensure_schema($conn);
$stmt = $conn->prepare('SELECT * FROM czech_work_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row)) {
    czech_fail('This contract link is not valid.', 404);
}
if (($row['status'] ?? '') === 'signed') {
    ob_clean();
    echo json_encode(['success' => true, 'status' => 'already_signed']);
    exit;
}

$fields = [
    'client_name' => trim((string) ($data['client_name'] ?? '')),
    'date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')),
    'nationality' => trim((string) ($data['nationality'] ?? '')),
    'passport_number' => trim((string) ($data['passport_number'] ?? '')),
    'phone' => trim((string) ($data['phone'] ?? '')),
    'email' => trim((string) ($data['email'] ?? '')),
    'residential_address' => trim((string) ($data['residential_address'] ?? '')),
    'agreement_date' => trim((string) ($data['agreement_date'] ?? '')),
    'client_place' => trim((string) ($data['client_place'] ?? '')),
];
foreach ($fields as $value) {
    if ($value === '') {
        czech_fail('Fill every blank before you sign.');
    }
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    czech_fail('Enter a valid email address.');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['agreement_date']) !== 1) {
    czech_fail('Enter the effective date.');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['date_of_birth']) !== 1) {
    czech_fail('Enter the date of birth.');
}
$signature = (string) ($data['signature'] ?? '');
if (!str_starts_with($signature, 'data:image/png;base64,')) {
    czech_fail('Draw your signature before you sign.');
}

$id = (int) $row['id'];
$sql = 'UPDATE czech_work_contracts SET
    client_name = ?, date_of_birth = ?, nationality = ?, passport_number = ?, phone = ?, email = ?,
    residential_address = ?, agreement_date = ?, client_place = ?, signature_image = ?,
    status = \'signed\', signed_at = NOW()
    WHERE id = ? AND contract_token = ? AND status <> \'signed\'';
$save = $conn->prepare($sql);
if (!$save) {
    czech_fail('Could not save this agreement.', 500);
}
$save->bind_param(
    'ssssssssssis',
    $fields['client_name'],
    $fields['date_of_birth'],
    $fields['nationality'],
    $fields['passport_number'],
    $fields['phone'],
    $fields['email'],
    $fields['residential_address'],
    $fields['agreement_date'],
    $fields['client_place'],
    $signature,
    $id,
    $token
);
$ok = $save->execute();
$save->close();
if (!$ok) {
    czech_fail('Could not save this agreement.', 500);
}

czech_work_contract_pdf($conn, $id);
ob_clean();
echo json_encode(['success' => true, 'status' => 'signed', 'contract_id' => $id]);

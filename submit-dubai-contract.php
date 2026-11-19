<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/dubai_work_contract.php';

header('Content-Type: application/json');

function dubai_fail(string $message, int $code = 400): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    dubai_fail('Invalid request.');
}

$token = trim((string) ($data['token'] ?? ''));
dubai_work_contract_ensure_schema($conn);
$stmt = $conn->prepare('SELECT * FROM dubai_work_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row)) {
    dubai_fail('This contract link is not valid.', 404);
}
if (($row['status'] ?? '') === 'signed') {
    ob_clean();
    echo json_encode(['success' => true, 'status' => 'already_signed']);
    exit;
}

$fields = [
    'client_name' => trim((string) ($data['client_name'] ?? '')),
    'nationality' => trim((string) ($data['nationality'] ?? '')),
    'passport_number' => trim((string) ($data['passport_number'] ?? '')),
    'phone' => trim((string) ($data['phone'] ?? '')),
    'email' => trim((string) ($data['email'] ?? '')),
    'residential_address' => trim((string) ($data['residential_address'] ?? '')),
    'position' => trim((string) ($data['position'] ?? '')),
    'employer' => trim((string) ($data['employer'] ?? '')),
    'salary_aed' => trim((string) ($data['salary_aed'] ?? '')),
    'contract_duration' => trim((string) ($data['contract_duration'] ?? '')),
    'agreement_date' => trim((string) ($data['agreement_date'] ?? '')),
];
foreach ($fields as $value) {
    if ($value === '') {
        dubai_fail('Fill every blank before you sign.');
    }
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    dubai_fail('Enter a valid email address.');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['agreement_date']) !== 1) {
    dubai_fail('Enter the date of the agreement.');
}
$signature = (string) ($data['signature'] ?? '');
if (!str_starts_with($signature, 'data:image/png;base64,')) {
    dubai_fail('Draw your signature before you sign.');
}

$id = (int) $row['id'];
$sql = 'UPDATE dubai_work_contracts SET
    client_name = ?, nationality = ?, passport_number = ?, phone = ?, email = ?,
    residential_address = ?, position = ?, employer = ?, salary_aed = ?, contract_duration = ?,
    agreement_date = ?, signature_image = ?, status = \'signed\', signed_at = NOW()
    WHERE id = ? AND contract_token = ? AND status <> \'signed\'';
$save = $conn->prepare($sql);
if (!$save) {
    dubai_fail('Could not save this agreement.', 500);
}
$save->bind_param(
    'ssssssssssssis',
    $fields['client_name'],
    $fields['nationality'],
    $fields['passport_number'],
    $fields['phone'],
    $fields['email'],
    $fields['residential_address'],
    $fields['position'],
    $fields['employer'],
    $fields['salary_aed'],
    $fields['contract_duration'],
    $fields['agreement_date'],
    $signature,
    $id,
    $token
);
$ok = $save->execute();
$save->close();
if (!$ok) {
    dubai_fail('Could not save this agreement.', 500);
}

dubai_work_contract_pdf($conn, $id);
ob_clean();
echo json_encode(['success' => true, 'status' => 'signed', 'contract_id' => $id]);

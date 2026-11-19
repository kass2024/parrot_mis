<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/employment_agreement_schema.php';

header('Content-Type: application/json; charset=utf-8');

function eaRespond(array $payload, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    eaRespond(['success' => false, 'error' => 'Invalid request'], 400);
}

employment_agreement_ensure_schema($conn);

$token = trim((string) ($data['token'] ?? ''));
$signature = (string) ($data['signature'] ?? '');
$fields = [
    'client_name' => trim((string) ($data['client_name'] ?? '')),
    'nationality' => trim((string) ($data['nationality'] ?? '')),
    'passport_number' => trim((string) ($data['passport_number'] ?? '')),
    'date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')),
    'residential_address' => trim((string) ($data['residential_address'] ?? '')),
    'phone' => trim((string) ($data['phone'] ?? '')),
    'email' => trim((string) ($data['email'] ?? '')),
    'destination_country' => trim((string) ($data['destination_country'] ?? '')),
    'preferred_job' => trim((string) ($data['preferred_job'] ?? '')),
    'preferred_city' => trim((string) ($data['preferred_city'] ?? '')),
    'expected_salary' => trim((string) ($data['expected_salary'] ?? '')),
    'employer_partner' => trim((string) ($data['employer_partner'] ?? '')),
    'place_of_signing' => trim((string) ($data['place_of_signing'] ?? '')),
    'contracting_jurisdiction' => trim((string) ($data['contracting_jurisdiction'] ?? '')),
    'agreement_date' => trim((string) ($data['agreement_date'] ?? '')),
];

$required = ['client_name', 'nationality', 'passport_number', 'date_of_birth', 'residential_address', 'phone', 'email', 'destination_country', 'preferred_job', 'preferred_city', 'agreement_date'];
foreach ($required as $key) {
    if ($fields[$key] === '') {
        eaRespond(['success' => false, 'error' => 'Please complete every required field.'], 400);
    }
}
if (!in_array($fields['destination_country'], employment_agreement_countries(), true)) {
    eaRespond(['success' => false, 'error' => 'Please select one destination country.'], 400);
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    eaRespond(['success' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (!str_starts_with($signature, 'data:image/png;base64,')) {
    eaRespond(['success' => false, 'error' => 'Please draw your signature.'], 400);
}
foreach (array_keys(employment_agreement_acknowledgments()) as $index) {
    if (empty($data['ack_' . $index])) {
        eaRespond(['success' => false, 'error' => 'Please confirm every statement in the declaration.'], 400);
    }
}

$stmt = $conn->prepare('SELECT id, status FROM employment_agreements WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    eaRespond(['success' => false, 'error' => 'This agreement link is not valid.'], 404);
}
if (($row['status'] ?? '') === 'signed') {
    eaRespond(['success' => true, 'status' => 'already_signed', 'message' => 'This agreement has already been signed.']);
}

$contractId = (int) $row['id'];
$sql = 'UPDATE employment_agreements SET
    client_name = ?, nationality = ?, passport_number = ?, date_of_birth = ?, residential_address = ?,
    phone = ?, email = ?, destination_country = ?, preferred_job = ?, preferred_city = ?,
    expected_salary = ?, employer_partner = ?, place_of_signing = ?, contracting_jurisdiction = ?,
    agreement_date = ?, signature_image = ?, status = \'signed\', signed_at = NOW()
    WHERE id = ? AND contract_token = ? AND status <> \'signed\'';
$update = $conn->prepare($sql);
$update->bind_param(
    'ssssssssssssssssis',
    $fields['client_name'],
    $fields['nationality'],
    $fields['passport_number'],
    $fields['date_of_birth'],
    $fields['residential_address'],
    $fields['phone'],
    $fields['email'],
    $fields['destination_country'],
    $fields['preferred_job'],
    $fields['preferred_city'],
    $fields['expected_salary'],
    $fields['employer_partner'],
    $fields['place_of_signing'],
    $fields['contracting_jurisdiction'],
    $fields['agreement_date'],
    $signature,
    $contractId,
    $token
);
$update->execute();
$changed = $update->affected_rows;
$update->close();

if ($changed < 1) {
    eaRespond(['success' => false, 'error' => 'This agreement could not be saved.'], 409);
}

$pdfError = null;
try {
    require_once __DIR__ . '/generate-employment-agreement-pdf.php';
    $pdfPath = generateEmploymentAgreementPDF($contractId);
    $save = $conn->prepare('UPDATE employment_agreements SET pdf_path = ? WHERE id = ?');
    $save->bind_param('si', $pdfPath, $contractId);
    $save->execute();
    $save->close();
} catch (Throwable $e) {
    $pdfError = 'Signed, but the PDF could not be created yet. Staff can open it from the agreements list.';
}

eaRespond([
    'success' => true,
    'status' => 'signed',
    'contract_id' => $contractId,
    'pdf_error' => $pdfError,
]);

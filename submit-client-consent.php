<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';

header('Content-Type: application/json; charset=utf-8');

function ccRespond(array $payload, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    ccRespond(['success' => false, 'error' => 'Invalid request'], 400);
}

client_consent_ensure_schema($conn);

$token = trim((string) ($data['token'] ?? ''));
$signature = (string) ($data['signature'] ?? '');
$accepted = !empty($data['accepted']);

$fields = [
    'client_name' => trim((string) ($data['client_name'] ?? '')),
    'passport_number' => trim((string) ($data['passport_number'] ?? '')),
    'nationality' => trim((string) ($data['nationality'] ?? '')),
    'date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')),
    'residential_address' => trim((string) ($data['residential_address'] ?? '')),
    'phone' => trim((string) ($data['phone'] ?? '')),
    'email' => trim((string) ($data['email'] ?? '')),
    'service_type' => trim((string) ($data['service_type'] ?? '')),
    'agreement_date' => trim((string) ($data['agreement_date'] ?? '')),
    'client_initials' => trim((string) ($data['client_initials'] ?? '')),
    'governing_law' => trim((string) ($data['governing_law'] ?? '')),
    'dispute_jurisdiction' => trim((string) ($data['dispute_jurisdiction'] ?? '')),
    'office_location' => trim((string) ($data['office_location'] ?? '')),
    'office_representative_name' => trim((string) ($data['office_representative_name'] ?? '')),
    'office_representative_position' => trim((string) ($data['office_representative_position'] ?? '')),
    'witness_name' => trim((string) ($data['witness_name'] ?? '')),
    'witness_passport' => trim((string) ($data['witness_passport'] ?? '')),
];

$required = ['client_name', 'passport_number', 'nationality', 'date_of_birth', 'residential_address', 'phone', 'email', 'service_type', 'agreement_date', 'client_initials'];
foreach ($required as $key) {
    if ($fields[$key] === '') {
        ccRespond(['success' => false, 'error' => 'Please complete every required client field.'], 400);
    }
}

if ($token === '' || !$accepted) {
    ccRespond(['success' => false, 'error' => 'Please read the agreement and confirm your acceptance.'], 400);
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    ccRespond(['success' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (!str_starts_with($signature, 'data:image/png;base64,')) {
    ccRespond(['success' => false, 'error' => 'Please draw your signature.'], 400);
}

$stmt = $conn->prepare('SELECT id, status FROM client_consent_agreements WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    ccRespond(['success' => false, 'error' => 'This agreement link is not valid.'], 404);
}
if (($row['status'] ?? '') === 'signed') {
    ccRespond(['success' => true, 'status' => 'already_signed', 'message' => 'This agreement has already been signed.']);
}

$contractId = (int) $row['id'];
$sql = 'UPDATE client_consent_agreements SET
    client_name = ?, passport_number = ?, nationality = ?, date_of_birth = ?, residential_address = ?,
    phone = ?, email = ?, service_type = ?, agreement_date = ?, client_initials = ?,
    governing_law = ?, dispute_jurisdiction = ?, office_location = ?, office_representative_name = ?,
    office_representative_position = ?, witness_name = ?, witness_passport = ?,
    signature_image = ?, status = \'signed\', signed_at = NOW()
    WHERE id = ? AND contract_token = ? AND status <> \'signed\'';
$update = $conn->prepare($sql);
$update->bind_param(
    'ssssssssssssssssssis',
    $fields['client_name'],
    $fields['passport_number'],
    $fields['nationality'],
    $fields['date_of_birth'],
    $fields['residential_address'],
    $fields['phone'],
    $fields['email'],
    $fields['service_type'],
    $fields['agreement_date'],
    $fields['client_initials'],
    $fields['governing_law'],
    $fields['dispute_jurisdiction'],
    $fields['office_location'],
    $fields['office_representative_name'],
    $fields['office_representative_position'],
    $fields['witness_name'],
    $fields['witness_passport'],
    $signature,
    $contractId,
    $token
);
$update->execute();
$changed = $update->affected_rows;
$update->close();

if ($changed < 1) {
    ccRespond(['success' => false, 'error' => 'This agreement could not be saved.'], 409);
}

$pdfError = null;
try {
    require_once __DIR__ . '/generate-client-consent-pdf.php';
    $pdfPath = generateClientConsentPDF($contractId);
    $save = $conn->prepare('UPDATE client_consent_agreements SET pdf_path = ? WHERE id = ?');
    $save->bind_param('si', $pdfPath, $contractId);
    $save->execute();
    $save->close();
} catch (Throwable $e) {
    $pdfError = 'Signed, but the PDF could not be created yet. Staff can open it from the agreements list.';
}

ccRespond([
    'success' => true,
    'status' => 'signed',
    'contract_id' => $contractId,
    'pdf_error' => $pdfError,
]);

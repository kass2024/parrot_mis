<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

header('Content-Type: application/json');
irs_agreement_ensure_schema($conn);

function irsFail(string $message, int $code = 400): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    irsFail('Invalid request');
}

$token = trim((string) ($data['token'] ?? ''));
$name = trim((string) ($data['client_name'] ?? ''));
$email = trim((string) ($data['client_email'] ?? ''));
$phone = trim((string) ($data['client_phone'] ?? ''));
$dob = trim((string) ($data['client_dob'] ?? ''));
$nationality = trim((string) ($data['client_nationality'] ?? ''));
$passport = trim((string) ($data['client_passport'] ?? ''));
$address = trim((string) ($data['client_address'] ?? ''));
$applicationType = trim((string) ($data['application_type'] ?? ''));
$uci = trim((string) ($data['uci_application_number'] ?? ''));
$dates = trim((string) ($data['submission_refusal_dates'] ?? ''));
$travel = trim((string) ($data['travel_history_period'] ?? ''));
$effective = trim((string) ($data['effective_date'] ?? ''));
$signedDate = trim((string) ($data['signed_date'] ?? ''));
$signature = (string) ($data['signature'] ?? '');

if ($token === '' || $name === '' || $email === '' || $phone === '' || $dob === '' || $nationality === '' || $address === '' || $applicationType === '' || $effective === '' || $signedDate === '' || $signature === '') {
    irsFail('Please complete every required field and draw your signature.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    irsFail('Enter a valid email address.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedDate)) {
    irsFail('Use a valid date.');
}
if (!str_starts_with($signature, 'data:image/') || !str_contains($signature, 'base64,')) {
    irsFail('The signature could not be read.');
}

$stmt = $conn->prepare('SELECT id, status FROM irs_agreements WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    irsFail('Agreement not found.', 404);
}
if (($row['status'] ?? '') === 'signed') {
    ob_clean();
    echo json_encode(['success' => true, 'status' => 'already_signed']);
    exit;
}

$id = (int) $row['id'];
$conn->begin_transaction();
try {
    $ins = $conn->prepare(
        'INSERT INTO irs_agreement_signatures (agreement_id, client_name, client_email, signed_date, signature_image, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $ins->bind_param('issss', $id, $name, $email, $signedDate, $signature);
    $ins->execute();
    $ins->close();

    $upd = $conn->prepare(
        "UPDATE irs_agreements SET
            status = 'signed', signed_at = NOW(), effective_date = ?,
            client_name = ?, client_email = ?, client_phone = ?, client_dob = ?,
            client_nationality = ?, client_passport = ?, client_address = ?,
            application_type = ?, uci_application_number = ?, submission_refusal_dates = ?,
            travel_history_period = ?
         WHERE id = ?"
    );
    $upd->bind_param(
        'ssssssssssssi',
        $effective,
        $name,
        $email,
        $phone,
        $dob,
        $nationality,
        $passport,
        $address,
        $applicationType,
        $uci,
        $dates,
        $travel,
        $id
    );
    $upd->execute();
    $upd->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    irsFail('The signature could not be saved.', 500);
}

$pdfError = null;
try {
    require_once __DIR__ . '/generate-irs-agreement-pdf.php';
    $pdfPath = generateIrsAgreementPDF($id);
    $save = $conn->prepare('UPDATE irs_agreements SET pdf_path = ? WHERE id = ?');
    $save->bind_param('si', $pdfPath, $id);
    $save->execute();
    $save->close();
} catch (Throwable $e) {
    $pdfError = $e->getMessage();
}

ob_clean();
echo json_encode(['success' => true, 'status' => 'signed', 'pdf_error' => $pdfError]);

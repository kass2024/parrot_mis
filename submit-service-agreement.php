<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

header('Content-Type: application/json');

function pcvc_sa_fail(string $message, int $code = 400): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    pcvc_sa_fail('Invalid JSON payload');
}

$token = trim((string) ($data['token'] ?? ''));
$name = trim((string) ($data['representative_name'] ?? ''));
$title = trim((string) ($data['representative_title'] ?? ''));
$email = trim((string) ($data['company_email'] ?? ''));
$signedDate = trim((string) ($data['signed_date'] ?? ''));
$signature = (string) ($data['signature'] ?? '');
$companyName = trim((string) ($data['company_name'] ?? ''));
$companyPhone = trim((string) ($data['company_phone'] ?? ''));
$companyAddress = trim((string) ($data['company_address'] ?? ''));
$registration = trim((string) ($data['company_registration'] ?? ''));
$law = trim((string) ($data['governing_law'] ?? ''));
$jurisdiction = trim((string) ($data['jurisdiction'] ?? ''));

pcvc_service_agreement_ensure_schema($conn);
$row = pcvc_service_agreement_by_token($conn, $token);
if (!$row) {
    pcvc_sa_fail('This contract link is not valid.', 404);
}

$annexJson = pcvc_service_agreement_annex_json($data, (string) ($row['annex_json'] ?? ''));
$annexOnly = !empty($data['annex_only']);
$ccnCatalog = (string) ($row['catalog_key'] ?? '');
if (!$annexOnly && in_array($ccnCatalog, ['ccn_burundi', 'ccn_burundi_fr'], true)) {
    $requiredKeys = $ccnCatalog === 'ccn_burundi_fr'
        ? pcvc_ccn_fr_required_field_keys()
        : pcvc_ccn_required_field_keys();
    $blankMessage = $ccnCatalog === 'ccn_burundi_fr'
        ? 'Remplissez toutes les cases de la convention avant de signer.'
        : 'Fill every blank on the agreement before you sign.';
    $signMessage = $ccnCatalog === 'ccn_burundi_fr'
        ? 'Dessinez la signature de CCN avant de signer.'
        : 'Draw the CCN signature before you sign.';
    foreach ($requiredKeys as $requiredKey) {
        if (trim((string) ($data[$requiredKey] ?? '')) === '') {
            pcvc_sa_fail($blankMessage);
        }
    }
    if (!str_starts_with(trim((string) ($data['ccn_signature'] ?? '')), 'data:image/png;base64,')) {
        pcvc_sa_fail($signMessage);
    }
}
$germanStudent = ($row['kind'] ?? '') === 'german_language';
if (!$annexOnly && $germanStudent) {
    $frenchStudent = strtolower((string) ($row['language'] ?? '')) === 'french';
    foreach (pcvc_german_student_required_field_keys() as $requiredKey) {
        if (trim((string) ($data[$requiredKey] ?? '')) === '') {
            pcvc_sa_fail($frenchStudent
                ? 'Remplissez toutes les cases de l\'accord avant de signer.'
                : 'Fill every blank on the agreement before you sign.');
        }
    }
    if (!str_starts_with(trim((string) ($data['student_signature'] ?? '')), 'data:image/png;base64,')) {
        pcvc_sa_fail($frenchStudent
            ? 'Dessinez la signature de l\'étudiant avant de signer.'
            : 'Draw the student signature before you sign.');
    }
    if ($name === '') {
        $name = trim((string) ($data['student_name'] ?? ''));
    }
    if ($companyName === '') {
        $companyName = $name;
    }
    if ($title === '') {
        $title = $frenchStudent ? 'Étudiant' : 'Student';
    }
    if ($email === '') {
        $email = trim((string) ($data['email'] ?? ''));
    }
    if ($companyPhone === '') {
        $companyPhone = trim((string) ($data['phone'] ?? ''));
    }
    if ($companyAddress === '') {
        $companyAddress = trim((string) ($data['address'] ?? ''));
    }
    if ($signedDate === '') {
        $signedDate = trim((string) ($data['student_date'] ?? ''));
    }
    if ($signature === '') {
        $signature = (string) ($data['student_signature'] ?? '');
    }
    if ($law === '') {
        $law = trim((string) ($data['governing_law'] ?? ''));
    }
    if ($jurisdiction === '') {
        $jurisdiction = trim((string) ($data['jurisdiction'] ?? ''));
    }
}

if (!$annexOnly && ($token === '' || $name === '' || $title === '' || $email === '' || $signedDate === '' || $companyName === '' || $signature === '')) {
    pcvc_sa_fail('Please complete the company, the person signing, the email, the date, and the signature.');
}
if (!$annexOnly && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pcvc_sa_fail('Enter a valid email address.');
}
if (!$annexOnly && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedDate)) {
    pcvc_sa_fail('Enter the date you are signing.');
}
if (!$annexOnly && !str_starts_with($signature, 'data:image/png;base64,')) {
    pcvc_sa_fail('Draw your signature before you submit.');
}

$editable = (int) $row['editable_after_sign'] === 1;
if ($annexOnly || ($row['status'] === 'signed' && !$editable)) {
    if (!$annexOnly && $row['status'] === 'signed' && !$editable) {
        ob_clean();
        echo json_encode(['success' => true, 'status' => 'already_signed', 'message' => 'This contract has already been signed.']);
        exit;
    }
    $stmt = $conn->prepare('UPDATE service_agreements SET annex_json = ?, updated_at = NOW() WHERE id = ? AND contract_token = ?');
    if (!$stmt) {
        pcvc_sa_fail('Could not save the candidate details.', 500);
    }
    $id = (int) $row['id'];
    $stmt->bind_param('sis', $annexJson, $id, $token);
    $stmt->execute();
    $stmt->close();
    if (($row['status'] ?? '') === 'signed') {
        pcvc_service_agreement_pdf($conn, $id);
    }
    ob_clean();
    echo json_encode(['success' => true, 'status' => 'updated', 'contract_id' => $id]);
    exit;
}

$id = (int) $row['id'];
$wasSigned = $row['status'] === 'signed';
$sql = "UPDATE service_agreements SET
    company_name = ?, company_email = ?, company_phone = ?, company_address = ?,
    company_registration = ?, representative_name = ?, representative_title = ?,
    governing_law = ?, jurisdiction = ?, signed_date = ?, signature_image = ?, annex_json = ?,
    status = 'signed',
    signed_at = IF(signed_at IS NULL, NOW(), signed_at),
    updated_at = NOW()
    WHERE id = ? AND contract_token = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    pcvc_sa_fail('Could not save this contract.', 500);
}
$stmt->bind_param(
    'ssssssssssssis',
    $companyName,
    $email,
    $companyPhone,
    $companyAddress,
    $registration,
    $name,
    $title,
    $law,
    $jurisdiction,
    $signedDate,
    $signature,
    $annexJson,
    $id,
    $token
);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) {
    pcvc_sa_fail('Could not save this contract.', 500);
}

pcvc_service_agreement_pdf($conn, $id);

ob_clean();
echo json_encode([
    'success' => true,
    'status' => $wasSigned ? 'updated' : 'signed',
    'contract_id' => $id,
]);

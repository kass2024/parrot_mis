<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';
require_once __DIR__ . '/helpers/mail_smtp.php';

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
$adminId = (int) $_SESSION['admin_id'];

$stmt = $conn->prepare("SELECT id, client_name, email, phone, passport_number, pdf_path, approved_at FROM client_consent_agreements WHERE id = ? AND status = 'signed' LIMIT 1");
$stmt->bind_param('i', $contractId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header('Location: admin-client-consents.php?error=' . urlencode('Agreement not found'));
    exit;
}
if (!empty($row['approved_at'])) {
    header('Location: admin-client-consents.php?error=' . urlencode('This agreement is already approved'));
    exit;
}

$pdfPath = trim((string) ($row['pdf_path'] ?? ''));
if ($pdfPath === '' || !is_file($pdfPath)) {
    require_once __DIR__ . '/generate-client-consent-pdf.php';
    try {
        $pdfPath = generateClientConsentPDF($contractId);
        $save = $conn->prepare('UPDATE client_consent_agreements SET pdf_path = ? WHERE id = ?');
        $save->bind_param('si', $pdfPath, $contractId);
        $save->execute();
        $save->close();
    } catch (Throwable $e) {
        header('Location: admin-client-consents.php?error=' . urlencode('The signed PDF could not be prepared'));
        exit;
    }
}
if (!is_file($pdfPath)) {
    header('Location: admin-client-consents.php?error=' . urlencode('The signed PDF could not be prepared'));
    exit;
}

$claim = $conn->prepare("UPDATE client_consent_agreements SET approved_at = NOW(), approved_by = ? WHERE id = ? AND status = 'signed' AND approved_at IS NULL");
$claim->bind_param('ii', $adminId, $contractId);
$claim->execute();
$claimed = $claim->affected_rows;
$claim->close();
if ($claimed < 1) {
    header('Location: admin-client-consents.php?error=' . urlencode('This agreement is already approved'));
    exit;
}

$clientName = trim((string) ($row['client_name'] ?? ''));
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $clientName) ?: 'client';
$replyTo = trim((string) ($row['email'] ?? ''));
$sent = sendSMTPMailDetailed(
    'infos@visaconsultantcanada.com',
    'Approved client consent agreement — ' . ($clientName !== '' ? $clientName : 'Client'),
    '<p>A signed Client Consent, Referral, and File-Transfer Agreement has been reviewed and approved.</p>'
    . '<p><strong>Client:</strong> ' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '<br>'
    . '<strong>Email:</strong> ' . htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
    . '<strong>Phone:</strong> ' . htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
    . '<strong>Passport/ID:</strong> ' . htmlspecialchars((string) ($row['passport_number'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p>The signed PDF is attached.</p>',
    [['path' => $pdfPath, 'name' => 'Client-Consent-' . $safeName . '.pdf']],
    [],
    null,
    ['heena@sharkishimmigration.com'],
    $replyTo
);

if (empty($sent['ok'])) {
    error_log('client consent approval email failed for agreement ' . $contractId . ': ' . ($sent['error'] ?? 'unknown'));
    $revert = $conn->prepare('UPDATE client_consent_agreements SET approved_at = NULL, approved_by = NULL WHERE id = ? AND approved_by = ?');
    $revert->bind_param('ii', $contractId, $adminId);
    $revert->execute();
    $revert->close();
    header('Location: admin-client-consents.php?error=' . urlencode('Approval email could not be sent. The agreement is still waiting for approval.'));
    exit;
}

header('Location: admin-client-consents.php?approved=1');
exit;

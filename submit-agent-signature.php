<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/agent_contract_schema.php';
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

agent_contract_ensure_schema($conn);

$LOG_FILE = __DIR__ . '/logs/agent-contract-signing.log';

function acLogMsg(string $msg, array $data = []): void
{
    global $LOG_FILE;
    if (!is_dir(dirname($LOG_FILE))) {
        mkdir(dirname($LOG_FILE), 0777, true);
    }
    file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . ' ' . json_encode($data) . PHP_EOL,
        FILE_APPEND
    );
}

function acRespond(array $payload, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function acFail(string $message, int $code = 400, array $debug = []): void
{
    acLogMsg('FAIL: ' . $message, $debug);
    acRespond(['success' => false, 'error' => $message], $code);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    acFail('Invalid JSON payload', 400);
}

$token         = trim($data['token'] ?? '');
$name          = trim($data['agent_name'] ?? '');
$signedDate    = trim($data['signed_date'] ?? '');
$signature     = $data['signature'] ?? '';
$email         = trim($data['agent_email'] ?? '');
$phone         = trim($data['agent_phone'] ?? '');
$address       = trim($data['agent_address'] ?? '');
$title         = trim($data['agent_title'] ?? '');
$effectiveDate = trim($data['effective_date'] ?? '') ?: $signedDate;

if ($token === '' || $name === '' || $signedDate === '' || $email === '' || $signature === '' || $address === '') {
    $missing = [];
    if ($token === '') $missing[] = 'token';
    if ($name === '') $missing[] = 'agent_name';
    if ($signedDate === '') $missing[] = 'signed_date';
    if ($email === '') $missing[] = 'agent_email';
    if ($signature === '') $missing[] = 'signature';
    if ($address === '') $missing[] = 'agent_address';
    acFail('Missing required fields: ' . implode(', ', $missing), 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    acFail('Invalid email address', 400);
}

if (!str_starts_with($signature, 'data:image/') || !str_contains($signature, 'base64,')) {
    acFail('Invalid signature format', 400);
}

$stmt = $conn->prepare('SELECT id, status FROM agent_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    acFail('Contract not found', 404);
}

if ($contract['status'] === 'signed') {
    acRespond([
        'success' => true,
        'status'  => 'already_signed',
        'message' => 'This contract has already been signed.',
    ]);
}

$contractId = (int) $contract['id'];

$conn->begin_transaction();

try {
    $stmt = $conn->prepare('SELECT id FROM agent_contracts WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO agent_signatures
        (contract_id, agent_name, agent_email, agent_title, signed_date, signature_image, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('isssss', $contractId, $name, $email, $title, $signedDate, $signature);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE agent_contracts SET
            status = 'signed',
            signed_at = NOW(),
            effective_date = ?,
            agent_name = ?,
            agent_email = ?,
            agent_phone = ?,
            agent_address = ?,
            agent_title = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        'ssssssi',
        $effectiveDate,
        $name,
        $email,
        $phone,
        $address,
        $title,
        $contractId
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    acLogMsg('Contract finalized', ['contract_id' => $contractId]);
} catch (Throwable $e) {
    $conn->rollback();
    acFail('Signing failed', 500, ['message' => $e->getMessage()]);
}

$pdfPath = null;
$pdfError = null;

try {
    require_once __DIR__ . '/generate-agent-contract-pdf.php';
    if (!function_exists('generateAgentContractPDF')) {
        throw new RuntimeException('PDF generator missing');
    }
    $pdfPath = generateAgentContractPDF($contractId);
    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new RuntimeException('PDF file was not created');
    }
    $stmt = $conn->prepare('UPDATE agent_contracts SET pdf_path = ? WHERE id = ?');
    $stmt->bind_param('si', $pdfPath, $contractId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $pdfError = $e->getMessage();
    acLogMsg('PDF generation failed', ['contract_id' => $contractId, 'message' => $pdfError]);
}

acRespond([
    'success'     => true,
    'status'      => 'signed',
    'contract_id' => $contractId,
    'pdf_path'    => $pdfPath,
    'pdf_error'   => $pdfError,
]);

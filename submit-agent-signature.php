<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/agent_contract_schema.php';
require_once __DIR__ . '/helpers/agent_contract_senegal.php';
require_once __DIR__ . '/helpers/agent_contract_admin.php';
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
$usernameIn    = trim($data['username'] ?? '');
$passwordIn    = (string) ($data['password'] ?? '');
$nationalId    = trim($data['national_id'] ?? '');
$dateOfBirth   = trim($data['date_of_birth'] ?? '');
$maritalStatus = trim($data['marital_status'] ?? '');
$nationality   = trim($data['nationality'] ?? '');
$placeOfBirth  = trim($data['place_of_birth'] ?? '');

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

$stmt = $conn->prepare('SELECT id, status, admin_id, contract_template FROM agent_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract || $contract['status'] === 'cancelled') {
    acFail('Contract not found', 404);
}

if ($contract['status'] === 'signed') {
    acRespond([
        'success' => true,
        'status'  => 'already_signed',
        'message' => 'This contract has already been signed.',
    ]);
}

if (empty($contract['admin_id']) && $phone === '') {
    acFail('Phone is required to create your agent account', 400);
}

$isSenegal = $contract['contract_template'] === AGENT_CONTRACT_SENEGAL;
$signerDetails = [];
if ($isSenegal) {
    if ($phone === '' || !agent_contract_senegal_date($signedDate)) {
        acFail('Renseignez votre téléphone et une date de signature valide.');
    }
    foreach (agent_contract_senegal_fields() as $field => [$label, $maxLength]) {
        $value = is_string($data[$field] ?? null) ? trim($data[$field]) : '';
        if ($value === '' || mb_strlen($value) > $maxLength) {
            acFail('Champ obligatoire ou trop long : ' . $label);
        }
        $signerDetails[$field] = $value;
    }
    if (!agent_contract_senegal_date($dateOfBirth) || $dateOfBirth > $signedDate) {
        acFail('Saisissez une date de naissance valide.');
    }
}

$contractId = (int) $contract['id'];
$accountInfo = [
    'admin_id' => 0,
    'created'  => false,
    'existing' => false,
    'username' => '',
];

$conn->begin_transaction();

try {
    $stmt = $conn->prepare('SELECT status, admin_id, effective_date, senegal_details FROM agent_contracts WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $locked = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$locked || $locked['status'] === 'cancelled') {
        $conn->rollback();
        acFail('Contract not found', 404);
    }
    if ($locked['status'] === 'signed') {
        $conn->rollback();
        acRespond(['success' => true, 'status' => 'already_signed']);
    }
    $adminId = $locked['admin_id'];
    $senegalDetails = null;
    if ($isSenegal) {
        $companyDetails = agent_contract_senegal_details($locked['senegal_details']);
        $place = is_string($data['company_signing_place'] ?? null) ? trim($data['company_signing_place']) : '';
        if (empty($companyDetails['company_signing_place'])) {
            if (mb_strlen($place) > 100) {
                throw new InvalidArgumentException('Le lieu de signature de la Société doit contenir au plus 100 caractères.');
            }
            $companyDetails['company_signing_place'] = $place;
        }
        $effectiveDate = $locked['effective_date'] ?: $effectiveDate;
        if (!agent_contract_senegal_date($effectiveDate)) {
            throw new InvalidArgumentException('Saisissez une date d’entrée en vigueur valide.');
        }
        $senegalDetails = json_encode($signerDetails + $companyDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

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
            agent_title = ?,
            senegal_details = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        'sssssssi',
        $effectiveDate,
        $name,
        $email,
        $phone,
        $address,
        $title,
        $senegalDetails,
        $contractId
    );
    $stmt->execute();
    $stmt->close();

    $accountInfo = [
        'admin_id' => (int) $adminId,
        'created'  => false,
        'existing' => !empty($adminId),
        'username' => '',
    ];

    if (empty($adminId)) {
        $accountInfo = agent_contract_ensure_admin($conn, [
            'name'                  => $name,
            'email'                 => $email,
            'phone'                 => $phone,
            'address'               => $address,
            'title'                 => $title,
            'username'              => $usernameIn,
            'password'              => $passwordIn,
            'national_id'           => $nationalId,
            'date_of_birth'         => $dateOfBirth,
            'marital_status'        => $maritalStatus,
            'nationality'           => $nationality,
            'place_of_birth'        => $placeOfBirth,
            'employment_start_date' => $effectiveDate,
        ]);
        $newAdminId = (int) $accountInfo['admin_id'];
        $agentType  = 'agent';
        $link = $conn->prepare("
            UPDATE agent_contracts
            SET admin_id = ?, agent_type = ?
            WHERE id = ?
        ");
        $link->bind_param('isi', $newAdminId, $agentType, $contractId);
        $link->execute();
        $link->close();
    }

    $conn->commit();
    acLogMsg('Contract finalized', [
        'contract_id' => $contractId,
        'admin_id'    => $accountInfo['admin_id'] ?? 0,
        'created'     => !empty($accountInfo['created']),
    ]);
} catch (InvalidArgumentException $e) {
    $conn->rollback();
    acFail($e->getMessage(), 400);
} catch (Throwable $e) {
    $conn->rollback();
    acFail('Signing failed', 500, ['code' => $e->getCode()]);
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

if (!empty($accountInfo['created']) && $email !== '') {
    try {
        require_once __DIR__ . '/helpers/mail_smtp.php';
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUser = htmlspecialchars((string) ($accountInfo['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com';
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $loginUrl = "{$scheme}://{$host}{$basePath}/admin-login.php";
        $safeLogin = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $body = "
<div style='font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6'>
    <p><strong>Parrot Canada Visa Consultant Co. Ltd.</strong></p>
    <p>Dear {$safeName},</p>
    <p>Your Agent Referral and Commission Agreement has been signed, and your <strong>agent account</strong> is ready.</p>
    <p>Username: <strong>{$safeUser}</strong><br>
    Password: the password you chose when you signed the contract.</p>
    <p style='margin:24px 0'><a href=\"{$safeLogin}\" style='display:inline-block;background:#427431;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600'>Sign in</a></p>
    <p>Kind regards,<br><strong>Parrot Canada Visa Consultant</strong></p>
</div>";
        sendSMTPMail($email, 'Your Parrot agent account is ready', $body);
    } catch (Throwable $e) {
        acLogMsg('Welcome email failed', ['contract_id' => $contractId, 'message' => $e->getMessage()]);
    }
}

acRespond([
    'success'     => true,
    'status'      => 'signed',
    'contract_id' => $contractId,
    'pdf_path'    => $pdfPath,
    'pdf_error'   => $pdfError,
    'account'     => [
        'created'  => !empty($accountInfo['created']),
        'existing' => !empty($accountInfo['existing']),
        'username' => (string) ($accountInfo['username'] ?? ''),
        'admin_id' => (int) ($accountInfo['admin_id'] ?? 0),
    ],
]);

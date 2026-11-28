<?php
/**
 * save_overtime_request.php
 * Saves staff overtime request + sends email notification
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

function ot_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers/role.php';
} catch (Throwable $e) {
    ot_json(['success' => false, 'message' => 'Server configuration error.'], 500);
}

if (!isset($conn) || $conn->connect_error) {
    ot_json(['success' => false, 'message' => 'Database unavailable.'], 500);
}

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['id'])) {
    ot_json(['success' => false, 'message' => 'Unauthorized or session expired'], 403);
}

$staffId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
if ($staffId < 1) {
    ot_json(['success' => false, 'message' => 'Unauthorized or session expired'], 403);
}

$sessionRole = (string) ($_SESSION['role'] ?? '');
$dbRole = $sessionRole;
$stRole = $conn->prepare('SELECT role, full_name, email FROM admins WHERE id = ? LIMIT 1');
$staffName = 'Staff';
$staffEmail = '';
if ($stRole) {
    $stRole->bind_param('i', $staffId);
    $stRole->execute();
    $roleRow = $stRole->get_result()->fetch_assoc() ?: null;
    $stRole->close();
    if ($roleRow) {
        $dbRole = (string) ($roleRow['role'] ?? $sessionRole);
        $staffName = trim((string) ($roleRow['full_name'] ?? '')) ?: 'Staff';
        $staffEmail = (string) ($roleRow['email'] ?? '');
    }
}
$roleKey = strtolower(trim(pcvc_normalize_role_string($dbRole !== '' ? $dbRole : $sessionRole)));
$canRequest = pcvc_is_superadmin_role($dbRole)
    || pcvc_is_staff_role($dbRole)
    || pcvc_is_agent_role($dbRole)
    || pcvc_is_admission_manager_role($dbRole)
    || in_array($roleKey, ['admin', 'director', 'secretary', 'agent', 'staff', 'accountant', 'admission_manager'], true);
if (!$canRequest) {
    ot_json(['success' => false, 'message' => 'Your role cannot submit overtime requests.'], 403);
}

$date = $_POST['date'] ?? null;
$reason = trim((string) ($_POST['reason'] ?? ''));
$hours = $_POST['hours'] ?? null;

if (($_POST['policy_accepted'] ?? '') !== '1') {
    ot_json(['success' => false, 'message' => 'Accept the overtime policy before you submit.']);
}

if (!$date || $reason === '') {
    ot_json(['success' => false, 'message' => 'Date and tasks are required']);
}

if (!is_numeric($hours) || (float) $hours < 3 || (float) $hours > 4) {
    ot_json(['success' => false, 'message' => 'Each overtime request must be between 3 and 4 hours.']);
}
$hours = (float) $hours;

$check = $conn->prepare("
    SELECT id
    FROM overtime_requests
    WHERE staff_id = ? AND status = 'pending'
    LIMIT 1
");
if (!$check) {
    ot_json(['success' => false, 'message' => 'Could not validate existing requests.'], 500);
}
$check->bind_param('i', $staffId);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    ot_json(['success' => false, 'message' => 'You already have a pending overtime request']);
}
$check->close();

$insert = $conn->prepare("
    INSERT INTO overtime_requests (
        staff_id,
        request_date,
        reason,
        expected_hours,
        status,
        created_at
    ) VALUES (?, ?, ?, ?, 'pending', NOW())
");
if (!$insert) {
    ot_json(['success' => false, 'message' => 'Failed to save overtime request'], 500);
}

$insert->bind_param('issd', $staffId, $date, $reason, $hours);
if (!$insert->execute()) {
    ot_json(['success' => false, 'message' => 'Failed to save overtime request'], 500);
}
$insert->close();

try {
    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isSMTP();
    $mail->Host = 'visaconsultantcanada.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@visaconsultantcanada.com';
    $mail->Password = 'Parrot@2026';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->setFrom('admission@visaconsultantcanada.com', 'Visa Consultant Canada');

    foreach ([
        'admission@visaconsultantcanada.com',
        'twajepeeter@gmail.com',
        'twajeepeter@gmail.com',
    ] as $email) {
        $mail->addAddress($email);
    }

    $approvalLink = 'https://mis.visaconsultantcanada.com/admin-login.php';
    $mail->isHTML(true);
    $mail->Subject = 'New Overtime Request – ' . $staffName;
    $mail->Body = '
        <h3>New Overtime Request Submitted</h3>
        <p><strong>Staff Name:</strong> ' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>Date:</strong> ' . htmlspecialchars((string) $date, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>Expected Hours:</strong> ' . htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>Tasks:</strong></p>
        <pre style="background:#f4f6f9;padding:12px;border-radius:8px;">'
        . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') .
        '</pre>
        <p>👉 <a href="' . htmlspecialchars($approvalLink, ENT_QUOTES, 'UTF-8') . '" target="_blank">
            Click here to login and approve the request
        </a></p>
        <hr><small>Generated automatically by Parrot MIS Overtime System</small>
    ';
    $mail->send();
} catch (Throwable $e) {
    error_log('Overtime mail error: ' . $e->getMessage());
}

ot_json([
    'success' => true,
    'message' => 'Overtime request submitted successfully',
]);

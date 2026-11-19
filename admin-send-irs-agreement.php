<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
irs_agreement_ensure_schema($conn);

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
pcvc_require_francophonie_mobility($conn, true);

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$id = (int) ($_POST['agreement_id'] ?? 0);
$stmt = $conn->prepare("SELECT pdf_path, client_name, client_email, agreement_reference FROM irs_agreements WHERE id = ? AND status = 'signed' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Agreement not found']);
    exit;
}

$absolute = __DIR__ . '/' . ltrim((string) $row['pdf_path'], '/');
if (!is_file($absolute)) {
    echo json_encode(['success' => false, 'error' => 'PDF file not found']);
    exit;
}
$email = trim((string) $row['client_email']);
$name = trim((string) $row['client_name']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid client email']);
    exit;
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'visaconsultantcanada.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@visaconsultantcanada.com';
    $mail->Password = 'Parrot@2026';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');
    $mail->addAddress($email, $name);
    $mail->Subject = 'Your signed Immigration Records Agreement - Parrot Canada Visa Consultant';
    $mail->isHTML(true);
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $mail->Body = "<p>Dear {$safeName},</p><p>Your Immigration Application Records Recovery and Review Support Agreement is signed. The PDF is attached.</p><p>Parrot Canada Visa Consultant</p>";
    $mail->AltBody = "Dear {$name},\n\nYour signed Immigration Records Agreement is attached.\n\nParrot Canada Visa Consultant";
    $mail->addAttachment($absolute, ($row['agreement_reference'] ?: 'agreement') . '.pdf');
    $mail->send();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Email failed']);
    exit;
}

$upd = $conn->prepare('UPDATE irs_agreements SET sent_at = NOW() WHERE id = ?');
$upd->bind_param('i', $id);
$upd->execute();
$upd->close();
echo json_encode(['success' => true]);

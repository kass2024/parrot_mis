<?php
declare(strict_types=1);

/* =====================================================
   HARD FAIL-SAFE ERROR LOGGING (FIRST LINE)
===================================================== */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* =====================================================
   LOG FILE SETUP (ABSOLUTE GUARANTEE)
===================================================== */
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$LOG = $logDir . '/receipt_email.log';

/* =====================================================
   GLOBAL LOG FUNCTION (CANNOT FAIL SILENTLY)
===================================================== */
function logMsg(string $msg, $data = null): void
{
    global $LOG;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($data !== null) {
        $line .= ' :: ' . (is_scalar($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    @file_put_contents($LOG, $line . PHP_EOL, FILE_APPEND);
}

/* =====================================================
   FATAL ERROR CAPTURE (VERY IMPORTANT)
===================================================== */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null) {
        logMsg('💥 FATAL ERROR', $err);
    }
});

/* =====================================================
   SCRIPT START
===================================================== */
logMsg('========== EMAIL ENDPOINT START ==========');

/* =====================================================
   HEADERS
===================================================== */
header('Content-Type: application/json; charset=utf-8');

/* =====================================================
   RAW REQUEST DEBUG
===================================================== */
logMsg('REQUEST METHOD', $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN');
logMsg('RAW POST', $_POST);
logMsg('RAW INPUT', file_get_contents('php://input'));

/* =====================================================
   METHOD CHECK
===================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    logMsg('❌ INVALID METHOD');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'reason' => 'method']);
    exit;
}

/* =====================================================
   SECRET CHECK
===================================================== */
$secret = $_POST['secret'] ?? '';
if ($secret !== 'RCP_9fA8kKx_2026_SECURE') {
    logMsg('❌ INVALID SECRET', $secret);
    http_response_code(403);
    echo json_encode(['status' => 'error', 'reason' => 'secret']);
    exit;
}

logMsg('SECRET OK');

/* =====================================================
   INPUT VALIDATION
===================================================== */
$receiptNo = trim((string)($_POST['receipt_no'] ?? ''));

if ($receiptNo === '') {
    logMsg('❌ RECEIPT NO EMPTY');
    echo json_encode(['status' => 'error', 'reason' => 'receipt_no']);
    exit;
}

logMsg('RECEIPT RECEIVED', $receiptNo);

/* =====================================================
   BOOTSTRAP DB + MAILER
===================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

/* =====================================================
   FETCH RECEIPT FROM DB (TRIM SAFE)
===================================================== */
$sql = "
    SELECT
        r.receipt_no,
        r.total_amount,
        r.payment_method,
        a.first_name,
        a.last_name,
        a.email
    FROM payment_receipts r
    JOIN student_applications a ON a.id = r.application_id
    WHERE TRIM(r.receipt_no) = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    logMsg('❌ SQL PREPARE FAILED', $conn->error);
    echo json_encode(['status' => 'error', 'reason' => 'sql_prepare']);
    exit;
}

$stmt->bind_param('s', $receiptNo);
$stmt->execute();
$result = $stmt->get_result();
$data   = $result ? $result->fetch_assoc() : null;
$stmt->close();

logMsg('DB QUERY RESULT', $data);

if (!$data || empty($data['email'])) {
    logMsg('❌ RECEIPT OR EMAIL NOT FOUND');
    echo json_encode(['status' => 'error', 'reason' => 'not_found']);
    exit;
}

/* =====================================================
   PDF CHECK
===================================================== */
$pdfPath = __DIR__ . '/receipts/' . $receiptNo . '.pdf';
logMsg('CHECKING PDF PATH', $pdfPath);

if (!is_file($pdfPath)) {
    logMsg('❌ PDF NOT FOUND');
    echo json_encode(['status' => 'error', 'reason' => 'pdf_missing']);
    exit;
}

/* =====================================================
   SEND EMAIL
===================================================== */
try {
    logMsg('SMTP INIT');

    $studentName = trim($data['first_name'] . ' ' . $data['last_name']);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'visaconsultantcanada.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'admission@visaconsultantcanada.com';
    $mail->Password   = getenv('SMTP_PASSWORD') ?: 'Petero@1981';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    $mail->setFrom(
        'admission@visaconsultantcanada.com',
        'Parrot Canada Visa Consultant – Finance'
    );

    $mail->addAddress($data['email'], $studentName);

    $mail->Subject = "Payment Receipt – {$receiptNo}";
    $mail->Body = "
        <p>Dear <strong>{$studentName}</strong>,</p>
        <p>Your official payment receipt is attached.</p>
        <p><strong>Receipt:</strong> {$receiptNo}</p>
        <p><strong>Amount:</strong> {$data['total_amount']}</p>
        <p><strong>Method:</strong> {$data['payment_method']}</p>
        <br>
        <p>Parrot Canada Visa Consultant – Finance</p>
    ";

    $mail->addAttachment($pdfPath, $receiptNo . '.pdf');

    $mail->send();

    logMsg('✅ EMAIL SENT SUCCESSFULLY');

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    logMsg('💥 EMAIL EXCEPTION', $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'reason' => 'mail']);
}

$conn->close();
logMsg('========== EMAIL ENDPOINT END ==========');
exit;

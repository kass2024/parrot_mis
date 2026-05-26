<?php
declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/receipt_render.php';
require_once __DIR__ . '/mailer.php';
require_once dirname(__DIR__) . '/generateReceiptPdf.php';
require_once dirname(__DIR__) . '/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/PHPMailer/src/SMTP.php';
require_once dirname(__DIR__) . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

function pcvc_special_payment_notify_log(string $msg, $data = null): void
{
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/special_payment_notify.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($data !== null) {
        $line .= ' :: ' . (is_scalar($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Resolve admin notify email from .env (SPECIAL_PAYMENT_NOTIFY_EMAIL).
 */
function pcvc_special_payment_notify_email(): string
{
    xander_load_env_file();

    $email = trim(xander_env_get('SPECIAL_PAYMENT_NOTIFY_EMAIL'));
    if ($email === '') {
        $email = trim(xander_env_get_from_dotenv_file('SPECIAL_PAYMENT_NOTIFY_EMAIL'));
    }
    if ($email === '') {
        $email = 'hatheo75@gmail.com';
    }

    return $email;
}

/**
 * Build PHPMailer using the same SMTP settings as sendReceiptEmail.php.
 */
function pcvc_special_payment_smtp_mailer(): PHPMailer
{
    xander_load_env_file();

    $host = xander_env_get('SMTP_HOST') ?: 'visaconsultantcanada.com';
    $username = xander_env_get('SMTP_USERNAME') ?: 'admission@visaconsultantcanada.com';
    $password = xander_env_get('SMTP_PASSWORD');
    if ($password === '') {
        $password = xander_env_get_from_dotenv_file('SMTP_PASSWORD');
    }
    if ($password === '') {
        $fromGetenv = getenv('SMTP_PASSWORD');
        $password = ($fromGetenv !== false && trim((string) $fromGetenv) !== '')
            ? trim((string) $fromGetenv)
            : 'Petero@1981';
    }

    $portStr = xander_env_get('SMTP_PORT');
    $port = $portStr !== '' ? (int) $portStr : 465;
    $fromEmail = xander_env_get('SMTP_FROM_EMAIL') ?: 'admission@visaconsultantcanada.com';

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $port > 0 ? $port : 465;
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom($fromEmail, 'Parrot Canada Visa Consultant – Finance');
    $mail->addReplyTo($fromEmail, 'Parrot Canada Finance');

    return $mail;
}

/**
 * Email admin after a special-program payment (email only — WhatsApp disabled).
 */
function pcvc_send_special_payment_notify(mysqli $conn, string $receiptNo): array
{
    $receiptNo = trim($receiptNo);
    if ($receiptNo === '') {
        return ['status' => 'error', 'reason' => 'receipt_no'];
    }

    $data = pcvc_load_receipt_data($conn, $receiptNo);
    if (!$data) {
        pcvc_special_payment_notify_log('Receipt not found', $receiptNo);
        return ['status' => 'error', 'reason' => 'not_found'];
    }

    $notifyEmail = pcvc_special_payment_notify_email();
    pcvc_special_payment_notify_log('Notify email target', $notifyEmail);

    $programLabel = match ($data['source_table'] ?? '') {
        'credit_transfer_applications' => 'Credit Transfer',
        'upafa_registrations'            => 'UPAFA Registration',
        default                          => 'Special Program',
    };

    $studentName = (string) ($data['customer_name'] ?? 'Unknown');
    $currency    = (string) ($data['currency'] ?? '');
    $totalPaid   = number_format((float) ($data['total_amount'] ?? 0), 2);
    $method      = (string) ($data['payment_method'] ?? '');
    $package     = (string) ($data['package_title'] ?? '');

    $pdfPath = dirname(__DIR__) . '/receipts/' . $receiptNo . '.pdf';
    if (!is_file($pdfPath)) {
        try {
            $pdfPath = generateReceiptPdf($receiptNo, $conn);
        } catch (Throwable $e) {
            pcvc_special_payment_notify_log('PDF generation failed', $e->getMessage());
        }
    }

    $emailSent = false;
    $errors    = [];

    $recipients = [];
    $seen = [];
    foreach ([$notifyEmail, $mailFrom = (xander_env_get('SMTP_FROM_EMAIL') ?: 'admission@visaconsultantcanada.com')] as $addr) {
        $addr = trim(strtolower($addr));
        if ($addr === '' || isset($seen[$addr])) {
            continue;
        }
        $seen[$addr] = true;
        $recipients[] = $addr;
    }

    pcvc_special_payment_notify_log('Sending admin email', [
        'recipients' => $recipients,
        'student'    => $studentName,
        'receipt'    => $receiptNo,
    ]);

    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $subject = "Payment Recorded — {$programLabel} — {$receiptNo}";

    $plainBody = "New payment recorded\n\n"
        . "Program: {$programLabel}\n"
        . "Student: {$studentName}\n"
        . "Receipt: {$receiptNo}\n"
        . "Package: {$package}\n"
        . "Amount: {$currency} {$totalPaid}\n"
        . "Method: {$method}\n\n"
        . "The student was NOT emailed. Receipt is in the dashboard.";

    $htmlBody = '
    <div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2933;max-width:640px;">
      <div style="background:#0b3c5d;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;">
        <div style="font-size:18px;font-weight:800;">New Payment — ' . $h($programLabel) . '</div>
      </div>
      <div style="border:1px solid #e5e7eb;border-top:0;padding:20px;background:#fff;border-radius:0 0 8px 8px;">
        <p>A payment was recorded for a <strong>' . $h($programLabel) . '</strong> student. The student was <strong>not</strong> emailed.</p>
        <table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:13px;">
          <tr><td style="padding:6px 0;color:#6b7280;width:130px;"><strong>Student</strong></td><td>' . $h($studentName) . '</td></tr>
          <tr><td style="padding:6px 0;color:#6b7280;"><strong>Receipt No.</strong></td><td>' . $h($receiptNo) . '</td></tr>
          <tr><td style="padding:6px 0;color:#6b7280;"><strong>Package</strong></td><td>' . $h($package) . '</td></tr>
          <tr><td style="padding:6px 0;color:#6b7280;"><strong>Amount</strong></td><td style="font-weight:700;color:#0b3c5d;">' . $h($currency . ' ' . $totalPaid) . '</td></tr>
          <tr><td style="padding:6px 0;color:#6b7280;"><strong>Method</strong></td><td>' . $h($method) . '</td></tr>
        </table>
        <p style="font-size:12px;color:#6b7280;">Receipt is available in the dashboard under Check payment Receipt.</p>
      </div>
    </div>';

    foreach ($recipients as $recipient) {
        try {
            $mail = pcvc_special_payment_smtp_mailer();
            $mail->addAddress($recipient, 'Finance Admin');
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $plainBody;

            if (is_file($pdfPath)) {
                $mail->addAttachment($pdfPath, $receiptNo . '.pdf');
            }

            $mail->send();
            $emailSent = true;
            pcvc_special_payment_notify_log('Admin email SMTP accepted', [
                'to'         => $recipient,
                'from'       => $mail->From,
                'subject'    => $subject,
                'message_id' => $mail->getLastMessageID(),
            ]);
        } catch (Throwable $e) {
            $errors[] = 'email(' . $recipient . '): ' . $e->getMessage();
            pcvc_special_payment_notify_log('Admin email failed', [
                'to'    => $recipient,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return [
        'status'        => $emailSent ? 'ok' : 'error',
        'email_sent'    => $emailSent,
        'whatsapp_sent' => false,
        'errors'        => $errors,
    ];
}

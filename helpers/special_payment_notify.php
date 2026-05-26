<?php
declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/receipt_render.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/student_status_notify.php';
require_once dirname(__DIR__) . '/generateReceiptPdf.php';

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
 * Email + WhatsApp admin after a special-program payment. Returns result summary.
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

    xander_load_env_file();

    $notifyEmail = trim(xander_env_get('SPECIAL_PAYMENT_NOTIFY_EMAIL'));
    if ($notifyEmail === '') {
        $notifyEmail = trim(xander_env_get_from_dotenv_file('SPECIAL_PAYMENT_NOTIFY_EMAIL'));
    }
    if ($notifyEmail === '') {
        $notifyEmail = 'hatheo75@gmail.com';
    }

    $notifyPhone = trim(xander_env_get('SPECIAL_PAYMENT_NOTIFY_WHATSAPP'));
    if ($notifyPhone === '') {
        $notifyPhone = trim(xander_env_get_from_dotenv_file('SPECIAL_PAYMENT_NOTIFY_WHATSAPP'));
    }
    if ($notifyPhone === '') {
        $notifyPhone = '+250783314265';
    }

    pcvc_special_payment_notify_log('Notify targets', ['email' => $notifyEmail, 'whatsapp' => $notifyPhone]);

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
    $waSent    = false;
    $errors    = [];

    try {
        $mail = app_mailer('Parrot Canada Visa Consultant – Finance');
        $mail->addAddress($notifyEmail, 'Finance Admin');

        $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $mail->Subject = "Payment Recorded — {$programLabel} — {$receiptNo}";
        $mail->Body = '
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

        if (is_file($pdfPath)) {
            $mail->addAttachment($pdfPath, $receiptNo . '.pdf');
        }

        $mail->send();
        $emailSent = true;
        pcvc_special_payment_notify_log('Admin email sent', $notifyEmail);
    } catch (Throwable $e) {
        $errors[] = 'email: ' . $e->getMessage();
        pcvc_special_payment_notify_log('Admin email failed', $e->getMessage());
    }

    $waBody = "New payment recorded\n\n"
        . "Program: {$programLabel}\n"
        . "Student: {$studentName}\n"
        . "Receipt: {$receiptNo}\n"
        . "Package: {$package}\n"
        . "Amount: {$currency} {$totalPaid}\n"
        . "Method: {$method}\n\n"
        . "Student was NOT emailed. Receipt is in the dashboard.";

    $token   = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    $phoneId = xander_env_get('WHATSAPP_PHONE_NUMBER_ID');
    $version = xander_env_get('META_GRAPH_VERSION') ?: 'v19.0';

    if ($token !== '' && $phoneId !== '') {
        $defaultCountry = xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE') ?: 'RW';
        $toE164 = xander_format_phone_for_whatsapp_e164($notifyPhone, $defaultCountry);

        if ($toE164) {
            $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
            $waResult = xander_whatsapp_send_template_or_session(
                $toE164,
                $url,
                $token,
                '',
                'en_US',
                0,
                [],
                $waBody
            );
            $waSent = !empty($waResult['sent']);
            if ($waSent) {
                pcvc_special_payment_notify_log('WhatsApp sent', $notifyPhone);
            } else {
                $errors[] = 'whatsapp: ' . ($waResult['error'] ?? 'failed');
                pcvc_special_payment_notify_log('WhatsApp failed', $waResult);
            }
        } else {
            $errors[] = 'whatsapp: invalid phone number';
            pcvc_special_payment_notify_log('Invalid WhatsApp number', $notifyPhone);
        }
    } else {
        $errors[] = 'whatsapp: not configured';
        pcvc_special_payment_notify_log('WhatsApp credentials missing');
    }

    return [
        'status'        => ($emailSent || $waSent) ? 'ok' : 'partial',
        'email_sent'    => $emailSent,
        'whatsapp_sent' => $waSent,
        'errors'        => $errors,
    ];
}

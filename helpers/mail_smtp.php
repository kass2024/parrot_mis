<?php
/**
 * Shared PHPMailer SMTP — delegates to helpers/mailer.php (.env SMTP_*).
 */
require_once __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @return PHPMailer Configured for SMTP; caller sets recipients, subject, body.
 */
function xander_create_phpmailer(): PHPMailer
{
    return app_mailer('Parrot Canada Visa Consultant');
}

/**
 * SMTP identity used for outbound mail to applicants (matches send-job-Email / legacy scripts).
 */
function xander_create_phpmailer_applicant_sender(): PHPMailer
{
    return app_admission_mailer();
}

/**
 * Simple HTML email send with optional attachments.
 *
 * @param array<int, array{path:string, name?:string}> $attachments
 */
function sendSMTPMail(string $to, string $subject, string $htmlBody, array $attachments = []): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $mail = xander_create_phpmailer_applicant_sender();
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        foreach ($attachments as $att) {
            $path = $att['path'] ?? '';
            if ($path !== '' && is_file($path)) {
                $mail->addAttachment($path, $att['name'] ?? basename($path));
            }
        }

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('sendSMTPMail failed: ' . $e->getMessage());
        return false;
    }
}

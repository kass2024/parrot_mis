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
 * Simple HTML email send with optional attachments, BCC, and CC copies.
 *
 * @param array<int, array{path:string, name?:string}> $attachments
 * @param string[] $bcc
 * @param string[] $cc
 */
function sendSMTPMail(string $to, string $subject, string $htmlBody, array $attachments = [], array $bcc = [], array $cc = [], ?string $replyTo = null): bool
{
    return sendSMTPMailDetailed($to, $subject, $htmlBody, $attachments, $bcc, null, $cc, $replyTo)['ok'];
}

/**
 * @param array<int, array{path:string, name?:string}> $attachments
 * @param string[] $bcc
 * @param string[] $cc
 * @return array{ok: bool, error?: string}
 */
function sendSMTPMailDetailed(
    string $to,
    string $subject,
    string $htmlBody,
    array $attachments = [],
    array $bcc = [],
    ?callable $mailerFactory = null,
    array $cc = [],
    ?string $replyTo = null
): array {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email'];
    }
    $replyTo = trim((string) $replyTo);
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = '';
    }

    $mail = null;
    try {
        $mail = $mailerFactory ? $mailerFactory() : xander_create_phpmailer_applicant_sender();
        if (pcvc_smtp_auth_is_down()) {
            return pcvc_send_via_local_mail($mail, $to, $subject, $htmlBody, $attachments, $bcc, $cc, $replyTo);
        }
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();
        $mail->clearReplyTos();
        $mail->addAddress($to);
        if ($replyTo !== '' && strcasecmp($replyTo, $to) !== 0) {
            $mail->addReplyTo($replyTo);
        }
        foreach ($cc as $copy) {
            $copy = trim((string) $copy);
            if ($copy !== '' && filter_var($copy, FILTER_VALIDATE_EMAIL) && strcasecmp($copy, $to) !== 0) {
                $mail->addCC($copy);
            }
        }
        foreach ($bcc as $copy) {
            $copy = trim((string) $copy);
            if ($copy !== '' && filter_var($copy, FILTER_VALIDATE_EMAIL) && strcasecmp($copy, $to) !== 0) {
                $mail->addBCC($copy);
            }
        }
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
        pcvc_clear_smtp_auth_down();

        return ['ok' => true];
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        error_log('sendSMTPMail failed: ' . $msg);
        if ($mail instanceof PHPMailer && pcvc_smtp_should_use_local_mail($msg)) {
            pcvc_mark_smtp_auth_down();
            return pcvc_send_via_local_mail($mail, $to, $subject, $htmlBody, $attachments, $bcc, $cc, $replyTo);
        }

        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'SMTP send failed'];
    }
}

function pcvc_smtp_auth_down_flag(): string
{
    return dirname(__DIR__) . '/logs/smtp_auth_down.flag';
}

function pcvc_smtp_auth_is_down(): bool
{
    $flag = pcvc_smtp_auth_down_flag();
    if (!is_file($flag)) {
        return false;
    }
    $age = time() - (int) filemtime($flag);

    return $age >= 0 && $age < 6 * 3600;
}

function pcvc_mark_smtp_auth_down(): void
{
    $flag = pcvc_smtp_auth_down_flag();
    $dir = dirname($flag);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
    }
    @touch($flag);
}

function pcvc_clear_smtp_auth_down(): void
{
    $flag = pcvc_smtp_auth_down_flag();
    if (is_file($flag)) {
        @unlink($flag);
    }
}

function pcvc_smtp_should_use_local_mail(string $error): bool
{
    $error = strtolower($error);
    return str_contains($error, 'could not authenticate')
        || str_contains($error, 'smtp connect()')
        || str_contains($error, 'smtp error');
}

/**
 * cPanel local mail transport. Used when the shared SMTP login in .env is rejected.
 *
 * @param array<int, array{path:string, name?:string}> $attachments
 * @param string[] $bcc
 * @param string[] $cc
 * @return array{ok: bool, error?: string}
 */
function pcvc_send_via_local_mail(
    PHPMailer $smtpMail,
    string $to,
    string $subject,
    string $htmlBody,
    array $attachments,
    array $bcc,
    array $cc,
    string $replyTo = ''
): array {
    try {
        $fromEmail = trim((string) $smtpMail->From);
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'admission@visaconsultantcanada.com';
        }
        $fromName = trim((string) $smtpMail->FromName);
        if ($fromName === '') {
            $fromName = 'Parrot Canada Visa Consultant';
        }

        $mail = new PHPMailer(true);
        $mail->isMail();
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->setFrom($fromEmail, $fromName);
        $mail->Sender = $fromEmail;
        $mail->addAddress($to);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && strcasecmp($replyTo, $to) !== 0) {
            $mail->addReplyTo($replyTo);
        }
        foreach ($cc as $copy) {
            $copy = trim((string) $copy);
            if ($copy !== '' && filter_var($copy, FILTER_VALIDATE_EMAIL) && strcasecmp($copy, $to) !== 0) {
                $mail->addCC($copy);
            }
        }
        foreach ($bcc as $copy) {
            $copy = trim((string) $copy);
            if ($copy !== '' && filter_var($copy, FILTER_VALIDATE_EMAIL) && strcasecmp($copy, $to) !== 0) {
                $mail->addBCC($copy);
            }
        }
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
        error_log('sendSMTPMail delivered via local mail to ' . $to);

        return ['ok' => true];
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        error_log('sendSMTPMail local mail failed: ' . $msg);

        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Local mail send failed'];
    }
}

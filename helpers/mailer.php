<?php
declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Central SMTP settings for PHPMailer across the project.
 * Credentials are read from project-root .env (SMTP_* keys).
 */
function app_mailer(?string $fromNameOverride = null): PHPMailer
{
    xander_load_env_file();

    $host = xander_env_get('SMTP_HOST') ?: 'visaconsultantcanada.com';
    $username = xander_env_get('SMTP_USERNAME') ?: 'admission@visaconsultantcanada.com';
    $password = xander_env_get('SMTP_PASSWORD');
    $portStr = xander_env_get('SMTP_PORT');
    $port = $portStr !== '' ? (int) $portStr : 465;
    $fromEmail = xander_env_get('SMTP_FROM_EMAIL') ?: $username;
    $fromName = $fromNameOverride
        ?? (xander_env_get('SMTP_FROM_NAME') ?: 'Visa Consultant Canada – Admission Department');

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $port > 0 ? $port : 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->isHTML(true);
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

/**
 * Finance/receipt SMTP — same settings as sendReceiptEmail.php (.env SMTP_* with fallbacks).
 */
function pcvc_finance_smtp_mailer(?string $fromNameOverride = null): PHPMailer
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
    $fromEmail = xander_env_get('SMTP_FROM_EMAIL') ?: $username;
    $fromName = $fromNameOverride
        ?? (xander_env_get('SMTP_FROM_NAME') ?: 'Parrot Canada Visa Consultant – Finance');
    $fromName = trim($fromName, " \t\n\r\0\x0B\"'");

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $port > 0 ? $port : 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->isHTML(true);
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

/**
 * Mailer preset for applicant-facing admission letters.
 */
function app_admission_mailer(): PHPMailer
{
    return app_mailer('Parrot Canada Visa Consultant');
}


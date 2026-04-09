<?php
/**
 * Shared PHPMailer SMTP — single place for mail credentials (same pattern as send_loan_email.php).
 * Primary mailbox: admission@visaconsultantcanada.com (see .env SMTP_* for overrides in mailer.php).
 */
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * @return PHPMailer Configured for SMTP; caller sets recipients, subject, body.
 */
function xander_create_phpmailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'visaconsultantcanada.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@visaconsultantcanada.com';
    $mail->Password = 'Petero@1981';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;

    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');

    return $mail;
}

/**
 * SMTP identity used for outbound mail to applicants (matches send-job-Email / legacy scripts).
 */
function xander_create_phpmailer_applicant_sender(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'visaconsultantcanada.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@visaconsultantcanada.com';
    $mail->Password = 'Petero@1981';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');

    return $mail;
}

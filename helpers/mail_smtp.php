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

<?php
declare(strict_types=1);

function eci_ensure_schema(mysqli $conn): bool
{
    static $ran = false;
    static $ok = false;
    if ($ran) {
        return $ok;
    }
    $ran = true;

    $uploadDir = dirname(__DIR__) . '/uploads/eci_malta';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $htaccess = $uploadDir . '/.htaccess';
    if (is_dir($uploadDir) && !is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Block direct HTTP access.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
        );
    }

    $sql = "CREATE TABLE IF NOT EXISTS `eci_malta_applications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` varchar(80) NOT NULL,
      `reference_id` varchar(24) NOT NULL,
      `first_name` varchar(120) NOT NULL,
      `last_name` varchar(120) NOT NULL,
      `title` varchar(30) NOT NULL DEFAULT '',
      `previous_family_name` varchar(120) NOT NULL DEFAULT '',
      `gender` varchar(20) NOT NULL DEFAULT '',
      `date_of_birth` date DEFAULT NULL,
      `nationality` varchar(80) NOT NULL DEFAULT '',
      `english_first` varchar(3) NOT NULL DEFAULT '',
      `disability` varchar(3) NOT NULL DEFAULT '',
      `studied_before` varchar(3) NOT NULL DEFAULT '',
      `studied_details` varchar(255) NOT NULL DEFAULT '',
      `permanent_address` text,
      `correspondence_address` text,
      `phone` varchar(40) NOT NULL DEFAULT '',
      `whatsapp` varchar(80) NOT NULL DEFAULT '',
      `linkedin` varchar(255) NOT NULL DEFAULT '',
      `email` varchar(150) NOT NULL,
      `course_title` varchar(255) NOT NULL DEFAULT '',
      `entry_month` varchar(10) NOT NULL DEFAULT '',
      `entry_year` varchar(4) NOT NULL DEFAULT '',
      `study_mode` varchar(4) NOT NULL DEFAULT '',
      `qualifications_json` mediumtext,
      `criminal_conviction` varchar(3) NOT NULL DEFAULT '',
      `ethnic_origin` varchar(80) NOT NULL DEFAULT '',
      `english_test` varchar(3) NOT NULL DEFAULT '',
      `english_result` varchar(80) NOT NULL DEFAULT '',
      `via_agent` varchar(3) NOT NULL DEFAULT '',
      `agent_details` varchar(255) NOT NULL DEFAULT '',
      `signature_name` varchar(200) NOT NULL DEFAULT '',
      `signature_date` date DEFAULT NULL,
      `passport_file` varchar(255) NOT NULL DEFAULT '',
      `transcripts_file` varchar(255) NOT NULL DEFAULT '',
      `cv_file` varchar(255) NOT NULL DEFAULT '',
      `english_file` varchar(255) NOT NULL DEFAULT '',
      `photo_file` varchar(255) NOT NULL DEFAULT '',
      `status` enum('pending','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
      `source` varchar(20) NOT NULL DEFAULT 'public',
      `admin_notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `reference_id` (`reference_id`),
      UNIQUE KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('eci_ensure_schema failed: ' . $conn->error);
        return false;
    }
    $ok = true;
    return true;
}

/**
 * One-year ECI programmes. Dual-level diplomas are listed once per MQF level
 * so the applicant selects a single award.
 *
 * @return array<string,array{title:string,level:string}>
 */
function eci_course_programs(): array
{
    return [
        'it_l5' => ['title' => 'Diploma in Information Technology (MQF Level 5)', 'level' => '5'],
        'tourism_l5' => ['title' => 'Diploma in Tourism and Hospitality Management (MQF Level 5)', 'level' => '5'],
        'tourism_l7' => ['title' => 'Diploma in Tourism and Hospitality Management (MQF Level 7)', 'level' => '7'],
        'business_l5' => ['title' => 'Diploma in Business Management (MQF Level 5)', 'level' => '5'],
        'strategic_l7' => ['title' => 'Diploma in Strategic Management and Leadership (MQF Level 7)', 'level' => '7'],
        'health_l5' => ['title' => 'Diploma in Health and Social Care Management (MQF Level 5)', 'level' => '5'],
        'health_l7' => ['title' => 'Diploma in Health and Social Care Management (MQF Level 7)', 'level' => '7'],
    ];
}

/** @return array<string,string> */
function eci_entry_months(): array
{
    return [
        'Jan' => 'Jan',
        'Apr' => 'Apr',
        'Jul' => 'Jul',
        'Oct' => 'Oct',
    ];
}

/** @return array<string,string> */
function eci_study_modes(): array
{
    return [
        'FT' => 'FT',
        'PT' => 'PT',
        'DL' => 'DL',
    ];
}

/** @return list<string> */
function eci_ethnic_options(): array
{
    return [
        'Arab',
        'Asian – Bangladeshi',
        'Asian – Chinese',
        'Asian – Indian',
        'Asian – Other',
        'Asian – Pakistani',
        'Black – African',
        'Black – Caribbean',
        'Black – other',
        'Gypsy, Traveller or Irish Traveller',
        'Other',
        'Other mixed',
        'White',
        'White and Asian',
        'White/Black African',
        'White/Black Caribbean',
    ];
}

function eci_store_upload(?array $file, string $prefix, bool $required): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('Please attach every required document.');
        }
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('A document could not be uploaded. Please try again.');
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('Each document must be 8 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Documents must be PDF, JPG, PNG, DOC, or DOCX.');
    }
    $dir = dirname(__DIR__) . '/uploads/eci_malta';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not store the documents.');
    }
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not store the documents.');
    }
    return 'uploads/eci_malta/' . $name;
}

/**
 * @param array<string,string> $application
 * @param array<string,string> $files
 * @return array{ok:bool, error?:string}
 */
function eci_email_application_copy(array $application, array $files): array
{
    try {
        require_once __DIR__ . '/mail_smtp.php';
        $esc = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };
        $body = '<div style="font-family:Arial,sans-serif;color:#111;line-height:1.45">';
        $body .= '<h2 style="margin:0 0 8px">ECI student application — study in Malta</h2>';
        $body .= '<p style="margin:0 0 14px">This is a completed European College of Innovation student application. The form details are below and the supporting documents are attached. A copy is sent to Parrot Canada Visa Consultant.</p>';
        $body .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:720px">';
        foreach ($application as $label => $value) {
            $body .= '<tr><td style="border:1px solid #ccc;font-weight:700;width:220px;background:#f6f6f6">'
                . $esc($label) . '</td><td style="border:1px solid #ccc">' . nl2br($esc($value)) . '</td></tr>';
        }
        $body .= '</table></div>';

        $root = dirname(__DIR__);
        $labels = [
            'passport' => 'Passport-ID',
            'transcripts' => 'Academic-transcripts',
            'cv' => 'CV',
            'english' => 'English-language-evidence',
            'photo' => 'Passport-photo',
        ];
        $attachments = [];
        foreach ($labels as $key => $label) {
            $rel = str_replace('\\', '/', (string) ($files[$key] ?? ''));
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $path = $root . '/' . ltrim($rel, '/');
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $attachments[] = [
                'path' => $path,
                'name' => $label . ($ext !== '' ? '.' . $ext : ''),
            ];
        }

        $reference = (string) ($application['Reference'] ?? '');
        $name = trim((string) ($application['Forename(s)'] ?? '') . ' ' . (string) ($application['Surname'] ?? ''));
        $replyTo = trim((string) ($application['Email'] ?? ''));
        $mailer = static function () {
            $mail = xander_create_phpmailer_applicant_sender();
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            return $mail;
        };
        $result = sendSMTPMailDetailed(
            'info@eci.com.mt',
            'ECI Malta application ' . $reference . ' — ' . $name,
            $body,
            $attachments,
            [],
            $mailer,
            ['infos@visaconsultantcanada.com'],
            $replyTo !== '' ? $replyTo : null
        );
        $confirm = ['ok' => false, 'error' => 'Applicant email is missing'];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $confirmBody = '<div style="font-family:Arial,sans-serif;color:#111;line-height:1.5">';
            $confirmBody .= '<p>Dear ' . $esc($name) . ',</p>';
            $confirmBody .= '<p>Your European College of Innovation student application has been received by Parrot Canada Visa Consultant. Please keep this reference:</p>';
            $confirmBody .= '<p style="font-size:18px;font-weight:700;letter-spacing:.04em">' . $esc($reference) . '</p>';
            $confirmBody .= '<p>Our team will review your form and documents and contact you at this email address.</p>';
            $confirmBody .= '<p>Parrot Canada Visa Consultant</p></div>';
            $confirm = sendSMTPMailDetailed(
                $replyTo,
                'We received your ECI Malta application ' . $reference,
                $confirmBody,
                [],
                [],
                $mailer,
                [],
                'infos@visaconsultantcanada.com'
            );
        }
        $log = '';
        if (empty($result['ok'])) {
            $log .= date('c') . ' office ' . $reference . ' ' . ($result['error'] ?? 'send failed') . PHP_EOL;
        }
        if (empty($confirm['ok'])) {
            $log .= date('c') . ' applicant ' . $reference . ' ' . ($confirm['error'] ?? 'send failed') . PHP_EOL;
        }
        if ($log !== '') {
            @file_put_contents($root . '/logs/eci_malta_email.log', $log, FILE_APPEND);
        }
        return [
            'ok' => !empty($result['ok']) && !empty($confirm['ok']),
            'office' => $result,
            'applicant' => $confirm,
        ];
    } catch (Throwable $e) {
        error_log('eci malta application email failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

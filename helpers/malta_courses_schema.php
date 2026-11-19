<?php
declare(strict_types=1);

function malta_ensure_schema(mysqli $conn): bool
{
    static $ran = false;
    static $ok = false;
    if ($ran) {
        return $ok;
    }
    $ran = true;

    $uploadDir = dirname(__DIR__) . '/uploads/malta_courses';
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

    $sql = "CREATE TABLE IF NOT EXISTS `malta_courses_applications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` varchar(80) NOT NULL,
      `reference_id` varchar(24) NOT NULL,
      `full_name` varchar(200) NOT NULL,
      `email` varchar(150) NOT NULL,
      `phone` varchar(40) NOT NULL,
      `country` varchar(120) NOT NULL,
      `program` varchar(40) NOT NULL,
      `passport_file` varchar(255) NOT NULL,
      `cv_file` varchar(255) NOT NULL,
      `academic_file` varchar(255) NOT NULL,
      `status` enum('pending','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
      `source` varchar(20) NOT NULL DEFAULT 'public',
      `admin_notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `reference_id` (`reference_id`),
      UNIQUE KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      KEY `program` (`program`),
      KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('malta_ensure_schema failed: ' . $conn->error);
        return false;
    }
    $ok = true;
    return true;
}

/** @return array<string,string> */
function malta_program_options(): array
{
    return [
        'electrician' => 'Professional Electrician',
        'plumber' => 'Professional Plumber',
        'welder' => 'Professional Welder',
        'hotel_manager' => 'Hotel Manager',
        'restaurant_manager' => 'Restaurant Manager',
        'yacht_interior' => 'Yacht Interior',
    ];
}

function malta_program_label(string $key): string
{
    $map = malta_program_options();
    return $map[$key] ?? ($key !== '' ? $key : '—');
}

/** @return list<string> */
function malta_country_options(): array
{
    return [
        'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Antigua and Barbuda', 'Argentina', 'Armenia', 'Australia', 'Austria',
        'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan',
        'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cabo Verde', 'Cambodia',
        'Cameroon', 'Canada', 'Central African Republic', 'Chad', 'Chile', 'China', 'Colombia', 'Comoros', 'Congo', 'Costa Rica',
        'Croatia', 'Cuba', 'Cyprus', 'Czechia', 'Democratic Republic of the Congo', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'Ecuador',
        'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Eswatini', 'Ethiopia', 'Fiji', 'Finland', 'France',
        'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
        'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland',
        'Israel', 'Italy', 'Ivory Coast', 'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Kuwait',
        'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg',
        'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Mauritania', 'Mauritius', 'Mexico',
        'Micronesia', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Morocco', 'Mozambique', 'Myanmar', 'Namibia', 'Nauru',
        'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'North Korea', 'North Macedonia', 'Norway', 'Oman',
        'Pakistan', 'Palau', 'Palestine', 'Panama', 'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Poland', 'Portugal',
        'Qatar', 'Romania', 'Russia', 'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Vincent and the Grenadines', 'Samoa', 'San Marino', 'Sao Tome and Principe',
        'Saudi Arabia', 'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia',
        'South Africa', 'South Korea', 'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Sweden', 'Switzerland', 'Syria',
        'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand', 'Timor-Leste', 'Togo', 'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Turkey',
        'Turkmenistan', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay', 'Uzbekistan', 'Vanuatu',
        'Vatican City', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe',
    ];
}

/** @return list<string> */
function malta_featured_countries(): array
{
    return ['Rwanda', 'Burundi', 'Uganda', 'Kenya', 'Tanzania', 'Ghana', 'Zambia'];
}

function malta_store_upload(array $file, string $prefix): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload all three documents.');
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('Each document must be 8 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Documents must be PDF, JPG, PNG, DOC, or DOCX.');
    }
    $dir = dirname(__DIR__) . '/uploads/malta_courses';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not store the documents.');
    }
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not store the documents.');
    }
    return 'uploads/malta_courses/' . $name;
}

/**
 * Email a full copy of a Malta application, with the uploaded documents attached.
 *
 * @param array{reference:string,full_name:string,email:string,phone:string,country:string,program:string,source:string} $application
 * @param array{passport:string,cv:string,academic:string} $files relative upload paths
 * @return array{ok:bool, error?:string}
 */
function malta_email_application_copy(array $application, array $files): array
{
    try {
        require_once __DIR__ . '/mail_smtp.php';
        $esc = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };
        $program = malta_program_label((string) ($application['program'] ?? ''));
        $rows = [
            'Reference' => (string) ($application['reference'] ?? ''),
            'Full name' => (string) ($application['full_name'] ?? ''),
            'Email' => (string) ($application['email'] ?? ''),
            'Phone / WhatsApp' => (string) ($application['phone'] ?? ''),
            'Country' => (string) ($application['country'] ?? ''),
            'Program' => $program,
            'Source' => (string) ($application['source'] ?? 'public') === 'staff' ? 'Added by staff' : 'Public form',
            'Submitted' => date('Y-m-d H:i'),
        ];
        $body = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#122018;line-height:1.45">';
        $body .= '<h2 style="margin:0 0 8px;color:#1b4332">Malta professional course application</h2>';
        $body .= '<p style="margin:0 0 14px">This is a full copy of the submitted form. The passport, CV, and academic documents are attached to this email.</p>';
        $body .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px">';
        foreach ($rows as $label => $value) {
            $body .= '<tr><td style="border:1px solid #d7e0da;font-weight:700;width:190px;background:#f6faf7">'
                . $esc($label) . '</td><td style="border:1px solid #d7e0da">' . $esc($value) . '</td></tr>';
        }
        $body .= '</table></div>';

        $root = dirname(__DIR__);
        $labels = [
            'passport' => 'Passport',
            'cv' => 'CV',
            'academic' => 'Academic-documents',
        ];
        $attachments = [];
        foreach ($labels as $key => $label) {
            $rel = str_replace('\\', '/', (string) ($files[$key] ?? ''));
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $path = $root . '/' . ltrim($rel, '/');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $attachments[] = [
                'path' => $path,
                'name' => $label . ($ext !== '' ? '.' . $ext : ''),
            ];
        }

        $reference = (string) ($application['reference'] ?? '');
        $name = (string) ($application['full_name'] ?? '');
        $replyTo = trim((string) ($application['email'] ?? ''));
        $mailer = static function () {
            $mail = xander_create_phpmailer_applicant_sender();
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            return $mail;
        };
        $result = sendSMTPMailDetailed(
            'info@6meuropeanacademy.com',
            'Malta application ' . $reference . ' — ' . $name,
            $body,
            $attachments,
            [],
            $mailer,
            [],
            $replyTo !== '' ? $replyTo : null
        );
        $confirm = ['ok' => false, 'error' => 'Applicant email is missing'];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $confirmBody = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#122018;line-height:1.5">';
            $confirmBody .= '<p>Dear ' . $esc($name) . ',</p>';
            $confirmBody .= '<p>Your application for professional training in Malta has been received. Please keep this reference:</p>';
            $confirmBody .= '<p style="font-size:18px;font-weight:700;letter-spacing:.04em">' . $esc($reference) . '</p>';
            $confirmBody .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px">';
            foreach ([
                'Program' => $program,
                'Country' => (string) ($application['country'] ?? ''),
                'Phone / WhatsApp' => (string) ($application['phone'] ?? ''),
                'Documents received' => 'Passport, CV, and academic documents',
            ] as $label => $value) {
                $confirmBody .= '<tr><td style="border:1px solid #d7e0da;font-weight:700;width:190px;background:#f6faf7">'
                    . $esc($label) . '</td><td style="border:1px solid #d7e0da">' . $esc($value) . '</td></tr>';
            }
            $confirmBody .= '</table>';
            $confirmBody .= '<p>Our team will review your documents and contact you at this email address. Job assistance is offered after graduation. Employment is not guaranteed.</p>';
            $confirmBody .= '<p>Parrot Canada Visa Consultant</p></div>';
            $confirm = sendSMTPMailDetailed(
                $replyTo,
                'We received your Malta application ' . $reference,
                $confirmBody,
                [],
                [],
                $mailer,
                [],
                'info@6meuropeanacademy.com'
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
            @file_put_contents($root . '/logs/malta_courses_email.log', $log, FILE_APPEND);
        }
        return [
            'ok' => !empty($result['ok']) && !empty($confirm['ok']),
            'office' => $result,
            'applicant' => $confirm,
        ];
    } catch (Throwable $e) {
        error_log('malta application email failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

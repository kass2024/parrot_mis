<?php
declare(strict_types=1);

require_once __DIR__ . '/form17_application_status.php';

/**
 * Self-registration (post-payment) private-link applications.
 */

function pcvc_self_reg_upload_root(): string
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'self_registration';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );
    }
    return $root;
}

function pcvc_self_reg_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `self_registrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token` CHAR(64) NOT NULL,
            `status` ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
            `full_name` VARCHAR(255) NOT NULL DEFAULT '',
            `date_of_birth` DATE NULL,
            `nationality` VARCHAR(120) NOT NULL DEFAULT '',
            `country_of_residence` VARCHAR(120) NOT NULL DEFAULT '',
            `destination_country` VARCHAR(120) NOT NULL DEFAULT '',
            `whatsapp` VARCHAR(80) NOT NULL DEFAULT '',
            `email` VARCHAR(190) NOT NULL DEFAULT '',
            `preferred_level` VARCHAR(160) NOT NULL DEFAULT '',
            `field_choice_1` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_2` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_3` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_4` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_5` VARCHAR(200) NOT NULL DEFAULT '',
            `other_field_specify` VARCHAR(255) NOT NULL DEFAULT '',
            `highest_education` VARCHAR(160) NOT NULL DEFAULT '',
            `previous_field` VARCHAR(200) NOT NULL DEFAULT '',
            `graduation_year` VARCHAR(20) NOT NULL DEFAULT '',
            `final_grade` VARCHAR(80) NOT NULL DEFAULT '',
            `preferred_language` VARCHAR(80) NOT NULL DEFAULT '',
            `preferred_intake` VARCHAR(160) NOT NULL DEFAULT '',
            `preferred_institution` VARCHAR(200) NOT NULL DEFAULT '',
            `preferred_province` VARCHAR(120) NOT NULL DEFAULT '',
            `sponsor` VARCHAR(120) NOT NULL DEFAULT '',
            `ready_to_pay` VARCHAR(10) NOT NULL DEFAULT '',
            `visa_refused` VARCHAR(10) NOT NULL DEFAULT '',
            `travel_with_family` VARCHAR(10) NOT NULL DEFAULT '',
            `referring_agent` VARCHAR(200) NOT NULL DEFAULT '',
            `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by_admin_id` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `submitted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_self_reg_token` (`token`),
            KEY `idx_self_reg_status` (`status`),
            KEY `idx_self_reg_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `self_registration_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `registration_id` INT UNSIGNED NOT NULL,
            `doc_key` VARCHAR(64) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL DEFAULT '',
            `stored_name` VARCHAR(255) NOT NULL DEFAULT '',
            `mime` VARCHAR(120) NOT NULL DEFAULT '',
            `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_self_reg_doc` (`registration_id`, `doc_key`),
            KEY `idx_self_reg_doc_reg` (`registration_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** @return list<string> */
function pcvc_self_reg_study_levels(): array
{
    return [
        'Kindergarten',
        'Primary School',
        'Secondary / High School',
        'Short Professional Courses',
        'Professional Training Courses',
        'Technical and Vocational Training',
        'English / French Language Courses',
        'Certificate',
        'Attestation of College Studies (AEC)',
        'Diploma of Vocational Studies (DEP)',
        'Diploma of College Studies (DEC)',
        'College Diploma',
        'Advanced Diploma',
        'Associate Degree',
        "Bachelor's Degree",
        'Postgraduate Certificate',
        'Postgraduate Diploma',
        "Master's Degree",
        'PhD / Doctorate',
    ];
}

/** @return list<string> */
function pcvc_self_reg_fields_of_study(): array
{
    return [
        'Accounting and Finance',
        'Agriculture and Agricultural Sciences',
        'Architecture and Urban Planning',
        'Artificial Intelligence and Machine Learning',
        'Automotive Engineering and Technology',
        'Aviation and Aeronautical Engineering',
        'Banking and Insurance',
        'Biomedical and Biotechnology Sciences',
        'Business Administration and Management',
        'Chemical Engineering',
        'Civil Engineering',
        'Computer Science and Information Technology',
        'Construction and Building Technology',
        'Cybersecurity and Computer Networking',
        'Data Science and Business Analytics',
        'Dentistry and Dental Sciences',
        'Economics',
        'Education and Teaching',
        'Early Childhood Education',
        'Electrical and Electronics Engineering',
        'Energy and Renewable Energy Engineering',
        'Environmental Science and Management',
        'Food Science and Nutrition',
        'Graphic Design and Multimedia',
        'Health Sciences and Healthcare Management',
        'Hospitality and Tourism Management',
        'Human Resource Management',
        'Industrial and Manufacturing Engineering',
        'International Business and Trade',
        'International Relations and Development Studies',
        'Journalism and Mass Communication',
        'Law and Legal Studies',
        'Logistics and Supply Chain Management',
        'Marketing and Digital Marketing',
        'Mathematics and Statistics',
        'Mechanical Engineering',
        'Medicine and Medical Sciences',
        'Mining and Geological Engineering',
        'Nursing and Midwifery',
        'Occupational Health and Safety',
        'Oil and Gas Engineering',
        'Pharmacy and Pharmaceutical Sciences',
        'Philosophy and Religious Studies',
        'Physics and Applied Physics',
        'Political Science and Governance',
        'Psychology and Mental Health',
        'Public Health',
        'Social Work and Community Development',
        'Software Engineering and Development',
        'Telecommunications Engineering',
        'Natural and Applied Sciences',
        'Languages and Literature',
        'Arts and Humanities',
        'Technical and Vocational Training',
        'Culinary Arts and Food Services',
        'Transportation and Maritime Studies',
        'Veterinary Medicine and Animal Sciences',
        'Sports and Recreation Management',
        'Security and Criminal Justice',
        'Other – Please Specify',
    ];
}

/** @return list<string> */
function pcvc_self_reg_education_levels(): array
{
    return [
        'Primary School',
        'Secondary / High School',
        'Certificate / Diploma',
        'College / AEC / DEC / DEP',
        "Bachelor's Degree",
        'Postgraduate Certificate / Diploma',
        "Master's Degree",
        'PhD / Doctorate',
        'Other',
    ];
}

/** @return list<string> */
function pcvc_self_reg_languages(): array
{
    return ['English', 'French', 'Either English or French'];
}

/** @return list<string> */
function pcvc_self_reg_sponsors(): array
{
    return [
        'Self-sponsored',
        'Father / Mother',
        'Husband / Wife',
        'Brother / Sister',
        'Other family member',
        'Company / Employer',
    ];
}

/**
 * Document slots. payment_proof is mandatory before submit.
 *
 * @return array<string, array{label:string,required:bool,accept:string}>
 */
function pcvc_self_reg_document_slots(): array
{
    return [
        'payment_proof' => [
            'label' => 'Payment proof (mandatory)',
            'required' => true,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'passport' => [
            'label' => 'Passport',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'diplomas' => [
            'label' => 'Academic diplomas',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'transcripts' => [
            'label' => 'Academic transcripts / School reports',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'cv' => [
            'label' => 'Curriculum Vitae (CV)',
            'required' => false,
            'accept' => '.pdf,.doc,.docx,.jpg,.jpeg,.png',
        ],
        'birth_certificate' => [
            'label' => 'Birth certificate',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'language_certificate' => [
            'label' => 'Language proficiency certificate (if available)',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
    ];
}

function pcvc_self_reg_issue_link(mysqli $conn, ?int $adminId = null): array
{
    pcvc_self_reg_ensure_schema($conn);
    $token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare(
        'INSERT INTO self_registrations (token, created_by_admin_id) VALUES (?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare self-registration insert: ' . $conn->error);
    }
    $stmt->bind_param('si', $token, $adminId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create self-registration: ' . $err);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return ['id' => $id, 'token' => $token];
}

function pcvc_self_reg_find_by_token(mysqli $conn, string $token): ?array
{
    pcvc_self_reg_ensure_schema($conn);
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM self_registrations WHERE token = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** @return array<string, array> keyed by doc_key */
function pcvc_self_reg_documents_map(mysqli $conn, int $registrationId): array
{
    $out = [];
    $stmt = $conn->prepare(
        'SELECT * FROM self_registration_documents WHERE registration_id = ?'
    );
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $registrationId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(string) $row['doc_key']] = $row;
    }
    $stmt->close();
    return $out;
}

function pcvc_self_reg_public_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return "{$scheme}://{$host}{$basePath}/self-registration.php?token=" . rawurlencode($token);
}

/**
 * @return list<array>
 */
function pcvc_self_reg_recent(mysqli $conn, int $limit = 40): array
{
    pcvc_self_reg_ensure_schema($conn);
    $limit = max(1, min(200, $limit));
    $rows = [];
    $sql = "SELECT id, token, status, full_name, email, whatsapp, destination_country,
                   preferred_intake, preferred_level, created_at, submitted_at, updated_at
            FROM self_registrations
            ORDER BY id DESC
            LIMIT {$limit}";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** @return array<string, string> */
function pcvc_self_reg_writable_fields(): array
{
    return [
        'full_name' => 'string',
        'date_of_birth' => 'date',
        'nationality' => 'string',
        'country_of_residence' => 'string',
        'destination_country' => 'string',
        'whatsapp' => 'string',
        'email' => 'string',
        'preferred_level' => 'string',
        'field_choice_1' => 'string',
        'field_choice_2' => 'string',
        'field_choice_3' => 'string',
        'field_choice_4' => 'string',
        'field_choice_5' => 'string',
        'other_field_specify' => 'string',
        'highest_education' => 'string',
        'previous_field' => 'string',
        'graduation_year' => 'string',
        'final_grade' => 'string',
        'preferred_language' => 'string',
        'preferred_intake' => 'string',
        'preferred_institution' => 'string',
        'preferred_province' => 'string',
        'sponsor' => 'string',
        'ready_to_pay' => 'yesno',
        'visa_refused' => 'yesno',
        'travel_with_family' => 'yesno',
        'referring_agent' => 'string',
        'declaration_accepted' => 'bool',
    ];
}

function pcvc_self_reg_sanitize_value(string $type, $raw): string
{
    $val = is_string($raw) ? trim($raw) : (is_scalar($raw) ? trim((string) $raw) : '');
    if ($type === 'date') {
        if ($val === '') {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        return ($dt && $dt->format('Y-m-d') === $val) ? $val : '';
    }
    if ($type === 'yesno') {
        return in_array($val, ['Yes', 'No'], true) ? $val : '';
    }
    if ($type === 'bool') {
        return ($val === '1' || $val === 'true' || $val === 'on' || $val === 'yes') ? '1' : '0';
    }
    return mb_substr($val, 0, 255);
}

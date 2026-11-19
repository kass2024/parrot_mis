<?php
declare(strict_types=1);

/**
 * Canada unskilled / general workers recruitment applications (idempotent schema).
 */
function cuw_ensure_schema(mysqli $conn): bool
{
    static $ran = false;
    static $ok = false;
    if ($ran) {
        return $ok;
    }
    $ran = true;

    $uploadDir = dirname(__DIR__) . '/uploads/canada_workers';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $htaccess = $uploadDir . '/.htaccess';
    if (is_dir($uploadDir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "# Block direct HTTP access.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }

    $sql = "CREATE TABLE IF NOT EXISTS `canada_workers_applications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` varchar(80) NOT NULL,
      `reference_id` varchar(24) NOT NULL,
      `full_name` varchar(200) NOT NULL,
      `first_name` varchar(100) NOT NULL DEFAULT '',
      `last_name` varchar(100) NOT NULL DEFAULT '',
      `date_of_birth` date NOT NULL,
      `gender` varchar(20) NOT NULL,
      `nationality` varchar(120) NOT NULL,
      `country_of_residence` varchar(120) NOT NULL,
      `email` varchar(150) NOT NULL,
      `phone_area_code` varchar(10) DEFAULT NULL,
      `phone_number` varchar(40) NOT NULL,
      `current_address` text NOT NULL,
      `how_did_you_know_us` varchar(200) NOT NULL DEFAULT '',
      `position` varchar(80) NOT NULL,
      `current_occupation` varchar(200) NOT NULL,
      `years_experience` decimal(4,1) NOT NULL DEFAULT 0,
      `education_level` varchar(80) NOT NULL,
      `work_experience_description` text NOT NULL,
      `passport_file` varchar(255) NOT NULL,
      `cv_file` varchar(255) NOT NULL,
      `photo_file` varchar(255) NOT NULL,
      `declaration_accepted` tinyint(1) NOT NULL DEFAULT 0,
      `status` enum('pending','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
      `source` varchar(20) NOT NULL DEFAULT 'public',
      `admin_notes` text DEFAULT NULL,
      `jobs_email_sent_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `reference_id` (`reference_id`),
      UNIQUE KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      KEY `email` (`email`),
      KEY `position` (`position`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        if (!$conn->query($sql)) {
            error_log('cuw_ensure_schema failed: ' . $conn->error);
            $ok = false;
            return false;
        }
    } catch (Throwable $e) {
        error_log('cuw_ensure_schema exception: ' . $e->getMessage());
        $ok = false;
        return false;
    }

    $col = $conn->query("SHOW COLUMNS FROM `canada_workers_applications` LIKE 'how_did_you_know_us'");
    if ($col && $col->num_rows === 0) {
        if (!$conn->query("ALTER TABLE `canada_workers_applications` ADD COLUMN `how_did_you_know_us` varchar(200) NOT NULL DEFAULT '' AFTER `current_address`")) {
            error_log('cuw_ensure_schema how_did_you_know_us failed: ' . $conn->error);
        }
    }
    if ($col) {
        $col->free();
    }

    $ok = true;
    return true;
}

/** @return array<string,string> */
function cuw_position_options(): array
{
    return [
        'storekeeper' => 'Storekeeper',
        'fruit_packer' => 'Fruit Packer',
        'fruit_picker' => 'Fruit Picker',
        'fruit_farmer' => 'Fruit Farmer',
        'general_labourer' => 'General Labourer',
    ];
}

/** Hourly pay shown on the application form and in notification emails. */
function cuw_position_salaries(): array
{
    return [
        'storekeeper' => 'CAD $21 per hour',
        'fruit_packer' => 'CAD $19 per hour',
        'fruit_picker' => 'CAD $19 per hour',
        'fruit_farmer' => 'CAD $21 per hour',
        'general_labourer' => 'CAD $19 per hour',
    ];
}

function cuw_position_label(string $key): string
{
    $map = cuw_position_options();
    return $map[$key] ?? ($key !== '' ? $key : '—');
}

function cuw_position_salary(string $key): string
{
    $map = cuw_position_salaries();
    return $map[$key] ?? '';
}

function cuw_position_choice_label(string $key): string
{
    $name = cuw_position_label($key);
    $salary = cuw_position_salary($key);
    return $salary !== '' ? $name . ' — ' . $salary : $name;
}

/** @return array<string,string> */
function cuw_gender_options(): array
{
    return [
        'female' => 'Female',
        'male' => 'Male',
        'other' => 'Other',
    ];
}

function cuw_gender_label(string $key): string
{
    $map = cuw_gender_options();
    return $map[$key] ?? ($key !== '' ? $key : '—');
}

/** @return array<string,string> */
function cuw_education_options(): array
{
    return [
        'primary' => 'Primary school',
        'secondary' => 'Secondary / High school',
        'vocational' => 'Vocational certificate',
        'diploma' => 'Diploma',
        'bachelor' => "Bachelor's degree",
        'master_or_higher' => "Master's degree or higher",
        'other' => 'Other',
    ];
}

function cuw_education_label(string $key): string
{
    $map = cuw_education_options();
    return $map[$key] ?? ($key !== '' ? $key : '—');
}

function cuw_agency_inbox(): string
{
    return 'jobs@ptemploymentagency.ca';
}

function cuw_jobs_copy_inbox(): string
{
    return 'jobs@visaconsultantcanada.ca';
}

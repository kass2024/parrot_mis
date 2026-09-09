<?php
declare(strict_types=1);

/**
 * Noble Education Group — Canada admissions applications (idempotent schema).
 */
function neg_ensure_schema(mysqli $conn): bool
{
    static $ran = false;
    static $ok = false;
    if ($ran) {
        return $ok;
    }
    $ran = true;

    $uploadDir = dirname(__DIR__) . '/uploads/noble_education';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $sql = "CREATE TABLE IF NOT EXISTS `noble_education_applications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` varchar(80) NOT NULL,
      `reference_id` varchar(24) NOT NULL,
      `full_name` varchar(200) NOT NULL,
      `first_name` varchar(100) NOT NULL DEFAULT '',
      `last_name` varchar(100) NOT NULL DEFAULT '',
      `email` varchar(150) NOT NULL,
      `phone_area_code` varchar(10) DEFAULT NULL,
      `phone_number` varchar(40) NOT NULL,
      `level_of_study` varchar(120) NOT NULL DEFAULT '',
      `program_of_interest` varchar(255) NOT NULL,
      `agent_name` varchar(200) NOT NULL DEFAULT '',
      `passport_file` varchar(255) NOT NULL,
      `high_school_certificate_file` varchar(255) NOT NULL,
      `transcripts_file` varchar(255) NOT NULL,
      `other_docs_json` text DEFAULT NULL,
      `status` enum('pending','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
      `source` varchar(20) NOT NULL DEFAULT 'public',
      `created_by_admin_id` int(11) DEFAULT NULL,
      `admin_notes` text DEFAULT NULL,
      `approval_email_sent_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `reference_id` (`reference_id`),
      UNIQUE KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      KEY `email` (`email`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        if (!$conn->query($sql)) {
            error_log('neg_ensure_schema failed: ' . $conn->error);
            $ok = false;
            return false;
        }
        neg_ensure_column($conn, 'noble_education_applications', 'approval_email_sent_at', "ALTER TABLE `noble_education_applications` ADD COLUMN `approval_email_sent_at` datetime DEFAULT NULL AFTER `admin_notes`");
        neg_ensure_column($conn, 'noble_education_applications', 'other_docs_json', "ALTER TABLE `noble_education_applications` ADD COLUMN `other_docs_json` text DEFAULT NULL AFTER `transcripts_file`");
        neg_ensure_column($conn, 'noble_education_applications', 'agent_name', "ALTER TABLE `noble_education_applications` ADD COLUMN `agent_name` varchar(200) NOT NULL DEFAULT '' AFTER `program_of_interest`");
        neg_ensure_column($conn, 'noble_education_applications', 'level_of_study', "ALTER TABLE `noble_education_applications` ADD COLUMN `level_of_study` varchar(120) NOT NULL DEFAULT '' AFTER `phone_number`");
    } catch (Throwable $e) {
        error_log('neg_ensure_schema exception: ' . $e->getMessage());
        $ok = false;
        return false;
    }

    $ok = true;
    return true;
}

function neg_ensure_column(mysqli $conn, string $table, string $column, string $alterSql): void
{
    $tableSafe = $conn->real_escape_string($table);
    $colSafe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$colSafe}'");
    if ($res && $res->num_rows === 0) {
        if (!$conn->query($alterSql)) {
            error_log("neg_ensure_column {$table}.{$column} failed: " . $conn->error);
        }
    }
    if ($res) {
        $res->free();
    }
}

/** @return array<string,string> */
function neg_level_options(): array
{
    return [
        'high_school' => 'High School',
        'diploma' => 'Diploma / Certificate',
        'undergraduate' => 'Undergraduate / Bachelor',
        'postgraduate' => 'Postgraduate / Master',
        'phd' => 'PhD / Doctorate',
        'other' => 'Other',
    ];
}

function neg_level_label(string $key): string
{
    $map = neg_level_options();
    return $map[$key] ?? ($key !== '' ? $key : '—');
}

function neg_level_program_label(array $row): string
{
    $level = neg_level_label((string) ($row['level_of_study'] ?? ''));
    $program = trim((string) ($row['program_of_interest'] ?? ''));
    if ($program === '') {
        return $level;
    }
    if ($level === '—' || $level === '') {
        return $program;
    }
    return $level . ' — ' . $program;
}

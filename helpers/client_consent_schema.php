<?php
declare(strict_types=1);

function client_consent_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/client_consents';
}

function client_consent_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $dir = client_consent_upload_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `client_consent_agreements` (
        `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
        `contract_token` varchar(64) NOT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'draft',
        `client_name` varchar(255) DEFAULT NULL,
        `passport_number` varchar(120) DEFAULT NULL,
        `nationality` varchar(120) DEFAULT NULL,
        `date_of_birth` varchar(40) DEFAULT NULL,
        `residential_address` text,
        `phone` varchar(80) DEFAULT NULL,
        `email` varchar(255) DEFAULT NULL,
        `service_type` varchar(255) DEFAULT NULL,
        `agreement_date` varchar(40) DEFAULT NULL,
        `client_initials` varchar(40) DEFAULT NULL,
        `governing_law` varchar(255) DEFAULT NULL,
        `dispute_jurisdiction` varchar(255) DEFAULT NULL,
        `office_location` varchar(80) DEFAULT NULL,
        `office_representative_name` varchar(255) DEFAULT NULL,
        `office_representative_position` varchar(255) DEFAULT NULL,
        `witness_name` varchar(255) DEFAULT NULL,
        `witness_passport` varchar(120) DEFAULT NULL,
        `signature_image` longtext,
        `signed_at` datetime DEFAULT NULL,
        `approved_at` datetime DEFAULT NULL,
        `approved_by` int(11) DEFAULT NULL,
        `pdf_path` varchar(500) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `contract_token` (`contract_token`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $approvedAt = $conn->query("SHOW COLUMNS FROM `client_consent_agreements` LIKE 'approved_at'");
    if ($approvedAt && $approvedAt->num_rows === 0) {
        $conn->query("ALTER TABLE `client_consent_agreements` ADD COLUMN `approved_at` datetime DEFAULT NULL AFTER `signed_at`");
    }
    $approvedBy = $conn->query("SHOW COLUMNS FROM `client_consent_agreements` LIKE 'approved_by'");
    if ($approvedBy && $approvedBy->num_rows === 0) {
        $conn->query("ALTER TABLE `client_consent_agreements` ADD COLUMN `approved_by` int(11) DEFAULT NULL AFTER `approved_at`");
    }
}

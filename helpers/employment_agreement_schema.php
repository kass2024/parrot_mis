<?php
declare(strict_types=1);

function employment_agreement_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/employment_agreements';
}

function employment_agreement_countries(): array
{
    return [
        'Finland', 'Malta', 'Cyprus', 'Croatia', 'Greece', 'Estonia', 'Luxembourg', 'Italy',
        'Slovenia', 'Latvia', 'Portugal', 'Slovakia', 'Belgium', 'Bulgaria', 'Serbia',
        'Germany', 'Denmark', 'Canada',
    ];
}

function employment_agreement_acknowledgments(): array
{
    return [
        'I have read and understood this Agreement.',
        'I have selected my preferred destination country.',
        'I understand the services that PARROT may provide.',
        'I understand that employment is not guaranteed.',
        'I understand that work permit and visa approval are not guaranteed.',
        'I understand that lawful payments made before application commencement may be non-refundable, subject to applicable law.',
        'I understand that lawful payments made after work permit approval may be non-refundable, subject to applicable law.',
        'I understand that mandatory refund rights cannot be waived.',
        'I understand that any lawful service charges must be separately disclosed and agreed upon before payment.',
        'I confirm that the information and documents I provide are genuine and accurate.',
        'I voluntarily agree to the terms and conditions of this Agreement.',
    ];
}

function employment_agreement_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $dir = employment_agreement_upload_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `employment_agreements` (
        `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
        `contract_token` varchar(64) NOT NULL,
        `reference_number` varchar(40) DEFAULT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'draft',
        `client_name` varchar(255) DEFAULT NULL,
        `nationality` varchar(120) DEFAULT NULL,
        `passport_number` varchar(120) DEFAULT NULL,
        `date_of_birth` varchar(40) DEFAULT NULL,
        `residential_address` text,
        `phone` varchar(80) DEFAULT NULL,
        `email` varchar(255) DEFAULT NULL,
        `destination_country` varchar(80) DEFAULT NULL,
        `preferred_job` varchar(255) DEFAULT NULL,
        `preferred_city` varchar(255) DEFAULT NULL,
        `expected_salary` varchar(120) DEFAULT NULL,
        `employer_partner` varchar(255) DEFAULT NULL,
        `place_of_signing` varchar(255) DEFAULT NULL,
        `contracting_jurisdiction` varchar(255) DEFAULT NULL,
        `agreement_date` varchar(40) DEFAULT NULL,
        `signature_image` longtext,
        `signed_at` datetime DEFAULT NULL,
        `pdf_path` varchar(500) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `contract_token` (`contract_token`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

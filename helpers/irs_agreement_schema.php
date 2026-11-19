<?php
declare(strict_types=1);

/**
 * Immigration Application Records Recovery and Review Support Agreement.
 */
function irs_agreement_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $uploadDir = dirname(__DIR__) . '/uploads/irs_agreements';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `irs_agreements` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `contract_token` varchar(64) NOT NULL,
          `agreement_reference` varchar(64) DEFAULT NULL,
          `status` enum('draft','signed','cancelled') NOT NULL DEFAULT 'draft',
          `effective_date` date DEFAULT NULL,
          `client_name` varchar(255) DEFAULT NULL,
          `client_email` varchar(190) DEFAULT NULL,
          `client_phone` varchar(64) DEFAULT NULL,
          `client_dob` date DEFAULT NULL,
          `client_nationality` varchar(190) DEFAULT NULL,
          `client_passport` varchar(64) DEFAULT NULL,
          `client_address` text DEFAULT NULL,
          `application_type` varchar(255) DEFAULT NULL,
          `uci_application_number` varchar(255) DEFAULT NULL,
          `submission_refusal_dates` varchar(255) DEFAULT NULL,
          `travel_history_period` varchar(255) DEFAULT NULL,
          `signed_at` timestamp NULL DEFAULT NULL,
          `sent_at` timestamp NULL DEFAULT NULL,
          `pdf_path` varchar(500) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `contract_token` (`contract_token`),
          KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `irs_agreement_signatures` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `agreement_id` int(11) unsigned NOT NULL,
          `client_name` varchar(255) NOT NULL,
          `client_email` varchar(190) DEFAULT NULL,
          `signed_date` varchar(32) NOT NULL,
          `signature_image` mediumtext DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `agreement_id` (`agreement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * @return array{name:string,address:string,registration:string,signatory:string,title:string,email:string,whatsapp:string,price:string}
 */
function irs_agreement_company(): array
{
    return [
        'name' => 'Parrot Canada Visa Consultant Co. Ltd',
        'address' => 'GISOZI, KIGALI RWANDA',
        'registration' => '122190556',
        'signatory' => 'Dr. Jean Pierre Twajamahoro',
        'title' => 'Owner and Managing Director',
        'email' => 'infos@visaconsultantcanada.com',
        'whatsapp' => '+1 (431) 302-0226',
        'price' => 'CAD 650',
    ];
}

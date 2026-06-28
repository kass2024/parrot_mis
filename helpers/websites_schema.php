<?php
declare(strict_types=1);

/**
 * Website management — schema helper (idempotent).
 *
 * Auto-creates the websites table on first request.
 * Safe to call on every page load — uses CREATE TABLE IF NOT EXISTS.
 */
function pcvc_ensure_websites_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $sql = "
        CREATE TABLE IF NOT EXISTS `websites` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `serial_no`       VARCHAR(32) NOT NULL,
            `website_name`    VARCHAR(255) NOT NULL,
            `website_link`    VARCHAR(500) NULL,
            `admin_username`  VARCHAR(255) NOT NULL,
            `admin_password`  VARCHAR(255) NOT NULL,
            `status`          ENUM('Active','Not Active') NOT NULL DEFAULT 'Active',
            `notes`           TEXT NULL,
            `created_by`      INT UNSIGNED NULL,
            `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_website_serial` (`serial_no`),
            KEY `idx_website_status` (`status`),
            KEY `idx_website_name` (`website_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        error_log('websites table create failed: ' . $conn->error);
    }
}

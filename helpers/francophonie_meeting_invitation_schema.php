<?php
declare(strict_types=1);

require_once __DIR__ . '/francophonie_mobility_schema.php';

function fm_meeting_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    fm_ensure_schema($conn);

    $sql = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_meeting_invitations` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `topic` varchar(255) NOT NULL,
      `agenda` text DEFAULT NULL,
      `start_time` datetime NOT NULL,
      `duration_minutes` smallint unsigned NOT NULL DEFAULT 60,
      `timezone` varchar(64) NOT NULL DEFAULT 'America/Toronto',
      `zoom_meeting_id` varchar(64) DEFAULT NULL,
      `zoom_meeting_number` varchar(32) DEFAULT NULL,
      `zoom_join_url` text NOT NULL,
      `zoom_password` varchar(32) DEFAULT NULL,
      `zoom_start_url` text DEFAULT NULL,
      `guest_join_token` varchar(64) DEFAULT NULL,
      `recipient_count` int unsigned NOT NULL DEFAULT 0,
      `emails_sent` int unsigned NOT NULL DEFAULT 0,
      `emails_failed` int unsigned NOT NULL DEFAULT 0,
      `created_by_admin_id` int(11) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `start_time` (`start_time`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query($sql);

    $sql2 = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_meeting_invitees` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `invitation_id` int(11) NOT NULL,
      `source_type` enum('francophonie_mobility','student_application') NOT NULL,
      `source_id` int(11) NOT NULL,
      `recipient_name` varchar(200) NOT NULL,
      `recipient_email` varchar(190) NOT NULL,
      `join_token` varchar(64) DEFAULT NULL,
      `joined_at` datetime DEFAULT NULL,
      `join_count` int unsigned NOT NULL DEFAULT 0,
      `email_sent` tinyint(1) NOT NULL DEFAULT 0,
      `email_error` varchar(255) DEFAULT NULL,
      `sent_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `invitation_id` (`invitation_id`),
      KEY `source_lookup` (`source_type`,`source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query($sql2);

    $col = $conn->query("SHOW COLUMNS FROM `francophonie_mobility_meeting_invitees` LIKE 'join_token'");
    if ($col && $col->num_rows === 0) {
        $conn->query(
            "ALTER TABLE `francophonie_mobility_meeting_invitees`
             ADD COLUMN `join_token` varchar(64) DEFAULT NULL AFTER `recipient_email`,
             ADD UNIQUE KEY `join_token` (`join_token`)"
        );
    }
    if ($col) {
        $col->free();
    }

    $colGuest = $conn->query("SHOW COLUMNS FROM `francophonie_mobility_meeting_invitations` LIKE 'guest_join_token'");
    if ($colGuest && $colGuest->num_rows === 0) {
        $conn->query(
            "ALTER TABLE `francophonie_mobility_meeting_invitations`
             ADD COLUMN `guest_join_token` varchar(64) DEFAULT NULL AFTER `zoom_start_url`"
        );
    }
    if ($colGuest) {
        $colGuest->free();
    }

    foreach (['joined_at' => 'datetime DEFAULT NULL', 'join_count' => 'int unsigned NOT NULL DEFAULT 0'] as $colName => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `francophonie_mobility_meeting_invitees` LIKE '{$colName}'");
        if ($chk && $chk->num_rows === 0) {
            $after = $colName === 'joined_at' ? 'join_token' : 'joined_at';
            $conn->query("ALTER TABLE `francophonie_mobility_meeting_invitees` ADD COLUMN `{$colName}` {$def} AFTER `{$after}`");
        }
        if ($chk) {
            $chk->free();
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `francophonie_mobility_meeting_attendance` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `invitation_id` int(11) NOT NULL,
      `invitee_id` int(11) DEFAULT NULL,
      `participant_type` enum('invitee','guest','host') NOT NULL DEFAULT 'guest',
      `participant_name` varchar(200) NOT NULL,
      `participant_email` varchar(190) DEFAULT NULL,
      `source_type` varchar(50) DEFAULT NULL,
      `source_id` int(11) DEFAULT NULL,
      `joined_at` datetime NOT NULL,
      `left_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `invitation_id` (`invitation_id`),
      KEY `invitee_id` (`invitee_id`),
      KEY `joined_at` (`joined_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query(
        "UPDATE francophonie_mobility_meeting_invitees
         SET join_token = MD5(CONCAT('fm-inv-', id, '-', UNIX_TIMESTAMP(), '-', RAND()))
         WHERE join_token IS NULL OR join_token = ''"
    );

    $conn->query(
        "UPDATE francophonie_mobility_meeting_invitations
         SET guest_join_token = MD5(CONCAT('fm-guest-', id, '-', UNIX_TIMESTAMP(), '-', RAND()))
         WHERE guest_join_token IS NULL OR guest_join_token = ''"
    );
}

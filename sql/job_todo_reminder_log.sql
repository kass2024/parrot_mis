-- Written automatically by cron_notify_incomplete_jobs.php.
-- One row per staff member (and per supervisor digest) per office day.

CREATE TABLE IF NOT EXISTS job_todo_reminder_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL DEFAULT 'staff',
    admin_id INT NOT NULL DEFAULT 0,
    recipient_key VARCHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    sent_on DATE NOT NULL,
    job_count INT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'sent',
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_kind_recipient_day (kind, recipient_key, sent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

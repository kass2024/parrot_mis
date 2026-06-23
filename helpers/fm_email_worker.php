<?php
declare(strict_types=1);

/**
 * Francophonie Mobility — background email dispatch (SMTP via .env).
 */
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/francophonie_mobility_notify.php';

function fm_email_worker_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    xander_load_env_file();
    $secret = xander_env_get('FM_EMAIL_TOKEN')
        ?: xander_env_get('SMTP_PASSWORD')
        ?: 'fm_parrot_mobility_worker';
    return $secret;
}

function fm_make_email_token(int $applicationId, string $referenceId): string
{
    return hash_hmac('sha256', $applicationId . '|' . $referenceId, fm_email_worker_secret());
}

function fm_verify_email_token(int $applicationId, string $referenceId, string $token): bool
{
    if ($token === '') {
        return false;
    }
    return hash_equals(fm_make_email_token($applicationId, $referenceId), $token);
}

function fm_dispatch_background_emails(int $applicationId, string $referenceId): string
{
    $token = fm_make_email_token($applicationId, $referenceId);
    $root = dirname(__DIR__);
    $script = $root . DIRECTORY_SEPARATOR . 'fm_background_email.php';
    $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';

    if (!is_file($script)) {
        return $token;
    }

    $id = (int) $applicationId;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $id;
        @pclose(@popen($cmd, 'r'));
    } else {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $id . ' > /dev/null 2>&1 &';
        @exec($cmd);
    }

    return $token;
}

function fm_send_new_application_emails(mysqli $conn, int $applicationId): bool
{
    $st = $conn->prepare(
        'SELECT id, reference_id, first_name, last_name, email, profession, submission_email_sent_at
         FROM francophonie_mobility_applications WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $applicationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return false;
    }

    if (!empty($row['submission_email_sent_at'])) {
        return true;
    }

    xander_load_env_file();

    $mailRow = [
        'reference_id' => $row['reference_id'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'email' => $row['email'],
        'profession' => $row['profession'],
    ];

    $applicantOk = false;
    try {
        $applicantOk = fm_notify_applicant_received($mailRow);
        fm_notify_admins_new_application($mailRow);
    } catch (Throwable $e) {
        error_log('FM background email: ' . $e->getMessage());
    }

    $mark = $conn->prepare(
        'UPDATE francophonie_mobility_applications SET submission_email_sent_at = NOW() WHERE id = ? AND submission_email_sent_at IS NULL'
    );
    if ($mark) {
        $mark->bind_param('i', $applicationId);
        $mark->execute();
        $mark->close();
    }

    return $applicantOk;
}

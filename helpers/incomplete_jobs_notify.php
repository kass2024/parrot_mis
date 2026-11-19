<?php
declare(strict_types=1);

/**
 * Daily email for staff who still have Job To-Do List items marked not completed.
 */

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/role.php';
require_once __DIR__ . '/roster_staff.php';
require_once __DIR__ . '/../includes/company_branding.php';

const PCVC_INCOMPLETE_JOBS_TZ = 'Africa/Kigali';
const PCVC_INCOMPLETE_JOBS_EMAIL_LIMIT = 40;

function pcvc_incomplete_jobs_timezone(): DateTimeZone
{
    return new DateTimeZone(PCVC_INCOMPLETE_JOBS_TZ);
}

function pcvc_incomplete_jobs_today(): string
{
    return (new DateTime('now', pcvc_incomplete_jobs_timezone()))->format('Y-m-d');
}

function pcvc_incomplete_jobs_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pcvc_incomplete_jobs_log(string $message): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . (new DateTime('now', pcvc_incomplete_jobs_timezone()))->format('Y-m-d H:i:s') . '] ' . $message;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'incomplete_jobs_cron.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $line . PHP_EOL;
}

function pcvc_incomplete_jobs_todo_url(): string
{
    $base = function_exists('pcvc_public_base_url') ? pcvc_public_base_url() : '';
    if ($base === '' || str_contains($base, 'localhost')) {
        $base = PCVC_COMPANY_WEBSITE;
    }

    return rtrim($base, '/') . '/job_todo_list.php';
}

function pcvc_incomplete_jobs_format_when(string $createdAt): string
{
    $createdAt = trim($createdAt);
    if ($createdAt === '') {
        return '';
    }
    try {
        $dt = new DateTime($createdAt, new DateTimeZone('UTC'));
        $dt->setTimezone(pcvc_incomplete_jobs_timezone());

        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $createdAt;
    }
}

function pcvc_incomplete_jobs_digest_enabled(): bool
{
    xander_load_env_file();
    $raw = strtolower(trim(xander_env_get('JOB_TODO_DIGEST')));
    if ($raw === '') {
        $raw = strtolower(trim(xander_env_get_from_dotenv_file('JOB_TODO_DIGEST')));
    }

    return in_array($raw, ['1', 'on', 'yes', 'true'], true);
}

/**
 * @return list<string>
 */
function pcvc_incomplete_jobs_extra_digest_emails(): array
{
    xander_load_env_file();
    $raw = trim(xander_env_get('JOB_TODO_DIGEST_EMAIL'));
    if ($raw === '') {
        $raw = trim(xander_env_get_from_dotenv_file('JOB_TODO_DIGEST_EMAIL'));
    }
    if ($raw === '') {
        return [];
    }

    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $email) {
        $email = trim((string) $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($email)] = $email;
        }
    }

    return array_values($out);
}

function pcvc_incomplete_jobs_ensure_log_table(mysqli $conn): bool
{
    $sql = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    if (!$conn->query($sql)) {
        pcvc_incomplete_jobs_log('Could not create job_todo_reminder_log: ' . $conn->error);

        return false;
    }

    return true;
}

function pcvc_incomplete_jobs_already_sent(mysqli $conn, string $kind, string $recipientKey, string $sentOn): bool
{
    $stmt = $conn->prepare(
        'SELECT status FROM job_todo_reminder_log WHERE kind = ? AND recipient_key = ? AND sent_on = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $kind, $recipientKey, $sentOn);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null && ($row['status'] ?? '') === 'sent';
}

function pcvc_incomplete_jobs_record(
    mysqli $conn,
    string $kind,
    int $adminId,
    string $recipientKey,
    string $email,
    string $sentOn,
    int $jobCount,
    string $status,
    string $error
): void {
    $error = substr($error, 0, 500);
    $stmt = $conn->prepare(
        'INSERT INTO job_todo_reminder_log
            (kind, admin_id, recipient_key, email, sent_on, job_count, status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            admin_id = VALUES(admin_id),
            email = VALUES(email),
            job_count = VALUES(job_count),
            status = VALUES(status),
            error_message = VALUES(error_message),
            updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        pcvc_incomplete_jobs_log('Could not record reminder log: ' . $conn->error);

        return;
    }
    $stmt->bind_param('sisssiss', $kind, $adminId, $recipientKey, $email, $sentOn, $jobCount, $status, $error);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return list<array{admin_id:int,name:string,email:string,role:string,jobs:list<array<string,string>>}>
 */
function pcvc_incomplete_jobs_fetch(mysqli $conn): array
{
    $sql = <<<SQL
SELECT
    a.id AS admin_id,
    COALESCE(
        NULLIF(TRIM(a.full_name), ''),
        TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, '')))
    ) AS admin_name,
    a.email AS admin_email,
    a.role AS admin_role,
    j.id AS job_id,
    j.title,
    j.applicant_name,
    j.applicant_email,
    j.job_type,
    j.created_at
FROM job_list j
INNER JOIN admins a ON a.id = j.admin_id
WHERE j.status = 'not_completed'
ORDER BY admin_name ASC, a.id ASC, j.created_at ASC, j.id ASC
SQL;

    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException('Could not read unfinished jobs: ' . $conn->error);
    }

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $adminId = (int) $row['admin_id'];
        if (!isset($grouped[$adminId])) {
            $name = trim((string) ($row['admin_name'] ?? ''));
            $grouped[$adminId] = [
                'admin_id' => $adminId,
                'name' => $name !== '' ? $name : 'Staff',
                'email' => trim((string) ($row['admin_email'] ?? '')),
                'role' => trim((string) ($row['admin_role'] ?? '')),
                'jobs' => [],
            ];
        }
        $grouped[$adminId]['jobs'][] = [
            'id' => (string) ($row['job_id'] ?? ''),
            'title' => trim((string) ($row['title'] ?? '')),
            'applicant_name' => trim((string) ($row['applicant_name'] ?? '')),
            'applicant_email' => trim((string) ($row['applicant_email'] ?? '')),
            'job_type' => trim((string) ($row['job_type'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'created_label' => pcvc_incomplete_jobs_format_when((string) ($row['created_at'] ?? '')),
        ];
    }
    $res->free();

    return array_values($grouped);
}

/**
 * @return list<array{admin_id:int,name:string,email:string}>
 */
function pcvc_incomplete_jobs_superadmins(mysqli $conn): array
{
    $res = $conn->query(
        "SELECT id,
                COALESCE(NULLIF(TRIM(full_name), ''), TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) AS full_name,
                email,
                role
         FROM admins"
    );
    if (!$res) {
        return [];
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        if (!pcvc_is_superadmin_role($row['role'] ?? '')) {
            continue;
        }
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $name = trim((string) ($row['full_name'] ?? ''));
        $out[] = [
            'admin_id' => (int) $row['id'],
            'name' => $name !== '' ? $name : 'Superadmin',
            'email' => $email,
        ];
    }
    $res->free();

    return $out;
}

/**
 * @param list<array<string, string>> $jobs
 */
function pcvc_incomplete_jobs_staff_html(string $name, array $jobs, string $todoUrl): string
{
    $safeName = pcvc_incomplete_jobs_h($name);
    $count = count($jobs);
    $shown = array_slice($jobs, 0, PCVC_INCOMPLETE_JOBS_EMAIL_LIMIT);
    $extra = $count - count($shown);
    $noun = $count === 1 ? 'job' : 'jobs';

    $rows = '';
    foreach ($shown as $job) {
        $title = pcvc_incomplete_jobs_h($job['title'] !== '' ? $job['title'] : 'Untitled job');
        $lead = pcvc_incomplete_jobs_h($job['applicant_name'] !== '' ? $job['applicant_name'] : '—');
        $leadEmail = pcvc_incomplete_jobs_h($job['applicant_email']);
        $type = pcvc_incomplete_jobs_h($job['job_type'] !== '' ? $job['job_type'] : '—');
        $when = pcvc_incomplete_jobs_h($job['created_label'] !== '' ? $job['created_label'] : '—');
        $leadLine = $lead;
        if ($leadEmail !== '') {
            $leadLine .= '<br><span style="color:#64748b;">' . $leadEmail . '</span>';
        }
        $rows .= '<tr>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;font-weight:600;">' . $title . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;">' . $leadLine . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;">' . $type . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;white-space:nowrap;">' . $when . '</td>'
            . '</tr>';
    }

    $more = $extra > 0
        ? '<p style="margin:12px 0 0;font-size:13px;color:#64748b;">And ' . $extra . ' more on your to-do list.</p>'
        : '';

    $safeUrl = pcvc_incomplete_jobs_h($todoUrl);
    $company = pcvc_incomplete_jobs_h(PCVC_COMPANY_DISPLAY_NAME);

    return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:680px;margin:0 auto;color:#1e293b;">'
        . '<p style="margin:0 0 12px;font-size:16px;">Dear <strong>' . $safeName . '</strong>,</p>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">You still have <strong>' . $count . ' unfinished ' . $noun . '</strong> on your Job To-Do List. Please open the list and mark each one completed.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<thead><tr style="background:#0f3d4c;color:#ffffff;">'
        . '<th align="left" style="padding:10px 8px;">Job</th>'
        . '<th align="left" style="padding:10px 8px;">Lead</th>'
        . '<th align="left" style="padding:10px 8px;">Type</th>'
        . '<th align="left" style="padding:10px 8px;">Added</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . $more
        . '<p style="margin:18px 0 0;"><a href="' . $safeUrl . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;">Open Job To-Do List</a></p>'
        . '<p style="margin:18px 0 0;font-size:13px;color:#94a3b8;">' . $company . '</p>'
        . '</div>';
}

/**
 * @param list<array{admin_id:int,name:string,email:string,role:string,jobs:list<array<string,string>>,mailed:bool,mail_note:string}> $staffRows
 */
function pcvc_incomplete_jobs_digest_html(string $sentOn, array $staffRows, string $todoUrl): string
{
    $safeDate = pcvc_incomplete_jobs_h($sentOn);
    $safeUrl = pcvc_incomplete_jobs_h($todoUrl);
    $company = pcvc_incomplete_jobs_h(PCVC_COMPANY_DISPLAY_NAME);
    $rows = '';
    foreach ($staffRows as $staff) {
        $count = count($staff['jobs']);
        $note = pcvc_incomplete_jobs_h($staff['mail_note']);
        $rows .= '<tr>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;font-weight:600;">' . pcvc_incomplete_jobs_h($staff['name']) . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;">' . pcvc_incomplete_jobs_h($staff['email'] !== '' ? $staff['email'] : '—') . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $count . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #e2e8f0;">' . $note . '</td>'
            . '</tr>';
    }

    $people = count($staffRows);

    return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:680px;margin:0 auto;color:#1e293b;">'
        . '<p style="margin:0 0 12px;font-size:16px;">Job To-Do List reminder for <strong>' . $safeDate . '</strong></p>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;"><strong>' . $people . '</strong> '
        . ($people === 1 ? 'person still has' : 'people still have')
        . ' unfinished jobs. Each person with a valid email was reminded directly.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;background:#f8fafc;border:1px solid #e2e8f0;">'
        . '<thead><tr style="background:#0f3d4c;color:#ffffff;">'
        . '<th align="left" style="padding:10px 8px;">Staff</th>'
        . '<th align="left" style="padding:10px 8px;">Email</th>'
        . '<th align="right" style="padding:10px 8px;">Open jobs</th>'
        . '<th align="left" style="padding:10px 8px;">Reminder</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p style="margin:18px 0 0;"><a href="' . $safeUrl . '" style="color:#2563eb;">Open the Job To-Do List</a></p>'
        . '<p style="margin:18px 0 0;font-size:13px;color:#94a3b8;">' . $company . '</p>'
        . '</div>';
}

/**
 * @return array{ok:bool,error:string}
 */
function pcvc_incomplete_jobs_send(string $to, string $name, string $subject, string $html): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email'];
    }

    $result = sendSMTPMailDetailed($to, $subject, $html);
    if (!empty($result['ok'])) {
        return ['ok' => true, 'error' => ''];
    }

    return ['ok' => false, 'error' => (string) ($result['error'] ?? 'SMTP send failed')];
}

/**
 * @param array{dry_run?:bool,force?:bool,sent_on?:string} $options
 * @return array{exit_code:int,staff:int,emailed:int,skipped:int,failed:int,digest:int}
 */
function pcvc_incomplete_jobs_run(mysqli $conn, array $options = []): array
{
    date_default_timezone_set(PCVC_INCOMPLETE_JOBS_TZ);

    $dryRun = !empty($options['dry_run']);
    $force = !empty($options['force']);
    $sentOn = trim((string) ($options['sent_on'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentOn)) {
        $sentOn = pcvc_incomplete_jobs_today();
    }

    $stats = [
        'exit_code' => 0,
        'staff' => 0,
        'emailed' => 0,
        'skipped' => 0,
        'failed' => 0,
        'digest' => 0,
    ];

    if (!$dryRun && !pcvc_incomplete_jobs_ensure_log_table($conn)) {
        $stats['exit_code'] = 2;

        return $stats;
    }

    $allowedIds = pcvc_roster_admin_ids($conn);
    $groups = array_values(array_filter(
        pcvc_incomplete_jobs_fetch($conn),
        static function (array $staff) use ($allowedIds): bool {
            return isset($allowedIds[(int) $staff['admin_id']]);
        }
    ));
    $stats['staff'] = count($groups);
    pcvc_incomplete_jobs_log(
        'Contract list only — ' . count($allowedIds) . ' linked accounts, ' . count($groups) . ' with unfinished jobs.'
    );
    $todoUrl = pcvc_incomplete_jobs_todo_url();
    $prettyDate = (new DateTime($sentOn, pcvc_incomplete_jobs_timezone()))->format('M j, Y');

    if ($groups === []) {
        pcvc_incomplete_jobs_log('No contract-list staff have unfinished Job To-Do List items for ' . $sentOn . '.');

        return $stats;
    }

    $digestRows = [];

    foreach ($groups as $staff) {
        $count = count($staff['jobs']);
        $email = $staff['email'];
        $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        $recipientKey = (string) $staff['admin_id'];
        $subject = 'Unfinished jobs on your to-do list (' . $count . ') — ' . $prettyDate;

        if (!$valid) {
            $stats['failed']++;
            $digestRows[] = $staff + ['mailed' => false, 'mail_note' => 'Not emailed — no valid email on the staff profile'];
            pcvc_incomplete_jobs_log('SKIP no email: ' . $staff['name'] . ' (admin ' . $staff['admin_id'] . ', ' . $count . ' open)');
            if (!$dryRun) {
                pcvc_incomplete_jobs_record($conn, 'staff', $staff['admin_id'], $recipientKey, '', $sentOn, $count, 'failed', 'No valid email');
            }
            continue;
        }

        if (!$force && !$dryRun && pcvc_incomplete_jobs_already_sent($conn, 'staff', $recipientKey, $sentOn)) {
            $stats['skipped']++;
            $digestRows[] = $staff + ['mailed' => true, 'mail_note' => 'Already emailed today'];
            pcvc_incomplete_jobs_log('SKIP already sent today: ' . $email . ' (' . $count . ' open)');
            continue;
        }

        if ($dryRun) {
            $stats['emailed']++;
            $digestRows[] = $staff + ['mailed' => false, 'mail_note' => 'Dry run — would email'];
            pcvc_incomplete_jobs_log('DRY RUN would email ' . $email . ' — ' . $staff['name'] . ' — ' . $count . ' open job(s)');
            continue;
        }

        $html = pcvc_incomplete_jobs_staff_html($staff['name'], $staff['jobs'], $todoUrl);
        $sent = pcvc_incomplete_jobs_send($email, $staff['name'], $subject, $html);
        if ($sent['ok']) {
            $stats['emailed']++;
            pcvc_incomplete_jobs_record($conn, 'staff', $staff['admin_id'], $recipientKey, $email, $sentOn, $count, 'sent', '');
            $digestRows[] = $staff + ['mailed' => true, 'mail_note' => 'Emailed'];
            pcvc_incomplete_jobs_log('Emailed ' . $email . ' — ' . $staff['name'] . ' — ' . $count . ' open job(s)');
        } else {
            $stats['failed']++;
            $stats['exit_code'] = 1;
            pcvc_incomplete_jobs_record($conn, 'staff', $staff['admin_id'], $recipientKey, $email, $sentOn, $count, 'failed', $sent['error']);
            $digestRows[] = $staff + ['mailed' => false, 'mail_note' => 'Email failed'];
            pcvc_incomplete_jobs_log('FAILED ' . $email . ' — ' . $sent['error']);
        }
    }

    if (pcvc_incomplete_jobs_digest_enabled()) {
        $recipients = [];
        foreach (pcvc_incomplete_jobs_superadmins($conn) as $super) {
            $recipients[strtolower($super['email'])] = $super;
        }
        foreach (pcvc_incomplete_jobs_extra_digest_emails() as $extra) {
            $key = strtolower($extra);
            if (!isset($recipients[$key])) {
                $recipients[$key] = ['admin_id' => 0, 'name' => 'Supervisor', 'email' => $extra];
            }
        }

        $digestSubject = 'Job to-do list: ' . count($digestRows) . ' staff still have unfinished jobs — ' . $prettyDate;
        $digestHtml = pcvc_incomplete_jobs_digest_html($prettyDate, $digestRows, $todoUrl);

        foreach ($recipients as $recipient) {
            $key = sha1(strtolower($recipient['email']));
            if (!$force && !$dryRun && pcvc_incomplete_jobs_already_sent($conn, 'digest', $key, $sentOn)) {
                pcvc_incomplete_jobs_log('SKIP digest already sent today: ' . $recipient['email']);
                continue;
            }
            if ($dryRun) {
                $stats['digest']++;
                pcvc_incomplete_jobs_log('DRY RUN would email digest to ' . $recipient['email']);
                continue;
            }
            $sent = pcvc_incomplete_jobs_send($recipient['email'], $recipient['name'], $digestSubject, $digestHtml);
            if ($sent['ok']) {
                $stats['digest']++;
                pcvc_incomplete_jobs_record($conn, 'digest', $recipient['admin_id'], $key, $recipient['email'], $sentOn, count($digestRows), 'sent', '');
                pcvc_incomplete_jobs_log('Digest emailed to ' . $recipient['email']);
            } else {
                $stats['failed']++;
                $stats['exit_code'] = 1;
                pcvc_incomplete_jobs_record($conn, 'digest', $recipient['admin_id'], $key, $recipient['email'], $sentOn, count($digestRows), 'failed', $sent['error']);
                pcvc_incomplete_jobs_log('Digest FAILED ' . $recipient['email'] . ' — ' . $sent['error']);
            }
        }
    }

    pcvc_incomplete_jobs_log(sprintf(
        'Finished %s — staff:%d emailed:%d skipped:%d failed:%d digest:%d%s',
        $sentOn,
        $stats['staff'],
        $stats['emailed'],
        $stats['skipped'],
        $stats['failed'],
        $stats['digest'],
        $dryRun ? ' (dry run)' : ''
    ));

    return $stats;
}

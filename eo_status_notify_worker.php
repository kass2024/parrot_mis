<?php
/**
 * Background status/approval emails for Employment Opportunities admin actions.
 * Usage: php eo_status_notify_worker.php <application_id> <status> [base64_note]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$appId = isset($argv[1]) ? (int) $argv[1] : 0;
$status = trim((string) ($argv[2] ?? ''));
$noteB64 = (string) ($argv[3] ?? '');
$note = $noteB64 !== '' ? (string) base64_decode($noteB64, true) : '';
if ($note === false) {
    $note = '';
}

$allowed = ['pending', 'under_review', 'approved', 'rejected'];
if ($appId <= 0 || !in_array($status, $allowed, true)) {
    fwrite(STDERR, "Invalid args\n");
    exit(1);
}

@set_time_limit(180);
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/employment_opportunities_schema.php';
require_once __DIR__ . '/helpers/employment_opportunities_files.php';
require_once __DIR__ . '/helpers/employment_opportunities_notify.php';

eo_ensure_schema($conn);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/eo-status-notify.log';
$log = static function (string $msg) use ($logFile, $appId): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [app:{$appId}] {$msg}" . PHP_EOL, FILE_APPEND);
};

$stmt = $conn->prepare('SELECT * FROM employment_opportunities_applications WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $appId);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    $log('Application not found');
    exit(1);
}

$app['status'] = $status;
$log('Worker started status=' . $status);

try {
    $to = trim((string) ($app['email'] ?? ''));
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $name  = htmlspecialchars((string) ($app['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ref   = htmlspecialchars((string) ($app['reference_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $label = ucwords(str_replace('_', ' ', $status));
        $noteHtml = $note !== ''
            ? '<p style="background:#f1f5f9;padding:12px;border-radius:8px">' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>'
            : '';
        $body = "<p>Dear {$name},</p>
            <p>The status of your Employment Opportunities application <strong>{$ref}</strong> is now: <strong>{$label}</strong>.</p>
            {$noteHtml}
            <p>Our team will contact you on WhatsApp or Telegram with any next steps.</p>";
        $ok = sendSMTPMail(
            $to,
            'Employment Opportunities — Application Update — ' . ($app['reference_id'] ?? ''),
            eo_email_wrap('Application Update', $body)
        );
        $log('Applicant email: ' . ($ok ? 'OK' : 'FAILED'));
    } else {
        $log('Applicant email skipped (missing/invalid)');
    }
} catch (Throwable $e) {
    $log('Applicant email exception: ' . $e->getMessage());
}

if ($status === 'approved') {
    try {
        $ok = eo_notify_office_new_application($app);
        $log('Approval package: ' . ($ok ? 'OK' : 'FAILED'));
    } catch (Throwable $e) {
        $log('Approval package exception: ' . $e->getMessage());
    }
}

$log('Worker finished');
exit(0);

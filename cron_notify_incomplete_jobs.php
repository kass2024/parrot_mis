<?php
declare(strict_types=1);

/**
 * Daily cron: email staff who still have unfinished Job To-Do List items.
 *
 * Sends at most once per person per office day (Africa/Kigali). A second run
 * the same day does not send again unless --force is passed.
 *
 * Windows Task Scheduler (this PC):
 *   Task name: ParrotMIS Incomplete Job To-Do Reminder
 *   Daily at 19:00
 *   Register or refresh it with:
 *     powershell -ExecutionPolicy Bypass -File scripts\register_incomplete_jobs_cron.ps1
 *
 * Manual:
 *   php cron_notify_incomplete_jobs.php
 *   php cron_notify_incomplete_jobs.php --dry-run
 *   php cron_notify_incomplete_jobs.php --force
 *
 * cPanel — 19:00 Africa/Kigali. If the server clock is UTC, 19:00 Kigali is 17:00 UTC:
 *   0 17 * * * /usr/local/bin/php /home/USERNAME/public_html/cron_notify_incomplete_jobs.php >> /home/USERNAME/logs/incomplete_jobs_cron.log 2>&1
 *
 * Optional browser/cPanel URL trigger. Set JOB_TODO_CRON_KEY in .env first.
 * Without that key, opening this file in a browser does nothing.
 *   https://visaconsultantcanada.com/cron_notify_incomplete_jobs.php?key=THE_KEY
 */

require_once __DIR__ . '/helpers/env_load.php';
require_once __DIR__ . '/helpers/incomplete_jobs_notify.php';

date_default_timezone_set('Africa/Kigali');

/**
 * @return array{dry_run:bool,force:bool,sent_on:string}
 */
function pcvc_incomplete_jobs_cli_options(array $argv): array
{
    $options = ['dry_run' => false, 'force' => false, 'sent_on' => ''];
    foreach (array_slice($argv, 1) as $arg) {
        $arg = (string) $arg;
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
            $options['sent_on'] = $m[1];
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
            $options['sent_on'] = $arg;
        }
    }

    return $options;
}

/**
 * @return array{ok:bool,dry_run:bool,force:bool,sent_on:string}
 */
function pcvc_incomplete_jobs_web_options(): array
{
    xander_load_env_file();
    $expected = trim(xander_env_get('JOB_TODO_CRON_KEY'));
    if ($expected === '') {
        $expected = trim(xander_env_get_from_dotenv_file('JOB_TODO_CRON_KEY'));
    }
    $given = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        return ['ok' => false, 'dry_run' => false, 'force' => false, 'sent_on' => ''];
    }

    $sentOn = trim((string) ($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentOn)) {
        $sentOn = '';
    }

    return [
        'ok' => true,
        'dry_run' => isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1',
        'force' => isset($_GET['force']) && (string) $_GET['force'] === '1',
        'sent_on' => $sentOn,
    ];
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $web = pcvc_incomplete_jobs_web_options();
    if (!$web['ok']) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    $options = [
        'dry_run' => $web['dry_run'],
        'force' => $web['force'],
        'sent_on' => $web['sent_on'],
    ];
} else {
    $options = pcvc_incomplete_jobs_cli_options($argv ?? []);
}

require_once __DIR__ . '/db.php';

$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$sentOn = $options['sent_on'] !== '' ? $options['sent_on'] : pcvc_incomplete_jobs_today();
$options['sent_on'] = $sentOn;
$ranMarker = $logDir . DIRECTORY_SEPARATOR . 'incomplete_jobs_last_date.txt';

if (
    !$options['dry_run']
    && !$options['force']
    && is_readable($ranMarker)
    && trim((string) file_get_contents($ranMarker)) === $sentOn
) {
    $message = 'Incomplete-job reminder already finished for ' . $sentOn . '. Skipping.';
    pcvc_incomplete_jobs_log($message);
    exit(0);
}

$lockFp = @fopen($logDir . DIRECTORY_SEPARATOR . 'incomplete_jobs_cron.lock', 'c+');
if ($lockFp !== false && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    pcvc_incomplete_jobs_log('Incomplete-job reminder is already running.');
    exit(0);
}

try {
    $stats = pcvc_incomplete_jobs_run($conn, $options);
} catch (Throwable $e) {
    pcvc_incomplete_jobs_log('Cron failed: ' . $e->getMessage());
    $stats = ['exit_code' => 1];
}

if (!$options['dry_run'] && (int) ($stats['exit_code'] ?? 1) === 0) {
    @file_put_contents($ranMarker, $sentOn, LOCK_EX);
}

if ($lockFp !== false) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

exit((int) ($stats['exit_code'] ?? 1));

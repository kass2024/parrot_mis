<?php
declare(strict_types=1);

/**
 * Employment Opportunities — email notifications (office inbox + optional applicant).
 */
require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/employment_opportunities_schema.php';
require_once __DIR__ . '/employment_opportunities_files.php';

function eo_email_wrap(string $title, string $innerHtml): string
{
    return "
    <html><body style='font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:20px;color:#1e293b'>
      <div style='background:linear-gradient(135deg,#1e4d2b 0%,#3661B9 100%);color:#fff;padding:28px;border-radius:12px 12px 0 0;text-align:center'>
        <h1 style='margin:0;font-size:22px'>{$title}</h1>
        <p style='margin:8px 0 0;opacity:.9;font-size:14px'>Parrot Canada Visa Consultant — Employment Opportunities</p>
      </div>
      <div style='background:#fff;border:1px solid #e2e8f0;border-top:0;padding:28px;border-radius:0 0 12px 12px'>
        {$innerHtml}
        <p style='margin-top:24px;font-size:12px;color:#64748b;text-align:center'>© " . date('Y') . " Parrot Canada Visa Consultant</p>
      </div>
    </body></html>";
}

function eo_notify_recipient_email(): string
{
    xander_load_env_file();
    // Prefer APPROVAL_EMAIL (matches Francophonie naming), fall back to NOTIFY_EMAIL.
    foreach (['EMPLOYMENT_OPPORTUNITIES_APPROVAL_EMAIL', 'EMPLOYMENT_OPPORTUNITIES_NOTIFY_EMAIL'] as $key) {
        $to = trim(xander_env_get($key));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = trim(xander_env_get_from_dotenv_file($key));
        }
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }
    }
    return '';
}

function eo_build_summary_html(array $row): string
{
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $field = eo_training_field_label((string) ($row['training_field'] ?? ''));
    $msgApp = ucfirst((string) ($row['messaging_app'] ?? 'whatsapp'));
    $phone = trim('+' . ($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? ''));

    return '
    <h3 style="margin-top:0;color:#1e4d2b">Applicant Details</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px">
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0;width:40%"><strong>Full Name</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['full_name'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Reference</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['reference_id'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Passport Number</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['passport_number'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Phone</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($phone) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Contact App</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($msgApp) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Email</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['email'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Training Field</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($field) . '</td></tr>
    </table>
    <p style="font-size:13px;color:#64748b">Passport scan and academic documents are attached to this email.</p>';
}

/**
 * @return array{attachments: array<int, array{path:string, name:string}>, labels: string[]}
 */
function eo_collect_attachments(array $row): array
{
    $attachments = [];
    $labels = [];
    $ref = (string) ($row['reference_id'] ?? 'EO');

    $passportAbs = eo_abs_upload_path((string) ($row['passport_file'] ?? ''));
    if ($passportAbs !== null) {
        $ext = pathinfo($passportAbs, PATHINFO_EXTENSION);
        $attachments[] = [
            'path' => $passportAbs,
            'name' => $ref . '_Passport' . ($ext ? '.' . $ext : ''),
        ];
        $labels[] = 'Passport';
    }

    $academicPaths = eo_parse_stored_files((string) ($row['academic_docs_file'] ?? ''));
    foreach ($academicPaths as $i => $rel) {
        $abs = eo_abs_upload_path($rel);
        if ($abs === null) {
            continue;
        }
        $ext = pathinfo($abs, PATHINFO_EXTENSION);
        $n = $i + 1;
        $attachments[] = [
            'path' => $abs,
            'name' => $ref . '_Academic_' . $n . ($ext ? '.' . $ext : ''),
        ];
        $labels[] = 'Academic Document ' . $n;
    }

    return ['attachments' => $attachments, 'labels' => $labels];
}

/** Email full application + documents to EMPLOYMENT_OPPORTUNITIES_NOTIFY_EMAIL (on approval). */
function eo_notify_office_new_application(array $row): bool
{
    $to = eo_notify_recipient_email();
    if ($to === '') {
        error_log('EMPLOYMENT_OPPORTUNITIES_NOTIFY_EMAIL is not set or invalid in .env');
        return false;
    }

    $pack = eo_collect_attachments($row);
    $body = '<p>An <strong>Employment Opportunities</strong> application has been <strong>approved</strong>. Full details and documents are attached.</p>'
        . eo_build_summary_html($row);

    if ($pack['labels'] !== []) {
        $body .= '<p><strong>Attachments:</strong> ' . htmlspecialchars(implode(', ', $pack['labels']), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $subject = 'Approved Employment Opportunities Application — ' . ($row['reference_id'] ?? '');
    return sendSMTPMail($to, $subject, eo_email_wrap('Approved Application Package', $body), $pack['attachments']);
}

function eo_notify_applicant_received(array $row): bool
{
    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($row['reference_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $field = htmlspecialchars(eo_training_field_label((string) ($row['training_field'] ?? '')), ENT_QUOTES, 'UTF-8');

    $body = "<p>Dear {$name},</p>
        <p>Thank you for applying to <strong>Employment Opportunities</strong> (professional training with Russian language).</p>
        <p style='font-family:monospace;font-size:18px;background:#f1f5f9;padding:12px;border-radius:8px'><strong>{$ref}</strong></p>
        <p><strong>Selected field:</strong> {$field}</p>
        <p>Save this reference ID. Our team will contact you on WhatsApp or Telegram using the number you provided.</p>";

    $subject = 'Employment Opportunities — Application Received — ' . ($row['reference_id'] ?? '');
    return sendSMTPMail($to, $subject, eo_email_wrap('Application Received', $body));
}

/** HMAC token for the async notify HTTP endpoint. */
function eo_notify_async_token(string $referenceId): string
{
    xander_load_env_file();
    $secret = xander_env_get('SMTP_PASSWORD');
    if ($secret === '') {
        $secret = xander_env_get_from_dotenv_file('SMTP_PASSWORD');
    }
    if ($secret === '') {
        $secret = 'eo-notify-fallback-secret';
    }
    return hash_hmac('sha256', 'eo_applicant|' . $referenceId, $secret);
}

/** HMAC token for approval package async endpoint. */
function eo_approval_async_token(int $appId): string
{
    xander_load_env_file();
    $secret = xander_env_get('SMTP_PASSWORD');
    if ($secret === '') {
        $secret = xander_env_get_from_dotenv_file('SMTP_PASSWORD');
    }
    if ($secret === '') {
        $secret = 'eo-notify-fallback-secret';
    }
    return hash_hmac('sha256', 'eo_approval|' . $appId, $secret);
}

/**
 * Generic fire-and-forget HTTP GET (shared hosting safe; no CLI needed).
 */
function eo_http_fire_and_forget(string $relScriptWithQuery): bool
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'mis.visaconsultantcanada.com');
    $scheme = $https ? 'https' : 'http';

    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if ($base === '/' || $base === '\\') {
        $base = '';
    }
    $relPath = $base . '/' . ltrim($relScriptWithQuery, '/');

    $candidates = [];
    $candidates[] = ($https ? 'https' : 'http') . '://127.0.0.1' . $relPath;
    $candidates[] = $scheme . '://' . $host . $relPath;

    foreach ($candidates as $url) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            $headers = ['Connection: Close'];
            if (strpos($url, '127.0.0.1') !== false) {
                $headers[] = 'Host: ' . $host;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            @curl_exec($ch);
            $errno = curl_errno($ch);
            @curl_close($ch);
            if ($errno === 0 || $errno === 28) {
                return true;
            }
        }
    }

    $url = $scheme . '://' . $host . $relPath;
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        error_log('EO async fire: bad URL ' . $url);
        return false;
    }
    $hostOnly = $parts['host'];
    $port = isset($parts['port']) ? (int) $parts['port'] : (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
    $reqPath = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $transport = (($parts['scheme'] ?? '') === 'https') ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($transport . $hostOnly . ':' . $port, $errno, $errstr, 2, STREAM_CLIENT_CONNECT);
    if ($fp === false) {
        error_log('EO async fire socket failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 1);
    $out = "GET {$reqPath} HTTP/1.1\r\nHost: {$hostOnly}\r\nConnection: Close\r\n\r\n";
    @fwrite($fp, $out);
    @fclose($fp);
    return true;
}

/**
 * Kick off applicant confirmation email without waiting.
 */
function eo_fire_async_applicant_notify(string $referenceId): bool
{
    $token = eo_notify_async_token($referenceId);
    $query = 'eo_notify_async.php?ref=' . rawurlencode($referenceId) . '&t=' . rawurlencode($token);
    return eo_http_fire_and_forget($query);
}

/**
 * Kick off office approval package email (details + docs). Does NOT notify applicant.
 */
function eo_fire_async_approval_package(int $appId): bool
{
    if ($appId <= 0) {
        return false;
    }
    $token = eo_approval_async_token($appId);
    $query = 'eo_approval_async.php?id=' . $appId . '&t=' . rawurlencode($token);
    return eo_http_fire_and_forget($query);
}


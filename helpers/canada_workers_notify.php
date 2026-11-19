<?php
declare(strict_types=1);

/**
 * Emails for Canada general-workers recruitment applications.
 */
require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/canada_workers_schema.php';
require_once __DIR__ . '/canada_workers_files.php';

function cuw_email_wrap(string $title, string $innerHtml): string
{
    return "
    <html><body style='font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:20px;color:#1e293b'>
      <div style='background:linear-gradient(135deg,#0B3A5B 0%,#1B6CA8 100%);color:#fff;padding:28px;border-radius:12px 12px 0 0;text-align:center'>
        <h1 style='margin:0;font-size:20px'>{$title}</h1>
        <p style='margin:8px 0 0;opacity:.9;font-size:14px'>Parrot Canada Visa Consultant Co. Ltd.</p>
      </div>
      <div style='background:#fff;border:1px solid #e2e8f0;border-top:0;padding:28px;border-radius:0 0 12px 12px'>
        {$innerHtml}
        <p style='margin-top:24px;font-size:12px;color:#64748b;text-align:center'>
          jobs@visaconsultantcanada.ca · WhatsApp +1 (431) 302-0226 · www.visaconsultantcanada.com
        </p>
      </div>
    </body></html>";
}

/**
 * @return array{attachments: array<int, array{path:string, name:string}>, labels: string[]}
 */
function cuw_collect_attachments(array $row): array
{
    $attachments = [];
    $labels = [];
    $ref = (string) ($row['reference_id'] ?? 'CUW');
    $map = [
        'passport_file' => 'Passport_Data_Page',
        'cv_file' => 'Resume_CV',
        'photo_file' => 'Passport_Photo',
    ];

    foreach ($map as $col => $label) {
        $abs = cuw_abs_upload_path((string) ($row[$col] ?? ''));
        if ($abs === null) {
            continue;
        }
        $ext = pathinfo($abs, PATHINFO_EXTENSION);
        $attachments[] = [
            'path' => $abs,
            'name' => $ref . '_' . $label . ($ext ? '.' . $ext : ''),
        ];
        $labels[] = str_replace('_', ' ', $label);
    }

    return ['attachments' => $attachments, 'labels' => $labels];
}

function cuw_application_summary_html(array $row): string
{
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $phone = trim('+' . ($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? ''));
    $years = (string) ($row['years_experience'] ?? '');

    return '
    <table style="width:100%;border-collapse:collapse;margin:12px 0 20px;font-size:14px">
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0;width:42%"><strong>Full name</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['full_name'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Date of birth</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['date_of_birth'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Gender</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc(cuw_gender_label((string) ($row['gender'] ?? ''))) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Nationality</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['nationality'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Country of residence</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['country_of_residence'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Phone / WhatsApp</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($phone) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Email</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['email'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Current address</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . nl2br($esc($row['current_address'] ?? '')) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>How did you know us</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['how_did_you_know_us'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Position</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc(cuw_position_label((string) ($row['position'] ?? ''))) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Salary</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc(cuw_position_salary((string) ($row['position'] ?? ''))) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Current occupation</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['current_occupation'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Years of experience</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($years) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Highest education</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc(cuw_education_label((string) ($row['education_level'] ?? ''))) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Previous work</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . nl2br($esc($row['work_experience_description'] ?? '')) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Reference</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['reference_id'] ?? '') . '</td></tr>
    </table>';
}

/**
 * @return array{ok:bool, error?:string}
 */
function cuw_notify_jobs_inbox(array $row): array
{
    $pack = cuw_collect_attachments($row);
    $name = trim((string) ($row['full_name'] ?? 'Applicant'));
    $position = cuw_position_label((string) ($row['position'] ?? ''));
    $subject = 'Approved — CANADA UNSKILLED WORKERS RECRUITMENT — ' . $name . ' — ' . $position;
    $attachNote = $pack['labels'] !== []
        ? '<p><strong>Attachments:</strong> ' . htmlspecialchars(implode(', ', $pack['labels']), ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $userId = trim((string) ($row['user_id'] ?? ''));
    $body = '<p>An application for <strong>CANADA UNSKILLED WORKERS RECRUITMENT</strong> has been approved. The application details and required documents are attached.</p>'
        . cuw_application_summary_html($row)
        . ($userId !== '' ? '<p><strong>Application user ID:</strong> ' . htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') . '</p>' : '')
        . $attachNote
        . '<p style="font-size:13px;color:#64748b">Passport data page, résumé, and photograph are attached.</p>';

    $replyTo = trim((string) ($row['email'] ?? ''));

    return sendSMTPMailDetailed(
        cuw_agency_inbox(),
        $subject,
        cuw_email_wrap('CANADA UNSKILLED WORKERS RECRUITMENT', $body),
        $pack['attachments'],
        [],
        null,
        [cuw_jobs_copy_inbox()],
        $replyTo
    );
}

function cuw_notify_applicant_received(array $row): bool
{
    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($row['reference_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $position = htmlspecialchars(cuw_position_choice_label((string) ($row['position'] ?? '')), ENT_QUOTES, 'UTF-8');
    $userId = htmlspecialchars((string) ($row['user_id'] ?? ''), ENT_QUOTES, 'UTF-8');

    $body = "<p>Dear {$name},</p>
        <p><strong>Application Submitted Successfully</strong></p>
        <p>Thank you for submitting your application. Your information will be reviewed, and you will be contacted regarding the next steps if appropriate.</p>
        <p><strong>Position:</strong> {$position}</p>
        <p style='font-family:monospace;font-size:18px;background:#f1f5f9;padding:12px;border-radius:8px'><strong>{$ref}</strong></p>
        <p>Save this reference ID. To retrieve your application later, you can also use this application user ID:</p>
        <p style='font-family:monospace;background:#f8fafc;padding:10px;border-radius:8px'>{$userId}</p>
        <p>Parrot Canada Visa Consultant Co. Ltd.<br>
        jobs@visaconsultantcanada.ca<br>
        WhatsApp: +1 (431) 302-0226<br>
        www.visaconsultantcanada.com</p>";

    $subject = 'Application Received — CANADA UNSKILLED WORKERS RECRUITMENT — ' . ($row['reference_id'] ?? '');
    return sendSMTPMail($to, $subject, cuw_email_wrap('Application Submitted Successfully', $body));
}

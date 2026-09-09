<?php
declare(strict_types=1);

/**
 * Noble Education Group — Canada admissions approval email.
 */
require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/noble_education_schema.php';
require_once __DIR__ . '/noble_education_files.php';

function neg_approval_to_email(): string
{
    return 'admissionspublic@nobleedugroup.ca';
}

/** @return list<string> */
function neg_approval_cc_emails(): array
{
    return [
        'lydia@nobleedugroup.ca',
        'infos@visaconsultantcanada.com',
    ];
}

function neg_email_wrap(string $title, string $innerHtml): string
{
    return "
    <html><body style='font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:20px;color:#1e293b'>
      <div style='background:linear-gradient(135deg,#0B3D2E 0%,#1B6CA8 100%);color:#fff;padding:28px;border-radius:12px 12px 0 0;text-align:center'>
        <h1 style='margin:0;font-size:22px'>{$title}</h1>
        <p style='margin:8px 0 0;opacity:.9;font-size:14px'>Noble Education Group — Canada · Parrot Canada Visa Consultant</p>
      </div>
      <div style='background:#fff;border:1px solid #e2e8f0;border-top:0;padding:28px;border-radius:0 0 12px 12px'>
        {$innerHtml}
        <p style='margin-top:24px;font-size:12px;color:#64748b;text-align:center'>© " . date('Y') . " Parrot Canada Visa Consultant</p>
      </div>
    </body></html>";
}

/**
 * @return array{attachments: array<int, array{path:string, name:string}>, labels: string[]}
 */
function neg_collect_attachments(array $row): array
{
    $attachments = [];
    $labels = [];
    $ref = (string) ($row['reference_id'] ?? 'NEG');

    $map = [
        'passport_file' => 'Passport',
        'high_school_certificate_file' => 'High_School_Certificate',
        'transcripts_file' => 'Transcripts',
    ];

    foreach ($map as $col => $label) {
        $abs = neg_abs_upload_path((string) ($row[$col] ?? ''));
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

    $others = neg_decode_other_docs(isset($row['other_docs_json']) ? (string) $row['other_docs_json'] : null);
    $i = 1;
    foreach ($others as $rel) {
        $abs = neg_abs_upload_path($rel);
        if ($abs === null) {
            continue;
        }
        $ext = pathinfo($abs, PATHINFO_EXTENSION);
        $attachments[] = [
            'path' => $abs,
            'name' => $ref . '_Other_' . $i . ($ext ? '.' . $ext : ''),
        ];
        $labels[] = 'Other document ' . $i;
        $i++;
    }

    return ['attachments' => $attachments, 'labels' => $labels];
}

/** @param string[] $attachmentLabels */
function neg_build_approval_body(array $row, array $attachmentLabels = []): string
{
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $phone = trim('+' . ($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? ''));
    $levelProgram = neg_level_program_label($row);
    $attachNote = $attachmentLabels !== []
        ? '<p><strong>Attachments:</strong> ' . $esc(implode(', ', $attachmentLabels)) . '</p>'
        : '';

    return '
    <p>A new Noble Education Group — Canada admission application has been <strong>approved</strong> and is ready for processing.</p>
    <h3 style="margin-top:16px;color:#0B3D2E">Student details</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px">
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0;width:40%"><strong>Full name</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['full_name'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Email address</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['email'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Mobile contact</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($phone) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Level and program of interest</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($levelProgram) . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Agent name</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['agent_name'] ?? '') . '</td></tr>
      <tr><td style="padding:6px;border-bottom:1px solid #e2e8f0"><strong>Reference</strong></td><td style="padding:6px;border-bottom:1px solid #e2e8f0">' . $esc($row['reference_id'] ?? '') . '</td></tr>
    </table>
    ' . $attachNote . '
    <p style="font-size:13px;color:#64748b">Clear copies of passport, high school certificate, transcripts, and any other relevant documents are attached when available.</p>';
}

/**
 * Send admissions approval email with document attachments.
 *
 * @return array{ok:bool, error?:string}
 */
function neg_send_approval_email(array $row): array
{
    $to = neg_approval_to_email();
    $cc = neg_approval_cc_emails();
    $pack = neg_collect_attachments($row);

    $studentName = trim((string) ($row['full_name'] ?? 'Student'));
    $levelProgram = neg_level_program_label($row);
    $subject = 'New Admissions ' . $studentName . ' - ' . $levelProgram;

    $body = neg_email_wrap('New Admissions', neg_build_approval_body($row, $pack['labels']));

    return sendSMTPMailDetailed($to, $subject, $body, $pack['attachments'], [], null, $cc);
}

function neg_notify_applicant_received(array $row): bool
{
    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($row['reference_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $program = htmlspecialchars(neg_level_program_label($row), ENT_QUOTES, 'UTF-8');

    $body = "<p>Dear {$name},</p>
        <p>Thank you for submitting your <strong>Noble Education Group — Canada</strong> admissions application.</p>
        <p style='font-family:monospace;font-size:18px;background:#f1f5f9;padding:12px;border-radius:8px'><strong>{$ref}</strong></p>
        <p><strong>Program:</strong> {$program}</p>
        <p>Save this reference ID. Our team will review your documents and contact you.</p>";

    $subject = 'Noble Education Group — Canada — Application Received — ' . ($row['reference_id'] ?? '');
    return sendSMTPMail($to, $subject, neg_email_wrap('Application Received', $body));
}

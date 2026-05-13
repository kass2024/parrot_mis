<?php
declare(strict_types=1);

require_once __DIR__ . '/study_choices.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../includes/company_branding.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Validate FK relationships for a study choice row (region ↔ university ↔ program).
 *
 * @return non-empty-string|null Error message, or null when valid.
 */
function pcvc_validate_study_choice_relations(
    mysqli $conn,
    int $regionId,
    int $universityId,
    int $levelId,
    int $programId
): ?string {
    if ($regionId <= 0 || $universityId <= 0 || $levelId <= 0 || $programId <= 0) {
        return 'Each of region, university, level, and program must be selected.';
    }

    $st = $conn->prepare('SELECT region_id FROM universities WHERE id = ? LIMIT 1');
    if (!$st) {
        return 'Database error (university lookup).';
    }
    $st->bind_param('i', $universityId);
    $st->execute();
    $ur = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$ur) {
        return 'University not found.';
    }
    if ((int) ($ur['region_id'] ?? 0) !== $regionId) {
        return 'University does not belong to the selected region.';
    }

    $st = $conn->prepare(
        'SELECT id FROM programs WHERE id = ? AND university_id = ? AND program_level_id = ? AND is_active = 1 LIMIT 1'
    );
    if (!$st) {
        return 'Database error (program lookup).';
    }
    $st->bind_param('iii', $programId, $universityId, $levelId);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();

    if (!$ok) {
        return 'Program does not match this university and level (or is inactive).';
    }

    return null;
}

/**
 * Insert one study choice; detects duplicate unique key (errno 1062).
 *
 * @return array{inserted:bool,duplicate:bool}
 */
function pcvc_try_insert_application_study_choice(
    mysqli $conn,
    int $applicationId,
    int $regionId,
    int $universityId,
    int $levelId,
    int $programId
): array {
    pcvc_ensure_study_choice_schema($conn);

    $stmt = $conn->prepare(
        'INSERT INTO application_study_choices
            (application_id, region_id, university_id, program_level_id, program_id)
         VALUES (?,?,?,?,?)'
    );
    if (!$stmt) {
        return ['inserted' => false, 'duplicate' => false, 'error' => 'Could not prepare insert.'];
    }

    $stmt->bind_param('iiiii', $applicationId, $regionId, $universityId, $levelId, $programId);

    if (!$stmt->execute()) {
        $errno = (int) ($stmt->errno ?: $conn->errno);
        $err = (string) ($stmt->error ?: $conn->error);
        $stmt->close();
        if ($errno === 1062) {
            return ['inserted' => false, 'duplicate' => true, 'error' => ''];
        }
        return ['inserted' => false, 'duplicate' => false, 'error' => $err !== '' ? $err : 'Insert failed.'];
    }

    $stmt->close();
    return ['inserted' => true, 'duplicate' => false, 'error' => ''];
}

/**
 * Same rows as api/applications.php?view study_choices payload.
 *
 * @return list<array<string,mixed>>
 */
function pcvc_fetch_study_choices_for_admin_view(mysqli $conn, int $applicationId): array
{
    if ($applicationId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "
        SELECT
            r.name  AS region,
            u.name  AS university,
            c.name  AS university_country,
            pl.name AS program_level,
            pl.abbreviation AS program_level_abbr,
            p.program_name AS program
        FROM application_study_choices ascx
        JOIN universities u    ON u.id = ascx.university_id
        JOIN regions r         ON r.id = ascx.region_id
        JOIN program_levels pl ON pl.id = ascx.program_level_id
        JOIN programs p        ON p.id = ascx.program_id
        LEFT JOIN countries c  ON c.id = u.country_id
        WHERE ascx.application_id = ?
        ORDER BY ascx.id ASC
    "
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows ?: [];
}

/**
 * Auto-create platform jobs for one university on an application (mirrors view endpoint logic).
 */
function pcvc_ensure_auto_jobs_for_university(mysqli $conn, int $applicationId, int $universityId): int
{
    if ($applicationId <= 0 || $universityId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        '
        SELECT first_name, last_name, email
        FROM student_applications
        WHERE id = ?
        LIMIT 1
    '
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $basic = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $applicantName = '';
    $applicantEmail = '';
    if ($basic) {
        $applicantName = trim((string) ($basic['first_name'] ?? '') . ' ' . (string) ($basic['last_name'] ?? ''));
        $applicantEmail = trim((string) ($basic['email'] ?? ''));
    }

    $stmt = $conn->prepare(
        '
        SELECT
            u.id AS university_id,
            u.name AS university_name,
            c.name AS country_name
        FROM universities u
        LEFT JOIN countries c ON c.id = u.country_id
        WHERE u.id = ?
        LIMIT 1
    '
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $universityId);
    $stmt->execute();
    $choice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$choice) {
        return 0;
    }

    $checkJob = $conn->prepare(
        '
        SELECT id
        FROM job_list
        WHERE
            application_id = ?
            AND university_id = ?
            AND admin_id = ?
            AND platform_id = ?
            AND is_auto_created = 1
        LIMIT 1
    '
    );
    if (!$checkJob) {
        return 0;
    }

    $insertJob = $conn->prepare(
        '
        INSERT INTO job_list
            (
                admin_id,
                application_id,
                university_id,
                platform_id,
                title,
                applicant_name,
                applicant_email,
                job_type,
                status,
                is_auto_created
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, \'Student Admission Application\', \'not_completed\', 1)
    '
    );
    if (!$insertJob) {
        $checkJob->close();
        return 0;
    }

    $stmt = $conn->prepare(
        '
        SELECT
            a.id AS admin_id,
            p.id AS platform_id
        FROM university_platforms up
        JOIN platforms p ON p.id = up.platform_id
        JOIN admins a ON a.id = p.person_in_charge
        WHERE up.university_id = ?
          AND p.status = \'Active\'
        ORDER BY up.is_preferred DESC, p.id ASC
    '
    );
    if (!$stmt) {
        $checkJob->close();
        $insertJob->close();
        return 0;
    }

    $stmt->bind_param('i', $choice['university_id']);
    $stmt->execute();
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$admins) {
        $checkJob->close();
        $insertJob->close();
        return 0;
    }

    $jobsCreated = 0;
    $uid = (int) $choice['university_id'];

    foreach ($admins as $admin) {
        $adminId = (int) $admin['admin_id'];
        $platformId = (int) $admin['platform_id'];

        $jobTitle = sprintf(
            'Application #%d – %s (%s)',
            $applicationId,
            $choice['university_name'],
            $choice['country_name'] ?? 'Unknown'
        );

        $checkJob->bind_param('iiii', $applicationId, $uid, $adminId, $platformId);
        $checkJob->execute();
        $checkJob->store_result();

        if ($checkJob->num_rows > 0) {
            continue;
        }

        $insertJob->bind_param(
            'iiiisss',
            $adminId,
            $applicationId,
            $uid,
            $platformId,
            $jobTitle,
            $applicantName,
            $applicantEmail
        );

        if ($insertJob->execute()) {
            $jobsCreated++;
        }
    }

    $checkJob->close();
    $insertJob->close();

    return $jobsCreated;
}

/**
 * Email the student that a study choice was added by staff (best-effort; errors swallowed).
 */
function pcvc_notify_student_study_choice_added(
    mysqli $conn,
    int $applicationId,
    int $regionId,
    int $universityId,
    int $levelId,
    int $programId
): bool {
    $stmt = $conn->prepare(
        '
        SELECT first_name, last_name, email
        FROM student_applications
        WHERE id = ?
        LIMIT 1
    '
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app) {
        return false;
    }

    $email = trim((string) ($app['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = trim((string) ($app['first_name'] ?? '') . ' ' . (string) ($app['last_name'] ?? ''));
    if ($name === '') {
        $name = 'Applicant';
    }

    $stmt = $conn->prepare(
        '
        SELECT
            r.name AS region,
            u.name AS university,
            c.name AS university_country,
            pl.name AS program_level,
            pl.abbreviation AS program_level_abbr,
            p.program_name AS program
        FROM application_study_choices ascx
        JOIN universities u ON u.id = ascx.university_id
        JOIN regions r ON r.id = ascx.region_id
        JOIN program_levels pl ON pl.id = ascx.program_level_id
        JOIN programs p ON p.id = ascx.program_id
        LEFT JOIN countries c ON c.id = u.country_id
        WHERE ascx.application_id = ?
          AND ascx.region_id = ?
          AND ascx.university_id = ?
          AND ascx.program_level_id = ?
          AND ascx.program_id = ?
        LIMIT 1
    '
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiiii', $applicationId, $regionId, $universityId, $levelId, $programId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }

    $region = htmlspecialchars((string) ($row['region'] ?? ''), ENT_QUOTES, 'UTF-8');
    $uni = htmlspecialchars((string) ($row['university'] ?? ''), ENT_QUOTES, 'UTF-8');
    $country = htmlspecialchars((string) ($row['university_country'] ?? ''), ENT_QUOTES, 'UTF-8');
    $lvl = htmlspecialchars((string) ($row['program_level_abbr'] ?? $row['program_level'] ?? ''), ENT_QUOTES, 'UTF-8');
    $prog = htmlspecialchars((string) ($row['program'] ?? ''), ENT_QUOTES, 'UTF-8');

    try {
        /** @var PHPMailer $mail */
        $mail = app_mailer();
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->setFrom(PCVC_COMPANY_SUPPORT_EMAIL, PCVC_COMPANY_DISPLAY_NAME);
        $mail->clearReplyTos();
        $mail->addReplyTo(PCVC_COMPANY_SUPPORT_EMAIL, PCVC_COMPANY_DISPLAY_NAME);
        $mail->addAddress($email, $name);
        $mail->Subject = PCVC_COMPANY_DISPLAY_NAME . ' — Study choice updated (application #' . $applicationId . ')';
        $mail->Body = '
<div style="font-family:Arial,sans-serif;line-height:1.55;color:#111;max-width:640px">
  <p>Hello <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
  <p>Your application has been updated with an additional study choice:</p>
  <table style="border-collapse:collapse;width:100%;margin:14px 0;font-size:14px">
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;width:140px">Region</th><td style="padding:8px;border:1px solid #e5e7eb">' . $region . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">University</th><td style="padding:8px;border:1px solid #e5e7eb">' . $uni . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">Country</th><td style="padding:8px;border:1px solid #e5e7eb">' . ($country !== '' ? $country : '—') . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">Level</th><td style="padding:8px;border:1px solid #e5e7eb">' . $lvl . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">Program</th><td style="padding:8px;border:1px solid #e5e7eb">' . $prog . '</td></tr>
  </table>
  <p style="color:#6b7280;font-size:13px">If you did not expect this message, please contact us at '
            . htmlspecialchars(PCVC_COMPANY_SUPPORT_EMAIL, ENT_QUOTES, 'UTF-8') . '.</p>
</div>';

        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

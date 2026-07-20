<?php
/**
 * Related university / program suggestions for applications.
 * When a student applies to a university that has admins in charge,
 * find similar programs at other assigned universities, queue them for
 * approval, and email those admins.
 */
declare(strict_types=1);

require_once __DIR__ . '/university_admins_schema.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../includes/company_branding.php';

use PHPMailer\PHPMailer\PHPMailer;

function pcvc_ensure_study_choice_suggestions_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    pcvc_ensure_university_admins_schema($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS `application_study_choice_suggestions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `application_id` INT(11) NOT NULL,
            `source_university_id` INT(11) NOT NULL,
            `source_program_id` INT(11) NOT NULL,
            `suggested_region_id` INT(11) NOT NULL,
            `suggested_university_id` INT(11) NOT NULL,
            `suggested_level_id` INT(11) NOT NULL,
            `suggested_program_id` INT(11) NOT NULL,
            `match_score` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `match_reason` VARCHAR(255) NOT NULL DEFAULT '',
            `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `notified_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `decided_at` DATETIME DEFAULT NULL,
            `decided_by_admin_id` INT(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_app_suggested_program` (`application_id`, `suggested_university_id`, `suggested_program_id`),
            KEY `idx_sugg_app_status` (`application_id`, `status`),
            KEY `idx_sugg_university` (`suggested_university_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * @return list<string>
 */
function pcvc_program_name_tokens(string $name): array
{
    $name = mb_strtolower(trim($name));
    $name = str_replace(['–', '—', '−', '/', '-', ',', '(', ')', '.', ':', ';', '|'], ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    $stop = [
        'a', 'an', 'the', 'of', 'and', 'or', 'in', 'on', 'for', 'with', 'to', 'by',
        'optional', 'coop', 'co', 'op', 'fall', 'spring', 'summer', 'winter',
        'bachelor', 'bachelors', 'master', 'masters', 'diploma', 'certificate',
        'cert', 'pg', 'msc', 'bsc', 'ba', 'ma', 'mba', 'phd', 'science', 'arts',
        'program', 'programme', 'degree', 'studies',
    ];

    $tokens = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if (strlen($t) < 3) {
            continue;
        }
        if (in_array($t, $stop, true)) {
            continue;
        }
        $out[] = $t;
    }
    return array_values(array_unique($out));
}

function pcvc_program_similarity(string $a, string $b): float
{
    $na = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/iu', ' ', $a) ?? $a));
    $nb = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/iu', ' ', $b) ?? $b));
    $na = preg_replace('/\s+/u', ' ', $na) ?? $na;
    $nb = preg_replace('/\s+/u', ' ', $nb) ?? $nb;

    if ($na === '' || $nb === '') {
        return 0.0;
    }
    if ($na === $nb) {
        return 100.0;
    }
    if (str_contains($na, $nb) || str_contains($nb, $na)) {
        return 92.0;
    }

    $ta = pcvc_program_name_tokens($a);
    $tb = pcvc_program_name_tokens($b);
    if ($ta === [] || $tb === []) {
        similar_text($na, $nb, $pct);
        return (float) $pct;
    }

    $inter = array_intersect($ta, $tb);
    $union = array_unique(array_merge($ta, $tb));
    $jaccard = count($union) > 0 ? (count($inter) / count($union)) * 100.0 : 0.0;

    similar_text($na, $nb, $pct);
    return max($jaccard, (float) $pct);
}

/**
 * Find related programs at other universities (assigned and unassigned).
 *
 * @param list<int> $excludeUniversityIds
 * @return list<array<string,mixed>>
 */
function pcvc_find_related_programs_for_choice(
    mysqli $conn,
    int $sourceUniversityId,
    int $sourceProgramId,
    int $sourceLevelId,
    string $sourceProgramName,
    array $excludeUniversityIds,
    float $minScore = 68.0,
    int $limit = 40
): array {
    pcvc_ensure_university_admins_schema($conn);

    $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeUniversityIds))));
    if ($sourceUniversityId > 0) {
        $exclude[] = $sourceUniversityId;
    }
    $exclude = array_values(array_unique($exclude));

    $excludeSql = '';
    if ($exclude !== []) {
        $excludeSql = 'AND p.university_id NOT IN (' . implode(',', $exclude) . ')';
    }

    // All remaining universities (with or without an admin in charge)
    $sql = "
        SELECT
            p.id AS program_id,
            p.program_name,
            p.university_id,
            p.program_level_id,
            u.name AS university_name,
            u.region_id,
            pl.name AS level_name,
            pl.abbreviation AS level_abbr,
            (
                SELECT COUNT(*)
                FROM university_admins ua
                WHERE ua.university_id = u.id
            ) AS admin_count
        FROM programs p
        INNER JOIN universities u ON u.id = p.university_id
        INNER JOIN program_levels pl ON pl.id = p.program_level_id
        WHERE p.is_active = 1
          {$excludeSql}
    ";

    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $matches = [];
    while ($row = $res->fetch_assoc()) {
        $score = pcvc_program_similarity($sourceProgramName, (string) $row['program_name']);
        $sameLevel = ((int) $row['program_level_id'] === $sourceLevelId);
        if ($sameLevel) {
            $score = min(100.0, $score + 8.0);
        }
        if ($score < $minScore) {
            continue;
        }

        $hasAdmin = (int) ($row['admin_count'] ?? 0) > 0;
        $reason = $score >= 99.0
            ? 'Exact / near-exact program match'
            : ($score >= 90.0 ? 'Very similar program name' : 'Related program keywords');
        if ($sameLevel) {
            $reason .= ' (same level)';
        }
        if (!$hasAdmin) {
            $reason .= ' — unassigned university';
        }

        $matches[] = [
            'source_university_id' => $sourceUniversityId,
            'source_program_id' => $sourceProgramId,
            'suggested_region_id' => (int) $row['region_id'],
            'suggested_university_id' => (int) $row['university_id'],
            'suggested_level_id' => (int) $row['program_level_id'],
            'suggested_program_id' => (int) $row['program_id'],
            'suggested_university_name' => (string) $row['university_name'],
            'suggested_program_name' => (string) $row['program_name'],
            'suggested_level_name' => (string) ($row['level_abbr'] ?: $row['level_name']),
            'match_score' => round($score, 2),
            'match_reason' => $reason,
            'has_university_admin' => $hasAdmin,
        ];
    }

    usort($matches, static fn ($a, $b) => $b['match_score'] <=> $a['match_score']);

    // Keep best program per suggested university
    $byUni = [];
    foreach ($matches as $m) {
        $uid = (int) $m['suggested_university_id'];
        if (!isset($byUni[$uid])) {
            $byUni[$uid] = $m;
        }
    }

    return array_slice(array_values($byUni), 0, $limit);
}

/**
 * @return list<array{id:int,full_name:string,email:string}>
 */
function pcvc_university_admins_list(mysqli $conn, int $universityId): array
{
    if ($universityId <= 0) {
        return [];
    }
    pcvc_ensure_university_admins_schema($conn);

    $stmt = $conn->prepare(
        "SELECT a.id, a.full_name, a.email
         FROM university_admins ua
         INNER JOIN admins a ON a.id = ua.admin_id
         WHERE ua.university_id = ?
           AND a.email IS NOT NULL
           AND TRIM(a.email) <> ''"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $universityId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows ?: [] as $r) {
        $email = trim((string) ($r['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[] = [
            'id' => (int) $r['id'],
            'full_name' => trim((string) ($r['full_name'] ?? '')) ?: $email,
            'email' => $email,
        ];
    }
    return $out;
}

/**
 * Application owner / assignee used when a suggested university has no admin in charge.
 *
 * @return array{id:int,full_name:string,email:string}|null
 */
function pcvc_application_assignee_admin(mysqli $conn, int $applicationId): ?array
{
    if ($applicationId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT a.id, a.full_name, a.email
         FROM student_applications sa
         INNER JOIN admins a ON a.id = sa.assigned_to_admin_id
         WHERE sa.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'full_name' => trim((string) ($row['full_name'] ?? '')) ?: $email,
        'email' => $email,
    ];
}

/**
 * Prefer university admins; fall back to application assignee for unassigned universities.
 *
 * @return list<array{id:int,full_name:string,email:string,is_fallback?:bool}>
 */
function pcvc_related_suggestion_notify_recipients(
    mysqli $conn,
    int $applicationId,
    int $suggestedUniversityId
): array {
    $admins = pcvc_university_admins_list($conn, $suggestedUniversityId);
    if ($admins !== []) {
        return $admins;
    }
    $assignee = pcvc_application_assignee_admin($conn, $applicationId);
    if ($assignee === null) {
        return [];
    }
    $assignee['is_fallback'] = true;
    return [$assignee];
}

/**
 * Persist pending suggestions (idempotent).
 *
 * @param list<array<string,mixed>> $matches
 * @return int inserted count
 */
function pcvc_store_study_choice_suggestions(mysqli $conn, int $applicationId, array $matches): int
{
    if ($applicationId <= 0 || $matches === []) {
        return 0;
    }
    pcvc_ensure_study_choice_suggestions_schema($conn);

    $stmt = $conn->prepare(
        "INSERT INTO application_study_choice_suggestions
            (application_id, source_university_id, source_program_id,
             suggested_region_id, suggested_university_id, suggested_level_id, suggested_program_id,
             match_score, match_reason, status)
         VALUES (?,?,?,?,?,?,?,?,?,'pending')
         ON DUPLICATE KEY UPDATE
            match_score = GREATEST(match_score, VALUES(match_score)),
            match_reason = VALUES(match_reason)"
    );
    if (!$stmt) {
        return 0;
    }

    $inserted = 0;
    foreach ($matches as $m) {
        $srcU = (int) ($m['source_university_id'] ?? 0);
        $srcP = (int) ($m['source_program_id'] ?? 0);
        $reg = (int) ($m['suggested_region_id'] ?? 0);
        $uni = (int) ($m['suggested_university_id'] ?? 0);
        $lvl = (int) ($m['suggested_level_id'] ?? 0);
        $prog = (int) ($m['suggested_program_id'] ?? 0);
        $score = (float) ($m['match_score'] ?? 0);
        $reason = substr((string) ($m['match_reason'] ?? ''), 0, 255);
        if ($reg <= 0 || $uni <= 0 || $lvl <= 0 || $prog <= 0) {
            continue;
        }
        $stmt->bind_param(
            'iiiiiiids',
            $applicationId,
            $srcU,
            $srcP,
            $reg,
            $uni,
            $lvl,
            $prog,
            $score,
            $reason
        );
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $inserted++;
        }
    }
    $stmt->close();
    return $inserted;
}

function pcvc_notify_admin_related_program_suggestion(
    mysqli $conn,
    int $applicationId,
    array $admin,
    array $student,
    array $sourceChoice,
    array $suggestion
): bool {
    $email = trim((string) ($admin['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $adminName = htmlspecialchars((string) ($admin['full_name'] ?? 'Colleague'), ENT_QUOTES, 'UTF-8');
    $studentName = htmlspecialchars((string) ($student['name'] ?? 'Applicant'), ENT_QUOTES, 'UTF-8');
    $studentEmail = htmlspecialchars((string) ($student['email'] ?? ''), ENT_QUOTES, 'UTF-8');

    $srcUni = htmlspecialchars((string) ($sourceChoice['university'] ?? ''), ENT_QUOTES, 'UTF-8');
    $srcProg = htmlspecialchars((string) ($sourceChoice['program'] ?? ''), ENT_QUOTES, 'UTF-8');
    $sugUni = htmlspecialchars((string) ($suggestion['suggested_university_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $sugProg = htmlspecialchars((string) ($suggestion['suggested_program_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $sugLvl = htmlspecialchars((string) ($suggestion['suggested_level_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $reason = htmlspecialchars((string) ($suggestion['match_reason'] ?? ''), ENT_QUOTES, 'UTF-8');
    $score = htmlspecialchars((string) ($suggestion['match_score'] ?? ''), ENT_QUOTES, 'UTF-8');

    $isFallback = !empty($admin['is_fallback']);
    $intro = $isFallback
        ? 'A student applied to an assigned partner university. A related program was found at an <strong>unassigned</strong> university (no admin in charge yet). It was added to the <strong>approval queue</strong> for your review.'
        : 'A student applied to an assigned partner university. A related program was found at a university you are in charge of, and it was added to the <strong>approval queue</strong> (not yet a study choice).';
    $suggestLabel = $isFallback ? 'Suggested university' : 'Suggested for you';

    try {
        /** @var PHPMailer $mail */
        $mail = app_mailer();
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->setFrom(PCVC_COMPANY_SUPPORT_EMAIL, PCVC_COMPANY_DISPLAY_NAME);
        $mail->clearReplyTos();
        $mail->addReplyTo(PCVC_COMPANY_SUPPORT_EMAIL, PCVC_COMPANY_DISPLAY_NAME);
        $mail->addAddress($email, (string) ($admin['full_name'] ?? ''));
        $mail->Subject = PCVC_COMPANY_DISPLAY_NAME . ' — Related program match for application #' . $applicationId;
        $mail->Body = '
<div style="font-family:Arial,sans-serif;line-height:1.55;color:#111;max-width:640px">
  <p>Hello <strong>' . $adminName . '</strong>,</p>
  <p>' . $intro . '</p>
  <table style="border-collapse:collapse;width:100%;margin:14px 0;font-size:14px">
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;width:160px">Student</th><td style="padding:8px;border:1px solid #e5e7eb">' . $studentName . '<br><span style="color:#6b7280">' . $studentEmail . '</span></td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">Applied to</th><td style="padding:8px;border:1px solid #e5e7eb">' . $srcUni . '<br>' . $srcProg . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">' . $suggestLabel . '</th><td style="padding:8px;border:1px solid #e5e7eb"><strong>' . $sugUni . '</strong><br>' . $sugLvl . ' — ' . $sugProg . '</td></tr>
    <tr><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb">Match</th><td style="padding:8px;border:1px solid #e5e7eb">' . $reason . ' (score ' . $score . ')</td></tr>
  </table>
  <p>Open the Student Application Report and approve the suggestion under <em>Related programs pending approval</em> to add it to the student’s study choices.</p>
  <p style="color:#6b7280;font-size:13px">Application #' . (int) $applicationId . '</p>
</div>';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Main entry: scan study choices of an application, queue related suggestions, email admins.
 *
 * @return array{suggestions:int,emails:int,triggered:bool}
 */
function pcvc_process_related_university_suggestions(mysqli $conn, int $applicationId): array
{
    $result = ['suggestions' => 0, 'emails' => 0, 'triggered' => false];
    if ($applicationId <= 0) {
        return $result;
    }

    pcvc_ensure_study_choice_suggestions_schema($conn);
    pcvc_ensure_university_admins_schema($conn);

    $stmt = $conn->prepare(
        "SELECT first_name, last_name, email FROM student_applications WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return $result;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$app) {
        return $result;
    }

    $student = [
        'name' => trim((string) ($app['first_name'] ?? '') . ' ' . (string) ($app['last_name'] ?? '')),
        'email' => trim((string) ($app['email'] ?? '')),
    ];
    if ($student['name'] === '') {
        $student['name'] = 'Applicant';
    }

    $stmt = $conn->prepare(
        "SELECT
            ascx.university_id,
            ascx.program_id,
            ascx.program_level_id,
            ascx.region_id,
            u.name AS university,
            p.program_name AS program,
            pl.abbreviation AS level_abbr,
            pl.name AS level_name,
            (SELECT COUNT(*) FROM university_admins ua WHERE ua.university_id = ascx.university_id) AS admin_count
         FROM application_study_choices ascx
         JOIN universities u ON u.id = ascx.university_id
         JOIN programs p ON p.id = ascx.program_id
         JOIN program_levels pl ON pl.id = ascx.program_level_id
         WHERE ascx.application_id = ?"
    );
    if (!$stmt) {
        return $result;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $choices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$choices) {
        return $result;
    }

    $hasAssignedUni = false;
    foreach ($choices as $c) {
        if ((int) ($c['admin_count'] ?? 0) > 0) {
            $hasAssignedUni = true;
            break;
        }
    }
    if (!$hasAssignedUni) {
        return $result;
    }
    $result['triggered'] = true;

    $chosenUniIds = array_map(static fn ($c) => (int) $c['university_id'], $choices);
    $allMatches = [];

    foreach ($choices as $c) {
        if ((int) ($c['admin_count'] ?? 0) <= 0) {
            // Only expand from universities that are part of the assigned network
            continue;
        }
        $found = pcvc_find_related_programs_for_choice(
            $conn,
            (int) $c['university_id'],
            (int) $c['program_id'],
            (int) $c['program_level_id'],
            (string) $c['program'],
            $chosenUniIds
        );
        foreach ($found as $m) {
            $m['_source_choice'] = [
                'university' => (string) $c['university'],
                'program' => (string) $c['program'],
                'level' => (string) ($c['level_abbr'] ?: $c['level_name']),
            ];
            $key = $m['suggested_university_id'] . ':' . $m['suggested_program_id'];
            if (!isset($allMatches[$key]) || $allMatches[$key]['match_score'] < $m['match_score']) {
                $allMatches[$key] = $m;
            }
        }
    }

    if ($allMatches === []) {
        return $result;
    }

    $result['suggestions'] = pcvc_store_study_choice_suggestions($conn, $applicationId, array_values($allMatches));

    // Email admins for newly pending suggestions (not yet notified)
    $stmt = $conn->prepare(
        "SELECT s.*,
                su.name AS suggested_university_name,
                sp.program_name AS suggested_program_name,
                pl.abbreviation AS suggested_level_abbr,
                pl.name AS suggested_level_name,
                ou.name AS source_university_name,
                op.program_name AS source_program_name
         FROM application_study_choice_suggestions s
         JOIN universities su ON su.id = s.suggested_university_id
         JOIN programs sp ON sp.id = s.suggested_program_id
         JOIN program_levels pl ON pl.id = s.suggested_level_id
         JOIN universities ou ON ou.id = s.source_university_id
         JOIN programs op ON op.id = s.source_program_id
         WHERE s.application_id = ?
           AND s.status = 'pending'
           AND s.notified_at IS NULL"
    );
    if (!$stmt) {
        return $result;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $pendingNotify = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $mark = $conn->prepare(
        "UPDATE application_study_choice_suggestions SET notified_at = NOW() WHERE id = ?"
    );

    foreach ($pendingNotify ?: [] as $row) {
        $recipients = pcvc_related_suggestion_notify_recipients(
            $conn,
            $applicationId,
            (int) $row['suggested_university_id']
        );
        $suggestion = [
            'suggested_university_name' => (string) $row['suggested_university_name'],
            'suggested_program_name' => (string) $row['suggested_program_name'],
            'suggested_level_name' => (string) ($row['suggested_level_abbr'] ?: $row['suggested_level_name']),
            'match_reason' => (string) $row['match_reason'],
            'match_score' => (string) $row['match_score'],
        ];
        $sourceChoice = [
            'university' => (string) $row['source_university_name'],
            'program' => (string) $row['source_program_name'],
        ];
        foreach ($recipients as $admin) {
            if (pcvc_notify_admin_related_program_suggestion(
                $conn,
                $applicationId,
                $admin,
                $student,
                $sourceChoice,
                $suggestion
            )) {
                $result['emails']++;
            }
        }
        // Mark notified after attempt so unassigned rows are not retried forever;
        // still mark when nobody could be emailed (queued for approval only).
        if ($mark) {
            $sid = (int) $row['id'];
            $mark->bind_param('i', $sid);
            $mark->execute();
        }
    }
    $mark?->close();

    return $result;
}

/**
 * @return list<array<string,mixed>>
 */
function pcvc_fetch_study_choice_suggestions(mysqli $conn, int $applicationId, string $status = 'pending'): array
{
    if ($applicationId <= 0) {
        return [];
    }
    pcvc_ensure_study_choice_suggestions_schema($conn);

    $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';
    $sql = "
        SELECT
            s.id,
            s.status,
            s.match_score,
            s.match_reason,
            s.suggested_region_id AS region_id,
            s.suggested_university_id AS university_id,
            s.suggested_level_id AS program_level_id,
            s.suggested_program_id AS program_id,
            r.name AS region,
            u.name AS university,
            c.name AS university_country,
            pl.name AS program_level,
            pl.abbreviation AS program_level_abbr,
            p.program_name AS program,
            ou.name AS source_university,
            op.program_name AS source_program,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ', ') AS admins_in_charge
        FROM application_study_choice_suggestions s
        JOIN regions r ON r.id = s.suggested_region_id
        JOIN universities u ON u.id = s.suggested_university_id
        JOIN program_levels pl ON pl.id = s.suggested_level_id
        JOIN programs p ON p.id = s.suggested_program_id
        LEFT JOIN countries c ON c.id = u.country_id
        JOIN universities ou ON ou.id = s.source_university_id
        JOIN programs op ON op.id = s.source_program_id
        LEFT JOIN university_admins ua ON ua.university_id = s.suggested_university_id
        LEFT JOIN admins a ON a.id = ua.admin_id
        WHERE s.application_id = ?
    ";
    if ($status !== 'all') {
        $sql .= ' AND s.status = ?';
    }
    $sql .= ' GROUP BY s.id ORDER BY s.match_score DESC, s.id ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($status !== 'all') {
        $stmt->bind_param('is', $applicationId, $status);
    } else {
        $stmt->bind_param('i', $applicationId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

/**
 * Approve a pending suggestion → insert study choice + optional student notify.
 *
 * @return array{ok:bool,msg:string,study_choices?:list}
 */
function pcvc_approve_study_choice_suggestion(
    mysqli $conn,
    int $suggestionId,
    int $adminId,
    bool $notifyStudent = true
): array {
    require_once __DIR__ . '/study_choice_admin_actions.php';
    pcvc_ensure_study_choice_suggestions_schema($conn);

    $stmt = $conn->prepare(
        "SELECT * FROM application_study_choice_suggestions WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'msg' => 'Database error'];
    }
    $stmt->bind_param('i', $suggestionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'msg' => 'Suggestion not found'];
    }
    if (($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'msg' => 'Suggestion is already ' . ($row['status'] ?? 'closed')];
    }

    $applicationId = (int) $row['application_id'];
    $regionId = (int) $row['suggested_region_id'];
    $universityId = (int) $row['suggested_university_id'];
    $levelId = (int) $row['suggested_level_id'];
    $programId = (int) $row['suggested_program_id'];

    $relErr = pcvc_validate_study_choice_relations($conn, $regionId, $universityId, $levelId, $programId);
    if ($relErr !== null) {
        return ['ok' => false, 'msg' => $relErr];
    }

    $ins = pcvc_try_insert_application_study_choice(
        $conn,
        $applicationId,
        $regionId,
        $universityId,
        $levelId,
        $programId
    );
    if (!$ins['inserted'] && !$ins['duplicate']) {
        return ['ok' => false, 'msg' => $ins['error'] ?: 'Could not add study choice'];
    }

    $upd = $conn->prepare(
        "UPDATE application_study_choice_suggestions
         SET status = 'approved', decided_at = NOW(), decided_by_admin_id = ?
         WHERE id = ?"
    );
    if ($upd) {
        $upd->bind_param('ii', $adminId, $suggestionId);
        $upd->execute();
        $upd->close();
    }

    if ($ins['inserted'] && $notifyStudent) {
        pcvc_notify_student_study_choice_added(
            $conn,
            $applicationId,
            $regionId,
            $universityId,
            $levelId,
            $programId
        );
    }

    // Create jobs for assignee if any
    $assigneeId = 0;
    $st = $conn->prepare('SELECT assigned_to_admin_id FROM student_applications WHERE id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('i', $applicationId);
        $st->execute();
        $ar = $st->get_result()->fetch_assoc();
        $st->close();
        if ($ar && $ar['assigned_to_admin_id']) {
            $assigneeId = (int) $ar['assigned_to_admin_id'];
        }
    }
    if ($assigneeId > 0 && $ins['inserted']) {
        pcvc_ensure_assignment_jobs_for_application($conn, $applicationId, $assigneeId);
    }

    return [
        'ok' => true,
        'msg' => $ins['duplicate'] ? 'Already in study choices; marked approved.' : 'Study choice added.',
        'study_choices' => pcvc_fetch_study_choices_for_admin_view($conn, $applicationId),
        'suggestions' => pcvc_fetch_study_choice_suggestions($conn, $applicationId, 'pending'),
    ];
}

/**
 * @return array{ok:bool,msg:string}
 */
function pcvc_reject_study_choice_suggestion(mysqli $conn, int $suggestionId, int $adminId): array
{
    pcvc_ensure_study_choice_suggestions_schema($conn);

    $stmt = $conn->prepare(
        "UPDATE application_study_choice_suggestions
         SET status = 'rejected', decided_at = NOW(), decided_by_admin_id = ?
         WHERE id = ? AND status = 'pending'"
    );
    if (!$stmt) {
        return ['ok' => false, 'msg' => 'Database error'];
    }
    $stmt->bind_param('ii', $adminId, $suggestionId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return [
        'ok' => $ok,
        'msg' => $ok ? 'Suggestion rejected.' : 'Suggestion not found or already decided.',
    ];
}

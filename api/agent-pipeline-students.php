<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/application_filters.php';

require_once __DIR__ . '/../helpers/company_view_as.php';
$viewer = pcvc_application_report_viewer($conn);
if ($viewer['id'] < 1) {
    jsonResponse('Please sign in.', false, 401);
}

$stage = strtolower(trim((string) ($_GET['pipeline'] ?? 'allocated')));
$expr = pcvc_agent_pipeline_expr($stage);
if ($expr === '') {
    jsonResponse('Unknown list.', false, 400);
}

$pcvcCoScope = pcvc_company_list_scope($conn);
$isSuper = pcvc_current_user_is_superadmin($conn) && !$pcvcCoScope;
$requested = strtolower(trim((string) ($_GET['agent_email'] ?? '')));
$email = ($isSuper && $requested !== '') ? $requested : strtolower(trim((string) $viewer['email']));
if ($email === '') {
    jsonResponse([], true, 200, ['count' => 0, 'pipeline' => $stage]);
}
if ($pcvcCoScope) {
    $memberEmails = [];
    $emRes = $conn->query(
        'SELECT DISTINCT LOWER(TRIM(email)) AS em FROM admins WHERE id IN ('
        . $pcvcCoScope['member_sql']
        . ") AND email IS NOT NULL AND TRIM(email) <> ''"
    );
    if ($emRes) {
        while ($er = $emRes->fetch_assoc()) {
            $em = strtolower(trim((string) ($er['em'] ?? '')));
            if ($em !== '') {
                $memberEmails[$em] = true;
            }
        }
    }
    if ($requested !== '' && !isset($memberEmails[$requested])) {
        jsonResponse([], true, 200, ['count' => 0, 'pipeline' => $stage]);
    }
    if ($requested !== '') {
        $email = $requested;
    } elseif (!isset($memberEmails[$email])) {
        jsonResponse([], true, 200, ['count' => 0, 'pipeline' => $stage]);
    }
}

$labels = pcvc_application_status_labels();
$eff = pcvc_sql_max_effective_status('sa');
$sql = "
    SELECT
        sa.id,
        MAX(sa.first_name) AS first_name,
        MAX(sa.last_name) AS last_name,
        MAX(sa.email) AS email,
        MAX(sa.created_at) AS created_at,
        {$eff} AS effective_status,
        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ' · ') AS universities,
        GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ' · ') AS regions,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ' · ') AS countries
    FROM student_applications sa
    LEFT JOIN application_study_choices ascx ON ascx.application_id = sa.id
    LEFT JOIN universities u ON u.id = ascx.university_id
    LEFT JOIN regions r ON r.id = ascx.region_id
    LEFT JOIN countries c ON c.id = u.country_id
    WHERE LOWER(TRIM(COALESCE(sa.agent_email, ''))) = ?
      AND ({$expr})
    GROUP BY sa.id
    ORDER BY MAX(sa.created_at) DESC
    LIMIT 500
";

$students = [];
$stmt = $conn->prepare($sql);
if (!$stmt) {
    jsonResponse('Could not load students.', false, 500);
}
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
    $statusKey = (string) ($row['effective_status'] ?? '');
    $study = array_values(array_filter([
        trim((string) ($row['universities'] ?? '')),
        trim((string) ($row['regions'] ?? '')),
        trim((string) ($row['countries'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));
    $created = (string) ($row['created_at'] ?? '');
    $students[] = [
        'name' => $name !== '' ? $name : '—',
        'email' => trim((string) ($row['email'] ?? '')),
        'study' => $study !== [] ? implode(' · ', $study) : '—',
        'status' => $statusKey !== '' ? ($labels[$statusKey] ?? $statusKey) : 'Allocated',
        'applied' => strlen($created) >= 10 ? substr($created, 0, 10) : '—',
    ];
}
$stmt->close();

$cyprusFile = __DIR__ . '/../database.php';
if (is_file($cyprusFile)) {
    require_once $cyprusFile;
}
if (isset($conn2) && $conn2 instanceof mysqli && $conn2->connect_errno === 0) {
    $cyprusStage = match ($stage) {
        'allocated' => '1 = 1',
        'submitted' => "status = 'verified'",
        'admit' => 'IFNULL(is_admitted, 0) = 1',
        default => '1 = 0',
    };
    $cyprusStatus = match ($stage) {
        'submitted' => 'Submitted',
        'admit' => 'Admitted',
        default => 'Allocated',
    };
    $cSql = "SELECT id, name, email, status, created_at
        FROM applications
        WHERE LOWER(TRIM(COALESCE(agent_email, ''))) = ?
          AND ({$cyprusStage})
        ORDER BY created_at DESC
        LIMIT 500";
    $cStmt = $conn2->prepare($cSql);
    if ($cStmt) {
        $cStmt->bind_param('s', $email);
        if ($cStmt->execute()) {
            $cRes = $cStmt->get_result();
            while ($row = $cRes->fetch_assoc()) {
                $created = (string) ($row['created_at'] ?? '');
                $status = $cyprusStatus;
                if ($stage === 'allocated') {
                    $raw = strtolower(trim((string) ($row['status'] ?? '')));
                    $status = $raw === 'verified' ? 'Submitted' : ($raw !== '' ? ucfirst($raw) : 'Allocated');
                }
                $students[] = [
                    'name' => trim((string) ($row['name'] ?? '')) !== '' ? trim((string) $row['name']) : '—',
                    'email' => trim((string) ($row['email'] ?? '')),
                    'study' => 'Cyprus file',
                    'status' => $status,
                    'applied' => strlen($created) >= 10 ? substr($created, 0, 10) : '—',
                ];
            }
        }
        $cStmt->close();
    }
}

jsonResponse($students, true, 200, ['count' => count($students), 'pipeline' => $stage]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/staff_self_register.php';
require_once __DIR__ . '/self_registration.php';

/**
 * @return array{applications:int,assigned:int,recruited:int,self_regs:int,admitted:int}
 */
function pcvc_company_activity_counts(mysqli $conn, int $adminId, string $email): array
{
    $counts = [
        'applications' => 0,
        'assigned' => 0,
        'recruited' => 0,
        'self_regs' => 0,
        'admitted' => 0,
    ];
    $adminId = max(0, $adminId);
    $email = strtolower(trim($email));
    if ($adminId < 1 && $email === '') {
        return $counts;
    }

    $hasAssign = false;
    $chk = @$conn->query("SHOW COLUMNS FROM student_applications LIKE 'assigned_to_admin_id'");
    if ($chk instanceof mysqli_result) {
        $hasAssign = $chk->num_rows > 0;
        $chk->free();
    }

    $parts = [];
    $types = '';
    $params = [];
    if ($hasAssign && $adminId > 0) {
        $parts[] = 'assigned_to_admin_id = ?';
        $types .= 'i';
        $params[] = $adminId;
    }
    if ($email !== '') {
        $parts[] = "LOWER(TRIM(COALESCE(agent_email, ''))) = ?";
        $types .= 's';
        $params[] = $email;
    }
    if ($parts === []) {
        return $counts;
    }

    $where = '(' . implode(' OR ', $parts) . ')';
    $colHave = [];
    $cols = @$conn->query('SHOW COLUMNS FROM student_applications');
    if ($cols instanceof mysqli_result) {
        while ($c = $cols->fetch_assoc()) {
            $colHave[strtolower((string) ($c['Field'] ?? ''))] = true;
        }
        $cols->free();
    }
    $admitBits = [];
    foreach (['is_admitted', 'admit', 'enrolled'] as $flag) {
        if (isset($colHave[$flag])) {
            $admitBits[] = "IFNULL(`{$flag}`, 0) = 1";
        }
    }
    $admitSel = $admitBits === []
        ? '0 AS admitted'
        : ('SUM(' . implode(' OR ', $admitBits) . ') AS admitted');
    $assignSel = ($hasAssign && $adminId > 0)
        ? 'SUM(assigned_to_admin_id = ?) AS assigned'
        : '0 AS assigned';
    $recruitSel = $email !== ''
        ? "SUM(LOWER(TRIM(COALESCE(agent_email, ''))) = ?) AS recruited"
        : '0 AS recruited';

    $sql = "SELECT COUNT(*) AS applications, {$assignSel}, {$recruitSel}, {$admitSel}
            FROM student_applications WHERE {$where}";
    $types2 = $types;
    $params2 = $params;
    if ($hasAssign && $adminId > 0) {
        $types2 .= 'i';
        $params2[] = $adminId;
    }
    if ($email !== '') {
        $types2 .= 's';
        $params2[] = $email;
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind = [];
        $bind[] = &$types2;
        foreach ($params2 as $k => $_) {
            $bind[] = &$params2[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $counts['applications'] = (int) ($row['applications'] ?? 0);
        $counts['assigned'] = (int) ($row['assigned'] ?? 0);
        $counts['recruited'] = (int) ($row['recruited'] ?? 0);
        $counts['admitted'] = (int) ($row['admitted'] ?? 0);
    }

    if ($adminId > 0) {
        pcvc_self_reg_ensure_schema($conn);
        $st = $conn->prepare(
            "SELECT COUNT(*) AS c FROM self_registrations
             WHERE status = 'submitted'
               AND (assigned_to_admin_id = ? OR referred_by_admin_id = ?)"
        );
        if ($st) {
            $st->bind_param('ii', $adminId, $adminId);
            $st->execute();
            $counts['self_regs'] = (int) (($st->get_result()->fetch_assoc()['c'] ?? 0));
            $st->close();
        }
    }

    return $counts;
}

/**
 * @return list<array<string,mixed>>
 */
function pcvc_company_applications(mysqli $conn, int $adminId, string $email, int $limit = 100): array
{
    $adminId = max(0, $adminId);
    $email = strtolower(trim($email));
    $limit = max(1, min(300, $limit));
    if ($adminId < 1 && $email === '') {
        return [];
    }

    $hasAssign = false;
    $chk = @$conn->query("SHOW COLUMNS FROM student_applications LIKE 'assigned_to_admin_id'");
    if ($chk instanceof mysqli_result) {
        $hasAssign = $chk->num_rows > 0;
        $chk->free();
    }

    $parts = [];
    $types = '';
    $params = [];
    if ($hasAssign && $adminId > 0) {
        $parts[] = 'sa.assigned_to_admin_id = ?';
        $types .= 'i';
        $params[] = $adminId;
    }
    if ($email !== '') {
        $parts[] = "LOWER(TRIM(COALESCE(sa.agent_email, ''))) = ?";
        $types .= 's';
        $params[] = $email;
    }
    if ($parts === []) {
        return [];
    }

    $assignSel = $hasAssign ? 'sa.assigned_to_admin_id' : 'NULL AS assigned_to_admin_id';
    // Optional columns may be missing on older schemas.
    $have = [];
    $cols = @$conn->query('SHOW COLUMNS FROM student_applications');
    if ($cols instanceof mysqli_result) {
        while ($c = $cols->fetch_assoc()) {
            $have[strtolower((string) ($c['Field'] ?? ''))] = true;
        }
        $cols->free();
    }
    $sel = [
        'sa.id',
        'sa.first_name',
        'sa.last_name',
        isset($have['email']) ? 'sa.email' : "'' AS email",
        isset($have['phone_number']) ? 'sa.phone_number' : "'' AS phone_number",
        isset($have['agent_email']) ? 'sa.agent_email' : "'' AS agent_email",
        $assignSel,
        isset($have['created_at']) ? 'sa.created_at' : 'NULL AS created_at',
        isset($have['destination_country']) ? 'sa.destination_country' : "'' AS destination_country",
        isset($have['preferred_intake']) ? 'sa.preferred_intake' : "'' AS preferred_intake",
    ];
    $sql = 'SELECT ' . implode(', ', $sel)
        . ' FROM student_applications sa WHERE (' . implode(' OR ', $parts) . ')'
        . ' ORDER BY sa.id DESC LIMIT ' . $limit;

    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }
    $bind = [];
    $bind[] = &$types;
    foreach ($params as $k => $_) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        $row['student_name'] = $name !== '' ? $name : 'Student';
        $rel = 'Linked';
        if ($hasAssign && $adminId > 0 && (int) ($row['assigned_to_admin_id'] ?? 0) === $adminId) {
            $rel = 'In charge';
        } elseif ($email !== '' && strtolower(trim((string) ($row['agent_email'] ?? ''))) === $email) {
            $rel = 'Recruited';
        }
        $row['relation'] = $rel;
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function pcvc_company_dashboard_url(int $adminId): string
{
    return 'company-dashboard.php?id=' . max(0, $adminId);
}

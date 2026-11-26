<?php
declare(strict_types=1);

require_once __DIR__ . '/application_filters.php';
require_once __DIR__ . '/role.php';

function pcvc_dash_admin_has_column(mysqli $conn, string $column): bool
{
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
    if ($column === '') {
        return false;
    }
    $found = $conn->query("SHOW COLUMNS FROM admins LIKE '" . $column . "'");
    $yes = $found instanceof mysqli_result && $found->num_rows > 0;
    if ($found instanceof mysqli_result) {
        $found->free();
    }

    return $yes;
}

function pcvc_dash_person_name(array $row): string
{
    $full = trim((string) ($row['full_name'] ?? ''));
    $joined = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
    if ($full !== '') {
        return $full;
    }
    if ($joined !== '') {
        return $joined;
    }
    $email = trim((string) ($row['email'] ?? ''));

    return $email !== '' ? $email : 'Account';
}

/**
 * Folded roster: staff and agents who are in charge of students, and company accounts that were created.
 *
 * @return array{
 *   staff: list<array<string, mixed>>,
 *   agents: list<array<string, mixed>>,
 *   companies: list<array<string, mixed>>,
 *   staff_count: int,
 *   agent_count: int,
 *   company_count: int,
 *   students_in_charge: int,
 *   company_students: int
 * }
 */
/**
 * @param list<int>|null $memberAdminIds When set, only these admin accounts and their students appear.
 */
function pcvc_dashboard_roster(mysqli $conn, ?mysqli $conn2 = null, ?array $memberAdminIds = null): array
{
    $empty = [
        'staff' => [],
        'agents' => [],
        'companies' => [],
        'staff_count' => 0,
        'agent_count' => 0,
        'agent_role_count' => 0,
        'company_count' => 0,
        'students_in_charge' => 0,
        'staff_students' => 0,
        'agent_students' => 0,
        'company_students' => 0,
    ];

    $memberFilter = null;
    if ($memberAdminIds !== null) {
        $memberFilter = [];
        foreach ($memberAdminIds as $mid) {
            $mid = (int) $mid;
            if ($mid > 0) {
                $memberFilter[$mid] = true;
            }
        }
        if ($memberFilter === []) {
            return $empty;
        }
    }

    $select = ['id', 'first_name', 'last_name', 'email', 'role'];
    foreach (['full_name', 'account_type', 'company_name', 'account_status'] as $column) {
        if (pcvc_dash_admin_has_column($conn, $column)) {
            $select[] = $column;
        }
    }
    $adminSql = 'SELECT ' . implode(', ', $select) . ' FROM admins';
    if ($memberFilter !== null) {
        $adminSql .= ' WHERE id IN (' . implode(',', array_map('intval', array_keys($memberFilter))) . ')';
    }
    $adminRes = $conn->query($adminSql);
    if (!$adminRes) {
        return $empty;
    }

    $admins = [];
    $agentRoleCount = 0;
    while ($row = $adminRes->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($id < 1 || $email === '') {
            continue;
        }
        $role = strtolower(trim((string) ($row['role'] ?? '')));
        $accountType = strtolower(trim((string) ($row['account_type'] ?? 'personal')));
        $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
        if ($status === '') {
            $status = 'active';
        }
        $isStaff = pcvc_is_staff_role($role) || pcvc_is_superadmin_role($role) || $role === 'admin';
        if (pcvc_is_agent_role($role)) {
            $agentRoleCount++;
        }
        $admins[$id] = [
            'id' => $id,
            'email' => $email,
            'name' => pcvc_dash_person_name($row),
            'company_name' => trim((string) ($row['company_name'] ?? '')),
            'contact' => pcvc_dash_person_name($row),
            'role' => $role !== '' ? $role : 'account',
            'account_type' => $accountType === 'company' ? 'company' : 'personal',
            'status' => $status,
            'is_staff' => $isStaff,
            'in_charge' => [],
            'recruited' => [],
        ];
    }
    $adminRes->free();

    $byEmail = [];
    foreach ($admins as $id => $admin) {
        $byEmail[$admin['email']] = $id;
    }

    $labels = pcvc_application_status_labels();
    $hasAssign = false;
    $assignCol = $conn->query("SHOW COLUMNS FROM student_applications LIKE 'assigned_to_admin_id'");
    if ($assignCol instanceof mysqli_result) {
        $hasAssign = $assignCol->num_rows > 0;
        $assignCol->free();
    }
    $flagColumns = [];
    $flagRes = $conn->query('SHOW COLUMNS FROM student_applications');
    if ($flagRes instanceof mysqli_result) {
        $have = [];
        while ($flagRow = $flagRes->fetch_assoc()) {
            $have[(string) ($flagRow['Field'] ?? '')] = true;
        }
        $flagRes->free();
        foreach (pcvc_application_status_priority() as $flag) {
            if (isset($have[$flag])) {
                $flagColumns[] = "WHEN IFNULL(sa.`{$flag}`, 0) = 1 THEN '{$flag}'";
            }
        }
    }
    $statusSql = $flagColumns === [] ? 'NULL' : ('CASE ' . implode(' ', $flagColumns) . ' ELSE NULL END');
    $assignSql = $hasAssign ? 'sa.assigned_to_admin_id' : 'NULL AS assigned_to_admin_id';
    $appWhere = '';
    if ($memberFilter !== null) {
        $inList = implode(',', array_map('intval', array_keys($memberFilter)));
        $emailParts = [];
        foreach ($admins as $admin) {
            $em = strtolower(trim((string) ($admin['email'] ?? '')));
            if ($em !== '') {
                $emailParts[$em] = "'" . $conn->real_escape_string($em) . "'";
            }
        }
        $ors = ["sa.assigned_to_admin_id IN ({$inList})"];
        if ($emailParts !== []) {
            $ors[] = 'LOWER(TRIM(COALESCE(sa.agent_email, \'\'))) IN (' . implode(',', array_values($emailParts)) . ')';
        }
        $appWhere = ' WHERE (' . implode(' OR ', $ors) . ')';
    }
    $appSql = "
        SELECT sa.id, sa.first_name, sa.last_name, sa.email, {$assignSql},
               LOWER(TRIM(COALESCE(sa.agent_email, ''))) AS agent_email,
               {$statusSql} AS effective_status
        FROM student_applications sa
        {$appWhere}
        ORDER BY sa.id DESC
    ";
    $appRes = $conn->query($appSql);
    if ($appRes) {
        while ($row = $appRes->fetch_assoc()) {
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            $student = [
                'name' => $name !== '' ? $name : 'Student',
                'email' => trim((string) ($row['email'] ?? '')),
                'status' => $labels[(string) ($row['effective_status'] ?? '')] ?? 'File opened',
                'source' => '',
            ];
            $assignee = (int) ($row['assigned_to_admin_id'] ?? 0);
            $agentEmail = strtolower(trim((string) ($row['agent_email'] ?? '')));
            $placed = false;
            if ($assignee > 0 && isset($admins[$assignee])) {
                $student['source'] = 'In charge';
                $admins[$assignee]['in_charge'][] = $student;
                $placed = true;
            }
            if ($agentEmail !== '' && isset($byEmail[$agentEmail])) {
                $ownerId = $byEmail[$agentEmail];
                if (!$placed || $ownerId !== $assignee) {
                    $copy = $student;
                    $copy['source'] = $placed ? 'Recruited' : 'Assigned';
                    $admins[$ownerId]['recruited'][] = $copy;
                    $placed = true;
                }
            }
        }
        $appRes->free();
    }

    if ($conn2 instanceof mysqli && $conn2->connect_errno === 0) {
        $cyprus = $conn2->query("
            SELECT name, email, LOWER(TRIM(COALESCE(agent_email, ''))) AS agent_email, status, IFNULL(is_admitted, 0) AS is_admitted
            FROM applications
            WHERE agent_email IS NOT NULL AND TRIM(agent_email) != ''
        ");
        if ($cyprus instanceof mysqli_result) {
            while ($row = $cyprus->fetch_assoc()) {
                $agentEmail = strtolower(trim((string) ($row['agent_email'] ?? '')));
                if ($agentEmail === '' || !isset($byEmail[$agentEmail])) {
                    continue;
                }
                $raw = strtolower(trim((string) ($row['status'] ?? '')));
                $status = ((int) ($row['is_admitted'] ?? 0) === 1) ? 'Admitted' : ($raw === 'verified' ? 'Submitted' : 'Cyprus file');
                $admins[$byEmail[$agentEmail]]['recruited'][] = [
                    'name' => trim((string) ($row['name'] ?? '')) !== '' ? trim((string) $row['name']) : 'Student',
                    'email' => trim((string) ($row['email'] ?? '')),
                    'status' => $status,
                    'source' => '',
                ];
            }
            $cyprus->free();
        }
    }

    $dedupe = static function (array $rows): array {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['name'] ?? '')) . '|' . trim((string) ($row['email'] ?? '')));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'name' => (string) ($row['name'] ?? 'Student'),
                'email' => (string) ($row['email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $out;
    };

    $staff = [];
    $agents = [];
    $companies = [];
    $scopedCompanyRoster = $memberFilter !== null;
    foreach ($admins as $admin) {
        $isCompany = $admin['account_type'] === 'company';
        if ($isCompany && !$scopedCompanyRoster) {
            if ($admin['status'] === 'inactive' && $admin['in_charge'] === [] && $admin['recruited'] === []) {
                continue;
            }
            $admin['students'] = $dedupe(array_merge($admin['recruited'], $admin['in_charge']));
            $admin['kind'] = 'Company';
            $companies[] = $admin;
            continue;
        }
        // HQ board: only people who already have students. Company board: show all linked people.
        if (!$scopedCompanyRoster && $admin['in_charge'] === []) {
            continue;
        }
        $admin['students'] = $dedupe(
            $scopedCompanyRoster
                ? array_merge($admin['in_charge'], $admin['recruited'])
                : $admin['in_charge']
        );
        $isAgentPerson = pcvc_is_agent_role($admin['role']) || $isCompany;
        $isStaffPerson = !$isAgentPerson && ($admin['is_staff'] || $scopedCompanyRoster);
        $admin['kind'] = $isCompany
            ? 'Company'
            : ($isAgentPerson
                ? 'Agent'
                : (pcvc_is_superadmin_role($admin['role'])
                    ? 'Superadmin'
                    : (function_exists('pcvc_admin_role_label') ? pcvc_admin_role_label($admin['role']) : 'Staff')));
        if ($isAgentPerson || $isCompany) {
            $agents[] = $admin;
        } else {
            $staff[] = $admin;
        }
    }

    $byStudents = static function (array $a, array $b): int {
        $ca = count($a['students'] ?? []);
        $cb = count($b['students'] ?? []);
        if ($cb !== $ca) {
            return $cb <=> $ca;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    };
    usort($staff, $byStudents);
    usort($agents, $byStudents);
    usort($companies, $byStudents);

    $studentsInCharge = 0;
    $staffStudents = 0;
    $agentStudents = 0;
    foreach ($staff as $person) {
        $n = count($person['students']);
        $staffStudents += $n;
        $studentsInCharge += $n;
    }
    foreach ($agents as $person) {
        $n = count($person['students']);
        $agentStudents += $n;
        $studentsInCharge += $n;
    }
    $companyStudents = 0;
    foreach ($companies as $company) {
        $companyStudents += count($company['students']);
    }

    return [
        'staff' => $staff,
        'agents' => $agents,
        'companies' => $companies,
        'staff_count' => count($staff),
        'agent_count' => count($agents),
        'agent_role_count' => $agentRoleCount,
        'company_count' => count($companies),
        'students_in_charge' => $studentsInCharge,
        'staff_students' => $staffStudents,
        'agent_students' => $agentStudents,
        'company_students' => $companyStudents,
    ];
}

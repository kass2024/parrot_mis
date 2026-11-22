<?php
declare(strict_types=1);

/**
 * Contract-renewal team from "Renew all staff contracts".
 * Daily job reminders and the daily activity report use only this list.
 */

require_once __DIR__ . '/attendance_checkout.php';

/**
 * @return list<array{name:string,email:string,position:string}>
 */
function pcvc_contract_roster(): array
{
    return [
        ['name' => 'Akayezu Sonia', 'email' => 'soniamuhizi@gmail.com', 'position' => 'IT supporting staff'],
        ['name' => 'Mutware Jules Bonheur', 'email' => 'mutwarejules6@gmail.com', 'position' => 'Country Coordinator Assistant'],
        ['name' => 'Mukeshimana Yvette', 'email' => 'mukeshimanayvette2@gmail.com', 'position' => 'Visa Application and Document Assessment Officer'],
        ['name' => 'Ineza Glad Henrianne', 'email' => 'gladineza623@gmail.com', 'position' => 'MIS Documents, Northquest University'],
        ['name' => 'Irakoze Viviane', 'email' => 'vivirako27@gmail.com', 'position' => 'Agent'],
        ['name' => 'Tuyishimire Robert', 'email' => 'tuyishimirerobert1@gmail.com', 'position' => 'Student Application and Job Application'],
        ['name' => 'Ingabire Felicite', 'email' => 'dearingabire@gmail.com', 'position' => 'Digital Marketing Manager'],
        ['name' => 'Ikuzwe Yvone Bienvenue', 'email' => 'ybienvenue1@gmail.com', 'position' => 'China representative of Parrot'],
        ['name' => 'Uwamahoro Delphine', 'email' => 'uwamahorodelphine8@gmail.com', 'position' => 'Remote Student Recruitment Staff'],
        ['name' => 'Uwitonze Yvette', 'email' => 'uwiyvette063@gmail.com', 'position' => 'Remote Student Recruitment Staff'],
        ['name' => 'Nyirabizeyimana Sandrine', 'email' => 'nyirabizeyimanasandrine4@gmail.com', 'position' => 'Company Officer'],
    ];
}

function pcvc_roster_norm(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9@.\s]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * @return list<string>
 */
function pcvc_roster_tokens(string $name): array
{
    $parts = preg_split('/\s+/', pcvc_roster_norm($name)) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        if (strlen($part) >= 3) {
            $tokens[] = $part;
        }
    }

    return array_values(array_unique($tokens));
}

function pcvc_roster_name_matches(string $rosterName, string $adminName): bool
{
    $tokens = pcvc_roster_tokens($rosterName);
    if ($tokens === []) {
        return false;
    }
    $hay = ' ' . pcvc_roster_norm($adminName) . ' ';
    foreach ($tokens as $token) {
        if (!str_contains($hay, ' ' . $token . ' ')) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<array{id:int,name:string,email:string,position:string}>
 */
function pcvc_roster_load_admins(mysqli $conn): array
{
    $res = $conn->query(
        "SELECT id,
                COALESCE(NULLIF(TRIM(full_name), ''), TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) AS full_name,
                email,
                COALESCE(position, '') AS position
         FROM admins"
    );
    if (!$res) {
        return [];
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => trim((string) ($row['full_name'] ?? '')),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'position' => trim((string) ($row['position'] ?? '')),
        ];
    }
    $res->free();

    return $rows;
}

/**
 * One login per contract person. Email wins. A second account with the same name is left out.
 *
 * @return list<array{name:string,email:string,position:string,admin_id:int,db_name:string,db_email:string,db_position:string}>
 */
function pcvc_roster_resolved(mysqli $conn): array
{
    $admins = pcvc_roster_load_admins($conn);
    $used = [];
    $resolved = [];

    foreach (pcvc_contract_roster() as $person) {
        $wantEmail = strtolower(trim($person['email']));
        $match = null;
        foreach ($admins as $admin) {
            if ($admin['email'] !== '' && $admin['email'] === $wantEmail && !isset($used[$admin['id']])) {
                $match = $admin;
                break;
            }
        }
        if ($match === null) {
            foreach ($admins as $admin) {
                if (isset($used[$admin['id']])) {
                    continue;
                }
                if (pcvc_roster_name_matches($person['name'], $admin['name'])) {
                    $match = $admin;
                    break;
                }
            }
        }
        if ($match !== null) {
            $used[$match['id']] = true;
        }
        $resolved[] = [
            'name' => $person['name'],
            'email' => $person['email'],
            'position' => $person['position'],
            'admin_id' => $match['id'] ?? 0,
            'db_name' => $match['name'] ?? '',
            'db_email' => $match['email'] ?? '',
            'db_position' => $match['position'] ?? '',
        ];
    }

    return $resolved;
}

/**
 * Contract roster plus every active accountant login, so the performance
 * report includes the accountant even when they are not on the contract list.
 *
 * @return list<array{name:string,email:string,position:string,admin_id:int,db_name:string,db_email:string,db_position:string}>
 */
function pcvc_roster_report_people(mysqli $conn): array
{
    $people = pcvc_roster_resolved($conn);
    $seen = [];
    foreach ($people as $person) {
        if ($person['admin_id'] > 0) {
            $seen[$person['admin_id']] = true;
        }
    }

    $res = $conn->query(
        "SELECT id,
                COALESCE(NULLIF(TRIM(full_name), ''), TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) AS full_name,
                LOWER(TRIM(COALESCE(email, ''))) AS email,
                COALESCE(position, '') AS position,
                LOWER(TRIM(COALESCE(account_status, 'active'))) AS account_status
         FROM admins
         WHERE LOWER(TRIM(COALESCE(role, ''))) = 'accountant'"
    );
    if (!$res) {
        return $people;
    }
    while ($row = $res->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['account_status'] ?? 'active');
        if ($id <= 0 || isset($seen[$id]) || in_array($status, ['inactive', 'disabled', 'deleted', 'pending'], true)) {
            continue;
        }
        $seen[$id] = true;
        $name = trim((string) ($row['full_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $position = trim((string) ($row['position'] ?? ''));
        if ($name === '') {
            $name = 'Accountant';
        }
        if ($position === '') {
            $position = 'Accountant';
        }
        $people[] = [
            'name' => $name,
            'email' => $email,
            'position' => $position,
            'admin_id' => $id,
            'db_name' => $name,
            'db_email' => $email,
            'db_position' => $position,
        ];
    }
    $res->free();

    return $people;
}

/**
 * @return array<int, true>
 */
function pcvc_roster_admin_ids(mysqli $conn): array
{
    $ids = [];
    foreach (pcvc_roster_resolved($conn) as $person) {
        if ($person['admin_id'] > 0) {
            $ids[$person['admin_id']] = true;
        }
    }

    return $ids;
}

function pcvc_roster_money(int $amount): string
{
    return 'RWF ' . number_format(max(0, $amount));
}

/**
 * @return list<array<string, mixed>>
 */
function pcvc_roster_daily_report(mysqli $conn, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = (new DateTime('now', new DateTimeZone('Africa/Kigali')))->format('Y-m-d');
    }

    $people = pcvc_roster_report_people($conn);
    $ids = [];
    foreach ($people as $person) {
        if ($person['admin_id'] > 0) {
            $ids[] = $person['admin_id'];
        }
    }

    $completed = [];
    $open = [];
    $pay = [];
    $jobs = [];
    $targets = pcvc_daily_job_targets_for_admins($conn, $ids);

    if ($ids !== []) {
        $idSql = implode(',', array_map('intval', $ids));
        $dateEsc = $conn->real_escape_string($date);

        $doneRes = $conn->query(
            "SELECT admin_id, COUNT(*) AS c
             FROM job_list
             WHERE status = 'completed'
               AND completed_at IS NOT NULL
               AND DATE(DATE_ADD(completed_at, INTERVAL 2 HOUR)) = '{$dateEsc}'
               AND admin_id IN ({$idSql})
             GROUP BY admin_id"
        );
        if ($doneRes) {
            while ($row = $doneRes->fetch_assoc()) {
                $completed[(int) $row['admin_id']] = (int) $row['c'];
            }
            $doneRes->free();
        }

        $openRes = $conn->query(
            "SELECT admin_id, COUNT(*) AS c
             FROM job_list
             WHERE status = 'not_completed'
               AND admin_id IN ({$idSql})
             GROUP BY admin_id"
        );
        if ($openRes) {
            while ($row = $openRes->fetch_assoc()) {
                $open[(int) $row['admin_id']] = (int) $row['c'];
            }
            $openRes->free();
        }

        $payRes = $conn->query(
            "SELECT admin_id, check_in_time, check_out_time, total_work_minutes, daily_salary_rwf, total_payment_rwf
             FROM attendance
             WHERE date = '{$dateEsc}'
               AND admin_id IN ({$idSql})"
        );
        if ($payRes) {
            while ($row = $payRes->fetch_assoc()) {
                $pay[(int) $row['admin_id']] = $row;
            }
            $payRes->free();
        }

        $jobRes = $conn->query(
            "SELECT admin_id, title, job_type, applicant_name, completed_at
             FROM job_list
             WHERE status = 'completed'
               AND completed_at IS NOT NULL
               AND DATE(DATE_ADD(completed_at, INTERVAL 2 HOUR)) = '{$dateEsc}'
               AND admin_id IN ({$idSql})
             ORDER BY completed_at DESC"
        );
        if ($jobRes) {
            while ($row = $jobRes->fetch_assoc()) {
                $aid = (int) $row['admin_id'];
                if (!isset($jobs[$aid])) {
                    $jobs[$aid] = [];
                }
                if (count($jobs[$aid]) >= 8) {
                    continue;
                }
                $jobs[$aid][] = [
                    'title' => trim((string) ($row['title'] ?? '')),
                    'type' => trim((string) ($row['job_type'] ?? '')),
                    'lead' => trim((string) ($row['applicant_name'] ?? '')),
                ];
            }
            $jobRes->free();
        }
    }

    $rows = [];
    foreach ($people as $person) {
        $id = $person['admin_id'];
        $done = $completed[$id] ?? 0;
        $stillOpen = $open[$id] ?? 0;
        $target = $targets[$id] ?? PCVC_CHECKOUT_MIN_JOBS_TODAY;
        $attendance = $pay[$id] ?? null;
        $salary = 0;
        $minutes = 0;
        $checkIn = '';
        $checkOut = '';
        if (is_array($attendance)) {
            $salary = (int) ($attendance['daily_salary_rwf'] ?? 0);
            if ($salary <= 0) {
                $salary = (int) ($attendance['total_payment_rwf'] ?? 0);
            }
            $minutes = (int) ($attendance['total_work_minutes'] ?? 0);
            $checkIn = trim((string) ($attendance['check_in_time'] ?? ''));
            $checkOut = trim((string) ($attendance['check_out_time'] ?? ''));
        }
        $listClear = $id > 0 && $stillOpen === 0;
        $rows[] = [
            'name' => $person['name'],
            'email' => $person['db_email'] !== '' ? $person['db_email'] : $person['email'],
            'position' => $person['db_position'] !== '' ? $person['db_position'] : $person['position'],
            'admin_id' => $id,
            'linked' => $id > 0,
            'completed_today' => $done,
            'open_jobs' => $stillOpen,
            'list_clear' => $listClear,
            'target' => $target,
            'target_met' => $done >= $target,
            'salary' => $salary,
            'salary_label' => pcvc_roster_money($salary),
            'minutes' => $minutes,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'jobs' => $jobs[$id] ?? [],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        if ($a['list_clear'] !== $b['list_clear']) {
            return $a['list_clear'] ? 1 : -1;
        }
        if ($a['salary'] !== $b['salary']) {
            return $b['salary'] <=> $a['salary'];
        }

        return strcasecmp($a['name'], $b['name']);
    });

    return $rows;
}

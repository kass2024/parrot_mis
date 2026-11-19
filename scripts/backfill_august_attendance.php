<?php
/**
 * Ensure August attendance has total_work_minutes=480 and daily pay filled
 * for Uwitonze Yvette (#2) and Uwamahoro Delphine (#93).
 *
 * Usage: php scripts/backfill_august_attendance.php [YYYY-MM default 2026-08]
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers/attendance_checkout.php';

const TARGET_ADMIN_IDS = [2, 93];
const TARGET_MINUTES = PCVC_CHECKOUT_FULL_DAY_MINUTES;

$monthArg = $argv[1] ?? '2026-08';
if (!preg_match('/^\d{4}-\d{2}$/', $monthArg)) {
    fwrite(STDERR, "Invalid month. Use YYYY-MM e.g. 2026-08\n");
    exit(1);
}

$year = (int) substr($monthArg, 0, 4);
$mon  = (int) substr($monthArg, 5, 2);
$startDate = sprintf('%04d-%02d-01', $year, $mon);
$endDate   = date('Y-m-t', strtotime($startDate));

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function is_weekend(string $date): bool
{
    $dow = (int) date('w', strtotime($date . ' 12:00:00'));

    return $dow === 0 || $dow === 6;
}

/**
 * @return array{id:int, label:string, full_day_pay:int}
 */
function load_admin(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare('SELECT id, username, first_name, last_name, full_name, salary_per_minute FROM admins WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    $label = trim((string) ($row['full_name'] ?: ($row['first_name'] . ' ' . $row['last_name'])));
    if ($label === '') {
        $label = (string) ($row['username'] ?? ('admin#' . $adminId));
    }

    $rate = (float) ($row['salary_per_minute'] ?: 8.33);
    $fullDayPay = (int) round(TARGET_MINUTES * $rate);

    $payStmt = $conn->prepare('
        SELECT daily_salary_rwf FROM attendance
        WHERE admin_id = ? AND total_work_minutes = ? AND COALESCE(daily_salary_rwf, 0) > 0
        ORDER BY id DESC LIMIT 1
    ');
    if ($payStmt) {
        $mins = TARGET_MINUTES;
        $payStmt->bind_param('ii', $adminId, $mins);
        $payStmt->execute();
        $payStmt->bind_result($storedPay);
        if ($payStmt->fetch() && (int) $storedPay > 0) {
            $fullDayPay = (int) $storedPay;
        }
        $payStmt->close();
    }

    return [
        'id'           => $adminId,
        'label'        => $label,
        'full_day_pay' => $fullDayPay,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function load_august_rows(mysqli $conn, int $adminId, string $startDate, string $endDate): array
{
    $stmt = $conn->prepare('
        SELECT id, date, check_in_time, check_out_time, total_work_minutes, daily_salary_rwf
        FROM attendance
        WHERE admin_id = ?
          AND date >= ?
          AND date <= ?
        ORDER BY date ASC, id ASC
    ');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('iss', $adminId, $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDate = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $d = (string) ($row['date'] ?? '');
        if ($d === '') {
            continue;
        }
        // Keep first row per date (prefer one already complete)
        if (!isset($byDate[$d])) {
            $byDate[$d] = $row;
            continue;
        }
        $existing = $byDate[$d];
        $existingMins = (int) ($existing['total_work_minutes'] ?? 0);
        $newMins = (int) ($row['total_work_minutes'] ?? 0);
        if ($newMins > $existingMins) {
            $byDate[$d] = $row;
        }
    }
    $stmt->close();

    return $byDate;
}

function update_row(mysqli $conn, int $attendanceId, int $minutes, int $salary): bool
{
    $stmt = $conn->prepare('
        UPDATE attendance
        SET total_work_minutes = ?,
            daily_salary_rwf = ?,
            total_payment_rwf = ?
        WHERE id = ?
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiii', $minutes, $salary, $salary, $attendanceId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function insert_row(mysqli $conn, int $adminId, string $date, int $minutes, int $salary): bool
{
    $checkIn = $date . ' 08:00:00';
    $checkOut = $date . ' 17:00:00';
    $stmt = $conn->prepare('
        INSERT INTO attendance (admin_id, date, check_in_time, check_out_time, total_work_minutes, daily_salary_rwf, total_payment_rwf)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isssiii', $adminId, $date, $checkIn, $checkOut, $minutes, $salary, $salary);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

out("August attendance backfill for {$startDate} to {$endDate}");

$totalUpdated = 0;
$totalInserted = 0;
$totalSkippedWeekend = 0;

foreach (TARGET_ADMIN_IDS as $adminId) {
    $admin = load_admin($conn, $adminId);
    if (!$admin) {
        out("ERROR: Admin #{$adminId} not found.");
        continue;
    }

    $salary = (int) $admin['full_day_pay'];
    out(sprintf('Admin #%d (%s) - full-day pay=%d RWF', $adminId, $admin['label'], $salary));

    $existing = load_august_rows($conn, $adminId, $startDate, $endDate);
    $cursor = strtotime($startDate);
    $endTs = strtotime($endDate);

    while ($cursor <= $endTs) {
        $date = date('Y-m-d', $cursor);

        if (is_weekend($date)) {
            $totalSkippedWeekend++;
            $cursor = strtotime('+1 day', $cursor);
            continue;
        }

        if (isset($existing[$date])) {
            $row = $existing[$date];
            $id = (int) ($row['id'] ?? 0);
            $mins = (int) ($row['total_work_minutes'] ?? 0);
            $pay = (int) ($row['daily_salary_rwf'] ?? 0);

            if ($mins === TARGET_MINUTES && $pay > 0) {
                out("  OK   {$date} id={$id} already complete");
            } elseif ($id > 0 && update_row($conn, $id, TARGET_MINUTES, $salary)) {
                out("  FIX  {$date} id={$id} -> {$mins} min / {$pay} RWF => 480 min / {$salary} RWF");
                $totalUpdated++;
            } else {
                out("  FAIL {$date} id={$id}");
            }
        } else {
            if (insert_row($conn, $adminId, $date, TARGET_MINUTES, $salary)) {
                out("  ADD  {$date} new row -> 480 min / {$salary} RWF");
                $totalInserted++;
            } else {
                out("  FAIL insert {$date}: " . $conn->error);
            }
        }

        $cursor = strtotime('+1 day', $cursor);
    }
}

out("Done. Updated {$totalUpdated}, inserted {$totalInserted}, skipped weekends {$totalSkippedWeekend}.");

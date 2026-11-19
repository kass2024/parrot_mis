<?php
/**
 * Backfill total_work_minutes (480 = full day) for Uwitonze Yvette and Uwamahoro Delphine.
 *
 * Usage (CLI on server):
 *   php scripts/backfill_attendance_work_minutes.php
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

const TARGET_MINUTES = PCVC_CHECKOUT_FULL_DAY_MINUTES;

/** Explicit admin IDs: Uwitonze Yvette (#2), Uwamahoro Delphine (#93). */
const TARGET_ADMIN_IDS = [2, 93];

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

/**
 * @return list<array{id:int, label:string, salary_per_minute:float, full_day_pay:int}>
 */
function load_target_admins(mysqli $conn): array
{
    $ids = implode(',', array_map('intval', TARGET_ADMIN_IDS));
    $res = $conn->query("SELECT id, username, first_name, last_name, full_name, salary_per_minute FROM admins WHERE id IN ($ids)");
    if (!$res) {
        throw new RuntimeException('Could not load admins: ' . $conn->error);
    }

    $admins = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $label = trim((string) ($row['full_name'] ?: ($row['first_name'] . ' ' . $row['last_name'])));
        if ($label === '') {
            $label = (string) ($row['username'] ?? ('admin#' . $id));
        }

        $rate = (float) ($row['salary_per_minute'] ?: 8.33);
        $payStmt = $conn->prepare("
            SELECT daily_salary_rwf
            FROM attendance
            WHERE admin_id = ?
              AND total_work_minutes = ?
              AND COALESCE(daily_salary_rwf, 0) > 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $fullDayPay = (int) round(TARGET_MINUTES * $rate);
        if ($payStmt) {
            $mins = TARGET_MINUTES;
            $payStmt->bind_param('ii', $id, $mins);
            $payStmt->execute();
            $payStmt->bind_result($storedPay);
            if ($payStmt->fetch() && (int) $storedPay > 0) {
                $fullDayPay = (int) $storedPay;
            }
            $payStmt->close();
        }

        $admins[] = [
            'id'                => $id,
            'label'             => $label,
            'salary_per_minute' => $rate,
            'full_day_pay'      => $fullDayPay,
        ];
    }
    $res->free();

    return $admins;
}

/**
 * @return list<array<string, mixed>>
 */
function load_rows_to_fix(mysqli $conn, int $adminId): array
{
    $stmt = $conn->prepare("
        SELECT id, date, check_in_time, check_out_time, total_work_minutes, daily_salary_rwf
        FROM attendance
        WHERE admin_id = ?
          AND COALESCE(total_work_minutes, 0) = 0
          AND check_in_time IS NOT NULL
        ORDER BY date ASC, id ASC
    ");
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function update_row(mysqli $conn, int $attendanceId, int $minutes, int $salary): bool
{
    $stmt = $conn->prepare("
        UPDATE attendance
        SET total_work_minutes = ?,
            daily_salary_rwf = ?,
            total_payment_rwf = ?
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new RuntimeException('Update prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiii', $minutes, $salary, $salary, $attendanceId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

out('Backfill attendance total_work_minutes -> ' . TARGET_MINUTES);

$admins = load_target_admins($conn);
if ($admins === []) {
    out('ERROR: Target admins not found.');
    exit(1);
}

$totalUpdated = 0;

foreach ($admins as $admin) {
    $adminId = (int) $admin['id'];
    $salary = (int) $admin['full_day_pay'];

    out(sprintf(
        'Admin #%d (%s) - salary_per_minute=%.2f, full-day pay=%d RWF',
        $adminId,
        $admin['label'],
        $admin['salary_per_minute'],
        $salary
    ));

    $rows = load_rows_to_fix($conn, $adminId);
    out('  Rows to update: ' . count($rows));

    foreach ($rows as $row) {
        $attendanceId = (int) ($row['id'] ?? 0);
        $date = (string) ($row['date'] ?? '');
        if ($attendanceId <= 0) {
            continue;
        }

        $dayOfWeek = (int) date('w', strtotime($date . ' 12:00:00'));
        $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);
        if ($isWeekend) {
            out("  SKIP id={$attendanceId} date={$date} (weekend)");
            continue;
        }

        if (!update_row($conn, $attendanceId, TARGET_MINUTES, $salary)) {
            out("  FAIL id={$attendanceId} date={$date}");
            continue;
        }

        out('  OK   id=' . $attendanceId . ' date=' . $date . ' -> ' . TARGET_MINUTES . ' min, ' . $salary . ' RWF');
        $totalUpdated++;
    }
}

out('Done. Updated ' . $totalUpdated . ' attendance row(s).');

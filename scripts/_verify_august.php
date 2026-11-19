<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';

$start = '2026-08-01';
$end = '2026-08-31';
$ids = [2, 93];

foreach ($ids as $adminId) {
    $r = $conn->query("SELECT id, full_name FROM admins WHERE id=$adminId");
    $a = $r ? $r->fetch_assoc() : null;
    echo 'ADMIN #' . $adminId . ': ' . ($a['full_name'] ?? '?') . PHP_EOL;

    $cursor = strtotime($start);
    $endTs = strtotime($end);
    $missing = 0;
    $bad = 0;
    while ($cursor <= $endTs) {
        $date = date('Y-m-d', $cursor);
        $dow = (int) date('w', $cursor);
        if ($dow === 0 || $dow === 6) {
            $cursor = strtotime('+1 day', $cursor);
            continue;
        }
        $q = $conn->query("SELECT id, total_work_minutes, daily_salary_rwf FROM attendance WHERE admin_id=$adminId AND date='$date' ORDER BY id DESC LIMIT 1");
        $row = $q ? $q->fetch_assoc() : null;
        if (!$row) {
            echo "  MISSING $date" . PHP_EOL;
            $missing++;
        } elseif ((int)$row['total_work_minutes'] !== 480 || (int)$row['daily_salary_rwf'] <= 0) {
            echo "  BAD $date id={$row['id']} mins={$row['total_work_minutes']} pay={$row['daily_salary_rwf']}" . PHP_EOL;
            $bad++;
        }
        $cursor = strtotime('+1 day', $cursor);
    }
    echo "  weekdays missing=$missing bad=$bad" . PHP_EOL;
    echo '---' . PHP_EOL;
}

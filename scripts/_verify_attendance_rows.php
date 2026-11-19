<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
$ids = [2, 34, 93];
foreach ($ids as $id) {
    $r = $conn->query("SELECT id, username, full_name FROM admins WHERE id=$id");
    $a = $r ? $r->fetch_assoc() : null;
    echo "ADMIN $id: " . json_encode($a) . PHP_EOL;
    $q = $conn->query("SELECT COUNT(*) c FROM attendance WHERE admin_id=$id AND COALESCE(total_work_minutes,0)=0");
    $c = $q ? $q->fetch_assoc()['c'] : '?';
    echo "  zero_minute_rows=$c" . PHP_EOL;
    $q2 = $conn->query("SELECT id, date, check_in_time, check_out_time, total_work_minutes FROM attendance WHERE admin_id=$id AND COALESCE(total_work_minutes,0)=0 ORDER BY date DESC LIMIT 15");
    while ($q2 && ($row = $q2->fetch_assoc())) {
        echo '  ' . json_encode($row) . PHP_EOL;
    }
    echo "---" . PHP_EOL;
}

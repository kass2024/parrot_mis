<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/payroll_helpers.php';

$adminId = (int) ($argv[1] ?? 93);
$month = $argv[2] ?? '2026-08';
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$stmt = $conn->prepare('SELECT salary_per_minute FROM admins WHERE id = ?');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$stmt->bind_result($rate);
$stmt->fetch();
$stmt->close();

$byAdmin = pcvc_payroll_load_attendance_by_admin($conn, $start, $end);
$days = $byAdmin[$adminId] ?? [];

$totalMinutes = 0;
$totalSalary = 0;
foreach ($days as $date => $row) {
    $billable = min((int) ($row['minutes'] ?? 0), 480);
    $stored = (float) ($row['stored_daily_pay'] ?? 0);
    $dayPay = $stored > 0 ? (int) round($stored) : (int) round($billable * (float) $rate);
    $totalMinutes += $billable;
    $totalSalary += $dayPay;
}

echo "admin={$adminId} month={$month} days=" . count($days) . " minutes={$totalMinutes} salary={$totalSalary}" . PHP_EOL;

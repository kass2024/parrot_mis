<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/payroll_helpers.php';
// JSON header
header("Content-Type: application/json");

// Disable output of PHP warnings/errors (prevents broken JSON)
error_reporting(0);
ini_set("display_errors", 0);

/* ===========================================================
   AUTH CHECK
=========================================================== */
if (!isset($_SESSION['id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$admin_id = intval($_SESSION['id']);

/* ===========================================================
   VALIDATE INPUT
=========================================================== */
if (empty($_POST['month'])) {
    echo json_encode(["error" => "Month is required"]);
    exit;
}

$month = $_POST['month']; // YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(["error" => "Invalid month"]);
    exit;
}

/* ===========================================================
   FETCH STAFF SALARY RATE (fallback when daily pay not stored)
=========================================================== */
$stmt = $conn->prepare("
    SELECT salary_per_minute 
    FROM admins 
    WHERE id = ?
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($salary_per_minute);
$stmt->fetch();
$stmt->close();

if (!$salary_per_minute || $salary_per_minute <= 0) {
    echo json_encode([
        "total_minutes" => 0,
        "salary"        => "0",
        "raw_salary"    => 0
    ]);
    exit;
}

/* ===========================================================
   FETCH ATTENDANCE FOR THE SELECTED MONTH

   One row per calendar day. Prefer checkout-stored daily pay
   (daily_salary_rwf / total_payment_rwf) so salary requests
   match attendance records; fall back to rate x minutes.
=========================================================== */
$startMonth = $month . '-01';
$endMonth   = date('Y-m-t', strtotime($startMonth));
$byAdmin    = pcvc_payroll_load_attendance_by_admin($conn, $startMonth, $endMonth);
$days       = $byAdmin[$admin_id] ?? [];

$total_minutes = 0;
$total_salary  = 0;
$rate          = (float) $salary_per_minute;

foreach ($days as $row) {
    $worked_minutes   = max(0, (int) ($row['minutes'] ?? 0));
    $billable_minutes = min($worked_minutes, 480);
    $storedPay        = (float) ($row['stored_daily_pay'] ?? 0);

    $total_minutes += $billable_minutes;
    if ($storedPay > 0) {
        $total_salary += (int) round($storedPay);
    } else {
        $total_salary += (int) round($billable_minutes * $rate);
    }
}

/* ===========================================================
   ALWAYS RETURN CLEAN JSON
=========================================================== */
$response = [
    "total_minutes" => $total_minutes,
    "salary"        => number_format($total_salary, 0, ".", ","), // display-friendly
    "raw_salary"    => $total_salary, // used for request insert
];

echo json_encode($response);
exit;
?>

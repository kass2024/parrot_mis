<?php
require_once __DIR__ . '/../db.php';
header("Content-Type: application/json");

// =============================================================
// 1. ONLY POST REQUESTS
// =============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit;
}

// =============================================================
// 2. ALWAYS use POST admin_id (NO SESSIONS for Android compatibility)
// =============================================================
$admin_id = intval($_POST['admin_id'] ?? 0);

if ($admin_id <= 0) {
    echo json_encode(["status" => "error", "message" => "admin_id missing"]);
    exit;
}

// =============================================================
// 3. CLEAN INPUTS
// =============================================================
$action   = $_POST['action'] ?? '';
$lat      = floatval($_POST['lat'] ?? 0);
$lng      = floatval($_POST['lng'] ?? 0);
$is_mock  = intval($_POST['mock'] ?? 0);
$location = trim($_POST['location'] ?? 'Unknown');
$timezone = $_POST['timezone'] ?? 'UTC';

date_default_timezone_set(
    in_array($timezone, timezone_identifiers_list()) ? $timezone : 'UTC'
);

$now       = date("Y-m-d H:i:s");
$today     = date("Y-m-d");
$dayOfWeek = date("w");
$isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);

// =============================================================
// 4. BLOCK FAKE GPS
// =============================================================
if ($is_mock == 1) {
    echo json_encode(["status" => "error", "message" => "Fake GPS detected"]);
    exit;
}

// =============================================================
// 5. LOAD OFFICE COORDINATES
// =============================================================
$q = $conn->prepare("
    SELECT o.latitude, o.longitude, o.radius_meters
    FROM admins a
    LEFT JOIN offices o ON a.office_id = o.id
    WHERE a.id = ?
");
$q->bind_param("i", $admin_id);
$q->execute();
$q->bind_result($officeLat, $officeLng, $officeRadius);
$q->fetch();
$q->close();

if (!$officeLat || !$officeLng || !$officeRadius) {
    echo json_encode(["status" => "error", "message" => "Office not configured"]);
    exit;
}

// =============================================================
// 6. CALCULATE DISTANCE
// =============================================================
function geoDistance($lat1, $lon1, $lat2, $lon2) {
    $earth = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2)**2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2)**2;

    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

$distance = geoDistance($lat, $lng, $officeLat, $officeLng);

// =============================================================
// 7. GEO-FENCE CHECK
// =============================================================
if ($distance > $officeRadius) {
    echo json_encode([
        "status" => "error",
        "message" => "Outside office radius",
        "distance" => round($distance),
        "radius" => $officeRadius
    ]);
    exit;
}

// =============================================================
// 8. CHECK-IN LOGIC
// =============================================================
if ($action === "checkin") {

    // prevent double check-in
    $chk = $conn->prepare("SELECT id FROM attendance WHERE admin_id = ? AND date = ?");
    $chk->bind_param("is", $admin_id, $today);
    $chk->execute();
    $chk->store_result();

    if ($chk->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "Already checked in"]);
        exit;
    }

    // insert check-in
    $ins = $conn->prepare("
        INSERT INTO attendance 
        (admin_id, date, check_in_time, check_in_location, check_in_lat, check_in_lng)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param("isssdd", $admin_id, $today, $now, $location, $lat, $lng);
    $ins->execute();

    echo json_encode([
        "status" => "success",
        "message" => $isWeekend ? "Weekend check-in (no salary)." : "Check-in successful",
        "time" => $now
    ]);
    exit;
}

// =============================================================
// 9. CHECK-OUT LOGIC
// =============================================================
if ($action === "checkout") {

    // get check-in
    $get = $conn->prepare("SELECT id, check_in_time FROM attendance WHERE admin_id = ? AND date = ?");
    $get->bind_param("is", $admin_id, $today);
    $get->execute();
    $get->bind_result($attendance_id, $check_in_time);
    $get->fetch();
    $get->close();

    if (!$check_in_time) {
        echo json_encode(["status" => "error", "message" => "No check-in found"]);
        exit;
    }

    // Weekend → no salary
    if ($isWeekend) {
        $up = $conn->prepare("
            UPDATE attendance SET
                check_out_time = ?, check_out_location = ?, check_out_lat = ?, check_out_lng = ?,
                break_duration_minutes = 0,
                total_work_minutes = 0,
                total_payment_rwf = 0,
                daily_salary_rwf = 0
            WHERE id = ?
        ");
        $up->bind_param("ssdddi", $now, $location, $lat, $lng, $attendance_id);
        $up->execute();

        echo json_encode(["status" => "success", "message" => "Weekend checkout", "salary" => 0]);
        exit;
    }

    // get rate
    $rate = $conn->prepare("SELECT salary_per_minute, allowed_break_minutes FROM admins WHERE id = ?");
    $rate->bind_param("i", $admin_id);
    $rate->execute();
    $rate->bind_result($salary_per_minute, $allowed_break);
    $rate->fetch();
    $rate->close();

    $salary_per_minute = floatval($salary_per_minute ?: 8.33);
    $allowed_break = intval($allowed_break ?? 0);

    // time calc
    $checkin_ts = strtotime($check_in_time);
    $checkout_ts = strtotime($now);
    $total_minutes = ceil(($checkout_ts - $checkin_ts) / 60);

    // =============================================================
    // NEW RULE:
    // If worked minutes >= 300 → count as FULL DAY (480)
    // Otherwise keep actual minutes
    // =============================================================
    if ($total_minutes >= 300) {
        $effective_minutes = 480;
    } else {
        $effective_minutes = $total_minutes;
    }

    $payment = round($effective_minutes * $salary_per_minute);

    // SAVE INTO ALL REQUIRED FIELDS
    $up = $conn->prepare("
        UPDATE attendance SET
            check_out_time = ?, check_out_location = ?, check_out_lat = ?, check_out_lng = ?,
            break_duration_minutes = ?, 
            total_work_minutes = ?, 
            total_payment_rwf = ?, 
            daily_salary_rwf = ?
        WHERE id = ?
    ");
    $up->bind_param(
        "ssddiiiii",
        $now, $location, $lat, $lng,
        $allowed_break, $effective_minutes,
        $payment, $payment,
        $attendance_id
    );
    $up->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Checkout successful",
        "worked_minutes" => $effective_minutes,
        "salary" => $payment
    ]);
    exit;
}

// =============================================================
// 10. INVALID REQUEST
// =============================================================
echo json_encode(["status" => "error", "message" => "Invalid action"]);
exit;

?>

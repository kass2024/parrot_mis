<?php
session_start();
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/form17_application_status.php';

xander_ensure_form17_process_status_column($conn);
xander_ensure_form17_intake_column($conn);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('Method not allowed', false, 405);
}

$adminId = 0;
if (!empty($_SESSION['id'])) {
    $adminId = (int) $_SESSION['id'];
} elseif (!empty($_SESSION['admin_id'])) {
    $adminId = (int) $_SESSION['admin_id'];
}
if ($adminId <= 0) {
    jsonResponse('Unauthorized', false, 401);
}

$stmtRole = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
if (!$stmtRole) {
    jsonResponse('Server error', false, 500);
}
$stmtRole->bind_param('i', $adminId);
$stmtRole->execute();
$roleRow = $stmtRole->get_result()->fetch_assoc();
$stmtRole->close();
if (!$roleRow) {
    jsonResponse('Unauthorized', false, 401);
}
$dbRole = (string) ($roleRow['role'] ?? '');
$sessionRole = (string) ($_SESSION['role'] ?? '');
$allowed = xander_is_superadmin_role($dbRole)
    || xander_is_superadmin_role($sessionRole)
    || pcvc_is_staff_role($dbRole)
    || pcvc_is_staff_role($sessionRole)
    || strtolower(trim($dbRole)) === 'agent'
    || strtolower(trim($sessionRole)) === 'agent';
if (!$allowed) {
    jsonResponse('Forbidden', false, 403);
}

$full = preg_replace('/\s+/u', ' ', trim((string) ($_POST['full_name'] ?? '')));
if ($full === '' || mb_strlen($full) < 2) {
    jsonResponse('Enter the applicant name.', false, 400);
}
$parts = preg_split('/\s+/u', $full);
$first = (string) array_shift($parts);
$last = $parts ? implode(' ', $parts) : $first;
$email = trim((string) ($_POST['email'] ?? ''));
$phone = '';
$status = trim((string) ($_POST['process_status'] ?? 'submitted'));
if (!xander_is_valid_visa_process_status($status)) {
    jsonResponse('Choose a valid status.', false, 400);
}
$visaType = trim((string) ($_POST['visa_type'] ?? 'Study Visa'));
if (!in_array($visaType, ['Study Visa', 'Visit Visa', 'Work Visa'], true)) {
    $visaType = 'Study Visa';
}

$destination = trim((string) ($_POST['destination_country'] ?? $_POST['country_to_visit'] ?? ''));
$allowedCountries = xander_visa_destination_countries();
if ($destination === '' || !in_array($destination, $allowedCountries, true)) {
    jsonResponse('Choose a destination country.', false, 400);
}

$intake = trim((string) ($_POST['intake'] ?? ''));
if ($visaType !== 'Study Visa') {
    $intake = '';
} elseif (!xander_is_valid_visa_intake($intake)) {
    jsonResponse('Choose a valid intake.', false, 400);
}

$userId = 'user-' . time() . '/' . random_int(100, 999) . '/' . random_int(10000, 99999) . '-' . random_int(1000, 9999);
$date = date('Y-m-d');
$empty = '';
$regionId = 0;
$countryId = 0;
$isRead = 1;

$sql = 'INSERT INTO form_17_applications
    (user_id, date, company, prefix, first_name, middle_name, last_name,
     email, applicant_mobile, birthdate, gender, passport_number,
     country_applying_from, country_to_visit, visa_type, intake,
     region_id, country_id, form_url, is_read, process_status, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
$st = $conn->prepare($sql);
if (!$st) {
    jsonResponse('Could not add the applicant.', false, 500);
}
$st->bind_param(
    'sssssssssssssssiisis',
    $userId,
    $date,
    $empty,
    $empty,
    $first,
    $empty,
    $last,
    $email,
    $phone,
    $empty,
    $empty,
    $empty,
    $destination,
    $visaType,
    $intake,
    $regionId,
    $countryId,
    $empty,
    $isRead,
    $status
);
if (!$st->execute()) {
    $err = $st->error;
    $st->close();
    jsonResponse('Could not add the applicant. ' . $err, false, 500);
}
$st->close();

jsonResponse([
    'user_id' => $userId,
    'process_status' => $status,
    'label' => xander_visa_application_process_statuses()[$status] ?? $status,
    'destination_country' => $destination,
    'intake' => $intake,
], true);

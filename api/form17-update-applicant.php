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

$userId = trim((string) ($_POST['user_id'] ?? ''));
if ($userId === '') {
    jsonResponse('Missing application id.', false, 400);
}

$full = preg_replace('/\s+/u', ' ', trim((string) ($_POST['full_name'] ?? '')));
if ($full === '' || mb_strlen($full) < 2) {
    jsonResponse('Enter the applicant name.', false, 400);
}
$parts = preg_split('/\s+/u', $full);
$first = (string) array_shift($parts);
$last = $parts ? implode(' ', $parts) : $first;

$email = trim((string) ($_POST['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse('Enter a valid email address.', false, 400);
}
$phone = preg_replace('/\s+/', '', trim((string) ($_POST['phone'] ?? $_POST['applicant_mobile'] ?? '')));
if (mb_strlen($phone) > 20) {
    jsonResponse('Phone number is too long.', false, 400);
}

$birthdateRaw = trim((string) ($_POST['birthdate'] ?? ''));
$birthdate = '';
if ($birthdateRaw !== '' && $birthdateRaw !== '0000-00-00') {
    $dt = DateTime::createFromFormat('Y-m-d', $birthdateRaw);
    if (!$dt || $dt->format('Y-m-d') !== $birthdateRaw) {
        jsonResponse('Enter a valid birth date.', false, 400);
    }
    $birthdate = $birthdateRaw;
}

$gender = trim((string) ($_POST['gender'] ?? ''));
if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
    jsonResponse('Choose a valid gender.', false, 400);
}

$passportNumber = trim((string) ($_POST['passport_number'] ?? ''));
if (mb_strlen($passportNumber) > 50) {
    jsonResponse('Passport number is too long.', false, 400);
}

$countryFrom = trim((string) ($_POST['country_applying_from'] ?? $_POST['country_from'] ?? ''));
$destination = trim((string) ($_POST['destination_country'] ?? $_POST['country_to_visit'] ?? $_POST['country_to'] ?? ''));
$allowedCountries = xander_visa_destination_countries();
if ($countryFrom !== '' && !in_array($countryFrom, $allowedCountries, true)) {
    jsonResponse('Choose a valid applying-from country.', false, 400);
}
if ($destination !== '' && !in_array($destination, $allowedCountries, true)) {
    jsonResponse('Choose a valid destination country.', false, 400);
}

$visaType = trim((string) ($_POST['visa_type'] ?? 'Study Visa'));
if (!in_array($visaType, ['Study Visa', 'Visit Visa', 'Work Visa'], true)) {
    jsonResponse('Choose a valid visa type.', false, 400);
}

$intake = trim((string) ($_POST['intake'] ?? ''));
if ($visaType !== 'Study Visa') {
    $intake = '';
}

$chk = $conn->prepare('SELECT user_id, intake FROM form_17_applications WHERE user_id = ? LIMIT 1');
if (!$chk) {
    jsonResponse('Server error', false, 500);
}
$chk->bind_param('s', $userId);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$existing) {
    jsonResponse('Application not found', false, 404);
}
$currentIntake = trim((string) ($existing['intake'] ?? ''));
if ($visaType === 'Study Visa' && $intake !== '' && !xander_is_valid_visa_intake($intake) && $intake !== $currentIntake) {
    jsonResponse('Choose a valid intake.', false, 400);
}

$emptyMiddle = '';
$sql = 'UPDATE form_17_applications SET
    first_name = ?, middle_name = ?, last_name = ?,
    email = ?, applicant_mobile = ?, birthdate = NULLIF(?, \'\'), gender = ?,
    passport_number = ?,
    country_applying_from = ?, country_to_visit = ?, visa_type = ?, intake = ?
    WHERE user_id = ? LIMIT 1';
$st = $conn->prepare($sql);
if (!$st) {
    jsonResponse('Could not update the applicant.', false, 500);
}

$st->bind_param(
    'sssssssssssss',
    $first,
    $emptyMiddle,
    $last,
    $email,
    $phone,
    $birthdate,
    $gender,
    $passportNumber,
    $countryFrom,
    $destination,
    $visaType,
    $intake,
    $userId
);
if (!$st->execute()) {
    $err = $st->error;
    $st->close();
    jsonResponse('Could not update the applicant. ' . $err, false, 500);
}
$st->close();

$displayName = trim($first . ($last !== '' && strcasecmp($first, $last) !== 0 ? ' ' . $last : ''));

jsonResponse([
    'user_id' => $userId,
    'name' => $displayName,
    'email' => $email,
    'mobile' => $phone,
    'birthdate' => $birthdate,
    'gender' => $gender,
    'passport' => $passportNumber,
    'passport_number' => $passportNumber,
    'country_from' => $countryFrom,
    'country_to' => $destination,
    'visa_type' => $visaType,
    'intake' => $intake,
], true);

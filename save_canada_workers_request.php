<?php
declare(strict_types=1);

/**
 * save_canada_workers_request.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('CUW_RECRUITMENT_FORM');
    session_start();
}

ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function cuw_json(bool $ok, string $message, array $extra = [], int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Canada workers save: ' . $e->getMessage());
    cuw_json(false, 'Server error while saving your application. Please try again.', [], 500);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cuw_json(false, 'Invalid request', [], 405);
}

$user_id = trim((string) ($_POST['user_id'] ?? ''));
if ($user_id === '' || !preg_match('/^cuw_[a-zA-Z0-9_]+$/', $user_id)) {
    cuw_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $user_id;
} elseif ($user_id !== $_SESSION['user_id']) {
    cuw_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/canada_workers_schema.php';
require_once __DIR__ . '/helpers/canada_workers_files.php';
require_once __DIR__ . '/helpers/canada_workers_notify.php';

cuw_ensure_schema($conn);

$full_name = trim((string) ($_POST['full_name'] ?? ''));
$dobRaw = trim((string) ($_POST['date_of_birth'] ?? ''));
$gender = strtolower(trim((string) ($_POST['gender'] ?? '')));
$nationality = trim((string) ($_POST['nationality'] ?? ''));
$country = trim((string) ($_POST['country_of_residence'] ?? ''));
$emailRaw = trim((string) ($_POST['email'] ?? ''));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
$phone_area_code = preg_replace('/\D+/', '', (string) ($_POST['phone_area_code'] ?? '')) ?? '';
$phone_number = preg_replace('/\D+/', '', (string) ($_POST['phone_number'] ?? '')) ?? '';
$address = trim((string) ($_POST['current_address'] ?? ''));
$howDidYouKnowUs = trim((string) ($_POST['how_did_you_know_us'] ?? ''));
$position = strtolower(trim((string) ($_POST['position'] ?? '')));
$occupation = trim((string) ($_POST['current_occupation'] ?? ''));
$yearsRaw = trim((string) ($_POST['years_experience'] ?? ''));
$education = strtolower(trim((string) ($_POST['education_level'] ?? '')));
$experience = trim((string) ($_POST['work_experience_description'] ?? ''));
$declaration = (string) ($_POST['declaration'] ?? '');

$passport_file = cuw_normalize_rel_path((string) ($_POST['passport_file'] ?? ''));
$cv_file = cuw_normalize_rel_path((string) ($_POST['cv_file'] ?? ''));
$photo_file = cuw_normalize_rel_path((string) ($_POST['photo_file'] ?? ''));

$missing = [];
if ($full_name === '' || mb_strlen($full_name) > 200) {
    $missing[] = 'Full Name';
}
$dob = DateTime::createFromFormat('Y-m-d', $dobRaw);
$dobErrors = DateTime::getLastErrors();
$dobWarn = is_array($dobErrors) ? (int) ($dobErrors['warning_count'] ?? 0) : 0;
$dobErr = is_array($dobErrors) ? (int) ($dobErrors['error_count'] ?? 0) : 0;
$dobOk = $dob instanceof DateTime && $dob->format('Y-m-d') === $dobRaw && $dobWarn === 0 && $dobErr === 0;
if (!$dobOk) {
    $missing[] = 'Date of Birth';
} else {
    $today = new DateTime('today');
    $age = (int) $dob->diff($today)->y;
    if ($dob > $today || $age < 18 || $age > 80) {
        $missing[] = 'Date of Birth (applicants must be 18 to 80)';
    }
}
if (!isset(cuw_gender_options()[$gender])) {
    $missing[] = 'Gender';
}
if ($nationality === '' || mb_strlen($nationality) > 120) {
    $missing[] = 'Nationality';
}
if ($country === '' || mb_strlen($country) > 120) {
    $missing[] = 'Country of Residence';
}
if (!$email) {
    $missing[] = 'Email Address';
}
if ($phone_number === '' || strlen($phone_number) < 6) {
    $missing[] = 'Phone / WhatsApp Number';
}
if ($address === '' || mb_strlen($address) > 1000) {
    $missing[] = 'Current Address';
}
if ($howDidYouKnowUs === '' || mb_strlen($howDidYouKnowUs) > 200) {
    $missing[] = 'How did you know us';
}
if (!isset(cuw_position_options()[$position])) {
    $missing[] = 'Position';
}
if ($occupation === '' || mb_strlen($occupation) > 200) {
    $missing[] = 'Current Occupation';
}
if ($yearsRaw === '' || !is_numeric($yearsRaw)) {
    $missing[] = 'Years of Work Experience';
} else {
    $yearsNum = (float) $yearsRaw;
    if ($yearsNum < 0 || $yearsNum > 60) {
        $missing[] = 'Years of Work Experience';
    }
}
if (!isset(cuw_education_options()[$education])) {
    $missing[] = 'Highest Level of Education';
}
if ($experience === '' || mb_strlen($experience) > 4000) {
    $missing[] = 'Brief Description of Previous Work Experience';
}
if ($declaration !== '1') {
    $missing[] = 'Applicant Declaration';
}
if ($passport_file === '' || !cuw_validate_stored_path($passport_file)) {
    $missing[] = 'Passport Data Page';
}
if ($cv_file === '' || !cuw_validate_stored_path($cv_file)) {
    $missing[] = 'Updated Résumé / CV';
}
if ($photo_file === '' || !cuw_validate_stored_path($photo_file)) {
    $missing[] = 'Recent Passport-Sized Photograph';
}

if ($missing !== []) {
    cuw_json(false, 'Please complete the required fields below.', ['missing' => array_values(array_unique($missing))], 422);
}

$check = $conn->prepare('SELECT id FROM canada_workers_applications WHERE user_id = ? LIMIT 1');
if (!$check) {
    cuw_json(false, 'Database error', [], 500);
}
$check->bind_param('s', $user_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    cuw_json(false, 'An application for this session already exists.', [], 409);
}
$check->close();

$reference_id = 'CUW' . date('Y') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$nameParts = preg_split('/\s+/', $full_name, 2) ?: [$full_name];
$first_name = $nameParts[0] ?? $full_name;
$last_name = $nameParts[1] ?? '';
$emailStore = strtolower((string) $email);
$dobStore = $dob->format('Y-m-d');
$yearsStore = number_format((float) $yearsRaw, 1, '.', '');
$declarationAccepted = 1;

$sql = 'INSERT INTO canada_workers_applications (
    user_id, reference_id, full_name, first_name, last_name,
    date_of_birth, gender, nationality, country_of_residence,
    email, phone_area_code, phone_number, current_address, how_did_you_know_us,
    position, current_occupation, years_experience, education_level,
    work_experience_description, passport_file, cv_file, photo_file,
    declaration_accepted, status, created_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? , "pending", NOW())';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    cuw_json(false, 'Database error', [], 500);
}

$stmt->bind_param(
    'ssssssssssssssssssssssi',
    $user_id,
    $reference_id,
    $full_name,
    $first_name,
    $last_name,
    $dobStore,
    $gender,
    $nationality,
    $country,
    $emailStore,
    $phone_area_code,
    $phone_number,
    $address,
    $howDidYouKnowUs,
    $position,
    $occupation,
    $yearsStore,
    $education,
    $experience,
    $passport_file,
    $cv_file,
    $photo_file,
    $declarationAccepted
);

if (!$stmt->execute()) {
    $stmt->close();
    cuw_json(false, 'Could not save application', [], 500);
}
$stmt->close();

$row = [
    'user_id' => $user_id,
    'reference_id' => $reference_id,
    'full_name' => $full_name,
    'date_of_birth' => $dobStore,
    'gender' => $gender,
    'nationality' => $nationality,
    'country_of_residence' => $country,
    'email' => $emailStore,
    'phone_area_code' => $phone_area_code,
    'phone_number' => $phone_number,
    'current_address' => $address,
    'how_did_you_know_us' => $howDidYouKnowUs,
    'position' => $position,
    'current_occupation' => $occupation,
    'years_experience' => $yearsStore,
    'education_level' => $education,
    'work_experience_description' => $experience,
    'passport_file' => $passport_file,
    'cv_file' => $cv_file,
    'photo_file' => $photo_file,
];

$_SESSION['user_id'] = 'cuw_' . bin2hex(random_bytes(6)) . '_' . time();

try {
    cuw_notify_applicant_received($row);
} catch (Throwable $e) {
    error_log('CUW applicant notify failed [' . $reference_id . ']: ' . $e->getMessage());
}

cuw_json(true, 'Application Submitted Successfully', [
    'reference_id' => $reference_id,
    'user_id' => $user_id,
]);

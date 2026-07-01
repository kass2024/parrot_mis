<?php
/**
 * save_francophonie_mobility_request.php
 * Canada Francophonie Mobility (Mobilité Francophone) — candidate form save
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('FM_MOBILITY_FORM');
    session_start();
}

ob_start();
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function fm_json(bool $ok, string $message, array $extra = [], int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Francophonie mobility save: ' . $e->getMessage());
    fm_json(false, 'Server error: ' . $e->getMessage(), [], 500);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . ($err['message'] ?? 'unknown'),
        ], JSON_UNESCAPED_UNICODE);
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fm_json(false, 'Invalid request', [], 405);
}

$user_id = trim((string) ($_POST['user_id'] ?? ''));
if ($user_id === '') {
    fm_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $user_id;
} elseif ($user_id !== $_SESSION['user_id']) {
    fm_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/fm_email_worker.php';

fm_ensure_schema($conn);

$full_name = trim((string) ($_POST['full_name'] ?? ''));
$emailRaw  = trim((string) ($_POST['email'] ?? ''));
$email     = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);

$missing = [];
if ($full_name === '') {
    $missing[] = 'Full Name';
}
if (!$email) {
    $missing[] = 'Valid Email';
}

$rawAttachmentPaths = [
    trim((string) ($_POST['cv_file'] ?? '')),
    trim((string) ($_POST['french_cert_file'] ?? '')),
    trim((string) ($_POST['english_cert_file'] ?? '')),
];
$academicTemps = fm_collect_post_file_paths('academic_docs_file', 'academic_file');
$hasRawAttachment = array_filter($rawAttachmentPaths, static fn(string $p): bool => $p !== '') !== []
    || $academicTemps !== [];

if (!$hasRawAttachment) {
    $missing[] = 'At least one attachment (CV, certificate, or academic document)';
}

if ($missing !== []) {
    fm_json(false, 'Please complete the required fields below.', ['missing' => array_values(array_unique($missing))], 422);
}

/** @var string $email */
$email = strtolower($email);

$check = $conn->prepare('SELECT id FROM francophonie_mobility_applications WHERE user_id = ? OR LOWER(email) = ? LIMIT 1');
$check->bind_param('ss', $user_id, $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    fm_json(false, 'An application with this session or email already exists.', [], 409);
}
$check->close();

$reference_id = 'FM' . date('Y') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

$nameParts = preg_split('/\s+/', $full_name, 2);
$first_name = $nameParts[0] ?? $full_name;
$last_name = $nameParts[1] ?? '';

$ageRaw = trim((string) ($_POST['age'] ?? ''));
$age = $ageRaw !== '' && ctype_digit($ageRaw) ? (int) $ageRaw : 0;

$phone_area_code = trim((string) ($_POST['phone_area_code'] ?? ''));
$phone_number = trim((string) ($_POST['phone_number'] ?? ''));
if ($phone_number === '') {
    $phone_number = '0';
}

$nationality = trim((string) ($_POST['nationality'] ?? '')) ?: 'N/A';
$country_of_residence = trim((string) ($_POST['country_of_residence'] ?? '')) ?: 'N/A';
$profession = trim((string) ($_POST['profession'] ?? '')) ?: 'N/A';
$years_experience = trim((string) ($_POST['years_experience'] ?? '')) ?: '0';
$highest_degree = trim((string) ($_POST['highest_degree'] ?? '')) ?: 'N/A';
$field_of_study = trim((string) ($_POST['field_of_study'] ?? '')) ?: 'N/A';
$university_name = trim((string) ($_POST['university_name'] ?? '')) ?: 'N/A';
$country_of_study = trim((string) ($_POST['country_of_study'] ?? '')) ?: 'N/A';
$graduation_year = trim((string) ($_POST['graduation_year'] ?? '')) ?: '0000';
$other_certifications = trim((string) ($_POST['other_certifications'] ?? ''));

$levelChoices = ['beginner', 'intermediate', 'advanced', 'fluent'];
$yesNoChoices = ['yes', 'no'];

$french_level = strtolower(trim((string) ($_POST['french_level'] ?? '')));
if (!in_array($french_level, $levelChoices, true)) {
    $french_level = 'beginner';
}
$english_level = strtolower(trim((string) ($_POST['english_level'] ?? '')));
if (!in_array($english_level, $levelChoices, true)) {
    $english_level = 'beginner';
}
$french_professional = strtolower(trim((string) ($_POST['french_professional'] ?? '')));
if (!in_array($french_professional, $yesNoChoices, true)) {
    $french_professional = 'no';
}
$english_professional = strtolower(trim((string) ($_POST['english_professional'] ?? '')));
if (!in_array($english_professional, $yesNoChoices, true)) {
    $english_professional = 'no';
}
$has_wes = strtolower(trim((string) ($_POST['has_wes'] ?? '')));
if (!in_array($has_wes, $yesNoChoices, true)) {
    $has_wes = 'no';
}

$french_tef = !empty($_POST['french_tef']) ? 1 : 0;
$french_tcf = !empty($_POST['french_tcf']) ? 1 : 0;
$english_toefl = !empty($_POST['english_toefl']) ? 1 : 0;
$english_ielts = !empty($_POST['english_ielts']) ? 1 : 0;

$cv_file = fm_finalize_stored_path_optional((string) ($_POST['cv_file'] ?? ''), 'cv');
$french_cert_file = fm_finalize_stored_path_optional((string) ($_POST['french_cert_file'] ?? ''), 'french_cert');
$english_cert_file = fm_finalize_stored_path_optional((string) ($_POST['english_cert_file'] ?? ''), 'english_cert');

$academicStored = fm_finalize_upload_list($academicTemps, 'academic');
$academic_docs_file = fm_encode_stored_files($academicStored);

$hasAttachment = $cv_file !== '' || $french_cert_file !== '' || $english_cert_file !== '' || $academic_docs_file !== '';
if (!$hasAttachment) {
    fm_json(false, 'Please complete the required fields below.', [
        'missing' => ['At least one attachment (CV, certificate, or academic document)'],
    ], 422);
}

$conn->begin_transaction();

$sql = 'INSERT INTO francophonie_mobility_applications (
    user_id, reference_id, first_name, last_name, email,
    phone_area_code, phone_number, age, nationality, country_of_residence,
    profession, years_experience, highest_degree, field_of_study, university_name,
    country_of_study, graduation_year, other_certifications,
    french_level, french_tef, french_tcf, french_professional,
    english_level, english_toefl, english_ielts, english_professional,
    has_wes, cv_file, french_cert_file, english_cert_file, academic_docs_file,
    status, created_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "pending", NOW())';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->rollback();
    fm_json(false, 'Database error', [], 500);
}

$cv_db = $cv_file !== '' ? $cv_file : '';
$french_db = $french_cert_file !== '' ? $french_cert_file : '';
$english_db = $english_cert_file !== '' ? $english_cert_file : '';
$academic_db = $academic_docs_file !== '' ? $academic_docs_file : '';

$stmt->bind_param(
    'sssssssissssssssssisiississssss',
    $user_id, $reference_id, $first_name, $last_name, $email,
    $phone_area_code, $phone_number, $age, $nationality, $country_of_residence,
    $profession, $years_experience, $highest_degree, $field_of_study, $university_name,
    $country_of_study, $graduation_year, $other_certifications,
    $french_level, $french_tef, $french_tcf, $french_professional,
    $english_level, $english_toefl, $english_ielts, $english_professional,
    $has_wes, $cv_db, $french_db, $english_db, $academic_db
);

if (!$stmt->execute()) {
    $conn->rollback();
    fm_json(false, 'Failed to save application: ' . $stmt->error, [], 500);
}

$request_id = (int) $stmt->insert_id;
$stmt->close();
$conn->commit();

$emailToken = fm_dispatch_background_emails($request_id, $reference_id);

fm_json(true, 'Application submitted successfully', [
    'reference_id' => $reference_id,
    'request_id' => $request_id,
    'email_token' => $emailToken,
]);

<?php
declare(strict_types=1);

/**
 * save_noble_education_request.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('NEG_EDUCATION_FORM');
    session_start();
}

ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function neg_json(bool $ok, string $message, array $extra = [], int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Noble education save: ' . $e->getMessage());
    neg_json(false, 'Server error: ' . $e->getMessage(), [], 500);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    neg_json(false, 'Invalid request', [], 405);
}

$user_id = trim((string) ($_POST['user_id'] ?? ''));
if ($user_id === '') {
    neg_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $user_id;
} elseif ($user_id !== $_SESSION['user_id']) {
    neg_json(false, 'Session expired. Please refresh the page and try again.', [], 401);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/noble_education_schema.php';
require_once __DIR__ . '/helpers/noble_education_files.php';
require_once __DIR__ . '/helpers/noble_education_notify.php';

neg_ensure_schema($conn);

$full_name = trim((string) ($_POST['full_name'] ?? ''));
$emailRaw = trim((string) ($_POST['email'] ?? ''));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
$phone_area_code = preg_replace('/\D+/', '', (string) ($_POST['phone_area_code'] ?? '')) ?? '';
$phone_number = preg_replace('/\D+/', '', (string) ($_POST['phone_number'] ?? '')) ?? '';
$level_of_study = strtolower(trim((string) ($_POST['level_of_study'] ?? '')));
$program_of_interest = trim((string) ($_POST['program_of_interest'] ?? ''));
$agent_name = trim((string) ($_POST['agent_name'] ?? ''));

$passport_file = neg_normalize_rel_path((string) ($_POST['passport_file'] ?? ''));
$hs_file = neg_normalize_rel_path((string) ($_POST['high_school_certificate_file'] ?? ''));
$transcripts_file = neg_normalize_rel_path((string) ($_POST['transcripts_file'] ?? ''));

$otherRaw = trim((string) ($_POST['other_docs_json'] ?? ''));
$otherPaths = [];
if ($otherRaw !== '') {
    $decoded = json_decode($otherRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $p) {
            $rel = neg_normalize_rel_path((string) $p);
            if ($rel !== '' && neg_validate_stored_path($rel)) {
                $otherPaths[] = $rel;
            }
        }
    }
}
$otherPaths = array_values(array_unique($otherPaths));
$other_docs_json = $otherPaths !== [] ? json_encode($otherPaths, JSON_UNESCAPED_UNICODE) : null;

$allowedLevels = array_keys(neg_level_options());
$missing = [];
if ($full_name === '') {
    $missing[] = 'Full Name';
}
if (!$email) {
    $missing[] = 'Valid Email';
}
if ($phone_number === '') {
    $missing[] = 'Mobile Contact';
}
if (!in_array($level_of_study, $allowedLevels, true)) {
    $missing[] = 'Level of Study';
}
if ($program_of_interest === '') {
    $missing[] = 'Program of Interest';
}
if ($agent_name === '') {
    $missing[] = 'Agent Name';
}
if ($passport_file === '' || !neg_validate_stored_path($passport_file)) {
    $missing[] = 'Passport Scan';
}
if ($hs_file === '' || !neg_validate_stored_path($hs_file)) {
    $missing[] = 'High School Certificate';
}
if ($transcripts_file === '' || !neg_validate_stored_path($transcripts_file)) {
    $missing[] = 'Transcripts';
}

if ($missing !== []) {
    neg_json(false, 'Please complete the required fields below.', ['missing' => array_values(array_unique($missing))], 422);
}

$check = $conn->prepare('SELECT id FROM noble_education_applications WHERE user_id = ? LIMIT 1');
$check->bind_param('s', $user_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    neg_json(false, 'An application for this session already exists.', [], 409);
}
$check->close();

$reference_id = 'NEG' . date('Y') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$nameParts = preg_split('/\s+/', $full_name, 2) ?: [$full_name];
$first_name = $nameParts[0] ?? $full_name;
$last_name = $nameParts[1] ?? '';
$emailStore = strtolower((string) $email);

$sql = 'INSERT INTO noble_education_applications (
    user_id, reference_id, full_name, first_name, last_name, email,
    phone_area_code, phone_number, level_of_study, program_of_interest, agent_name,
    passport_file, high_school_certificate_file, transcripts_file, other_docs_json,
    status, created_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"pending",NOW())';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    neg_json(false, 'Database error', [], 500);
}

$stmt->bind_param(
    'sssssssssssssss',
    $user_id,
    $reference_id,
    $full_name,
    $first_name,
    $last_name,
    $emailStore,
    $phone_area_code,
    $phone_number,
    $level_of_study,
    $program_of_interest,
    $agent_name,
    $passport_file,
    $hs_file,
    $transcripts_file,
    $other_docs_json
);

if (!$stmt->execute()) {
    $stmt->close();
    neg_json(false, 'Could not save application', [], 500);
}
$stmt->close();

$row = [
    'reference_id' => $reference_id,
    'full_name' => $full_name,
    'email' => $emailStore,
    'level_of_study' => $level_of_study,
    'program_of_interest' => $program_of_interest,
];

$_SESSION['user_id'] = 'neg_' . bin2hex(random_bytes(6)) . '_' . time();

try {
    neg_notify_applicant_received($row);
} catch (Throwable $e) {
    error_log('NEG applicant notify failed [' . $reference_id . ']: ' . $e->getMessage());
}

neg_json(true, 'Application submitted successfully. A confirmation email will arrive shortly.', [
    'reference_id' => $reference_id,
    'user_id' => $user_id,
]);

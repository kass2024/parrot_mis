<?php
declare(strict_types=1);

ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('UTC');

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/job_admission_details.php';
require_once __DIR__ . '/helpers/attendance_checkout.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: private, no-store');

$respond = static function (string $message, int $code): void {
    http_response_code($code);
    echo $message;
    exit;
};

$adminId = (int) ($_SESSION['id'] ?? 0);
if ($adminId < 1 || !isset($_SESSION['role'])) {
    $respond('Sign in again to complete the job.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond('Submit the admission form to complete the job.', 405);
}
$csrf = $_SESSION['job_admission_csrf'] ?? '';
$token = $_POST['csrf_token'] ?? null;
if ($csrf === '' || !is_string($token) || !hash_equals($csrf, $token)) {
    $respond('Reload the form before submitting.', 403);
}

$jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$jobId) {
    $respond('Job not specified.', 400);
}
$superadmin = pcvc_current_user_is_superadmin($conn);
session_write_close();

$jobIsAvailable = static function (?array $job) use ($adminId, $superadmin, $respond): void {
    if ($job !== null && $job['status'] === 'completed' && ($superadmin || (int) $job['admin_id'] === $adminId)) {
        $respond('success', 200);
    }
    if (!pcvc_job_admission_can_edit($job, $adminId, $superadmin)) {
        $respond('This job is no longer open for editing.', 409);
    }
};
$jobIsAvailable(pcvc_job_admission_load($conn, $jobId));

$screenshot = $_FILES['screenshot'] ?? [];
if (($screenshot['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($screenshot['tmp_name'])) {
    $respond('Screenshot is required.', 422);
}
if ($screenshot['size'] > 5 * 1024 * 1024) {
    $respond('Screenshot is too large (maximum 5 MB).', 422);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($screenshot['tmp_name']);
if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
    $respond('Upload a PNG or JPG screenshot.', 422);
}

$uploadedFile = null;
$inTransaction = false;
try {
    $posted = pcvc_job_admission_validate_fields(array_intersect_key($_POST, pcvc_job_admission_fields()));
    pcvc_ensure_job_list_completed_at($conn);
    $conn->begin_transaction();
    $inTransaction = true;
    $job = pcvc_job_admission_load($conn, $jobId, true);
    if (!pcvc_job_admission_can_edit($job, $adminId, $superadmin)) {
        $conn->rollback();
        $jobIsAvailable($job);
    }

    $fields = array_replace(pcvc_job_admission_form($job), $posted);
    if ($fields['applicant_name'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter an applicant name and a valid email address.');
    }
    $applicationId = $fields['application_id'] !== '' ? $fields['application_id'] : 'APP-' . strtoupper(bin2hex(random_bytes(6)));
    $universityId = (int) $fields['university_id'];
    $universityName = '';
    if ($universityId > 0) {
        $stmt = $conn->prepare('SELECT name FROM universities WHERE id = ?');
        $stmt->bind_param('i', $universityId);
        $stmt->execute();
        $university = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$university) {
            throw new InvalidArgumentException('Select a valid university.');
        }
        $universityName = $university['name'];
    }

    $extension = $mime === 'image/png' ? 'png' : 'jpg';
    $path = 'uploads/job_' . $jobId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($screenshot['tmp_name'], __DIR__ . '/' . $path)) {
        throw new RuntimeException('Could not save screenshot.');
    }
    $uploadedFile = __DIR__ . '/' . $path;

    $stmt = $conn->prepare(
        'INSERT INTO student_app (applicant_name, application_id, email, platform, destination_country,
            university_id, phone_number, country, city, status_app, application_remarks, subagent, job_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param(
        'sssssissssssi',
        $fields['applicant_name'], $applicationId, $fields['email'], $fields['platform'], $fields['destination'],
        $universityId, $fields['phone_number'], $fields['country'], $fields['city'], $fields['status'],
        $fields['application_remarks'], $fields['subagent'], $jobId
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        'UPDATE job_list SET screenshot_path = ?, applicant_name = ?, applicant_email = ?, admission_draft = NULL WHERE id = ?'
    );
    $stmt->bind_param('sssi', $path, $fields['applicant_name'], $fields['email'], $jobId);
    $stmt->execute();
    $stmt->close();
    pcvc_job_list_mark_completed($conn, $jobId);
    $conn->commit();
} catch (Throwable $error) {
    if ($inTransaction) {
        $conn->rollback();
    }
    if ($uploadedFile !== null && is_file($uploadedFile)) {
        unlink($uploadedFile);
    }
    if ($error instanceof InvalidArgumentException) {
        $respond($error->getMessage(), 422);
    }
    if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
        $respond('That application ID is already in use.', 409);
    }
    error_log('Admission job completion failed: ' . $error->getCode());
    $respond('Could not complete the job. Try again.', 500);
}

try {
    $stmt = $conn->prepare('SELECT sheet_id FROM admins WHERE id = ?');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $sheetId = $stmt->get_result()->fetch_assoc()['sheet_id'] ?? null;
    $stmt->close();
    if ($sheetId) {
        require_once __DIR__ . '/vendor/autoload.php';
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/credentials.json');
        $client = new Google_Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);

        $service = new Google_Service_Sheets($client);
        $rows = $service->spreadsheets_values->get($sheetId, 'Sheet1!A:A')->getValues() ?? [];
        $row = max(count($rows) + 1, 3);
        $body = new Google_Service_Sheets_ValueRange(['values' => [[
            $row - 2, $fields['applicant_name'], '', $fields['email'], $fields['platform'], $job['job_type'],
            $fields['destination'], $universityName, $fields['phone_number'], $fields['country'], $fields['city'],
            $fields['status'], $fields['application_remarks'], $fields['subagent'], date('Y-m-d H:i:s'),
        ]]]);
        $service->spreadsheets_values->update($sheetId, "Sheet1!A{$row}:O{$row}", $body, ['valueInputOption' => 'RAW']);
    }
} catch (Throwable $error) {
    error_log('Google Sheets error: ' . $error->getMessage());
}

session_start();
$_SESSION['job_save_success'] = 'Student admission job was saved and marked completed.';
$respond('success', 200);

<?php
/**
 * submit-job.php
 * FINAL – FIXED & SOLID
 */

session_start();
require_once __DIR__ . '/db.php';
/* ================= CONFIG ================= */
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ================= AUTH ================= */
if (!isset($_SESSION['id'])) {
    http_response_code(403);
    exit("Access denied");
}

$admin_id = (int) $_SESSION['id'];
$today    = date('Y-m-d');

/* ================= INPUT ================= */
$job_id          = (int) ($_POST['job_id'] ?? 0);
$job_title       = trim($_POST['job_title'] ?? '');
$job_description = trim($_POST['job_description'] ?? '');
$ai_suggestions  = trim($_POST['ai_suggestions'] ?? '');

if ($job_id <= 0 || $job_title === '' || $job_description === '') {
    exit("Missing required data");
}

/* ================= TRANSACTION ================= */
$conn->begin_transaction();

try {

    /* ========= INSERT JOB (FIXED) ========= */
    $stmt = $conn->prepare("
        INSERT INTO jobs (
            admin_id,
            attendance_id,
            date,
            job_title,
            job_description,
            hours_spent,
            productivity_score,
            remarks,
            ai_suggestions,
            created_at
        ) VALUES (
            ?, NULL, ?, ?, ?, 0, 0, '', ?, NOW()
        )
    ");

    $stmt->bind_param(
        "issss",
        $admin_id,
        $today,
        $job_title,
        $job_description,
        $ai_suggestions
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $entry_id = $stmt->insert_id;
    $stmt->close();

    /* ========= MARK JOB COMPLETED ========= */
    $upd = $conn->prepare("
        UPDATE job_list
        SET status = 'completed'
        WHERE id = ?
    ");
    $upd->bind_param("i", $job_id);
    $upd->execute();
    $upd->close();

    /* ========= GOOGLE SHEETS (SAFE) ========= */
    try {
        require __DIR__ . '/vendor/autoload.php';
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/credentials.json');

        $client = new Google_Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);

        $service = new Google_Service_Sheets($client);

        $spreadsheetId = '1Bt9UirQs8RR7RxlzbZXEOO6XORPhu3OMJrMstmOz_GY';

        $values = [[
            $entry_id,
            $admin_id,
            $today,
            $job_title,
            $job_description,
            0,
            0,
            '',
            $ai_suggestions,
            date("Y-m-d H:i:s")
        ]];

        $body = new Google_Service_Sheets_ValueRange(['values' => $values]);

        $service->spreadsheets_values->append(
            $spreadsheetId,
            "Sheet1!A:J",
            $body,
            ['valueInputOption' => 'RAW']
        );

    } catch (Throwable $e) {
        error_log("Sheets error: " . $e->getMessage());
    }

    /* ========= COMMIT ========= */
    $conn->commit();

    header("Location: job_todo_list.php");
    exit;

} catch (Throwable $e) {

    $conn->rollback();
    error_log("JOB SAVE ERROR: " . $e->getMessage());
    exit("❌ Failed to save job.");
}

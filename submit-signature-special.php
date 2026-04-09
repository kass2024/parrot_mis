<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . "/vendor/autoload.php";

header("Content-Type: application/json");

/* =====================================================
   CONFIG
===================================================== */
$LOG_FILE = __DIR__ . "/logs/contract-signing.log";

/* =====================================================
   HELPERS
===================================================== */
function logMsg(string $msg, array $data = []): void {
    global $LOG_FILE;

    if (!is_dir(dirname($LOG_FILE))) {
        mkdir(dirname($LOG_FILE), 0777, true);
    }

    file_put_contents(
        $LOG_FILE,
        "[" . date("Y-m-d H:i:s") . "] {$msg} " . json_encode($data) . PHP_EOL,
        FILE_APPEND
    );
}

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function fail(string $message, int $code = 400, array $debug = []): void {
    logMsg("FAIL: {$message}", $debug);
    respond([
        "success" => false,
        "error"   => $message
    ], $code);
}

/* =====================================================
   1. READ INPUT
===================================================== */
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

logMsg("RAW INPUT", ["raw" => $raw]);

if (!is_array($data)) {
    fail("Invalid JSON payload", 400);
}

/* =====================================================
   2. EXTRACT DATA
===================================================== */
/* --- CORE --- */
$token      = trim($data['token'] ?? '');
$name       = trim($data['student_name'] ?? '');
$signedDate = trim($data['signed_date'] ?? '');
$signature  = $data['signature'] ?? '';

/* --- ARTICLE 7 PACKAGE --- */
$pkgLabel = trim($data['selected_package_label'] ?? '');
$pkgCode  = trim($data['selected_package_code'] ?? '');

/* --- STUDENT --- */
$email       = trim($data['student_email'] ?? '');
$dob         = $data['student_dob'] ?? null;
$nationality = trim($data['student_nationality'] ?? '');
$passport    = trim($data['student_passport'] ?? '');
$phone       = trim($data['student_phone'] ?? '');

/* =====================================================
   3. HARD VALIDATION
===================================================== */
if (
    $token === '' ||
    $name === '' ||
    $signedDate === '' ||
    $email === '' ||
    $signature === '' ||
    $pkgLabel === '' ||
    $pkgCode === ''
) {
    fail("Missing required fields", 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("Invalid email address", 400);
}

if (!str_starts_with($signature, 'data:image/png;base64,')) {
    fail("Invalid signature format", 400);
}

/* =====================================================
   4. LOAD CONTRACT (NO LOCK YET)
===================================================== */
$stmt = $conn->prepare("
    SELECT id, status
    FROM student_contracts_special
    WHERE contract_token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    fail("Contract not found", 404);
}

/* =====================================================
   5. ALREADY SIGNED = SUCCESS (IDEMPOTENT)
===================================================== */
if ($contract['status'] === 'signed') {
    respond([
        "success" => true,
        "status"  => "already_signed",
        "message" => "This contract has already been signed."
    ]);
}

$contractId = (int) $contract['id'];

/* =====================================================
   6. TRANSACTION
===================================================== */
$conn->begin_transaction();

try {

    /* =====================================================
       6.1 LOCK CONTRACT ROW
    ===================================================== */
    $stmt = $conn->prepare("
        SELECT id
        FROM student_contracts_special
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $stmt->close();

    logMsg("Contract locked", ["contract_id" => $contractId]);

    /* =====================================================
       6.2 UPSERT STUDENT (MANUAL OR AUTOFILL)
    ===================================================== */
    $stmt = $conn->prepare("
        SELECT id
        FROM student_applications
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $studentId = (int)$existing['id'];

        $stmt = $conn->prepare("
            UPDATE student_applications SET
                first_name      = ?,
                dob             = ?,
                nationality     = ?,
                passport_number = ?,
                phone_number    = ?,
                updated_at      = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param(
            "sssssi",
            $name,
            $dob,
            $nationality,
            $passport,
            $phone,
            $studentId
        );
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO student_applications
            (email, first_name, dob, nationality, passport_number, phone_number, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "ssssss",
            $email,
            $name,
            $dob,
            $nationality,
            $passport,
            $phone
        );
        $stmt->execute();
        $studentId = $stmt->insert_id;
        $stmt->close();
    }

    logMsg("Student saved", ["student_id" => $studentId]);

    /* =====================================================
       6.3 SAVE SIGNATURE
    ===================================================== */
    $stmt = $conn->prepare("
        INSERT INTO student_signatures_special
        (contract_id, student_name, student_email, signed_date, signature_image, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        "issss",
        $contractId,
        $name,
        $email,
        $signedDate,
        $signature
    );
    $stmt->execute();
    $stmt->close();

    /* =====================================================
       6.4 FINALIZE CONTRACT
    ===================================================== */
    $stmt = $conn->prepare("
        UPDATE student_contracts_special SET
            student_id             = ?,
            status                 = 'signed',
            signed_at              = NOW(),
            selected_package_code  = ?,
            selected_package_label = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "issi",
        $studentId,
        $pkgCode,
        $pkgLabel,
        $contractId
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    logMsg("Contract finalized", ["contract_id" => $contractId]);

} catch (Throwable $e) {
    $conn->rollback();
    fail("Signing failed", 500, [
        "message" => $e->getMessage(),
        "line"    => $e->getLine()
    ]);
}

/* =====================================================
   7. GENERATE PDF (POST-COMMIT)
===================================================== */
require_once __DIR__ . "/generate-contract-pdf-special.php";

if (!function_exists('generateContractPDF')) {
    fail("PDF generator missing", 500);
}

$pdfPath = generateContractPDF($contractId);

if (!$pdfPath || !file_exists($pdfPath)) {
    fail("PDF generation failed", 500);
}

/* =====================================================
   8. SAVE PDF PATH
===================================================== */
$stmt = $conn->prepare("
    UPDATE student_contracts_special
    SET pdf_path = ?
    WHERE id = ?
");
$stmt->bind_param("si", $pdfPath, $contractId);
$stmt->execute();
$stmt->close();

/* =====================================================
   9. FINAL RESPONSE (ALWAYS JSON)
===================================================== */
respond([
    "success"      => true,
    "status"       => "signed",
    "contract_id"  => $contractId,
    "student_id"   => $studentId,
    "pdf_path"     => $pdfPath
]);

<?php
/**
 * Send one email with multiple admission PDFs (one per selected university).
 * POST: student_id, table, email, university_id[] (parallel), letters[] (PDF files)
 */
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$id    = (int) ($_POST['student_id'] ?? 0);
$table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['table'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

$allowed_tables = ['student_applications', 'malta_applications', 'turkey_applications'];

if ($id < 1 || $table === '' || !in_array($table, $allowed_tables, true)) {
    exit('Invalid input');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit('Invalid email address');
}

$uniIds = isset($_POST['university_id']) ? (array) $_POST['university_id'] : [];
if (!isset($_FILES['letters'])) {
    exit('No admission files uploaded');
}

$files = $_FILES['letters'];
if (!is_array($files['name'] ?? null)) {
    exit('Invalid file upload format');
}

/** @var array<int, array{uid:int, name:string, path:string, attach:string}> */
$admissions = [];
$nInputs = max(count($uniIds), count($files['name']));

for ($i = 0; $i < $nInputs; $i++) {
    $uid = (int) ($uniIds[$i] ?? 0);
    $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

    if ($uid < 1 && $err === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    if ($uid < 1) {
        exit('Each admission letter needs a university selected.');
    }

    if ($err !== UPLOAD_ERR_OK) {
        exit('PDF upload failed (error code ' . $err . '). Try again or use a smaller file.');
    }

    $tmp = (string) ($files['tmp_name'][$i] ?? '');
    $origName = (string) ($files['name'][$i] ?? 'letter.pdf');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        exit('Invalid upload for one of the PDF files.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string) finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }
    if ($mime !== '' && stripos($mime, 'pdf') === false && $mime !== 'application/octet-stream') {
        exit('Only PDF files are allowed for admission letters.');
    }

    $uploadDir = __DIR__ . '/admission_letters/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $filename = uniqid('admission_', true) . '.pdf';
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($tmp, $filepath)) {
        exit('Failed to save one of the uploaded letters on the server.');
    }

    $stmtU = $conn->prepare('SELECT name FROM universities WHERE id = ? LIMIT 1');
    if (!$stmtU) {
        exit('Database error loading universities.');
    }
    $stmtU->bind_param('i', $uid);
    $stmtU->execute();
    $stmtU->bind_result($uniName);
    if (!$stmtU->fetch()) {
        $stmtU->close();
        @unlink($filepath);
        exit('Unknown university selected (ID ' . $uid . ').');
    }
    $stmtU->close();

    $safeBase = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $safeBase = trim(preg_replace('/_+/', '_', $safeBase), '_');
    if ($safeBase === '') {
        $safeBase = 'Admission';
    }
    $attachName = $safeBase . '_' . preg_replace('/[^\w\-]/', '_', $uniName) . '.pdf';
    if (strlen($attachName) > 180) {
        $attachName = substr($attachName, 0, 180) . '.pdf';
    }

    $admissions[] = [
        'uid' => $uid,
        'name' => $uniName,
        'path' => $filepath,
        'attach' => $attachName,
    ];
}

if (count($admissions) < 1) {
    exit('Add at least one university and attach its PDF letter.');
}

// Fetch student name and program
if ($table === 'student_applications') {
    $stmt = $conn->prepare('SELECT first_name, last_name, masters_program FROM student_applications WHERE id = ?');
} elseif ($table === 'malta_applications') {
    $stmt = $conn->prepare('SELECT name AS first_name, surname AS last_name, degree_program AS masters_program FROM malta_applications WHERE id = ?');
} else {
    $stmt = $conn->prepare('SELECT first_name, last_name, NULL AS masters_program FROM turkey_applications WHERE id = ?');
}

if (!$stmt) {
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    exit('Database error.');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($firstName, $lastName, $program);
if (!$stmt->fetch()) {
    $stmt->close();
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    exit('Application record not found.');
}
$stmt->close();

$studentName = trim($firstName . ' ' . $lastName);

// Update flags — set admit=1, reset others
$allFlags = [
    'incomplete_app', 'submitted', 'admit', 'i20_sent', 'sevis_paid',
    'visa_scheduled', 'visa_approved', 'enrolled', 'addn_doc', 'deny', 'app_start',
];
$resetFlags = implode(', ', array_map(static fn ($f) => "`$f` = 0", $allFlags));
$updateSQL = "UPDATE `$table` SET $resetFlags, `admit` = 1 WHERE id = ?";
$stmtUp = $conn->prepare($updateSQL);
if (!$stmtUp) {
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    exit('Database update failed (prepare).');
}
$stmtUp->bind_param('i', $id);
if (!$stmtUp->execute()) {
    $stmtUp->close();
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    exit('Database update failed.');
}
$stmtUp->close();

$uniListHtml = '';
foreach ($admissions as $a) {
    $uniListHtml .= '<li><strong>' . htmlspecialchars($a['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></li>';
}

$programHtml = $program
    ? '<p>Program context: <em>' . htmlspecialchars((string) $program, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</em></p>'
    : '';

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'visaconsultantcanada.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'admission@visaconsultantcanada.com';
    $mail->Password   = getenv('SMTP_PASSWORD') ?: 'Petero@1981';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->CharSet = 'UTF-8';
    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');
    $mail->addAddress($email, $studentName);

    $count = count($admissions);
    $subjectBase = $count > 1
        ? "Your admission letters ($count universities)"
        : ('Your admission letter — ' . $admissions[0]['name']);
    $mail->Subject = '=?UTF-8?B?' . base64_encode($subjectBase) . '?=';
    $mail->isHTML(true);

    $mail->Body = '
        <p>Dear ' . htmlspecialchars($studentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>
        <p>Congratulations! You have been <strong>admitted</strong> to the following institution(s):</p>
        <ul>' . $uniListHtml . '</ul>
        ' . $programHtml . '
        <p>Please find your official admission letter(s) attached to this email.</p>
        <p>If you have any questions, feel free to reach out.</p>
        <p>Warm regards,<br><strong>Parrot Canada Visa Consultant Team</strong></p>
    ';

    foreach ($admissions as $a) {
        $mail->addAttachment($a['path'], $a['attach']);
    }

    $mail->send();
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    echo 'ok';
} catch (Exception $e) {
    foreach ($admissions as $a) {
        @unlink($a['path']);
    }
    $info = isset($mail) && $mail instanceof PHPMailer ? $mail->ErrorInfo : $e->getMessage();
    error_log('Admission email failed: ' . $info);
    exit('mail_error');
}

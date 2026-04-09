<?php
require_once __DIR__ . '/db.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$id    = (int)$_POST['student_id'];
$table = $_POST['table'] ?? '';
$email = $_POST['email'] ?? '';

// ✅ Allow all 3 application tables
$allowed_tables = ['student_applications', 'malta_applications', 'turkey_applications'];

if (!$id || !$table || !$email || !in_array($table, $allowed_tables)) {
    exit('Invalid input');
}

// ✅ Validate uploaded letter
if (!isset($_FILES['letter']) || $_FILES['letter']['error'] !== 0) {
    exit('No letter uploaded');
}

$uploadDir = __DIR__ . '/admission_letters/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
$filename  = uniqid('admission_', true) . '.pdf';
$filepath  = $uploadDir . $filename;

if (!move_uploaded_file($_FILES['letter']['tmp_name'], $filepath)) {
    exit('Failed to save letter');
}

// ✅ Fetch student name and program
if ($table === 'student_applications') {
    $stmt = $conn->prepare("SELECT first_name, last_name, masters_program, university_id FROM student_applications WHERE id = ?");
} elseif ($table === 'malta_applications') {
    $stmt = $conn->prepare("SELECT name AS first_name, surname AS last_name, degree_program AS masters_program, 0 AS university_id FROM malta_applications WHERE id = ?");
} else { // turkey_applications
    $stmt = $conn->prepare("SELECT first_name, last_name, NULL AS masters_program, 0 AS university_id FROM turkey_applications WHERE id = ?");
}

$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($firstName, $lastName, $program, $universityId);
$stmt->fetch();
$stmt->close();

$studentName = trim($firstName . ' ' . $lastName);

// ✅ Fetch university name
$universityName = 'Your University';
if ($universityId > 0) {
    $stmt2 = $conn->prepare("SELECT name FROM universities WHERE id = ?");
    $stmt2->bind_param("i", $universityId);
    $stmt2->execute();
    $stmt2->bind_result($universityName);
    $stmt2->fetch();
    $stmt2->close();
} elseif ($table === 'malta_applications') {
    $universityName = 'International European University - Malta Campus';
} elseif ($table === 'turkey_applications') {
    $universityName = 'Your Selected Turkish University';
}

// ✅ Update flags — set admit=1, reset others
$allFlags = [
    'incomplete_app', 'submitted', 'admit', 'i20_sent', 'sevis_paid',
    'visa_scheduled', 'visa_approved', 'enrolled', 'addn_doc', 'deny', 'app_start'
];
$resetFlags = implode(', ', array_map(fn($f) => "`$f` = 0", $allFlags));
$updateSQL  = "UPDATE `$table` SET $resetFlags, `admit` = 1 WHERE id = ?";
$stmt = $conn->prepare($updateSQL);
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

// ✅ Send email using PHPMailer
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'visaconsultantcanada.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'admission@visaconsultantcanada.com';
    $mail->Password   = getenv('SMTP_PASSWORD') ?: 'Petero@1981'; // Fallback
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->CharSet    = 'UTF-8';
    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');
    $mail->addAddress($email, $studentName);
    $mail->Subject = "=?UTF-8?B?" . base64_encode("📄 Your Admission Letter from $universityName") . "?=";
    $mail->isHTML(true);

    $mail->Body = "
        <p>Dear $studentName,</p>
        <p>🎉 Congratulations! You have been <strong>admitted</strong> to <strong>$universityName</strong> " .
        ($program ? "for the <em>$program</em> program." : "to pursue your studies.") . "</p>
        <p>📎 Please find your official admission letter attached to this email.</p>
        <p>If you have any questions, feel free to reach out.</p>
        <p>Warm regards,<br><strong>Parrot Canada Visa Consultant Team</strong></p>
    ";

    $mail->addAttachment($filepath, "Admission_Letter.pdf");

    $mail->send();
    echo 'ok';
} catch (Exception $e) {
    error_log("Email sending failed: " . $mail->ErrorInfo);
    echo 'mail_error';
}

<?php
require_once __DIR__ . '/db.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$userId = $argv[1] ?? null;

if (!$userId) exit("Missing user ID.\n");

// Fetch data
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, current_program, proposed_program FROM credit_transfer_applications WHERE user_id = ?");
$stmt->bind_param("s", $userId);
$stmt->execute();
$stmt->bind_result($firstName, $middleName, $lastName, $email, $currentProgram, $proposedProgram);
$stmt->fetch();
$stmt->close();

$studentName = trim("$firstName $middleName $lastName");

// Send email
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'visaconsultantcanada.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@visaconsultantcanada.com';
    $mail->Password = getenv('SMTP_PASSWORD') ?: 'Petero@1981';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->setFrom('admission@visaconsultantcanada.com', 'Parrot Canada Visa Consultant');

    // Admin
    $mail->addAddress('admission@visaconsultantcanada.com');
    $mail->isHTML(true);
    $mail->Subject = "New Credit Transfer Request Received";
    $mail->Body = "<p>A new credit transfer request has been submitted.</p>
                   <p><strong>Student:</strong> $studentName<br>
                      <strong>Email:</strong> $email<br>
                      <strong>Current Program:</strong> $currentProgram<br>
                      <strong>Proposed Program:</strong> $proposedProgram<br>
                      <strong>User ID:</strong> $userId</p>";

    // Student
    if (!empty($email)) {
        $mail->addAddress($email, $studentName);
        $mail->Body .= "<hr><p>Dear $studentName,</p>
                        <p>We have received your credit transfer request and will contact you shortly.</p>
                        <p>Best regards,<br>Parrot Canada Visa Consultant Team</p>";
    }

    $mail->send();
} catch (Exception $e) {
    error_log("Email send failed for $userId: " . $e->getMessage());
}

$conn->close();

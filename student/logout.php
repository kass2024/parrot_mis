<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['student_account_id'], $_SESSION['student_application_id'], $_SESSION['student_email'], $_SESSION['student_name']);

header('Location: /parrot_mis/student-login.php');
exit;


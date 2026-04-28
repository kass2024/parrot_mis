<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['student_account_id'])) {
    header('Location: /parrot_mis/student-login.php');
    exit;
}


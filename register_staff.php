<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/staff_self_register.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

$csrf = (string) ($_SESSION['pcvc_reg_csrf'] ?? '');
$result = pcvc_self_register_staff($conn, $_POST, $csrf);
if (!empty($result['rotate_csrf'])) {
    $_SESSION['pcvc_reg_csrf'] = bin2hex(random_bytes(16));
}
$_SESSION['pcvc_reg_flash'] = $result;
header('Location: register.php');
exit;

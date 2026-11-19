<?php
session_start();
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/caq_applications.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('Method not allowed', false, 405);
}

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    jsonResponse('Unauthorized', false, 401);
}
$stmtRole = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
if (!$stmtRole) {
    jsonResponse('Server error', false, 500);
}
$stmtRole->bind_param('i', $adminId);
$stmtRole->execute();
$roleRow = $stmtRole->get_result()->fetch_assoc();
$stmtRole->close();
if (!$roleRow) {
    jsonResponse('Unauthorized', false, 401);
}
$dbRole = (string) ($roleRow['role'] ?? '');
$sessionRole = (string) ($_SESSION['role'] ?? '');
$allowed = xander_is_superadmin_role($dbRole)
    || xander_is_superadmin_role($sessionRole)
    || pcvc_is_staff_role($dbRole)
    || pcvc_is_staff_role($sessionRole)
    || strtolower(trim($dbRole)) === 'agent'
    || strtolower(trim($sessionRole)) === 'agent';
if (!$allowed) {
    jsonResponse('Forbidden', false, 403);
}

pcvc_caq_ensure_schema($conn);
$action = trim((string) ($_POST['action'] ?? ''));

if ($action === 'add') {
    $name = preg_replace('/\s+/u', ' ', trim((string) ($_POST['full_name'] ?? '')));
    if ($name === '' || mb_strlen($name) < 2) {
        jsonResponse('Enter the applicant name.', false, 400);
    }
    $form = trim((string) ($_POST['caq_form'] ?? ''));
    $signed = trim((string) ($_POST['signed_form'] ?? ''));
    $submitted = trim((string) ($_POST['submitted_status'] ?? ''));
    $received = trim((string) ($_POST['caq_received'] ?? ''));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $ircc = trim((string) ($_POST['ircc_account'] ?? ''));
    $docs = pcvc_caq_keys_to_docs((array) ($_POST['missing_docs'] ?? []));
    if (!array_key_exists($form, pcvc_caq_form_options())) {
        $form = '';
    }
    if (!array_key_exists($signed, pcvc_caq_signed_options())) {
        $signed = '';
    }
    if (!array_key_exists($submitted, pcvc_caq_submitted_options())) {
        $submitted = '';
    }
    if (!array_key_exists($received, pcvc_caq_received_options())) {
        $received = '';
    }
    if (!array_key_exists($ircc, pcvc_caq_ircc_options())) {
        $ircc = '';
    }
    $st = $conn->prepare('INSERT INTO caq_applications
        (full_name, caq_form, signed_form, submitted_status, caq_received, missing_docs, comment, ircc_account)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$st) {
        jsonResponse('Could not add the applicant.', false, 500);
    }
    $st->bind_param('ssssssss', $name, $form, $signed, $submitted, $received, $docs, $comment, $ircc);
    if (!$st->execute()) {
        $err = $st->errno === 1062 ? 'That name is already on the CAQ list.' : 'Could not add the applicant.';
        $st->close();
        jsonResponse($err, false, 400);
    }
    $id = (int) $st->insert_id;
    $st->close();
    jsonResponse(['id' => $id], true);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    jsonResponse('Invalid applicant.', false, 400);
}

if ($action === 'docs') {
    $docs = pcvc_caq_keys_to_docs((array) ($_POST['missing_docs'] ?? []));
    $st = $conn->prepare('UPDATE caq_applications SET missing_docs = ? WHERE id = ? LIMIT 1');
    if (!$st) {
        jsonResponse('Could not save documents.', false, 500);
    }
    $st->bind_param('si', $docs, $id);
    $st->execute();
    $st->close();
    jsonResponse(['missing_docs' => $docs], true);
}

if ($action === 'field') {
    $field = trim((string) ($_POST['field'] ?? ''));
    $value = trim((string) ($_POST['value'] ?? ''));
    $maps = [
        'caq_form' => pcvc_caq_form_options(),
        'signed_form' => pcvc_caq_signed_options(),
        'submitted_status' => pcvc_caq_submitted_options(),
        'caq_received' => pcvc_caq_received_options(),
        'ircc_account' => pcvc_caq_ircc_options(),
    ];
    if ($field === 'comment') {
        $value = mb_substr($value, 0, 255);
        $st = $conn->prepare('UPDATE caq_applications SET comment = ? WHERE id = ? LIMIT 1');
        if (!$st) {
            jsonResponse('Could not save.', false, 500);
        }
        $st->bind_param('si', $value, $id);
        $st->execute();
        $st->close();
        jsonResponse(['field' => $field, 'value' => $value], true);
    }
    if (!isset($maps[$field]) || !array_key_exists($value, $maps[$field])) {
        jsonResponse('Invalid value.', false, 400);
    }
    $sql = 'UPDATE caq_applications SET `' . $field . '` = ? WHERE id = ? LIMIT 1';
    $st = $conn->prepare($sql);
    if (!$st) {
        jsonResponse('Could not save.', false, 500);
    }
    $st->bind_param('si', $value, $id);
    $st->execute();
    $st->close();
    jsonResponse(['field' => $field, 'value' => $value], true);
}

jsonResponse('Unknown action.', false, 400);

<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_name('XGS_JOB_FORM');
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Session expired']);
    exit;
}

$user_id = $_SESSION['user_id'];

if (empty($_FILES['file']['name'])) {
    http_response_code(400);
    echo json_encode(['error'=>'No file']);
    exit;
}

$field = $_POST['field'] ?? 'unknown';

$allowed = ['pdf','jpg','jpeg','png','doc','docx'];
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid file type']);
    exit;
}

if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error'=>'File too large']);
    exit;
}

$base = __DIR__ . "/uploads/tmp/{$user_id}/";
@mkdir($base, 0755, true);

$filename = "{$field}_" . bin2hex(random_bytes(8)) . ".{$ext}";
$path = $base . $filename;

move_uploaded_file($_FILES['file']['tmp_name'], $path);

echo json_encode([
    'status' => 'success',
    'field'  => $field,
    'path'   => "uploads/tmp/{$user_id}/{$filename}"
]);

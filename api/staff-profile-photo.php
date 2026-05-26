<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/db.php';

if (!isset($_SESSION['id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied. Superadmin only.']);
    exit;
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
if ($staffId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid staff ID.']);
    exit;
}

if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No valid image uploaded.']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid image type. Use JPG, PNG, GIF, or WebP.']);
    exit;
}

if ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image too large (max 2MB).']);
    exit;
}

$stmt = $conn->prepare('SELECT profile_photo FROM admins WHERE id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
    exit;
}
$stmt->bind_param('i', $staffId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Staff member not found.']);
    exit;
}

$photoName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$target = $uploadDir . $photoName;

if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save image.']);
    exit;
}

$upd = $conn->prepare('UPDATE admins SET profile_photo = ? WHERE id = ?');
if (!$upd) {
    @unlink($target);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
    exit;
}
$upd->bind_param('si', $photoName, $staffId);

if (!$upd->execute()) {
    @unlink($target);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update profile photo.']);
    exit;
}
$upd->close();

$oldPhoto = trim((string) ($row['profile_photo'] ?? ''));
if ($oldPhoto !== '' && $oldPhoto !== 'default_avatar.png') {
    $oldPath = $uploadDir . basename($oldPhoto);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

echo json_encode([
    'ok' => true,
    'photo' => $photoName,
    'photo_url' => 'uploads/' . $photoName,
]);

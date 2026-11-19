<?php
require_once __DIR__ . '/db.php';

$type = (string) ($_POST['type'] ?? '');
$value = trim((string) ($_POST['value'] ?? ''));

if ($type === 'email') {
    $value = strtolower($value);
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        echo 'invalid';
        exit;
    }
    $stmt = $conn->prepare('SELECT id FROM admins WHERE email = ? OR username = ? LIMIT 1');
    if (!$stmt) {
        echo 'invalid';
        exit;
    }
    $stmt->bind_param('ss', $value, $value);
} elseif ($type === 'first_name') {
    $stmt = $conn->prepare('SELECT id FROM admins WHERE first_name = ? LIMIT 1');
    if (!$stmt) {
        echo 'invalid';
        exit;
    }
    $stmt->bind_param('s', $value);
} else {
    echo 'invalid';
    exit;
}

$stmt->execute();
$stmt->store_result();
echo $stmt->num_rows > 0 ? 'exists' : 'ok';
$stmt->close();
$conn->close();

<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$res = $conn->query("
    SELECT first_name, last_name, email
    FROM admins
    WHERE role = 'superadmin'
    ORDER BY id ASC
    LIMIT 1
");

if ($row = $res->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode([]);
}

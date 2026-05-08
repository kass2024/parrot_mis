<?php
require 'db.php'; // must define $conn = new mysqli(...)

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$search = "%{$q}%";

$sql = "
   SELECT 
    id,
    first_name,
    last_name,
    email,
    full_name
FROM admins
WHERE (
        full_name LIKE ?
     OR first_name LIKE ?
     OR last_name LIKE ?
     OR email LIKE ?
     OR username LIKE ?
  )
ORDER BY full_name ASC
LIMIT 50

";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([]);
    exit;
}

$stmt->bind_param("sssss", $search, $search, $search, $search, $search);
$stmt->execute();

$result = $stmt->get_result();
$agents = [];

while ($row = $result->fetch_assoc()) {
    $agents[] = $row;
}

$stmt->close();

header('Content-Type: application/json');
echo json_encode($agents);

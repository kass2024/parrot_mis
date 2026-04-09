<?php
require_once __DIR__ . '/db.php';
session_start();

$admin_id = $_SESSION['admin_id'];
$roleQuery = $conn->prepare("SELECT role FROM admins WHERE id=?");
$roleQuery->bind_param("i", $admin_id);
$roleQuery->execute();
$roleQuery->bind_result($role);
$roleQuery->fetch();
$roleQuery->close();

$filter = $_POST['filter'] ?? 'daily';
$date  = $_POST['date'] ?? date("Y-m-d");
$week  = $_POST['week'] ?? date("W");
$month = $_POST['month'] ?? date("Y-m");
$staff = $_POST['staff'] ?? '';

$sql = "SELECT a.full_name, att.*
        FROM attendance att
        JOIN admins a ON a.id = att.admin_id
        WHERE 1 ";

$params = [];
$bind = "";

// FILTER TYPES
if ($filter == "daily") {
    $sql .= " AND att.date = ? ";
    $params[] = $date;
    $bind .= "s";
}
if ($filter == "weekly") {
    $sql .= " AND WEEK(att.date,1)=? AND YEAR(att.date)=YEAR(NOW()) ";
    $params[] = $week;
    $bind .= "i";
}
if ($filter == "monthly") {
    $sql .= " AND DATE_FORMAT(att.date,'%Y-%m')=? ";
    $params[] = $month;
    $bind .= "s";
}

// STAFF FILTER
if ($role == "superadmin" && !empty($staff)) {
    $sql .= " AND a.full_name LIKE ? ";
    $params[] = "%$staff%";
    $bind .= "s";
}

// NORMAL ADMIN CAN ONLY SEE HIS
if ($role !== "superadmin") {
    $sql .= " AND a.id = ? ";
    $params[] = $admin_id;
    $bind .= "i";
}

$sql .= " ORDER BY att.date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($bind, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
$chartLabels = [];
$chartValues = [];
$totalMinutes = 0;
$totalSalary = 0;

while ($row = $result->fetch_assoc()) {
    $data[] = [
        "name" => $row['full_name'],
        "date" => $row['date'],
        "check_in" => $row['check_in_time'],
        "check_out" => $row['check_out_time'],
        "minutes" => $row['total_work_minutes'],
        "salary" => $row['daily_salary_rwf']
    ];

    $chartLabels[] = $row['date'];
    $chartValues[] = $row['total_work_minutes'];
    $totalMinutes += $row['total_work_minutes'];
    $totalSalary  += $row['daily_salary_rwf'];
}

$avgMinutes = count($data) ? round($totalMinutes / count($data)) : 0;

echo json_encode([
    "table" => $data,
    "kpi" => [
        "records" => count($data),
        "total_minutes" => $totalMinutes,
        "avg_minutes" => $avgMinutes,
        "total_salary" => number_format($totalSalary)
    ],
    "chart" => [
        "labels" => $chartLabels,
        "values" => $chartValues
    ]
]);

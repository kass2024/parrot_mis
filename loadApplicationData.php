<?php

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

/* =========================================
   GET APPLICATION ID
========================================= */

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid ID"
    ]);
    exit;
}

/* =========================================
   LOAD APPLICATION
========================================= */

$stmt = $conn->prepare("
    SELECT *
    FROM student_applications
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$application = $result->fetch_assoc();

$stmt->close();

if (!$application) {
    echo json_encode([
        "status" => "error",
        "message" => "Application not found"
    ]);
    exit;
}

/* =========================================
   LOAD STUDY CHOICES
========================================= */

$stmt = $conn->prepare("
SELECT
    sc.region_id,
    r.name AS region_name,

    sc.university_id,
    u.name AS university_name,

    sc.program_level_id,
    pl.name AS level_name,

    sc.program_id,
    p.program_name

FROM application_study_choices sc

JOIN regions r
    ON r.id = sc.region_id

JOIN universities u
    ON u.id = sc.university_id

JOIN program_levels pl
    ON pl.id = sc.program_level_id

JOIN programs p
    ON p.id = sc.program_id

WHERE sc.application_id = ?
ORDER BY sc.id ASC
");

$stmt->bind_param("i", $id);
$stmt->execute();

$res = $stmt->get_result();

$study_choices = [];

while ($row = $res->fetch_assoc()) {

    $study_choices[] = [
        "region_id" => (int)$row["region_id"],
        "region_name" => $row["region_name"],

        "university_id" => (int)$row["university_id"],
        "university_name" => $row["university_name"],

        "program_level_id" => (int)$row["program_level_id"],
        "level_name" => $row["level_name"],

        "program_id" => (int)$row["program_id"],
        "program_name" => $row["program_name"]
    ];
}

$stmt->close();

/* =========================================
   RETURN JSON
========================================= */

echo json_encode([
    "status" => "success",
    "application" => $application,
    "study_choices" => $study_choices
]);
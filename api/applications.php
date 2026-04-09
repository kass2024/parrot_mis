<?php
session_start();
require_once __DIR__ . '/../helpers/db.php';
require_once "../helpers/response.php";
require_once "../helpers/role.php";

$action = $_GET['action'] ?? '';

/**
 * ======================================================
 * DELETE APPLICATION (superadmin only)
 * ======================================================
 */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $adminId = 0;
    if (!empty($_SESSION['id'])) {
        $adminId = (int) $_SESSION['id'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $adminId = (int) $_SESSION['admin_id'];
    }
    if ($adminId <= 0) {
        jsonResponse("Unauthorized", false, 401);
    }

    $stmtRole = $conn->prepare("SELECT role FROM admins WHERE id = ? LIMIT 1");
    if (!$stmtRole) {
        jsonResponse("Server error", false, 500);
    }
    $stmtRole->bind_param("i", $adminId);
    $stmtRole->execute();
    $roleRow = $stmtRole->get_result()->fetch_assoc();
    $stmtRole->close();

    if (!$roleRow) {
        jsonResponse("Unauthorized", false, 401);
    }

    $dbRole = (string) ($roleRow['role'] ?? '');
    $sessionRole = (string) ($_SESSION['role'] ?? '');
    if (
        !pcvc_is_superadmin_role($dbRole)
        && !pcvc_is_superadmin_role($sessionRole)
    ) {
        jsonResponse("Forbidden", false, 403);
    }

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse("Invalid application id", false, 400);
    }

    /**
     * Force-delete application: clear all dependent rows (FK + application_id),
     * then parent. Uses INFORMATION_SCHEMA so new referencing tables do not
     * require code changes. FOREIGN_KEY_CHECKS disabled only for this operation.
     */
    $identOk = static function (string $name): bool {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
    };

    $conn->begin_transaction();

    try {
        if (!$conn->query("SET FOREIGN_KEY_CHECKS = 0")) {
            throw new Exception($conn->error);
        }

        $dbRes = $conn->query("SELECT DATABASE() AS dbname");
        $dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
        $schema = $dbRow['dbname'] ?? '';
        if ($schema === '') {
            throw new Exception("No database selected");
        }

        // 1) Every BASE TABLE column that formally references student_applications.id
        $fkSql = "
            SELECT DISTINCT k.TABLE_NAME, k.COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            INNER JOIN INFORMATION_SCHEMA.TABLES t
                ON k.TABLE_SCHEMA = t.TABLE_SCHEMA
                AND k.TABLE_NAME = t.TABLE_NAME
            WHERE k.TABLE_SCHEMA = ?
              AND k.REFERENCED_TABLE_NAME = 'student_applications'
              AND k.REFERENCED_COLUMN_NAME = 'id'
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND k.TABLE_NAME <> 'student_applications'
        ";
        $fkStmt = $conn->prepare($fkSql);
        if (!$fkStmt) {
            throw new Exception($conn->error);
        }
        $fkStmt->bind_param("s", $schema);
        $fkStmt->execute();
        $fkRows = $fkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fkStmt->close();

        foreach ($fkRows as $row) {
            $t = $row['TABLE_NAME'];
            $c = $row['COLUMN_NAME'];
            if (!$identOk($t) || !$identOk($c)) {
                continue;
            }
            $sqlDel = "DELETE FROM `{$t}` WHERE `{$c}` = " . $id;
            if (!$conn->query($sqlDel)) {
                throw new Exception($conn->error . " ({$t}.{$c})");
            }
        }

        // 2) Any BASE TABLE with an application_id column (logical refs without FK)
        $colSql = "
            SELECT DISTINCT c.TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS c
            INNER JOIN INFORMATION_SCHEMA.TABLES t
                ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                AND c.TABLE_NAME = t.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ?
              AND c.COLUMN_NAME = 'application_id'
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND c.TABLE_NAME <> 'student_applications'
        ";
        $colStmt = $conn->prepare($colSql);
        if (!$colStmt) {
            throw new Exception($conn->error);
        }
        $colStmt->bind_param("s", $schema);
        $colStmt->execute();
        $colRows = $colStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $colStmt->close();

        foreach ($colRows as $row) {
            $t = $row['TABLE_NAME'];
            if (!$identOk($t)) {
                continue;
            }
            $sqlDel = "DELETE FROM `{$t}` WHERE `application_id` = " . $id;
            if (!$conn->query($sqlDel)) {
                throw new Exception($conn->error . " ({$t}.application_id)");
            }
        }

        // 3) Parent row
        $st = $conn->prepare("DELETE FROM student_applications WHERE id = ? LIMIT 1");
        if (!$st) {
            throw new Exception($conn->error);
        }
        $st->bind_param("i", $id);
        if (!$st->execute()) {
            throw new Exception($st->error);
        }
        if ($st->affected_rows === 0) {
            $st->close();
            throw new Exception("NOT_FOUND");
        }
        $st->close();

        if (!$conn->query("SET FOREIGN_KEY_CHECKS = 1")) {
            throw new Exception($conn->error);
        }

        $conn->commit();
        jsonResponse(["deleted" => true, "id" => $id]);
    } catch (Throwable $e) {
        $conn->rollback();
        @ $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        if ($e->getMessage() === "NOT_FOUND") {
            jsonResponse("Application not found", false, 404);
        }
        error_log("applications force delete failed: " . $e->getMessage());
        jsonResponse("Delete failed", false, 500);
    }

    exit;
}

/**
 * ======================================================
 * LIST APPLICATIONS (SIDEBAR)
 * ======================================================
 */
if ($action === 'list') {

    $where  = [];
$types  = "";
$values = [];

/**
 * ======================================================
 * GLOBAL SEARCH (Student + University + Region + Country)
 * ======================================================
 */
if (!empty($_GET['q'])) {
    $q = '%' . trim($_GET['q']) . '%';

    $where[] = "(
        sa.first_name LIKE ?
        OR sa.last_name LIKE ?
        OR sa.email LIKE ?
        OR u.name LIKE ?
        OR r.name LIKE ?
        OR c.name LIKE ?
    )";

    $types  .= "ssssss";
    $values = array_merge($values, [$q, $q, $q, $q, $q, $q]);
}

/**
 * ======================================================
 * FILTERS (Exact Match)
 * ======================================================
 */
if (!empty($_GET['region_id'])) {
    $where[] = "ascx.region_id = ?";
    $types  .= "i";
    $values[] = (int)$_GET['region_id'];
}

if (!empty($_GET['university_id'])) {
    $where[] = "ascx.university_id = ?";
    $types  .= "i";
    $values[] = (int)$_GET['university_id'];
}

if (!empty($_GET['program_level_id'])) {
    $where[] = "ascx.program_level_id = ?";
    $types  .= "i";
    $values[] = (int)$_GET['program_level_id'];
}

    /* ======================================================
       REQUIRE AT LEAST ONE UPLOADED DOCUMENT
       (HIDE EMPTY APPLICATIONS FROM SIDEBAR)
    ====================================================== */
    $where[] = "(
        (sa.degree_transcripts IS NOT NULL AND sa.degree_transcripts != '[]')
        OR sa.high_school_degree IS NOT NULL
        OR sa.valid_passport IS NOT NULL
        OR sa.cv_resume IS NOT NULL
        OR sa.personal_statement IS NOT NULL
        OR sa.recommendation_letters IS NOT NULL
        OR sa.english_certificate IS NOT NULL
        OR sa.birth_certificate IS NOT NULL
        OR sa.payment_proof IS NOT NULL
    )";

$sql = "
    SELECT DISTINCT
        sa.id,
        sa.application_id,
        sa.first_name,
        sa.last_name,
        sa.email,
        sa.phone_number,
        sa.created_at,
        sa.is_read,

    -- aggregated sidebar info (ONE row per application)
GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ' · ') AS universities,
GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ' · ') AS regions,
GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ' · ') AS countries


    FROM student_applications sa

    LEFT JOIN application_study_choices ascx
        ON ascx.application_id = sa.id

    LEFT JOIN universities u
        ON u.id = ascx.university_id

    LEFT JOIN regions r
        ON r.id = ascx.region_id

    LEFT JOIN countries c
        ON c.id = u.country_id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    GROUP BY sa.id
    ORDER BY sa.created_at DESC
";


    $stmt = $conn->prepare($sql);
    if ($values) {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();

   $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$data = array_map(function ($r) {
    return [
        "id" => (int)$r["id"],
        "application_id" => $r["application_id"],

        "bio" => [
            "first_name" => $r["first_name"],
            "last_name"  => $r["last_name"],
            "email"      => $r["email"]
        ],

       "study" => [
    "universities" => $r["universities"] ?: null,
    "regions"      => $r["regions"] ?: null,
    "countries"    => $r["countries"] ?: null
],


        "meta" => [
            "created_at" => $r["created_at"],
            "is_read"    => (bool)$r["is_read"]
        ]
    ];
}, $rows);

jsonResponse($data);
exit;



}

/**
 * ======================================================
 * VIEW FULL APPLICATION (ALL TABLES)
 * ======================================================
 */
if ($action === 'view' && !empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    // mark as read
    $stmt = $conn->prepare("UPDATE student_applications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
/**
 * ==================================================
 * AUTO CREATE JOBS (ONE UNIVERSITY = ONE JOB)
 * ==================================================
 */

// 1️⃣ Load applicant basic info (we already need this later anyway)
$applicantName  = '';
$applicantEmail = '';

$stmt = $conn->prepare("
    SELECT first_name, last_name, email
    FROM student_applications
    WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$basic = $stmt->get_result()->fetch_assoc();

if ($basic) {
    $applicantName  = trim($basic['first_name'] . ' ' . $basic['last_name']);
    $applicantEmail = $basic['email'];
}

// 2️⃣ Load STUDY CHOICES (each university = one job)
$stmt = $conn->prepare("
    SELECT DISTINCT
        ascx.university_id,
        u.name AS university_name,
        c.name AS country_name
    FROM application_study_choices ascx
    JOIN universities u ON u.id = ascx.university_id
    LEFT JOIN countries c ON c.id = u.country_id
    WHERE ascx.application_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$studyChoicesForJobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3️⃣ Prepare job statements
$checkJob = $conn->prepare("
    SELECT id
    FROM job_list
    WHERE
        application_id = ?
        AND university_id = ?
        AND admin_id = ?
        AND platform_id = ?
        AND is_auto_created = 1
    LIMIT 1
");


$insertJob = $conn->prepare("
    INSERT INTO job_list
        (
            admin_id,
            application_id,
            university_id,
            platform_id,
            title,
            applicant_name,
            applicant_email,
            job_type,
            status,
            is_auto_created
        )
    VALUES
        (?, ?, ?, ?, ?, ?, ?, 'Student Admission Application', 'not_completed', 1)
");


// 4️⃣ Loop: ONE UNIVERSITY = ONE JOB
$jobsCreated = 0;

foreach ($studyChoicesForJobs as $choice) {

    /**
     * ==================================================
     * 1️⃣ LOAD ALL PLATFORMS / ADMINS FOR THIS UNIVERSITY
     * ==================================================
     */
    $stmt = $conn->prepare("
        SELECT
            a.id AS admin_id,
            p.id AS platform_id
        FROM university_platforms up
        JOIN platforms p ON p.id = up.platform_id
        JOIN admins a ON a.id = p.person_in_charge
        WHERE up.university_id = ?
          AND p.status = 'Active'
        ORDER BY up.is_preferred DESC, p.id ASC
    ");
    $stmt->bind_param("i", $choice['university_id']);
    $stmt->execute();
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$admins) {
        continue; // no platforms → no jobs
    }

    /**
     * ==================================================
     * 2️⃣ CREATE ONE JOB PER ADMIN / PLATFORM
     * ==================================================
     */
    foreach ($admins as $admin) {

        $adminId    = (int)$admin['admin_id'];
        $platformId = (int)$admin['platform_id'];

        // unique job title
        $jobTitle = sprintf(
            "Application #%d – %s (%s)",
            $id,
            $choice['university_name'],
            $choice['country_name'] ?? 'Unknown'
        );

        /**
         * ==================================================
         * 3️⃣ PREVENT DUPLICATES (STRICT)
         * ==================================================
         */
        $checkJob->bind_param(
            "iiii",
            $id,                           // application_id
            $choice['university_id'],
            $adminId,
            $platformId
        );
        $checkJob->execute();
        $checkJob->store_result();

        if ($checkJob->num_rows > 0) {
            continue; // exact job already exists
        }

        /**
         * ==================================================
         * 4️⃣ INSERT JOB
         * ==================================================
         */
        $insertJob->bind_param(
            "iiiisss",
            $adminId,
            $id,
            $choice['university_id'],
            $platformId,
            $jobTitle,
            $applicantName,
            $applicantEmail
        );

        $insertJob->execute();
        $jobsCreated++;
    }
}

    /**
     * ==================================================
     * MAIN APPLICATION (SAFE COUNTRY RESOLUTION)
     * ==================================================
     */
    $stmt = $conn->prepare("
        SELECT
            sa.*,

            -- SAFE: works if stored as name OR id
            COALESCE(cb.name, sa.country_of_birth)   AS birth_country_name,
            COALESCE(nat.name, sa.nationality)       AS nationality_name,
            COALESCE(pic.name, sa.previous_institution_country) AS prev_country_name

        FROM student_applications sa
        LEFT JOIN countries cb
            ON cb.id = sa.country_of_birth OR cb.name = sa.country_of_birth
        LEFT JOIN countries nat
            ON nat.id = sa.nationality OR nat.name = sa.nationality
        LEFT JOIN countries pic
            ON pic.id = sa.previous_institution_country
            OR pic.name = sa.previous_institution_country
        WHERE sa.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        jsonResponse("Application not found", false, 404);
    }

    /**
     * ==================================================
     * STUDY CHOICES
     * ==================================================
     */
    $stmt = $conn->prepare("
        SELECT
            r.name  AS region,
            u.name  AS university,
            c.name  AS university_country,
            pl.name AS program_level,
            pl.abbreviation AS program_level_abbr,
            p.program_name AS program
        FROM application_study_choices ascx
        JOIN universities u    ON u.id = ascx.university_id
        JOIN regions r         ON r.id = ascx.region_id
        JOIN program_levels pl ON pl.id = ascx.program_level_id
        JOIN programs p        ON p.id = ascx.program_id
        LEFT JOIN countries c  ON c.id = u.country_id
        WHERE ascx.application_id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $studyChoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /**
     * ==================================================
     * DOCUMENTS
     * ==================================================
     */
    $documents = [
        "degree_transcripts"     => json_decode($app['degree_transcripts'] ?? "[]", true),
        "high_school_degree"     => $app['high_school_degree'],
        "passport"               => $app['valid_passport'],
        "cv_resume"              => $app['cv_resume'],
        "personal_statement"     => $app['personal_statement'],
        "recommendation_letters" => $app['recommendation_letters'],
        "english_certificate"    => $app['english_certificate'],
        "birth_certificate"      => $app['birth_certificate'],
        "payment_proof"          => $app['payment_proof']
    ];

    /**
     * ==================================================
     * SUPERADMIN DELETE (same rules as action=delete)
     * ==================================================
     */
    $canDeleteApplicationMeta = false;
    $adminId = 0;
    if (!empty($_SESSION['id'])) {
        $adminId = (int) $_SESSION['id'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $adminId = (int) $_SESSION['admin_id'];
    }
    if ($adminId > 0) {
            $stmtRole = $conn->prepare("SELECT role FROM admins WHERE id = ? LIMIT 1");
            if ($stmtRole) {
                $stmtRole->bind_param("i", $adminId);
                $stmtRole->execute();
                $roleRow = $stmtRole->get_result()->fetch_assoc();
                $stmtRole->close();
                $dbRole = $roleRow ? (string) ($roleRow['role'] ?? '') : '';
                $sessionRole = (string) ($_SESSION['role'] ?? '');
                $canDeleteApplicationMeta =
                    pcvc_is_superadmin_role($dbRole)
                    || pcvc_is_superadmin_role($sessionRole);
            }
    }

    /**
     * ==================================================
     * FINAL RESPONSE (JS SAFE)
     * ==================================================
     */
    jsonResponse([
        "bio" => [
            "first_name" => $app['first_name'],
            "last_name"  => $app['last_name'],
            "gender"     => $app['gender'],
            "dob"        => $app['dob'],
            "email"      => $app['email'],
            "phone"      => trim($app['area_code'] . " " . $app['phone_number']),
            "nationality"=> $app['nationality_name'],
            "country_of_birth" => $app['birth_country_name'],
            "passport_number"  => $app['passport_number'],
            "national_id"      => $app['student_national_id']
        ],

        "address" => [
            "line1" => $app['address_line1'],
            "line2" => $app['address_line2'],
            "city"  => $app['city'],
            "state" => $app['state_province'],
            "postal_code" => $app['postal_code']
        ],

        "parents" => [
            "father" => trim($app['father_first_name'] . " " . $app['father_last_name']),
            "mother" => trim($app['mother_first_name'] . " " . $app['mother_last_name'])
        ],

        "emergency" => [
            "name"  => trim($app['emergency_first_name'] . " " . $app['emergency_last_name']),
            "email" => $app['emergency_email'],
            "phone" => trim($app['emergency_area_code'] . " " . $app['emergency_phone_number']),
            "relationship" => $app['emergency_relationship']
        ],

        "education" => [
            "institution" => $app['previous_institution_name'],
            "city"        => $app['previous_institution_city'],
            "country"     => $app['prev_country_name'],
            "start_date"  => $app['previous_study_start'],
            "graduation"  => $app['previous_study_graduation'],
            "language"    => $app['language_of_instruction'],
            "study_gap"   => $app['study_gap'],
            "study_gap_details" => $app['study_gap_details']
        ],

        "study_choices" => $studyChoices,
        "agent" => [
            "name"  => trim($app['agent_first_name'] . " " . $app['agent_last_name']),
            "email" => $app['agent_email']
        ],
        "documents" => $documents,

        "status" => [
            "submitted" => (bool)$app['submitted'],
            "admit"     => (bool)$app['admit'],
            "visa_scheduled" => (bool)$app['visa_scheduled'],
            "visa_approved"  => (bool)$app['visa_approved'],
            "enrolled" => (bool)$app['enrolled'],
            "denied"   => (bool)$app['deny'],
            "paid"     => (bool)$app['app_paid']
        ],

     "meta" => [
    "application_id" => $app['application_id'],
    "created_at" => $app['created_at'],
    "updated_at" => $app['updated_at'],
    "is_read"    => 1,
    "jobs_created" => $jobsCreated, // 👈 ADD THIS
    "can_delete_application" => $canDeleteApplicationMeta
]

    ]);
}
/**
 * ======================================================
 * APPLICATION JOURNEY (TRACK EVERYTHING)
 * ======================================================
 */
if ($action === 'journey' && !empty($_GET['id'])) {

    $applicationId = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT
            jl.id,
            jl.status,
            jl.created_at,
            jl.is_auto_created,

            u.name AS university_name,
            p.platform_name,
            a.full_name AS admin_name

        FROM job_list jl

        LEFT JOIN universities u
            ON u.id = jl.university_id

        LEFT JOIN platforms p
            ON p.id = jl.platform_id

        LEFT JOIN admins a
            ON a.id = jl.admin_id

        WHERE jl.application_id = ?
        ORDER BY jl.created_at ASC
    ");

    $stmt->bind_param("i", $applicationId);
    $stmt->execute();

    $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    jsonResponse($jobs);
    exit;
}
/**
 * ======================================================
 * UNIVERSITY → KOREA CHECK (AUTHORITATIVE)
 * South Korea country_id = 61
 * ======================================================
 */
if ($action === 'university_country' && !empty($_GET['university_id'])) {

    $universityId = (int) $_GET['university_id'];

    $stmt = $conn->prepare("
        SELECT
            u.country_id
        FROM universities u
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $universityId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $countryId = isset($row['country_id']) ? (int) $row['country_id'] : null;

    jsonResponse([
        "country_id" => $countryId,
        "is_korea"   => ($countryId === 61)
    ]);

    exit;
}


/**
 * ======================================================
 * FALLBACK
 * ======================================================
 */
jsonResponse("Invalid request", false, 400);

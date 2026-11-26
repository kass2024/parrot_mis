<?php
declare(strict_types=1);

require_once __DIR__ . '/form17_application_status.php';

/**
 * Self-registration (post-payment) private-link applications.
 */

function pcvc_self_reg_upload_root(): string
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'self_registration';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );
    }
    return $root;
}

function pcvc_self_reg_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `self_registrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token` CHAR(64) NOT NULL,
            `status` ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
            `full_name` VARCHAR(255) NOT NULL DEFAULT '',
            `date_of_birth` DATE NULL,
            `nationality` VARCHAR(120) NOT NULL DEFAULT '',
            `country_of_residence` VARCHAR(120) NOT NULL DEFAULT '',
            `destination_country` VARCHAR(120) NOT NULL DEFAULT '',
            `whatsapp` VARCHAR(80) NOT NULL DEFAULT '',
            `email` VARCHAR(190) NOT NULL DEFAULT '',
            `preferred_level` VARCHAR(160) NOT NULL DEFAULT '',
            `field_choice_1` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_2` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_3` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_4` VARCHAR(200) NOT NULL DEFAULT '',
            `field_choice_5` VARCHAR(200) NOT NULL DEFAULT '',
            `other_field_specify` VARCHAR(255) NOT NULL DEFAULT '',
            `highest_education` VARCHAR(160) NOT NULL DEFAULT '',
            `previous_field` VARCHAR(200) NOT NULL DEFAULT '',
            `graduation_year` VARCHAR(20) NOT NULL DEFAULT '',
            `final_grade` VARCHAR(80) NOT NULL DEFAULT '',
            `preferred_language` VARCHAR(80) NOT NULL DEFAULT '',
            `preferred_intake` VARCHAR(160) NOT NULL DEFAULT '',
            `preferred_institution` VARCHAR(200) NOT NULL DEFAULT '',
            `preferred_province` VARCHAR(120) NOT NULL DEFAULT '',
            `sponsor` VARCHAR(120) NOT NULL DEFAULT '',
            `ready_to_pay` VARCHAR(10) NOT NULL DEFAULT '',
            `visa_refused` VARCHAR(10) NOT NULL DEFAULT '',
            `travel_with_family` VARCHAR(10) NOT NULL DEFAULT '',
            `referring_agent` VARCHAR(200) NOT NULL DEFAULT '',
            `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by_admin_id` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `submitted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_self_reg_token` (`token`),
            KEY `idx_self_reg_status` (`status`),
            KEY `idx_self_reg_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `self_registration_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `registration_id` INT UNSIGNED NOT NULL,
            `doc_key` VARCHAR(64) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL DEFAULT '',
            `stored_name` VARCHAR(255) NOT NULL DEFAULT '',
            `mime` VARCHAR(120) NOT NULL DEFAULT '',
            `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_self_reg_doc` (`registration_id`, `doc_key`),
            KEY `idx_self_reg_doc_reg` (`registration_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Workflow columns (safe to re-run)
    $cols = [
        'source' => "ALTER TABLE `self_registrations` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'link' AFTER `status`",
        'followup_status' => "ALTER TABLE `self_registrations` ADD COLUMN `followup_status` VARCHAR(40) NOT NULL DEFAULT 'new' AFTER `source`",
        'assigned_to_admin_id' => "ALTER TABLE `self_registrations` ADD COLUMN `assigned_to_admin_id` INT NULL AFTER `created_by_admin_id`",
        'assigned_by_admin_id' => "ALTER TABLE `self_registrations` ADD COLUMN `assigned_by_admin_id` INT NULL AFTER `assigned_to_admin_id`",
        'assigned_at' => "ALTER TABLE `self_registrations` ADD COLUMN `assigned_at` DATETIME NULL AFTER `assigned_by_admin_id`",
        'followup_notes' => "ALTER TABLE `self_registrations` ADD COLUMN `followup_notes` TEXT NULL AFTER `assigned_at`",
        'referred_by_admin_id' => "ALTER TABLE `self_registrations` ADD COLUMN `referred_by_admin_id` INT NULL AFTER `followup_notes`",
    ];
    foreach ($cols as $name => $sql) {
        $chk = $conn->query("SHOW COLUMNS FROM `self_registrations` LIKE '{$name}'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($sql);
        }
        if ($chk instanceof mysqli_result) {
            $chk->free();
        }
    }
}

/**
 * Display name for staff / agent / company account used on referral links.
 */
function pcvc_self_reg_admin_label(mysqli $conn, int $adminId): string
{
    if ($adminId <= 0) {
        return '';
    }
    $stmt = $conn->prepare(
        'SELECT full_name, username, role, email FROM admins WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return '';
    }
    $name = trim((string) ($row['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['username'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($row['email'] ?? ''));
    }
    $role = trim((string) ($row['role'] ?? ''));
    if ($name !== '' && $role !== '' && stripos($name, $role) === false) {
        // Keep clean name only for the student-facing field.
        return $name;
    }
    return $name;
}

/** Stable per-account student link (same URL every time for that account). */
function pcvc_self_reg_referral_url(int $adminId): string
{
    $base = pcvc_self_reg_fixed_public_url();
    if ($adminId <= 0) {
        return $base;
    }
    return $base . '?ref=' . $adminId;
}

/** @return array<string, string> */
function pcvc_self_reg_followup_statuses(): array
{
    return [
        'new' => 'New',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'contacted' => 'Contacted',
        'waiting_documents' => 'Waiting documents',
        'under_review' => 'Under review',
        'offer_received' => 'Offer received',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];
}

function pcvc_self_reg_is_valid_followup_status(string $key): bool
{
    return array_key_exists($key, pcvc_self_reg_followup_statuses());
}

/**
 * Staff + agents available for transfer.
 *
 * @return list<array{id:int,full_name:string,role:string}>
 */
function pcvc_self_reg_assignable_users(mysqli $conn): array
{
    $rows = [];
    $sql = "SELECT id, full_name, username, role
            FROM admins
            WHERE REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(role,''))),' ',''),'_',''),'-','')
                  IN ('staff','staffadmin','accountant','admission_manager','admissionmanager','agent')
            ORDER BY full_name ASC, username ASC";
    $res = $conn->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetch_assoc()) {
        $name = trim((string) ($row['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['username'] ?? '')) ?: ('User #' . (int) $row['id']);
        }
        $rows[] = [
            'id' => (int) $row['id'],
            'full_name' => $name,
            'role' => (string) ($row['role'] ?? ''),
        ];
    }
    return $rows;
}

/** @return array{total:int,draft:int,submitted:int,unassigned:int,assigned:int,in_progress:int,waiting_documents:int,closed:int} */
function pcvc_self_reg_kpis(mysqli $conn, ?int $scopeAdminId = null): array
{
    pcvc_self_reg_ensure_schema($conn);
    $kpi = [
        'total' => 0,
        'draft' => 0,
        'submitted' => 0,
        'unassigned' => 0,
        'assigned' => 0,
        'in_progress' => 0,
        'waiting_documents' => 0,
        'closed' => 0,
    ];
    $scope = ($scopeAdminId !== null && $scopeAdminId > 0) ? $scopeAdminId : 0;
    if ($scope > 0) {
        $stmt = $conn->prepare(
            "SELECT
                SUM(status = 'submitted') AS total,
                SUM(status = 'draft') AS draft,
                SUM(status = 'submitted') AS submitted,
                SUM(status = 'submitted' AND (assigned_to_admin_id IS NULL OR assigned_to_admin_id = 0)) AS unassigned,
                SUM(status = 'submitted' AND assigned_to_admin_id IS NOT NULL AND assigned_to_admin_id > 0) AS assigned,
                SUM(status = 'submitted' AND followup_status = 'in_progress') AS in_progress,
                SUM(status = 'submitted' AND followup_status = 'waiting_documents') AS waiting_documents,
                SUM(status = 'submitted' AND followup_status IN ('closed','cancelled')) AS closed
             FROM self_registrations
             WHERE assigned_to_admin_id = ? OR referred_by_admin_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $scope, $scope);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($row) {
                foreach ($kpi as $k => $_) {
                    $kpi[$k] = (int) ($row[$k] ?? 0);
                }
            }
        }
        return $kpi;
    }
    // Dashboard KPIs / list focus on submitted applications only.
    $res = $conn->query(
        "SELECT
            SUM(status = 'submitted') AS total,
            SUM(status = 'draft') AS draft,
            SUM(status = 'submitted') AS submitted,
            SUM(status = 'submitted' AND (assigned_to_admin_id IS NULL OR assigned_to_admin_id = 0)) AS unassigned,
            SUM(status = 'submitted' AND assigned_to_admin_id IS NOT NULL AND assigned_to_admin_id > 0) AS assigned,
            SUM(status = 'submitted' AND followup_status = 'in_progress') AS in_progress,
            SUM(status = 'submitted' AND followup_status = 'waiting_documents') AS waiting_documents,
            SUM(status = 'submitted' AND followup_status IN ('closed','cancelled')) AS closed
         FROM self_registrations"
    );
    if ($res && ($row = $res->fetch_assoc())) {
        foreach ($kpi as $k => $_) {
            $kpi[$k] = (int) ($row[$k] ?? 0);
        }
    }
    return $kpi;
}

/** Attach / refresh referral attribution on an existing draft. */
function pcvc_self_reg_apply_referral(
    mysqli $conn,
    int $registrationId,
    int $referredByAdminId,
    string $referringAgentName = ''
): void {
    if ($registrationId <= 0 || $referredByAdminId <= 0) {
        return;
    }
    $refName = mb_substr(trim($referringAgentName), 0, 200);
    if ($refName === '') {
        $refName = pcvc_self_reg_admin_label($conn, $referredByAdminId);
    }
    $stmt = $conn->prepare(
        "UPDATE self_registrations
         SET referred_by_admin_id = ?,
             referring_agent = ?,
             assigned_to_admin_id = COALESCE(NULLIF(assigned_to_admin_id, 0), ?),
             assigned_at = COALESCE(assigned_at, NOW()),
             followup_status = IF(followup_status = 'new' OR followup_status = '' OR followup_status IS NULL, 'assigned', followup_status)
         WHERE id = ? AND status = 'draft'
         LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isii', $referredByAdminId, $refName, $referredByAdminId, $registrationId);
    $stmt->execute();
    $stmt->close();
}

/**
 * HQ-wide Self Registration access: superadmin or Admission Manager
 * (never while switched into a company partner view).
 */
function pcvc_self_reg_has_hq_access(mysqli $conn): bool
{
    if (function_exists('pcvc_company_view_limits_data') && pcvc_company_view_limits_data()) {
        return false;
    }
    if (function_exists('pcvc_current_user_is_superadmin') && pcvc_current_user_is_superadmin($conn)) {
        return true;
    }
    if (function_exists('pcvc_current_user_is_admission_manager') && pcvc_current_user_is_admission_manager($conn)) {
        return true;
    }

    return false;
}

function pcvc_self_reg_can_access_row(array $row, int $adminId, bool $isSuper): bool
{
    if ($isSuper) {
        return true;
    }
    if ($adminId <= 0) {
        return false;
    }
    return (int) ($row['assigned_to_admin_id'] ?? 0) === $adminId
        || (int) ($row['referred_by_admin_id'] ?? 0) === $adminId;
}

/**
 * @return list<array>
 */
function pcvc_self_reg_list_applications(
    mysqli $conn,
    ?int $assignedTo = null,
    string $filter = 'all',
    int $limit = 200,
    ?int $referredBy = null
): array {
    pcvc_self_reg_ensure_schema($conn);
    $limit = max(1, min(500, $limit));
    $where = ['1=1'];
    $types = '';
    $params = [];

    // Never list non-submitted drafts in the dashboard.
    $where[] = "r.status = 'submitted'";

    if ($assignedTo !== null && $assignedTo > 0 && $referredBy !== null && $referredBy > 0) {
        $where[] = '(r.assigned_to_admin_id = ? OR r.referred_by_admin_id = ?)';
        $types .= 'ii';
        $params[] = $assignedTo;
        $params[] = $referredBy;
    } elseif ($assignedTo !== null && $assignedTo > 0) {
        $where[] = 'r.assigned_to_admin_id = ?';
        $types .= 'i';
        $params[] = $assignedTo;
    } elseif ($referredBy !== null && $referredBy > 0) {
        $where[] = 'r.referred_by_admin_id = ?';
        $types .= 'i';
        $params[] = $referredBy;
    }
    if ($filter === 'unassigned') {
        $where[] = '(r.assigned_to_admin_id IS NULL OR r.assigned_to_admin_id = 0)';
    } elseif ($filter === 'assigned') {
        $where[] = 'r.assigned_to_admin_id IS NOT NULL AND r.assigned_to_admin_id > 0';
    } elseif ($filter === 'closed') {
        $where[] = "r.followup_status IN ('closed','cancelled')";
    } elseif (pcvc_self_reg_is_valid_followup_status($filter)) {
        $where[] = 'r.followup_status = ?';
        $types .= 's';
        $params[] = $filter;
    }

    $sql = 'SELECT r.*,
                   a.full_name AS assignee_name,
                   a.role AS assignee_role,
                   b.full_name AS created_by_name
            FROM self_registrations r
            LEFT JOIN admins a ON a.id = r.assigned_to_admin_id
            LEFT JOIN admins b ON b.id = r.created_by_admin_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.id DESC
            LIMIT ' . $limit;

    $rows = [];
    if ($types === '') {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }
    $bind = [];
    $bind[] = &$types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function pcvc_self_reg_find_by_id(mysqli $conn, int $id): ?array
{
    pcvc_self_reg_ensure_schema($conn);
    if ($id <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM self_registrations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function pcvc_self_reg_transfer(
    mysqli $conn,
    int $registrationId,
    int $assigneeId,
    int $byAdminId,
    string $notes = ''
): void {
    pcvc_self_reg_ensure_schema($conn);
    $assigneeId = max(0, $assigneeId);
    $notes = mb_substr(trim($notes), 0, 2000);

    // Validate assignee is staff/agent
    $okUser = false;
    foreach (pcvc_self_reg_assignable_users($conn) as $u) {
        if ((int) $u['id'] === $assigneeId) {
            $okUser = true;
            break;
        }
    }
    if (!$okUser) {
        throw new InvalidArgumentException('Selected staff/agent is not assignable.');
    }

    $stmt = $conn->prepare(
        "UPDATE self_registrations
         SET assigned_to_admin_id = ?,
             assigned_by_admin_id = ?,
             assigned_at = NOW(),
             followup_status = CASE
                 WHEN followup_status IN ('new','') THEN 'assigned'
                 ELSE followup_status
             END,
             followup_notes = CASE
                 WHEN ? = '' THEN followup_notes
                 WHEN followup_notes IS NULL OR followup_notes = '' THEN ?
                 ELSE CONCAT(followup_notes, '\n', ?)
             END
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Transfer prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiissi', $assigneeId, $byAdminId, $notes, $notes, $notes, $registrationId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Transfer failed: ' . $err);
    }
    $stmt->close();
}

function pcvc_self_reg_delete(mysqli $conn, int $registrationId): void
{
    pcvc_self_reg_ensure_schema($conn);
    $reg = pcvc_self_reg_find_by_id($conn, $registrationId);
    if (!$reg) {
        throw new InvalidArgumentException('Application not found.');
    }

    $token = (string) ($reg['token'] ?? '');
    $docs = pcvc_self_reg_documents_map($conn, $registrationId);
    $dir = $token !== ''
        ? (pcvc_self_reg_upload_root() . DIRECTORY_SEPARATOR . $token)
        : '';

    foreach ($docs as $doc) {
        $stored = (string) ($doc['stored_name'] ?? '');
        if ($dir !== '' && $stored !== '') {
            $path = $dir . DIRECTORY_SEPARATOR . $stored;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    $stmtDocs = $conn->prepare('DELETE FROM self_registration_documents WHERE registration_id = ?');
    if ($stmtDocs) {
        $stmtDocs->bind_param('i', $registrationId);
        $stmtDocs->execute();
        $stmtDocs->close();
    }

    $stmt = $conn->prepare('DELETE FROM self_registrations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Delete prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $registrationId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Delete failed: ' . $err);
    }
    $stmt->close();

    if ($dir !== '' && is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
}

function pcvc_self_reg_update_followup(
    mysqli $conn,
    int $registrationId,
    string $status,
    string $notes = ''
): void {
    pcvc_self_reg_ensure_schema($conn);
    if (!pcvc_self_reg_is_valid_followup_status($status)) {
        throw new InvalidArgumentException('Invalid follow-up status.');
    }
    $notes = mb_substr(trim($notes), 0, 2000);
    $stmt = $conn->prepare(
        "UPDATE self_registrations
         SET followup_status = ?,
             followup_notes = CASE
                 WHEN ? = '' THEN followup_notes
                 WHEN followup_notes IS NULL OR followup_notes = '' THEN ?
                 ELSE CONCAT(followup_notes, '\n', ?)
             END
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Status update prepare failed.');
    }
    $stmt->bind_param('ssssi', $status, $notes, $notes, $notes, $registrationId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Status update failed: ' . $err);
    }
    $stmt->close();
}

/** @return list<string> */
function pcvc_self_reg_study_levels(): array
{
    return [
        'Kindergarten',
        'Primary School',
        'Secondary / High School',
        'Short Professional Courses',
        'Professional Training Courses',
        'Technical and Vocational Training',
        'English / French Language Courses',
        'Certificate',
        'Attestation of College Studies (AEC)',
        'Diploma of Vocational Studies (DEP)',
        'Diploma of College Studies (DEC)',
        'College Diploma',
        'Advanced Diploma',
        'Associate Degree',
        "Bachelor's Degree",
        'Postgraduate Certificate',
        'Postgraduate Diploma',
        "Master's Degree",
        'PhD / Doctorate',
    ];
}

/** @return list<string> */
function pcvc_self_reg_fields_of_study(): array
{
    return [
        'Accounting and Finance',
        'Agriculture and Agricultural Sciences',
        'Architecture and Urban Planning',
        'Artificial Intelligence and Machine Learning',
        'Automotive Engineering and Technology',
        'Aviation and Aeronautical Engineering',
        'Banking and Insurance',
        'Biomedical and Biotechnology Sciences',
        'Business Administration and Management',
        'Chemical Engineering',
        'Civil Engineering',
        'Computer Science and Information Technology',
        'Construction and Building Technology',
        'Cybersecurity and Computer Networking',
        'Data Science and Business Analytics',
        'Dentistry and Dental Sciences',
        'Economics',
        'Education and Teaching',
        'Early Childhood Education',
        'Electrical and Electronics Engineering',
        'Energy and Renewable Energy Engineering',
        'Environmental Science and Management',
        'Food Science and Nutrition',
        'Graphic Design and Multimedia',
        'Health Sciences and Healthcare Management',
        'Hospitality and Tourism Management',
        'Human Resource Management',
        'Industrial and Manufacturing Engineering',
        'International Business and Trade',
        'International Relations and Development Studies',
        'Journalism and Mass Communication',
        'Law and Legal Studies',
        'Logistics and Supply Chain Management',
        'Marketing and Digital Marketing',
        'Mathematics and Statistics',
        'Mechanical Engineering',
        'Medicine and Medical Sciences',
        'Mining and Geological Engineering',
        'Nursing and Midwifery',
        'Occupational Health and Safety',
        'Oil and Gas Engineering',
        'Pharmacy and Pharmaceutical Sciences',
        'Philosophy and Religious Studies',
        'Physics and Applied Physics',
        'Political Science and Governance',
        'Psychology and Mental Health',
        'Public Health',
        'Social Work and Community Development',
        'Software Engineering and Development',
        'Telecommunications Engineering',
        'Natural and Applied Sciences',
        'Languages and Literature',
        'Arts and Humanities',
        'Technical and Vocational Training',
        'Culinary Arts and Food Services',
        'Transportation and Maritime Studies',
        'Veterinary Medicine and Animal Sciences',
        'Sports and Recreation Management',
        'Security and Criminal Justice',
        'Other – Please Specify',
    ];
}

/** @return list<string> */
function pcvc_self_reg_education_levels(): array
{
    return [
        'Primary School',
        'Secondary / High School',
        'Certificate / Diploma',
        'College / AEC / DEC / DEP',
        "Bachelor's Degree",
        'Postgraduate Certificate / Diploma',
        "Master's Degree",
        'PhD / Doctorate',
        'Other',
    ];
}

/** @return list<string> */
function pcvc_self_reg_languages(): array
{
    return ['English', 'French', 'Either English or French'];
}

/** @return list<string> */
function pcvc_self_reg_sponsors(): array
{
    return [
        'Self-sponsored',
        'Father / Mother',
        'Husband / Wife',
        'Brother / Sister',
        'Other family member',
        'Company / Employer',
    ];
}

/**
 * Document slots. payment_proof is mandatory before submit.
 *
 * @return array<string, array{label:string,required:bool,accept:string}>
 */
function pcvc_self_reg_document_slots(): array
{
    return [
        'payment_proof' => [
            'label' => 'Payment proof (mandatory)',
            'required' => true,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'passport' => [
            'label' => 'Passport',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'diplomas' => [
            'label' => 'Academic diplomas',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'transcripts' => [
            'label' => 'Academic transcripts / School reports',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'cv' => [
            'label' => 'Curriculum Vitae (CV)',
            'required' => false,
            'accept' => '.pdf,.doc,.docx,.jpg,.jpeg,.png',
        ],
        'birth_certificate' => [
            'label' => 'Birth certificate',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
        'language_certificate' => [
            'label' => 'Language proficiency certificate (if available)',
            'required' => false,
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ],
    ];
}

function pcvc_self_reg_issue_link(
    mysqli $conn,
    ?int $adminId = null,
    string $source = 'link',
    ?int $referredByAdminId = null,
    string $referringAgentName = ''
): array {
    pcvc_self_reg_ensure_schema($conn);
    $source = in_array($source, ['link', 'manual'], true) ? $source : 'link';
    $token = bin2hex(random_bytes(32));
    $refId = ($referredByAdminId !== null && $referredByAdminId > 0) ? $referredByAdminId : null;
    $refName = mb_substr(trim($referringAgentName), 0, 200);
    if ($refName === '' && $refId !== null) {
        $refName = pcvc_self_reg_admin_label($conn, $refId);
    }
    $createdBy = ($adminId !== null && $adminId > 0) ? $adminId : null;
    $assignedTo = $refId; // auto-route referrals to the issuing account for follow-up

    if ($refId !== null) {
        $stmt = $conn->prepare(
            'INSERT INTO self_registrations
                (token, source, followup_status, created_by_admin_id, referred_by_admin_id,
                 assigned_to_admin_id, assigned_at, referring_agent)
             VALUES (?, ?, \'assigned\', ?, ?, ?, NOW(), ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare self-registration insert: ' . $conn->error);
        }
        $stmt->bind_param('ssiiis', $token, $source, $createdBy, $refId, $assignedTo, $refName);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO self_registrations
                (token, source, followup_status, created_by_admin_id, referring_agent)
             VALUES (?, ?, \'new\', ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare self-registration insert: ' . $conn->error);
        }
        $stmt->bind_param('ssis', $token, $source, $createdBy, $refName);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create self-registration: ' . $err);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return ['id' => $id, 'token' => $token, 'source' => $source];
}

function pcvc_self_reg_find_by_token(mysqli $conn, string $token): ?array
{
    pcvc_self_reg_ensure_schema($conn);
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM self_registrations WHERE token = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** @return array<string, array> keyed by doc_key */
function pcvc_self_reg_documents_map(mysqli $conn, int $registrationId): array
{
    $out = [];
    $stmt = $conn->prepare(
        'SELECT * FROM self_registration_documents WHERE registration_id = ?'
    );
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $registrationId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(string) $row['doc_key']] = $row;
    }
    $stmt->close();
    return $out;
}

function pcvc_self_reg_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return "{$scheme}://{$host}{$basePath}";
}

/** Fixed public link students always receive (no random token). */
function pcvc_self_reg_fixed_public_url(): string
{
    return pcvc_self_reg_base_url() . '/self-registration.php';
}

/** Tokenized URL for a specific draft/application (admin/manual use). */
function pcvc_self_reg_public_url(string $token): string
{
    return pcvc_self_reg_fixed_public_url() . '?token=' . rawurlencode($token);
}

/**
 * @return list<array>
 */
function pcvc_self_reg_recent(mysqli $conn, int $limit = 40): array
{
    return pcvc_self_reg_list_applications($conn, null, 'all', $limit);
}

/** @return array<string, string> */
function pcvc_self_reg_writable_fields(): array
{
    return [
        'full_name' => 'string',
        'date_of_birth' => 'date',
        'nationality' => 'string',
        'country_of_residence' => 'string',
        'destination_country' => 'string',
        'whatsapp' => 'string',
        'email' => 'string',
        'preferred_level' => 'string',
        'field_choice_1' => 'string',
        'field_choice_2' => 'string',
        'field_choice_3' => 'string',
        'field_choice_4' => 'string',
        'field_choice_5' => 'string',
        'other_field_specify' => 'string',
        'highest_education' => 'string',
        'previous_field' => 'string',
        'graduation_year' => 'string',
        'final_grade' => 'string',
        'preferred_language' => 'string',
        'preferred_intake' => 'string',
        'preferred_institution' => 'string',
        'preferred_province' => 'string',
        'sponsor' => 'string',
        'ready_to_pay' => 'yesno',
        'visa_refused' => 'yesno',
        'travel_with_family' => 'yesno',
        'referring_agent' => 'string',
        'declaration_accepted' => 'bool',
    ];
}

function pcvc_self_reg_sanitize_value(string $type, $raw): string
{
    $val = is_string($raw) ? trim($raw) : (is_scalar($raw) ? trim((string) $raw) : '');
    if ($type === 'date') {
        if ($val === '') {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        return ($dt && $dt->format('Y-m-d') === $val) ? $val : '';
    }
    if ($type === 'yesno') {
        return in_array($val, ['Yes', 'No'], true) ? $val : '';
    }
    if ($type === 'bool') {
        return ($val === '1' || $val === 'true' || $val === 'on' || $val === 'yes') ? '1' : '0';
    }
    return mb_substr($val, 0, 255);
}

<?php
/**
 * Normalize role strings from DB/session ("Super Admin", "superadmin", etc.)
 */
function pcvc_normalize_role_string($role): string
{
    $s = trim((string) $role);
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s) ?? $s;

    return trim($s);
}

function pcvc_is_superadmin_role($role): bool
{
    $s = strtolower(pcvc_normalize_role_string($role));
    // Strip zero-width / BOM / NBSP so DB values still match
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
    $s = preg_replace('/[\s_\-]+/u', '', $s);
    return $s === 'superadmin' || $s === 'director' || $s === 'secretary';
}

function pcvc_is_accountant_role($role): bool
{
    $s = strtolower(pcvc_normalize_role_string($role));
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
    $s = preg_replace('/[\s_\-]+/u', '', $s);

    return $s === 'accountant';
}

function pcvc_is_secretary_role($role): bool
{
    $s = strtolower(pcvc_normalize_role_string($role));
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
    $s = preg_replace('/[\s_\-]+/u', '', $s);

    return $s === 'secretary';
}

function pcvc_is_staff_role($role): bool
{
    $s = strtolower(pcvc_normalize_role_string($role));
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
    $compact = preg_replace('/[\s_\-]+/u', '', $s);

    return $compact === 'staff' || $compact === 'staffadmin' || $compact === 'accountant';
}

/** Staff or superadmin (session role and/or DB role). */
function pcvc_current_user_is_staff_or_superadmin(mysqli $conn): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminPk <= 0) {
        return false;
    }

    $sessionRole = (string) ($_SESSION['role'] ?? '');
    if (pcvc_is_superadmin_role($sessionRole) || pcvc_is_staff_role($sessionRole)) {
        return true;
    }

    $st = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $adminPk);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $dbRole = (string) ($row['role'] ?? '');

    return pcvc_is_superadmin_role($dbRole) || pcvc_is_staff_role($dbRole);
}

/**
 * SQL fragment: admins.role may own assigned student applications (staff or superadmin).
 * Superadmin normalization matches pcvc_is_superadmin_role() (spaces / underscores / hyphens removed).
 */
function pcvc_sql_assignable_application_owner_condition(): string
{
    return '(LOWER(TRIM(COALESCE(role, \'\'))) IN (\'staff\', \'accountant\')'
        . ' OR REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(role, \'\'))), \' \', \'\'), \'_\', \'\'), \'-\', \'\') IN (\'superadmin\', \'director\', \'secretary\'))';
}

/** @deprecated Use pcvc_is_superadmin_role() */
function xander_is_superadmin_role($role): bool
{
    return pcvc_is_superadmin_role($role);
}

/**
 * Whether the logged-in admin is superadmin (session role and/or DB role).
 */
function pcvc_current_user_is_superadmin(mysqli $conn): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionRole = trim((string) ($_SESSION['role'] ?? ''));
    if (pcvc_is_superadmin_role($sessionRole)) {
        return true;
    }

    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminPk <= 0) {
        return false;
    }

    $st = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $adminPk);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return pcvc_is_superadmin_role((string) ($row['role'] ?? ''));
}

/**
 * Compact admins.role for SQL comparisons (matches pcvc_is_superadmin_role / pcvc_is_staff_role).
 */
function pcvc_sql_role_compact_expr(string $column = 'role'): string
{
    $col = trim($column) !== '' ? $column : 'role';

    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE({$col}, ''))), CHAR(10), ''), CHAR(13), ''), ' ', ''), '_', ''), '-', '')";
}

/**
 * SQL expression: TRUE when admins.role is staff or superadmin (any common variant).
 */
function pcvc_sql_is_payroll_employee_expr(string $column = 'role'): string
{
    $compact = pcvc_sql_role_compact_expr($column);

    return "({$compact} = 'staff' OR {$compact} = 'accountant' OR {$compact} = 'superadmin' OR {$compact} = 'director' OR {$compact} = 'secretary')";
}

/**
 * SQL expression: TRUE when admins.role is any superadmin variant.
 * Normalization matches pcvc_is_superadmin_role().
 */
function pcvc_sql_is_superadmin_role_expr(string $column = 'role'): string
{
    $compact = pcvc_sql_role_compact_expr($column);

    return "({$compact} = 'superadmin' OR {$compact} = 'director' OR {$compact} = 'secretary')";
}

/**
 * Resolve admin role from session + DB, then enforce superadmin-only access.
 */
/**
 * Map DB/session role to a dashboard sidebar/cards key (handles newlines and superadmin variants).
 *
 * @param array<string, mixed> $accessMap
 */
function pcvc_resolve_dashboard_role_key(string $role, array $accessMap, string $fallback = 'standard'): string
{
    if (pcvc_is_superadmin_role($role)) {
        return isset($accessMap['superadmin']) ? 'superadmin' : $fallback;
    }

    if (pcvc_is_staff_role($role)) {
        return isset($accessMap['staff']) ? 'staff' : $fallback;
    }

    $normalized = pcvc_normalize_role_string($role);
    if ($normalized !== '' && isset($accessMap[$normalized])) {
        return $normalized;
    }

    $lower = strtolower($normalized);
    foreach (array_keys($accessMap) as $key) {
        if (strtolower((string) $key) === $lower) {
            return (string) $key;
        }
    }

    return isset($accessMap[$fallback]) ? $fallback : (string) array_key_first($accessMap);
}

/** Allow Director, Accountant, and Secretary on admins.role when that column is a fixed list. */
function pcvc_ensure_director_role(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $res = $conn->query("SHOW COLUMNS FROM admins LIKE 'role'");
    if (!$res) {
        return;
    }
    $row = $res->fetch_assoc();
    $res->free();
    $type = (string) ($row['Type'] ?? '');
    if (!preg_match('/^enum\((.*)\)$/i', $type, $match)) {
        return;
    }
    $missing = [];
    foreach (['director', 'accountant', 'secretary'] as $roleName) {
        if (stripos($match[1], "'" . $roleName . "'") === false) {
            $missing[] = "'" . $roleName . "'";
        }
    }
    if ($missing === []) {
        return;
    }
    $nullSql = (($row['Null'] ?? '') === 'YES') ? 'NULL' : 'NOT NULL';
    $defaultSql = '';
    if (array_key_exists('Default', $row) && $row['Default'] !== null) {
        $defaultSql = " DEFAULT '" . $conn->real_escape_string((string) $row['Default']) . "'";
    }
    $conn->query('ALTER TABLE admins MODIFY COLUMN role ENUM(' . $match[1] . ',' . implode(',', $missing) . ") {$nullSql}{$defaultSql}");
}

/** Meeting invitation / recordings pages: superadmin and staff (incl. staff admin variants). */
function pcvc_require_staff_or_superadmin(mysqli $conn, bool $json = false): void
{
    if (pcvc_current_user_is_staff_or_superadmin($conn)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    header('Location: admin-login.php');
    exit;
}

/**
 * Staff member Yvonne (stored as Yvone / Yvonne on first name, last name, or full name).
 */
function pcvc_admin_row_is_yvonne(array $admin): bool
{
    $fields = ['first_name', 'last_name', 'full_name', 'username'];
    foreach ($fields as $field) {
        $raw = pcvc_normalize_role_string($admin[$field] ?? '');
        if ($raw === '') {
            continue;
        }
        foreach (preg_split('/\s+/', $raw) ?: [] as $part) {
            $token = strtolower(preg_replace('/[^a-z]/i', '', (string) $part) ?? '');
            if ($token === 'yvonne' || $token === 'yvone') {
                return true;
            }
        }
    }

    return false;
}

/**
 * Superadmin Akayezu Sonia. Website Management is hidden for this account only.
 */
function pcvc_admin_row_is_akayezu_sonia(array $admin): bool
{
    $email = strtolower(trim((string) ($admin['email'] ?? '')));
    if (in_array($email, ['soniamuhizi@gmail.com', 'asoniamuhizi@gmail.com'], true)) {
        return true;
    }

    $parts = [];
    foreach (['first_name', 'last_name', 'full_name', 'username'] as $field) {
        $parts[] = strtolower(pcvc_normalize_role_string($admin[$field] ?? ''));
    }
    $blob = preg_replace('/[^a-z]+/', '', implode('', $parts)) ?? '';

    return str_contains($blob, 'akayezu') && str_contains($blob, 'sonia');
}

function pcvc_current_user_hides_website_management(mysqli $conn): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminPk <= 0) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($adminPk, $cache)) {
        return $cache[$adminPk];
    }

    $st = $conn->prepare('SELECT first_name, last_name, full_name, username, email FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        $cache[$adminPk] = false;
        return false;
    }
    $st->bind_param('i', $adminPk);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $cache[$adminPk] = is_array($row) && pcvc_admin_row_is_akayezu_sonia($row);

    return $cache[$adminPk];
}

/**
 * Websites, job platforms, Noble Education, and Canada workers menus.
 * Superadmins, plus the staff account named Yvonne.
 */
function pcvc_can_access_sensitive_menus(mysqli $conn): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminPk <= 0) {
        return false;
    }

    if (pcvc_is_superadmin_role((string) ($_SESSION['role'] ?? ''))) {
        return true;
    }

    $st = $conn->prepare('SELECT first_name, last_name, full_name, username, role FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $adminPk);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return false;
    }

    if (pcvc_is_superadmin_role((string) ($row['role'] ?? ''))) {
        return true;
    }

    return pcvc_admin_row_is_yvonne($row);
}

function pcvc_admin_row_is_tuyishimire_robert(array $admin): bool
{
    $email = strtolower(trim((string) ($admin['email'] ?? '')));
    if ($email === 'tuyishimirerobert1@gmail.com') {
        return true;
    }

    $parts = [];
    foreach (['first_name', 'last_name', 'full_name', 'username'] as $field) {
        $parts[] = strtolower(pcvc_normalize_role_string($admin[$field] ?? ''));
    }
    $blob = preg_replace('/[^a-z]+/', '', implode('', $parts)) ?? '';

    return strpos($blob, 'tuyishimire') !== false && strpos($blob, 'robert') !== false;
}

/**
 * Francophonie Mobility sidebar: superadmins, plus staff Tuyishimire Robert.
 */
function pcvc_can_access_francophonie_mobility(mysqli $conn): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminPk <= 0) {
        return false;
    }

    if (pcvc_is_superadmin_role((string) ($_SESSION['role'] ?? ''))) {
        return true;
    }

    $st = $conn->prepare('SELECT first_name, last_name, full_name, username, email, role FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $adminPk);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return false;
    }

    if (pcvc_is_superadmin_role((string) ($row['role'] ?? ''))) {
        return true;
    }

    return pcvc_admin_row_is_tuyishimire_robert($row);
}

function pcvc_require_francophonie_mobility(mysqli $conn, bool $json = false): void
{
    if (pcvc_can_access_francophonie_mobility($conn)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    header('Location: admin-dashboard.php');
    exit;
}

function pcvc_require_sensitive_menus(mysqli $conn, bool $json = false): void
{
    if (pcvc_can_access_sensitive_menus($conn)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    header('Location: admin-dashboard.php');
    exit;
}

function pcvc_require_superadmin(mysqli $conn, bool $json = false): void
{
    if (pcvc_current_user_is_superadmin($conn)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Superadmin access required']);
        exit;
    }

    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;text-align:center;">'
        . '<h2>Access denied</h2><p>This page is available to superadmin only.</p></body></html>';
    exit;
}

function pcvc_logged_in_admin_id(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
}

/**
 * Signed-in admin identity for forms that must record the staff member as the agent.
 *
 * @return array{id:int,first_name:string,last_name:string,email:string,full_name:string}|null
 */
function pcvc_logged_in_admin_profile(mysqli $conn): ?array
{
    $adminId = pcvc_logged_in_admin_id();
    if ($adminId <= 0) {
        return null;
    }

    $st = $conn->prepare('SELECT id, first_name, last_name, full_name, email FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $adminId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $full = trim((string) ($row['full_name'] ?? ''));
    if ($first === '' && $last === '' && $full !== '') {
        $parts = preg_split('/\s+/', $full, 2) ?: [];
        $first = trim((string) ($parts[0] ?? ''));
        $last = trim((string) ($parts[1] ?? ''));
    }
    if ($first === '' && $full !== '') {
        $first = $full;
    }
    if ($last === '') {
        $last = $first;
    }
    if ($full === '') {
        $full = trim($first . ' ' . ($last !== $first ? $last : ''));
    }

    return [
        'id' => (int) ($row['id'] ?? $adminId),
        'first_name' => $first,
        'last_name' => $last,
        'email' => trim((string) ($row['email'] ?? '')),
        'full_name' => $full,
    ];
}

/** Only allow a return to the staff student application, never an external URL. */
function pcvc_student_application_return_target(string $raw): string
{
    $raw = str_replace(["\r", "\n", "\0"], '', trim($raw));
    if ($raw === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) || str_starts_with($raw, '//') || str_contains($raw, '\\')) {
        return '';
    }

    $parts = parse_url($raw);
    if (!is_array($parts)) {
        return '';
    }
    $path = basename(str_replace('\\', '/', (string) ($parts['path'] ?? $raw)));
    if ($path !== 'student-application.php') {
        return '';
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    $keep = [];
    $resumeId = isset($query['id']) && is_string($query['id']) ? $query['id'] : '';
    if ($resumeId !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $resumeId)) {
        $keep['id'] = $resumeId;
    }
    $lang = isset($query['lang']) && is_string($query['lang']) ? $query['lang'] : '';
    if (in_array($lang, ['en', 'fr'], true)) {
        $keep['lang'] = $lang;
    }
    $qs = http_build_query($keep);

    return 'student-application.php' . ($qs !== '' ? '?' . $qs : '');
}

function pcvc_is_agent_role($role): bool
{
    $s = strtolower(pcvc_normalize_role_string($role));
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s) ?? $s;
    $compact = preg_replace('/[\s_\-]+/u', '', $s) ?? $s;

    return $compact === 'agent';
}

/**
 * Who is looking at the Student Application Report.
 * Every role sees the full list. Agents and company accounts see only
 * applications assigned to them and applications they submitted.
 *
 * @return array{id:int, role:string, email:string, username:string, scope:string, account_type:string}
 */
function pcvc_application_report_viewer(mysqli $conn): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Company dashboard switch: isolate students to that company only.
    if (!function_exists('pcvc_acting_company_id') && is_file(__DIR__ . '/company_view_as.php')) {
        require_once __DIR__ . '/company_view_as.php';
    }
    if (function_exists('pcvc_is_viewing_as_company') && pcvc_is_viewing_as_company()) {
        $company = function_exists('pcvc_load_acting_company') ? pcvc_load_acting_company($conn) : null;
        if ($company) {
            $email = strtolower(trim((string) ($company['email'] ?? '')));
            $username = strtolower(trim((string) ($company['username'] ?? '')));
            return [
                'id' => (int) ($company['id'] ?? 0),
                'role' => 'agent',
                'email' => $email,
                'username' => $username,
                'scope' => 'own',
                'account_type' => 'company',
            ];
        }
    }

    $adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    $viewer = ['id' => $adminId, 'role' => '', 'email' => '', 'username' => '', 'scope' => 'own', 'account_type' => 'personal'];
    if ($adminId < 1) {
        return $viewer;
    }

    $role = (string) ($_SESSION['role'] ?? '');
    $email = '';
    $username = strtolower(trim((string) ($_SESSION['username'] ?? '')));
    $accountType = 'personal';
    $hasAccountType = false;
    $col = @$conn->query("SHOW COLUMNS FROM admins LIKE 'account_type'");
    if ($col instanceof mysqli_result) {
        $hasAccountType = $col->num_rows > 0;
        $col->free();
    }
    $sql = $hasAccountType
        ? 'SELECT role, email, username, account_type FROM admins WHERE id = ? LIMIT 1'
        : 'SELECT role, email, username FROM admins WHERE id = ? LIMIT 1';
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('i', $adminId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $role = (string) ($row['role'] ?? $role);
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $dbUsername = strtolower(trim((string) ($row['username'] ?? '')));
            if ($dbUsername !== '') {
                $username = $dbUsername;
            }
            if ($hasAccountType) {
                $accountType = strtolower(trim((string) ($row['account_type'] ?? 'personal')));
            }
        }
    }
    $viewer['role'] = strtolower(pcvc_normalize_role_string($role));
    $viewer['email'] = $email;
    $viewer['username'] = $username;
    $viewer['account_type'] = $accountType === 'company' ? 'company' : 'personal';
    $limited = pcvc_is_agent_role($role) || $viewer['account_type'] === 'company';
    $viewer['scope'] = $limited ? 'own' : 'all';

    return $viewer;
}

/** Superadmin, director, secretary, or the login whose username is Robert, may reassign an application. */
function pcvc_can_assign_application_staff(mysqli $conn): bool
{
    // While switched into a company dashboard, do not expose global reassignment.
    if (!function_exists('pcvc_is_viewing_as_company') && is_file(__DIR__ . '/company_view_as.php')) {
        require_once __DIR__ . '/company_view_as.php';
    }
    if (function_exists('pcvc_is_viewing_as_company') && pcvc_is_viewing_as_company()) {
        return false;
    }
    $viewer = pcvc_application_report_viewer($conn);
    if (($viewer['id'] ?? 0) < 1) {
        return false;
    }
    if (pcvc_is_superadmin_role($viewer['role'] ?? '')) {
        return true;
    }

    return ($viewer['username'] ?? '') === 'robert';
}

function pcvc_agent_owns_application(mysqli $conn, int $applicationId, string $email): bool
{
    $email = strtolower(trim($email));
    if ($applicationId < 1 || $email === '') {
        return false;
    }
    $st = $conn->prepare(
        "SELECT id FROM student_applications
         WHERE id = ? AND LOWER(TRIM(COALESCE(agent_email, ''))) = ?
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $applicationId, $email);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();

    return $ok;
}

/**
 * Agents and company accounts may open a file when it is assigned to them
 * or when they added it from the Add student form (stored on agent email).
 */
function pcvc_viewer_can_see_application(mysqli $conn, int $applicationId, array $viewer): bool
{
    if ($applicationId < 1) {
        return false;
    }
    if (($viewer['scope'] ?? 'own') !== 'own') {
        return true;
    }

    $adminId = (int) ($viewer['id'] ?? 0);
    $email = strtolower(trim((string) ($viewer['email'] ?? '')));
    if ($adminId < 1) {
        return false;
    }

    $memberIds = [$adminId];
    if (($viewer['account_type'] ?? '') === 'company') {
        if (!function_exists('pcvc_company_member_admin_ids') && is_file(__DIR__ . '/company_view_as.php')) {
            require_once __DIR__ . '/company_view_as.php';
        }
        if (function_exists('pcvc_company_member_admin_ids')) {
            $memberIds = pcvc_company_member_admin_ids($conn, $adminId);
        }
    }
    $memberIds = array_values(array_filter(array_map('intval', $memberIds), static fn(int $id): bool => $id > 0));
    $inList = $memberIds !== [] ? implode(',', $memberIds) : (string) $adminId;

    $hasAssign = false;
    $chk = $conn->query("SHOW COLUMNS FROM student_applications LIKE 'assigned_to_admin_id'");
    if ($chk && $chk->num_rows > 0) {
        $hasAssign = true;
    }
    if ($chk) {
        $chk->free();
    }

    if ($hasAssign && $email !== '') {
        $sql = "SELECT id FROM student_applications
                WHERE id = ?
                  AND (
                    assigned_to_admin_id IN ({$inList})
                    OR LOWER(TRIM(COALESCE(agent_email, ''))) = ?
                  )
                LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) {
            return false;
        }
        $st->bind_param('is', $applicationId, $email);
    } elseif ($hasAssign) {
        $st = $conn->prepare("SELECT id FROM student_applications WHERE id = ? AND assigned_to_admin_id IN ({$inList}) LIMIT 1");
        if (!$st) {
            return false;
        }
        $st->bind_param('i', $applicationId);
    } elseif ($email !== '') {
        return pcvc_agent_owns_application($conn, $applicationId, $email);
    } else {
        return false;
    }

    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();

    return $ok;
}

function pcvc_ensure_account_status_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $found = @$conn->query("SHOW COLUMNS FROM `admins` LIKE 'account_status'");
    if ($found && $found->num_rows > 0) {
        $found->free();
        return;
    }
    if ($found) {
        $found->free();
    }
    @$conn->query("ALTER TABLE `admins` ADD COLUMN `account_status` VARCHAR(20) NOT NULL DEFAULT 'active'");
}

/**
 * Remove a staff or agent account, including rows that block the delete.
 *
 * @return array{ok:bool, error?:string}
 */
function pcvc_delete_admin_account(mysqli $conn, int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Choose an account to delete.'];
    }

    $conn->begin_transaction();
    $checksOff = false;
    try {
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $checksOff = true;
        $schemaRes = $conn->query('SELECT DATABASE() AS db');
        $schema = '';
        if ($schemaRes instanceof mysqli_result) {
            $schema = (string) ($schemaRes->fetch_assoc()['db'] ?? '');
            $schemaRes->free();
        }
        if ($schema !== '') {
            $fk = $conn->prepare(
                'SELECT k.TABLE_NAME, k.COLUMN_NAME, c.IS_NULLABLE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 INNER JOIN information_schema.COLUMNS c
                    ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                   AND c.TABLE_NAME = k.TABLE_NAME
                   AND c.COLUMN_NAME = k.COLUMN_NAME
                 WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ?'
            );
            if ($fk) {
                $admins = 'admins';
                $pk = 'id';
                $fk->bind_param('sss', $schema, $admins, $pk);
                $fk->execute();
                $links = $fk->get_result();
                $rows = [];
                if ($links instanceof mysqli_result) {
                    while ($row = $links->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
                $fk->close();
                foreach ($rows as $link) {
                    $child = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($link['TABLE_NAME'] ?? '')) ?? '';
                    $col = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($link['COLUMN_NAME'] ?? '')) ?? '';
                    if ($child === '' || $col === '' || strcasecmp($child, 'admins') === 0) {
                        continue;
                    }
                    $sql = strtoupper((string) ($link['IS_NULLABLE'] ?? '')) === 'YES'
                        ? "UPDATE `{$child}` SET `{$col}` = NULL WHERE `{$col}` = ?"
                        : "DELETE FROM `{$child}` WHERE `{$col}` = ?";
                    $st = $conn->prepare($sql);
                    if (!$st) {
                        throw new RuntimeException('Could not clear records linked to this account.');
                    }
                    $st->bind_param('i', $id);
                    if (!$st->execute()) {
                        $st->close();
                        throw new RuntimeException('Could not clear records linked to this account.');
                    }
                    $st->close();
                }
            }
        }
        $del = $conn->prepare('DELETE FROM admins WHERE id = ? LIMIT 1');
        if (!$del) {
            throw new RuntimeException('Could not delete that account.');
        }
        $del->bind_param('i', $id);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            throw new RuntimeException('That account could not be deleted.');
        }
        $del->close();
        $conn->commit();
        $conn->query('SET FOREIGN_KEY_CHECKS=1');

        return ['ok' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($checksOff) {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['ok' => false, 'error' => 'That account could not be deleted. It is still tied to other records.'];
    }
}

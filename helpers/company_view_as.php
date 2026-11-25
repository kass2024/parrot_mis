<?php
declare(strict_types=1);

require_once __DIR__ . '/staff_self_register.php';

const PCVC_ACTING_COMPANY_SESSION = 'pcvc_acting_company_id';
const PCVC_ACTING_COMPANY_REAL = 'pcvc_acting_real_admin_id';

function pcvc_acting_company_id(): int
{
    return (int) ($_SESSION[PCVC_ACTING_COMPANY_SESSION] ?? 0);
}

function pcvc_acting_real_admin_id(): int
{
    return (int) ($_SESSION[PCVC_ACTING_COMPANY_REAL] ?? 0);
}

function pcvc_is_viewing_as_company(): bool
{
    return pcvc_acting_company_id() > 0;
}

/**
 * @return array<string,mixed>|null
 */
function pcvc_load_acting_company(mysqli $conn): ?array
{
    $id = pcvc_acting_company_id();
    if ($id < 1) {
        return null;
    }
    pcvc_ensure_staff_account_columns($conn);
    $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if (!$row || !pcvc_admin_is_company_account($row)) {
        return null;
    }

    return $row;
}

/**
 * Start viewing the MIS as a company partner (superadmin only). Does not replace login identity.
 *
 * @return array{ok:bool, error?:string, company?:array<string,mixed>}
 */
function pcvc_enter_company_view(mysqli $conn, int $companyAdminId, int $realAdminId): array
{
    if ($companyAdminId < 1 || $realAdminId < 1) {
        return ['ok' => false, 'error' => 'Choose a company account.'];
    }
    pcvc_ensure_staff_account_columns($conn);
    $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return ['ok' => false, 'error' => 'Company view is unavailable.'];
    }
    $st->bind_param('i', $companyAdminId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if (!$row || !pcvc_admin_is_company_account($row)) {
        return ['ok' => false, 'error' => 'That company account was not found.'];
    }
    $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
    if ($status === 'pending') {
        return ['ok' => false, 'error' => 'Approve this company before opening its dashboard view.'];
    }
    $_SESSION[PCVC_ACTING_COMPANY_SESSION] = $companyAdminId;
    $_SESSION[PCVC_ACTING_COMPANY_REAL] = $realAdminId;

    return ['ok' => true, 'company' => $row];
}

function pcvc_exit_company_view(): void
{
    unset($_SESSION[PCVC_ACTING_COMPANY_SESSION], $_SESSION[PCVC_ACTING_COMPANY_REAL]);
}

function pcvc_company_logo_url(?string $filename): string
{
    $filename = trim(str_replace('\\', '/', (string) $filename));
    if ($filename === '') {
        return '';
    }
    if (function_exists('pcvc_profile_photo_url')) {
        return pcvc_profile_photo_url($filename);
    }
    if (stripos($filename, 'uploads/') === 0) {
        return $filename;
    }

    return 'uploads/' . ltrim($filename, '/');
}

/** Initials mark when a company has no uploaded logo (never use Parrot seal). */
function pcvc_company_brand_mark_data_uri(string $companyName): string
{
    $name = trim($companyName);
    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $ch = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        if ($ch !== '') {
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($ch) : strtoupper($ch);
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }
    if ($initials === '') {
        $initials = 'CO';
    }
    $safe = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
        . '<rect width="128" height="128" rx="64" fill="#14532d"/>'
        . '<text x="64" y="78" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="44" font-weight="700" fill="#ffffff">'
        . $safe
        . '</text></svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

/**
 * Admin IDs that belong to a company partner (the company itself + linked staff/agents).
 *
 * @return list<int>
 */
function pcvc_company_member_admin_ids(mysqli $conn, int $companyAdminId): array
{
    $companyAdminId = max(0, $companyAdminId);
    if ($companyAdminId < 1) {
        return [];
    }
    pcvc_ensure_staff_account_columns($conn);
    $ids = [$companyAdminId];
    if (pcvc_admin_has_column($conn, 'parent_company_admin_id')) {
        $st = $conn->prepare(
            'SELECT id FROM admins WHERE parent_company_admin_id = ? ORDER BY id ASC'
        );
        if ($st) {
            $st->bind_param('i', $companyAdminId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $st->close();
        }
    }

    return array_values(array_unique($ids));
}

/**
 * SQL IN list helper for company member admin ids. Returns "0" when empty.
 */
function pcvc_company_member_admin_ids_sql(mysqli $conn, int $companyAdminId): string
{
    $ids = pcvc_company_member_admin_ids($conn, $companyAdminId);
    if ($ids === []) {
        return '0';
    }

    return implode(',', array_map('intval', $ids));
}

/** Effective company account when acting as company or logged in as company. */
function pcvc_effective_company_context(mysqli $conn): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (function_exists('pcvc_is_viewing_as_company') && pcvc_is_viewing_as_company()) {
        return pcvc_load_acting_company($conn);
    }
    $adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminId < 1) {
        return null;
    }
    $st = $conn->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $adminId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if (!$row || !pcvc_admin_is_company_account($row)) {
        return null;
    }

    return $row;
}

/**
 * Superadmin “view as company” must not see Parrot HQ-wide lists.
 * Login identity stays superadmin; data breadth follows the company.
 */
function pcvc_company_view_limits_data(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return pcvc_is_viewing_as_company();
}

/** Admin id for company-scoped queries (acting company, logged-in company, or self). */
function pcvc_effective_data_admin_id(mysqli $conn): int
{
    $ctx = pcvc_effective_company_context($conn);
    if ($ctx) {
        return (int) ($ctx['id'] ?? 0);
    }

    return (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
}

/**
 * SQL predicate limiting student_applications to a company and its linked people.
 * Safe to embed (ids are ints; emails are escaped).
 */
function pcvc_company_applications_where_sql(mysqli $conn, int $companyAdminId, string $alias = 'sa'): string
{
    $companyAdminId = max(0, $companyAdminId);
    if ($companyAdminId < 1) {
        return '0 = 1';
    }
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'sa';
    $ids = pcvc_company_member_admin_ids($conn, $companyAdminId);
    if ($ids === []) {
        return '0 = 1';
    }
    $inList = implode(',', array_map('intval', $ids));
    $parts = ["{$alias}.assigned_to_admin_id IN ({$inList})"];
    $emailRes = $conn->query(
        "SELECT DISTINCT LOWER(TRIM(email)) AS em
         FROM admins
         WHERE id IN ({$inList})
           AND email IS NOT NULL
           AND TRIM(email) <> ''"
    );
    if ($emailRes) {
        while ($row = $emailRes->fetch_assoc()) {
            $em = strtolower(trim((string) ($row['em'] ?? '')));
            if ($em === '') {
                continue;
            }
            $parts[] = "LOWER(TRIM(COALESCE({$alias}.agent_email, ''))) = '"
                . $conn->real_escape_string($em) . "'";
        }
        $emailRes->free();
    }

    return '(' . implode(' OR ', $parts) . ')';
}

/** @return list<int> */
function pcvc_company_scoped_application_ids(mysqli $conn, int $companyAdminId, int $limit = 5000): array
{
    $where = pcvc_company_applications_where_sql($conn, $companyAdminId, 'sa');
    $limit = max(1, min(20000, $limit));
    $ids = [];
    $res = $conn->query(
        "SELECT sa.id FROM student_applications sa WHERE {$where} ORDER BY sa.id DESC LIMIT {$limit}"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $res->free();
    }

    return $ids;
}

/**
 * List-page scope for company view / company login.
 * null = Parrot HQ (no extra filter).
 * When active, pages without a company ownership column must use hq_block_sql (empty list).
 *
 * @return array{
 *   company_id:int,
 *   member_sql:string,
 *   apps_where:string,
 *   app_ids_sql:string,
 *   hq_block_sql:string
 * }|null
 */
function pcvc_company_list_scope(mysqli $conn): ?array
{
    $ctx = pcvc_effective_company_context($conn);
    if (!$ctx) {
        return null;
    }
    $companyId = (int) ($ctx['id'] ?? 0);
    if ($companyId < 1) {
        return null;
    }
    $appIds = pcvc_company_scoped_application_ids($conn, $companyId);

    return [
        'company_id' => $companyId,
        'member_sql' => pcvc_company_member_admin_ids_sql($conn, $companyId),
        'apps_where' => pcvc_company_applications_where_sql($conn, $companyId, 'sa'),
        'app_ids_sql' => $appIds === [] ? '0' : implode(',', array_map('intval', $appIds)),
        'hq_block_sql' => '0 = 1',
    ];
}

/** True when lists must not show Parrot HQ rows. */
function pcvc_company_blocks_hq_lists(mysqli $conn): bool
{
    return pcvc_company_list_scope($conn) !== null;
}

/**
 * Script basenames that store Parrot HQ data only (no company owner column yet).
 * In company view these pages show an empty state instead of HQ rows.
 *
 * @return list<string>
 */
function pcvc_company_hq_only_scripts(): array
{
    return [
        'visa-report.php',
        'visa-export.php',
        'form-20-report.php',
        'Credit-Transfer-report.php',
        'credit-search.php',
        'malta-courses-applications.php',
        'eci-malta-applications.php',
        'job-applicant.php',
        'export_applicants.php',
        'ajax_search.php',
        'live_search.php',
        'loan-applicants-report.php',
        'loan_search_ajax.php',
        'canada-workers-applications.php',
        'canada-medical-exams-applications.php',
        'caq-report.php',
        'caq-export.php',
        'francophonie-mobility-applications.php',
        'francophonie_mobility_application_details.php',
        'employment-opportunities-applications.php',
        'admin-fm-contracts.php',
        'admin-eo-contracts.php',
        'admin-dubai-contracts.php',
        'admin-czech-contracts.php',
        'admin-employment-agreements.php',
        'admin-irs-agreements.php',
        'admin-german-language-contracts.php',
        'admin-special-contracts.php',
        'admin-partner-contracts.php',
        'admin-agent-contracts.php',
        'admin-client-consents.php',
        'contract_esign.php',
        'applyboard-dashboard.php',
        'mopay-payment-transactions.php',
        'special-student-payments.php',
        'refund-requests-report.php',
        'old-report.php',
        'commission-requests-report.php',
        'receipts_ajax.php',
    ];
}

/**
 * When company view hits an HQ-only module, stop and show an empty state.
 * Pages that already scope their own queries should not be listed above.
 */
function pcvc_company_block_hq_only_script(mysqli $conn): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $scope = pcvc_company_list_scope($conn);
    if ($scope === null) {
        return;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = strtolower(basename($script));
    $blocked = array_fill_keys(array_map('strtolower', pcvc_company_hq_only_scripts()), true);
    if (!isset($blocked[$base])) {
        return;
    }
    // JSON/API style endpoints
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $isAjax = str_contains($accept, 'application/json')
        || str_ends_with($base, '_ajax.php')
        || str_contains($base, 'export')
        || isset($_GET['format']);
    if ($isAjax || str_ends_with($base, '-export.php') || str_ends_with($base, '_ajax.php')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'rows' => [],
            'data' => [],
            'message' => 'No records for this company yet.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    $title = htmlspecialchars((string) ($scope['company_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Company records</title>'
        . '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;margin:0;padding:40px;color:#0f172a}'
        . '.box{max-width:640px;margin:40px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(15,23,42,.06)}'
        . 'h1{font-size:1.25rem;margin:0 0 8px}p{margin:0;color:#64748b;line-height:1.5}</style></head><body>'
        . '<div class="box"><h1>No records for this company yet</h1>'
        . '<p>Parrot Canada headquarters data is hidden in company view. '
        . 'This module will show rows only after this company has its own records.</p></div></body></html>';
    exit;
}

/** Attach a newly created staff/agent account to the active company partner. */
function pcvc_link_admin_to_company(mysqli $conn, int $adminId, int $companyAdminId): void
{
    $adminId = max(0, $adminId);
    $companyAdminId = max(0, $companyAdminId);
    if ($adminId < 1 || $companyAdminId < 1 || $adminId === $companyAdminId) {
        return;
    }
    pcvc_ensure_staff_account_columns($conn);
    if (!pcvc_admin_has_column($conn, 'parent_company_admin_id')) {
        return;
    }
    $st = $conn->prepare('UPDATE admins SET parent_company_admin_id = ? WHERE id = ? LIMIT 1');
    if (!$st) {
        return;
    }
    $st->bind_param('ii', $companyAdminId, $adminId);
    $st->execute();
    $st->close();
}

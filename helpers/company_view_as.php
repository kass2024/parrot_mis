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

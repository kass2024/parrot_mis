<?php
/**
 * Normalize role strings from DB/session ("Super Admin", "superadmin", etc.)
 */
function pcvc_is_superadmin_role($role): bool
{
    $s = strtolower(trim((string) $role));
    // Strip zero-width / BOM / NBSP so DB values still match
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
    $s = preg_replace('/[\s_\-]+/u', '', $s);
    return $s === 'superadmin';
}

/**
 * SQL fragment: admins.role may own assigned student applications (staff or superadmin).
 * Superadmin normalization matches pcvc_is_superadmin_role() (spaces / underscores / hyphens removed).
 */
function pcvc_sql_assignable_application_owner_condition(): string
{
    return '(LOWER(TRIM(COALESCE(role, \'\'))) = \'staff\''
        . ' OR REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(role, \'\'))), \' \', \'\'), \'_\', \'\'), \'-\', \'\') = \'superadmin\')';
}

/** @deprecated Use pcvc_is_superadmin_role() */
function xander_is_superadmin_role($role): bool
{
    return pcvc_is_superadmin_role($role);
}

/**
 * Resolve admin role from session + DB, then enforce superadmin-only access.
 */
function pcvc_require_superadmin(mysqli $conn, bool $json = false): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionRole = trim((string) ($_SESSION['role'] ?? ''));
    $dbRole = '';
    $adminPk = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);

    if ($adminPk > 0) {
        $st = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $adminPk);
            $st->execute();
            if ($row = $st->get_result()->fetch_assoc()) {
                $dbRole = trim((string) ($row['role'] ?? ''));
            }
            $st->close();
        }
    }

    if (pcvc_is_superadmin_role($dbRole) || pcvc_is_superadmin_role($sessionRole)) {
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

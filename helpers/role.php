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

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

/** @deprecated Use pcvc_is_superadmin_role() */
function xander_is_superadmin_role($role): bool
{
    return pcvc_is_superadmin_role($role);
}

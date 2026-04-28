<?php
declare(strict_types=1);

/**
 * Compute the application base path dynamically.
 *
 * Examples:
 * - Local XAMPP:   /parrot_mis/student/index.php  -> base "/parrot_mis"
 * - cPanel root:   /student/index.php            -> base ""
 * - API endpoint:  /parrot_mis/api/x.php         -> base "/parrot_mis"
 */
function pcvc_app_base_path(): string
{
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($sn === '') return '';

    foreach (['/student/', '/api/'] as $seg) {
        $pos = strpos($sn, $seg);
        if ($pos !== false) {
            $base = rtrim(substr($sn, 0, $pos), '/');
            return $base;
        }
    }

    // Fallback: directory of script (e.g. /parrot_mis)
    $dir = rtrim(dirname($sn), '/');
    return $dir === '/' ? '' : $dir;
}

/**
 * Build an absolute-path URL within the app, respecting base path.
 * Pass paths like "/student/index.php" or "/student-login.php".
 */
function pcvc_url(string $path): string
{
    $path = trim($path);
    if ($path === '') return pcvc_app_base_path() ?: '/';
    if ($path[0] !== '/') $path = '/' . $path;
    return pcvc_app_base_path() . $path;
}


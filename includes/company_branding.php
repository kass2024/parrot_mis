<?php
/**
 * Single source for legal / display company name (replaces legacy “Xander” branding in UI).
 */
declare(strict_types=1);

if (!defined('PCVC_COMPANY_DISPLAY_NAME')) {
    $pcvcName = getenv('PCVC_COMPANY_DISPLAY_NAME');
    define(
        'PCVC_COMPANY_DISPLAY_NAME',
        ($pcvcName !== false && $pcvcName !== '') ? trim((string) $pcvcName) : 'Parrot Canada Visa Consultant'
    );
}

if (!defined('PCVC_PAYROLL_CURRENCY')) {
    $pcvcCur = getenv('PCVC_PAYROLL_CURRENCY');
    define(
        'PCVC_PAYROLL_CURRENCY',
        ($pcvcCur !== false && $pcvcCur !== '') ? trim((string) $pcvcCur) : 'RWF'
    );
}

if (!defined('PCVC_SUPPORT_EMAIL')) {
    $pcvcSe = getenv('PCVC_SUPPORT_EMAIL');
    define(
        'PCVC_SUPPORT_EMAIL',
        ($pcvcSe !== false && $pcvcSe !== '') ? trim((string) $pcvcSe) : ''
    );
}

/**
 * Absolute base URL for this app (no trailing slash). Uses PCVC_PUBLIC_BASE_URL from env when set.
 */
function pcvc_public_base_url(): string
{
    $fromEnv = getenv('PCVC_PUBLIC_BASE_URL');
    if ($fromEnv !== false && trim((string) $fromEnv) !== '') {
        return rtrim(trim((string) $fromEnv), '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

<?php
declare(strict_types=1);

/**
 * Parrot MIS — public name, URLs, and contact hints for templates and emails.
 * Used by student applications, portal emails, and internal tools.
 */

/** Primary name shown in titles, banners, and email signatures */
const PCVC_COMPANY_DISPLAY_NAME = 'Parrot Canada Visa Consultant';

/** Same name in French (UI / bilingual templates) */
const PCVC_COMPANY_DISPLAY_NAME_FR = 'Parrot Canada Consultant en Visa';

/** Public website (marketing / student-facing links) */
const PCVC_COMPANY_WEBSITE = 'https://visaconsultantcanada.com';

/** Default admissions contact (aligns with SMTP / admissions flow) */
const PCVC_COMPANY_SUPPORT_EMAIL = 'admission@visaconsultantcanada.com';

/** Shown when no staff member is assigned on an application (admin lists) */
const PCVC_DEFAULT_ASSIGNED_PERSON_LABEL = 'Parrot Canada';

/**
 * Public base URL for this MIS install (receipt email, webhooks, internal curl).
 * Set APP_PUBLIC_URL in .env on production, e.g. https://mis.visaconsultantcanada.com
 */
function pcvc_public_base_url(): string
{
    if (!function_exists('xander_env_get')) {
        $envLoader = __DIR__ . '/../helpers/env_load.php';
        if (is_file($envLoader)) {
            require_once $envLoader;
        }
    }

    $env = '';
    if (function_exists('xander_env_get')) {
        $env = trim((string) xander_env_get('APP_PUBLIC_URL'));
    }
    if ($env !== '') {
        return rtrim($env, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return rtrim($scheme . '://' . $host . $dir, '/');
}

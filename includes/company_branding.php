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

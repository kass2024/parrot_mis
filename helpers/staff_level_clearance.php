<?php
declare(strict_types=1);

require_once __DIR__ . '/role.php';

/**
 * Menus and submenus a staff account can be granted.
 * No saved row means every item is allowed.
 *
 * @return array<string, array{group:string,label:string,items:array<string,string>}>
 */
function pcvc_staff_level_clearance_catalog(): array
{
    return [
        'all_admissions' => ['group' => 'Study Abroad', 'label' => 'University & College Admissions', 'items' => [
            'student-application.php' => 'Add student',
            'application-list.php' => 'Student application Report',
            'students-manage.php' => 'Applicants Management',
            'receipt_viewer.php' => 'Check payment Receipt',
            'applyboard-dashboard.php' => 'ApplyBoard Applications',
            'task-assignment-monitoring.php' => 'Task assignment monitoring',
        ]],
        'loan_applications' => ['group' => 'Study Abroad', 'label' => 'Study Loan Applications', 'items' => [
            'loan-applicants-report.php' => 'Loan Application list',
            'loan_search.php' => 'User-iD',
        ]],
        'I-20_applications' => ['group' => 'Study Abroad', 'label' => 'USA – I-20 Applications', 'items' => [
            'form-20-report.php' => 'I-20 Applicant List',
        ]],
        'credit_transfer' => ['group' => 'Study Abroad', 'label' => 'Credit Transfer Applications', 'items' => [
            'Credit-Transfer-report.php' => 'Transfer Requests list',
            'credit-search.php' => 'credit userID',
            'research-project-elearning.php' => 'RESEARCH PROJECT FOR ELEARNING',
            'special-student-payments.php' => 'Record Payment (Credit Transfer / UPAFA)',
        ]],
        'german_language_contracts' => ['group' => 'Study Abroad', 'label' => 'Germany – Language Programs & Contracts', 'items' => [
            'admin-generate-german-language-contract.php' => 'Issue German language contract link (English)',
            'admin-generate-german-language-contract-french.php' => 'Issue German language contract link (French)',
            'admin-german-language-contracts.php' => 'View German language contracts',
        ]],
        'malta_courses' => ['group' => 'Study Abroad', 'label' => 'Malta – Professional Courses', 'items' => [
            'malta-courses-request.php?staff=1' => 'Add student',
            'malta-courses-applications.php' => 'View applications',
            'malta-courses-applications.php?status=pending' => 'Pending',
            'malta-courses-applications.php?status=approved' => 'Approved',
        ]],
        'noble_education' => ['group' => 'Study Abroad', 'label' => 'Canada – Noble Education Group', 'items' => [
            'noble-education-applications.php' => 'View Applications',
            'noble-education-applications.php?status=pending' => 'Pending',
            'noble-education-applications.php?status=under_review' => 'Under Review',
            'noble-education-applications.php?status=approved' => 'Approved',
            'noble-education-applications.php?status=rejected' => 'Rejected',
        ]],
        'contracts' => ['group' => 'Study Abroad', 'label' => 'Student Contracts', 'items' => [
            'admin-generate-student-contract.php' => 'Issue English Contract Link',
            'admin-generate-student-contract-french.php' => 'Issue French contract link',
            'admin-contracts.php' => 'View students Contracts',
            'admin-contracts-special.php' => 'Special students Contracts',
            'admin-generate-student-contract-special.php' => 'Issue special contract link',
            'admin-special-contracts.php?only=cedrick' => 'Cedrick study Canada agreement',
            'admin/contract_esign.php' => 'Signed PhD Contracts',
        ]],
        'student_consent' => ['group' => 'Study Abroad', 'label' => 'Student Consent', 'items' => [
            'admin-student-consent.php' => 'Issue and view student consent forms',
        ]],
        'jobsabrod' => ['group' => 'Work Abroad', 'label' => 'International Job Applications', 'items' => [
            'job-applicant.php' => 'Check job Applicants',
            'job-applicant.php?process_status=rejected' => 'Rejected',
        ]],
        'francophonie_mobility' => ['group' => 'Work Abroad', 'label' => 'Canada – Francophone Mobility', 'items' => [
            'francophonie-mobility-applications.php' => 'Francophonie Mobility',
            'francophonie-mobility-staff-add.php' => 'Smart Add candidate',
            'francophonie-mobility-applications.php?status=pending' => 'Pending',
            'francophonie-mobility-applications.php?status=under_review' => 'Under Review',
            'francophonie-mobility-applications.php?status=approved' => 'Approved',
            'francophonie-mobility-applications.php?status=rejected' => 'Rejected',
            'admin-generate-fm-contract.php' => 'Issue E-Sign Contract',
            'admin-fm-contracts.php' => 'Signed Mobility Contracts',
            'admin-generate-fm-contract.php?variant=special' => 'Issue Special E-Sign',
            'admin-fm-contracts.php?variant=special' => 'Signed Special Contracts',
            'francophonie-record-invoice.php' => 'Record Invoice',
        ]],
        'canada_workers' => ['group' => 'Work Abroad', 'label' => 'Canada – General Worker Recruitment', 'items' => [
            'canada-workers-applications.php' => 'View Applications',
            'canada-workers-applications.php?status=pending' => 'Pending',
            'canada-workers-applications.php?status=under_review' => 'Under Review',
            'canada-workers-applications.php?status=approved' => 'Approved',
            'canada-workers-applications.php?status=rejected' => 'Rejected',
        ]],
        'employment_opportunities' => ['group' => 'Work Abroad', 'label' => 'Russia – Employment Opportunities', 'items' => [
            'employment-opportunities-applications.php' => 'View Applications',
            'employment-opportunities-applications.php?status=pending' => 'Pending',
            'employment-opportunities-applications.php?status=under_review' => 'Under Review',
            'employment-opportunities-applications.php?status=approved' => 'Approved',
            'employment-opportunities-applications.php?status=rejected' => 'Rejected',
            'admin-generate-eo-contract.php' => 'Issue E-Sign Contract',
            'admin-eo-contracts.php' => 'Signed Employment Contracts',
        ]],
        'employment_agreement' => ['group' => 'Work Abroad', 'label' => 'Europe – Employment Applications & Agreements', 'items' => [
            'admin-generate-employment-agreement.php' => 'Issue agreement link',
            'admin-employment-agreements.php' => 'View agreements',
        ]],
        'dubai_work' => ['group' => 'Work Abroad', 'label' => 'Dubai – Work Contract', 'items' => [
            'admin-generate-dubai-contract.php' => 'Issue Dubai work contract',
            'admin-dubai-contracts.php' => 'View Dubai work contracts',
            'dubai-record-invoice.php' => 'Record Invoice',
        ]],
        'czech_work' => ['group' => 'Work Abroad', 'label' => 'Czech Republic – Work Permit', 'items' => [
            'admin-generate-czech-contract.php' => 'Issue Czech work permit agreement',
            'admin-czech-contracts.php' => 'View Czech work permit agreements',
            'czech-record-invoice.php' => 'Record Invoice',
        ]],
        'visit_study_visa' => ['group' => 'Visit & Immigration', 'label' => 'Visitor & Study Visa Applications', 'items' => [
            'visa-report.php' => 'Visa Applicant List',
            'visa-report.php?process_status=rejected' => 'Rejected',
            'caq-report.php' => 'CAQ Applications',
        ]],
        'canada_medical' => ['group' => 'Visit & Immigration', 'label' => 'Canada – Immigration Medical Examinations', 'items' => [
            'canada-medical-exams-applications.php' => 'View Applications',
            'canada-medical-exams-applications.php?status=pending' => 'Pending',
            'canada-medical-exams-applications.php?status=under_review' => 'Under Review',
            'canada-medical-exams-applications.php?status=approved' => 'Approved',
            'canada-medical-exams-applications.php?status=rejected' => 'Rejected',
        ]],
        'korea_event_participation' => ['group' => 'Visit & Immigration', 'label' => 'South Korea – Events & Conferences', 'items' => [
            'korea-event-applications.php' => 'View Applications',
            'korea-event-applications.php?register=1' => 'Register applicant',
            'korea-event-applications.php?status=pending' => 'Pending',
            'korea-event-applications.php?status=under_review' => 'Under Review',
            'korea-event-applications.php?status=approved' => 'Approved',
            'korea-event-applications.php?status=rejected' => 'Rejected',
        ]],
        'korea_invitation_contracts' => ['group' => 'Visit & Immigration', 'label' => 'South Korea – Invitation & Visa Contracts', 'items' => [
            'admin-generate-korea-invitation-contract.php' => 'Issue Korea invitation contract link',
            'admin-korea-invitation-contracts.php' => 'View Korea invitation contracts',
        ]],
        'client_consent' => ['group' => 'Visit & Immigration', 'label' => 'Immigration Client Consent Agreements', 'items' => [
            'admin-generate-client-consent.php' => 'Issue client consent link',
            'admin-client-consents.php' => 'View client consent agreements',
        ]],
        'immigration_records' => ['group' => 'Visit & Immigration', 'label' => 'Immigration Records Agreement', 'items' => [
            'admin-generate-irs-agreement.php' => 'Issue signing link',
            'admin-irs-agreements.php' => 'Signed agreements',
        ]],
        'staff_reporting' => ['group' => 'Partners & Operations', 'label' => 'Staff Management', 'items' => [
            'staff-management.php' => 'Accounts management',
            'admin/contracts-admin.php' => 'Staff Employment Contracts',
            'salary-report.php' => 'Requested Salaries',
            'leave-approvals.php' => 'Manage Permissions',
            'overtime-approvals.php' => 'Overtime Management',
            'jobs_report.php' => 'Job Report',
            'staff_daily_report.php' => 'Contract team daily report',
            'admin-payroll.php' => 'Payroll',
            'cards/generate_staff_card.php' => 'Generate Staff Cards',
            'staff-warning-letters.php' => 'Staff Warning Letters',
            'mopay-payment-transactions.php' => 'MoPay Transactions',
        ]],
        'staff_attendance' => ['group' => 'Partners & Operations', 'label' => 'Staff Attendance', 'items' => [
            'attendance-ui.php' => 'Take Attendance',
            'job_todo_list.php' => 'Job To-Do List',
            'daily-work-report.php' => 'Report Daily work',
            'salary.php' => 'Salary Request',
            'admin/contract.php' => 'Sign Contract',
            'leave-request.php' => 'Permission Request',
            'staff_overtime_request.php' => 'Overtime Request',
            'my-leaves.php' => 'Permission Status',
            'attendance-report.php' => 'Attendance Report',
            'jobs_report.php' => 'Job Report',
            'cards/generate_staff_card.php' => 'Generate your service card',
        ]],
        'commission_request' => ['group' => 'Partners & Operations', 'label' => 'Commission Requests', 'items' => [
            'Commission-Request.php' => 'Request commission',
            'commission-my-requests.php' => 'My requests',
            'commission-requests-report.php' => 'All Requests',
        ]],
        'marketing' => ['group' => 'Partners & Operations', 'label' => 'Marketing', 'items' => [
            'upload-materials.php' => 'Upload Marketing Materials',
            'get-materials.php' => 'Get Marketing Materials',
            'weekly-advert.php' => 'Weekly Advert',
        ]],
        'smart_brochure' => ['group' => 'Partners & Operations', 'label' => 'Smart Brochure', 'items' => [
            'marketing-brochures.php' => 'Smart Brochure Sharing',
        ]],
        'meeting_invitation' => ['group' => 'Partners & Operations', 'label' => 'Meeting Invitations', 'items' => [
            'francophonie-meeting-invitation.php' => 'Meeting Invitation',
            'francophonie-meeting-recordings.php' => 'Meeting Recordings',
        ]],
        'refund_requests' => ['group' => 'Partners & Operations', 'label' => 'Refund Management', 'items' => [
            'refund-requests-report.php' => 'All Refund Requests',
            'refund-requests-report.php?status=pending' => 'Pending',
            'refund-requests-report.php?status=under_review' => 'Under Review',
            'refund-requests-report.php?status=approved' => 'Approved',
            'refund-requests-report.php?status=rejected' => 'Rejected',
        ]],
        'canada_partner_clients' => ['group' => 'PARROT PartnerConnect', 'label' => 'PARROT PartnerConnect', 'items' => [
            'canada-partners.php' => 'Partners and client monitor',
            'canada-partners.php#partner-form' => 'Add a partner',
            'canada-partner-client.php' => 'Client file',
        ]],
        'finance' => ['group' => 'Finance', 'label' => 'Finance', 'items' => [
            'finance.php' => 'Income',
            'finance-budget.php' => 'Monthly budget',
            'finance-request.php' => 'Request from a budget line',
        ]],
        'partner_contracts' => ['group' => 'Partners & Operations', 'label' => 'B2B Partner Contracts', 'items' => [
            'admin-generate-partner-contract.php' => 'Issue partner contract link (English)',
            'admin-generate-partner-contract-french.php' => 'Issue partner contract link (French)',
            'admin-partner-contracts.php' => 'View partner contracts',
            'admin-special-contracts.php?only=ccn_burundi' => 'CCN Burundi partnership (English)',
            'admin-special-contracts.php?only=ccn_burundi_fr' => 'CCN Burundi partnership (French)',
        ]],
        'agent_contracts' => ['group' => 'Partners & Operations', 'label' => 'Individual Agent Contracts', 'items' => [
            'admin-generate-agent-contract.php' => 'Issue agent contract link',
            'admin-agent-contracts.php' => 'View agent contracts',
        ]],
        'special_contracts' => ['group' => 'Partners & Operations', 'label' => 'Special Service Contracts', 'items' => [
            'admin-special-contracts.php' => 'Special contracts',
        ]],
        'platform' => ['group' => 'System & Account', 'label' => 'Platform Management', 'items' => [
            'platforms.php' => 'Platforms management',
        ]],
        'website_management' => ['group' => 'System & Account', 'label' => 'Website Management', 'items' => [
            'websites.php' => 'Website Management',
        ]],
        'job_platforms' => ['group' => 'System & Account', 'label' => 'Job Platform Management', 'items' => [
            'job-platforms.php' => 'Job platforms management',
        ]],
    ];
}

function pcvc_staff_level_clearance_menu_key(string $menuKey): string
{
    return 'menu:' . $menuKey;
}

function pcvc_staff_level_clearance_sub_key(string $menuKey, string $url): string
{
    return 'sub:' . $menuKey . '|' . $url;
}

function pcvc_staff_level_clearance_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS staff_level_clearance (
          admin_id INT UNSIGNED NOT NULL,
          item_key VARCHAR(190) NOT NULL,
          PRIMARY KEY (admin_id, item_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** @return list<string> */
function pcvc_staff_level_clearance_denied(mysqli $conn, int $adminId): array
{
    pcvc_staff_level_clearance_ensure_schema($conn);
    if ($adminId <= 0) {
        return [];
    }
    $stmt = $conn->prepare('SELECT item_key FROM staff_level_clearance WHERE admin_id = ?');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $keys = [];
    while ($row = $res->fetch_assoc()) {
        $keys[] = (string) $row['item_key'];
    }
    $stmt->close();
    return $keys;
}

/**
 * @param list<string> $denied
 */
function pcvc_staff_level_clearance_save(mysqli $conn, int $adminId, array $denied): void
{
    pcvc_staff_level_clearance_ensure_schema($conn);
    $conn->begin_transaction();
    try {
        $del = $conn->prepare('DELETE FROM staff_level_clearance WHERE admin_id = ?');
        $del->bind_param('i', $adminId);
        $del->execute();
        $del->close();
        if ($denied !== []) {
            $ins = $conn->prepare('INSERT INTO staff_level_clearance (admin_id, item_key) VALUES (?, ?)');
            foreach ($denied as $key) {
                $key = substr(trim($key), 0, 190);
                if ($key === '') {
                    continue;
                }
                $ins->bind_param('is', $adminId, $key);
                $ins->execute();
            }
            $ins->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Staff with no saved rows receive every catalog menu.
 *
 * @return array{menus:list<string>,denied:list<string>,index:array<string,list<string>>}
 */
function pcvc_staff_level_clearance_apply(mysqli $conn, int $adminId): array
{
    $catalog = pcvc_staff_level_clearance_catalog();
    $denied = array_fill_keys(pcvc_staff_level_clearance_denied($conn, $adminId), true);
    $menus = [];
    $index = [];
    foreach ($catalog as $menuKey => $menu) {
        $visibleSubs = 0;
        foreach ($menu['items'] as $url => $label) {
            $subKey = pcvc_staff_level_clearance_sub_key($menuKey, $url);
            $index[$url][] = $subKey;
            if (!isset($denied[$subKey]) && !isset($denied[pcvc_staff_level_clearance_menu_key($menuKey)])) {
                $visibleSubs++;
            }
        }
        if ($visibleSubs > 0 && !isset($denied[pcvc_staff_level_clearance_menu_key($menuKey)])) {
            $menus[] = $menuKey;
        }
    }
    return [
        'menus' => $menus,
        'denied' => array_keys($denied),
        'index' => $index,
    ];
}

function pcvc_staff_level_clearance_is_target(string $role): bool
{
    if (pcvc_is_accountant_role($role) || pcvc_is_secretary_role($role)) {
        return true;
    }

    return pcvc_is_staff_role($role) && !pcvc_is_superadmin_role($role);
}

function pcvc_staff_clearance_guard_request(mysqli $conn): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $role = (string) ($_SESSION['role'] ?? '');
    if (!pcvc_staff_level_clearance_is_target($role)) {
        return;
    }
    $adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = ltrim($script, '/');
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $catalog = pcvc_staff_level_clearance_catalog();
    $applicable = [];
    $exact = [];
    foreach ($catalog as $menuKey => $menu) {
        foreach ($menu['items'] as $url => $label) {
            $parts = explode('?', $url, 2);
            $path = $parts[0];
            $itemQuery = $parts[1] ?? '';
            $matchesPath = strcasecmp($script, $path) === 0 || str_ends_with(strtolower($script), '/' . strtolower($path));
            if (!$matchesPath) {
                continue;
            }
            $subKey = pcvc_staff_level_clearance_sub_key($menuKey, $url);
            $applicable[] = [$menuKey, $subKey, $itemQuery];
            if ($itemQuery === $query) {
                $exact[] = [$menuKey, $subKey];
            }
        }
    }
    if ($applicable === []) {
        return;
    }
    $checks = $exact !== [] ? $exact : array_map(static fn(array $row): array => [$row[0], $row[1]], array_filter($applicable, static fn(array $row): bool => $row[2] === ''));
    if ($checks === []) {
        return;
    }
    $denied = array_fill_keys(pcvc_staff_level_clearance_denied($conn, $adminId), true);
    foreach ($checks as [$menuKey, $subKey]) {
        if (!isset($denied[pcvc_staff_level_clearance_menu_key($menuKey)]) && !isset($denied[$subKey])) {
            return;
        }
    }

    $wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    http_response_code(403);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'This menu is not on your level clearance.']);
        exit;
    }
    header('Location: admin-dashboard.php');
    exit;
}

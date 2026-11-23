<?php
require_once 'auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/job_application_status.php';
require_once __DIR__ . '/helpers/form17_application_status.php';

xander_ensure_form17_process_status_column($conn);
xander_ensure_form17_intake_column($conn);

$visaDestCountries = xander_visa_destination_countries();
$visaFeaturedDestCountries = array_values(array_filter(
    xander_visa_featured_destination_countries(),
    static fn(string $name): bool => in_array($name, $visaDestCountries, true)
));
$visaIntakeByCountry = xander_visa_intake_options_by_country();

$sessionRole = isset($_SESSION['role']) ? trim((string) $_SESSION['role']) : '';
$dbRole = '';
$adminPk = 0;
if (!empty($_SESSION['admin_id'])) {
    $adminPk = (int) $_SESSION['admin_id'];
} elseif (!empty($_SESSION['id'])) {
    $adminPk = (int) $_SESSION['id'];
}
if ($adminPk > 0) {
    $stAd = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if ($stAd) {
        $stAd->bind_param('i', $adminPk);
        $stAd->execute();
        $rowAd = $stAd->get_result()->fetch_assoc();
        $stAd->close();
        if ($rowAd) {
            $dbRole = trim((string) ($rowAd['role'] ?? ''));
        }
    }
}
$canEditVisaProcess = $adminPk > 0;
$canDeleteVisaApplication = xander_is_superadmin_role($dbRole) || xander_is_superadmin_role($sessionRole);

$JOB_PROCESS_STATUSES = xander_visa_application_process_statuses();
$JOB_PROCESS_ORDER = array_keys($JOB_PROCESS_STATUSES);

// Initialize search + optional process filter (sidebar: Rejected, etc.)
$searchTerm = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$processStatusFilter = isset($_GET['process_status']) ? trim((string) $_GET['process_status']) : '';
$visaProcessFilterActive = $processStatusFilter !== '' && xander_is_valid_visa_process_status($processStatusFilter);
$visaFilterLabel = $visaProcessFilterActive ? ($JOB_PROCESS_STATUSES[$processStatusFilter] ?? $processStatusFilter) : '';

$whereParts = [];
$params = [];
$paramTypes = '';

if ($visaProcessFilterActive) {
    $whereParts[] = 'f.process_status = ?';
    $params[] = $processStatusFilter;
    $paramTypes .= 's';
}
$whereClause = $whereParts === [] ? '' : 'WHERE ' . implode(' AND ', $whereParts);

// Fetch visa applications
$query = "
SELECT f.*, r.name AS region_name, c.name AS country_name
FROM form_17_applications f
LEFT JOIN regions r ON f.region_id = r.id
LEFT JOIN countries c ON f.country_id = c.id
$whereClause
ORDER BY f.user_id DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $applicants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    die("Error preparing query: " . $conn->error);
}

$visaKpiCounts = array_fill_keys(array_keys($JOB_PROCESS_STATUSES), 0);
$visaKpiTotal = 0;
$kpiRes = $conn->query('SELECT process_status, COUNT(*) AS c FROM form_17_applications GROUP BY process_status');
if ($kpiRes) {
    while ($kpiRow = $kpiRes->fetch_assoc()) {
        $kpiKey = xander_normalize_visa_process_status($kpiRow['process_status'] ?? null);
        $kpiCount = (int) ($kpiRow['c'] ?? 0);
        $visaKpiCounts[$kpiKey] = ($visaKpiCounts[$kpiKey] ?? 0) + $kpiCount;
        $visaKpiTotal += $kpiCount;
    }
}
$visaKpiSearch = [];
if ($searchTerm !== '') {
    $visaKpiSearch['search'] = $searchTerm;
}

// Brand color codes
$colors = [
    'navy' => '#427431',
    'secondary_blue' => '#3661B9',
    'dark_blue' => '#2f5a26',
    'gold' => '#E21D1E',
    'white' => '#FFFFFF',
    'light_gray' => '#F8F9FA',
    'border_gray' => '#E0E0E0'
];

// Document type mapping
$fileFields = [
    'passport_copy' => 'Passport Copy',
    'academic_documents' => 'Academic Documents',
    'old_visa_copy' => 'Old Visa Copy',
    'passport_photo' => 'Passport Photo',
    'cv' => 'CV',
    'signature' => 'Signature'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visa Applications - Parrot Canada Visa Consultant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html {
            height: 100%;
            overflow: hidden;
        }

        body {
            background-color: <?= $colors['light_gray'] ?>;
            color: <?= $colors['navy'] ?>;
            height: 100%;
            max-height: 100%;
            min-height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .dashboard-container {
            padding: 20px;
            max-width: min(1600px, 98vw);
            margin: 0 auto;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .visa-main-scroll {
            flex: 1;
            min-height: 0;
            overflow: auto;
            scrollbar-gutter: stable;
            -webkit-overflow-scrolling: touch;
        }

        /* Dashboard Header */
        .dashboard-header {
            background: <?= $colors['white'] ?>;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-left: 5px solid <?= $colors['gold'] ?>;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            flex-shrink: 0;
        }

        .header-title h1 {
            color: <?= $colors['navy'] ?>;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title h1 i {
            color: <?= $colors['gold'] ?>;
        }

        .header-title p {
            color: #666;
            font-size: 0.8rem;
            margin-top: 2px;
        }

        .header-stats {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .visa-export-tools {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .visa-export-tools label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }

        .visa-export-tools input[type="date"] {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 8px;
            font-size: 13px;
            color: #14532d;
        }

        .visa-export-tools input[type="date"]:disabled {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .applicant-count {
            background: <?= $colors['navy'] ?>;
            color: <?= $colors['white'] ?>;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn {
            background: <?= $colors['gold'] ?>;
            color: <?= $colors['navy'] ?>;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .export-btn:hover {
            background: #e69542;
            transform: translateY(-2px);
        }

        .export-btn-pdf {
            background: #166534;
            color: #fff;
        }

        .export-btn-pdf:hover {
            background: #14532d;
        }

        /* Search Container */
        .search-container {
            background: <?= $colors['white'] ?>;
            padding: 8px 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            flex-shrink: 0;
        }

        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            width: 100%;
            min-width: 0;
            padding: 12px 20px;
            border: 2px solid <?= $colors['border_gray'] ?>;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: <?= $colors['gold'] ?>;
            box-shadow: 0 0 0 3px rgba(242, 166, 90, 0.2);
        }

        .search-btn {
            background: <?= $colors['navy'] ?>;
            color: <?= $colors['white'] ?>;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .search-btn:hover {
            background: <?= $colors['dark_blue'] ?>;
        }

        .clear-btn {
            background: <?= $colors['secondary_blue'] ?>;
            color: <?= $colors['white'] ?>;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .clear-btn:hover {
            background: <?= $colors['navy'] ?>;
        }

        /* Main Content - Cards Grid */
        .applicants-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        @media (min-width: 980px) {
            .applicants-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1500px) {
            .applicants-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        /* Applicant Card */
        .applicant-card {
            background: <?= $colors['white'] ?>;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border: 1px solid <?= $colors['border_gray'] ?>;
            position: relative;
        }

        .applicant-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        /* Unread Dot */
        .unread-dot {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 12px;
            height: 12px;
            background: <?= $colors['gold'] ?>;
            border-radius: 50%;
            display: none;
        }

        .applicant-card.unread .unread-dot {
            display: block;
        }

        .card-header {
            background: linear-gradient(135deg, <?= $colors['navy'] ?> 0%, <?= $colors['dark_blue'] ?> 100%);
            color: <?= $colors['white'] ?>;
            padding: 8px 12px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .applicant-name {
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1.2;
            margin: 0;
            padding-right: 8px;
        }

        .applicant-id {
            font-size: 0.72rem;
            opacity: 0.8;
            font-family: monospace;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .card-body {
            padding: 0 0 8px;
        }

        .card-section {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid <?= $colors['light_gray'] ?>;
        }

        .card-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-row {
            display: flex;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .info-label {
            width: 140px;
            font-weight: 600;
            color: <?= $colors['secondary_blue'] ?>;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .info-value {
            flex: 1;
            color: <?= $colors['navy'] ?>;
            font-size: 0.95rem;
            word-break: break-word;
        }

        .info-value i {
            margin-right: 8px;
            color: <?= $colors['gold'] ?>;
            width: 16px;
        }

        /* Tags for status */
        .status-tag {
            background: #e6f0ff;
            color: #007bff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin-right: 6px;
            margin-bottom: 4px;
        }

        .status-tag.visa {
            background: #d4edda;
            color: #155724;
        }

        .status-tag.passport {
            background: #fff3cd;
            color: #856404;
        }

        /* Documents Section */
        .documents-section {
            background: rgba(242, 166, 90, 0.05);
            padding: 12px;
            border-radius: 6px;
            border-left: 3px solid <?= $colors['gold'] ?>;
        }

        .documents-list {
            list-style: none;
        }

        .document-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed <?= $colors['border_gray'] ?>;
        }

        .document-item:last-child {
            border-bottom: none;
        }

        .document-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .document-info i {
            color: <?= $colors['gold'] ?>;
        }

        .document-name {
            font-size: 0.9rem;
            color: <?= $colors['navy'] ?>;
        }

        .document-actions {
            display: flex;
            gap: 5px;
        }

        .doc-btn {
            background: <?= $colors['secondary_blue'] ?>;
            color: <?= $colors['white'] ?>;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }

        .doc-btn:hover {
            background: <?= $colors['navy'] ?>;
        }

        .doc-btn.download {
            background: <?= $colors['gold'] ?>;
            color: <?= $colors['navy'] ?>;
        }

        .doc-btn.download:hover {
            background: #e69542;
        }

        /* Card Footer */
        .card-footer {
            padding: 8px 10px;
            background: <?= $colors['light_gray'] ?>;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            border-top: 1px solid <?= $colors['border_gray'] ?>;
        }

        .card-footer .status-pill-v {
            align-self: flex-start;
            max-width: 100%;
            white-space: normal;
            line-height: 1.25;
        }

        .applied-date {
            font-size: 0.85rem;
            color: #666;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            width: 100%;
            min-width: 0;
        }

        .action-buttons > .view-btn,
        .action-buttons > .contact-btn,
        .action-buttons > .btn-delete-visa {
            flex: 1 1 auto;
            min-width: 0;
            justify-content: center;
            white-space: nowrap;
        }

        .view-btn {
            background: <?= $colors['secondary_blue'] ?>;
            color: <?= $colors['white'] ?>;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .view-btn:hover {
            background: <?= $colors['navy'] ?>;
        }

        .contact-btn {
            background: <?= $colors['gold'] ?>;
            color: <?= $colors['navy'] ?>;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .contact-btn:hover {
            background: #e69542;
        }

        .btn-delete-visa {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            visibility: visible;
            opacity: 1;
        }

        .btn-delete-visa:hover {
            background: #b91c1c;
        }

        /* Application process (same workflow as job applications) */
        .process-panel {
            background: #fff;
            border-bottom: 1px solid <?= $colors['border_gray'] ?>;
            padding: 8px 10px;
        }

        .process-panel-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: <?= $colors['secondary_blue'] ?>;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .process-panel-title i { color: <?= $colors['gold'] ?>; }

        .process-tracker {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 6px;
            margin-bottom: 14px;
            overflow-x: auto;
            padding-bottom: 6px;
        }

        .process-step {
            flex: 1;
            min-width: 72px;
            text-align: center;
            position: relative;
        }

        .process-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 11px;
            left: calc(50% + 14px);
            right: calc(-50% + 14px);
            height: 3px;
            background: #e2e8f0;
            z-index: 0;
        }

        .process-step.done:not(:last-child)::after {
            background: linear-gradient(90deg, <?= $colors['gold'] ?>, #e2e8f0);
        }

        .process-step-dot {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            margin: 0 auto 6px;
            position: relative;
            z-index: 1;
            background: #e2e8f0;
            border: 3px solid #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .process-step.done .process-step-dot { background: <?= $colors['gold'] ?>; }
        .process-step.current .process-step-dot {
            background: <?= $colors['navy'] ?>;
            border-color: <?= $colors['gold'] ?>;
            box-shadow: 0 0 0 3px rgba(242, 166, 90, 0.35);
        }

        .process-step-label {
            display: block;
            font-size: 0.65rem;
            line-height: 1.25;
            color: #64748b;
            font-weight: 600;
        }

        .process-step.done .process-step-label,
        .process-step.current .process-step-label { color: <?= $colors['navy'] ?>; }

        .process-status-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            justify-content: space-between;
        }

        .status-pill-v {
            display: inline-flex;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            background: #e0e7ff;
            color: <?= $colors['navy'] ?>;
        }

        .status-pill-v--submitted { background: #f1f5f9; color: #475569; }
        .status-pill-v--under_review { background: #fef3c7; color: #92400e; }
        .status-pill-v--waiting_decision { background: #dbeafe; color: #1e40af; }
        .status-pill-v--final_decision { background: #d1fae5; color: #065f46; }
        .status-pill-v--closed { background: #e2e8f0; color: #334155; }
        .status-pill-v--rejected { background: #fee2e2; color: #991b1b; }
        .status-pill-v--visa_approved { background: #dcfce7; color: #166534; }
        .status-pill-v--visa_application_done { background: #dbeafe; color: #1e3a8a; }
        .status-pill-v--visit_visa { background: #ede9fe; color: #5b21b6; }
        .status-pill-v--waiting_for_visa_application,
        .status-pill-v--waiting_for_caq,
        .status-pill-v--waiting_for_deferral,
        .status-pill-v--waiting_for_letters_and_visa,
        .status-pill-v--intake_changed,
        .status-pill-v--intake_changed_waiting_documents,
        .status-pill-v--names_changed_waiting_documents { background: #ffedd5; color: #9a3412; }

        .visa-process-select {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 2px solid <?= $colors['border_gray'] ?>;
            font-size: 0.95rem;
            font-weight: 600;
            color: <?= $colors['navy'] ?>;
            background: #fff;
            cursor: pointer;
        }

        .card-facts {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            padding: 6px 12px 2px;
        }
        .card-date-light { color: rgba(255,255,255,.9); font-size: 12px; white-space: nowrap; }
        .view-btn, .contact-btn, .btn-delete-visa { padding: 7px 10px; font-size: 0.8rem; }

        .card-fact {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-size: 0.82rem;
            line-height: 1.3;
            color: <?= $colors['navy'] ?>;
            word-break: break-word;
        }

        .card-fact i {
            width: 16px;
            margin-top: 3px;
            color: <?= $colors['gold'] ?>;
            flex-shrink: 0;
            text-align: center;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            border: 0;
        }

        .process-toast-v {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            background: <?= $colors['navy'] ?>;
            color: #fff;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 10px;
        }

        .process-toast-v.show { display: flex; }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 50px 20px;
            background: <?= $colors['white'] ?>;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 3rem;
            color: <?= $colors['border_gray'] ?>;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            color: #666;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .empty-state p {
            color: #777;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }

        /* Search Info */
        .search-info {
            background: rgba(242, 166, 90, 0.1);
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid <?= $colors['gold'] ?>;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Responsive */
        @media (max-width: 720px) {
            .dashboard-header {
                flex-direction: column;
                align-items: stretch;
            }
            .header-stats,
            .visa-export-tools {
                justify-content: flex-start;
            }
            .dashboard-container {
                padding: 12px;
            }
        }

        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
            }
            .search-input,
            .search-btn,
            .clear-btn {
                width: 100%;
                justify-content: center;
            }
            .action-buttons > .view-btn,
            .action-buttons > .contact-btn,
            .action-buttons > .btn-delete-visa {
                flex: 1 1 calc(50% - 6px);
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                padding: 10px;
            }
            
            .applicants-grid {
                gap: 15px;
                grid-template-columns: 1fr;
            }
            
            .card-header, .card-body, .card-footer {
                padding: 12px;
            }

            .action-buttons > .view-btn,
            .action-buttons > .contact-btn,
            .action-buttons > .btn-delete-visa {
                flex: 1 1 100%;
            }
        }

        /* Narrow cards in 2-column grid still need full action row */
        @media (min-width: 980px) and (max-width: 1299px) {
            .action-buttons > .view-btn,
            .action-buttons > .contact-btn,
            .action-buttons > .btn-delete-visa {
                flex: 1 1 calc(50% - 6px);
            }
        }

        #visaStatusNotifyModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        #visaStatusNotifyModal.show-flex { display: flex !important; }
        .visa-notify-dialog {
            background: #fff;
            border-radius: 16px;
            max-width: 640px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .visa-notify-header {
            background: linear-gradient(135deg, #427431 0%, #3661B9 100%);
            color: #fff;
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .visa-notify-header h3 { margin: 0; font-size: 1.15rem; font-weight: 600; }
        .visa-notify-header p { margin: 6px 0 0; font-size: 0.85rem; opacity: 0.9; }
        .visa-notify-close { background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; line-height: 1; }
        .visa-notify-body { padding: 20px; }
        .visa-notify-preview { text-align: center; margin-bottom: 16px; }
        .visa-notify-badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 600;
            background: #e8eef9; color: #427431; margin-bottom: 8px;
        }
        .visa-notify-label-text { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
        .visa-notify-hint { text-align: center; font-size: 0.85rem; color: #64748b; margin-bottom: 14px; }
        .visa-notify-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
        }
        @media (min-width: 768px) { .visa-notify-grid { grid-template-columns: repeat(4, 1fr); } }
        .visa-notify-channel {
            border: 2px solid #e2e8f0; border-radius: 12px; padding: 12px 10px; background: #fff;
            cursor: pointer; font-size: 0.9rem; font-weight: 600; color: #334155;
        }
        .visa-notify-channel.active { border-color: #427431; color: #427431; box-shadow: 0 0 0 1px #427431; }
        .visa-notify-channel span { display: block; font-size: 0.75rem; font-weight: 400; opacity: 0.85; margin-top: 4px; }
        .visa-notify-footer {
            padding: 14px 20px 18px; background: #f8fafc; display: flex; justify-content: flex-end; gap: 10px;
        }
        .visa-notify-footer button { padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; }
        .visa-notify-cancel { background: #fff; border: 1px solid #cbd5e1 !important; color: #475569; }
        .visa-notify-confirm { background: linear-gradient(135deg, #427431, #3661B9); color: #fff; }
        .process-toast-v.warn { background: linear-gradient(135deg, #b45309, #d97706); }
        .process-status-row { display: flex; align-items: center; gap: 8px; }
        .visa-kpis {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 0 0 8px;
            flex-shrink: 0;
        }
        .visa-kpi {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 4px 10px;
            text-decoration: none;
            color: #14532d;
            line-height: 1.2;
        }
        .visa-kpi strong { font-size: 13px; line-height: 1; }
        .visa-kpi span { font-size: 12px; font-weight: 700; color: #475569; }
        .visa-kpi.active { border-color: #166534; box-shadow: inset 0 0 0 1px #166534; background: #f0fdf4; }
        .visa-kpi.kpi-approved strong { color: #166534; }
        .visa-kpi.kpi-rejected strong { color: #b91c1c; }
        .visa-add-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; margin: 0 0 8px; }
        .process-panel { padding: 8px 10px; }
        .visa-add-card summary { cursor: pointer; font-size: 1.05rem; font-weight: 700; color: #14532d; }
        .visa-add-card form { margin-top: 12px; }
        .visa-add-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        @media (min-width: 900px) { .visa-add-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; align-items: end; } }
        @media (min-width: 1280px) { .visa-add-grid { grid-template-columns: 1.2fr 1fr .85fr .9fr 1.15fr 1.25fr 1.15fr auto; align-items: end; } }
        .visa-add-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: #334155; }
        .visa-add-grid input, .visa-add-grid select { font: inherit; font-size: 15px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; }
        .visa-add-grid button { background: #166534; color: #fff; border: 0; border-radius: 10px; padding: 11px 16px; font-weight: 700; cursor: pointer; }
        .visa-smart-country { position: relative; }
        .visa-smart-country .visa-country-trigger {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            font: inherit; font-size: 15px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;
            background: #fff; color: #0f172a; cursor: pointer; text-align: left; width: 100%;
        }
        .visa-smart-country .visa-country-trigger[data-empty="1"] { color: #94a3b8; }
        .visa-smart-country .visa-country-panel {
            position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + 4px);
            background: #fff; border: 1px solid #cbd5e1; border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14); padding: 8px; display: none;
        }
        .visa-smart-country.open .visa-country-panel { display: block; }
        .visa-smart-country .visa-country-search {
            width: 100%; margin: 0 0 6px; font: inherit; font-size: 14px; padding: 8px 10px;
            border: 1px solid #cbd5e1; border-radius: 8px;
        }
        .visa-smart-country .visa-country-list {
            max-height: 220px; overflow: auto; margin: 0; padding: 0; list-style: none;
        }
        .visa-smart-country .visa-country-list button {
            display: block; width: 100%; text-align: left; background: transparent; border: 0;
            padding: 8px 10px; border-radius: 8px; font: inherit; font-size: 14px; color: #0f172a; cursor: pointer;
        }
        .visa-smart-country .visa-country-list button:hover,
        .visa-smart-country .visa-country-list button[aria-selected="true"] { background: #ecfdf5; color: #14532d; }
        .visa-smart-country .visa-country-group {
            font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase;
            color: #64748b; padding: 8px 10px 4px;
        }
        .visa-smart-country .visa-country-empty { padding: 10px; font-size: 13px; color: #64748b; }
        .visa-edit-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
        @media (min-width: 700px) { .visa-edit-grid { grid-template-columns: 1fr 1fr; } }
        .visa-edit-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: #334155; }
        .visa-edit-grid input, .visa-edit-grid select {
            font: inherit; font-size: 14px; padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
        }
        .visa-edit-grid .full { grid-column: 1 / -1; }
        .visa-edit-msg { margin: 0 0 10px; font-size: 13px; font-weight: 700; min-height: 1.2em; }
        .visa-edit-msg.ok { color: #166534; }
        .visa-edit-msg.err { color: #b91c1c; }
        .process-panel { padding-bottom: 12px; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="header-title">
                <h1><i class="fas fa-passport"></i> Visa Applications</h1>
                <p>Review and manage all visa applications and documents<?php if ($visaProcessFilterActive): ?> — <strong style="color:#b91c1c;"><?= htmlspecialchars($visaFilterLabel) ?></strong><?php endif; ?></p>
                <?php if ($visaProcessFilterActive): ?>
                <p class="small" style="margin-top:8px;"><a href="visa-report.php" style="color:#3661B9;font-weight:600;"><i class="fas fa-list"></i> View all applicants</a></p>
                <?php endif; ?>
            </div>
            <div class="header-stats">
                <div class="applicant-count">
                    <i class="fas fa-users"></i>
                    <span id="visaVisibleCount"><?php
                        $total = count($applicants);
                        echo $total . ' Application' . ($total != 1 ? 's' : '');
                    ?></span>
                </div>
                <div class="visa-export-tools">
                    <label><input type="radio" name="visaExportMode" value="today" checked> Today</label>
                    <label><input type="radio" name="visaExportMode" value="range"> Date range</label>
                    <input type="date" id="visaExportFrom" disabled aria-label="From date">
                    <input type="date" id="visaExportTo" disabled aria-label="To date">
                    <button type="button" class="export-btn" id="visaExportCsv">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="export-btn export-btn-pdf" id="visaExportPdf">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
        </div>

        <div class="visa-kpis" aria-label="Visa status totals">
            <?php
            $allKpiQuery = $visaKpiSearch;
            $allKpiHref = 'visa-report.php' . ($allKpiQuery === [] ? '' : '?' . http_build_query($allKpiQuery));
            ?>
            <a class="visa-kpi<?= $visaProcessFilterActive ? '' : ' active' ?>" href="<?= htmlspecialchars($allKpiHref) ?>">
                <strong><?= (int) $visaKpiTotal ?></strong>
                <span>All applications</span>
            </a>
            <?php
            $kpiKeys = ['visa_approved', 'rejected'];
            foreach ($JOB_PROCESS_ORDER as $kpiKey) {
                if (in_array($kpiKey, $kpiKeys, true)) {
                    continue;
                }
                if (($visaKpiCounts[$kpiKey] ?? 0) > 0) {
                    $kpiKeys[] = $kpiKey;
                }
            }
            foreach ($kpiKeys as $kpiKey):
                $kpiQuery = $visaKpiSearch;
                $kpiQuery['process_status'] = $kpiKey;
                $kpiClass = 'visa-kpi';
                if ($kpiKey === 'visa_approved') {
                    $kpiClass .= ' kpi-approved';
                } elseif ($kpiKey === 'rejected') {
                    $kpiClass .= ' kpi-rejected';
                }
                if ($processStatusFilter === $kpiKey) {
                    $kpiClass .= ' active';
                }
            ?>
            <a class="<?= $kpiClass ?>" href="<?= htmlspecialchars('visa-report.php?' . http_build_query($kpiQuery)) ?>">
                <strong data-kpi-num="<?= htmlspecialchars($kpiKey) ?>"><?= (int) ($visaKpiCounts[$kpiKey] ?? 0) ?></strong>
                <span><?= htmlspecialchars($JOB_PROCESS_STATUSES[$kpiKey] ?? $kpiKey) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($canEditVisaProcess): ?>
        <details class="visa-add-card">
            <summary>Add an applicant</summary>
            <form id="visaAddForm">
            <div class="visa-add-grid">
                <label>Full name
                    <input name="full_name" required maxlength="200" placeholder="First and last name">
                </label>
                <label>Email
                    <input name="email" type="email" maxlength="150" placeholder="Optional">
                </label>
                <label>Visa type
                    <select name="visa_type" id="visaAddVisaType">
                        <option>Study Visa</option>
                        <option>Visit Visa</option>
                        <option>Work Visa</option>
                    </select>
                </label>
                <label class="visa-smart-country" id="visaDestCountryField">Destination country
                    <input type="hidden" name="destination_country" id="visaDestCountry" value="" required>
                    <button type="button" class="visa-country-trigger" id="visaDestCountryBtn" data-empty="1" aria-haspopup="listbox" aria-expanded="false">
                        <span id="visaDestCountryLabel">Search or select country…</span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="visa-country-panel" id="visaDestCountryPanel" hidden>
                        <input type="search" class="visa-country-search" id="visaDestCountrySearch" placeholder="Type to filter countries…" autocomplete="off" aria-label="Search destination country">
                        <div class="visa-country-list" id="visaDestCountryList" role="listbox"></div>
                    </div>
                </label>
                <label id="visaAddIntakeWrap">Choose intake
                    <select name="intake" id="visaIntakeSelect">
                        <option value="">Select intake…</option>
                        <?php foreach ($visaIntakeByCountry as $groupLabel => $intakeLabels): ?>
                        <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                            <?php foreach ($intakeLabels as $intakeLabel): ?>
                            <option value="<?= htmlspecialchars($intakeLabel) ?>"><?= htmlspecialchars($intakeLabel) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status
                    <select name="process_status">
                        <?php foreach ($JOB_PROCESS_STATUSES as $k => $lab): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Add</button>
            </div>
            <p id="visaAddMsg" style="margin:8px 0 0;font-size:13px;font-weight:700;"></p>
            </form>
        </details>
        <?php endif; ?>

        <div class="search-container">
            <form class="search-form" onsubmit="return false;">
                <?php if ($visaProcessFilterActive): ?>
                <input type="hidden" name="process_status" value="<?= htmlspecialchars($processStatusFilter) ?>">
                <?php endif; ?>
                <input type="search"
                       name="search"
                       class="search-input"
                       placeholder="Search by name, email, passport number, or ID..."
                       value="<?= htmlspecialchars($searchTerm) ?>"
                       autocomplete="off"
                       aria-label="Search applicants">
            </form>
        </div>

        <!-- Applications Grid (scroll area keeps horizontal scrollbar in view) -->
        <div class="visa-main-scroll">
        <div class="applicants-grid">
            <?php if(empty($applicants)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No applications found</h3>
                    <?php if(!empty($searchTerm)): ?>
                        <p>No applications match your search criteria. Try a different search term.</p>
                        <a href="<?= $visaProcessFilterActive ? htmlspecialchars('?' . http_build_query(['process_status' => $processStatusFilter])) : '?' ?>" class="search-btn" style="display: inline-flex;">
                            <i class="fas fa-redo"></i> Show All Applications
                        </a>
                    <?php elseif ($visaProcessFilterActive): ?>
                        <p>No applications currently have status &ldquo;<?= htmlspecialchars($visaFilterLabel) ?>&rdquo;.</p>
                        <a href="visa-report.php" class="search-btn" style="display: inline-flex;">
                            <i class="fas fa-redo"></i> View all applications
                        </a>
                    <?php else: ?>
                        <p>No visa applications have been submitted yet.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach($applicants as $applicant): 
                    $firstName = trim((string) ($applicant['first_name'] ?? ''));
                    $middleName = trim((string) ($applicant['middle_name'] ?? ''));
                    $lastName = trim((string) ($applicant['last_name'] ?? ''));
                    if ($lastName !== '' && strcasecmp($firstName, $lastName) === 0 && $middleName === '') {
                        $lastName = '';
                    }
                    $fullName = trim(implode(' ', array_filter([
                        trim((string) ($applicant['prefix'] ?? '')),
                        $firstName,
                        $middleName,
                        $lastName,
                    ])));
                    
                    $appliedDate = !empty($applicant['date']) ? date('M d, Y', strtotime($applicant['date'])) : 'Not specified';
                    $isUnread = $applicant['is_read'] == 0;
                    $passportInfo = trim((string) ($applicant['passport_number'] ?? ''));
                    if (!empty($applicant['passport_expiry'])) {
                        $passportInfo .= ' (Exp: ' . $applicant['passport_expiry'] . ')';
                    }
                    $emailText = trim((string) ($applicant['email'] ?? ''));
                    $phoneText = trim((string) ($applicant['applicant_mobile'] ?? ''));
                    $birthText = trim((string) ($applicant['birthdate'] ?? ''));
                    if ($birthText === '0000-00-00') {
                        $birthText = '';
                    }
                    $genderText = trim((string) ($applicant['gender'] ?? ''));
                    $fromCountry = trim((string) ($applicant['country_applying_from'] ?? ''));
                    $toCountry = trim((string) ($applicant['country_to_visit'] ?? ''));
                    $visaTypeText = trim((string) ($applicant['visa_type'] ?? ''));
                    $intakeText = trim((string) ($applicant['intake'] ?? ''));

                    $procKey = xander_normalize_visa_process_status($applicant['process_status'] ?? null);
                    $procLabel = $JOB_PROCESS_STATUSES[$procKey];
                    $searchBlob = mb_strtolower(trim(implode(' ', array_filter([
                        $fullName,
                        $emailText,
                        $phoneText,
                        $passportInfo,
                        $visaTypeText,
                        $intakeText,
                        (string) ($applicant['user_id'] ?? ''),
                        $fromCountry,
                        $toCountry,
                    ]))));
                ?>
                <div class="applicant-card <?= $isUnread ? 'unread' : '' ?>" data-id="<?= htmlspecialchars($applicant['user_id']) ?>" data-user-id="<?= htmlspecialchars($applicant['user_id']) ?>" data-search="<?= htmlspecialchars($searchBlob) ?>" title="<?= htmlspecialchars($fullName) ?>">
                    <div class="unread-dot" title="Unread"></div>
                    <div class="card-header">
                        <div class="applicant-name"><?= htmlspecialchars($fullName) ?></div>
                        <div class="applied-date card-date-light"><?= htmlspecialchars($appliedDate) ?></div>
                    </div>
                    <div class="process-panel">
                        <?php if ($canEditVisaProcess): ?>
                        <label class="sr-only" for="visa-status-<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9_-]/', '_', $applicant['user_id'])) ?>">Set application status</label>
                        <select id="visa-status-<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9_-]/', '_', $applicant['user_id'])) ?>"
                                class="visa-process-select"
                                data-user-id="<?= htmlspecialchars($applicant['user_id']) ?>"
                                title="Application status">
                            <?php foreach ($JOB_PROCESS_STATUSES as $k => $lab): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $k === $procKey ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Card Body -->
                    <div class="card-body">
                        <div class="card-facts">
                            <?php if ($visaTypeText !== ''): ?>
                            <div class="card-fact"><i class="fas fa-file-alt"></i><span><?= htmlspecialchars($visaTypeText) ?></span></div>
                            <?php endif; ?>
                            <?php if ($fromCountry !== '' || $toCountry !== ''): ?>
                            <div class="card-fact"><i class="fas fa-plane"></i><span><?= htmlspecialchars($fromCountry !== '' ? $fromCountry : '—') ?> → <?= htmlspecialchars($toCountry !== '' ? $toCountry : '—') ?></span></div>
                            <?php endif; ?>
                            <?php if ($intakeText !== ''): ?>
                            <div class="card-fact card-fact-intake"><i class="fas fa-calendar-alt"></i><span><?= htmlspecialchars($intakeText) ?></span></div>
                            <?php endif; ?>
                            <?php if ($emailText !== ''): ?>
                            <div class="card-fact"><i class="fas fa-envelope"></i><span><?= htmlspecialchars($emailText) ?></span></div>
                            <?php endif; ?>
                            <?php if ($phoneText !== ''): ?>
                            <div class="card-fact"><i class="fas fa-phone"></i><span><?= htmlspecialchars($phoneText) ?></span></div>
                            <?php endif; ?>
                            <?php if ($passportInfo !== ''): ?>
                            <div class="card-fact"><i class="fas fa-passport"></i><span><?= htmlspecialchars($passportInfo) ?></span></div>
                            <?php endif; ?>
                            <?php if ($birthText !== '' || $genderText !== ''): ?>
                            <div class="card-fact"><i class="fas fa-user"></i><span><?= htmlspecialchars(trim($birthText . ($genderText !== '' ? ' (' . $genderText . ')' : ''))) ?></span></div>
                            <?php endif; ?>
                        </div>
                        <?php 
                        $hasDocuments = false;
                        foreach ($fileFields as $field => $label) {
                            if (!empty($applicant[$field])) {
                                $hasDocuments = true;
                                break;
                            }
                        }
                        ?>
                        
                        <?php if($hasDocuments): ?>
                        <div class="card-section">
                            <div class="info-row">
                                <div class="info-label">Documents</div>
                                <div class="info-value">
                                    <div class="documents-section">
                                        <ul class="documents-list">
                                            <?php foreach($fileFields as $field => $label): 
                                                if (!empty($applicant[$field])):
                                                    $filePath = $applicant[$field];
                                                    $fileName = basename($filePath);
                                                    $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
                                                    $icon = ($fileExtension == 'pdf') ? 'fa-file-pdf' : 'fa-file';
                                            ?>
                                            <li class="document-item">
                                                <div class="document-info">
                                                    <i class="fas <?= $icon ?>"></i>
                                                    <div class="document-name">
                                                        <?= htmlspecialchars($label) ?>: <?= $fileName ?>
                                                    </div>
                                                </div>
                                                <div class="document-actions">
                                                    <a href="<?= htmlspecialchars($filePath) ?>" class="doc-btn download" download title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars($filePath) ?>" class="doc-btn" target="_blank" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </li>
                                            <?php endif; endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Card Footer -->
                    <div class="card-footer">
                        <span class="status-pill-v status-pill-v--<?= htmlspecialchars($procKey) ?>"><?= htmlspecialchars($procLabel) ?></span>
                        <div class="action-buttons">
                            <?php if ($emailText !== ''): ?>
                            <a href="mailto:<?= htmlspecialchars($emailText) ?>" class="contact-btn" title="Send Email">
                                <i class="fas fa-envelope"></i> <span>Contact</span>
                            </a>
                            <?php endif; ?>
                            <button class="view-btn view-details-btn" 
                                    data-applicant='<?= htmlspecialchars(json_encode([
                                        'name' => $fullName,
                                        'id' => $applicant['user_id'],
                                        'email' => $applicant['email'],
                                        'mobile' => $applicant['applicant_mobile'],
                                        'birthdate' => ($birthText !== '' ? $birthText : ''),
                                        'gender' => $applicant['gender'],
                                        'passport' => $passportInfo,
                                        'passport_number' => $applicant['passport_number'] ?? '',
                                        'country_from' => $applicant['country_applying_from'],
                                        'country_to' => $applicant['country_to_visit'],
                                        'visa_type' => $applicant['visa_type'],
                                        'intake' => $intakeText,
                                        'region' => $applicant['region_name'],
                                        'country' => $applicant['country_name'],
                                        'terms' => $applicant['terms'],
                                        'applied' => $appliedDate,
                                        'is_read' => $applicant['is_read'],
                                        'processStatus' => $procKey,
                                        'processLabel' => $procLabel,
                                    ], JSON_UNESCAPED_UNICODE)) ?>'>
                                <i class="fas fa-eye"></i> <span>Details</span>
                            </button>
                            <?php if ($canDeleteVisaApplication): ?>
                            <button type="button" class="btn-delete-visa btn-delete-visa-app" data-user-id="<?= htmlspecialchars($applicant['user_id']) ?>" title="Delete this application (Superadmin)">
                                <i class="fas fa-trash-alt"></i> <span>Delete</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div id="visaLiveEmpty" class="empty-state" style="display:none; grid-column: 1 / -1;">
                <i class="fas fa-search"></i>
                <h3>No applications match</h3>
                <p>Try a different name, email, passport number, or ID.</p>
            </div>
        </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: white; border-radius: 10px; max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div style="background: <?= $colors['navy'] ?>; color: white; padding: 20px; border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1.2rem;"><i class="fas fa-passport"></i> Visa Application Details</h3>
                <button id="closeModal" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div style="padding: 20px;" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <div id="visaStatusNotifyModal" aria-hidden="true">
        <div class="visa-notify-dialog">
            <div class="visa-notify-header">
                <div>
                    <h3><i class="fas fa-bell"></i> Save status</h3>
                    <p>Notify the applicant (visit or study visa)</p>
                </div>
                <button type="button" class="visa-notify-close" id="visaNotifyCloseX" aria-label="Close">&times;</button>
            </div>
            <div class="visa-notify-body">
                <div class="visa-notify-preview">
                    <div class="visa-notify-badge">New status</div>
                    <div class="visa-notify-label-text" id="visaNotifyStatusLabel">—</div>
                </div>
                <div id="visaRejectReasonWrap" style="display:none;margin-bottom:14px;">
                    <label for="visaRejectReason" style="display:block;font-weight:600;font-size:0.85rem;color:#b91c1c;margin-bottom:6px;">Reason for rejection</label>
                    <textarea id="visaRejectReason" rows="3" maxlength="2000" style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:0.9rem;resize:vertical;" placeholder="Required if you send email or WhatsApp"></textarea>
                    <p style="margin:6px 0 0;font-size:0.78rem;color:#64748b;">Included in the message to the applicant when you notify.</p>
                </div>
                <div id="visaNotifyIntakeWrap" style="display:none;margin-bottom:14px;">
                    <label for="visaNotifyIntake" style="display:block;font-weight:600;font-size:0.85rem;color:#14532d;margin-bottom:6px;">Changed to which intake?</label>
                    <select id="visaNotifyIntake" style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:0.9rem;background:#fff;">
                        <option value="">Select intake…</option>
                        <?php foreach ($visaIntakeByCountry as $groupLabel => $intakeLabels): ?>
                        <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                            <?php foreach ($intakeLabels as $intakeLabel): ?>
                            <option value="<?= htmlspecialchars($intakeLabel) ?>"><?= htmlspecialchars($intakeLabel) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p style="margin:6px 0 0;font-size:0.78rem;color:#64748b;">Saved on the applicant record with this status change.</p>
                </div>
                <p class="visa-notify-hint">Choose one — you can save without a notification.</p>
                <div class="visa-notify-grid">
                    <button type="button" class="visa-notify-channel active" data-ne="0" data-nw="0">Record only<span>No notification</span></button>
                    <button type="button" class="visa-notify-channel" data-ne="1" data-nw="0">Email<span>Send email</span></button>
                    <button type="button" class="visa-notify-channel" data-ne="0" data-nw="1">WhatsApp<span>Send WhatsApp</span></button>
                    <button type="button" class="visa-notify-channel" data-ne="1" data-nw="1">Both<span>Email + WhatsApp</span></button>
                </div>
            </div>
            <div class="visa-notify-footer">
                <button type="button" class="visa-notify-cancel" id="visaNotifyCancel">Cancel</button>
                <button type="button" class="visa-notify-confirm" id="visaNotifyConfirm">Save</button>
            </div>
        </div>
    </div>

    <div id="processToastV" class="process-toast-v" role="status" aria-live="polite">
        <i class="fas fa-check-circle"></i>
        <span id="processToastMsgV">Saved</span>
    </div>

    <script>
        window.JOB_PROCESS_ORDER = <?= json_encode($JOB_PROCESS_ORDER, JSON_UNESCAPED_UNICODE) ?>;
        window.JOB_PROCESS_LABELS = <?= json_encode($JOB_PROCESS_STATUSES, JSON_UNESCAPED_UNICODE) ?>;
        window.CAN_DELETE_VISA = <?= json_encode($canDeleteVisaApplication) ?>;
        window.CAN_EDIT_VISA_PROCESS = <?= json_encode($canEditVisaProcess) ?>;
        window.VISA_DEST_COUNTRIES = <?= json_encode($visaDestCountries, JSON_UNESCAPED_UNICODE) ?>;
        window.VISA_FEATURED_DEST_COUNTRIES = <?= json_encode($visaFeaturedDestCountries, JSON_UNESCAPED_UNICODE) ?>;
        window.VISA_INTAKE_BY_COUNTRY = <?= json_encode($visaIntakeByCountry, JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.visa-process-select').forEach(function(sel) {
                sel.setAttribute('data-prev-status', sel.value);
            });

            const modal = document.getElementById('detailsModal');
            const modalContent = document.getElementById('modalContent');
            const closeModal = document.getElementById('closeModal');
            
            // View details button click
            document.querySelectorAll('.view-details-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const applicantData = JSON.parse(this.getAttribute('data-applicant'));
                    showApplicantDetails(applicantData);
                    
                    // Mark as read
                    const card = this.closest('.applicant-card');
                    const userId = card.getAttribute('data-id');
                    markAsRead(userId, card);
                });
            });
            
            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function buildCountryOptionsHtml(selected) {
                var featured = window.VISA_FEATURED_DEST_COUNTRIES || [];
                var all = window.VISA_DEST_COUNTRIES || [];
                var selectedVal = String(selected || '');
                var featuredSet = {};
                var html = '<option value="">Select country…</option>';
                if (featured.length) {
                    html += '<optgroup label="Popular destinations">';
                    featured.forEach(function(name) {
                        featuredSet[name] = true;
                        html += '<option value="' + escapeHtml(name) + '"' + (selectedVal === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
                    });
                    html += '</optgroup>';
                }
                html += '<optgroup label="All countries">';
                all.forEach(function(name) {
                    if (featuredSet[name]) return;
                    html += '<option value="' + escapeHtml(name) + '"' + (selectedVal === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
                });
                html += '</optgroup>';
                if (selectedVal && all.indexOf(selectedVal) === -1 && !featuredSet[selectedVal]) {
                    html += '<option value="' + escapeHtml(selectedVal) + '" selected>' + escapeHtml(selectedVal) + '</option>';
                }
                return html;
            }

            function buildIntakeOptionsHtml(selected) {
                var groups = window.VISA_INTAKE_BY_COUNTRY || {};
                var selectedVal = String(selected || '');
                var html = '<option value="">Select intake…</option>';
                Object.keys(groups).forEach(function(groupLabel) {
                    html += '<optgroup label="' + escapeHtml(groupLabel) + '">';
                    (groups[groupLabel] || []).forEach(function(label) {
                        html += '<option value="' + escapeHtml(label) + '"' + (selectedVal === label ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
                    });
                    html += '</optgroup>';
                });
                if (selectedVal) {
                    var known = false;
                    Object.keys(groups).forEach(function(g) {
                        if ((groups[g] || []).indexOf(selectedVal) !== -1) known = true;
                    });
                    if (!known) {
                        html += '<option value="' + escapeHtml(selectedVal) + '" selected>' + escapeHtml(selectedVal) + '</option>';
                    }
                }
                return html;
            }

            // Show applicant details in modal
            function showApplicantDetails(data) {
                const procLabel = data.processLabel || (window.JOB_PROCESS_LABELS && data.processStatus ? window.JOB_PROCESS_LABELS[data.processStatus] : '') || '—';
                const canEdit = !!window.CAN_EDIT_VISA_PROCESS;
                const birthVal = (data.birthdate && data.birthdate !== 'null' && data.birthdate !== '0000-00-00') ? String(data.birthdate) : '';
                const genderVal = (data.gender && data.gender !== 'null') ? String(data.gender) : '';
                const visaType = data.visa_type || 'Study Visa';
                const intakeVal = data.intake || '';
                const passportNum = data.passport_number || ((data.passport || '').replace(/\s*\(Exp:.*$/, '').trim());

                if (!canEdit) {
                    modalContent.innerHTML = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div style="grid-column: 1 / -1; background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 15px; border-radius: 8px; text-align: center;">
                            <h4 style="margin: 0; color: <?= $colors['navy'] ?>;">${escapeHtml(data.name)}</h4>
                            <p style="margin: 5px 0 0 0; color: #666;">ID: ${escapeHtml(data.id)} | Applied on ${escapeHtml(data.applied)}</p>
                        </div>
                        <div style="grid-column: 1 / -1; background: rgba(37, 77, 129, 0.08); padding: 12px 14px; border-radius: 8px; border-left: 4px solid <?= $colors['gold'] ?>;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>; font-size: 0.85rem;">Application process</strong>
                            <div style="margin-top: 6px; font-weight: 700; color: <?= $colors['navy'] ?>;">${escapeHtml(procLabel)}</div>
                        </div>
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>;">Contact Information</strong>
                            <div style="margin-top: 8px;">
                                <div><i class="fas fa-envelope" style="color: <?= $colors['gold'] ?>;"></i> ${escapeHtml(data.email || '—')}</div>
                                <div><i class="fas fa-mobile-alt" style="color: <?= $colors['gold'] ?>;"></i> ${escapeHtml(data.mobile || '—')}</div>
                            </div>
                        </div>
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>;">Personal Details</strong>
                            <div style="margin-top: 8px;">
                                <div><i class="fas fa-birthday-cake" style="color: <?= $colors['gold'] ?>;"></i> ${escapeHtml(birthVal || '—')}</div>
                                <div><i class="fas fa-venus-mars" style="color: <?= $colors['gold'] ?>;"></i> ${escapeHtml(genderVal || '—')}</div>
                            </div>
                        </div>
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>;">Passport Information</strong>
                            <div style="margin-top: 8px;">
                                <div><i class="fas fa-passport" style="color: <?= $colors['gold'] ?>;"></i> ${escapeHtml(data.passport || '—')}</div>
                            </div>
                        </div>
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>;">Travel Information</strong>
                            <div style="margin-top: 8px;">
                                <div><i class="fas fa-plane-departure" style="color: <?= $colors['gold'] ?>;"></i> From: ${escapeHtml(data.country_from || '—')}</div>
                                <div><i class="fas fa-plane-arrival" style="color: <?= $colors['gold'] ?>;"></i> To: ${escapeHtml(data.country_to || '—')}</div>
                                <div><i class="fas fa-file-alt" style="color: <?= $colors['gold'] ?>;"></i> Visa Type: <span class="status-tag visa">${escapeHtml(visaType)}</span></div>
                                ${intakeVal ? `<div><i class="fas fa-calendar-alt" style="color: <?= $colors['gold'] ?>;"></i> Intake: ${escapeHtml(intakeVal)}</div>` : ''}
                            </div>
                        </div>
                        <div style="grid-column: 1 / -1; background: rgba(242, 166, 90, 0.1); padding: 12px; border-radius: 6px; border-left: 4px solid <?= $colors['gold'] ?>;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>;">Application Status</strong>
                            <div style="margin-top: 8px;">
                                <div><i class="fas fa-info-circle" style="color: <?= $colors['gold'] ?>;"></i> Application ID: ${escapeHtml(data.id)}</div>
                                <div><i class="fas fa-calendar" style="color: <?= $colors['gold'] ?>;"></i> Submitted: ${escapeHtml(data.applied)}</div>
                                <div><i class="fas fa-eye" style="color: <?= $colors['gold'] ?>;"></i> Read Status: ${data.is_read == 0 ? 'Unread' : 'Read'}</div>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 15px; border-top: 1px solid #dee2e6;">
                        <a href="mailto:${escapeHtml(data.email || '')}" class="contact-btn" style="display: inline-flex; text-decoration: none;">
                            <i class="fas fa-envelope"></i> Send Email
                        </a>
                        <button type="button" id="closeDetails" class="search-btn" style="background: #666;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>`;
                } else {
                    modalContent.innerHTML = `
                    <form id="visaEditDetailsForm">
                        <input type="hidden" name="user_id" value="${escapeHtml(data.id)}">
                        <div style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 14px;">
                            <h4 style="margin: 0; color: <?= $colors['navy'] ?>;" id="visaEditTitleName">${escapeHtml(data.name)}</h4>
                            <p style="margin: 5px 0 0 0; color: #666;">ID: ${escapeHtml(data.id)} | Applied on ${escapeHtml(data.applied)}</p>
                        </div>
                        <div style="background: rgba(37, 77, 129, 0.08); padding: 12px 14px; border-radius: 8px; border-left: 4px solid <?= $colors['gold'] ?>; margin-bottom: 14px;">
                            <strong style="color: <?= $colors['secondary_blue'] ?>; font-size: 0.85rem;">Application process</strong>
                            <div style="margin-top: 6px; font-weight: 700; color: <?= $colors['navy'] ?>;">${escapeHtml(procLabel)}</div>
                            <p style="margin: 6px 0 0; font-size: 12px; color: #64748b;">Change status from the applicant card. Edit personal and travel details below.</p>
                        </div>
                        <p class="visa-edit-msg" id="visaEditMsg"></p>
                        <div class="visa-edit-grid">
                            <label class="full">Full name
                                <input name="full_name" required maxlength="200" value="${escapeHtml(data.name || '')}">
                            </label>
                            <label>Email
                                <input name="email" type="email" maxlength="150" value="${escapeHtml(data.email || '')}" placeholder="Optional">
                            </label>
                            <label>Phone
                                <input name="phone" maxlength="20" value="${escapeHtml(data.mobile || '')}" placeholder="Optional">
                            </label>
                            <label>Birth date
                                <input name="birthdate" type="date" value="${escapeHtml(birthVal)}">
                            </label>
                            <label>Gender
                                <select name="gender">
                                    <option value="">Not specified</option>
                                    <option value="Male"${genderVal === 'Male' ? ' selected' : ''}>Male</option>
                                    <option value="Female"${genderVal === 'Female' ? ' selected' : ''}>Female</option>
                                    <option value="Other"${genderVal === 'Other' ? ' selected' : ''}>Other</option>
                                </select>
                            </label>
                            <label>Passport number
                                <input name="passport_number" maxlength="50" value="${escapeHtml(passportNum || '')}" placeholder="Optional">
                            </label>
                            <label>Visa type
                                <select name="visa_type" id="visaEditVisaType">
                                    <option${visaType === 'Study Visa' ? ' selected' : ''}>Study Visa</option>
                                    <option${visaType === 'Visit Visa' ? ' selected' : ''}>Visit Visa</option>
                                    <option${visaType === 'Work Visa' ? ' selected' : ''}>Work Visa</option>
                                </select>
                            </label>
                            <label>Applying from
                                <select name="country_applying_from">${buildCountryOptionsHtml(data.country_from)}</select>
                            </label>
                            <label>Destination country
                                <select name="destination_country">${buildCountryOptionsHtml(data.country_to)}</select>
                            </label>
                            <label class="full" id="visaEditIntakeWrap">Choose intake
                                <select name="intake" id="visaEditIntake">${buildIntakeOptionsHtml(intakeVal)}</select>
                            </label>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 15px; margin-top: 15px; border-top: 1px solid #dee2e6; flex-wrap: wrap;">
                            <a href="mailto:${escapeHtml(data.email || '')}" class="contact-btn" style="display: inline-flex; text-decoration: none;">
                                <i class="fas fa-envelope"></i> Send Email
                            </a>
                            <button type="button" id="closeDetails" class="search-btn" style="background: #666;">
                                <i class="fas fa-times"></i> Close
                            </button>
                            <button type="submit" class="search-btn" style="background: #166534;" id="visaEditSaveBtn">
                                <i class="fas fa-save"></i> Save details
                            </button>
                        </div>
                    </form>`;
                }

                modal.style.display = 'flex';

                var closeBtn = document.getElementById('closeDetails');
                if (closeBtn) closeBtn.onclick = hideModal;
                closeModal.onclick = hideModal;
                modal.onclick = function(e) {
                    if (e.target === modal) hideModal();
                };

                if (canEdit) {
                    var form = document.getElementById('visaEditDetailsForm');
                    var typeSel = document.getElementById('visaEditVisaType');
                    var intakeWrap = document.getElementById('visaEditIntakeWrap');
                    var intakeSel = document.getElementById('visaEditIntake');
                    function syncEditIntake() {
                        var isStudy = typeSel && typeSel.value === 'Study Visa';
                        if (intakeWrap) intakeWrap.style.display = isStudy ? '' : 'none';
                        if (!isStudy && intakeSel) intakeSel.value = '';
                    }
                    if (typeSel) typeSel.addEventListener('change', syncEditIntake);
                    syncEditIntake();

                    if (form) {
                        form.addEventListener('submit', function(ev) {
                            ev.preventDefault();
                            var msg = document.getElementById('visaEditMsg');
                            var saveBtn = document.getElementById('visaEditSaveBtn');
                            syncEditIntake();
                            var fd = new FormData(form);
                            if (msg) {
                                msg.className = 'visa-edit-msg';
                                msg.textContent = 'Saving…';
                            }
                            if (saveBtn) saveBtn.disabled = true;
                            fetch('api/form17-update-applicant.php', {
                                method: 'POST',
                                body: fd,
                                credentials: 'same-origin'
                            }).then(function(r) { return r.json(); }).then(function(json) {
                                if (saveBtn) saveBtn.disabled = false;
                                if (!json.success) {
                                    if (msg) {
                                        msg.className = 'visa-edit-msg err';
                                        msg.textContent = json.message || 'Could not save details.';
                                    }
                                    return;
                                }
                                var saved = json.data || {};
                                if (msg) {
                                    msg.className = 'visa-edit-msg ok';
                                    msg.textContent = 'Details saved.';
                                }
                                var title = document.getElementById('visaEditTitleName');
                                if (title && saved.name) title.textContent = saved.name;

                                var uid = String(data.id || '');
                                var card = null;
                                document.querySelectorAll('.applicant-card').forEach(function(c) {
                                    if (c.getAttribute('data-user-id') === uid || c.getAttribute('data-id') === uid) card = c;
                                });
                                if (card) {
                                    var nameEl = card.querySelector('.applicant-name');
                                    if (nameEl && saved.name) nameEl.textContent = saved.name;
                                    updateCardIntakeUI(card, saved.intake || '');
                                    var viewBtn = card.querySelector('.view-details-btn');
                                    if (viewBtn) {
                                        try {
                                            var d = JSON.parse(viewBtn.getAttribute('data-applicant'));
                                            Object.keys(saved).forEach(function(k) {
                                                if (k === 'user_id') d.id = saved.user_id;
                                                else d[k] = saved[k];
                                            });
                                            viewBtn.setAttribute('data-applicant', JSON.stringify(d));
                                        } catch (err3) {}
                                    }
                                    var searchBits = [
                                        saved.name, saved.email, saved.mobile, saved.passport,
                                        saved.visa_type, saved.intake, saved.country_from, saved.country_to, data.id
                                    ];
                                    card.setAttribute('data-search', searchBits.filter(Boolean).join(' ').toLowerCase());
                                    if (window.visaFilterCards) window.visaFilterCards();
                                }
                            }).catch(function() {
                                if (saveBtn) saveBtn.disabled = false;
                                if (msg) {
                                    msg.className = 'visa-edit-msg err';
                                    msg.textContent = 'Network error.';
                                }
                            });
                        });
                    }
                }
            }
            
            function hideModal() {
                modal.style.display = 'none';
            }
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if(e.key === 'Escape' && modal.style.display === 'flex') {
                    hideModal();
                }
            });
            
            // Mark as read function
            function markAsRead(userId, cardElement) {
                if (!cardElement.classList.contains('unread')) return;
                
                fetch('mark_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + encodeURIComponent(userId) + '&table=form_17_applications'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        cardElement.classList.remove('unread');
                        const dot = cardElement.querySelector('.unread-dot');
                        if (dot) dot.style.display = 'none';
                    }
                })
                .catch(err => console.error('Error marking as read:', err));
            }
            
            // Mark all as read button (optional - add if needed)
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('mark-all-read')) {
                    document.querySelectorAll('.applicant-card.unread').forEach(card => {
                        const userId = card.getAttribute('data-id');
                        markAsRead(userId, card);
                    });
                }
            });

            function shiftVisaKpi(fromKey, toKey) {
                if (!fromKey || !toKey || fromKey === toKey) return;
                [fromKey, toKey].forEach(function(key, index) {
                    var el = document.querySelector('[data-kpi-num="' + key + '"]');
                    if (!el) return;
                    var n = parseInt(el.textContent, 10) || 0;
                    el.textContent = String(Math.max(0, n + (index === 0 ? -1 : 1)));
                });
            }

            function showProcessToastV(msg) {
                const t = document.getElementById('processToastV');
                const m = document.getElementById('processToastMsgV');
                if (!t || !m) return;
                m.textContent = msg;
                t.classList.add('show');
                clearTimeout(window._processToastTimerV);
                window._processToastTimerV = setTimeout(function() { t.classList.remove('show'); }, 2800);
            }

            function updateCardVisaProcessUI(card, statusKey) {
                if (!card || !window.JOB_PROCESS_ORDER) return;
                const order = window.JOB_PROCESS_ORDER;
                const labels = window.JOB_PROCESS_LABELS || {};
                const curIdx = order.indexOf(statusKey);
                const idx = curIdx === -1 ? 0 : curIdx;
                card.querySelectorAll('.process-step').forEach(function(step, i) {
                    step.classList.remove('done', 'current', 'pending');
                    var st = i < idx ? 'done' : (i === idx ? 'current' : 'pending');
                    step.classList.add(st);
                });
                var pill = card.querySelector('.status-pill-v');
                if (pill) {
                    pill.className = 'status-pill-v status-pill-v--' + statusKey;
                    pill.textContent = labels[statusKey] || statusKey;
                }
                var sel = card.querySelector('.visa-process-select');
                if (sel && sel.querySelector('option[value="' + statusKey + '"]')) {
                    sel.value = statusKey;
                }
            }

            var visaNotifyModal = document.getElementById('visaStatusNotifyModal');
            var visaNotifyPending = null;

            document.querySelectorAll('.visa-notify-channel').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.visa-notify-channel').forEach(function(b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                });
            });

            function openVisaNotifyModal() {
                if (!visaNotifyModal) return;
                visaNotifyModal.classList.add('show-flex');
                visaNotifyModal.style.display = 'flex';
                visaNotifyModal.setAttribute('aria-hidden', 'false');
            }
            function closeVisaNotifyModal() {
                if (!visaNotifyModal) return;
                visaNotifyModal.classList.remove('show-flex');
                visaNotifyModal.style.display = 'none';
                visaNotifyModal.setAttribute('aria-hidden', 'true');
                visaNotifyPending = null;
                var vri = document.getElementById('visaRejectReason');
                var vrw = document.getElementById('visaRejectReasonWrap');
                if (vri) vri.value = '';
                if (vrw) vrw.style.display = 'none';
                var vi = document.getElementById('visaNotifyIntake');
                var viw = document.getElementById('visaNotifyIntakeWrap');
                if (vi) vi.value = '';
                if (viw) viw.style.display = 'none';
            }

            function isVisaIntakeChangeStatus(key) {
                return key === 'intake_changed' || key === 'intake_changed_waiting_documents';
            }

            function updateCardIntakeUI(card, intakeText) {
                if (!card) return;
                var facts = card.querySelector('.card-facts');
                if (!facts) return;
                var existing = card.querySelector('.card-fact-intake');
                var text = (intakeText || '').trim();
                if (!text) {
                    if (existing) existing.remove();
                    return;
                }
                if (!existing) {
                    existing = document.createElement('div');
                    existing.className = 'card-fact card-fact-intake';
                    existing.innerHTML = '<i class="fas fa-calendar-alt"></i><span></span>';
                    facts.appendChild(existing);
                }
                var span = existing.querySelector('span');
                if (span) span.textContent = text;
            }

            document.getElementById('visaNotifyCancel').addEventListener('click', closeVisaNotifyModal);
            document.getElementById('visaNotifyCloseX').addEventListener('click', closeVisaNotifyModal);
            visaNotifyModal.addEventListener('click', function(ev) {
                if (ev.target === visaNotifyModal) closeVisaNotifyModal();
            });

            document.getElementById('visaNotifyConfirm').addEventListener('click', function() {
                var p = visaNotifyPending;
                if (!p || !p.sel) return;
                var active = document.querySelector('.visa-notify-channel.active');
                var ne = active ? parseInt(active.getAttribute('data-ne'), 10) || 0 : 0;
                var nw = active ? parseInt(active.getAttribute('data-nw'), 10) || 0 : 0;
                var sel = p.sel;
                var statusKey = p.newKey;
                var userId = p.userId;
                var vrEl = document.getElementById('visaRejectReason');
                var rejectReason = vrEl ? (vrEl.value || '').trim() : '';
                var intakeEl = document.getElementById('visaNotifyIntake');
                var intakeValue = intakeEl ? (intakeEl.value || '').trim() : '';
                if (statusKey === 'rejected' && (ne || nw) && rejectReason === '') {
                    alert('Please enter a rejection reason before sending email or WhatsApp.');
                    return;
                }
                if (isVisaIntakeChangeStatus(statusKey) && !intakeValue) {
                    alert('Please choose which intake this application changed to.');
                    return;
                }
                closeVisaNotifyModal();
                sel.disabled = true;
                var fd = new FormData();
                fd.append('user_id', userId);
                fd.append('process_status', statusKey);
                fd.append('notify_email', ne ? '1' : '0');
                fd.append('notify_whatsapp', nw ? '1' : '0');
                fd.append('rejection_reason', rejectReason);
                if (intakeValue) fd.append('intake', intakeValue);
                fetch('api/form17-application-status.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    sel.disabled = false;
                    if (!json.success) {
                        alert(json.message || 'Could not update status');
                        sel.value = p.prevKey;
                        return;
                    }
                    sel.value = statusKey;
                    sel.setAttribute('data-prev-status', statusKey);
                    shiftVisaKpi(p.prevKey, statusKey);
                    var card = sel.closest('.applicant-card');
                    updateCardVisaProcessUI(card, statusKey);
                    if (json.data && json.data.intake) {
                        updateCardIntakeUI(card, json.data.intake);
                    }
                    var label = (json.data && json.data.label) ? json.data.label : ((window.JOB_PROCESS_LABELS && window.JOB_PROCESS_LABELS[statusKey]) || statusKey);
                    var n = json.data && json.data.notify;
                    var parts = ['Status saved'];
                    var anyFail = false;
                    if ((ne || nw) && !n) {
                        anyFail = true;
                        parts.push('Notifications failed (server error).');
                    }
                    if (n && n.email && n.email.requested) {
                        if (n.email.sent) parts.push('Email sent');
                        else { anyFail = true; parts.push('Email failed' + (n.email.error ? ': ' + n.email.error : '')); }
                    }
                    if (n && n.whatsapp && n.whatsapp.requested) {
                        if (n.whatsapp.sent) {
                            parts.push(n.whatsapp.method === 'text' ? 'WhatsApp sent (session)' : 'WhatsApp sent');
                        } else {
                            anyFail = true;
                            parts.push('WhatsApp failed' + (n.whatsapp.error ? ': ' + n.whatsapp.error : ''));
                        }
                    }
                    if (!ne && !nw) {
                        parts.length = 1;
                        parts[0] = 'Status saved (no notification)';
                    }
                    var msg = parts.join(' · ');
                    var toast = document.getElementById('processToastV');
                    var toastMsg = document.getElementById('processToastMsgV');
                    if (toast && toastMsg) {
                        toast.classList.toggle('warn', anyFail);
                        toastMsg.textContent = msg;
                        toast.classList.add('show');
                        clearTimeout(window._visaToastTimer);
                        window._visaToastTimer = setTimeout(function() { toast.classList.remove('show'); toast.classList.remove('warn'); }, 3800);
                    } else {
                        alert(msg);
                    }
                    var viewBtn = card ? card.querySelector('.view-details-btn') : null;
                    if (viewBtn) {
                        try {
                            var d = JSON.parse(viewBtn.getAttribute('data-applicant'));
                            d.processStatus = statusKey;
                            d.processLabel = label;
                            if (json.data && json.data.intake) d.intake = json.data.intake;
                            viewBtn.setAttribute('data-applicant', JSON.stringify(d));
                        } catch (err2) {}
                    }
                })
                .catch(function(err) {
                    sel.disabled = false;
                    sel.value = p.prevKey;
                    console.error(err);
                    alert('Network error while saving status.');
                });
            });

            document.addEventListener('focusin', function(e) {
                var t = e.target;
                if (t.classList && t.classList.contains('visa-process-select')) {
                    t.setAttribute('data-prev-status', t.value);
                }
            });

            document.addEventListener('change', function(e) {
                var sel = e.target;
                if (!sel.classList || !sel.classList.contains('visa-process-select')) return;
                var userId = sel.getAttribute('data-user-id');
                var newKey = sel.value;
                var prevKey = sel.getAttribute('data-prev-status');
                if (prevKey === null || prevKey === '') return;
                if (!userId || newKey === prevKey) return;
                sel.value = prevKey;
                var labels = window.JOB_PROCESS_LABELS || {};
                document.getElementById('visaNotifyStatusLabel').textContent = labels[newKey] || newKey;
                document.querySelectorAll('.visa-notify-channel').forEach(function(b, i) {
                    b.classList.toggle('active', i === 0);
                });
                var vrr = document.getElementById('visaRejectReasonWrap');
                var vri = document.getElementById('visaRejectReason');
                if (vrr && vri) {
                    vrr.style.display = newKey === 'rejected' ? 'block' : 'none';
                    vri.value = '';
                }
                var viw = document.getElementById('visaNotifyIntakeWrap');
                var vi = document.getElementById('visaNotifyIntake');
                if (viw && vi) {
                    viw.style.display = isVisaIntakeChangeStatus(newKey) ? 'block' : 'none';
                    vi.value = '';
                }
                visaNotifyPending = { sel: sel, userId: userId, newKey: newKey, prevKey: prevKey };
                openVisaNotifyModal();
            });

            var visaAddForm = document.getElementById('visaAddForm');
            if (visaAddForm) {
                (function initVisaSmartCountry() {
                    var featured = <?= json_encode($visaFeaturedDestCountries, JSON_UNESCAPED_UNICODE) ?>;
                    var allCountries = <?= json_encode($visaDestCountries, JSON_UNESCAPED_UNICODE) ?>;
                    var field = document.getElementById('visaDestCountryField');
                    var hidden = document.getElementById('visaDestCountry');
                    var btn = document.getElementById('visaDestCountryBtn');
                    var label = document.getElementById('visaDestCountryLabel');
                    var panel = document.getElementById('visaDestCountryPanel');
                    var search = document.getElementById('visaDestCountrySearch');
                    var list = document.getElementById('visaDestCountryList');
                    if (!field || !hidden || !btn || !label || !panel || !search || !list) return;

                    function setOpen(open) {
                        field.classList.toggle('open', open);
                        panel.hidden = !open;
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                        if (open) {
                            search.focus();
                            search.select();
                        }
                    }

                    function syncIntakeForCountry(name) {
                        var intakeSel = document.getElementById('visaIntakeSelect');
                        if (!intakeSel) return;
                        var n = String(name || '').toLowerCase();
                        var preferUsa = n === 'united states' || n === 'usa' || n === 'us';
                        var preferCanada = n === 'canada';
                        var groups = intakeSel.querySelectorAll('optgroup');
                        groups.forEach(function(g) {
                            var labelText = String(g.label || '').toLowerCase();
                            var isUsa = labelText.indexOf('usa') !== -1 || labelText.indexOf('united states') !== -1;
                            var isCanada = labelText.indexOf('canada') !== -1;
                            if (preferUsa) {
                                g.disabled = !isUsa;
                                g.hidden = !isUsa;
                            } else if (preferCanada) {
                                g.disabled = !isCanada;
                                g.hidden = !isCanada;
                            } else {
                                g.disabled = false;
                                g.hidden = false;
                            }
                        });
                        if (preferUsa || preferCanada) {
                            var cur = intakeSel.value;
                            var stillVisible = false;
                            Array.prototype.forEach.call(intakeSel.options, function(opt) {
                                if (opt.value && opt.value === cur && !opt.parentNode.disabled && !opt.parentNode.hidden) {
                                    stillVisible = true;
                                }
                            });
                            if (!stillVisible) intakeSel.value = '';
                        }
                    }

                    function setCountry(name) {
                        hidden.value = name || '';
                        if (name) {
                            label.textContent = name;
                            btn.setAttribute('data-empty', '0');
                        } else {
                            label.textContent = 'Search or select country…';
                            btn.setAttribute('data-empty', '1');
                        }
                        syncIntakeForCountry(name);
                        setOpen(false);
                    }

                    function render(filter) {
                        var q = (filter || '').trim().toLowerCase();
                        list.innerHTML = '';
                        function addGroup(title, names) {
                            var filtered = names.filter(function(n) {
                                return !q || String(n).toLowerCase().indexOf(q) !== -1;
                            });
                            if (!filtered.length) return 0;
                            var head = document.createElement('div');
                            head.className = 'visa-country-group';
                            head.textContent = title;
                            list.appendChild(head);
                            filtered.forEach(function(name) {
                                var li = document.createElement('div');
                                var b = document.createElement('button');
                                b.type = 'button';
                                b.textContent = name;
                                b.setAttribute('role', 'option');
                                b.setAttribute('aria-selected', hidden.value === name ? 'true' : 'false');
                                b.addEventListener('click', function() { setCountry(name); });
                                li.appendChild(b);
                                list.appendChild(li);
                            });
                            return filtered.length;
                        }
                        var featuredSet = {};
                        (featured || []).forEach(function(n) { featuredSet[n] = true; });
                        var rest = (allCountries || []).filter(function(n) { return !featuredSet[n]; });
                        var shown = 0;
                        if (!q) {
                            shown += addGroup('Popular destinations', featured || []);
                        }
                        shown += addGroup(q ? 'Matching countries' : 'All countries', q ? (allCountries || []) : rest);
                        if (!shown) {
                            var empty = document.createElement('div');
                            empty.className = 'visa-country-empty';
                            empty.textContent = 'No countries match that search.';
                            list.appendChild(empty);
                        }
                    }

                    btn.addEventListener('click', function() {
                        setOpen(panel.hidden);
                        if (!panel.hidden) render(search.value);
                    });
                    search.addEventListener('input', function() { render(search.value); });
                    search.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') setOpen(false);
                    });
                    document.addEventListener('click', function(e) {
                        if (!field.contains(e.target)) setOpen(false);
                    });
                    render('');
                })();

                (function syncVisaAddIntakeVisibility() {
                    var typeSel = document.getElementById('visaAddVisaType');
                    var wrap = document.getElementById('visaAddIntakeWrap');
                    var intakeSel = document.getElementById('visaIntakeSelect');
                    if (!typeSel || !wrap) return;
                    function apply() {
                        var isStudy = typeSel.value === 'Study Visa';
                        wrap.style.display = isStudy ? '' : 'none';
                        if (!isStudy && intakeSel) intakeSel.value = '';
                    }
                    typeSel.addEventListener('change', apply);
                    apply();
                })();

                visaAddForm.addEventListener('submit', function(ev) {
                    ev.preventDefault();
                    var msg = document.getElementById('visaAddMsg');
                    var dest = document.getElementById('visaDestCountry');
                    if (!dest || !dest.value) {
                        if (msg) msg.textContent = 'Choose a destination country.';
                        return;
                    }
                    var typeSel = document.getElementById('visaAddVisaType');
                    var intakeSel = document.getElementById('visaIntakeSelect');
                    if (typeSel && typeSel.value !== 'Study Visa' && intakeSel) {
                        intakeSel.value = '';
                    }
                    var fd = new FormData(visaAddForm);
                    if (msg) msg.textContent = 'Saving…';
                    fetch('api/form17-add-applicant.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    }).then(function(r) { return r.json(); }).then(function(json) {
                        if (!json.success) {
                            if (msg) msg.textContent = json.message || 'Could not add the applicant.';
                            return;
                        }
                        window.location.reload();
                    }).catch(function() {
                        if (msg) msg.textContent = 'Network error.';
                    });
                });
            }

            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.btn-delete-visa-app');
                if (!btn) return;
                if (!window.CAN_DELETE_VISA) {
                    alert('Only Superadmin can delete applications.');
                    return;
                }
                var userId = btn.getAttribute('data-user-id');
                if (!userId) return;
                if (!confirm('Permanently delete this visa application? This cannot be undone.')) return;
                var fd = new FormData();
                fd.append('user_id', userId);
                btn.disabled = true;
                fetch('api/form17-application-delete.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    btn.disabled = false;
                    if (!json.success) {
                        alert(json.message || 'Delete failed');
                        return;
                    }
                    var card = btn.closest('.applicant-card');
                    if (card) card.remove();
                    if (window.visaFilterCards) window.visaFilterCards();
                    showProcessToastV('Application deleted');
                })
                .catch(function(err) {
                    btn.disabled = false;
                    console.error(err);
                    alert('Delete failed.');
                });
            });
        });
        
        (function () {
            var searchEl = document.querySelector('.search-input');
            var liveEmpty = document.getElementById('visaLiveEmpty');
            var countEl = document.getElementById('visaVisibleCount');
            function visaFilterCards() {
                var q = searchEl ? searchEl.value.trim().toLowerCase() : '';
                var shown = 0;
                document.querySelectorAll('.applicant-card').forEach(function (card) {
                    var hay = (card.getAttribute('data-search') || '').toLowerCase();
                    var match = q === '' || hay.indexOf(q) !== -1;
                    card.style.display = match ? '' : 'none';
                    if (match) shown++;
                });
                if (liveEmpty) liveEmpty.style.display = shown === 0 && q !== '' ? '' : 'none';
                if (countEl) countEl.textContent = shown + ' Application' + (shown === 1 ? '' : 's');
            }
            if (searchEl) {
                searchEl.addEventListener('input', visaFilterCards);
                if (searchEl.value.trim() !== '') visaFilterCards();
            }
            window.visaFilterCards = visaFilterCards;

            var fromEl = document.getElementById('visaExportFrom');
            var toEl = document.getElementById('visaExportTo');
            var today = new Date();
            var iso = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
            if (fromEl && !fromEl.value) fromEl.value = iso;
            if (toEl && !toEl.value) toEl.value = iso;
            document.querySelectorAll('input[name="visaExportMode"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    var rangeOn = radio.value === 'range' && radio.checked;
                    if (fromEl) fromEl.disabled = !rangeOn;
                    if (toEl) toEl.disabled = !rangeOn;
                });
            });
            function visaExport(format) {
                var modeEl = document.querySelector('input[name="visaExportMode"]:checked');
                var mode = modeEl ? modeEl.value : 'today';
                var params = new URLSearchParams();
                params.set('format', format);
                params.set('range', mode);
                if (mode === 'range') {
                    if (!fromEl.value || !toEl.value) {
                        alert('Choose a start date and an end date.');
                        return;
                    }
                    params.set('from', fromEl.value);
                    params.set('to', toEl.value);
                }
                var searchEl = document.querySelector('.search-input');
                if (searchEl && searchEl.value.trim() !== '') {
                    params.set('search', searchEl.value.trim());
                }
                var statusEl = document.querySelector('.search-form input[name="process_status"]');
                if (statusEl && statusEl.value) {
                    params.set('process_status', statusEl.value);
                }
                var frame = document.getElementById('visaExportFrame');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = 'visaExportFrame';
                    frame.hidden = true;
                    frame.setAttribute('aria-hidden', 'true');
                    document.body.appendChild(frame);
                }
                frame.src = 'visa-export.php?' + params.toString();
            }
            var csvBtn = document.getElementById('visaExportCsv');
            var pdfBtn = document.getElementById('visaExportPdf');
            if (csvBtn) csvBtn.addEventListener('click', function () { visaExport('csv'); });
            if (pdfBtn) pdfBtn.addEventListener('click', function () { visaExport('pdf'); });
        })();
    </script>
</body>
</html>

<?php
// Close database connection
$conn->close();
?>
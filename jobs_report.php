<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/company_branding.php';
$companyBrandName = PCVC_COMPANY_DISPLAY_NAME;
/* ===========================================================
   AUTHENTICATION & AUTHORIZATION
============================================================ */
if (!isset($_SESSION['id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$current_user_id = (int)$_SESSION['id'];
$role = $_SESSION['role'] ?? 'staff';
$isSuperAdmin = ($role === 'superadmin');

/* ===========================================================
   ACTIVE ADMIN VIEW (for superadmin)
============================================================ */
$active_admin_id = ($isSuperAdmin && isset($_GET['view_admin_id']) && ctype_digit($_GET['view_admin_id']))
    ? (int)$_GET['view_admin_id']
    : $current_user_id;

/* ===========================================================
   ACTIVE VIEW (jobs or applications)
============================================================ */
$view = ($_GET['view'] ?? 'jobs') === 'applications' ? 'applications' : 'jobs';

/* ===========================================================
   FETCH ADMIN INFO
============================================================ */
$stmt = $conn->prepare("SELECT full_name, profile_photo, position, sheet_link FROM admins WHERE id = ?");
$stmt->bind_param("i", $active_admin_id);
$stmt->execute();
$admin_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ===========================================================
   FETCH ADMIN LIST FOR SUPERADMIN
============================================================ */
$admin_list = [];
if ($isSuperAdmin) {
    $res = $conn->query("SELECT id, full_name, profile_photo FROM admins ORDER BY full_name");
    while ($r = $res->fetch_assoc()) {
        $admin_list[] = $r;
    }
}

/* ===========================================================
   FETCH DATA BASED ON VIEW
============================================================ */
if ($view === 'jobs') {
    $stmt = $conn->prepare("
        SELECT jobs.*, admins.full_name, admins.profile_photo
        FROM jobs
        JOIN admins ON admins.id = jobs.admin_id
        WHERE jobs.admin_id = ?
        ORDER BY jobs.created_at DESC
    ");
    $stmt->bind_param("i", $active_admin_id);
} else {
    $stmt = $conn->prepare("
        SELECT job_list.*, admins.full_name, admins.profile_photo
        FROM job_list
        JOIN admins ON admins.id = job_list.admin_id
        WHERE job_list.admin_id = ?
        ORDER BY job_list.created_at DESC
    ");
    $stmt->bind_param("i", $active_admin_id);
}

$stmt->execute();
$result = $stmt->get_result();

// Get counts for stats
$totalRecords = $result->num_rows;
$highCount = 0;
$midCount = 0;
$lowCount = 0;

if ($view === 'jobs') {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $score = (int)($row['productivity_score'] ?? 0);
        if ($score >= 75) $highCount++;
        elseif ($score >= 40) $midCount++;
        else $lowCount++;
    }
    $result->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parrot Canada Visa — Smart Combined Report</title>
    
    <!-- Brand color variables -->
    <style>
        :root {
            --deep-navy: #427431;
            --secondary-blue: #3661B9;
            --dark-blue: #2f5a26;
            --gold: #E21D1E;
            --white: #FFFFFF;
            --light-bg: #f8fafc;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --success: #2e7d32;
            --danger: #c62828;
            --warning: #ed6c02;
            --info: #0288d1;
            --border-light: #e2e8f0;
            --low-bg: #fee2e2;
            --low-text: #991b1b;
            --mid-bg: #fef3c7;
            --mid-text: #92400e;
            --high-bg: #dcfce7;
            --high-text: #166534;
        }
    </style>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(180deg, var(--white) 0%, #f0f4f8 100%);
            color: var(--text-dark);
            min-height: 100vh;
            overflow: hidden;
        }

        /* ===== Brand header ===== */
        .brand-payroll-header {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            padding: 12px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 3px solid var(--gold);
        }

        .header-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 0 24px;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-main {
            font-size: 2rem;
            font-weight: 800;
            color: var(--white);
            letter-spacing: 1px;
            position: relative;
            display: inline-block;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .logo-main::after {
            content: '📊';
            position: absolute;
            top: -8px;
            right: -35px;
            font-size: 1.8rem;
            filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.3));
        }

        .logo-subtitle {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 1px;
            border-left: 3px solid var(--gold);
            padding-left: 20px;
            text-transform: uppercase;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        /* ===== MAIN CONTAINER ===== */
        .main-container {
            height: calc(100vh - 80px);
            padding: 20px 24px 0 24px;
            display: flex;
            flex-direction: column;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--deep-navy);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .page-title i {
            color: var(--gold);
            font-size: 28px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-img-container {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--gold);
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--deep-navy) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }

        .admin-details {
            display: flex;
            flex-direction: column;
        }

        .admin-name {
            font-weight: 700;
            color: var(--deep-navy);
            font-size: 16px;
        }

        .admin-position {
            font-size: 12px;
            color: var(--text-muted);
        }

        .stats-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--white);
            padding: 10px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(1, 47, 107, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border-light);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 20px;
        }

        .stat-info h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--deep-navy);
            line-height: 1.2;
        }

        .stat-info p {
            margin: 0;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* ===== SHEET BUTTON ===== */
        .sheet-btn {
            background: linear-gradient(135deg, var(--success) 0%, #1b5e20 100%);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .sheet-btn:hover {
            background: linear-gradient(135deg, #1b5e20 0%, #0a3622 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(46, 125, 50, 0.2);
            color: var(--white);
        }

        /* ===== FILTERS SECTION ===== */
        .filters-section {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(1, 47, 107, 0.08);
            border: 1px solid var(--border-light);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            flex-shrink: 0;
        }

        .admin-select {
            min-width: 250px;
            padding: 10px 12px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
            background: var(--white);
        }

        .admin-select:focus {
            outline: none;
            border-color: var(--gold);
        }

        .view-switch {
            display: flex;
            gap: 8px;
            margin-left: auto;
        }

        .view-btn {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .view-btn-jobs {
            background: var(--light-bg);
            color: var(--text-muted);
            border: 1px solid var(--border-light);
        }

        .view-btn-apps {
            background: var(--light-bg);
            color: var(--text-muted);
            border: 1px solid var(--border-light);
        }

        .view-btn.active {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            color: var(--white);
            border: none;
        }

        .view-btn.active i {
            color: var(--gold);
        }

        /* ===== SEARCH BAR ===== */
        .search-wrapper {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        #searchInput {
            width: 100%;
            padding: 10px 16px 10px 42px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }

        #searchInput:focus {
            outline: none;
            border-color: var(--gold);
        }

        /* ===== TABLE CARD ===== */
        .table-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(1, 47, 107, 0.1);
            border: 1px solid var(--border-light);
            overflow: hidden;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .table-responsive {
            flex: 1;
            overflow: auto;
            position: relative;
        }

        /* Table headers */
        th {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f6 100%);
            color: var(--deep-navy);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 12px;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            border-bottom: 2px solid var(--gold);
            border-right: 1px solid #dee2e6;
        }

        th:last-child {
            border-right: none;
        }

        th.sortable {
            cursor: pointer;
            transition: background 0.2s ease;
        }

        th.sortable:hover {
            background: linear-gradient(135deg, #eef2f6 0%, #e2e8f0 100%);
        }

        th i {
            margin-left: 4px;
            color: var(--gold);
        }

        /* Table cells */
        td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid #f1f3f5;
            vertical-align: middle;
            background: var(--white);
        }

        td:last-child {
            border-right: none;
        }

        tr:hover td {
            background: rgba(242, 166, 90, 0.02);
        }

        /* Admin badge in table */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--light-bg);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            color: var(--deep-navy);
        }

        .admin-badge i {
            color: var(--gold);
        }

        /* Score badges */
        .score-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }

        .score-high {
            background: var(--high-bg);
            color: var(--high-text);
            border-left: 3px solid var(--success);
        }

        .score-mid {
            background: var(--mid-bg);
            color: var(--mid-text);
            border-left: 3px solid var(--warning);
        }

        .score-low {
            background: var(--low-bg);
            color: var(--low-text);
            border-left: 3px solid var(--danger);
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: var(--high-bg);
            color: var(--high-text);
        }

        .status-pending {
            background: var(--mid-bg);
            color: var(--mid-text);
        }

        .status-closed {
            background: #e9ecef;
            color: var(--text-muted);
        }

        /* Thumbnail */
        .thumb-container {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            overflow: hidden;
            border: 2px solid var(--gold);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .thumb-container:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 8px rgba(1, 47, 107, 0.2);
        }

        .thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .thumb-placeholder {
            width: 48px;
            height: 48px;
            background: var(--light-bg);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 20px;
        }

        /* Description cell */
        .description-cell {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Hours display */
        .hours-display {
            font-weight: 600;
            color: var(--deep-navy);
        }

        /* Date display */
        .date-display {
            display: flex;
            flex-direction: column;
        }

        .date-main {
            font-weight: 500;
            color: var(--text-dark);
        }

        .date-small {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Empty state */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--text-muted);
        }

        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .empty-text {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Back link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            margin-top: 16px;
            transition: all 0.2s ease;
        }

        .back-link:hover {
            color: var(--deep-navy);
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-light);
            border-radius: 50%;
            border-top-color: var(--deep-navy);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-container {
                padding: 0 16px;
            }
            
            .logo-main {
                font-size: 1.5rem;
            }
            
            .logo-main::after {
                right: -25px;
                font-size: 1.3rem;
            }
            
            .logo-subtitle {
                font-size: 1rem;
                padding-left: 12px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .stats-container {
                width: 100%;
            }
            
            .filters-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .view-switch {
                margin-left: 0;
            }
            
            .admin-select {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<!-- Company header -->
<div class="brand-payroll-header">
    <div class="header-container">
        <div class="logo-container">
            <div class="logo-main"><?= htmlspecialchars($companyBrandName) ?></div>
            <div class="logo-subtitle">Job report</div>
        </div>
    </div>
</div>

<div class="main-container">
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="admin-info">
            <?php if (!empty($admin_info['profile_photo'])): ?>
            <div class="profile-img-container">
                <img src="uploads/<?= htmlspecialchars($admin_info['profile_photo']) ?>" 
                     alt="Profile" class="profile-img">
            </div>
            <?php else: ?>
            <div class="profile-placeholder">
                <?= strtoupper(substr($admin_info['full_name'] ?? 'U', 0, 1)) ?>
            </div>
            <?php endif; ?>
            <div class="admin-details">
                <span class="admin-name"><?= htmlspecialchars($admin_info['full_name'] ?? 'N/A') ?></span>
                <span class="admin-position"><?= htmlspecialchars($admin_info['position'] ?? 'Staff Member') ?></span>
            </div>
        </div>
        
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-file-text"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $totalRecords ?></h3>
                    <p>Total Records</p>
                </div>
            </div>
            
            <?php if ($view === 'jobs'): ?>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-arrow-up-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $highCount ?></h3>
                    <p>High Score</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-dash-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $midCount ?></h3>
                    <p>Medium</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-arrow-down-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $lowCount ?></h3>
                    <p>Low</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($admin_info['sheet_link'])): ?>
            <a class="sheet-btn" href="<?= htmlspecialchars($admin_info['sheet_link']) ?>" target="_blank">
                <i class="bi bi-google"></i> Google Sheet
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Filters Section -->
    <div class="filters-section">
        <?php if ($isSuperAdmin): ?>
        <form method="GET" id="adminFilterForm" class="d-flex gap-3 align-items-center">
            <select name="view_admin_id" class="admin-select" onchange="this.form.submit()">
                <?php foreach ($admin_list as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id'] == $active_admin_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="view" value="<?= $view ?>">
        </form>
        <?php endif; ?>
        
        <div class="search-wrapper">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search records...">
        </div>
        
        <div class="view-switch">
            <a href="?view=jobs<?= $isSuperAdmin ? '&view_admin_id=' . $active_admin_id : '' ?>" 
               class="view-btn view-btn-jobs <?= $view === 'jobs' ? 'active' : '' ?>">
                <i class="bi bi-briefcase"></i> Jobs
            </a>
            <a href="?view=applications<?= $isSuperAdmin ? '&view_admin_id=' . $active_admin_id : '' ?>" 
               class="view-btn view-btn-apps <?= $view === 'applications' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i> Applications
            </a>
        </div>
    </div>
    
    <!-- Table Card -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="table" id="reportTable">
                <thead>
                    <tr>
                        <?php if ($isSuperAdmin): ?>
                        <th class="sortable" onclick="sortTable(0)" style="min-width: 150px;">
                            Admin <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <?php endif; ?>
                        
                        <?php if ($view === 'jobs'): ?>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 1 : 0 ?>)" style="min-width: 200px;">
                            Job Title <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <th style="min-width: 250px;">Description</th>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 3 : 2 ?>)" style="min-width: 100px;">
                            Hours <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 4 : 3 ?>)" style="min-width: 100px;">
                            Score <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 5 : 4 ?>)" style="min-width: 150px;">
                            Date <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <?php else: ?>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 1 : 0 ?>)" style="min-width: 150px;">
                            Applicant <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <th style="min-width: 200px;">Email</th>
                        <th style="min-width: 120px;">Job Type</th>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 4 : 3 ?>)" style="min-width: 100px;">
                            Status <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <th style="min-width: 80px;">Screenshot</th>
                        <th class="sortable" onclick="sortTable(<?= $isSuperAdmin ? 6 : 4 ?>)" style="min-width: 150px;">
                            Date <i class="bi bi-arrow-down-up"></i>
                        </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($r = $result->fetch_assoc()): ?>
                        <tr>
                            <?php if ($isSuperAdmin): ?>
                            <td>
                                <span class="admin-badge">
                                    <i class="bi bi-person-circle"></i>
                                    <?= htmlspecialchars($r['full_name'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            
                            <?php if ($view === 'jobs'): 
                                $score = (int)($r['productivity_score'] ?? 0);
                                $scoreClass = $score >= 75 ? 'score-high' : ($score >= 40 ? 'score-mid' : 'score-low');
                            ?>
                            <td>
                                <strong><?= htmlspecialchars($r['job_title'] ?? 'N/A') ?></strong>
                            </td>
                            <td class="description-cell" title="<?= htmlspecialchars($r['job_description'] ?? '') ?>">
                                <?= nl2br(htmlspecialchars($r['job_description'] ?? '')) ?>
                            </td>
                            <td>
                                <span class="hours-display">
                                    <?= number_format($r['hours_spent'] ?? 0, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="score-badge <?= $scoreClass ?>">
                                    <?= $score ?>%
                                </span>
                            </td>
                            <td>
                                <div class="date-display">
                                    <span class="date-main"><?= date('M d, Y', strtotime($r['created_at'] ?? '')) ?></span>
                                    <span class="date-small"><?= date('H:i', strtotime($r['created_at'] ?? '')) ?></span>
                                </div>
                            </td>
                            
                            <?php else: ?>
                            <td>
                                <strong><?= htmlspecialchars($r['applicant_name'] ?? 'N/A') ?></strong>
                            </td>
                            <td>
                                <a href="mailto:<?= htmlspecialchars($r['applicant_email'] ?? '') ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($r['applicant_email'] ?? '') ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <?= htmlspecialchars($r['job_type'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($r['status'] ?? 'pending') ?>">
                                    <?= htmlspecialchars($r['status'] ?? 'PENDING') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($r['screenshot_path'])): ?>
                                <div class="thumb-container" onclick="window.open('<?= htmlspecialchars($r['screenshot_path']) ?>')">
                                    <img src="<?= htmlspecialchars($r['screenshot_path']) ?>" 
                                         alt="Screenshot" class="thumb">
                                </div>
                                <?php else: ?>
                                <div class="thumb-placeholder">
                                    <i class="bi bi-image"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="date-display">
                                    <span class="date-main"><?= date('M d, Y', strtotime($r['created_at'] ?? '')) ?></span>
                                    <span class="date-small"><?= date('H:i', strtotime($r['created_at'] ?? '')) ?></span>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="<?= $isSuperAdmin ? ($view === 'jobs' ? 6 : 7) : ($view === 'jobs' ? 5 : 6) ?>">
                            <div class="empty-state">
                                <div class="empty-icon">📊</div>
                                <div class="empty-title">No Records Found</div>
                                <div class="empty-text">No <?= $view ?> records available for this staff member</div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Back Link -->
    <a href="admin-dashboard.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Dashboard
    </a>
</div>

<script>
// Search functionality
document.getElementById('searchInput').addEventListener('keyup', function() {
    let value = this.value.toLowerCase();
    let rows = document.querySelectorAll('#reportTable tbody tr');
    
    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return; // Skip empty state row
        let text = row.textContent.toLowerCase();
        row.style.display = text.includes(value) ? '' : 'none';
    });
});

// Sort table
let sortDirections = {};

function sortTable(columnIndex) {
    let table = document.getElementById('reportTable');
    let tbody = table.querySelector('tbody');
    let rows = Array.from(tbody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
    
    if (rows.length === 0) return;
    
    // Toggle sort direction
    sortDirections[columnIndex] = !sortDirections[columnIndex];
    
    rows.sort((a, b) => {
        let aText, bText;
        
        // Handle different column types
        if (columnIndex === 1 || columnIndex === 2) {
            // Name columns
            aText = a.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            bText = b.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
        } else if (columnIndex === 3) {
            // Hours or Status
            if (document.querySelector('th:nth-child(4)').textContent.includes('Hours')) {
                // Hours - numeric
                aText = parseFloat(a.cells[columnIndex]?.textContent.trim()) || 0;
                bText = parseFloat(b.cells[columnIndex]?.textContent.trim()) || 0;
                return sortDirections[columnIndex] ? aText - bText : bText - aText;
            } else {
                // Status - text
                aText = a.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
                bText = b.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            }
        } else if (columnIndex === 4) {
            // Score or Date
            if (document.querySelector('th:nth-child(5)').textContent.includes('Score')) {
                // Score - numeric
                aText = parseInt(a.cells[columnIndex]?.textContent.trim()) || 0;
                bText = parseInt(b.cells[columnIndex]?.textContent.trim()) || 0;
                return sortDirections[columnIndex] ? aText - bText : bText - aText;
            } else {
                // Date - convert to timestamp
                let aDate = a.querySelector('.date-main')?.textContent.trim() || '';
                let bDate = b.querySelector('.date-main')?.textContent.trim() || '';
                aText = aDate ? new Date(aDate).getTime() : 0;
                bText = bDate ? new Date(bDate).getTime() : 0;
                return sortDirections[columnIndex] ? aText - bText : bText - aText;
            }
        } else {
            // Default
            aText = a.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            bText = b.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
        }
        
        if (sortDirections[columnIndex]) {
            return aText.localeCompare(bText);
        } else {
            return bText.localeCompare(aText);
        }
    });
    
    // Reorder rows
    rows.forEach(row => tbody.appendChild(row));
}

// Keyboard shortcut for search
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
    }
});
</script>

</body>
</html>
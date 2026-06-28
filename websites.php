<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/websites_schema.php';

pcvc_ensure_websites_schema($conn);

$admin_id = $_SESSION['id'] ?? null;
if (!$admin_id) {
    header('Location: admin-login.php');
    exit;
}

$role = 'standard';
$admin_id_safe = mysqli_real_escape_string($conn, $admin_id);
$result = mysqli_query($conn, "SELECT role FROM admins WHERE id = '$admin_id_safe'");
if ($result && mysqli_num_rows($result) > 0) {
    $admin = mysqli_fetch_assoc($result);
    $role = trim($admin['role'] ?? 'standard');
}

if ($role !== 'superadmin') {
    header('Location: admin-dashboard.php');
    exit;
}

function generateWebsiteSerial(mysqli $conn): string
{
    $year = date('Y');
    $sql = $conn->query("SELECT COUNT(*) AS total FROM websites WHERE YEAR(created_at) = $year");
    $row = $sql->fetch_assoc();
    $count = (int) ($row['total'] ?? 0) + 1;
    return 'WEB-' . $year . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
}

if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $serial = generateWebsiteSerial($conn);
    $name = trim($_POST['website_name'] ?? '');
    $link = normalizeWebsiteLink(trim($_POST['website_link'] ?? ''));
    $user = trim($_POST['admin_username'] ?? '');
    $pass = trim($_POST['admin_password'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare('
        INSERT INTO websites
        (serial_no, website_name, website_link, admin_username, admin_password, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('sssssssi', $serial, $name, $link, $user, $pass, $status, $notes, $admin_id);
    $stmt->execute();
    $stmt->close();

    header('Location: ' . $_SERVER['PHP_SELF'] . '?added=1#listSection');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['website_name'] ?? '');
    $link = normalizeWebsiteLink(trim($_POST['website_link'] ?? ''));
    $user = trim($_POST['admin_username'] ?? '');
    $pass = trim($_POST['admin_password'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');

    if ($pass === '') {
        $stmt = $conn->prepare('
            UPDATE websites
            SET website_name=?, website_link=?, admin_username=?, status=?, notes=?
            WHERE id=?
        ');
        $stmt->bind_param('sssssi', $name, $link, $user, $status, $notes, $id);
    } else {
        $stmt = $conn->prepare('
            UPDATE websites
            SET website_name=?, website_link=?, admin_username=?, admin_password=?, status=?, notes=?
            WHERE id=?
        ');
        $stmt->bind_param('ssssssi', $name, $link, $user, $pass, $status, $notes, $id);
    }
    $stmt->execute();
    $stmt->close();

    header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1#listSection');
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM websites WHERE id=$id");
    header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1#listSection');
    exit;
}

function previewLink(string $url): string
{
    if ($url === '') {
        return '';
    }
    $clean = preg_replace('(^https?://)', '', $url);
    return strlen($clean) > 28 ? substr($clean, 0, 28) . '…' : $clean;
}

function normalizeWebsiteLink(string $link): string
{
    $link = trim($link);
    if ($link === '') {
        return '';
    }
    $link = preg_replace('#^https?://#i', '', $link);
    $link = ltrim($link, '/');
    return 'https://' . $link;
}

$totalCount = 0;
$activeCount = 0;
$statsQ = $conn->query('SELECT COUNT(*) AS total, SUM(status = "Active") AS active FROM websites');
if ($statsQ) {
    $stats = $statsQ->fetch_assoc();
    $totalCount = (int) ($stats['total'] ?? 0);
    $activeCount = (int) ($stats['active'] ?? 0);
}

$openAdd = isset($_GET['open']) && $_GET['open'] === 'add';
$flash = '';
$flashType = 'success';
if (isset($_GET['added'])) {
    $flash = 'Website added successfully.';
} elseif (isset($_GET['updated'])) {
    $flash = 'Website updated successfully.';
} elseif (isset($_GET['deleted'])) {
    $flash = 'Website removed.';
    $flashType = 'warning';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --wm-green: #2E6A2C;
            --wm-green-dark: #1f4a1e;
            --wm-green-light: #e8f5e6;
            --wm-blue: #1E64B7;
            --wm-shadow: 0 12px 40px rgba(46, 106, 44, 0.12);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f0f7ee 0%, #e8f0fa 50%, #f5f9f3 100%);
            min-height: 100vh;
            color: #1a2e1a;
        }

        .wm-page { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }

        .wm-hero {
            background: linear-gradient(135deg, var(--wm-green) 0%, var(--wm-green-dark) 100%);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            color: #fff;
            box-shadow: var(--wm-shadow);
            position: relative;
            overflow: hidden;
            animation: fadeSlideDown 0.5s ease;
        }

        .wm-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .wm-hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: 10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }

        .wm-hero h1 {
            font-weight: 800;
            font-size: 1.85rem;
            margin: 0;
            position: relative;
        }

        .wm-hero p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            position: relative;
        }

        .wm-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
            position: relative;
        }

        .wm-stat-card {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 14px;
            padding: 1rem 1.25rem;
            transition: transform 0.25s ease, background 0.25s ease;
        }

        .wm-stat-card:hover {
            transform: translateY(-3px);
            background: rgba(255,255,255,0.22);
        }

        .wm-stat-card .num {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
        }

        .wm-stat-card .lbl {
            font-size: 0.8rem;
            opacity: 0.85;
            margin-top: 0.25rem;
        }

        .wm-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin: 1.5rem 0;
            animation: fadeSlideDown 0.5s ease 0.1s both;
        }

        .wm-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
        }

        .wm-btn-primary {
            background: linear-gradient(135deg, var(--wm-green), var(--wm-green-dark));
            color: #fff;
            box-shadow: 0 4px 15px rgba(46, 106, 44, 0.35);
        }

        .wm-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 106, 44, 0.45);
            color: #fff;
        }

        .wm-btn-outline {
            background: #fff;
            color: var(--wm-green);
            border: 2px solid var(--wm-green);
        }

        .wm-btn-outline:hover {
            background: var(--wm-green-light);
            transform: translateY(-2px);
            color: var(--wm-green-dark);
        }

        .wm-btn-ghost {
            background: rgba(255,255,255,0.9);
            color: #555;
            border: 1px solid #dde5dd;
        }

        .wm-btn-ghost:hover {
            background: #fff;
            border-color: var(--wm-green);
            color: var(--wm-green);
            transform: translateY(-2px);
        }

        .wm-panel {
            background: #fff;
            border-radius: 18px;
            box-shadow: var(--wm-shadow);
            overflow: hidden;
            animation: fadeSlideUp 0.5s ease 0.15s both;
            border: 1px solid rgba(46, 106, 44, 0.08);
        }

        .wm-panel-header {
            background: linear-gradient(90deg, var(--wm-green-light), #fff);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0ebe0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .wm-panel-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--wm-green-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .wm-panel-body { padding: 1.25rem 1.5rem 1.5rem; }

        #webTable thead th {
            background: var(--wm-green) !important;
            color: #fff !important;
            font-weight: 600;
            font-size: 0.85rem;
            border: none !important;
            padding: 0.85rem 0.75rem;
        }

        #webTable tbody tr {
            transition: background 0.2s ease, transform 0.2s ease;
        }

        #webTable tbody tr:hover {
            background: var(--wm-green-light) !important;
        }

        .wm-badge {
            display: inline-block;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .wm-badge-active {
            background: #d4edda;
            color: #155724;
        }

        .wm-badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .wm-serial {
            font-family: 'Consolas', monospace;
            font-size: 0.82rem;
            background: #f0f4f0;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            color: var(--wm-green-dark);
        }

        .wm-cred-box {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #f8faf8;
            border: 1px solid #e2ebe2;
            border-radius: 10px;
            padding: 0.35rem 0.6rem;
        }

        .wm-pwd-text {
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
            letter-spacing: 1px;
            min-width: 70px;
        }

        .wm-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .wm-icon-btn:hover {
            transform: scale(1.1);
        }

        .wm-icon-btn-eye { background: #e3f0ff; color: var(--wm-blue); }
        .wm-icon-btn-eye:hover { background: var(--wm-blue); color: #fff; }
        .wm-icon-btn-copy { background: #e8f5e6; color: var(--wm-green); }
        .wm-icon-btn-copy:hover { background: var(--wm-green); color: #fff; }
        .wm-icon-btn-edit { background: #fff3cd; color: #856404; }
        .wm-icon-btn-edit:hover { background: #ffc107; color: #000; }
        .wm-icon-btn-del { background: #f8d7da; color: #721c24; }
        .wm-icon-btn-del:hover { background: #dc3545; color: #fff; }
        .wm-icon-btn-link { background: #e3f0ff; color: var(--wm-blue); }
        .wm-icon-btn-link:hover { background: var(--wm-blue); color: #fff; }

        .wm-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            justify-content: center;
        }

        .wm-link-cell a {
            color: var(--wm-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .wm-link-cell a:hover { text-decoration: underline; }

        .wm-modal .modal-content {
            border: none;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .wm-modal .modal-header {
            background: linear-gradient(135deg, var(--wm-green), var(--wm-green-dark));
            color: #fff;
            border: none;
            padding: 1.25rem 1.5rem;
        }

        .wm-modal .modal-title { font-weight: 700; }

        .wm-form-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--wm-green);
            z-index: 2;
        }

        .wm-input-wrap { position: relative; }

        .wm-input-wrap .form-control,
        .wm-input-wrap .form-select {
            padding-left: 2.5rem;
            border-radius: 12px;
            border: 2px solid #e0ebe0;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .wm-input-wrap .form-control:focus,
        .wm-input-wrap .form-select:focus {
            border-color: var(--wm-green);
            box-shadow: 0 0 0 3px rgba(46, 106, 44, 0.15);
        }

        .wm-pwd-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            cursor: pointer;
            z-index: 2;
            padding: 0.25rem;
            transition: color 0.2s;
        }

        .wm-pwd-toggle:hover { color: var(--wm-green); }

        .wm-toast {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 9999;
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            animation: slideInRight 0.4s ease;
        }

        .wm-toast-success { background: var(--wm-green); color: #fff; }
        .wm-toast-warning { background: #ffc107; color: #333; }
        .wm-toast-info { background: var(--wm-blue); color: #fff; }

        .wm-view-grid {
            display: grid;
            gap: 1rem;
        }

        .wm-view-item {
            background: #f8faf8;
            border-radius: 12px;
            padding: 1rem 1.15rem;
            border-left: 4px solid var(--wm-green);
        }

        .wm-view-item label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #888;
            font-weight: 600;
            margin-bottom: 0.25rem;
            display: block;
        }

        .wm-view-item .val {
            font-weight: 600;
            color: #222;
            word-break: break-all;
        }

        @keyframes fadeSlideDown {
            from { opacity: 0; transform: translateY(-16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .dataTables_wrapper .dataTables_filter input {
            border-radius: 10px;
            border: 2px solid #e0ebe0;
            padding: 0.4rem 0.75rem;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--wm-green);
            outline: none;
        }

        .wm-url-group {
            display: flex;
            align-items: stretch;
            border: 2px solid #e0ebe0;
            border-radius: 12px;
            overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
        }

        .wm-url-group:focus-within {
            border-color: var(--wm-green);
            box-shadow: 0 0 0 3px rgba(46, 106, 44, 0.15);
        }

        .wm-url-prefix {
            display: flex;
            align-items: center;
            padding: 0 0.85rem;
            background: var(--wm-green-light);
            color: var(--wm-green-dark);
            font-weight: 700;
            font-size: 0.9rem;
            border-right: 1px solid #d5e8d5;
            white-space: nowrap;
        }

        .wm-url-input {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding-left: 0.85rem !important;
        }

        .wm-url-input:focus {
            box-shadow: none !important;
        }

        .wm-save-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 35, 15, 0.55);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.25s ease;
        }

        .wm-save-box {
            background: #fff;
            border-radius: 18px;
            padding: 2rem 2.25rem;
            width: min(420px, 92vw);
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            animation: fadeSlideUp 0.35s ease;
        }

        .wm-save-box .wm-save-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--wm-green-light);
            color: var(--wm-green);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .wm-save-box h4 {
            margin: 0 0 0.35rem;
            font-weight: 700;
            color: var(--wm-green-dark);
        }

        .wm-save-box p {
            margin: 0 0 1.25rem;
            color: #666;
            font-size: 0.9rem;
        }

        .wm-progress-track {
            height: 10px;
            background: #e8f0e8;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .wm-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--wm-green), #4caf50, var(--wm-green-dark));
            background-size: 200% 100%;
            border-radius: 20px;
            transition: width 0.35s ease;
            animation: progressShine 1.5s linear infinite;
        }

        .wm-progress-pct {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--wm-green);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes progressShine {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body>

<?php if ($flash): ?>
<div class="wm-toast wm-toast-<?= $flashType ?>" id="flashToast"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div id="saveOverlay" class="wm-save-overlay" style="display:none;" aria-live="polite">
    <div class="wm-save-box">
        <div class="wm-save-icon"><i class="bi bi-cloud-upload"></i></div>
        <h4 id="saveOverlayTitle">Saving website…</h4>
        <p id="saveOverlayMsg">Please wait while your data is being saved.</p>
        <div class="wm-progress-track">
            <div class="wm-progress-bar" id="saveProgressBar"></div>
        </div>
        <div class="wm-progress-pct" id="saveProgressPct">0%</div>
    </div>
</div>

<div class="wm-page">

    <div class="wm-hero">
        <h1><i class="bi bi-globe2 me-2"></i>Website Management</h1>
        <p>Manage website links, admin usernames &amp; passwords — all in one place.</p>
        <div class="wm-stats">
            <div class="wm-stat-card">
                <div class="num"><?= $totalCount ?></div>
                <div class="lbl">Total Websites</div>
            </div>
            <div class="wm-stat-card">
                <div class="num"><?= $activeCount ?></div>
                <div class="lbl">Active</div>
            </div>
            <div class="wm-stat-card">
                <div class="num"><?= max(0, $totalCount - $activeCount) ?></div>
                <div class="lbl">Inactive</div>
            </div>
        </div>
    </div>

    <div class="wm-toolbar">
        <button type="button" class="wm-btn wm-btn-outline" onclick="scrollToList()">
            <i class="bi bi-list-ul"></i> List All
        </button>
        <button type="button" class="wm-btn wm-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-circle"></i> Add New
        </button>
        <button type="button" class="wm-btn wm-btn-ghost" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <div class="wm-panel" id="listSection">
        <div class="wm-panel-header">
            <h2><i class="bi bi-table"></i> All Websites</h2>
            <span class="text-muted small">Use action buttons to edit, view password, copy or delete</span>
        </div>
        <div class="wm-panel-body">
            <table id="webTable" class="table table-hover w-100">
                <thead>
                    <tr>
                        <th>Serial</th>
                        <th>Website Name</th>
                        <th>Website Link</th>
                        <th>Admin Username</th>
                        <th>Admin Password</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $q = $conn->query('SELECT * FROM websites ORDER BY id DESC');
                while ($row = $q->fetch_assoc()):
                    $safeRow = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                    $isActive = ($row['status'] === 'Active');
                ?>
                    <tr>
                        <td><span class="wm-serial"><?= htmlspecialchars($row['serial_no']) ?></span></td>
                        <td><strong><?= htmlspecialchars($row['website_name']) ?></strong></td>
                        <td class="wm-link-cell">
                            <?php if (!empty($row['website_link'])): ?>
                                <a href="<?= htmlspecialchars($row['website_link']) ?>" target="_blank" title="<?= htmlspecialchars($row['website_link']) ?>">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    <?= htmlspecialchars(previewLink($row['website_link'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="wm-cred-box">
                                <i class="bi bi-person-badge text-success"></i>
                                <span><?= htmlspecialchars($row['admin_username']) ?></span>
                                <button type="button" class="wm-icon-btn wm-icon-btn-copy" title="Copy username"
                                    onclick="copyText('<?= htmlspecialchars($row['admin_username'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <div class="wm-cred-box">
                                <span class="wm-pwd-text" id="pwd_<?= (int) $row['id'] ?>">••••••••</span>
                                <button type="button" class="wm-icon-btn wm-icon-btn-eye" title="View password"
                                    id="eye_<?= (int) $row['id'] ?>"
                                    onclick="togglePassword('<?= htmlspecialchars($row['admin_password'], ENT_QUOTES) ?>', <?= (int) $row['id'] ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button type="button" class="wm-icon-btn wm-icon-btn-copy" title="Copy password"
                                    onclick="copyText('<?= htmlspecialchars($row['admin_password'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <span class="wm-badge <?= $isActive ? 'wm-badge-active' : 'wm-badge-inactive' ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="wm-actions">
                                <button type="button" class="wm-icon-btn wm-icon-btn-edit" title="Edit"
                                    onclick='editWebsite(<?= $safeRow ?>)'>
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button type="button" class="wm-icon-btn wm-icon-btn-eye" title="View credentials"
                                    onclick='viewCredentials(<?= $safeRow ?>)'>
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <?php if (!empty($row['website_link'])): ?>
                                <a href="<?= htmlspecialchars($row['website_link']) ?>" target="_blank"
                                   class="wm-icon-btn wm-icon-btn-link" title="Open website">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <?php endif; ?>
                                <a href="?delete=<?= (int) $row['id'] ?>" class="wm-icon-btn wm-icon-btn-del" title="Delete"
                                   onclick="return confirm('Delete this website permanently?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade wm-modal" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add New Website</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addForm" onsubmit="return handleSaveSubmit(this, 'Saving website…')">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-globe wm-form-icon"></i>
                        <input type="text" name="website_name" class="form-control" placeholder="Website Name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1 ms-1">Website URL</label>
                        <div class="wm-url-group">
                            <span class="wm-url-prefix">https://</span>
                            <input type="text" id="add_link_part" class="form-control wm-url-input" placeholder="example.com" autocomplete="off">
                            <input type="hidden" name="website_link" id="add_website_link">
                        </div>
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-person wm-form-icon"></i>
                        <input type="text" name="admin_username" class="form-control" placeholder="Admin Username">
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-key wm-form-icon"></i>
                        <input type="password" name="admin_password" id="add_pass" class="form-control" placeholder="Admin Password" style="padding-right:2.5rem;">
                        <button type="button" class="wm-pwd-toggle" onclick="toggleFieldPwd('add_pass', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-toggle-on wm-form-icon"></i>
                        <select class="form-select" name="status">
                            <option value="Active">Active</option>
                            <option value="Not Active">Not Active</option>
                        </select>
                    </div>
                    <div class="wm-input-wrap">
                        <i class="bi bi-sticky wm-form-icon"></i>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Notes (optional)" style="padding-left:2.5rem;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="wm-btn wm-btn-primary" id="addSubmitBtn">
                        <i class="bi bi-check-lg"></i> Save Website
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade wm-modal" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Website</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm" onsubmit="return handleSaveSubmit(this, 'Updating website…')">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body p-4">
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-globe wm-form-icon"></i>
                        <input type="text" name="website_name" id="edit_name" class="form-control" placeholder="Website Name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1 ms-1">Website URL</label>
                        <div class="wm-url-group">
                            <span class="wm-url-prefix">https://</span>
                            <input type="text" id="edit_link_part" class="form-control wm-url-input" placeholder="example.com" autocomplete="off">
                            <input type="hidden" name="website_link" id="edit_website_link">
                        </div>
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-person wm-form-icon"></i>
                        <input type="text" name="admin_username" id="edit_user" class="form-control" placeholder="Admin Username">
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-key wm-form-icon"></i>
                        <input type="password" name="admin_password" id="edit_pass" class="form-control" placeholder="Admin Password" style="padding-right:2.5rem;">
                        <button type="button" class="wm-pwd-toggle" onclick="toggleFieldPwd('edit_pass', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="wm-input-wrap mb-3">
                        <i class="bi bi-toggle-on wm-form-icon"></i>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="Active">Active</option>
                            <option value="Not Active">Not Active</option>
                        </select>
                    </div>
                    <div class="wm-input-wrap">
                        <i class="bi bi-sticky wm-form-icon"></i>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="2" placeholder="Notes" style="padding-left:2.5rem;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="wm-btn wm-btn-primary" id="editSubmitBtn">
                        <i class="bi bi-check-lg"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Credentials Modal -->
<div class="modal fade wm-modal" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>View Credentials</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="wm-view-grid">
                    <div class="wm-view-item">
                        <label>Website Name</label>
                        <div class="val" id="view_name">—</div>
                    </div>
                    <div class="wm-view-item">
                        <label>Website Link</label>
                        <div class="val" id="view_link">—</div>
                    </div>
                    <div class="wm-view-item">
                        <label>Admin Username</label>
                        <div class="val d-flex align-items-center gap-2">
                            <span id="view_user">—</span>
                            <button type="button" class="wm-icon-btn wm-icon-btn-copy" onclick="copyText(document.getElementById('view_user').textContent)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="wm-view-item">
                        <label>Admin Password</label>
                        <div class="val d-flex align-items-center gap-2">
                            <span class="wm-pwd-text" id="view_pwd">••••••••</span>
                            <button type="button" class="wm-icon-btn wm-icon-btn-eye" id="view_eye_btn" onclick="toggleViewPassword()">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="wm-icon-btn wm-icon-btn-copy" onclick="copyText(viewPwdReal)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="wm-view-item">
                        <label>Status</label>
                        <div class="val" id="view_status">—</div>
                    </div>
                    <div class="wm-view-item" id="view_notes_wrap" style="display:none;">
                        <label>Notes</label>
                        <div class="val" id="view_notes">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="wm-btn wm-btn-primary" id="view_edit_btn">
                    <i class="bi bi-pencil-square"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
let viewPwdReal = '';
let viewPwdVisible = false;
let currentViewRow = null;
let saveProgressTimer = null;

function buildFullUrl(domainInput, hiddenInput) {
    let part = (domainInput.value || '').trim();
    part = part.replace(/^https?:\/\//i, '').replace(/^\/+/, '');
    hiddenInput.value = part ? 'https://' + part : '';
}

function handleSaveSubmit(form, title) {
    const linkPart = form.querySelector('.wm-url-input');
    const linkHidden = form.querySelector('input[name="website_link"]');
    if (linkPart && linkHidden) {
        buildFullUrl(linkPart, linkHidden);
    }

    form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
        btn.disabled = true;
    });

    showSaveProgress(title || 'Saving…');
    return true;
}

function showSaveProgress(title) {
    const overlay = document.getElementById('saveOverlay');
    const bar = document.getElementById('saveProgressBar');
    const pct = document.getElementById('saveProgressPct');
    const titleEl = document.getElementById('saveOverlayTitle');

    if (saveProgressTimer) clearInterval(saveProgressTimer);

    titleEl.textContent = title;
    bar.style.width = '0%';
    pct.textContent = '0%';
    overlay.style.display = 'flex';

    let progress = 0;
    saveProgressTimer = setInterval(function () {
        if (progress < 90) {
            progress += progress < 50 ? 4 : progress < 75 ? 2 : 1;
            if (progress > 90) progress = 90;
            bar.style.width = progress + '%';
            pct.textContent = progress + '%';
        }
    }, 120);
}

$(document).ready(function () {
    $('#webTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 10,
        language: { emptyTable: 'No websites yet — click "Add New" to create one.' }
    });

    <?php if ($openAdd): ?>
    openAddModal();
    <?php endif; ?>

    document.querySelectorAll('.wm-url-input').forEach(function (input) {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/^https?:\/\//i, '').replace(/^\/+/, '');
        });
    });

    setTimeout(function () {
        const t = document.getElementById('flashToast');
        if (t) t.style.transition = 'opacity 0.4s';
        if (t) setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3500);
    }, 100);
});

function showToast(msg, type) {
    const el = document.createElement('div');
    el.className = 'wm-toast wm-toast-' + (type || 'info');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 2500);
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard', 'success'));
}

function scrollToList() {
    document.getElementById('listSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function openAddModal() {
    document.getElementById('add_link_part').value = '';
    document.getElementById('add_website_link').value = '';
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function togglePassword(real, id) {
    const span = document.getElementById('pwd_' + id);
    const eyeBtn = document.getElementById('eye_' + id);
    const icon = eyeBtn.querySelector('i');
    const hidden = span.textContent.includes('•');

    if (hidden) {
        span.textContent = real;
        icon.className = 'bi bi-eye-slash';
        eyeBtn.title = 'Hide password';
    } else {
        span.textContent = '••••••••';
        icon.className = 'bi bi-eye';
        eyeBtn.title = 'View password';
    }
}

function toggleFieldPwd(fieldId, btn) {
    const input = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function editWebsite(row) {
    $('#edit_id').val(row.id);
    $('#edit_name').val(row.website_name);
    const domain = (row.website_link || '').replace(/^https?:\/\//i, '');
    $('#edit_link_part').val(domain);
    $('#edit_website_link').val(row.website_link || '');
    $('#edit_user').val(row.admin_username);
    $('#edit_pass').val('');
    $('#edit_pass').attr('placeholder', 'Leave blank to keep current password');
    $('#edit_status').val(row.status);
    $('#edit_notes').val(row.notes || '');
    const ep = document.getElementById('edit_pass');
    ep.type = 'password';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function viewCredentials(row) {
    currentViewRow = row;
    viewPwdReal = row.admin_password;
    viewPwdVisible = false;

    document.getElementById('view_name').textContent = row.website_name;
    document.getElementById('view_link').innerHTML = row.website_link
        ? '<a href="' + row.website_link + '" target="_blank">' + row.website_link + '</a>'
        : '—';
    document.getElementById('view_user').textContent = row.admin_username;
    document.getElementById('view_pwd').textContent = '••••••••';
    document.getElementById('view_status').textContent = row.status;

    const notesWrap = document.getElementById('view_notes_wrap');
    if (row.notes) {
        document.getElementById('view_notes').textContent = row.notes;
        notesWrap.style.display = '';
    } else {
        notesWrap.style.display = 'none';
    }

    document.getElementById('view_eye_btn').querySelector('i').className = 'bi bi-eye';

    document.getElementById('view_edit_btn').onclick = function () {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        editWebsite(row);
    };

    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

function toggleViewPassword() {
    const span = document.getElementById('view_pwd');
    const icon = document.getElementById('view_eye_btn').querySelector('i');
    viewPwdVisible = !viewPwdVisible;
    span.textContent = viewPwdVisible ? viewPwdReal : '••••••••';
    icon.className = viewPwdVisible ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>

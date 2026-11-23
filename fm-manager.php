<?php
/**
 * Private Francophonie Mobility manager portal.
 * Open with ?k=TOKEN → login → Smart Add / View list / Overall report.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';

fm_ensure_manager_portal_schema($conn);

if (isset($_GET['logout'])) {
    fm_manager_portal_logout();
    $settings = fm_manager_portal_settings($conn);
    $token = (string) ($settings['access_token'] ?? '');
    header('Location: fm-manager.php?k=' . rawurlencode($token));
    exit;
}

$token = trim((string) ($_GET['k'] ?? $_POST['k'] ?? ''));
if ($token !== '' && fm_manager_portal_token_valid($conn, $token)) {
    fm_manager_portal_mark_invite_ok();
    $_SESSION['fm_manager_portal_token'] = $token;
} elseif (!empty($_SESSION['fm_manager_portal_token'])
    && fm_manager_portal_token_valid($conn, (string) $_SESSION['fm_manager_portal_token'])) {
    $token = (string) $_SESSION['fm_manager_portal_token'];
    fm_manager_portal_mark_invite_ok();
}

$linkOk = fm_manager_portal_invite_ok() && $token !== '' && fm_manager_portal_token_valid($conn, $token);
$error = '';
$settings = fm_manager_portal_settings($conn);

if (!$linkOk) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Private link required</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="bg-light">
    <div class="container py-5" style="max-width:480px">
        <div class="alert alert-danger">This private manager link is missing or invalid. Ask your administrator for a new link.</div>
    </div></body></html>
    <?php
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['portal_login'])) {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (fm_manager_portal_login($conn, $user, $pass)) {
        header('Location: fm-manager.php');
        exit;
    }
    $error = 'Incorrect username or password.';
}

$loggedIn = fm_manager_portal_logged_in();
$defaultUser = (string) ($settings['username'] ?? fm_manager_portal_default_username());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Francophonie Mobility — Manager portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f3; color: #1e293b; min-height: 100vh; }
        .hero {
            background: linear-gradient(135deg, #1e4d2b, #3661B9);
            color: #fff;
            padding: 1.5rem 0 1.25rem;
            margin-bottom: 1.25rem;
        }
        .card-soft {
            border: 0; border-radius: 14px;
            box-shadow: 0 1px 8px rgba(15, 23, 42, .08);
            background: #fff;
        }
        .tool-card {
            display: block; text-decoration: none; color: inherit;
            border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.15rem 1.1rem;
            height: 100%; transition: border-color .15s, box-shadow .15s, transform .15s;
        }
        .tool-card:hover {
            border-color: #1e4d2b; box-shadow: 0 4px 14px rgba(30, 77, 43, .12);
            transform: translateY(-1px); color: inherit;
        }
        .tool-card .icon {
            width: 2.5rem; height: 2.5rem; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #e8f0ea; color: #1e4d2b; margin-bottom: .7rem;
        }
        .tool-card h2 { font-size: 1.05rem; font-weight: 700; margin: 0 0 .3rem; }
        .tool-card p { margin: 0; color: #64748b; font-size: .88rem; }
        .login-shell { max-width: 420px; margin: 0 auto; }
    </style>
</head>
<body>
<?php if ($loggedIn): ?>
<?= fm_manager_portal_nav_html('hub') ?>
<?php endif; ?>
<div class="hero">
    <div class="container" style="max-width:920px">
        <h1 class="h4 mb-1"><i class="fas fa-shield-halved me-2"></i>Francophonie Mobility manager portal</h1>
        <p class="mb-0 opacity-75 small">Private access for Smart Add, application list, and client tracking overall report.</p>
    </div>
</div>

<div class="container pb-5" style="max-width:920px">
<?php if (!$loggedIn): ?>
    <div class="login-shell card card-soft p-4">
        <h2 class="h5 mb-1">Sign in</h2>
        <p class="text-muted small mb-3">Enter the manager username and password for this private link.</p>
        <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="username">
            <input type="hidden" name="k" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="portal_login" value="1">
            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <input class="form-control" name="username" required maxlength="64"
                       value="<?= htmlspecialchars((string) ($_POST['username'] ?? $defaultUser)) ?>"
                       autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Password</label>
                <input class="form-control" type="password" name="password" required
                       autocomplete="current-password" placeholder="Enter password">
            </div>
            <button type="submit" class="btn btn-success w-100">
                <i class="fas fa-sign-in-alt me-1"></i> Continue
            </button>
        </form>
    </div>
<?php else: ?>
    <p class="text-muted small mb-3">Signed in as <strong><?= htmlspecialchars((string) ($_SESSION['fm_manager_portal']['username'] ?? $defaultUser)) ?></strong></p>
    <div class="row g-3">
        <div class="col-md-4">
            <a class="tool-card" href="francophonie-mobility-staff-add.php">
                <div class="icon"><i class="fas fa-user-plus"></i></div>
                <h2>Smart Add</h2>
                <p>Create a candidate, set status, and file documents into groups.</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="tool-card" href="francophonie-mobility-applications.php">
                <div class="icon"><i class="fas fa-list-ul"></i></div>
                <h2>View list</h2>
                <p>Browse applications, open details, and move clients by status.</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="tool-card" href="francophonie-mobility-client-tracking.php">
                <div class="icon"><i class="fas fa-clipboard-check"></i></div>
                <h2>Overall report</h2>
                <p>Client tracking checklist with green/red document status and export.</p>
            </a>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>

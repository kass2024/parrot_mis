<?php
/**
 * Issue / copy the private Francophonie Mobility manager portal link (staff only).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_require_francophonie_mobility($conn);
fm_ensure_manager_portal_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $error = 'Session expired. Refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'rotate') {
            fm_manager_portal_rotate_token($conn);
            $message = 'New private link generated. Share the updated link with the manager.';
        } elseif ($action === 'reset_password') {
            fm_manager_portal_reset_credentials($conn);
            $message = 'Credentials reset to the default manager username and password.';
        }
    }
}

$settings = fm_manager_portal_settings($conn);
$privateUrl = fm_manager_portal_private_url($conn);
$username = (string) ($settings['username'] ?? fm_manager_portal_default_username());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager private link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f3; }
        .card-soft { border: 0; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); background: #fff; }
        .page-head { background: linear-gradient(135deg, #1e4d2b, #3661B9); color: #fff; padding: 1.1rem 0; margin-bottom: 1rem; }
        code.url { word-break: break-all; }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container" style="max-width:820px">
        <h1 class="h4 mb-1"><i class="fas fa-link me-2"></i>Manager private link</h1>
        <p class="mb-0 opacity-75 small">Issue a private portal link. After sign-in the manager can use Smart Add, View list, and Overall report.</p>
    </div>
</div>
<div class="container pb-5" style="max-width:820px">
    <p class="mb-3"><a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a></p>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card card-soft p-3 p-md-4 mb-3">
        <h2 class="h5">Private link</h2>
        <p class="small text-muted">Send this link only to the manager. Opening it asks for username and password.</p>
        <div class="input-group mb-2">
            <input type="text" class="form-control" id="portalUrl" readonly value="<?= htmlspecialchars($privateUrl) ?>">
            <button type="button" class="btn btn-primary" id="copyBtn"><i class="fas fa-copy me-1"></i> Copy</button>
        </div>
        <p class="small mb-0"><a href="<?= htmlspecialchars($privateUrl) ?>" target="_blank" rel="noopener">Open private link</a></p>
    </div>

    <div class="card card-soft p-3 p-md-4 mb-3">
        <h2 class="h5">Sign-in details</h2>
        <ul class="mb-0">
            <li>Username: <code><?= htmlspecialchars($username) ?></code></li>
            <li>Password: default manager password (reset below if needed)</li>
        </ul>
    </div>

    <div class="card card-soft p-3 p-md-4">
        <h2 class="h5">Manage</h2>
        <form method="post" class="d-flex flex-wrap gap-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <button type="submit" name="action" value="rotate" class="btn btn-outline-danger"
                    onclick="return confirm('Generate a new private link? The old link will stop working.');">
                <i class="fas fa-sync-alt me-1"></i> Generate new link
            </button>
            <button type="submit" name="action" value="reset_password" class="btn btn-outline-secondary"
                    onclick="return confirm('Reset username/password to the default manager credentials?');">
                <i class="fas fa-key me-1"></i> Reset default password
            </button>
        </form>
    </div>
</div>
<script>
document.getElementById('copyBtn').addEventListener('click', async function () {
    const input = document.getElementById('portalUrl');
    try {
        await navigator.clipboard.writeText(input.value);
        this.innerHTML = '<i class="fas fa-check me-1"></i> Copied';
    } catch (e) {
        input.select();
        document.execCommand('copy');
        this.innerHTML = '<i class="fas fa-check me-1"></i> Copied';
    }
});
</script>
</body>
</html>

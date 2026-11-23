<?php
/**
 * Private Francophonie Mobility manager portal.
 * Valid ?k=TOKEN opens tools with no username/password.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/fm_payment_records.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';

fm_ensure_manager_portal_schema($conn);
fm_ensure_schema($conn);
fm_ensure_payment_records_schema($conn);

if (isset($_GET['logout'])) {
    fm_manager_portal_logout();
    $settings = fm_manager_portal_settings($conn);
    $token = (string) ($settings['access_token'] ?? '');
    header('Location: fm-manager.php?k=' . rawurlencode($token));
    exit;
}

$token = trim((string) ($_GET['k'] ?? $_POST['k'] ?? ''));
if ($token !== '' && fm_manager_portal_token_valid($conn, $token)) {
    $_SESSION['fm_manager_portal_token'] = $token;
    fm_manager_portal_grant_access();
} elseif (!empty($_SESSION['fm_manager_portal_token'])
    && fm_manager_portal_token_valid($conn, (string) $_SESSION['fm_manager_portal_token'])) {
    $token = (string) $_SESSION['fm_manager_portal_token'];
    fm_manager_portal_grant_access();
}

$linkOk = $token !== '' && fm_manager_portal_token_valid($conn, $token) && fm_manager_portal_logged_in();
$flash = '';
$flashErr = '';

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['send_report'])) {
    if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $flashErr = 'Session expired. Refresh and try again.';
    } else {
        @set_time_limit(180);
        $privateUrl = fm_manager_portal_private_url($conn);
        $picked = $_POST['report_to'] ?? [];
        if (!is_array($picked)) {
            $picked = [];
        }
        $result = fm_send_manager_full_report($conn, $privateUrl, $picked);
        if (!empty($result['ok'])) {
            $flash = (string) ($result['message'] ?? 'Report sent.');
        } else {
            $flashErr = (string) ($result['message'] ?? 'Send failed.');
        }
    }
}

$privateUrl = fm_manager_portal_private_url($conn);
$reportRecipients = fm_payment_report_recipients();
$requiredReportTo = fm_payment_report_required_recipient();
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
    </style>
</head>
<body>
<?= fm_manager_portal_nav_html('hub') ?>
<div class="hero">
    <div class="container" style="max-width:960px">
        <h1 class="h4 mb-1"><i class="fas fa-shield-halved me-2"></i>Francophonie Mobility manager portal</h1>
        <p class="mb-0 opacity-75 small">Private link access — no username or password. Send the full Excel report, or open tools for more details.</p>
    </div>
</div>

<div class="container pb-5" style="max-width:960px">
    <?php if ($flash !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

    <div class="card card-soft p-3 p-md-4 mb-4">
        <h2 class="h5 mb-2">Send full report</h2>
        <p class="text-muted small mb-3">
            Emails the complete Excel report (Client tracking + Payment records) and asks recipients to open the private link for more details.
            <strong>info@francocana.com</strong> is always included.
        </p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="k" value="<?= htmlspecialchars($token) ?>">
            <div class="d-flex flex-wrap gap-3 mb-3">
                <?php foreach ($reportRecipients as $email => $meta): ?>
                    <?php $required = !empty($meta['required']); ?>
                    <label class="form-check mb-0">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="report_to[]"
                            value="<?= htmlspecialchars($email) ?>"
                            <?= $required ? 'checked disabled' : 'checked' ?>
                        >
                        <?php if ($required): ?>
                        <input type="hidden" name="report_to[]" value="<?= htmlspecialchars($requiredReportTo) ?>">
                        <?php endif; ?>
                        <span class="form-check-label">
                            <?= htmlspecialchars($email) ?>
                            <?= $required ? '<span class="text-muted small">(always)</span>' : '' ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="send_report" value="1" class="btn btn-success">
                <i class="fas fa-paper-plane me-1"></i> Send report
            </button>
            <span class="small text-muted ms-2">Attachment: Excel workbook</span>
        </form>
        <hr class="my-3">
        <p class="small mb-1 fw-semibold">For more details, use this private link:</p>
        <code class="small" style="word-break:break-all"><?= htmlspecialchars($privateUrl) ?></code>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-lg-3">
            <a class="tool-card" href="francophonie-mobility-staff-add.php">
                <div class="icon"><i class="fas fa-user-plus"></i></div>
                <h2>Smart Add</h2>
                <p>Create a candidate and file documents into groups.</p>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a class="tool-card" href="francophonie-mobility-applications.php">
                <div class="icon"><i class="fas fa-list-ul"></i></div>
                <h2>View list</h2>
                <p>Browse applications and update client status.</p>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a class="tool-card" href="francophonie-mobility-client-tracking.php">
                <div class="icon"><i class="fas fa-clipboard-check"></i></div>
                <h2>Overall report</h2>
                <p>Document checklist with green / red status.</p>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a class="tool-card" href="francophonie-mobility-payment-records.php">
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                <h2>Payment records</h2>
                <p>Sultan fees, balances, visa status, and comments.</p>
            </a>
        </div>
    </div>
</div>
</body>
</html>

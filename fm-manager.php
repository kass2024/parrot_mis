<?php
/**
 * Private Francophonie Mobility manager portal.
 * Valid ?k=TOKEN opens tools with no username/password.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';

fm_ensure_manager_portal_schema($conn);
fm_ensure_schema($conn);

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

$tools = [
    [
        'href' => 'francophonie-mobility-staff-add.php',
        'icon' => 'fa-user-plus',
        'tone' => 'green',
        'title' => 'Smart Add',
        'desc' => 'Create a candidate and file documents into visa groups.',
    ],
    [
        'href' => 'francophonie-mobility-applications.php',
        'icon' => 'fa-list-ul',
        'tone' => 'blue',
        'title' => 'View list',
        'desc' => 'Browse applications and update Waiting List, Ready2start, and more.',
    ],
    [
        'href' => 'francophonie-mobility-client-tracking.php',
        'icon' => 'fa-clipboard-check',
        'tone' => 'teal',
        'title' => 'Client Tracking',
        'desc' => 'Document checklist with green / red status and Excel export.',
    ],
    [
        'href' => 'francophonie-mobility-payment-records.php',
        'icon' => 'fa-money-bill-wave',
        'tone' => 'gold',
        'title' => 'Payment records',
        'desc' => 'Sultan fees, balances, visa status, and comments.',
    ],
];
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
        :root {
            --fm-green: #1e4d2b;
            --fm-blue: #3661B9;
            --fm-ink: #0f172a;
            --fm-muted: #64748b;
        }
        body {
            background:
                radial-gradient(1200px 420px at 10% -10%, rgba(54, 97, 185, .14), transparent 60%),
                radial-gradient(900px 380px at 100% 0%, rgba(30, 77, 43, .12), transparent 55%),
                #f1f5f0;
            color: var(--fm-ink);
            min-height: 100vh;
        }
        .hero {
            background: linear-gradient(135deg, #163b22 0%, var(--fm-green) 42%, var(--fm-blue) 100%);
            color: #fff;
            padding: 1.75rem 0 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .18);
        }
        .hero h1 {
            font-size: clamp(1.35rem, 2.4vw, 1.75rem);
            font-weight: 750;
            letter-spacing: -.02em;
            margin: 0 0 .35rem;
        }
        .hero p { margin: 0; opacity: .88; max-width: 42rem; }
        .section-label {
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--fm-muted);
            margin-bottom: .85rem;
        }
        .tool-card {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            height: 100%;
            min-height: 210px;
            padding: 1.35rem 1.25rem 1.2rem;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, .06);
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .07);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
            position: relative;
            overflow: hidden;
        }
        .tool-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: var(--accent, var(--fm-green));
        }
        .tool-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 34px rgba(15, 23, 42, .12);
            border-color: color-mix(in srgb, var(--accent, var(--fm-green)) 35%, #e2e8f0);
            color: inherit;
        }
        .tool-card .icon {
            width: 3.15rem;
            height: 3.15rem;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            background: var(--icon-bg, #e8f0ea);
            color: var(--accent, var(--fm-green));
        }
        .tool-card h2 {
            font-size: 1.2rem;
            font-weight: 750;
            letter-spacing: -.01em;
            margin: 0 0 .45rem;
        }
        .tool-card p {
            margin: 0;
            color: var(--fm-muted);
            font-size: .95rem;
            line-height: 1.45;
            flex: 1;
        }
        .tool-card .open {
            margin-top: 1.1rem;
            font-size: .86rem;
            font-weight: 700;
            color: var(--accent, var(--fm-green));
            display: inline-flex;
            align-items: center;
            gap: .4rem;
        }
        .tool-card.tone-green { --accent: #1e4d2b; --icon-bg: #e7f3eb; }
        .tool-card.tone-blue { --accent: #3661B9; --icon-bg: #e8eefc; }
        .tool-card.tone-teal { --accent: #0f766e; --icon-bg: #e6f7f5; }
        .tool-card.tone-gold { --accent: #b45309; --icon-bg: #fff4e5; }
        @media (max-width: 767.98px) {
            .tool-card { min-height: 0; }
        }
    </style>
</head>
<body>
<?= fm_manager_portal_nav_html('hub') ?>
<div class="hero">
    <div class="container" style="max-width:980px">
        <h1><i class="fas fa-shield-halved me-2"></i>Francophonie Mobility manager portal</h1>
        <p>Private link access — no username or password. Open a tool below for Smart Add, applications, Client Tracking, or payment records.</p>
    </div>
</div>

<div class="container pb-5" style="max-width:980px">
    <div class="section-label">Manager tools</div>
    <div class="row g-3 g-lg-4">
        <?php foreach ($tools as $tool): ?>
        <div class="col-md-6">
            <a class="tool-card tone-<?= htmlspecialchars($tool['tone']) ?>" href="<?= htmlspecialchars($tool['href']) ?>">
                <div class="icon"><i class="fas <?= htmlspecialchars($tool['icon']) ?>"></i></div>
                <h2><?= htmlspecialchars($tool['title']) ?></h2>
                <p><?= htmlspecialchars($tool['desc']) ?></p>
                <span class="open">Open <i class="fas fa-arrow-right"></i></span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>

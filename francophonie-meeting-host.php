<?php
/**
 * francophonie-meeting-host.php — Host meeting in browser (local @zoom/meetingsdk).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/env_load.php';
require_once __DIR__ . '/helpers/francophonie_meeting_invitation_schema.php';
require_once __DIR__ . '/helpers/zoom_meeting_sdk.php';

xander_load_env_file();
fm_meeting_ensure_schema($conn);

if (empty($_SESSION['id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'staff'], true)) {
    header('Location: admin-login.php');
    exit;
}

$invitationId = (int) ($_GET['invitation_id'] ?? 0);
$topic = 'Meeting';
$sdkAuth = null;
$sdkError = '';
$startUrl = '';

$publicBase = fm_zoom_public_base_url();
$assetBase = $publicBase . '/assets/zoom-meetingsdk';
$jsBase = $publicBase . '/assets/js';
$meetingJs = fm_zoom_meeting_js_file();
$assetsOk = fm_zoom_sdk_assets_installed();

if ($invitationId > 0) {
    $st = $conn->prepare(
        'SELECT topic, zoom_meeting_number, zoom_password, zoom_start_url FROM francophonie_mobility_meeting_invitations WHERE id = ? LIMIT 1'
    );
    $st->bind_param('i', $invitationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        $topic = (string) ($row['topic'] ?? $topic);
        $meetingNumber = (string) ($row['zoom_meeting_number'] ?? '');
        $password = (string) ($row['zoom_password'] ?? '');
        $startUrl = (string) ($row['zoom_start_url'] ?? '');

        $adminName = trim((string) (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
        if ($adminName === '') {
            $adminName = trim((string) ($_SESSION['username'] ?? 'Host'));
        }
        $adminEmail = trim((string) ($_SESSION['email'] ?? ''));

        $plBase = fm_meeting_parrot_learning_frontend_base();
        if ($plBase !== '' && $meetingNumber !== '') {
            $embedPath = fm_meeting_embed_room_path($meetingNumber, 1, $password, $adminName, $adminEmail !== '' ? $adminEmail : null);
            header('Location: ' . $plBase . $embedPath);
            exit;
        }

        $sdkResult = zoom_sdk_build_join_payload(
            $meetingNumber,
            $adminName,
            1,
            $password,
            $adminEmail !== '' ? $adminEmail : null,
            true
        );
        if ($sdkResult['ok']) {
            $sdkAuth = $sdkResult['sdk'];
            if ($password !== '') {
                $sdkAuth['password_candidates'] = array_values(array_unique([$password, '']));
            }
        } else {
            $sdkError = (string) ($sdkResult['message'] ?? 'SDK auth failed');
        }
    } else {
        $sdkError = 'Meeting invitation not found.';
    }
} else {
    $sdkError = 'Missing invitation_id.';
}

if (!$assetsOk && $sdkError === '') {
    $sdkError = 'Zoom Meeting SDK files are missing. Run: npm install (in parrot_mis folder).';
}
if (!zoom_sdk_is_configured() && $sdkError === '') {
    $sdkError = 'Zoom embed credentials missing. Set ZOOM_EMBED_CLIENT_ID and ZOOM_EMBED_CLIENT_SECRET in .env.';
}

$leaveUrl = $publicBase . '/francophonie-meeting-invitation.php';
$zoomLibUrl = $assetBase . '/dist/lib';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host meeting — <?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase . '/dist/ui/zoom-meetingsdk.css', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preload" href="<?= htmlspecialchars($assetBase . '/dist/' . $meetingJs, ENT_QUOTES, 'UTF-8') ?>" as="script">
    <style>
        html, body { margin:0; padding:0; width:100%; height:100%; overflow:hidden; background:#1a1a1a; }
        #zmmtg-root {
            display:none; position:fixed; inset:0; width:100vw; height:100dvh; z-index:1;
        }
        html.zoom-client-meeting-active #zmmtg-root,
        body.zoom-client-meeting-active #zmmtg-root { display:block; }
        .host-boot {
            position:fixed; inset:0; z-index:10; display:flex; flex-direction:column;
            align-items:center; justify-content:center; gap:.75rem; padding:1.5rem;
            text-align:center; background:#0f172a; color:#e2e8f0; font-family:Arial,sans-serif;
            transition:opacity .35s ease;
        }
        .host-boot.hidden { opacity:0; pointer-events:none; }
        .host-boot .err { color:#fecaca; background:#7f1d1d55; padding:.75rem 1rem; border-radius:8px; max-width:560px; }
        .host-boot .hint { color:#94a3b8; font-size:.85rem; max-width:480px; }
        .spinner {
            width:42px; height:42px; border:3px solid #334155; border-top-color:#22c55e;
            border-radius:50%; animation:spin .8s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        .back-link {
            position:fixed; top:12px; left:12px; z-index:2147483000;
            background:#1e293b; color:#fff; text-decoration:none; padding:.45rem .8rem;
            border-radius:8px; font-size:.85rem; border:1px solid #334155;
        }
        .fallback-link {
            display:none; margin-top:.5rem; color:#93c5fd; font-size:.85rem;
        }
        .host-boot.show-fallback .fallback-link { display:inline-block; }
    </style>
</head>
<body>
<a class="back-link" href="francophonie-meeting-invitation.php">&larr; Invitations</a>
<div id="zmmtg-root"></div>
<div class="host-boot" id="hostBoot">
    <div class="spinner" id="hostSpinner"></div>
    <div id="hostBootTitle">Starting Zoom meeting…</div>
    <div class="hint" id="hostBootSub"><?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="err" id="hostBootErr" style="display:none"></div>
    <?php if ($startUrl !== ''): ?>
    <a class="fallback-link" id="hostFallback" href="<?= htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open in Zoom desktop app instead</a>
    <?php endif; ?>
</div>

<script src="<?= htmlspecialchars($assetBase . '/vendor/react.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/react-dom.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/redux.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/redux-thunk.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script defer src="<?= htmlspecialchars($assetBase . '/dist/' . $meetingJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script defer src="<?= htmlspecialchars($jsBase . '/francophonie-zoom-host.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sdk = <?= $sdkAuth ? json_encode($sdkAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null' ?>;
    const serverError = <?= json_encode($sdkError, JSON_UNESCAPED_UNICODE) ?>;
    const leaveUrl = <?= json_encode($leaveUrl, JSON_UNESCAPED_UNICODE) ?>;
    const zoomLibUrl = <?= json_encode($zoomLibUrl, JSON_UNESCAPED_UNICODE) ?>;

    const boot = document.getElementById('hostBoot');
    const bootErr = document.getElementById('hostBootErr');
    const bootTitle = document.getElementById('hostBootTitle');
    const bootSub = document.getElementById('hostBootSub');
    const spinner = document.getElementById('hostSpinner');

    setTimeout(function () { boot.classList.add('show-fallback'); }, 25000);

    function showError(msg) {
        bootTitle.textContent = 'Could not start meeting';
        bootSub.style.display = 'none';
        spinner.style.display = 'none';
        bootErr.style.display = 'block';
        bootErr.textContent = msg;
        boot.classList.add('show-fallback');
    }

    function hideBoot() {
        boot.classList.add('hidden');
        setTimeout(function () { boot.style.display = 'none'; }, 400);
    }

    if (serverError) {
        showError(serverError);
        return;
    }

    function waitForZoom(cb, tries) {
        if (typeof startFrancophonieZoomHost === 'function' && typeof ZoomMtg !== 'undefined') {
            cb();
            return;
        }
        if (tries <= 0) {
            showError('Zoom SDK scripts did not load. Hard-refresh (Ctrl+F5) and try again.');
            return;
        }
        setTimeout(function () { waitForZoom(cb, tries - 1); }, 100);
    }

    waitForZoom(function () {
        startFrancophonieZoomHost({
            sdk: sdk,
            leaveUrl: leaveUrl,
            zoomLibUrl: zoomLibUrl,
            onStatus: function (msg) {
                bootTitle.textContent = msg;
            },
            onError: showError,
            onJoined: hideBoot,
        });
    }, 80);
});
</script>
</body>
</html>

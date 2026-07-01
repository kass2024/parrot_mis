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
$adminName = 'Host';
$adminEmail = '';

$publicBase = fm_zoom_public_base_url();
$assetBase = $publicBase . '/assets/zoom-meetingsdk';
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

        $hostIdentity = zoom_api_resolve_host_join_identity(true);
        $adminName = $hostIdentity['name'];
        $adminEmail = $hostIdentity['email'];

        if ($adminEmail === '') {
            $sdkError = 'Could not load Zoom host profile. Check ZOOM_HOST_USER_ID and Zoom API credentials in .env.';
        }

        if ($sdkError === '' && $meetingNumber !== '') {
            $plBase = fm_meeting_parrot_learning_frontend_base();
            if ($plBase !== '') {
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
            } else {
                $sdkError = (string) ($sdkResult['message'] ?? 'SDK auth failed');
            }
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
$hostAttendanceMeta = [
    'invitation_id' => $invitationId,
    'participant_type' => 'host',
    'participant_name' => is_array($sdkAuth) ? (string) ($sdkAuth['user_name'] ?? $adminName) : $adminName,
    'participant_email' => is_array($sdkAuth) ? (string) ($sdkAuth['user_email'] ?? $adminEmail) : $adminEmail,
];
?>
<!DOCTYPE html>
<html lang="en" class="zoom-client-meeting-active">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host meeting — <?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase . '/dist/ui/zoom-meetingsdk.css', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/francophonie-zoom-room.css">
    <style>
        html, body { background:#1a1a1a; font-family:Arial,sans-serif; }
        .host-boot {
            position:fixed; inset:0; z-index:5; display:flex; flex-direction:column;
            align-items:center; justify-content:center; gap:1rem; padding:1.5rem;
            text-align:center; background:#0f172a; color:#e2e8f0; font-family:Arial,sans-serif;
        }
        .host-boot.hidden { opacity:0; pointer-events:none; transition:opacity .3s; }
        .host-boot .err { color:#fecaca; background:#7f1d1d55; padding:.75rem 1rem; border-radius:8px; max-width:560px; }
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
    </style>
</head>
<body class="zoom-client-meeting-active">
<a class="back-link" href="francophonie-meeting-invitation.php">&larr; Invitations</a>
<div id="zmmtg-root"></div>
<div class="host-boot" id="hostBoot">
    <div class="spinner" id="hostSpinner"></div>
    <div id="hostBootTitle">Starting Zoom meeting…</div>
    <div style="color:#94a3b8;font-size:.9rem;max-width:520px"><?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?></div>
    <?php if ($adminName !== '' && $adminName !== 'Host'): ?>
    <div style="color:#94a3b8;font-size:.85rem">Host: <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="err" id="hostBootErr" style="display:none"></div>
    <?php if ($startUrl !== ''): ?>
    <a id="hostFallback" href="<?= htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="display:none;margin-top:8px;color:#93c5fd;font-size:.85rem">Open in Zoom app</a>
    <?php endif; ?>
</div>

<script src="<?= htmlspecialchars($assetBase . '/vendor/react.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/react-dom.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/redux.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/vendor/redux-thunk.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/dist/' . $meetingJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="assets/js/francophonie-zoom-room.js"></script>
<script>
(function () {
    var sdk = <?= $sdkAuth ? json_encode($sdkAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null' ?>;
    var serverError = <?= json_encode($sdkError, JSON_UNESCAPED_UNICODE) ?>;
    var leaveUrl = <?= json_encode($leaveUrl, JSON_UNESCAPED_UNICODE) ?>;
    var zoomLibUrl = <?= json_encode($zoomLibUrl, JSON_UNESCAPED_UNICODE) ?>;
    var attendanceMeta = <?= json_encode($hostAttendanceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var fmAttendanceId = 0;
    var errMsg = (window.FmZoomRoom && FmZoomRoom.errMsg) ? FmZoomRoom.errMsg : function (e) { return String(e); };

    function recordAttendance(action) {
        var body = Object.assign({ action: action }, attendanceMeta);
        if (action === 'leave' && fmAttendanceId > 0) body.attendance_id = fmAttendanceId;
        var payload = JSON.stringify(body);
        if (action === 'leave' && navigator.sendBeacon) {
            navigator.sendBeacon('record_fm_meeting_attendance.php', new Blob([payload], { type: 'application/json' }));
            return;
        }
        fetch('record_fm_meeting_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: payload,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.ok && d.attendance_id) fmAttendanceId = d.attendance_id;
        }).catch(function () {});
    }
    window.addEventListener('beforeunload', function () {
        if (fmAttendanceId > 0) recordAttendance('leave');
    });

    var boot = document.getElementById('hostBoot');
    var bootErr = document.getElementById('hostBootErr');
    var bootTitle = document.getElementById('hostBootTitle');
    var spinner = document.getElementById('hostSpinner');
    var fallback = document.getElementById('hostFallback');

    setTimeout(function () { if (fallback) fallback.style.display = 'inline-block'; }, 20000);

    function showError(msg) {
        boot.style.display = 'flex';
        boot.classList.remove('hidden');
        bootTitle.textContent = 'Could not start meeting';
        spinner.style.display = 'none';
        bootErr.style.display = 'block';
        bootErr.textContent = msg;
        if (fallback) fallback.style.display = 'inline-block';
    }

    function hideBoot() {
        boot.classList.add('hidden');
        setTimeout(function () { boot.style.display = 'none'; }, 350);
    }

    function showZoomRoot() {
        document.documentElement.classList.add('zoom-client-meeting-active');
        document.body.classList.add('zoom-client-meeting-active');
        var root = document.getElementById('zmmtg-root');
        if (root) root.style.display = 'block';
    }

    function doJoin(passWord, useZak) {
        return new Promise(function (resolve, reject) {
            var done = false;
            function finish(ok, val) {
                if (done) return;
                done = true;
                ok ? resolve(val) : reject(val);
            }
            var timer = setTimeout(function () {
                finish(false, new Error('Join timed out. Use “Open in Zoom app” below.'));
            }, 90000);

            ZoomMtg.inMeetingServiceListener('onMeetingStatus', function (data) {
                if (data && data.status === 2) {
                    clearTimeout(timer);
                    finish(true);
                }
            });

            var payload = {
                signature: sdk.signature,
                meetingNumber: String(sdk.meeting_number),
                userName: sdk.user_name || 'Host',
                passWord: passWord,
                success: function () {
                    setTimeout(function () { clearTimeout(timer); finish(true); }, 1500);
                },
                error: function (err) {
                    clearTimeout(timer);
                    finish(false, new Error(errMsg(err)));
                }
            };
            if (sdk.user_email) payload.userEmail = sdk.user_email;
            if (useZak && sdk.zak) payload.zak = sdk.zak;
            ZoomMtg.join(payload);
        });
    }

    function joinMeeting() {
        var passwords = [sdk.password || '', ''];
        var tries = [{ p: passwords[0], z: true }, { p: passwords[0], z: false }, { p: '', z: false }];
        var i = 0;
        function next(err) {
            if (i >= tries.length) {
                showError(err && err.message ? err.message : 'Join failed');
                return;
            }
            var t = tries[i++];
            bootTitle.textContent = 'Joining meeting…';
            doJoin(t.p, t.z).then(function () {
                recordAttendance('join');
                hideBoot();
            }).catch(next);
        }
        next();
    }

    if (serverError) { showError(serverError); return; }
    if (!sdk || !sdk.signature) { showError('SDK credentials missing.'); return; }
    if (typeof ZoomMtg === 'undefined' || !window.FmZoomRoom) { showError('Zoom SDK not loaded. Run npm install.'); return; }

    bootTitle.textContent = 'Loading Zoom…';
    showZoomRoot();
    FmZoomRoom.prepareSdk(zoomLibUrl)
        .then(function () {
            bootTitle.textContent = 'Initializing…';
            return FmZoomRoom.initClient(leaveUrl);
        })
        .then(function () {
            hideBoot();
            joinMeeting();
        })
        .catch(function (e) { showError(e.message || String(e)); });
})();
</script>
</body>
</html>

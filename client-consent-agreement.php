<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';
require_once __DIR__ . '/helpers/client_consent_document.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

client_consent_ensure_schema($conn);

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

$stmt = $conn->prepare('SELECT * FROM client_consent_agreements WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$agreement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$agreement) {
    http_response_code(404);
    exit('This agreement link is invalid or has expired.');
}

$isSigned = ($agreement['status'] ?? '') === 'signed';
if (($agreement['agreement_date'] ?? '') === '') {
    $agreement['agreement_date'] = date('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Client Consent, Referral, and File-Transfer Agreement</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; padding: 28px 14px 60px; background: #eef2f7; color: #111827; font-family: Georgia, "Times New Roman", serif; }
.paper { max-width: 860px; margin: 0 auto; background: #fff; padding: 42px 48px 36px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.08); }
.doc-company, h1, .doc-kicker { text-align: center; }
h1 { font-size: 26px; line-height: 1.25; margin: 8px 0 4px; }
.doc-kicker { font-weight: bold; margin-top: 0; }
h2 { font-size: 18px; margin: 22px 0 8px; }
p, li { font-size: 16px; line-height: 1.55; }
.field-line { margin: 10px 0; }
.field-line span { display: block; font-family: "Segoe UI", sans-serif; font-size: 13px; color: #374151; margin-bottom: 3px; }
.cc-field { width: 100%; border: none; border-bottom: 1px solid #111; background: transparent; font: 16px Georgia, serif; padding: 6px 0; }
textarea.cc-field { border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; }
.field-line.cc-missing span { color: #b91c1c; font-weight: 700; }
.field-line.cc-missing span::after { content: " — please fill this"; font-weight: 600; }
.cc-field.cc-missing { background: #fef2f2; border-bottom: 2px solid #dc2626; }
textarea.cc-field.cc-missing { border: 2px solid #dc2626; }
.signature-canvas.cc-missing { border: 2px solid #dc2626; background: #fef2f2; }
.accept.cc-missing { color: #b91c1c; font-weight: 700; }
#signStatus { color: #b91c1c; font-family: "Segoe UI", sans-serif; min-height: 1.4em; }
.sign-box { margin-top: 18px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb; font-family: "Segoe UI", sans-serif; }
.signature-canvas { width: 100%; height: 160px; background: #fff; border: 1px dashed #9ca3af; border-radius: 8px; touch-action: none; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
button, .download { border: 0; border-radius: 8px; padding: 12px 16px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
#signContract { background: #1f4fd8; color: #fff; }
#clearSignature, .download { background: #e5e7eb; color: #111827; }
.accept { font-family: "Segoe UI", sans-serif; margin: 16px 0; }
.done { font-family: "Segoe UI", sans-serif; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 16px; border-radius: 10px; }
.company-sign img { max-height: 70px; max-width: 240px; }
@media (max-width: 700px) { .paper { padding: 22px 16px; } h1 { font-size: 22px; } }
</style>
</head>
<body>
<div class="paper">
<?php if ($isSigned): ?>
    <div class="done">
        <strong>This agreement is already signed.</strong>
        <p>A copy is stored with Parrot Canada Visa Consultant. You can download the signed PDF below.</p>
        <p><a class="download" href="download-client-consent.php?token=<?= cc_h($token) ?>">Download signed agreement</a></p>
    </div>
    <?= client_consent_document_html($agreement, 'pdf') ?>
<?php else: ?>
    <form id="consentForm" onsubmit="return false;">
        <?= client_consent_document_html($agreement, 'form') ?>
        <h2>Signatures</h2>
        <div class="company-sign">
            <p><strong>For Parrot Canada Visa Consultant Co. Ltd.</strong><br>
            Name: Dr. Jean Pierre Twajamahoro<br>
            Title: Managing Director/Owner</p>
            <?php if (is_file(__DIR__ . '/admin/signature-manager.png')): ?>
                <img src="admin/signature-manager.png" alt="Managing Director signature">
            <?php endif; ?>
        </div>
        <div class="sign-box">
            <strong>Authorized office representative</strong>
            <p>Optional. Leave blank if the office representative will sign later.</p>
            <p class="field-line"><span>Office (Kigali Office / Musanze Office)</span><input class="cc-field" type="text" name="office_location" value="<?= cc_h((string) ($agreement['office_location'] ?? '')) ?>" autocomplete="off"></p>
            <p class="field-line"><span>Full name</span><input class="cc-field" type="text" name="office_representative_name" value="<?= cc_h((string) ($agreement['office_representative_name'] ?? '')) ?>" autocomplete="off"></p>
            <p class="field-line"><span>Position</span><input class="cc-field" type="text" name="office_representative_position" value="<?= cc_h((string) ($agreement['office_representative_position'] ?? '')) ?>" autocomplete="off"></p>
        </div>
        <div class="sign-box">
            <strong>Witness (optional)</strong>
            <p class="field-line"><span>Full name</span><input class="cc-field" type="text" name="witness_name" value="<?= cc_h((string) ($agreement['witness_name'] ?? '')) ?>" autocomplete="off"></p>
            <p class="field-line"><span>Passport/ID number</span><input class="cc-field" type="text" name="witness_passport" value="<?= cc_h((string) ($agreement['witness_passport'] ?? '')) ?>" autocomplete="off"></p>
        </div>
        <div class="sign-box">
            <strong>Client signature</strong>
            <p>Draw your signature in the box. This is your acceptance of the referral and file transfer.</p>
            <canvas class="signature-canvas"></canvas>
            <label class="accept"><input type="checkbox" id="accepted"> I have read this Agreement. I voluntarily accept the referral and authorize the transfer of my necessary information and documents.</label>
            <div class="actions">
                <button type="button" id="clearSignature">Clear signature</button>
                <button type="button" id="signContract">Sign and submit</button>
            </div>
            <p id="signStatus"></p>
        </div>
    </form>
    <script>
    (() => {
        const canvas = document.querySelector('.signature-canvas');
        const ctx = canvas.getContext('2d');
        let drawing = false;
        let points = [];
        function resizeCanvas() {
            const ratio = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            const snapshot = canvas.toDataURL();
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#111';
            if (snapshot && snapshot.length > 120) {
                const img = new Image();
                img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
                img.src = snapshot;
            }
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            const src = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) { e.preventDefault(); drawing = true; canvas.classList.remove('cc-missing'); points = [pos(e)]; ctx.beginPath(); ctx.moveTo(points[0].x, points[0].y); }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            const p = pos(e);
            points.push(p);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
        }
        function stop() { drawing = false; points = []; }
        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        canvas.addEventListener('mouseup', stop);
        canvas.addEventListener('mouseleave', stop);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', stop);
        document.getElementById('clearSignature').addEventListener('click', () => ctx.clearRect(0, 0, canvas.width, canvas.height));
        function hasInk() {
            const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
            return pixels.some(channel => channel !== 0);
        }
        function field(name) {
            return document.querySelector('[name="' + name + '"]');
        }
        function value(name) {
            const el = field(name);
            return el ? el.value.trim() : '';
        }
        function initialsFrom(name) {
            return name.split(/\s+/).filter(Boolean).map(part => part.charAt(0).toUpperCase()).join('').slice(0, 8);
        }
        function todayIso() {
            const now = new Date();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            return now.getFullYear() + '-' + month + '-' + day;
        }
        function autofill() {
            const date = field('agreement_date');
            if (date && !date.value) date.value = todayIso();
            const name = field('client_name');
            const initials = field('client_initials');
            if (name && initials && !initials.dataset.userEdited && name.value.trim()) {
                initials.value = initialsFrom(name.value);
            }
            const shown = document.getElementById('authClientName');
            if (shown && name) shown.textContent = name.value.trim() || '________________';
        }
        const nameInput = field('client_name');
        const initialsInput = field('client_initials');
        if (nameInput) nameInput.addEventListener('input', autofill);
        if (initialsInput) initialsInput.addEventListener('input', () => { initialsInput.dataset.userEdited = '1'; });
        document.getElementById('accepted')?.addEventListener('change', (event) => {
            if (event.target.checked) event.target.closest('.accept')?.classList.remove('cc-missing');
        });
        autofill();
        document.querySelectorAll('.cc-field').forEach(el => {
            el.addEventListener('input', () => {
                if (el.value.trim()) {
                    el.classList.remove('cc-missing');
                    el.closest('.field-line')?.classList.remove('cc-missing');
                }
            });
        });
        document.getElementById('signContract').addEventListener('click', () => {
            const status = document.getElementById('signStatus');
            autofill();
            document.querySelectorAll('.cc-missing').forEach(el => el.classList.remove('cc-missing'));
            const required = ['client_name','passport_number','nationality','date_of_birth','residential_address','phone','email','service_type','agreement_date','client_initials'];
            const missing = [];
            required.forEach(key => {
                const el = field(key);
                if (!el || !el.value.trim()) {
                    if (el) {
                        el.classList.add('cc-missing');
                        el.closest('.field-line')?.classList.add('cc-missing');
                        missing.push(el.closest('.field-line')?.querySelector('span')?.textContent || key);
                    }
                }
            });
            const email = value('email');
            const emailField = field('email');
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && emailField) {
                emailField.classList.add('cc-missing');
                emailField.closest('.field-line')?.classList.add('cc-missing');
                missing.push('Email address (not a valid email)');
            }
            const accepted = document.getElementById('accepted');
            if (!accepted.checked) {
                accepted.closest('.accept')?.classList.add('cc-missing');
                missing.push('Acceptance confirmation');
            }
            if (!hasInk()) {
                canvas.classList.add('cc-missing');
                missing.push('Signature');
            }
            if (missing.length) {
                status.textContent = 'Still needed: ' + missing.join(', ') + '.';
                const first = document.querySelector('.cc-missing');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            status.textContent = '';
            const button = document.getElementById('signContract');
            button.disabled = true;
            button.textContent = 'Submitting...';
            const payload = { token: <?= json_encode($token) ?>, accepted: true, signature: canvas.toDataURL('image/png') };
            ['client_name','passport_number','nationality','date_of_birth','residential_address','phone','email','service_type','agreement_date','client_initials','governing_law','dispute_jurisdiction','office_location','office_representative_name','office_representative_position','witness_name','witness_passport'].forEach(key => payload[key] = value(key));
            fetch('submit-client-consent.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Signing failed');
                    status.textContent = data.pdf_error || 'Agreement signed. Your copy is saved.';
                    setTimeout(() => location.reload(), 900);
                })
                .catch(err => {
                    button.disabled = false;
                    button.textContent = 'Sign and submit';
                    status.textContent = err.message;
                });
        });
    })();
    </script>
<?php endif; ?>
</div>
</body>
</html>

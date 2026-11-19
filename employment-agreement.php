<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/employment_agreement_schema.php';
require_once __DIR__ . '/helpers/employment_agreement_document.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

employment_agreement_ensure_schema($conn);

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

$stmt = $conn->prepare('SELECT * FROM employment_agreements WHERE contract_token = ? LIMIT 1');
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
<title>International Employment Application and Service Agreement</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<style>
* { box-sizing: border-box; }
html, body { max-width: 100%; overflow-x: hidden; }
body { margin: 0; padding: 28px 14px 60px; background: #eef2f7; color: #111827; font-family: Georgia, "Times New Roman", serif; }
.paper { width: 100%; max-width: 860px; margin: 0 auto; background: #fff; padding: 42px 48px 36px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.08); overflow-wrap: break-word; }
.doc-company, h1, .doc-kicker { text-align: center; text-wrap: balance; }
.doc-company span { display: block; }
h1 { font-size: 26px; line-height: 1.25; margin: 8px 0 4px; }
.doc-kicker { font-weight: bold; margin-top: 0; line-height: 1.4; }
.company-facts { margin: 8px 0 14px; }
.company-facts div { margin: 0 0 12px; }
.company-facts dt { font-family: "Segoe UI", sans-serif; font-size: 12px; font-weight: 700; letter-spacing: .02em; color: #4b5563; }
.company-facts dd { margin: 2px 0 0; overflow-wrap: anywhere; }
h2 { font-size: 18px; margin: 22px 0 8px; }
h3 { font-size: 16px; margin: 14px 0 6px; }
p, li { font-size: 16px; line-height: 1.55; }
.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; margin: 12px 0 18px; padding: 16px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 14px; }
.field-line { margin: 10px 0; }
.details-grid .field-line { margin: 0; }
.field-line.wide { grid-column: 1 / -1; }
.field-line span { display: block; font-family: "Segoe UI", sans-serif; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.ea-field { width: 100%; max-width: 100%; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; font: 16px "Segoe UI", sans-serif; padding: 11px 12px; color: #111827; }
.ea-field:focus { outline: none; border-color: #1f4fd8; box-shadow: 0 0 0 3px rgba(31,79,216,.16); }
textarea.ea-field { min-height: 76px; resize: vertical; }
.country-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 14px 0 18px; }
.country-card { position: relative; display: flex; align-items: center; gap: 12px; min-height: 64px; margin: 0; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 14px; background: #fff; cursor: pointer; font-family: "Segoe UI", sans-serif; font-size: 15px; font-weight: 700; color: #1f2937; }
.country-card img { width: 48px; height: 32px; object-fit: cover; border-radius: 4px; box-shadow: 0 0 0 1px rgba(15,23,42,.12); flex: 0 0 auto; }
.country-card input { position: absolute; opacity: 0; pointer-events: none; }
.country-card:hover { border-color: #93c5fd; background: #f8fbff; }
.country-card:has(input:checked) { border-color: #1f4fd8; background: #eef4ff; box-shadow: 0 0 0 3px rgba(31,79,216,.16); }
.country-card:has(input:checked)::after { content: "✓"; position: absolute; top: 8px; right: 10px; width: 22px; height: 22px; border-radius: 50%; background: #1f4fd8; color: #fff; font-size: 13px; line-height: 22px; text-align: center; }
.ack-item { display: flex; gap: 8px; align-items: flex-start; }
.ack-list { list-style: none; padding: 0; }
.ack-list li { margin: 8px 0; }
.field-line.ea-missing span, .country-grid.ea-missing, .ack-item.ea-missing { color: #b91c1c; font-weight: 700; }
.field-line.ea-missing span::after { content: " — please fill this"; font-weight: 600; }
.ea-field.ea-missing { background: #fef2f2; border: 2px solid #dc2626; }
.country-grid.ea-missing { outline: 2px solid #dc2626; background: #fef2f2; border-radius: 8px; padding: 8px; }
.signature-canvas.ea-missing { border: 2px solid #dc2626; background: #fef2f2; }
.sign-box { margin-top: 18px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb; font-family: "Segoe UI", sans-serif; }
.signature-canvas { width: 100%; height: 160px; background: #fff; border: 1px dashed #9ca3af; border-radius: 8px; touch-action: none; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
button, .download { border: 0; border-radius: 8px; padding: 12px 16px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
#signContract { background: #1f4fd8; color: #fff; }
#clearSignature, .download { background: #e5e7eb; color: #111827; }
.done { font-family: "Segoe UI", sans-serif; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 16px; border-radius: 10px; }
.company-sign img { max-height: 70px; max-width: 240px; }
#signStatus { color: #b91c1c; font-family: "Segoe UI", sans-serif; min-height: 1.4em; }
@media (max-width: 700px) {
  body { padding: 8px 8px 36px; }
  .paper { padding: 18px 14px 22px; border-radius: 12px; }
  h1 { font-size: 22px; }
  h2 { font-size: 18px; }
  .doc-kicker, p, li { font-size: 15px; }
  .details-grid { padding: 12px; gap: 12px; }
  .country-grid, .details-grid { grid-template-columns: 1fr; }
  .country-card { min-height: 58px; padding-right: 40px; }
  .ack-item input { width: 22px; height: 22px; flex: 0 0 22px; margin-top: 2px; }
  .actions { flex-direction: column; }
  .actions button, .download { width: 100%; text-align: center; }
  .signature-canvas { height: 190px; }
  .company-sign img { max-width: 100%; height: auto; }
}
</style>
</head>
<body>
<div class="paper">
<?php if ($isSigned): ?>
    <div class="done">
        <strong>This agreement is already signed.</strong>
        <p>A copy is stored with Parrot Canada Visa Consultant. You can download the signed PDF below.</p>
        <p><a class="download" href="download-employment-agreement.php?token=<?= ea_h($token) ?>">Download signed agreement</a></p>
    </div>
    <?= employment_agreement_document_html($agreement, 'pdf') ?>
<?php else: ?>
    <form id="employmentForm" onsubmit="return false;">
        <?= employment_agreement_document_html($agreement, 'form') ?>
        <h2>Article 16: Signatures</h2>
        <div class="company-sign">
            <p><strong>Parrot Canada Visa Consultant Co. Ltd.</strong><br>
            Dr. Jean Pierre Twajamahoro, Managing Director / Owner</p>
            <?php if (is_file(__DIR__ . '/admin/signature-manager.png')): ?>
                <img src="admin/signature-manager.png" alt="Managing Director signature">
            <?php endif; ?>
        </div>
        <div class="sign-box">
            <strong>Client signature</strong>
            <p>Selected country: <strong id="shownCountry">not selected yet</strong></p>
            <canvas class="signature-canvas"></canvas>
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
        function resizeCanvas() {
            const ratio = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#111';
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            const src = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) { e.preventDefault(); drawing = true; canvas.classList.remove('ea-missing'); const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e) { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
        function stop() { drawing = false; }
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
        function field(name) { return document.querySelector('[name="' + name + '"]'); }
        function value(name) { const el = field(name); return el ? el.value.trim() : ''; }
        function selectedCountry() {
            const chosen = document.querySelector('input[name="destination_country"]:checked');
            return chosen ? chosen.value : '';
        }
        function todayIso() {
            const now = new Date();
            return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        }
        function autofill() {
            const date = field('agreement_date');
            if (date && !date.value) date.value = todayIso();
            const name = field('client_name');
            const shown = document.getElementById('authClientName');
            if (shown && name) shown.textContent = name.value.trim() || '________________';
            const country = document.getElementById('shownCountry');
            if (country) country.textContent = selectedCountry() || 'not selected yet';
        }
        field('client_name')?.addEventListener('input', autofill);
        document.querySelectorAll('input[name="destination_country"]').forEach(el => el.addEventListener('change', () => {
            document.querySelector('.country-grid')?.classList.remove('ea-missing');
            autofill();
        }));
        document.querySelectorAll('.ea-field').forEach(el => el.addEventListener('input', () => {
            if (el.value.trim()) {
                el.classList.remove('ea-missing');
                el.closest('.field-line')?.classList.remove('ea-missing');
            }
        }));
        document.querySelectorAll('.ack-box').forEach(el => el.addEventListener('change', () => {
            if (el.checked) el.closest('.ack-item')?.classList.remove('ea-missing');
        }));
        autofill();
        document.getElementById('signContract').addEventListener('click', () => {
            const status = document.getElementById('signStatus');
            autofill();
            document.querySelectorAll('.ea-missing').forEach(el => el.classList.remove('ea-missing'));
            const required = ['client_name','nationality','passport_number','date_of_birth','residential_address','phone','email','preferred_job','preferred_city','agreement_date'];
            const missing = [];
            required.forEach(key => {
                const el = field(key);
                if (!el || !el.value.trim()) {
                    el?.classList.add('ea-missing');
                    el?.closest('.field-line')?.classList.add('ea-missing');
                    missing.push(el?.closest('.field-line')?.querySelector('span')?.textContent || key);
                }
            });
            const email = value('email');
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                field('email')?.classList.add('ea-missing');
                field('email')?.closest('.field-line')?.classList.add('ea-missing');
                missing.push('Email address (not a valid email)');
            }
            if (!selectedCountry()) {
                document.querySelector('.country-grid')?.classList.add('ea-missing');
                missing.push('Destination country');
            }
            document.querySelectorAll('.ack-box').forEach(box => {
                if (!box.checked) {
                    box.closest('.ack-item')?.classList.add('ea-missing');
                    missing.push('Declaration');
                }
            });
            if (missing.includes('Declaration')) {
                const only = missing.filter((item, index) => item !== 'Declaration' || missing.indexOf('Declaration') === index);
                missing.splice(0, missing.length, ...only);
            }
            if (!hasInk()) {
                canvas.classList.add('ea-missing');
                missing.push('Signature');
            }
            if (missing.length) {
                status.textContent = 'Still needed: ' + missing.join(', ') + '.';
                document.querySelector('.ea-missing')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const button = document.getElementById('signContract');
            button.disabled = true;
            button.textContent = 'Submitting...';
            const payload = { token: <?= json_encode($token) ?>, signature: canvas.toDataURL('image/png'), destination_country: selectedCountry() };
            ['client_name','nationality','passport_number','date_of_birth','residential_address','phone','email','preferred_job','preferred_city','expected_salary','employer_partner','place_of_signing','contracting_jurisdiction','agreement_date'].forEach(key => payload[key] = value(key));
            document.querySelectorAll('.ack-box').forEach(box => { payload[box.name] = box.checked ? 1 : 0; });
            fetch('submit-employment-agreement.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
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

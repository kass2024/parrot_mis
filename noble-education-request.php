<?php
declare(strict_types=1);

ob_start();
session_name('NEG_EDUCATION_FORM');
session_start();

if (isset($_GET['new']) && (string) $_GET['new'] === '1') {
    $_SESSION['user_id'] = 'neg_' . bin2hex(random_bytes(6)) . '_' . time();
    header('Location: noble-education-request.php');
    exit;
}

$resumeId = trim((string) ($_GET['id'] ?? ''));
if ($resumeId !== '' && preg_match('/^neg_[a-zA-Z0-9_]+$/', $resumeId)) {
    $_SESSION['user_id'] = $resumeId;
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'neg_' . bin2hex(random_bytes(6)) . '_' . time();
}
$user_id = $_SESSION['user_id'];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/noble_education_schema.php';
neg_ensure_schema($conn);

$already = false;
$existing_ref = '';
$st = $conn->prepare('SELECT reference_id, status FROM noble_education_applications WHERE user_id = ? LIMIT 1');
if ($st) {
    $st->bind_param('s', $user_id);
    $st->execute();
    $existing = $st->get_result()->fetch_assoc();
    $st->close();
    if ($existing) {
        $already = true;
        $existing_ref = (string) $existing['reference_id'];
    }
}
$levels = neg_level_options();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Noble Education Group — Canada | Parrot Canada Visa Consultant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --neg-green:#0B3D2E; --neg-blue:#1B6CA8; --neg-bg:#f4f6f8; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family:"Segoe UI",system-ui,-apple-system,sans-serif; background:var(--neg-bg); color:#0f172a; margin:0; }
        .neg-wrap { width:100%; max-width:820px; margin:0 auto; padding:0.75rem 0.75rem 2.5rem; }
        .neg-hero { background:linear-gradient(135deg,var(--neg-green) 0%,var(--neg-blue) 100%); color:#fff; border-radius:14px; padding:clamp(1rem,4vw,1.75rem); margin-bottom:0.85rem; }
        .neg-hero h1 { font-size:clamp(1.1rem,4.6vw,1.65rem); font-weight:700; margin:0; line-height:1.3; }
        .neg-hero .sub { opacity:.92; margin-top:.5rem; font-size:clamp(.85rem,3.5vw,.95rem); }
        .neg-section { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:clamp(0.9rem,3vw,1.35rem); margin-bottom:0.85rem; box-shadow:0 2px 12px rgba(11,61,46,.06); }
        .neg-section h2 { font-size:clamp(0.98rem,3.8vw,1.08rem); font-weight:700; color:var(--neg-green); margin:0 0 0.9rem; padding-bottom:.65rem; border-bottom:2px solid #e2e8f0; }
        .neg-label { display:block; font-weight:600; font-size:0.92rem; margin-bottom:0.35rem; }
        .neg-label.required::after { content:" *"; color:#c0392b; }
        .form-control, .form-select { min-height:48px; font-size:16px; border-radius:10px; width:100%; }
        .row-2 { display:grid; grid-template-columns:1fr; gap:0.75rem; }
        @media (min-width:640px){ .row-2 { grid-template-columns:1fr 1fr; } }
        .upload-zone { border:2px dashed #cbd5e1; border-radius:10px; padding:1rem 0.75rem; text-align:center; cursor:pointer; background:#fafafa; min-height:96px; display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%; }
        .upload-zone:hover, .upload-zone.dragover { border-color:var(--neg-blue); background:#f0f9ff; }
        .file-chip { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.55rem .7rem; margin-top:.5rem; font-size:.86rem; width:100%; }
        .file-chip span { min-width:0; overflow-wrap:anywhere; flex:1; }
        .btn-neg { background:linear-gradient(135deg,var(--neg-green),var(--neg-blue)); border:0; color:#fff; font-weight:600; width:100%; padding:0.95rem 1.1rem; border-radius:10px; min-height:52px; }
        .btn-neg:disabled { opacity:.65; }
        .iti { width:100% !important; display:block; }
        .iti input.form-control { width:100% !important; padding-left:90px !important; }
        .hint { font-size:clamp(.78rem,3.2vw,.84rem); color:#64748b; }
        .done-box { background:#fff; border-radius:12px; border:1px solid #bbf7d0; padding:clamp(1rem,4vw,1.5rem); text-align:center; }
        .done-box .ref { font-family:ui-monospace,monospace; font-size:clamp(1rem,4.5vw,1.2rem); background:#f1f5f9; padding:.7rem .85rem; border-radius:8px; display:block; margin:.75rem 0; word-break:break-word; }
        #formError { text-align:left; padding:0.75rem 0.85rem; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; }
        @media (min-width:641px){ .submit-wrap { text-align:center; } .btn-neg { max-width:360px; width:auto; min-width:280px; } }
    </style>
</head>
<body>
<div class="neg-wrap">
    <div class="neg-hero">
        <h1>🇨🇦 Noble Education Group — Canada</h1>
        <p class="sub mb-0">Admissions application with passport, high school certificate, transcripts, and supporting documents.</p>
    </div>

    <?php if ($already): ?>
        <div class="done-box">
            <h2 class="h5 text-success mb-2"><i class="fas fa-check-circle"></i> Application already submitted</h2>
            <p class="mb-1">Your reference ID:</p>
            <div class="ref"><?= htmlspecialchars($existing_ref, ENT_QUOTES, 'UTF-8') ?></div>
            <a href="noble-education-request.php?new=1" class="btn btn-neg mt-3">Start new application</a>
            <a href="index.php" class="btn btn-outline-secondary mt-2 w-100">Back to home</a>
        </div>
    <?php else: ?>

    <div class="neg-section">
        <p class="mb-2" style="margin:0;line-height:1.45">Submit your details and clear copies of required documents for Noble Education Group — Canada admissions review.</p>
        <p class="hint mb-0">Fields marked with * are required.</p>
    </div>

    <form id="negForm" novalidate>
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="passport_file" id="passport_file" value="">
        <input type="hidden" name="high_school_certificate_file" id="high_school_certificate_file" value="">
        <input type="hidden" name="transcripts_file" id="transcripts_file" value="">
        <input type="hidden" name="other_docs_json" id="other_docs_json" value="[]">
        <input type="hidden" name="phone_area_code" id="phone_area_code" value="">
        <input type="hidden" name="phone_number" id="phone_number_hidden" value="">

        <div class="neg-section">
            <h2><span style="color:#c0392b">1.</span> Student details</h2>
            <div class="mb-3">
                <label class="neg-label required" for="full_name">Full name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="200" autocomplete="name" placeholder="As on passport">
            </div>
            <div class="row-2 mb-3">
                <div>
                    <label class="neg-label required" for="email">Email address</label>
                    <input type="email" class="form-control" id="email" name="email" required maxlength="150" autocomplete="email" placeholder="you@example.com">
                </div>
                <div>
                    <label class="neg-label required" for="phone_input">Mobile contact</label>
                    <input type="tel" class="form-control" id="phone_input" required autocomplete="tel">
                </div>
            </div>
            <div class="row-2 mb-3">
                <div>
                    <label class="neg-label required" for="level_of_study">Level of study</label>
                    <select class="form-select" id="level_of_study" name="level_of_study" required>
                        <option value="">Select level…</option>
                        <?php foreach ($levels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="neg-label required" for="program_of_interest">Program of interest</label>
                    <input type="text" class="form-control" id="program_of_interest" name="program_of_interest" required maxlength="255" placeholder="e.g. Business Administration">
                </div>
            </div>
            <div class="mb-0">
                <label class="neg-label required" for="agent_name">Agent name</label>
                <input type="text" class="form-control" id="agent_name" name="agent_name" required maxlength="200" placeholder="Referring agent full name">
            </div>
        </div>

        <div class="neg-section">
            <h2><span style="color:#c0392b">2.</span> Documents</h2>
            <p class="hint mb-3">PDF, JPG, PNG, WEBP, DOC, DOCX — max 15MB each.</p>

            <div class="mb-3">
                <label class="neg-label required">Passport scan</label>
                <div class="upload-zone" id="passportZone" tabindex="0" role="button">
                    <div id="passportZoneInner"><i class="fas fa-cloud-upload-alt mb-1"></i><div>Tap or drop passport scan</div></div>
                </div>
                <input type="file" id="passportInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>
                <div id="passportPreview"></div>
            </div>

            <div class="mb-3">
                <label class="neg-label required">High school certificate</label>
                <div class="upload-zone" id="hsZone" tabindex="0" role="button">
                    <div id="hsZoneInner"><i class="fas fa-cloud-upload-alt mb-1"></i><div>Tap or drop high school certificate</div></div>
                </div>
                <input type="file" id="hsInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>
                <div id="hsPreview"></div>
            </div>

            <div class="mb-3">
                <label class="neg-label required">Transcripts</label>
                <div class="upload-zone" id="transcriptsZone" tabindex="0" role="button">
                    <div id="transcriptsZoneInner"><i class="fas fa-cloud-upload-alt mb-1"></i><div>Tap or drop transcripts</div></div>
                </div>
                <input type="file" id="transcriptsInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>
                <div id="transcriptsPreview"></div>
            </div>

            <div class="mb-0">
                <label class="neg-label">Other relevant documents <span class="hint">(optional — you can add more than one)</span></label>
                <div class="upload-zone" id="otherZone" tabindex="0" role="button">
                    <div id="otherZoneInner"><i class="fas fa-cloud-upload-alt mb-1"></i><div>Tap or drop additional document</div></div>
                </div>
                <input type="file" id="otherInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>
                <div id="otherPreview"></div>
            </div>
        </div>

        <div id="formError" class="text-danger mb-3" style="display:none"></div>
        <div class="submit-wrap">
            <button type="submit" class="btn btn-neg" id="submitBtn"><i class="fas fa-paper-plane me-1"></i> Submit application</button>
        </div>
    </form>
    <div id="formSuccess" class="done-box mt-3" style="display:none"></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(() => {
    const form = document.getElementById('negForm');
    if (!form) return;

    const phoneInput = document.getElementById('phone_input');
    const iti = window.intlTelInput(phoneInput, {
        initialCountry: 'rw',
        preferredCountries: ['rw', 'ke', 'ug', 'bi', 'cd', 'tz', 'ca'],
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js'
    });

    let otherDocs = [];

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function setFileHidden(hiddenId, previewId, path, name) {
        document.getElementById(hiddenId).value = path || '';
        const box = document.getElementById(previewId);
        if (!path) { box.innerHTML = ''; return; }
        const clearId = 'clear_' + hiddenId;
        box.innerHTML = `<div class="file-chip"><span><i class="fas fa-file me-1"></i>${escapeHtml(name || 'File')}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" id="${clearId}">Remove</button></div>`;
        document.getElementById(clearId)?.addEventListener('click', () => setFileHidden(hiddenId, previewId, '', ''));
    }

    function renderOtherPreview() {
        const box = document.getElementById('otherPreview');
        document.getElementById('other_docs_json').value = JSON.stringify(otherDocs.map(d => d.path));
        if (!otherDocs.length) { box.innerHTML = ''; return; }
        box.innerHTML = otherDocs.map((d, i) =>
            `<div class="file-chip"><span><i class="fas fa-file me-1"></i>${escapeHtml(d.name)}</span>
             <button type="button" class="btn btn-sm btn-outline-danger" data-i="${i}">Remove</button></div>`
        ).join('');
        box.querySelectorAll('button[data-i]').forEach(btn => {
            btn.addEventListener('click', () => {
                otherDocs.splice(Number(btn.dataset.i), 1);
                renderOtherPreview();
            });
        });
    }

    function uploadFile(file, field, onProgress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('field', field);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'noble_education_upload.php');
            xhr.timeout = 120000;
            if (xhr.upload && typeof onProgress === 'function') {
                xhr.upload.onprogress = (ev) => {
                    if (ev.lengthComputable && ev.total > 0) onProgress(Math.round((ev.loaded / ev.total) * 100));
                };
            }
            xhr.onload = () => {
                let data;
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) { reject(new Error('Upload failed')); return; }
                if (!data.success) { reject(new Error(data.message || 'Upload failed')); return; }
                resolve(data);
            };
            xhr.ontimeout = () => reject(new Error('Upload timed out'));
            xhr.onerror = () => reject(new Error('Network error during upload'));
            xhr.send(fd);
        });
    }

    function wireZone(zoneId, inputId, field, hiddenId, previewId, multiOther) {
        const zone = document.getElementById(zoneId);
        const input = document.getElementById(inputId);
        const inner = document.getElementById(zoneId.replace('Zone', 'ZoneInner'));
        const openPicker = () => input.click();
        zone.addEventListener('click', openPicker);
        zone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault(); zone.classList.remove('dragover');
            if (e.dataTransfer.files?.length) handleFile(e.dataTransfer.files[0]);
        });
        input.addEventListener('change', () => {
            if (input.files?.length) handleFile(input.files[0]);
            input.value = '';
        });

        async function handleFile(file) {
            if (!file) return;
            if (file.size > 15 * 1024 * 1024) { alert('File too large (max 15MB)'); return; }
            const defaultHtml = inner.innerHTML;
            const label = escapeHtml(file.name);
            inner.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin"></i> Uploading ${label}… 0%</span>`;
            try {
                const res = await uploadFile(file, field, (pct) => {
                    inner.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin"></i> Uploading ${label}… ${pct}%</span>`;
                });
                inner.innerHTML = defaultHtml;
                if (multiOther) {
                    otherDocs.push({ path: res.file_path, name: res.original_name || file.name });
                    renderOtherPreview();
                } else {
                    setFileHidden(hiddenId, previewId, res.file_path, res.original_name || file.name);
                }
            } catch (err) {
                inner.innerHTML = `<span class="text-danger">${escapeHtml(err.message)}</span>`;
                setTimeout(() => { inner.innerHTML = defaultHtml; }, 3500);
            }
        }
    }

    wireZone('passportZone', 'passportInput', 'passport', 'passport_file', 'passportPreview', false);
    wireZone('hsZone', 'hsInput', 'high_school_certificate', 'high_school_certificate_file', 'hsPreview', false);
    wireZone('transcriptsZone', 'transcriptsInput', 'transcripts', 'transcripts_file', 'transcriptsPreview', false);
    wireZone('otherZone', 'otherInput', 'other', '', 'otherPreview', true);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errEl = document.getElementById('formError');
        const okEl = document.getElementById('formSuccess');
        const btn = document.getElementById('submitBtn');
        errEl.style.display = 'none';
        okEl.style.display = 'none';

        const email = String(document.getElementById('email').value || '').trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.textContent = 'Please enter a valid email address.';
            errEl.style.display = 'block';
            return;
        }
        if (!iti.isValidNumber()) {
            errEl.textContent = 'Please enter a valid mobile number with country code.';
            errEl.style.display = 'block';
            return;
        }
        const country = iti.getSelectedCountryData();
        document.getElementById('phone_area_code').value = country.dialCode || '';
        const full = iti.getNumber().replace(/\D/g, '');
        const dial = String(country.dialCode || '');
        let national = full;
        if (dial && full.startsWith(dial)) national = full.slice(dial.length);
        document.getElementById('phone_number_hidden').value = national;

        if (!document.getElementById('passport_file').value) { errEl.textContent = 'Please upload your passport scan.'; errEl.style.display = 'block'; return; }
        if (!document.getElementById('high_school_certificate_file').value) { errEl.textContent = 'Please upload your high school certificate.'; errEl.style.display = 'block'; return; }
        if (!document.getElementById('transcripts_file').value) { errEl.textContent = 'Please upload your transcripts.'; errEl.style.display = 'block'; return; }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Submitting…';
        const fd = new FormData(form);
        try {
            const r = await fetch('save_noble_education_request.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (!data.success) {
                let msg = data.message || 'Submission failed';
                if (Array.isArray(data.missing) && data.missing.length) msg += ': ' + data.missing.join(', ');
                errEl.textContent = msg;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Submit application';
                return;
            }
            form.style.display = 'none';
            document.querySelectorAll('.neg-section').forEach(el => { el.style.display = 'none'; });
            okEl.style.display = 'block';
            const ref = escapeHtml(data.reference_id || '');
            okEl.innerHTML = `
                <div class="text-center py-2">
                    <div class="mb-3" style="font-size:2.5rem;color:#15803d"><i class="fas fa-check-circle"></i></div>
                    <h2 class="h5 text-success mb-2">Application submitted successfully</h2>
                    <p class="mb-1 fw-semibold">Your reference ID</p>
                    <div class="ref">${ref}</div>
                    <a href="noble-education-request.php?new=1" class="btn btn-neg mt-3">Start new application</a>
                    <a href="index.php" class="btn btn-outline-secondary mt-2 w-100">Back to home</a>
                </div>`;
        } catch (err) {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Submit application';
        }
    });
})();
</script>
</body>
</html>

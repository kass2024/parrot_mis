<?php
declare(strict_types=1);

ob_start();
session_name('CUW_RECRUITMENT_FORM');
session_start();

if (isset($_GET['new']) && (string) $_GET['new'] === '1') {
    $_SESSION['user_id'] = 'cuw_' . bin2hex(random_bytes(6)) . '_' . time();
    header('Location: canada-workers-request.php');
    exit;
}

$resumeId = trim((string) ($_GET['id'] ?? ''));
if ($resumeId !== '' && preg_match('/^cuw_[a-zA-Z0-9_]+$/', $resumeId)) {
    $_SESSION['user_id'] = $resumeId;
}

if (!isset($_SESSION['user_id']) || !preg_match('/^cuw_[a-zA-Z0-9_]+$/', (string) $_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'cuw_' . bin2hex(random_bytes(6)) . '_' . time();
}
$user_id = (string) $_SESSION['user_id'];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/canada_workers_schema.php';
cuw_ensure_schema($conn);

$already = false;
$existing_ref = '';
$st = $conn->prepare('SELECT reference_id FROM canada_workers_applications WHERE user_id = ? LIMIT 1');
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

$positions = cuw_position_options();
$genders = cuw_gender_options();
$education = cuw_education_options();
$maxDob = (new DateTime('today'))->modify('-18 years')->format('Y-m-d');
$minDob = (new DateTime('today'))->modify('-80 years')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>CANADA UNSKILLED WORKERS RECRUITMENT | Parrot Canada Visa Consultant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --cuw-navy:#0B3A5B; --cuw-blue:#1B6CA8; --cuw-red:#b42318; --cuw-bg:#f3f6f8; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family:"Segoe UI",system-ui,-apple-system,sans-serif; background:var(--cuw-bg); color:#0f172a; margin:0; }
        .cuw-wrap { width:100%; max-width:860px; margin:0 auto; padding:0.85rem 0.85rem 2.5rem; }
        .cuw-hero { background:linear-gradient(145deg,var(--cuw-navy) 0%, var(--cuw-blue) 100%); color:#fff; border-radius:16px; padding:clamp(1.15rem,4vw,1.9rem); margin-bottom:0.9rem; }
        .cuw-hero h1 { font-size:clamp(1.2rem,4.6vw,1.75rem); font-weight:800; margin:0; line-height:1.3; letter-spacing:.02em; }
        .cuw-hero .lead-line { margin:0.65rem 0 0; font-weight:700; font-size:clamp(.92rem,3.4vw,1.05rem); line-height:1.4; }
        .cuw-hero .sub { opacity:.95; margin:0.7rem 0 0; font-size:clamp(.9rem,3.4vw,1rem); line-height:1.5; }
        .cuw-section { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:clamp(0.95rem,3vw,1.4rem); margin-bottom:0.9rem; box-shadow:0 2px 12px rgba(11,58,91,.05); }
        .cuw-section h2 { font-size:clamp(1rem,3.6vw,1.12rem); font-weight:750; color:var(--cuw-navy); margin:0 0 1rem; padding-bottom:.7rem; border-bottom:2px solid #e6eef4; display:flex; align-items:center; gap:.5rem; }
        .cuw-label { display:block; font-weight:650; font-size:0.92rem; margin-bottom:0.35rem; }
        .cuw-label.required::after { content:" *"; color:var(--cuw-red); font-weight:700; }
        .form-control, .form-select, textarea.form-control { min-height:48px; font-size:16px; border-radius:10px; width:100%; border-color:#d5dee7; }
        textarea.form-control { min-height:120px; }
        .row-2 { display:grid; grid-template-columns:1fr; gap:0.85rem; }
        @media (min-width:680px){ .row-2 { grid-template-columns:1fr 1fr; } }
        .upload-zone { border:2px dashed #cbd5e1; border-radius:12px; padding:1rem 0.75rem; text-align:center; cursor:pointer; background:#f8fafc; min-height:108px; display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%; }
        .upload-zone:hover, .upload-zone.dragover { border-color:var(--cuw-blue); background:#f0f7fc; }
        .upload-zone i { color:var(--cuw-blue); font-size:1.25rem; }
        .file-chip { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.55rem .7rem; margin-top:.5rem; font-size:.86rem; width:100%; }
        .file-chip span { min-width:0; overflow-wrap:anywhere; flex:1; }
        .hint { font-size:clamp(.78rem,3.2vw,.84rem); color:#64748b; margin:.25rem 0 .55rem; }
        .btn-cuw { background:linear-gradient(135deg,var(--cuw-navy),var(--cuw-blue)); border:0; color:#fff; font-weight:700; letter-spacing:.04em; width:100%; padding:1rem 1.1rem; border-radius:12px; min-height:54px; }
        .btn-cuw:disabled { opacity:.65; }
        .iti { width:100% !important; display:block; }
        .iti input.form-control { width:100% !important; padding-left:90px !important; }
        .done-box { background:#fff; border-radius:14px; border:1px solid #bbf7d0; padding:clamp(1.1rem,4vw,1.75rem); text-align:center; }
        .done-box .ref { font-family:ui-monospace,monospace; font-size:clamp(1rem,4.2vw,1.15rem); background:#f1f5f9; padding:.75rem .9rem; border-radius:8px; display:block; margin:.75rem 0; word-break:break-word; }
        #formError { text-align:left; padding:0.8rem 0.9rem; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; }
        .privacy { background:#f8fafc; border:1px solid #dbe4ee; border-left:4px solid var(--cuw-blue); border-radius:12px; padding:1rem 1rem .9rem; }
        .privacy h2 { border:0; margin-bottom:.4rem; padding:0; }
        .privacy p, .privacy li { font-size:.92rem; color:#334155; line-height:1.5; }
        .declare { display:flex; gap:.75rem; align-items:flex-start; background:#fff; border:1px solid #dbe4ee; border-radius:12px; padding:1rem; }
        .declare input { width:1.25rem; height:1.25rem; margin-top:.15rem; flex-shrink:0; accent-color:var(--cuw-navy); }
        .contact-card { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:1.1rem 1.15rem; }
        .contact-card a { color:var(--cuw-blue); text-decoration:none; font-weight:600; }
        .contact-card a:hover { text-decoration:underline; }
        .contact-line { display:flex; gap:.55rem; align-items:flex-start; margin:.35rem 0; }
        @media (min-width:681px){ .submit-wrap { text-align:center; } .btn-cuw { max-width:420px; } }
    </style>
</head>
<body>
<div class="cuw-wrap">
    <div class="cuw-hero">
        <h1>CANADA UNSKILLED WORKERS RECRUITMENT</h1>
        <p class="lead-line">EXCEPTIONAL JOB OPPORTUNITY IN CANADA FOR GENERAL WORKERS</p>
        <p class="sub">Complete the form below to submit your application for available general worker positions in Canada. Please provide accurate information and upload all required documents.</p>
    </div>

    <?php if ($already): ?>
        <div class="done-box mb-3">
            <div class="mb-2" style="font-size:2.4rem;color:#15803d"><i class="fas fa-check-circle"></i></div>
            <h2 class="h4 text-success">Application Submitted Successfully</h2>
            <p class="mb-2">Thank you for submitting your application. Your information will be reviewed, and you will be contacted regarding the next steps if appropriate.</p>
            <p class="mb-1 fw-semibold">Your reference ID</p>
            <div class="ref"><?= htmlspecialchars($existing_ref, ENT_QUOTES, 'UTF-8') ?></div>
            <p class="hint mb-2">Application user ID: <?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?></p>
            <a href="canada-workers-request.php?new=1" class="btn btn-cuw mt-2">Start a new application</a>
        </div>
    <?php else: ?>

    <form id="cuwForm" novalidate>
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="passport_file" id="passport_file" value="">
        <input type="hidden" name="cv_file" id="cv_file" value="">
        <input type="hidden" name="photo_file" id="photo_file" value="">
        <input type="hidden" name="phone_area_code" id="phone_area_code" value="">
        <input type="hidden" name="phone_number" id="phone_number_hidden" value="">

        <section class="cuw-section">
            <h2><i class="fas fa-user"></i> 1. Personal Information</h2>
            <div class="mb-3">
                <label class="cuw-label required" for="full_name">Full Name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="200" autocomplete="name" placeholder="As shown on your passport">
            </div>
            <div class="row-2 mb-3">
                <div>
                    <label class="cuw-label required" for="date_of_birth">Date of Birth</label>
                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required min="<?= htmlspecialchars($minDob) ?>" max="<?= htmlspecialchars($maxDob) ?>">
                </div>
                <div>
                    <label class="cuw-label required" for="gender">Gender</label>
                    <select class="form-select" id="gender" name="gender" required>
                        <option value="">Select gender…</option>
                        <?php foreach ($genders as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row-2 mb-3">
                <div>
                    <label class="cuw-label required" for="nationality">Nationality</label>
                    <input type="text" class="form-control" id="nationality" name="nationality" required maxlength="120" autocomplete="country-name" placeholder="e.g. Rwandan">
                </div>
                <div>
                    <label class="cuw-label required" for="country_of_residence">Country of Residence</label>
                    <input type="text" class="form-control" id="country_of_residence" name="country_of_residence" required maxlength="120" placeholder="Country where you live now">
                </div>
            </div>
            <div class="row-2 mb-3">
                <div>
                    <label class="cuw-label required" for="phone_input">Phone / WhatsApp Number</label>
                    <input type="tel" class="form-control" id="phone_input" required autocomplete="tel">
                </div>
                <div>
                    <label class="cuw-label required" for="email">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" required maxlength="150" autocomplete="email" placeholder="you@example.com">
                </div>
            </div>
            <div class="mb-0">
                <label class="cuw-label required" for="current_address">Current Address</label>
                <textarea class="form-control" id="current_address" name="current_address" required maxlength="1000" rows="3" placeholder="Street, city, and country"></textarea>
            </div>
        </section>

        <section class="cuw-section">
            <h2><i class="fas fa-briefcase"></i> 2. Position Applied For</h2>
            <label class="cuw-label required" for="position">Select the Position You Are Applying For</label>
            <select class="form-select" id="position" name="position" required>
                <option value="">Select the Position You Are Applying For</option>
                <?php foreach ($positions as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(cuw_position_choice_label($key), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="cuw-section">
            <h2><i class="fas fa-clipboard-list"></i> 3. Work Information</h2>
            <div class="row-2 mb-3">
                <div>
                    <label class="cuw-label required" for="current_occupation">Current Occupation</label>
                    <input type="text" class="form-control" id="current_occupation" name="current_occupation" required maxlength="200" placeholder="e.g. Farm assistant">
                </div>
                <div>
                    <label class="cuw-label required" for="years_experience">Years of Work Experience</label>
                    <input type="number" class="form-control" id="years_experience" name="years_experience" required min="0" max="60" step="0.5" inputmode="decimal" placeholder="e.g. 2">
                </div>
            </div>
            <div class="mb-3">
                <label class="cuw-label required" for="education_level">Highest Level of Education</label>
                <select class="form-select" id="education_level" name="education_level" required>
                    <option value="">Select education level…</option>
                    <?php foreach ($education as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-0">
                <label class="cuw-label required" for="work_experience_description">Brief Description of Previous Work Experience</label>
                <textarea class="form-control" id="work_experience_description" name="work_experience_description" required maxlength="4000" rows="4" placeholder="Describe the work you have done, including duties and where you worked."></textarea>
            </div>
        </section>

        <section class="cuw-section">
            <h2><i class="fas fa-folder-open"></i> 4. Required Documents</h2>
            <p class="hint">Fields marked with * are required. Upload clear, readable copies.</p>

            <div class="mb-3">
                <label class="cuw-label required">Passport Data Page</label>
                <p class="hint">Upload a clear copy of the required passport page(s).</p>
                <div class="upload-zone" id="passportZone" tabindex="0" role="button" aria-label="Upload passport data page">
                    <div id="passportZoneInner"><i class="fas fa-passport mb-1"></i><div>Tap to upload passport data page</div><div class="hint mb-0">PDF, JPG, JPEG, or PNG · max 10MB</div></div>
                </div>
                <input type="file" id="passportInput" accept=".pdf,.jpg,.jpeg,.png,image/jpeg,image/png,application/pdf" hidden>
                <div id="passportPreview"></div>
            </div>

            <div class="mb-3">
                <label class="cuw-label required">Updated Résumé / CV</label>
                <p class="hint">Accepted formats: PDF, DOC, or DOCX.</p>
                <div class="upload-zone" id="cvZone" tabindex="0" role="button" aria-label="Upload résumé or CV">
                    <div id="cvZoneInner"><i class="fas fa-file-alt mb-1"></i><div>Tap to upload résumé / CV</div><div class="hint mb-0">PDF, DOC, or DOCX · max 10MB</div></div>
                </div>
                <input type="file" id="cvInput" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden>
                <div id="cvPreview"></div>
            </div>

            <div class="mb-0">
                <label class="cuw-label required">Recent Passport-Sized Photograph</label>
                <p class="hint">Accepted formats: JPG, JPEG, or PNG.</p>
                <div class="upload-zone" id="photoZone" tabindex="0" role="button" aria-label="Upload passport photograph">
                    <div id="photoZoneInner"><i class="fas fa-camera mb-1"></i><div>Tap to upload photograph</div><div class="hint mb-0">JPG, JPEG, or PNG · max 5MB</div></div>
                </div>
                <input type="file" id="photoInput" accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden>
                <div id="photoPreview"></div>
            </div>
        </section>

        <section class="cuw-section privacy">
            <h2><i class="fas fa-lock"></i> Privacy Notice</h2>
            <p>Parrot Canada Visa Consultant Co. Ltd. collects the personal information and documents you submit on this form so we can review your application for general and entry-level work in Canada, contact you about next steps, and keep a record of your application.</p>
            <p class="mb-1"><strong>What we collect.</strong> Your name, date of birth, gender, nationality, country of residence, phone or WhatsApp number, email, address, the name of who referred you, work and education details, passport data page, résumé or CV, and passport-sized photograph.</p>
            <p class="mb-0"><strong>How we use it.</strong> Only for recruitment review, communication about this application, and related record-keeping. We do not sell your information.</p>
        </section>

        <section class="cuw-section">
            <h2><i class="fas fa-user-friends"></i> 5. How did you know us</h2>
            <label class="cuw-label required" for="how_did_you_know_us">How did you know us</label>
            <input type="text" class="form-control" id="how_did_you_know_us" name="how_did_you_know_us" required maxlength="200" placeholder="type the name of who referenced you">
        </section>

        <section class="cuw-section">
            <h2><i class="fas fa-pen-to-square"></i> 6. Applicant Declaration</h2>
            <label class="declare" for="declaration">
                <input type="checkbox" id="declaration" name="declaration" value="1" required>
                <span><strong>I confirm that the information and documents submitted in this application are accurate and belong to me.</strong></span>
            </label>
        </section>

        <div id="formError" class="text-danger mb-3" style="display:none" role="alert"></div>
        <div class="submit-wrap mb-3">
            <button type="submit" class="btn btn-cuw" id="submitBtn">SUBMIT APPLICATION</button>
        </div>
    </form>
    <div id="formSuccess" class="done-box mb-3" style="display:none"></div>
    <?php endif; ?>

    <div class="contact-card" id="contactCard">
        <h2 class="h6 mb-2" style="color:var(--cuw-navy)">Contact Information</h2>
        <p class="mb-2 fw-semibold">Parrot Canada Visa Consultant Co. Ltd.</p>
        <div class="contact-line"><span>📧</span> <a href="mailto:jobs@visaconsultantcanada.ca">jobs@visaconsultantcanada.ca</a></div>
        <div class="contact-line"><span>📱</span> <a href="https://wa.me/14313020226" target="_blank" rel="noopener">WhatsApp: +1 (431) 302-0226</a></div>
        <div class="contact-line"><span>🌐</span> <a href="https://www.visaconsultantcanada.com" target="_blank" rel="noopener">www.visaconsultantcanada.com</a></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(() => {
    const form = document.getElementById('cuwForm');
    if (!form) return;

    const phoneInput = document.getElementById('phone_input');
    const iti = window.intlTelInput(phoneInput, {
        initialCountry: 'rw',
        preferredCountries: ['rw', 'ke', 'ug', 'ca', 'cd', 'bi', 'tz'],
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js'
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function showError(msg) {
        const errEl = document.getElementById('formError');
        errEl.textContent = msg;
        errEl.style.display = 'block';
        errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setFileHidden(hiddenId, previewId, path, name) {
        document.getElementById(hiddenId).value = path || '';
        const box = document.getElementById(previewId);
        if (!path) { box.innerHTML = ''; return; }
        const clearId = 'clear_' + hiddenId;
        box.innerHTML = `<div class="file-chip"><span><i class="fas fa-check-circle text-success me-1"></i>${escapeHtml(name || 'File uploaded')}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" id="${clearId}">Remove</button></div>`;
        document.getElementById(clearId)?.addEventListener('click', () => setFileHidden(hiddenId, previewId, '', ''));
    }

    function uploadFile(file, field, onProgress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('field', field);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'canada_workers_upload.php');
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
            xhr.ontimeout = () => reject(new Error('Upload timed out. Please try again.'));
            xhr.onerror = () => reject(new Error('Network error during upload'));
            xhr.send(fd);
        });
    }

    function wireZone(zoneId, inputId, field, hiddenId, previewId, maxBytes) {
        const zone = document.getElementById(zoneId);
        const input = document.getElementById(inputId);
        const inner = document.getElementById(zoneId + 'Inner');
        const openPicker = () => input.click();
        zone.addEventListener('click', openPicker);
        zone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('dragover');
            if (e.dataTransfer.files?.length) handleFile(e.dataTransfer.files[0]);
        });
        input.addEventListener('change', () => {
            if (input.files?.length) handleFile(input.files[0]);
            input.value = '';
        });

        async function handleFile(file) {
            if (!file) return;
            if (file.size > maxBytes) {
                alert('File too large (max ' + Math.round(maxBytes / (1024 * 1024)) + 'MB)');
                return;
            }
            const defaultHtml = inner.innerHTML;
            const label = escapeHtml(file.name);
            inner.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin"></i> Uploading ${label}… 0%</span>`;
            try {
                const res = await uploadFile(file, field, (pct) => {
                    inner.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin"></i> Uploading ${label}… ${pct}%</span>`;
                });
                inner.innerHTML = defaultHtml;
                setFileHidden(hiddenId, previewId, res.file_path, res.original_name || file.name);
            } catch (err) {
                inner.innerHTML = `<span class="text-danger">${escapeHtml(err.message)}</span>`;
                setTimeout(() => { inner.innerHTML = defaultHtml; }, 4000);
            }
        }
    }

    wireZone('passportZone', 'passportInput', 'passport', 'passport_file', 'passportPreview', 10 * 1024 * 1024);
    wireZone('cvZone', 'cvInput', 'cv', 'cv_file', 'cvPreview', 10 * 1024 * 1024);
    wireZone('photoZone', 'photoInput', 'photo', 'photo_file', 'photoPreview', 5 * 1024 * 1024);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errEl = document.getElementById('formError');
        const okEl = document.getElementById('formSuccess');
        const btn = document.getElementById('submitBtn');
        errEl.style.display = 'none';
        okEl.style.display = 'none';

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        const email = String(document.getElementById('email').value || '').trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('Please enter a valid email address.');
            return;
        }
        if (!iti.isValidNumber()) {
            showError('Please enter a valid phone / WhatsApp number with country code.');
            return;
        }
        const country = iti.getSelectedCountryData();
        document.getElementById('phone_area_code').value = country.dialCode || '';
        const full = iti.getNumber().replace(/\D/g, '');
        const dial = String(country.dialCode || '');
        let national = full;
        if (dial && full.startsWith(dial)) national = full.slice(dial.length);
        document.getElementById('phone_number_hidden').value = national;

        if (!document.getElementById('passport_file').value) { showError('Please upload your passport data page.'); return; }
        if (!document.getElementById('cv_file').value) { showError('Please upload your updated résumé / CV.'); return; }
        if (!document.getElementById('photo_file').value) { showError('Please upload a recent passport-sized photograph.'); return; }
        const referral = String(document.getElementById('how_did_you_know_us').value || '').trim();
        if (!referral) { showError('Please enter how you knew us (the name of who referred you).'); return; }
        document.getElementById('how_did_you_know_us').value = referral;
        if (!document.getElementById('declaration').checked) { showError('Please confirm the applicant declaration before submitting.'); return; }

        btn.disabled = true;
        btn.textContent = 'SUBMITTING…';
        try {
            const r = await fetch('save_canada_workers_request.php', { method: 'POST', body: new FormData(form) });
            const data = await r.json();
            if (!data.success) {
                let msg = data.message || 'Submission failed';
                if (Array.isArray(data.missing) && data.missing.length) msg += ': ' + data.missing.join(', ');
                showError(msg);
                btn.disabled = false;
                btn.textContent = 'SUBMIT APPLICATION';
                return;
            }
            form.style.display = 'none';
            okEl.style.display = 'block';
            const ref = escapeHtml(data.reference_id || '');
            const uid = escapeHtml(data.user_id || '');
            okEl.innerHTML = `
                <div class="mb-2" style="font-size:2.6rem;color:#15803d"><i class="fas fa-check-circle"></i></div>
                <h2 class="h4 text-success">Application Submitted Successfully</h2>
                <p>Thank you for submitting your application. Your information will be reviewed, and you will be contacted regarding the next steps if appropriate.</p>
                <p class="mb-1 fw-semibold">Your reference ID</p>
                <div class="ref">${ref}</div>
                <p class="hint">Application user ID (for Retrieve Application): ${uid}</p>
                <a href="canada-workers-request.php?new=1" class="btn btn-cuw mt-2">Start a new application</a>`;
            okEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
            showError('Network error. Please try again.');
            btn.disabled = false;
            btn.textContent = 'SUBMIT APPLICATION';
        }
    });
})();
</script>
</body>
</html>

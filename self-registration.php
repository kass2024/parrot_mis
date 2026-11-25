<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/self_registration.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Service temporarily unavailable.');
}

pcvc_self_reg_ensure_schema($conn);

$token = trim((string) ($_GET['token'] ?? ''));
$isManualAdmin = isset($_GET['manual']) && (string) $_GET['manual'] === '1'
    && (!empty($_SESSION['admin_id']) || !empty($_SESSION['id']));
$refAdminId = (int) ($_GET['ref'] ?? 0);
$refLabel = '';
if ($refAdminId > 0) {
    $refLabel = pcvc_self_reg_admin_label($conn, $refAdminId);
    if ($refLabel === '') {
        $refAdminId = 0;
    }
}
// Manual add by a logged-in account: attribute to that account when no ?ref= given.
if ($isManualAdmin && $refAdminId <= 0) {
    $refAdminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
    if ($refAdminId > 0) {
        $refLabel = pcvc_self_reg_admin_label($conn, $refAdminId);
        if ($refLabel === '') {
            $refAdminId = 0;
        }
    }
}
$reg = null;

if ($token !== '') {
    $reg = pcvc_self_reg_find_by_token($conn, $token);
    if (!$reg) {
        http_response_code(404);
        exit('This self-registration link is invalid or has expired. Please contact Parrot Canada Visa Consultant.');
    }
    if ($refAdminId > 0 && (($reg['status'] ?? '') === 'draft')) {
        pcvc_self_reg_apply_referral($conn, (int) $reg['id'], $refAdminId, $refLabel);
        $reg = pcvc_self_reg_find_by_token($conn, $token) ?: $reg;
    }
} else {
    // Fixed / referral public link (no token): one draft per browser session until submitted.
    // Admin "Add manually" always starts a fresh draft.
    if (!$isManualAdmin) {
        $sessionToken = trim((string) ($_SESSION['self_reg_draft_token'] ?? ''));
        if ($sessionToken !== '') {
            $reg = pcvc_self_reg_find_by_token($conn, $sessionToken);
            if ($reg && (($reg['status'] ?? '') === 'submitted')) {
                $reg = null;
                unset($_SESSION['self_reg_draft_token']);
            }
        }
    }
    if ($reg && $refAdminId > 0 && (($reg['status'] ?? '') === 'draft')) {
        pcvc_self_reg_apply_referral($conn, (int) $reg['id'], $refAdminId, $refLabel);
        $reg = pcvc_self_reg_find_by_token($conn, (string) $reg['token']) ?: $reg;
    }
    if (!$reg) {
        $adminId = $isManualAdmin
            ? (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0)
            : null;
        $source = $isManualAdmin ? 'manual' : 'link';
        $created = pcvc_self_reg_issue_link(
            $conn,
            $adminId > 0 ? $adminId : null,
            $source,
            $refAdminId > 0 ? $refAdminId : null,
            $refLabel
        );
        if (!$isManualAdmin) {
            $_SESSION['self_reg_draft_token'] = $created['token'];
        }
        $reg = pcvc_self_reg_find_by_token($conn, $created['token']);
    }
    if (!$reg) {
        http_response_code(500);
        exit('Could not start self-registration form. Please try again.');
    }
    $token = (string) $reg['token'];
}

$referrerLocked = ((int) ($reg['referred_by_admin_id'] ?? 0) > 0)
    && trim((string) ($reg['referring_agent'] ?? '')) !== '';

$docs = pcvc_self_reg_documents_map($conn, (int) $reg['id']);
$isSubmitted = (($reg['status'] ?? '') === 'submitted');
$levels = pcvc_self_reg_study_levels();
$fields = pcvc_self_reg_fields_of_study();
$eduLevels = pcvc_self_reg_education_levels();
$languages = pcvc_self_reg_languages();
$sponsors = pcvc_self_reg_sponsors();
$docSlots = pcvc_self_reg_document_slots();
$intakes = xander_visa_intake_options_by_country();
$countries = xander_visa_destination_countries();
$featured = array_values(array_filter(
    xander_visa_featured_destination_countries(),
    static fn(string $n): bool => in_array($n, $countries, true)
));

function sr_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function sr_val(array $reg, string $key): string
{
    return sr_h((string) ($reg[$key] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Self Registration | Parrot Canada Visa Consultant</title>
<style>
:root {
  --green: #15803d;
  --green-deep: #14532d;
  --sky: #0284c7;
  --ink: #0f172a;
  --muted: #64748b;
  --line: #d7e7dc;
  --card: #ffffff;
  --danger: #b91c1c;
  --radius: 16px;
  --shadow: 0 16px 40px rgba(20, 83, 45, .10);
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: "Segoe UI", ui-sans-serif, system-ui, sans-serif;
  color: var(--ink);
  background:
    radial-gradient(1000px 320px at 0% -5%, rgba(14,165,233,.18), transparent 55%),
    radial-gradient(900px 280px at 100% 0%, rgba(34,197,94,.16), transparent 50%),
    linear-gradient(180deg, #eef8f2 0%, #f8fafc 45%, #ffffff 100%);
  min-height: 100vh;
}
.sr-shell { max-width: 860px; margin: 0 auto; padding: 18px 14px 48px; }
.sr-hero {
  border-radius: 22px;
  padding: 22px 20px;
  color: #fff;
  background: linear-gradient(135deg, #14532d 0%, #15803d 48%, #0284c7 120%);
  box-shadow: var(--shadow);
  margin-bottom: 16px;
}
.sr-hero h1 { margin: 0 0 6px; font-size: clamp(1.25rem, 4vw, 1.7rem); line-height: 1.25; }
.sr-hero p { margin: 0; opacity: .92; font-size: .95rem; }
.sr-status {
  margin-top: 12px; display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
  padding: 8px 12px; border-radius: 999px; font-size: .85rem; font-weight: 700;
}
.sr-autosave {
  position: sticky; top: 8px; z-index: 30; margin-bottom: 12px;
  display: flex; justify-content: flex-end;
}
.sr-autosave span {
  background: #14532d; color: #fff; border-radius: 999px; padding: 8px 12px;
  font-size: .82rem; font-weight: 750; box-shadow: 0 8px 18px rgba(20,83,45,.25);
  opacity: 0; transform: translateY(-6px); transition: .2s ease;
}
.sr-autosave span.show { opacity: 1; transform: none; }
.sr-autosave span.err { background: var(--danger); }
.sr-card {
  background: var(--card); border: 1px solid var(--line); border-radius: var(--radius);
  padding: 18px 16px; margin-bottom: 14px; box-shadow: 0 8px 24px rgba(15,23,42,.04);
}
.sr-card h2 {
  margin: 0 0 14px; font-size: 1.05rem; color: var(--green-deep);
  display: flex; align-items: center; gap: 8px;
}
.sr-card h2 .n {
  width: 26px; height: 26px; border-radius: 999px; background: var(--green-deep);
  color: #fff; display: inline-flex; align-items: center; justify-content: center;
  font-size: .78rem; font-weight: 800;
}
.sr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sr-field { display: flex; flex-direction: column; gap: 6px; }
.sr-field.full { grid-column: 1 / -1; }
.sr-field label { font-size: .86rem; font-weight: 750; color: #1e293b; }
.sr-field .hint { font-size: .78rem; color: var(--muted); font-weight: 500; }
.sr-field input, .sr-field select, .sr-field textarea {
  width: 100%; border: 2px solid #dbe7df; border-radius: 12px; padding: 12px 12px;
  font-size: 16px; background: #fff; color: var(--ink); outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.sr-field input:focus, .sr-field select:focus, .sr-field textarea:focus {
  border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18);
}
.sr-field select { appearance: none; background-image: linear-gradient(45deg, transparent 50%, #64748b 50%), linear-gradient(135deg, #64748b 50%, transparent 50%); background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px); background-size: 6px 6px, 6px 6px; background-repeat: no-repeat; padding-right: 34px; }
.smart-country { position: relative; }
.smart-country .sc-trigger {
  width: 100%; text-align: left; border: 2px solid #dbe7df; border-radius: 12px;
  padding: 12px 40px 12px 12px; font-size: 16px; background: #fff; cursor: pointer;
  position: relative;
}
.smart-country .sc-trigger::after {
  content: "▾"; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #64748b;
}
.smart-country .sc-trigger[data-empty="1"] { color: #94a3b8; }
.smart-country .sc-panel {
  display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 40;
  background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 16px 36px rgba(15,23,42,.16);
  overflow: hidden;
}
.smart-country.open .sc-panel { display: block; }
.smart-country .sc-search { width: 100%; border: 0; border-bottom: 1px solid #e2e8f0; padding: 12px; font-size: 16px; outline: none; }
.smart-country .sc-list { max-height: 220px; overflow: auto; }
.smart-country .sc-group { padding: 8px 12px 4px; font-size: .72rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
.smart-country .sc-list button {
  display: block; width: 100%; text-align: left; border: 0; background: transparent;
  padding: 10px 12px; font-size: .95rem; cursor: pointer;
}
.smart-country .sc-list button:hover, .smart-country .sc-list button[aria-selected="true"] { background: #ecfdf5; color: #14532d; }
.smart-country .sc-empty { padding: 12px; color: #64748b; font-size: .9rem; }
.doc-list { display: grid; gap: 12px; }
.doc-item {
  border: 1px solid #dbe7df; border-radius: 14px; padding: 12px; background: linear-gradient(180deg, #f8fbf9, #fff);
}
.doc-item.required { border-color: #fdba74; background: linear-gradient(180deg, #fff7ed, #fff); }
.doc-item.saved { border-color: #86efac; }
.doc-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; margin-bottom: 8px; }
.doc-top strong { font-size: .92rem; }
.doc-top .tag { font-size: .72rem; font-weight: 800; color: #9a3412; background: #ffedd5; padding: 3px 8px; border-radius: 999px; }
.doc-item.saved .tag { color: #14532d; background: #dcfce7; }
.doc-item input[type=file] { width: 100%; font-size: .9rem; }
.sr-progress { margin-top: 8px; display: none; }
.sr-progress.show { display: block; }
.sr-progress-track { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
.sr-progress-bar { height: 100%; width: 0; background: #2563eb; transition: width .15s linear; }
.sr-progress.done .sr-progress-bar { background: #16a34a; }
.sr-progress.fail .sr-progress-bar { background: #dc2626; }
.sr-progress-label { margin-top: 4px; font-size: .8rem; color: var(--muted); font-weight: 650; }
.sr-progress.done .sr-progress-label { color: #15803d; }
.doc-file { margin-top: 6px; font-size: .82rem; color: #14532d; font-weight: 650; word-break: break-all; }
.sr-actions { display: grid; gap: 10px; margin-top: 8px; }
.sr-btn {
  border: 0; border-radius: 14px; padding: 14px 16px; font-size: 1rem; font-weight: 800; cursor: pointer;
}
.sr-btn.primary { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; }
.sr-btn:disabled { opacity: .6; cursor: not-allowed; }
.sr-locked {
  background: #ecfdf5; border: 1px solid #86efac; color: #14532d; border-radius: 14px;
  padding: 14px; font-weight: 700; margin-bottom: 14px;
}
.sr-check { display: flex; gap: 10px; align-items: flex-start; font-weight: 650; }
.sr-check input { width: 18px; height: 18px; margin-top: 2px; accent-color: #15803d; }
.note { font-size: .85rem; color: var(--muted); margin: 0 0 10px; }
@media (max-width: 700px) {
  .sr-grid { grid-template-columns: 1fr; }
  .sr-shell { padding: 12px 10px 36px; }
  .sr-hero { padding: 18px 16px; border-radius: 18px; }
}
</style>
</head>
<body>
<div class="sr-shell">
  <header class="sr-hero">
    <h1>Study in Canada — New Student Application</h1>
    <p>Parrot Canada Visa Consultant Co. Ltd · Complete this form after payment. Your answers auto-save.</p>
    <div class="sr-status"><?= $isSubmitted ? 'Submitted' : 'Draft · private link' ?></div>
  </header>

  <?php if ($isSubmitted): ?>
  <div class="sr-locked">Thank you — your application was submitted<?= !empty($reg['submitted_at']) ? ' on ' . sr_h((string) $reg['submitted_at']) : '' ?>. This form is now locked.</div>
  <?php endif; ?>

  <div class="sr-autosave"><span id="saveToast">Saved</span></div>

  <form id="srForm" novalidate <?= $isSubmitted ? 'data-locked="1"' : '' ?>>
    <input type="hidden" name="token" id="srToken" value="<?= sr_h($token) ?>">

    <section class="sr-card">
      <h2><span class="n">1</span> Personal Information</h2>
      <div class="sr-grid">
        <div class="sr-field full">
          <label for="full_name">Full name</label>
          <input id="full_name" name="full_name" value="<?= sr_val($reg, 'full_name') ?>" autocomplete="name" <?= $isSubmitted ? 'readonly' : '' ?> required>
        </div>
        <div class="sr-field">
          <label for="date_of_birth">Date of birth</label>
          <input type="date" id="date_of_birth" name="date_of_birth" value="<?= sr_val($reg, 'date_of_birth') ?>" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
        <div class="sr-field">
          <label for="whatsapp">WhatsApp number</label>
          <input id="whatsapp" name="whatsapp" value="<?= sr_val($reg, 'whatsapp') ?>" inputmode="tel" placeholder="+250…" <?= $isSubmitted ? 'readonly' : '' ?> required>
        </div>
        <div class="sr-field full">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" value="<?= sr_val($reg, 'email') ?>" autocomplete="email" <?= $isSubmitted ? 'readonly' : '' ?> required>
        </div>
        <div class="sr-field">
          <label>Nationality</label>
          <div class="smart-country" data-name="nationality" data-value="<?= sr_val($reg, 'nationality') ?>"></div>
        </div>
        <div class="sr-field">
          <label>Country of residence</label>
          <div class="smart-country" data-name="country_of_residence" data-value="<?= sr_val($reg, 'country_of_residence') ?>"></div>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">2</span> Preferred Destination &amp; Intake</h2>
      <div class="sr-grid">
        <div class="sr-field">
          <label>Preferred country of destination</label>
          <div class="smart-country" data-name="destination_country" data-value="<?= sr_val($reg, 'destination_country') ?>" data-featured="1"></div>
        </div>
        <div class="sr-field">
          <label for="preferred_intake">Preferred intake</label>
          <select id="preferred_intake" name="preferred_intake" <?= $isSubmitted ? 'disabled' : '' ?> required>
            <option value="">Select intake…</option>
            <?php foreach ($intakes as $group => $labels): ?>
            <optgroup label="<?= sr_h($group) ?>">
              <?php foreach ($labels as $label): ?>
              <option value="<?= sr_h($label) ?>" <?= ((string) ($reg['preferred_intake'] ?? '') === $label) ? 'selected' : '' ?>><?= sr_h($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">3</span> Preferred Level of Study</h2>
      <div class="sr-field">
        <label for="preferred_level">Select your preferred level of study</label>
        <select id="preferred_level" name="preferred_level" <?= $isSubmitted ? 'disabled' : '' ?> required>
          <option value="">Select level…</option>
          <?php foreach ($levels as $lvl): ?>
          <option value="<?= sr_h($lvl) ?>" <?= ((string) ($reg['preferred_level'] ?? '') === $lvl) ? 'selected' : '' ?>><?= sr_h($lvl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">4</span> Preferred Fields of Study</h2>
      <p class="note">Select up to five preferred majors in order of priority. Primary/secondary applicants may leave these blank and note grade in previous field of study.</p>
      <div class="sr-grid">
        <?php
        $choiceLabels = ['First Choice', 'Second Choice', 'Third Choice', 'Fourth Choice', 'Fifth Choice'];
        for ($i = 1; $i <= 5; $i++):
          $key = 'field_choice_' . $i;
        ?>
        <div class="sr-field full">
          <label for="<?= $key ?>"><?= $choiceLabels[$i - 1] ?></label>
          <select id="<?= $key ?>" name="<?= $key ?>" class="field-choice" <?= $isSubmitted ? 'disabled' : '' ?>>
            <option value="">Select major…</option>
            <?php foreach ($fields as $f): ?>
            <option value="<?= sr_h($f) ?>" <?= ((string) ($reg[$key] ?? '') === $f) ? 'selected' : '' ?>><?= sr_h($f) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endfor; ?>
        <div class="sr-field full" id="otherFieldWrap" hidden>
          <label for="other_field_specify">Other – please specify</label>
          <input id="other_field_specify" name="other_field_specify" value="<?= sr_val($reg, 'other_field_specify') ?>" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">5</span> Academic Information</h2>
      <div class="sr-grid">
        <div class="sr-field">
          <label for="highest_education">Highest level of education completed</label>
          <select id="highest_education" name="highest_education" <?= $isSubmitted ? 'disabled' : '' ?>>
            <option value="">Select…</option>
            <?php foreach ($eduLevels as $e): ?>
            <option value="<?= sr_h($e) ?>" <?= ((string) ($reg['highest_education'] ?? '') === $e) ? 'selected' : '' ?>><?= sr_h($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sr-field">
          <label for="previous_field">Previous field of study / grade</label>
          <input id="previous_field" name="previous_field" value="<?= sr_val($reg, 'previous_field') ?>" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
        <div class="sr-field">
          <label for="graduation_year">Graduation year</label>
          <input id="graduation_year" name="graduation_year" value="<?= sr_val($reg, 'graduation_year') ?>" inputmode="numeric" placeholder="2024" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
        <div class="sr-field">
          <label for="final_grade">Final grade / Percentage</label>
          <input id="final_grade" name="final_grade" value="<?= sr_val($reg, 'final_grade') ?>" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
        <div class="sr-field full">
          <label for="preferred_language">Preferred language of study</label>
          <select id="preferred_language" name="preferred_language" <?= $isSubmitted ? 'disabled' : '' ?>>
            <option value="">Select…</option>
            <?php foreach ($languages as $lang): ?>
            <option value="<?= sr_h($lang) ?>" <?= ((string) ($reg['preferred_language'] ?? '') === $lang) ? 'selected' : '' ?>><?= sr_h($lang) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">6</span> Study Preferences</h2>
      <div class="sr-grid">
        <div class="sr-field">
          <label for="preferred_institution">Preferred institution (if any)</label>
          <input id="preferred_institution" name="preferred_institution" value="<?= sr_val($reg, 'preferred_institution') ?>" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
        <div class="sr-field">
          <label for="preferred_province">Preferred province (if any)</label>
          <input id="preferred_province" name="preferred_province" value="<?= sr_val($reg, 'preferred_province') ?>" placeholder="Ontario, Quebec…" <?= $isSubmitted ? 'readonly' : '' ?>>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">7</span> Financial Information</h2>
      <div class="sr-grid">
        <div class="sr-field">
          <label for="sponsor">Who will sponsor your studies?</label>
          <select id="sponsor" name="sponsor" <?= $isSubmitted ? 'disabled' : '' ?> required>
            <option value="">Select…</option>
            <?php foreach ($sponsors as $s): ?>
            <option value="<?= sr_h($s) ?>" <?= ((string) ($reg['sponsor'] ?? '') === $s) ? 'selected' : '' ?>><?= sr_h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sr-field">
          <label for="ready_to_pay">Ready to pay tuition after admission?</label>
          <select id="ready_to_pay" name="ready_to_pay" <?= $isSubmitted ? 'disabled' : '' ?> required>
            <option value="">Select…</option>
            <option value="Yes" <?= ((string) ($reg['ready_to_pay'] ?? '') === 'Yes') ? 'selected' : '' ?>>Yes</option>
            <option value="No" <?= ((string) ($reg['ready_to_pay'] ?? '') === 'No') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">8</span> Required Documents</h2>
      <p class="note">Files upload automatically when you choose them. <strong>Payment proof is mandatory</strong>.</p>
      <div class="doc-list">
        <?php foreach ($docSlots as $key => $slot):
          $has = isset($docs[$key]);
          $fname = $has ? (string) ($docs[$key]['original_name'] ?? '') : '';
        ?>
        <div class="doc-item <?= !empty($slot['required']) ? 'required' : '' ?> <?= $has ? 'saved' : '' ?>" data-doc="<?= sr_h($key) ?>">
          <div class="doc-top">
            <strong><?= sr_h($slot['label']) ?></strong>
            <span class="tag"><?= $has ? 'Saved' : (!empty($slot['required']) ? 'Required' : 'Optional') ?></span>
          </div>
          <?php if (!$isSubmitted): ?>
          <input type="file" class="sr-upload" data-doc-key="<?= sr_h($key) ?>" accept="<?= sr_h($slot['accept']) ?>">
          <?php endif; ?>
          <div class="sr-progress" id="prog_<?= sr_h($key) ?>">
            <div class="sr-progress-track"><div class="sr-progress-bar" id="bar_<?= sr_h($key) ?>"></div></div>
            <div class="sr-progress-label" id="lab_<?= sr_h($key) ?>">Uploading…</div>
          </div>
          <div class="doc-file" id="file_<?= sr_h($key) ?>"><?= $fname !== '' ? sr_h($fname) : '' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">9</span> Additional Information</h2>
      <div class="sr-grid">
        <div class="sr-field">
          <label for="visa_refused">Have you previously been refused a visa?</label>
          <select id="visa_refused" name="visa_refused" <?= $isSubmitted ? 'disabled' : '' ?> required>
            <option value="">Select…</option>
            <option value="Yes" <?= ((string) ($reg['visa_refused'] ?? '') === 'Yes') ? 'selected' : '' ?>>Yes</option>
            <option value="No" <?= ((string) ($reg['visa_refused'] ?? '') === 'No') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="sr-field">
          <label for="travel_with_family">Do you intend to travel with family members?</label>
          <select id="travel_with_family" name="travel_with_family" <?= $isSubmitted ? 'disabled' : '' ?> required>
            <option value="">Select…</option>
            <option value="Yes" <?= ((string) ($reg['travel_with_family'] ?? '') === 'Yes') ? 'selected' : '' ?>>Yes</option>
            <option value="No" <?= ((string) ($reg['travel_with_family'] ?? '') === 'No') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="sr-field full">
          <label for="referring_agent">Name of referring agent or staff member (if applicable)</label>
          <input id="referring_agent" name="referring_agent" value="<?= sr_val($reg, 'referring_agent') ?>" <?= ($isSubmitted || $referrerLocked) ? 'readonly' : '' ?>>
          <?php if ($referrerLocked && !$isSubmitted): ?>
          <div class="sr-hint" style="margin-top:6px;color:#64748b;font-size:.85rem">Filled automatically from the registration link you received.</div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="sr-card">
      <h2><span class="n">10</span> Declaration</h2>
      <label class="sr-check">
        <input type="checkbox" id="declaration_accepted" name="declaration_accepted" value="1" <?= !empty($reg['declaration_accepted']) ? 'checked' : '' ?> <?= $isSubmitted ? 'disabled' : '' ?>>
        <span>I confirm that the information provided is accurate and authorize Parrot Canada Visa Consultant Co. Ltd to assess my eligibility and assist with my admission application.</span>
      </label>
      <?php if (!$isSubmitted): ?>
      <div class="sr-actions">
        <button type="button" class="sr-btn primary" id="submitBtn">Submit application</button>
      </div>
      <?php endif; ?>
    </section>
  </form>
</div>

<script>
(function () {
  const locked = document.getElementById('srForm')?.dataset.locked === '1';
  const token = document.getElementById('srToken')?.value || '';
  const toast = document.getElementById('saveToast');
  const ALL_COUNTRIES = <?= json_encode($countries, JSON_UNESCAPED_UNICODE) ?>;
  const FEATURED = <?= json_encode($featured, JSON_UNESCAPED_UNICODE) ?>;
  let saveTimer = null;
  let saving = false;

  function showToast(msg, isErr) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.toggle('err', !!isErr);
    toast.classList.add('show');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => toast.classList.remove('show'), 1800);
  }

  function collectFields() {
    const fd = new FormData();
    fd.append('action', 'save_fields');
    fd.append('token', token);
    document.querySelectorAll('#srForm [name]').forEach(el => {
      const name = el.getAttribute('name');
      if (!name || name === 'token' || el.type === 'file') return;
      if (el.type === 'checkbox') {
        fd.append(name, el.checked ? '1' : '0');
        return;
      }
      fd.append(name, el.value || '');
    });
    return fd;
  }

  function scheduleSave() {
    if (locked) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveFields, 450);
  }

  function saveFields() {
    if (locked || saving) return;
    saving = true;
    fetch('self_registration_api.php', { method: 'POST', body: collectFields() })
      .then(r => r.json())
      .then(data => {
        if (data && data.ok) showToast('Saved', false);
        else showToast((data && data.error) || 'Save failed', true);
      })
      .catch(() => showToast('Save failed', true))
      .finally(() => { saving = false; });
  }

  function mountSmartCountry(root) {
    const name = root.getAttribute('data-name');
    const featuredOnly = root.getAttribute('data-featured') === '1';
    const initial = root.getAttribute('data-value') || '';
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = name;
    hidden.value = initial;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sc-trigger';
    btn.dataset.empty = initial ? '0' : '1';
    btn.innerHTML = '<span>' + (initial || 'Search or select country…') + '</span>';
    const panel = document.createElement('div');
    panel.className = 'sc-panel';
    panel.innerHTML = '<input type="search" class="sc-search" placeholder="Type to filter countries…" autocomplete="off"><div class="sc-list"></div>';
    root.appendChild(hidden);
    root.appendChild(btn);
    root.appendChild(panel);
    if (locked) {
      btn.disabled = true;
      return;
    }

    const search = panel.querySelector('.sc-search');
    const list = panel.querySelector('.sc-list');
    const featuredSet = {};
    FEATURED.forEach(c => { featuredSet[c] = true; });

    function render(q) {
      q = (q || '').trim().toLowerCase();
      list.innerHTML = '';
      let shown = 0;
      function addGroup(title, items) {
        const filtered = items.filter(n => !q || n.toLowerCase().includes(q));
        if (!filtered.length) return 0;
        const head = document.createElement('div');
        head.className = 'sc-group';
        head.textContent = title;
        list.appendChild(head);
        filtered.forEach(n => {
          const b = document.createElement('button');
          b.type = 'button';
          b.textContent = n;
          if (n === hidden.value) b.setAttribute('aria-selected', 'true');
          b.addEventListener('click', () => {
            hidden.value = n;
            btn.dataset.empty = '0';
            btn.querySelector('span').textContent = n;
            root.classList.remove('open');
            scheduleSave();
          });
          list.appendChild(b);
        });
        return filtered.length;
      }
      if (featuredOnly || !q) {
        shown += addGroup('Featured', FEATURED);
      }
      const rest = (ALL_COUNTRIES || []).filter(n => !featuredSet[n]);
      shown += addGroup(q ? 'Matching countries' : 'All countries', q ? (ALL_COUNTRIES || []) : rest);
      if (!shown) {
        const empty = document.createElement('div');
        empty.className = 'sc-empty';
        empty.textContent = 'No countries match';
        list.appendChild(empty);
      }
    }

    btn.addEventListener('click', () => {
      const open = root.classList.toggle('open');
      if (open) { render(search.value); search.focus(); }
    });
    search.addEventListener('input', () => render(search.value));
    document.addEventListener('click', (e) => {
      if (!root.contains(e.target)) root.classList.remove('open');
    });
    render('');
  }

  document.querySelectorAll('.smart-country').forEach(mountSmartCountry);

  document.querySelectorAll('#srForm input, #srForm select, #srForm textarea').forEach(el => {
    if (el.type === 'file') return;
    el.addEventListener('change', scheduleSave);
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.addEventListener('input', scheduleSave);
    }
  });

  function syncOtherField() {
    const wrap = document.getElementById('otherFieldWrap');
    if (!wrap) return;
    let need = false;
    document.querySelectorAll('.field-choice').forEach(sel => {
      if ((sel.value || '').indexOf('Other') === 0) need = true;
    });
    wrap.hidden = !need;
  }
  document.querySelectorAll('.field-choice').forEach(sel => sel.addEventListener('change', syncOtherField));
  syncOtherField();

  function uploadFile(input) {
    if (locked || !input.files || !input.files[0]) return;
    const key = input.getAttribute('data-doc-key');
    const file = input.files[0];
    const prog = document.getElementById('prog_' + key);
    const bar = document.getElementById('bar_' + key);
    const lab = document.getElementById('lab_' + key);
    const fileEl = document.getElementById('file_' + key);
    const item = input.closest('.doc-item');
    if (prog) { prog.classList.add('show'); prog.classList.remove('done', 'fail'); }
    if (bar) { bar.style.width = '8%'; }
    if (lab) lab.textContent = 'Uploading…';

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('token', token);
    fd.append('doc_key', key);
    fd.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'self_registration_api.php');
    xhr.upload.onprogress = function (e) {
      if (!e.lengthComputable || !bar) return;
      const pct = Math.max(8, Math.round((e.loaded / e.total) * 100));
      bar.style.width = pct + '%';
      if (lab) lab.textContent = 'Uploading ' + pct + '%';
    };
    xhr.onload = function () {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
        if (bar) bar.style.width = '100%';
        if (prog) prog.classList.add('done');
        if (lab) lab.textContent = 'Saved';
        if (fileEl) fileEl.textContent = data.original_name || file.name;
        if (item) {
          item.classList.add('saved');
          const tag = item.querySelector('.tag');
          if (tag) tag.textContent = 'Saved';
        }
        showToast('Document saved', false);
      } else {
        if (prog) prog.classList.add('fail');
        if (lab) lab.textContent = (data && data.error) || 'Upload failed';
        showToast((data && data.error) || 'Upload failed', true);
      }
      input.value = '';
    };
    xhr.onerror = function () {
      if (prog) prog.classList.add('fail');
      if (lab) lab.textContent = 'Upload failed';
      showToast('Upload failed', true);
      input.value = '';
    };
    xhr.send(fd);
  }

  document.querySelectorAll('.sr-upload').forEach(input => {
    input.addEventListener('change', () => uploadFile(input));
  });

  document.getElementById('submitBtn')?.addEventListener('click', function () {
    if (locked) return;
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Submitting…';
    // ensure latest fields saved first
    const fdSave = collectFields();
    fetch('self_registration_api.php', { method: 'POST', body: fdSave })
      .then(r => r.json())
      .then(() => {
        const fd = new FormData();
        fd.append('action', 'submit');
        fd.append('token', token);
        return fetch('self_registration_api.php', { method: 'POST', body: fd }).then(r => r.json());
      })
      .then(data => {
        if (data && data.ok) {
          showToast('Submitted', false);
          window.location.reload();
          return;
        }
        alert((data && data.error) || 'Submit failed');
        btn.disabled = false;
        btn.textContent = 'Submit application';
      })
      .catch(() => {
        alert('Submit failed. Check your connection.');
        btn.disabled = false;
        btn.textContent = 'Submit application';
      });
  });
})();
</script>
</body>
</html>

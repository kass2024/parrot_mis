<?php
declare(strict_types=1);

$isStaffAdd = (string) ($_GET['staff'] ?? $_POST['staff_add'] ?? '') === '1';

if ($isStaffAdd) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('MALTA_COURSES_FORM');
        session_start();
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/malta_courses_schema.php';
if ($isStaffAdd) {
    require_once __DIR__ . '/helpers/role.php';
}
malta_ensure_schema($conn);

if ($isStaffAdd) {
    $adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        header('Location: admin-login.php');
        exit;
    }
}

$error = '';
$doneRef = '';
$doneUser = '';
$doneEmail = '';
$programs = malta_program_options();
$countries = malta_country_options();
$featuredCountries = malta_featured_countries();

if (!$isStaffAdd && isset($_GET['new']) && (string) $_GET['new'] === '1') {
    $_SESSION['malta_user_id'] = 'mlt_' . bin2hex(random_bytes(6)) . '_' . time();
    header('Location: malta-courses-request.php');
    exit;
}

if (!$isStaffAdd) {
    $resumeId = trim((string) ($_GET['id'] ?? ''));
    if ($resumeId !== '' && preg_match('/^mlt_[a-zA-Z0-9_]+$/', $resumeId)) {
        $_SESSION['malta_user_id'] = $resumeId;
    }
    if (empty($_SESSION['malta_user_id']) || !preg_match('/^mlt_[a-zA-Z0-9_]+$/', (string) $_SESSION['malta_user_id'])) {
        $_SESSION['malta_user_id'] = 'mlt_' . bin2hex(random_bytes(6)) . '_' . time();
    }
    $userId = (string) $_SESSION['malta_user_id'];
    $st = $conn->prepare('SELECT reference_id, email FROM malta_courses_applications WHERE user_id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $userId);
        $st->execute();
        $existing = $st->get_result()->fetch_assoc();
        $st->close();
        if ($existing) {
            $doneRef = (string) $existing['reference_id'];
            $doneUser = $userId;
            $doneEmail = (string) ($existing['email'] ?? '');
        }
    }
} else {
    $userId = 'mlt_staff_' . bin2hex(random_bytes(6)) . '_' . time();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $doneRef === '') {
    try {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $program = (string) ($_POST['program'] ?? '');
        if ($fullName === '' || mb_strlen($fullName) > 200) {
            throw new RuntimeException('Enter the student full name.');
        }
        if ($email === false) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if ($phone === '' || mb_strlen($phone) > 40) {
            throw new RuntimeException('Enter a phone or WhatsApp number.');
        }
        if (!in_array($country, $countries, true)) {
            throw new RuntimeException('Choose a country from the list.');
        }
        if (!isset($programs[$program])) {
            throw new RuntimeException('Choose a professional training program.');
        }
        $passport = malta_store_upload($_FILES['passport'] ?? [], 'passport');
        $cv = malta_store_upload($_FILES['cv'] ?? [], 'cv');
        $academic = malta_store_upload($_FILES['academic'] ?? [], 'academic');
        $reference = 'MLT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $source = $isStaffAdd ? 'staff' : 'public';
        $emailStr = (string) $email;
        $stmt = $conn->prepare(
            'INSERT INTO malta_courses_applications
            (user_id, reference_id, full_name, email, phone, country, program, passport_file, cv_file, academic_file, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not save the application.');
        }
        $stmt->bind_param(
            'sssssssssss',
            $userId,
            $reference,
            $fullName,
            $emailStr,
            $phone,
            $country,
            $program,
            $passport,
            $cv,
            $academic,
            $source
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save the application. Please try again.');
        }
        $stmt->close();
        malta_email_application_copy(
            [
                'reference' => $reference,
                'full_name' => $fullName,
                'email' => $emailStr,
                'phone' => $phone,
                'country' => $country,
                'program' => $program,
                'source' => $source,
            ],
            [
                'passport' => $passport,
                'cv' => $cv,
                'academic' => $academic,
            ]
        );
        $doneRef = $reference;
        $doneUser = $userId;
        $doneEmail = $emailStr;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Study Professional Courses in Malta | Parrot Canada Visa Consultant</title>
<style>
:root { --green:#1b4332; --mid:#2d6a4f; --ink:#122018; --muted:#5c6b63; --line:#d7e0da; --bg:#f3f6f4; --red:#b42318; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.wrap { max-width:920px; margin:0 auto; padding:1.4rem 1rem 3rem; }
.hero { background:linear-gradient(145deg,#143326,#1b4332 48%,#2d6a4f); color:#fff; border-radius:20px; padding:1.5rem 1.5rem 1.3rem; box-shadow:0 16px 36px rgba(20,51,38,.16); }
.kicker { display:inline-block; background:rgba(255,255,255,.14); border-radius:999px; padding:.3rem .72rem; font-size:.75rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
.hero h1 { margin:.6rem 0 0; font-size:clamp(1.45rem,3vw,2rem); line-height:1.2; letter-spacing:-.02em; }
.hero p { margin:.7rem 0 0; line-height:1.55; max-width:64ch; color:rgba(255,255,255,.92); }
.pills { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:1rem; }
.pills span { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.16); border-radius:999px; padding:.32rem .7rem; font-size:.8rem; }
.card { background:#fff; border:1px solid #e4ebe6; border-radius:18px; padding:1.35rem 1.35rem 1.4rem; margin-top:1rem; box-shadow:0 10px 28px rgba(18,32,24,.05); }
h2 { margin:0; font-size:1.08rem; color:var(--green); letter-spacing:-.01em; }
.section-note { margin:.25rem 0 1rem; color:var(--muted); font-size:.9rem; }
.grid { display:grid; gap:1rem 1.1rem; }
@media (min-width:760px) { .grid.two { grid-template-columns:1fr 1fr; } .programs { grid-template-columns:1fr 1fr 1fr; } .docs { grid-template-columns:1fr 1fr 1fr; } }
.field { min-width:0; }
.field label, .req { display:block; margin:0 0 .4rem; font-size:.84rem; font-weight:650; color:#1c2b23; letter-spacing:.01em; }
label.req::after { content:" *"; color:var(--red); }
.control {
  display:block; width:100%; height:52px; margin:0; padding:0 14px;
  border:1.5px solid var(--line); border-radius:10px; background:#fbfcfb; color:var(--ink);
  font:500 15.5px/52px "Segoe UI",system-ui,sans-serif;
  box-shadow:inset 0 1px 1px rgba(18,32,24,.03);
  transition:border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.control::placeholder { color:#8d9a93; font-weight:400; }
.control:hover { border-color:#b7c7bd; background:#fff; }
.control:focus { outline:none; border-color:var(--green); background:#fff; box-shadow:0 0 0 4px rgba(27,67,50,.12); }
select.control { padding-right:40px; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%231b4332' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' d='M3.5 6.2 8 10.5l4.5-4.3'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; cursor:pointer; }
.divider { height:1px; background:#e7eee9; margin:1.35rem 0 1.15rem; border:0; }
.programs { display:grid; gap:.65rem; }
.program { border:1.5px solid var(--line); border-radius:12px; padding:.85rem .9rem; display:flex; gap:.65rem; align-items:center; cursor:pointer; background:#fff; min-height:72px; }
.program:hover { border-color:#b7c7bd; }
.program:has(input:checked) { border-color:var(--green); background:#f3faf5; box-shadow:0 0 0 3px rgba(27,67,50,.08); }
.program input { width:18px; height:18px; margin:0; accent-color:var(--green); flex:0 0 auto; }
.program strong { display:block; font-size:.94rem; }
.program small { color:var(--muted); }
.fees { margin-top:1.15rem; border:1.5px solid var(--line); border-radius:14px; background:#f8fbf9; padding:1rem 1.05rem .95rem; }
.fees h3 { margin:0 0 .35rem; font-size:1rem; color:var(--ink); }
.fees > p { margin:0 0 .8rem; color:var(--muted); font-size:.88rem; line-height:1.5; }
.fees ul { list-style:none; margin:0; padding:0; display:grid; gap:.55rem; }
@media (min-width:760px) { .fees ul { grid-template-columns:1fr 1fr 1fr; } }
.fee { background:#fff; border:1px solid #e4ebe6; border-radius:10px; padding:.8rem .85rem; }
.fee strong { display:block; color:var(--green); font-size:.95rem; margin-bottom:.25rem; }
.fee span { display:block; font-size:.92rem; line-height:1.35; }
.docs { display:grid; gap:.75rem; }
.doc { border:1.5px solid var(--line); border-radius:14px; padding:1rem .95rem .9rem; background:#fbfcfb; display:flex; flex-direction:column; min-height:168px; }
.doc strong { display:block; font-size:.95rem; }
.doc p { margin:.3rem 0 .85rem; color:var(--muted); font-size:.82rem; line-height:1.4; flex:1; }
.file-btn { position:relative; display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border-radius:8px; border:1.5px solid var(--green); color:var(--green); background:#fff; font-size:.86rem; font-weight:700; cursor:pointer; width:fit-content; }
.file-btn:hover { background:#f3faf5; }
.file-btn input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
.file-name { display:block; margin-top:.55rem; font-size:.8rem; color:var(--muted); word-break:break-word; }
.file-name.is-set { color:var(--green); font-weight:650; }
button[type="submit"] { width:100%; margin-top:1.25rem; background:var(--green); color:#fff; border:0; border-radius:10px; height:54px; font-weight:700; font-size:1rem; letter-spacing:.01em; cursor:pointer; }
button[type="submit"]:hover { background:#143326; }
.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:12px; padding:.9rem 1rem; margin-top:1rem; }
.ok { text-align:center; }
.ref { font-family:ui-monospace,monospace; background:#f4f7f5; padding:.8rem 1rem; border-radius:8px; display:inline-block; margin:.5rem 0; }
.hint { color:var(--muted); font-size:.9rem; }
.facts { display:grid; gap:.7rem; }
@media (min-width:760px) { .facts { grid-template-columns:1fr 1fr; } }
.fact { background:#f6faf7; border-radius:12px; padding:.85rem .9rem; }
.fact strong { display:block; margin-bottom:.25rem; color:var(--green); }
.fact p { margin:0; color:#3d4a42; font-size:.9rem; line-height:1.45; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <span class="kicker">Malta · Schengen · English</span>
    <h1>Study professional courses in Malta</h1>
    <p>Parrot Canada Visa Consultant is accepting applications for practical professional training. Slots are limited. Job assistance is offered after graduation; employment is not guaranteed.</p>
    <div class="pills">
      <span>Electrician</span><span>Plumber</span><span>Welder</span><span>Hotel manager</span><span>Restaurant manager</span><span>Yacht interior</span>
    </div>
  </div>

  <?php if ($doneRef !== ''): ?>
  <div class="card ok">
    <h2>Application received</h2>
    <p>Keep this reference.<?php if ($doneEmail !== ''): ?> A confirmation email was sent to <?= htmlspecialchars($doneEmail, ENT_QUOTES, 'UTF-8') ?>.<?php endif; ?> Our team will review the passport, CV, and academic documents.</p>
    <div class="ref"><?= htmlspecialchars($doneRef, ENT_QUOTES, 'UTF-8') ?></div>
    <p class="hint">Application ID: <?= htmlspecialchars($doneUser, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($isStaffAdd): ?>
    <p><a href="malta-courses-applications.php">Back to Malta applications</a></p>
    <?php else: ?>
    <p><a href="malta-courses-request.php?new=1">Submit another application</a></p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php $selectedCountry = (string) ($_POST['country'] ?? ''); $selectedProgram = (string) ($_POST['program'] ?? ''); ?>
  <form class="card" method="post" enctype="multipart/form-data">
    <?php if ($isStaffAdd): ?><input type="hidden" name="staff_add" value="1"><?php endif; ?>
    <h2><?= $isStaffAdd ? 'Add a Malta student' : 'Your details' ?></h2>
    <p class="section-note">Use the name as it appears on the passport.</p>
    <div class="grid two">
      <div class="field">
        <label class="req" for="full_name">Full name</label>
        <input class="control" id="full_name" name="full_name" type="text" autocomplete="name" required maxlength="200" placeholder="As written on the passport" value="<?= htmlspecialchars((string) ($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="field">
        <label class="req" for="email">Email</label>
        <input class="control" id="email" name="email" type="email" autocomplete="email" required maxlength="150" placeholder="name@email.com" value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="field">
        <label class="req" for="phone">Phone / WhatsApp</label>
        <input class="control" id="phone" name="phone" type="tel" autocomplete="tel" required maxlength="40" placeholder="+250 7XX XXX XXX" value="<?= htmlspecialchars((string) ($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="field">
        <label class="req" for="country">Country</label>
        <select class="control" id="country" name="country" required>
          <option value="">Select a country…</option>
          <optgroup label="Priority countries">
            <?php foreach ($featuredCountries as $name): ?>
            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedCountry === $name ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="All countries">
            <?php foreach ($countries as $name): ?>
            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedCountry === $name ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>
    </div>

    <hr class="divider">
    <h2>Professional training program</h2>
    <p class="section-note">Choose one program. Slots are limited.</p>
    <div class="programs">
      <?php foreach ($programs as $key => $label): ?>
      <label class="program">
        <input type="radio" name="program" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedProgram === $key ? 'checked' : '' ?> required>
        <span><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><small>Malta</small></span>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="fees">
      <h3>Course fees</h3>
      <p>The fees are the same for all six courses: Professional Electrician, Professional Plumber, Professional Welder, Hotel Manager, Restaurant Manager and Yacht Interior.</p>
      <ul>
        <li class="fee"><strong>Soft</strong><span>€4,500 total | €1,350 deposit</span></li>
        <li class="fee"><strong>Semi-Intensive</strong><span>€6,900 total | €2,070 deposit</span></li>
        <li class="fee"><strong>Intensive</strong><span>€10,900 total | €3,270 deposit</span></li>
      </ul>
    </div>

    <hr class="divider">
    <h2>Documents</h2>
    <p class="section-note">PDF, JPG, PNG, DOC, or DOCX. Maximum 8 MB each.</p>
    <div class="docs">
      <div class="doc">
        <strong>Passport *</strong>
        <p>Data page, clear and readable.</p>
        <label class="file-btn">Choose file
          <input id="passport" name="passport" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
        </label>
        <span class="file-name" data-for="passport">No file chosen</span>
      </div>
      <div class="doc">
        <strong>CV *</strong>
        <p>Current résumé.</p>
        <label class="file-btn">Choose file
          <input id="cv" name="cv" type="file" required accept=".pdf,.doc,.docx,application/pdf">
        </label>
        <span class="file-name" data-for="cv">No file chosen</span>
      </div>
      <div class="doc">
        <strong>Academic documents *</strong>
        <p>Certificates or transcripts in one file.</p>
        <label class="file-btn">Choose file
          <input id="academic" name="academic" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*">
        </label>
        <span class="file-name" data-for="academic">No file chosen</span>
      </div>
    </div>
    <button type="submit"><?= $isStaffAdd ? 'Save student' : 'Submit application' ?></button>
  </form>
  <div class="card">
    <h2>Why Malta</h2>
    <div class="facts">
      <div class="fact"><strong>European Union and Schengen</strong><p>Malta is in the EU and the Schengen area. English is an official language.</p></div>
      <div class="fact"><strong>Practical training</strong><p>Career-focused programs for electrician, plumber, welder, hospitality, and yacht interior work.</p></div>
      <div class="fact"><strong>After graduation</strong><p>The academy offers career guidance and job placement assistance. Employment is not guaranteed.</p></div>
      <div class="fact"><strong>During studies</strong><p>Eligible students may work part-time, subject to Malta’s student employment rules.</p></div>
    </div>
  </div>
  <script>
  document.querySelectorAll('.doc input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = document.querySelector('.file-name[data-for="' + input.id + '"]');
      if (!label) return;
      if (input.files && input.files[0]) {
        label.textContent = input.files[0].name;
        label.classList.add('is-set');
      } else {
        label.textContent = 'No file chosen';
        label.classList.remove('is-set');
      }
    });
  });
  </script>
  <?php endif; ?>
</div>
</body>
</html>

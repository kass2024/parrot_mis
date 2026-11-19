<?php
declare(strict_types=1);

$isStaffAdd = (string) ($_GET['staff'] ?? $_POST['staff_add'] ?? '') === '1';

if ($isStaffAdd) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('ECI_MALTA_FORM');
        session_start();
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/eci_malta_schema.php';
if ($isStaffAdd) {
    require_once __DIR__ . '/helpers/role.php';
}
eci_ensure_schema($conn);

if ($isStaffAdd) {
    $adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        header('Location: admin-login.php');
        exit;
    }
}

function eci_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eci_post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function eci_upper(string $value, int $max): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if (function_exists('mb_strtoupper')) {
        $value = mb_strtoupper($value, 'UTF-8');
    } else {
        $value = strtoupper($value);
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function eci_yn(string $value): string
{
    return in_array($value, ['yes', 'no'], true) ? $value : '';
}

$error = '';
$doneRef = '';
$doneUser = '';
$doneEmail = '';
$eciMailSent = false;
$months = eci_entry_months();
$modes = eci_study_modes();
$ethnicOptions = eci_ethnic_options();
$courses = eci_course_programs();

if (!$isStaffAdd && isset($_GET['new']) && (string) $_GET['new'] === '1') {
    $_SESSION['eci_user_id'] = 'eci_' . bin2hex(random_bytes(6)) . '_' . time();
    header('Location: eci-malta-application.php');
    exit;
}

if (!$isStaffAdd) {
    $resumeId = trim((string) ($_GET['id'] ?? ''));
    if ($resumeId !== '' && preg_match('/^eci_[a-zA-Z0-9_]+$/', $resumeId)) {
        $_SESSION['eci_user_id'] = $resumeId;
    }
    if (empty($_SESSION['eci_user_id']) || !preg_match('/^eci_[a-zA-Z0-9_]+$/', (string) $_SESSION['eci_user_id'])) {
        $_SESSION['eci_user_id'] = 'eci_' . bin2hex(random_bytes(6)) . '_' . time();
    }
    $userId = (string) $_SESSION['eci_user_id'];
    $st = $conn->prepare('SELECT reference_id, email FROM eci_malta_applications WHERE user_id = ? LIMIT 1');
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
    $userId = 'eci_staff_' . bin2hex(random_bytes(6)) . '_' . time();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $doneRef === '') {
    try {
        $lastName = eci_upper(eci_post('last_name'), 120);
        $firstName = eci_upper(eci_post('first_name'), 120);
        $title = eci_upper(eci_post('title'), 30);
        $previousName = eci_upper(eci_post('previous_family_name'), 120);
        $gender = eci_post('gender');
        $dob = eci_post('date_of_birth');
        $nationality = eci_upper(eci_post('nationality'), 80);
        $englishFirst = eci_yn(eci_post('english_first'));
        $disability = eci_yn(eci_post('disability'));
        $studiedBefore = eci_yn(eci_post('studied_before'));
        $studiedDetails = eci_upper(eci_post('studied_details'), 255);
        $permanent = eci_upper(eci_post('permanent_address'), 1000);
        $correspondence = eci_upper(eci_post('correspondence_address'), 1000);
        $phone = eci_post('phone');
        $whatsapp = eci_post('whatsapp');
        $linkedin = trim(eci_post('linkedin'));
        $email = filter_var(trim(eci_post('email')), FILTER_VALIDATE_EMAIL);
        $courseKey = eci_post('course_title');
        $entryMonth = eci_post('entry_month');
        $entryYear = eci_post('entry_year');
        $studyMode = eci_post('study_mode');
        $criminal = eci_yn(eci_post('criminal_conviction'));
        $ethnic = eci_post('ethnic_origin');
        $englishTest = eci_yn(eci_post('english_test'));
        $englishResult = eci_upper(eci_post('english_result'), 80);
        $viaAgent = eci_yn(eci_post('via_agent'));
        $agentDetails = eci_upper(eci_post('agent_details'), 255);
        $signature = eci_upper(eci_post('signature_name'), 200);
        $signatureDate = eci_post('signature_date');
        $declare = $_POST['declare'] ?? [];
        if (!is_array($declare)) {
            $declare = [];
        }

        if (!isset($courses[$courseKey])) {
            throw new RuntimeException('Choose a programme from the list.');
        }
        $course = $courses[$courseKey]['title'];
        if (!isset($months[$entryMonth])) {
            throw new RuntimeException('Choose the month of entry.');
        }
        $yearNow = (int) date('Y');
        if (!preg_match('/^\d{4}$/', $entryYear) || (int) $entryYear < $yearNow || (int) $entryYear > $yearNow + 4) {
            throw new RuntimeException('Enter a valid year of entry.');
        }
        if (!isset($modes[$studyMode])) {
            throw new RuntimeException('Choose the mode of study.');
        }
        if ($lastName === '' || $firstName === '') {
            throw new RuntimeException('Enter the surname and forename(s) as they should appear on the certificate.');
        }
        if (!in_array($gender, ['Male', 'Female'], true)) {
            throw new RuntimeException('Choose a gender.');
        }
        $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobObj || $dobObj->format('Y-m-d') !== $dob || $dobObj > new DateTime('today')) {
            throw new RuntimeException('Enter a valid date of birth.');
        }
        if ($nationality === '') {
            throw new RuntimeException('Enter the nationality.');
        }
        if ($englishFirst === '' || $disability === '' || $studiedBefore === '') {
            throw new RuntimeException('Answer the personal-details questions.');
        }
        if ($permanent === '') {
            throw new RuntimeException('Enter the permanent address.');
        }
        if ($phone === '' || strlen($phone) > 40) {
            throw new RuntimeException('Enter a phone or mobile number.');
        }
        if (strlen($whatsapp) > 80) {
            throw new RuntimeException('WhatsApp / Skype is too long.');
        }
        if (strlen($linkedin) > 255) {
            throw new RuntimeException('The LinkedIn profile is too long.');
        }
        if ($email === false) {
            throw new RuntimeException('Enter a valid email address.');
        }
        $qualNames = $_POST['qual_name'] ?? [];
        $qualGrades = $_POST['qual_grade'] ?? [];
        $qualDates = $_POST['qual_date'] ?? [];
        $qualSchools = $_POST['qual_school'] ?? [];
        if (!is_array($qualNames) || !is_array($qualGrades) || !is_array($qualDates) || !is_array($qualSchools)) {
            throw new RuntimeException('Enter the qualifications.');
        }
        $quals = [];
        $count = min(6, count($qualNames));
        for ($i = 0; $i < $count; $i++) {
            $qName = eci_upper((string) ($qualNames[$i] ?? ''), 180);
            $qGrade = eci_upper((string) ($qualGrades[$i] ?? ''), 40);
            $qDate = eci_upper((string) ($qualDates[$i] ?? ''), 40);
            $qSchool = eci_upper((string) ($qualSchools[$i] ?? ''), 180);
            if ($qName === '' && $qGrade === '' && $qDate === '' && $qSchool === '') {
                continue;
            }
            if ($qName === '' || $qSchool === '') {
                throw new RuntimeException('Each qualification needs the qualification name and the educational establishment.');
            }
            $quals[] = [
                'qualification' => $qName,
                'grade' => $qGrade,
                'completion' => $qDate,
                'establishment' => $qSchool,
            ];
        }
        if ($quals === []) {
            throw new RuntimeException('List at least the highest qualification.');
        }
        if ($criminal === '') {
            throw new RuntimeException('Answer the criminal-convictions question.');
        }
        if (!in_array($ethnic, $ethnicOptions, true)) {
            throw new RuntimeException('Select an ethnic origin.');
        }
        if ($englishTest === '') {
            throw new RuntimeException('Answer the English language test question.');
        }
        if ($englishTest === 'yes' && $englishResult === '') {
            throw new RuntimeException('Enter the overall English language result.');
        }
        if ($viaAgent === '') {
            throw new RuntimeException('Say whether you are applying through an agent.');
        }
        if ($viaAgent === 'yes' && $agentDetails === '') {
            throw new RuntimeException('Enter the agent or educational representative name and email.');
        }
        foreach (['1', '2', '3', '4'] as $box) {
            if (!in_array($box, $declare, true)) {
                throw new RuntimeException('Confirm every statement in the declaration.');
            }
        }
        if ($signature === '') {
            throw new RuntimeException('Type your signature name.');
        }
        $signObj = DateTime::createFromFormat('Y-m-d', $signatureDate);
        if (!$signObj || $signObj->format('Y-m-d') !== $signatureDate) {
            throw new RuntimeException('Enter the declaration date.');
        }

        $attachPassport = eci_yn(eci_post('attach_passport'));
        $attachTranscripts = eci_yn(eci_post('attach_transcripts'));
        $attachCv = eci_yn(eci_post('attach_cv'));
        $attachEnglish = eci_yn(eci_post('attach_english'));
        $attachPhoto = eci_yn(eci_post('attach_photo'));
        if ($attachPassport !== 'yes' || $attachTranscripts !== 'yes' || $attachPhoto !== 'yes') {
            throw new RuntimeException('Passport/ID, academic transcripts, and a passport photo are required.');
        }
        if ($attachCv === '') {
            throw new RuntimeException('Say whether a CV is attached.');
        }
        if ($attachEnglish === '') {
            throw new RuntimeException('Say whether English language evidence is attached.');
        }
        if ($englishFirst === 'no' && $attachEnglish !== 'yes') {
            throw new RuntimeException('Attach the English language qualification because English is not the first language.');
        }

        $passport = eci_store_upload($_FILES['passport'] ?? [], 'passport', true);
        $transcripts = eci_store_upload($_FILES['transcripts'] ?? [], 'transcripts', true);
        $cv = eci_store_upload($_FILES['cv'] ?? [], 'cv', $attachCv === 'yes');
        $englishFile = eci_store_upload($_FILES['english_file'] ?? [], 'english', $attachEnglish === 'yes');
        $photo = eci_store_upload($_FILES['photo'] ?? [], 'photo', true);

        $reference = 'ECI-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $source = $isStaffAdd ? 'staff' : 'public';
        $emailStr = (string) $email;
        $qualJson = json_encode($quals, JSON_UNESCAPED_UNICODE);
        if ($qualJson === false) {
            throw new RuntimeException('Could not save the qualifications.');
        }
        $dobSql = $dobObj->format('Y-m-d');
        $signSql = $signObj->format('Y-m-d');

        $stmt = $conn->prepare(
            'INSERT INTO eci_malta_applications
            (user_id, reference_id, first_name, last_name, title, previous_family_name, gender, date_of_birth, nationality,
             english_first, disability, studied_before, studied_details, permanent_address, correspondence_address,
             phone, whatsapp, linkedin, email, course_title, entry_month, entry_year, study_mode, qualifications_json,
             criminal_conviction, ethnic_origin, english_test, english_result, via_agent, agent_details, signature_name,
             signature_date, passport_file, transcripts_file, cv_file, english_file, photo_file, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not save the application.');
        }
        $stmt->bind_param(
            'ssssssssssssssssssssssssssssssssssssss',
            $userId,
            $reference,
            $firstName,
            $lastName,
            $title,
            $previousName,
            $gender,
            $dobSql,
            $nationality,
            $englishFirst,
            $disability,
            $studiedBefore,
            $studiedDetails,
            $permanent,
            $correspondence,
            $phone,
            $whatsapp,
            $linkedin,
            $emailStr,
            $course,
            $entryMonth,
            $entryYear,
            $studyMode,
            $qualJson,
            $criminal,
            $ethnic,
            $englishTest,
            $englishResult,
            $viaAgent,
            $agentDetails,
            $signature,
            $signSql,
            $passport,
            $transcripts,
            $cv,
            $englishFile,
            $photo,
            $source
        );
        if (!$stmt->execute()) {
            $dbError = $stmt->error;
            $stmt->close();
            if (str_contains($dbError, 'Duplicate')) {
                throw new RuntimeException('This application was already submitted.');
            }
            throw new RuntimeException('Could not save the application. Please try again.');
        }
        $stmt->close();

        $qualLines = [];
        foreach ($quals as $index => $row) {
            $qualLines[] = ($index + 1) . '. ' . $row['qualification'] . ' | ' . $row['grade'] . ' | ' . $row['completion'] . ' | ' . $row['establishment'];
        }
        $emailResult = eci_email_application_copy(
            [
                'Reference' => $reference,
                'Application ID' => $userId,
                'Course' => $course,
                'Entry' => $entryMonth . ' ' . $entryYear,
                'Mode of study' => $studyMode,
                'Surname' => $lastName,
                'Forename(s)' => $firstName,
                'Title' => $title,
                'Previous family name' => $previousName,
                'Gender' => $gender,
                'Date of birth' => $dobSql,
                'Nationality' => $nationality,
                'English first language' => $englishFirst,
                'Disability' => $disability,
                'Previously studied at ECI' => $studiedBefore . ($studiedDetails !== '' ? ' — ' . $studiedDetails : ''),
                'Permanent address' => $permanent,
                'Correspondence address' => $correspondence,
                'Phone' => $phone,
                'WhatsApp / Skype' => $whatsapp,
                'LinkedIn' => $linkedin,
                'Email' => $emailStr,
                'Qualifications' => implode("\n", $qualLines),
                'Criminal conviction' => $criminal,
                'Ethnic origin' => $ethnic,
                'English test' => $englishTest . ($englishResult !== '' ? ' — ' . $englishResult : ''),
                'Agent' => $viaAgent . ($agentDetails !== '' ? ' — ' . $agentDetails : ''),
                'Signature' => $signature,
                'Signature date' => $signSql,
                'Source' => $source === 'staff' ? 'Added by staff' : 'Public form',
                'Submitted' => date('Y-m-d H:i'),
            ],
            [
                'passport' => $passport,
                'transcripts' => $transcripts,
                'cv' => $cv,
                'english' => $englishFile,
                'photo' => $photo,
            ]
        );
        $doneRef = $reference;
        $doneUser = $userId;
        $doneEmail = $emailStr;
        $eciMailSent = !empty($emailResult['office']['ok']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$v = static function (string $key) : string {
    return eci_h(trim((string) ($_POST[$key] ?? '')));
};
$checked = static function (string $key, string $expect) : string {
    return (string) ($_POST[$key] ?? '') === $expect ? ' checked' : '';
};
$qualValue = static function (string $key, int $index) : string {
    $bag = $_POST[$key] ?? [];
    if (!is_array($bag)) {
        return '';
    }
    return eci_h(trim((string) ($bag[$index] ?? '')));
};
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ECI Student Application Form — Study in Malta</title>
<style>
  :root { --blue:#2a5db0; --ink:#111; --muted:#444; --yellow:#ffe14a; }
  * { box-sizing:border-box; }
  body { margin:0; background:#e6e6e6; color:var(--ink); font-family:Arial, Helvetica, sans-serif; }
  .sheet { max-width:980px; margin:18px auto 40px; background:#fff; border:1px solid #cfcfcf; box-shadow:0 8px 28px rgba(0,0,0,.08); }
  .eci-top { display:flex; align-items:stretch; background:#000; color:#fff; min-height:92px; }
  .logo { background:var(--yellow); color:#111; width:108px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:8px 6px; flex:0 0 108px; }
  .logo strong { font-size:34px; letter-spacing:-1px; line-height:1; }
  .logo small { display:block; margin-top:3px; font-size:7px; font-weight:700; letter-spacing:.02em; line-height:1.15; }
  .brand { padding:16px 18px 14px; display:flex; flex-direction:column; justify-content:center; }
  .brand h1 { margin:0; font-size:22px; letter-spacing:.04em; font-weight:700; }
  .brand p { margin:6px 0 0; color:var(--yellow); font-weight:700; letter-spacing:.08em; font-size:13px; }
  .intro { padding:16px 18px 0; }
  .intro h2 { margin:0 0 6px; font-size:18px; }
  .intro h2 span { font-weight:400; font-size:15px; }
  .intro .mail { margin:0 0 12px; font-size:13px; }
  .via { margin:0 18px 12px; font-size:12px; color:#333; background:#f7f7f7; border:1px solid #ddd; padding:8px 10px; }
  .bar { background:#000; color:#fff; font-weight:700; letter-spacing:.04em; font-size:13px; padding:6px 10px; margin:0; }
  table.form { width:100%; border-collapse:collapse; }
  table.form td { border:1px solid var(--blue); padding:8px 10px; vertical-align:middle; font-size:13px; }
  table.form .lbl { width:168px; font-weight:700; background:#fff; }
  .hint { display:block; font-weight:400; font-style:italic; color:#333; font-size:11px; margin-top:3px; }
  input[type="text"], input[type="email"], input[type="tel"], input[type="url"], input[type="date"], input[type="number"], select, textarea {
    width:100%; border:1px solid var(--blue); background:#fff; color:#111; font:13px Arial, Helvetica, sans-serif;
    padding:5px 7px; border-radius:0; text-transform:uppercase;
  }
  input[type="email"], input[type="url"], input[type="date"], input[type="file"] { text-transform:none; }
  textarea { min-height:72px; resize:vertical; }
  .checks { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; }
  .checks label { font-weight:700; display:inline-flex; align-items:center; gap:6px; }
  .yearbox { max-width:140px; }
  select.course { text-transform:none; padding:8px 7px; background:#fff; }
  .reqs { margin-top:10px; padding:10px 12px; background:#f8fbff; border:1px solid #d5e0f2; font-size:12.5px; line-height:1.45; }
  .reqs strong { display:block; margin-bottom:6px; }
  .reqs p { margin:0 0 8px; padding:6px 8px; border:1px solid transparent; }
  .reqs p.is-on { background:#fff6c2; border-color:#e2c84a; }
  .reqs .equiv { margin:0; padding:0; font-style:italic; color:#333; }
  .note { font-size:12px; color:#222; }
  table.quals { width:100%; border-collapse:collapse; }
  table.quals th, table.quals td { border:1px solid var(--blue); padding:6px; font-size:12px; text-align:center; }
  table.quals th { font-weight:700; background:#fff; }
  .ethnic { display:grid; grid-template-columns:1fr 1fr; gap:6px 18px; padding:10px 12px 12px; font-size:13px; }
  .ethnic label { display:flex; gap:6px; align-items:flex-start; }
  .decl { padding:4px 14px 2px; font-size:14px; line-height:1.5; }
  .decl label { display:grid; grid-template-columns:22px 1fr; gap:10px; align-items:start; margin:0 0 10px; padding:10px 12px; border:1px solid #d5e0f2; background:#f8fbff; }
  .decl input { width:16px; height:16px; margin-top:2px; }
  .regs { margin:4px 14px 14px; font-size:13.5px; line-height:1.45; }
  .sign td { width:50%; vertical-align:top; }
  .sign .cap { display:block; font-weight:700; margin-bottom:6px; }
  .pair { display:grid; grid-template-columns:1.2fr .8fr; border-left:1px solid var(--blue); border-right:1px solid var(--blue); }
  .pair .q, .pair .a { padding:12px; border-bottom:1px solid var(--blue); }
  .pair .q { border-right:1px solid var(--blue); }
  .pair .q strong, .doc-row strong { display:block; margin-bottom:8px; font-size:13.5px; line-height:1.4; }
  .docs { border-top:1px solid var(--blue); }
  .doc-row { display:grid; grid-template-columns:1fr 220px; gap:16px; align-items:center; padding:14px 16px; border-bottom:1px solid var(--blue); border-left:1px solid var(--blue); border-right:1px solid var(--blue); }
  .file-btn { position:relative; display:inline-flex; align-items:center; gap:8px; min-height:36px; padding:0 12px; border:1px solid var(--blue); background:#f4f7fc; color:#123; font-weight:700; font-size:13px; cursor:pointer; }
  .file-btn input { position:absolute; inset:0; opacity:0; cursor:pointer; }
  .file-name { display:block; margin-top:6px; font-size:12px; color:#555; word-break:break-word; }
  .file-name.is-set { color:#0b3d91; font-weight:700; }
  button[type="submit"] { display:block; width:calc(100% - 32px); margin:16px 16px 20px; background:#111; color:#fff; border:0; height:48px; font-weight:700; letter-spacing:.06em; cursor:pointer; }
  button[type="submit"]:hover { background:#333; }
  .err { margin:12px 18px 0; background:#fde8e8; border:1px solid #e7b4b4; color:#8a1f1f; padding:10px 12px; font-size:14px; }
  .ok { padding:28px 22px 32px; text-align:center; }
  .ok h2 { margin:0 0 8px; }
  .ref { display:inline-block; margin:10px 0; padding:10px 14px; background:#f4f4f4; border:1px solid #ddd; font-family:Consolas, monospace; font-size:18px; letter-spacing:.04em; }
  .ok a { color:#1a3f86; }
  @media (max-width:720px) {
    .eci-top { flex-direction:column; }
    .logo { width:100%; flex-basis:auto; padding:10px; }
    table.form, table.form tbody, table.form tr, table.form td { display:block; width:100%; }
    table.form .lbl { width:auto; border-bottom:0; }
    .ethnic { grid-template-columns:1fr; }
    .brand h1 { font-size:18px; }
    .pair, .doc-row { grid-template-columns:1fr; }
    .pair .q { border-right:0; border-bottom:0; }
  }
</style>
</head>
<body>
<div class="sheet">
  <div class="eci-top">
    <div class="logo">
      <strong>ECI</strong>
      <small>EUROPEAN COLLEGE<br>OF INNOVATION</small>
    </div>
    <div class="brand">
      <h1>EUROPEAN COLLEGE OF INNOVATION</h1>
      <p>STUDENT APPLICATION FORM</p>
    </div>
  </div>

  <?php if ($doneRef !== ''): ?>
  <div class="ok">
    <h2>Application received</h2>
    <p><?php if ($eciMailSent): ?>This application and the supporting documents were emailed to info@eci.com.mt, with a copy to infos@visaconsultantcanada.com.<?php else: ?>The application is saved. The email to info@eci.com.mt could not be sent, so please forward the form and documents.<?php endif; ?><?php if ($doneEmail !== ''): ?> A confirmation was sent to <?= eci_h($doneEmail) ?>.<?php endif; ?></p>
    <div class="ref"><?= eci_h($doneRef) ?></div>
    <p>Application ID: <?= eci_h($doneUser) ?></p>
    <?php if ($isStaffAdd): ?>
    <p><a href="eci-malta-applications.php">Back to ECI applications</a></p>
    <?php else: ?>
    <p><a href="eci-malta-application.php?new=1">Submit another application</a></p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <?php if ($error !== ''): ?><div class="err"><?= eci_h($error) ?> Please choose the documents again.</div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" autocomplete="on">
    <?php if ($isStaffAdd): ?><input type="hidden" name="staff_add" value="1"><?php endif; ?>
    <div class="intro">
      <h2>Student Application Form <span>(Please complete this form in BLOCK CAPITALS)</span></h2>
      <p class="mail">When you submit, this form and the attached documents are emailed to info@eci.com.mt, with a copy to infos@visaconsultantcanada.com.</p>
    </div>
    <p class="via">This copy is submitted through Parrot Canada Visa Consultant. The college layout is kept so the application matches the ECI form.</p>

    <h3 class="bar">YOUR COURSE</h3>
    <table class="form">
      <tr>
        <td class="lbl">Level &amp; Title of course</td>
        <td>
          <select class="course" id="course_title" name="course_title" required>
            <option value="">Select a one-year programme</option>
            <optgroup label="MQF Level 5">
              <?php foreach ($courses as $key => $courseOption): if ($courseOption['level'] !== '5') continue; ?>
              <option value="<?= eci_h($key) ?>" data-level="5"<?= (string) ($_POST['course_title'] ?? '') === $key ? ' selected' : '' ?>><?= eci_h($courseOption['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MQF Level 7">
              <?php foreach ($courses as $key => $courseOption): if ($courseOption['level'] !== '7') continue; ?>
              <option value="<?= eci_h($key) ?>" data-level="7"<?= (string) ($_POST['course_title'] ?? '') === $key ? ' selected' : '' ?>><?= eci_h($courseOption['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
          <div class="reqs" id="course-reqs">
            <strong>Entry requirements</strong>
            <p data-level="5"><span class="lvl">MQF Level 5:</span> Senior High School Certificate / Senior Secondary School Certificate / National Diploma / Diploma / WASSCE, WAEC, KCSE, Higher Diploma holders only.</p>
            <p data-level="7"><span class="lvl">MQF Level 7:</span> Bachelor’s Degree, Postgraduate Diploma, or Master’s Degree.</p>
            <p class="equiv">All Level 7 Diploma programmes are equivalent to a Master’s degree, while Level 5 Diplomas are equivalent to a Higher National Diploma (HND).</p>
          </div>
        </td>
        <td>
          <div>Month/Year of entry</div>
          <div class="checks" style="margin-top:6px">
            <?php foreach ($months as $key => $label): ?>
            <label><input type="checkbox" name="entry_month" value="<?= eci_h($key) ?>"<?= $checked('entry_month', $key) ?>> <?= eci_h($label) ?></label>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:8px">Year <input class="yearbox" type="text" name="entry_year" inputmode="numeric" maxlength="4" required value="<?= $v('entry_year') ?>"></div>
        </td>
      </tr>
      <tr>
        <td class="lbl">Mode of Study</td>
        <td colspan="2">
          <div class="checks">
            <?php foreach ($modes as $key => $label): ?>
            <label><input type="checkbox" name="study_mode" value="<?= eci_h($key) ?>"<?= $checked('study_mode', $key) ?>> <?= eci_h($label) ?></label>
            <?php endforeach; ?>
          </div>
        </td>
      </tr>
    </table>

    <h3 class="bar">PERSONAL DETAILS</h3>
    <table class="form">
      <tr>
        <td class="lbl">Surname/Family name</td>
        <td><input type="text" name="last_name" required maxlength="120" autocomplete="family-name" value="<?= $v('last_name') ?>"></td>
        <td class="lbl">Forename(s)</td>
        <td><input type="text" name="first_name" required maxlength="120" autocomplete="given-name" value="<?= $v('first_name') ?>"></td>
        <td class="lbl">Title</td>
        <td><input type="text" name="title" maxlength="30" value="<?= $v('title') ?>"></td>
      </tr>
      <tr>
        <td colspan="6"><span class="hint">(these should be the names you are formally known by as they will be used on your certificate)</span></td>
      </tr>
      <tr>
        <td class="lbl">Gender</td>
        <td colspan="2">
          <div class="checks">
            <label><input type="checkbox" name="gender" value="Male"<?= $checked('gender', 'Male') ?>> Male</label>
            <label><input type="checkbox" name="gender" value="Female"<?= $checked('gender', 'Female') ?>> Female</label>
          </div>
        </td>
        <td class="lbl">Previous family name (if changed)</td>
        <td colspan="2"><input type="text" name="previous_family_name" maxlength="120" value="<?= $v('previous_family_name') ?>"></td>
      </tr>
      <tr>
        <td class="lbl">Date of birth</td>
        <td colspan="2"><input type="date" name="date_of_birth" required value="<?= $v('date_of_birth') ?>"></td>
        <td class="lbl">Nationality</td>
        <td colspan="2"><input type="text" name="nationality" required maxlength="80" value="<?= $v('nationality') ?>"></td>
      </tr>
      <tr>
        <td class="lbl">Is English your first language or the language you were educated in?</td>
        <td>
          <div class="checks">
            <label><input type="checkbox" name="english_first" value="yes"<?= $checked('english_first', 'yes') ?>> Yes</label>
            <label><input type="checkbox" name="english_first" value="no"<?= $checked('english_first', 'no') ?>> No</label>
          </div>
        </td>
        <td colspan="4" class="note">If No, please attach a copy of your English language qualification certificate/s.</td>
      </tr>
      <tr>
        <td class="lbl">Do you have any disabilities which may affect your studies?</td>
        <td>
          <div class="checks">
            <label><input type="checkbox" name="disability" value="yes"<?= $checked('disability', 'yes') ?>> Yes</label>
            <label><input type="checkbox" name="disability" value="no"<?= $checked('disability', 'no') ?>> No</label>
          </div>
        </td>
        <td colspan="4" class="note">ECI will contact you to discuss this further if applicable.</td>
      </tr>
      <tr>
        <td class="lbl">Have you previously studied at ECI?</td>
        <td>
          <div class="checks">
            <label><input type="checkbox" name="studied_before" value="yes"<?= $checked('studied_before', 'yes') ?>> Yes</label>
            <label><input type="checkbox" name="studied_before" value="no"<?= $checked('studied_before', 'no') ?>> No</label>
          </div>
        </td>
        <td colspan="4"><input type="text" name="studied_details" maxlength="255" value="<?= $v('studied_details') ?>"></td>
      </tr>
      <tr>
        <td class="lbl">Permanent Address</td>
        <td colspan="2"><textarea name="permanent_address" required maxlength="1000"><?= $v('permanent_address') ?></textarea></td>
        <td class="lbl">Correspondence Address (if different)</td>
        <td colspan="2"><textarea name="correspondence_address" maxlength="1000"><?= $v('correspondence_address') ?></textarea></td>
      </tr>
      <tr>
        <td class="lbl">Your phone/mobile number</td>
        <td colspan="2"><input type="tel" name="phone" required maxlength="40" autocomplete="tel" value="<?= $v('phone') ?>"></td>
        <td class="lbl">Your WhatsApp / Skype</td>
        <td colspan="2"><input type="text" name="whatsapp" maxlength="80" value="<?= $v('whatsapp') ?>"></td>
      </tr>
      <tr>
        <td class="lbl">Your Linkedin profile</td>
        <td colspan="5"><input type="url" name="linkedin" maxlength="255" value="<?= $v('linkedin') ?>"></td>
      </tr>
      <tr>
        <td class="lbl">Your email address</td>
        <td colspan="5"><input type="email" name="email" required maxlength="150" autocomplete="email" value="<?= $v('email') ?>"></td>
      </tr>
    </table>

    <h3 class="bar">QUALIFICATIONS</h3>
    <p class="note" style="margin:8px 12px">Please list all academic and professional qualifications starting with your highest level qualification and attach a copy of your qualification certificate which demonstrated that you meet the entry requirements</p>
    <table class="quals">
      <thead>
        <tr>
          <th>Qualification</th>
          <th>Grades/Percentage</th>
          <th>Completion date</th>
          <th>Name of educational establishment</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < 6; $i++): ?>
        <tr>
          <td><input type="text" name="qual_name[]" maxlength="180" value="<?= $qualValue('qual_name', $i) ?>"></td>
          <td><input type="text" name="qual_grade[]" maxlength="40" value="<?= $qualValue('qual_grade', $i) ?>"></td>
          <td><input type="text" name="qual_date[]" maxlength="40" value="<?= $qualValue('qual_date', $i) ?>"></td>
          <td><input type="text" name="qual_school[]" maxlength="180" value="<?= $qualValue('qual_school', $i) ?>"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <h3 class="bar">FURTHER INFORMATION</h3>
    <table class="form">
      <tr>
        <td colspan="2">Do you have any criminal convictions (excluding a motoring offence for which a fine and/or a maximum of three penalty points were imposed) or a spent conviction?</td>
      </tr>
      <tr>
        <td colspan="2">
          <div class="checks">
            <label><input type="checkbox" name="criminal_conviction" value="yes"<?= $checked('criminal_conviction', 'yes') ?>> Yes (ECI will contact you for further details)</label>
            <label><input type="checkbox" name="criminal_conviction" value="no"<?= $checked('criminal_conviction', 'no') ?>> No</label>
          </div>
        </td>
      </tr>
    </table>
    <div style="padding:8px 12px 0;font-weight:700;font-size:13px">What is your ethnic origin?</div>
    <div class="ethnic">
      <?php foreach ($ethnicOptions as $option): ?>
      <label><input type="radio" name="ethnic_origin" value="<?= eci_h($option) ?>"<?= $checked('ethnic_origin', $option) ?> required> <?= eci_h($option) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="pair">
      <div class="q">
        <strong>Any English Language Qualification/Test (IELTS or Equivalent)?</strong>
        <div class="checks">
          <label><input type="checkbox" name="english_test" value="yes"<?= $checked('english_test', 'yes') ?>> Yes</label>
          <label><input type="checkbox" name="english_test" value="no"<?= $checked('english_test', 'no') ?>> No</label>
        </div>
      </div>
      <div class="a">
        <strong style="display:block;margin-bottom:8px">If yes, overall result?</strong>
        <input type="text" name="english_result" maxlength="80" value="<?= $v('english_result') ?>">
      </div>
    </div>
    <div class="pair">
      <div class="q">
        <strong>Are you applying through an agent or educational representative?</strong>
        <div class="checks">
          <label><input type="checkbox" name="via_agent" value="yes"<?= $checked('via_agent', 'yes') ?>> Yes</label>
          <label><input type="checkbox" name="via_agent" value="no"<?= $checked('via_agent', 'no') ?>> No</label>
        </div>
      </div>
      <div class="a">
        <strong style="display:block;margin-bottom:8px">Agent name / email</strong>
        <input type="text" name="agent_details" maxlength="255" value="<?= $v('agent_details') ?>">
      </div>
    </div>

    <h3 class="bar">DECLARATION</h3>
    <div class="decl">
      <label><input type="checkbox" name="declare[]" value="1"<?= in_array('1', (array) ($_POST['declare'] ?? []), true) ? ' checked' : '' ?>> I understand that the information given on this form will be treated in strictest confidence and I consent to the details being used for ECI and Institute internal record-keeping purposes and procedures.</label>
      <label><input type="checkbox" name="declare[]" value="2"<?= in_array('2', (array) ($_POST['declare'] ?? []), true) ? ' checked' : '' ?>> I confirm that the information I have given in this form is true and accurate.</label>
      <label><input type="checkbox" name="declare[]" value="3"<?= in_array('3', (array) ($_POST['declare'] ?? []), true) ? ' checked' : '' ?>> I understand that giving false information will forfeit my right to the award.</label>
      <label><input type="checkbox" name="declare[]" value="4"<?= in_array('4', (array) ($_POST['declare'] ?? []), true) ? ' checked' : '' ?>> I understand that the course fees are neither transferable nor refundable once submitted.</label>
    </div>
    <p class="regs">We take your participation in this programme as evidence of having read and made a commitment to abiding by the course regulations.</p>
    <table class="form sign">
      <tr>
        <td><span class="cap">Signature</span><input type="text" name="signature_name" required maxlength="200" value="<?= $v('signature_name') ?>"></td>
        <td><span class="cap">Date</span><input type="date" name="signature_date" required value="<?= $v('signature_date') !== '' ? $v('signature_date') : eci_h($today) ?>"></td>
      </tr>
    </table>

    <h3 class="bar">NEXT STEP</h3>
    <p class="note" style="margin:10px 16px 0">Tick Yes when you are attaching the document. PDF, JPG, PNG, DOC, or DOCX. Maximum 8 MB each.</p>
    <div class="docs">
      <?php
      $docRows = [
          ['passport', 'attach_passport', 'Passport/ID', 'Attach a copy of your current passport or ID.', '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*'],
          ['transcripts', 'attach_transcripts', 'Academic transcripts', 'All transcripts and degrees from your education since the age of 16.', '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*'],
          ['cv', 'attach_cv', 'Work experience / CV', 'Jobs held since the age of 18. A CV is acceptable.', '.pdf,.doc,.docx,application/pdf'],
          ['english_file', 'attach_english', 'English language evidence', 'IELTS or an equivalent certificate, if you have one.', '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*'],
          ['photo', 'attach_photo', 'Passport photo', 'A recent passport-sized photograph.', '.jpg,.jpeg,.png,.webp,.pdf,image/*'],
      ];
      foreach ($docRows as $doc):
      ?>
      <div class="doc-row">
        <div>
          <strong><?= eci_h($doc[2]) ?></strong>
          <span class="note"><?= eci_h($doc[3]) ?></span>
        </div>
        <div>
          <div class="checks">
            <label><input type="checkbox" name="<?= eci_h($doc[1]) ?>" value="yes"<?= $checked($doc[1], 'yes') ?>> Yes</label>
            <label><input type="checkbox" name="<?= eci_h($doc[1]) ?>" value="no"<?= $checked($doc[1], 'no') ?>> No</label>
          </div>
          <label class="file-btn">Choose file
            <input type="file" name="<?= eci_h($doc[0]) ?>" accept="<?= eci_h($doc[4]) ?>">
          </label>
          <span class="file-name" data-for="<?= eci_h($doc[0]) ?>">No file chosen</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="mail" style="padding:0 12px">Submitting this form emails the application and supporting documents to info@eci.com.mt, with a copy to infos@visaconsultantcanada.com.</p>
    <button type="submit"><?= $isStaffAdd ? 'SAVE APPLICATION' : 'SUBMIT APPLICATION' ?></button>
  </form>
  <script>
  document.querySelectorAll('input[type="checkbox"][name]').forEach(function (box) {
    box.addEventListener('change', function () {
      if (!box.checked) return;
      var group = box.form.querySelectorAll('input[type="checkbox"][name="' + box.name + '"]');
      if (group.length < 2) return;
      group.forEach(function (other) { if (other !== box) other.checked = false; });
    });
  });
  var courseSelect = document.getElementById('course_title');
  if (courseSelect) {
    var syncCourseReqs = function () {
      var opt = courseSelect.options[courseSelect.selectedIndex];
      var level = opt ? (opt.getAttribute('data-level') || '') : '';
      document.querySelectorAll('#course-reqs [data-level]').forEach(function (row) {
        row.classList.toggle('is-on', row.getAttribute('data-level') === level);
      });
    };
    courseSelect.addEventListener('change', syncCourseReqs);
    syncCourseReqs();
  }
  document.querySelectorAll('.file-btn input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = document.querySelector('.file-name[data-for="' + input.name + '"]');
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

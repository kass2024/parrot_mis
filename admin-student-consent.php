<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/student_consent.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$viewer = pcvc_application_report_viewer($conn);
$adminId = $viewer['id'];
$superadmin = (int) pcvc_is_superadmin_role($viewer['role']);
if ($adminId < 1 || (!$superadmin && !pcvc_is_staff_role($viewer['role']))) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$_SESSION['student_consent_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['student_consent_csrf'];
$language = ($_GET['lang'] ?? '') === 'fr' ? 'fr' : 'en';
$languageName = $language === 'fr' ? 'French' : 'English';
$issuerPath = 'admin-student-consent.php' . ($language === 'fr' ? '?lang=fr' : '');
$h = 'student_consent_escape';
$error = '';
$search = '';
$matches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($csrf, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Reload the form and try again.');
    }

    if (($_POST['action'] ?? '') === 'search') {
        $search = is_string($_POST['search'] ?? null) ? trim($_POST['search']) : '';
        if (mb_strlen($search) < 2 || mb_strlen($search) > 100) {
            $error = 'Search using 2 to 100 characters.';
        } else {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM student_applications
                WHERE TRIM(CONCAT_WS(' ', first_name, last_name)) LIKE ? OR email LIKE ?
                ORDER BY id DESC LIMIT 40");
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (pcvc_viewer_can_see_application($conn, (int) $row['id'], $viewer)) {
                    $matches[] = $row;
                }
            }
            $stmt->close();
        }
    } elseif (($_POST['action'] ?? '') === 'issue') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $details = [];
        if ($studentId === false || ($studentId && !pcvc_viewer_can_see_application($conn, $studentId, $viewer))) {
            $error = 'This student is unavailable.';
        } elseif ($studentId) {
            $stmt = $conn->prepare('SELECT s.first_name, s.last_name, s.email, s.phone_number, s.dob, s.nationality,
                s.passport_number, u.name AS institution, p.program_name
                FROM student_applications s
                LEFT JOIN application_study_choices c ON c.application_id = s.id
                LEFT JOIN universities u ON u.id = COALESCE(c.university_id, s.university_id)
                LEFT JOIN programs p ON p.id = c.program_id
                WHERE s.id = ? ORDER BY c.id LIMIT 1');
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$student) {
                $error = 'This student is unavailable.';
            } else {
                $dob = (string) ($student['dob'] ?? '');
                $details = [
                    'student_name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                    'date_of_birth' => student_consent_date_is_valid($dob) ? $dob : '',
                    'passport_id' => trim((string) $student['passport_number']),
                    'nationality' => trim((string) $student['nationality']),
                    'email' => trim((string) $student['email']),
                    'phone' => trim((string) $student['phone_number']),
                    'institution_program' => implode(' — ', array_filter([$student['institution'], $student['program_name']])),
                ];
            }
        }
        if ($error === '') {
            $studentId = $studentId ?: null;
            $token = bin2hex(random_bytes(32));
            $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $stmt = $conn->prepare('INSERT INTO student_consent_authorizations
                (contract_token, language, student_application_id, issued_by, form_data) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('ssiis', $token, $language, $studentId, $adminId, $json);
            $stmt->execute();
            $issuedId = (int) $conn->insert_id;
            $stmt->close();
            header('Location: admin-student-consent.php?' . http_build_query(['lang' => $language, 'issued' => $issuedId]), true, 303);
            exit;
        }
    }
}

$issuedId = filter_var($_GET['issued'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$issuedLink = '';
if ($issuedId) {
    $stmt = $conn->prepare('SELECT contract_token FROM student_consent_authorizations
        WHERE id = ? AND language = ? AND (issued_by = ? OR ? = 1)');
    $stmt->bind_param('isii', $issuedId, $language, $adminId, $superadmin);
    $stmt->execute();
    $issued = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($issued) {
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $issuedLink = $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/student-consent.php?token=' . $issued['contract_token'];
    }
}

$stmt = $conn->prepare("SELECT contract_token, signed_at, created_at,
    JSON_UNQUOTE(JSON_EXTRACT(form_data, '$.student_name')) AS student_name
    FROM student_consent_authorizations WHERE language = ? AND (issued_by = ? OR ? = 1)
    ORDER BY id DESC LIMIT 25");
$stmt->bind_param('sii', $language, $adminId, $superadmin);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Consent (<?= $h($languageName) ?>)</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; padding: 24px 14px; background: #f4f5f7; color: #1d2633; font: 15px/1.5 system-ui, sans-serif; }
main { max-width: 800px; margin: auto; padding: 24px; background: #fff; border: 1px solid #e2e4e8; border-radius: 12px; }
h1 { font-size: 23px; margin: 0 0 12px; }
h2 { font-size: 17px; margin: 24px 0 10px; }
a { color: #911423; }
nav { display: flex; gap: 14px; margin-bottom: 18px; }
form { margin: 8px 0; }
button { padding: 8px 12px; border: 0; border-radius: 6px; background: #911423; color: #fff; font: inherit; cursor: pointer; }
input { padding: 8px; border: 1px solid #bdc3cc; border-radius: 5px; font: inherit; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.row input { flex: 1; min-width: min(220px, 100%); }
label { display: block; margin: 0 0 6px; font-weight: 600; }
.notice { padding: 12px; background: #f8f1f2; border: 1px solid #e9cfd2; border-radius: 6px; }
.record { padding: 8px 0; border-bottom: 1px solid #e2e4e8; }
.record span { overflow-wrap: anywhere; }
small { color: #4c5562; }
</style>
</head>
<body>
<main>
  <h1>Student Consent (<?= $h($languageName) ?>)</h1>
  <nav aria-label="Language">
    <a href="admin-student-consent.php" <?= $language === 'en' ? 'aria-current="page"' : '' ?>>English</a>
    <a href="admin-student-consent.php?lang=fr" <?= $language === 'fr' ? 'aria-current="page"' : '' ?>>French</a>
  </nav>
  <?php if ($error !== ''): ?><p class="notice" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <?php if ($issuedLink !== ''): ?>
  <section class="notice">
    <label for="consent-link">Signing link</label>
    <div class="row">
      <input id="consent-link" type="text" readonly value="<?= $h($issuedLink) ?>">
      <button type="button" id="copy-link">Copy link</button>
      <a href="<?= $h($issuedLink) ?>" target="_blank" rel="noopener noreferrer">Open form</a>
    </div>
    <span id="copy-status" role="status"></span>
  </section>
  <?php endif; ?>

  <h2>Use an existing student record</h2>
  <form method="post" action="<?= $h($issuerPath) ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <label for="search">Student name or email</label>
    <div class="row">
      <input id="search" name="search" type="search" minlength="2" maxlength="100" required value="<?= $h($search) ?>">
      <button name="action" value="search">Search</button>
    </div>
  </form>
  <?php foreach ($matches as $result): ?>
    <form method="post" action="<?= $h($issuerPath) ?>" class="row record">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="student_id" value="<?= (int) $result['id'] ?>">
      <span><?= $h(trim($result['first_name'] . ' ' . $result['last_name'])) ?> · <?= $h((string) $result['email']) ?></span>
      <button name="action" value="issue">Issue prefilled link</button>
    </form>
  <?php endforeach; ?>
  <?php if ($search !== '' && !$matches && $error === ''): ?><p>No matching student records.</p><?php endif; ?>

  <h2>Issue a blank form</h2>
  <p>The student can complete and sign the form from the link.</p>
  <form method="post" action="<?= $h($issuerPath) ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="student_id" value="0">
    <button name="action" value="issue">Issue blank link</button>
  </form>

  <?php if ($recent): ?>
  <h2>Recent consent forms</h2>
  <?php foreach ($recent as $record): ?>
    <?php $url = 'student-consent.php?token=' . $record['contract_token']; ?>
    <div class="row record">
      <span><?= $h($record['student_name'] ?: 'Blank form') ?> · <?= $record['signed_at'] ? 'Signed' : 'Pending' ?>
        <small>(<?= $h(substr($record['created_at'], 0, 10)) ?>)</small></span>
      <a href="<?= $h($url) ?>" target="_blank" rel="noopener noreferrer">Open form</a>
      <?php if ($record['signed_at']): ?><a href="<?= $h($url . '&download=1') ?>">Signed PDF</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>
</main>
<?php if ($issuedLink !== ''): ?>
<script>
document.getElementById('copy-link').addEventListener('click', async () => {
    const link = document.getElementById('consent-link');
    const status = document.getElementById('copy-status');
    try {
        await navigator.clipboard.writeText(link.value);
        status.textContent = 'Link copied.';
    } catch {
        link.select();
        status.textContent = document.execCommand('copy') ? 'Link copied.' : 'Copy the selected link.';
    }
});
</script>
<?php endif; ?>
</body>
</html>

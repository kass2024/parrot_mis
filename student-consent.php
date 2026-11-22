<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/student_consent.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    http_response_code(404);
    exit('Consent form link not found.');
}
$stmt = $conn->prepare('SELECT id, language, form_data, signed_at, signing_date, signature_png
    FROM student_consent_authorizations WHERE contract_token = ?');
$stmt->bind_param('s', $token);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$record) {
    http_response_code(404);
    exit('Consent form link not found.');
}

$language = $record['language'];
$copy = student_consent_copy($language);
$details = json_decode($record['form_data'], true) ?: [];
$editable = $record['signed_at'] === null;
$date = $record['signing_date'] ?? student_consent_today();
$signature = $editable ? '' : 'data:image/png;base64,' . base64_encode($record['signature_png']);
$formUrl = 'student-consent.php?token=' . $token;
$h = 'student_consent_escape';
$error = '';
$letterhead = 'assets/student-consent/letterhead.png';
$letterFooter = 'assets/student-consent/letter-footer.png';

if (($_GET['download'] ?? '') === '1') {
    if ($editable) {
        http_response_code(403);
        exit('Sign the consent form to download it.');
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $letterhead = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/' . $letterhead));
    $letterFooter = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/' . $letterFooter));
    ob_start();
    require __DIR__ . '/templates/student-consent-document.php';
    $document = ob_get_clean();
    $styles = file_get_contents(__DIR__ . '/templates/student-consent.css');
    $styles .= 'body{margin:0}.consent-letter-footer{position:fixed;bottom:0;left:0}';
    $options = new Dompdf\Options();
    $options->set('defaultFont', 'Times');
    $pdf = new Dompdf\Dompdf($options);
    $pdf->setPaper('letter');
    $pdf->loadHtml('<!doctype html><html lang="' . $language . '"><head><meta charset="UTF-8"><style>'
        . $styles . '</style></head><body>' . $document . '</body></html>');
    $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="student-consent-' . $language . '-' . (int) $record['id'] . '.pdf"');
    echo $pdf->output();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$editable) {
        header('Location: ' . $formUrl, true, 303);
        exit;
    }
    $details = [];
    foreach (student_consent_fields() as $name => [, $length]) {
        $value = is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
        $details[$name] = $value;
        if ($value === '' || mb_strlen($value) > $length) {
            $error = $copy['missing'];
        }
    }
    if (($_POST['accepted'] ?? null) !== '1') {
        $error = $copy['missing'];
    }
    if ($error === '' && (!filter_var($details['email'], FILTER_VALIDATE_EMAIL)
        || !student_consent_date_is_valid($details['date_of_birth']) || $details['date_of_birth'] > $date)) {
        $error = $copy['invalid'];
    }
    $signatureData = is_string($_POST['signature'] ?? null) ? student_consent_png($_POST['signature']) : null;
    if ($error === '' && $signatureData === null) {
        $error = $copy['draw'];
    }
    if ($error === '') {
        $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare('UPDATE student_consent_authorizations
            SET form_data = ?, signature_png = ?, signing_date = ?, signed_at = UTC_TIMESTAMP()
            WHERE contract_token = ? AND signed_at IS NULL');
        $stmt->bind_param('ssss', $json, $signatureData, $date, $token);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . $formUrl, true, 303);
        exit;
    }
    http_response_code(422);
}
?>
<!doctype html>
<html lang="<?= $h($language) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($copy['title']) ?></title>
<style>
<?php require __DIR__ . '/templates/student-consent.css'; ?>
* { box-sizing: border-box; }
body { margin: 0; background: #f4f5f6; }
main { width: min(612pt, calc(100% - 24px)); min-height: 792pt; margin: 20px auto; padding: 36pt 61.2pt; background: #fff; }
.consent-document { position: relative; min-height: 720pt; padding-bottom: 42pt; }
.consent-letter-footer { position: absolute; bottom: 0; left: 0; }
.consent-input { font: inherit; color: #000; background: #fff; border: 0; border-bottom: 1px solid #555; padding: 2pt 4pt; }
.consent-input:focus-visible { outline: 2px solid #911423; }
#consent-signature { display: block; width: min(240pt, 100%); height: 56pt; margin: 4pt 0; touch-action: none; border: 1px dashed #911423; }
.consent-controls, .consent-status { margin: 12pt 0; font: 14px/1.5 system-ui, sans-serif; }
.consent-status { color: #911423; }
.consent-controls button, #consent-clear, .consent-status a {
  border: 0; border-radius: 4px; padding: 6px 10px; background: #911423; color: #fff; font: inherit; cursor: pointer;
}
.consent-controls button { display: block; margin-top: 8pt; }
.consent-status a { display: inline-block; text-decoration: none; }
@media (max-width: 660px) {
  main { width: 100%; min-height: 0; margin: 0; padding: 20px 16px; }
  .consent-document { min-height: 0; }
  .consent-letterhead, .consent-letter-footer { height: auto; }
  .consent-input { min-width: min(190pt, 100%); font-size: 16px; }
}
@media print {
  body { background: #fff; }
  main { width: auto; min-height: 0; margin: 0; padding: 0; }
  .consent-status, .consent-controls, #consent-clear { display: none; }
  .consent-document { print-color-adjust: exact; }
}
</style>
</head>
<body>
<main>
  <?php if ($error !== ''): ?><p class="consent-status" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <?php if (!$editable): ?>
    <div class="consent-status" role="status"><?= $h($copy['signed']) ?>
      <a href="<?= $h($formUrl . '&download=1') ?>"><?= $h($copy['download']) ?></a>
    </div>
  <?php else: ?>
    <form method="post" id="consent-form" action="<?= $h($formUrl) ?>">
  <?php endif; ?>

  <?php require __DIR__ . '/templates/student-consent-document.php'; ?>

  <?php if ($editable): ?>
    <div class="consent-controls">
      <input type="hidden" name="signature" id="consent-signature-data">
      <label><input type="checkbox" name="accepted" value="1" required> <?= $h($copy['accept']) ?></label>
      <p id="consent-signature-error" class="consent-status" role="alert" hidden><?= $h($copy['draw']) ?></p>
      <button type="submit"><?= $h($copy['sign']) ?></button>
    </div>
    </form>
  <?php endif; ?>
</main>
<?php if ($editable): ?>
<script>
const canvas = document.getElementById('consent-signature');
const ink = canvas.getContext('2d');
const nameInput = document.getElementById('consent-student_name');
const mirrored = document.getElementById('consent-signature-name');
const warning = document.getElementById('consent-signature-error');
let activePointer = null;
let drawn = false;
ink.lineWidth = 3;
ink.lineCap = 'round';
ink.lineJoin = 'round';

function point(event) {
    const bounds = canvas.getBoundingClientRect();
    return [(event.clientX - bounds.left) * canvas.width / bounds.width,
        (event.clientY - bounds.top) * canvas.height / bounds.height];
}

nameInput.addEventListener('input', () => { mirrored.textContent = nameInput.value.trim() || '\u00a0'; });
canvas.addEventListener('pointerdown', event => {
    if (activePointer !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
    canvas.setPointerCapture(event.pointerId);
    activePointer = event.pointerId;
    const [x, y] = point(event);
    ink.beginPath();
    ink.moveTo(x, y);
    ink.lineTo(x + .1, y);
    ink.stroke();
    drawn = true;
    warning.hidden = true;
});
canvas.addEventListener('pointermove', event => {
    if (event.pointerId !== activePointer) return;
    ink.lineTo(...point(event));
    ink.stroke();
});
canvas.addEventListener('lostpointercapture', () => { activePointer = null; });
document.getElementById('consent-clear').addEventListener('click', () => {
    ink.clearRect(0, 0, canvas.width, canvas.height);
    drawn = false;
});
document.getElementById('consent-form').addEventListener('submit', event => {
    if (!drawn) {
        event.preventDefault();
        warning.hidden = false;
        canvas.scrollIntoView({ block: 'center' });
        return;
    }
    document.getElementById('consent-signature-data').value = canvas.toDataURL('image/png');
});
</script>
<?php endif; ?>
</body>
</html>

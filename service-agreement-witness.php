<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

$jsonBody = json_decode((string) file_get_contents('php://input'), true);
if (is_array($jsonBody) && isset($jsonBody['signature'])) {
    header('Content-Type: application/json');
    $token = trim((string) ($jsonBody['token'] ?? ''));
    $role = trim((string) ($jsonBody['role'] ?? ''));
    $witnessToken = trim((string) ($jsonBody['w'] ?? ''));
    pcvc_service_agreement_ensure_schema($conn);
    $row = $token !== '' ? pcvc_service_agreement_by_token($conn, $token) : null;
    if (!is_array($row)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'This contract link is not valid.']);
        exit;
    }
    $result = pcvc_service_agreement_save_witness($conn, $row, $role, $witnessToken, [
        'name' => (string) ($jsonBody['name'] ?? ''),
        'id' => (string) ($jsonBody['id'] ?? ''),
        'phone' => (string) ($jsonBody['phone'] ?? ''),
        'date' => (string) ($jsonBody['date'] ?? ''),
        'signature' => (string) ($jsonBody['signature'] ?? ''),
    ]);
    if (empty($result['ok'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => (string) ($result['error'] ?? 'Could not save the signature.')]);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

$token = trim((string) ($_REQUEST['token'] ?? ''));
$role = trim((string) ($_REQUEST['role'] ?? ''));
$witnessToken = trim((string) ($_REQUEST['w'] ?? ''));
$roles = pcvc_cedrick_witness_roles();
pcvc_service_agreement_ensure_schema($conn);
$row = $token !== '' ? pcvc_service_agreement_by_token($conn, $token) : null;
$spec = $roles[$role] ?? null;
$annex = [];
if (is_array($row)) {
    $decoded = json_decode((string) ($row['annex_json'] ?? ''), true);
    $annex = is_array($decoded) ? $decoded : [];
}
$valid = is_array($row) && is_array($spec) && $witnessToken !== '' && hash_equals((string) ($annex[$spec['token']] ?? ''), $witnessToken);
$error = '';
$done = false;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = pcvc_service_agreement_save_witness($conn, $row, $role, $witnessToken, [
        'name' => (string) ($_POST['name'] ?? ''),
        'id' => (string) ($_POST['national_id'] ?? ''),
        'phone' => (string) ($_POST['phone'] ?? ''),
        'date' => (string) ($_POST['when'] ?? ''),
        'signature' => (string) ($_POST['signature'] ?? ''),
    ]);
    if (!empty($result['ok'])) {
        $done = true;
    } else {
        $error = (string) ($result['error'] ?? 'Could not save the signature.');
        $annex[$spec['name']] = (string) ($_POST['name'] ?? '');
        $annex[$spec['id']] = (string) ($_POST['national_id'] ?? '');
        $annex[$spec['phone']] = (string) ($_POST['phone'] ?? '');
        $annex[$spec['date']] = (string) ($_POST['when'] ?? '');
    }
}

$client = '';
if (is_array($row)) {
    $client = trim((string) ($row['representative_name'] ?? ''));
    if ($client === '') {
        $client = trim((string) ($row['company_name'] ?? ''));
    }
}
$val = static function (string $key) use ($annex): string {
    return htmlspecialchars((string) ($annex[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Witness signature</title>
<style>
body { margin: 0; background: #e8eef5; color: #0f172a; font-family: "Segoe UI", sans-serif; }
.wrap { max-width: 640px; margin: 28px auto; padding: 0 14px 40px; }
.card { background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 10px 28px rgba(15,23,42,.08); }
h1 { font-size: 22px; margin: 0 0 6px; }
p { line-height: 1.45; }
label { display: block; font-weight: 700; font-size: 13px; margin: 12px 0 4px; }
input[type="text"] { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font: inherit; }
canvas { width: 100%; height: 160px; border: 2px dashed #166534; border-radius: 10px; background: #f0fdf4; touch-action: none; cursor: crosshair; }
.hint { color: #166534; font-size: 13px; margin: 4px 0 0; }
button { font: inherit; font-weight: 700; border: 0; border-radius: 8px; cursor: pointer; }
#clear { background: #e2e8f0; color: #0f172a; padding: 8px 12px; margin-top: 8px; }
#submit { background: #166534; color: #fff; width: 100%; padding: 12px 16px; margin-top: 16px; font-size: 16px; }
.err { background: #fef2f2; color: #991b1b; border-radius: 8px; padding: 10px 12px; }
.ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: 10px 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <?php if (!$valid || $spec === null): ?>
      <h1>Link not valid</h1>
      <p>Ask for a new witness email. This link cannot be used.</p>
    <?php elseif ($done): ?>
      <h1>Signature submitted</h1>
      <p class="ok">Thank you. Your signature is on the agreement<?= $client !== '' ? ' for ' . htmlspecialchars($client, ENT_QUOTES, 'UTF-8') : '' ?>.</p>
    <?php else: ?>
      <h1><?= htmlspecialchars($spec['label'], ENT_QUOTES, 'UTF-8') ?></h1>
      <p>Agreement<?= $client !== '' ? ' for <strong>' . htmlspecialchars($client, ENT_QUOTES, 'UTF-8') . '</strong>' : '' ?>. Draw your signature, then press Submit.</p>
      <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      <form method="post" id="form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="w" value="<?= htmlspecialchars($witnessToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="signature" id="signature">
        <label for="name">Full name</label>
        <input id="name" name="name" type="text" required value="<?= $val($spec['name']) ?>">
        <label for="national_id">National ID or passport</label>
        <input id="national_id" name="national_id" type="text" required value="<?= $val($spec['id']) ?>">
        <label for="phone">Telephone or email</label>
        <input id="phone" name="phone" type="text" required value="<?= $val($spec['phone']) ?>">
        <label for="when">Date and place</label>
        <input id="when" name="when" type="text" required value="<?= $val($spec['date']) ?>">
        <label>Signature</label>
        <canvas id="pad"></canvas>
        <p class="hint">Draw signature here</p>
        <button type="button" id="clear">Clear</button>
        <button type="submit" id="submit">Submit</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php if ($valid && !$done && $spec !== null): ?>
<script>
(function () {
  const pad = document.getElementById('pad');
  const ctx = pad.getContext('2d');
  let drawing = false;
  let dirty = false;
  function size() {
    if (dirty) return;
    const rect = pad.getBoundingClientRect();
    const ratio = window.devicePixelRatio || 1;
    pad.width = Math.max(1, rect.width * ratio);
    pad.height = Math.max(1, rect.height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#14532d';
  }
  function pos(e) {
    const r = pad.getBoundingClientRect();
    const p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  }
  function start(e) { drawing = true; dirty = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
  function move(e) { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
  function end() { drawing = false; }
  size();
  pad.addEventListener('mousedown', start);
  pad.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  pad.addEventListener('touchstart', start, { passive: false });
  pad.addEventListener('touchmove', move, { passive: false });
  pad.addEventListener('touchend', end);
  document.getElementById('clear').addEventListener('click', function () {
    ctx.clearRect(0, 0, pad.width, pad.height);
    dirty = false;
    size();
    dirty = false;
  });
  document.getElementById('form').addEventListener('submit', function (e) {
    if (!dirty) {
      e.preventDefault();
      alert('Draw your signature, then press Submit.');
      return;
    }
    document.getElementById('signature').value = pad.toDataURL('image/png');
  });
})();
</script>
<?php endif; ?>
</body>
</html>

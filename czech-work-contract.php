<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_work_contract.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid contract link.');
}

czech_work_contract_ensure_schema($conn);
$stmt = $conn->prepare('SELECT * FROM czech_work_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row)) {
    http_response_code(404);
    exit('This contract link is invalid.');
}
$signed = ($row['status'] ?? '') === 'signed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Czech Republic Work Permit Service Agreement</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; padding: 18px 10px 80px; background: #d9d9d9; }
.sheet { max-width: 8.5in; margin: 0 auto; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
<?= czech_work_contract_css() ?>
.savebar { position: sticky; bottom: 12px; max-width: 860px; margin: 14px auto 0; background: #fff; border: 1px solid #000; padding: 12px 14px; display: flex; gap: 12px; align-items: center; }
.savebar button { background: #000; color: #fff; border: 0; padding: 12px 18px; font-family: "Times New Roman", Times, serif; font-size: 12pt; font-weight: 700; cursor: pointer; }
.savebar button:disabled { background: #888; }
#msg { margin: 0; font-family: "Times New Roman", Times, serif; }
.done { max-width: 860px; margin: 0 auto 12px; background: #f4f8f4; color: #14532d; border: 1px solid #86efac; padding: 10px 12px; font-family: "Times New Roman", Times, serif; }
</style>
</head>
<body>
<?php if ($signed): ?><p class="done">This agreement is signed.</p><?php endif; ?>
<div class="sheet">
<?= czech_work_contract_html($row, !$signed) ?>
</div>
<?php if (!$signed): ?>
<div class="savebar">
  <button type="button" id="save">Sign agreement</button>
  <p id="msg"></p>
</div>
<script>
const token = <?= json_encode($token) ?>;
const pad = document.getElementById('clientPad');
const ctx = pad.getContext('2d');
let drawing = false;
function sizePad() {
  if (pad.dataset.dirty === '1') return;
  const rect = pad.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  pad.width = Math.max(1, rect.width * ratio);
  pad.height = Math.max(1, rect.height * ratio);
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.lineWidth = 2.2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#000';
}
function pos(e) {
  const r = pad.getBoundingClientRect();
  const p = e.touches ? e.touches[0] : e;
  return { x: p.clientX - r.left, y: p.clientY - r.top };
}
pad.addEventListener('pointerdown', (e) => { drawing = true; pad.dataset.dirty = '1'; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
pad.addEventListener('pointermove', (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
window.addEventListener('pointerup', () => { drawing = false; });
sizePad();
window.addEventListener('load', sizePad);
function mirrorSigned() {
  document.querySelectorAll('.cz-mirror').forEach((el) => {
    const src = document.getElementById(el.dataset.from);
    let value = src ? src.value.trim() : '';
    if (src && src.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      const day = new Date(value + 'T00:00:00');
      value = day.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    }
    el.textContent = value || '—';
  });
}
document.querySelectorAll('.cz-field').forEach((el) => el.addEventListener('input', mirrorSigned));
mirrorSigned();
document.getElementById('save').addEventListener('click', async () => {
  const msg = document.getElementById('msg');
  const body = { token };
  let firstBlank = null;
  document.querySelectorAll('.cz-field').forEach((el) => {
    body[el.id] = el.value.trim();
    const empty = body[el.id] === '';
    el.style.borderColor = empty ? '#b91c1c' : '';
    if (empty && !firstBlank) firstBlank = el;
  });
  if (firstBlank) {
    msg.textContent = 'Fill every blank before you sign.';
    firstBlank.focus();
    return;
  }
  if (pad.dataset.dirty !== '1') {
    msg.textContent = 'Draw your signature, then press Sign agreement.';
    return;
  }
  body.signature = pad.toDataURL('image/png');
  const btn = document.getElementById('save');
  btn.disabled = true;
  msg.textContent = 'Saving...';
  try {
    const res = await fetch('submit-czech-contract.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json();
    if (!data.success) {
      msg.textContent = data.error || 'Could not save.';
      btn.disabled = false;
      return;
    }
    msg.textContent = 'Signed.';
    setTimeout(() => location.reload(), 600);
  } catch (e) {
    msg.textContent = 'Could not save. Please try again.';
    btn.disabled = false;
  }
});
</script>
<?php endif; ?>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/irs_agreement_document.php';

irs_agreement_ensure_schema($conn);

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid agreement link.');
}

$stmt = $conn->prepare('SELECT * FROM irs_agreements WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$agreement = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$agreement) {
    http_response_code(404);
    exit('This agreement link is invalid.');
}

$isSigned = ($agreement['status'] ?? '') === 'signed';
$signatureSrc = '';
if ($isSigned) {
    $id = (int) $agreement['id'];
    $sig = $conn->prepare('SELECT signature_image, signed_date FROM irs_agreement_signatures WHERE agreement_id = ? ORDER BY id DESC LIMIT 1');
    $sig->bind_param('i', $id);
    $sig->execute();
    $sigRow = $sig->get_result()->fetch_assoc();
    $sig->close();
    if ($sigRow) {
        $agreement['signed_date'] = $sigRow['signed_date'] ?? '';
        $signatureSrc = (string) ($sigRow['signature_image'] ?? '');
    }
} else {
    $today = date('Y-m-d');
    if (trim((string) ($agreement['effective_date'] ?? '')) === '') {
        $agreement['effective_date'] = $today;
    }
    if (trim((string) ($agreement['signed_date'] ?? '')) === '') {
        $agreement['signed_date'] = $today;
    }
}
$agreement['client_signature_src'] = $signatureSrc;
$agreement['parrot_signature_src'] = 'admin/signature-manager.png';
$agreement['company_stamp_src'] = 'admin/employer-signature.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Immigration Records Agreement | Parrot Canada Visa Consultant</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --green:#1f6b34; --ink:#1c241c; --line:#d7e3d7; }
* { box-sizing: border-box; }
body { margin: 0; background: #eef3ee; color: var(--ink); font-family: "Segoe UI", system-ui, sans-serif; font-size: 17px; line-height: 1.55; }
.wrap { max-width: 860px; margin: 0 auto; padding: 12px 12px 108px; }
.paper { background: #fff; border-radius: 16px; padding: 18px 14px 22px; box-shadow: 0 8px 28px rgba(20,40,20,.08); }
.irs-doc h1 { font-size: 1.35rem; line-height: 1.3; text-align: center; margin: 8px 0 14px; }
.irs-brand { text-align: center; font-weight: 800; letter-spacing: .04em; font-size: .92rem; margin: 0; color: var(--green); }
.irs-doc h2 { font-size: 1.08rem; margin: 1.15rem 0 .4rem; color: var(--green); }
.irs-doc p { margin: 0 0 .75rem; }
.irs-doc strong { font-weight: 700; }
.irs-in { display: block; width: 100%; margin: 4px 0 10px; padding: 12px 12px; font: inherit; font-size: 16px; border: 1px solid var(--line); border-radius: 10px; background: #f7fbf7; color: var(--ink); }
.irs-in:focus { outline: 2px solid #8fbf96; border-color: var(--green); background: #fff; }
.irs-mirror { font-weight: 700; color: #0f3d18; }
.irs-doc table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
.irs-doc th, .irs-doc td { border: 1px solid #c9d6c9; padding: 8px; text-align: left; vertical-align: top; }
.irs-sign td { width: 50%; padding: 12px; }
.irs-doc img { max-width: 100%; height: auto; }
.signature-canvas { width: 100%; height: 170px; background: #fff; border: 2px dashed var(--green); border-radius: 12px; touch-action: none; display: block; margin-top: 6px; }
.sign-tools { display: flex; gap: 8px; margin-top: 8px; }
.sign-bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 1px solid var(--line); padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); display: flex; gap: 8px; }
.sign-bar button, .btn { font: inherit; font-size: 16px; font-weight: 700; border: 0; border-radius: 12px; padding: 14px 16px; cursor: pointer; }
.sign-bar .primary, .btn.primary { background: var(--green); color: #fff; flex: 1; }
.sign-bar .ghost, .btn.ghost { background: #e7f3e8; color: var(--green); }
.done { margin-top: 16px; background: #f7fbf7; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.done a { display: inline-block; margin-top: 8px; background: var(--green); color: #fff; text-decoration: none; font-weight: 700; border-radius: 12px; padding: 12px 16px; }
.msg { margin: 8px 0 0; font-weight: 700; }
@media (min-width: 760px) {
  body { font-size: 17.5px; }
  .wrap { padding: 28px 20px 120px; }
  .paper { padding: 32px 36px 36px; }
  .irs-doc h1 { font-size: 1.7rem; }
}
@media (max-width: 700px) {
  .irs-sign, .irs-sign tbody, .irs-sign tr, .irs-sign td { display: block; width: 100% !important; }
  .irs-doc th, .irs-doc td { font-size: 15px; }
}
</style>
</head>
<body>
<div class="wrap">
  <?php if ($isSigned): ?>
    <div class="paper"><?= irs_agreement_document_html($agreement, false, false) ?></div>
    <div class="done">
      <p>This agreement is signed.</p>
      <a href="download-irs-agreement.php?token=<?= urlencode($token) ?>">Download signed PDF</a>
    </div>
  <?php else: ?>
    <form id="signForm">
      <div class="paper">
        <?= irs_agreement_document_html($agreement, false, true) ?>
        <p class="msg" id="signMsg"></p>
      </div>
      <div class="sign-bar">
        <button type="button" class="ghost" id="clearSignature">Clear</button>
        <button type="submit" class="primary" id="signContract">Sign agreement</button>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php if (!$isSigned): ?>
<script>
(() => {
  const slot = document.getElementById('irsSignSlot');
  const canvas = document.createElement('canvas');
  canvas.id = 'signature';
  canvas.className = 'signature-canvas';
  slot.appendChild(canvas);
  const ctx = canvas.getContext('2d');
  let drawing = false;
  let snapshot = null;
  function resize(keep) {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    if (keep) snapshot = canvas.toDataURL();
    canvas.width = Math.max(1, rect.width) * ratio;
    canvas.height = Math.max(1, rect.height) * ratio;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#111';
    if (keep && snapshot) {
      const img = new Image();
      img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
      img.src = snapshot;
    }
  }
  resize(false);
  window.addEventListener('resize', () => resize(true));
  const pos = (e) => {
    const r = canvas.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  };
  const start = (e) => { e.preventDefault(); drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
  const move = (e) => { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
  const stop = () => { drawing = false; };
  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', stop);
  canvas.addEventListener('mouseleave', stop);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', stop);
  document.getElementById('clearSignature').addEventListener('click', () => ctx.clearRect(0, 0, canvas.width, canvas.height));
  function pretty(iso) {
    if (!iso) return '—';
    const d = new Date(iso + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }
  let dateTouched = false;
  const signedInput = document.getElementById('signed_date');
  const effectiveInput = document.getElementById('effective_date');
  signedInput.addEventListener('input', () => { dateTouched = true; });
  effectiveInput.addEventListener('input', () => {
    if (!dateTouched) signedInput.value = effectiveInput.value;
  });
  function sync() {
    const name = (document.getElementById('client_name').value || '').trim();
    document.getElementById('irsMirrorName').textContent = name || '—';
    if (!dateTouched && effectiveInput.value && signedInput.value !== effectiveInput.value) {
      signedInput.value = effectiveInput.value;
    }
    document.getElementById('irsMirrorParrotDate').textContent = pretty(effectiveInput.value);
  }
  document.querySelectorAll('.irs-in').forEach(el => el.addEventListener('input', sync));
  sync();
  function hasInk() {
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    for (let i = 3; i < data.length; i += 4) if (data[i] > 0) return true;
    return false;
  }
  document.getElementById('signForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('signMsg');
    if (!hasInk()) { msg.textContent = 'Please draw your signature.'; slot.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    const payload = Object.fromEntries(new FormData(e.target).entries());
    payload.token = <?= json_encode($token) ?>;
    payload.signature = canvas.toDataURL('image/png');
    msg.textContent = 'Saving…';
    const res = await fetch('submit-irs-signature.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({ success: false, error: 'Could not save the signature.' }));
    if (!data.success) { msg.textContent = data.error || 'Could not save the signature.'; return; }
    window.location.reload();
  });
})();
</script>
<?php endif; ?>
</body>
</html>

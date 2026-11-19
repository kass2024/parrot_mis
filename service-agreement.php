<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Invalid contract link.');
}

pcvc_service_agreement_ensure_schema($conn);
$contract = pcvc_service_agreement_by_token($conn, $token);
if (!$contract) {
    http_response_code(404);
    exit('This contract link is invalid or expired.');
}

$editable = (int) $contract['editable_after_sign'] === 1;
$signed = ($contract['status'] ?? '') === 'signed';
$locked = $signed && !$editable;
$isSpecial = ($contract['kind'] ?? '') === 'special';
$specialMeta = $isSpecial ? (pcvc_special_contract_catalog()[(string) ($contract['catalog_key'] ?? '')] ?? []) : [];
$documentLayout = ($specialMeta['layout'] ?? '') === 'document';
$germanStudent = ($contract['kind'] ?? '') === 'german_language';
if ($germanStudent) {
    $documentLayout = true;
}
if ($documentLayout) {
    $locked = false;
}
$activeSigner = 'client';
$witnessToken = '';
if ($documentLayout) {
    $askedRole = trim((string) ($_GET['as'] ?? ''));
    $askedWitness = trim((string) ($_GET['w'] ?? ''));
    $witnessRoles = pcvc_cedrick_witness_roles();
    if (isset($witnessRoles[$askedRole]) && $askedWitness !== '') {
        $annexNow = json_decode((string) ($contract['annex_json'] ?? ''), true);
        $expectedWitness = is_array($annexNow) ? (string) ($annexNow[$witnessRoles[$askedRole]['token']] ?? '') : '';
        if ($expectedWitness !== '' && hash_equals($expectedWitness, $askedWitness)) {
            $activeSigner = $askedRole;
            $witnessToken = $askedWitness;
        }
    }
}
$documentFrench = strtolower((string) ($specialMeta['language'] ?? '')) === 'french';
$isFrench = $documentFrench || (!$isSpecial && strtolower((string) ($contract['language'] ?? 'english')) === 'french');
if ($germanStudent && $isFrench) {
    $documentFrench = true;
}
if ($germanStudent) {
    $title = $isFrench
        ? 'Accord de service pour l\'étudiant en langue allemande'
        : 'German Language Student Service Agreement';
    $subtitle = $isFrench
        ? 'Programmes de langue allemande en présentiel en Allemagne'
        : 'In-person German language study programs in Germany';
} else {
$title = (string) ($specialMeta['page_title'] ?? ($isSpecial
    ? 'B2B Partnership and Service Cooperation Agreement'
    : ($isFrench
        ? 'Accord B2B de partenariat pour les études de langue allemande'
        : 'German Language B2B Partnership and Service Cooperation Agreement')));
$subtitle = (string) ($specialMeta['page_subtitle'] ?? ($isSpecial
    ? 'In-person German language studies in Germany'
    : ($isFrench
        ? 'Programmes de langue allemande en présentiel en Allemagne'
        : 'In-person German language study programs in Germany')));
}
$val = static function (string $key) use ($contract): string {
    return htmlspecialchars((string) ($contract[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="<?= $isFrench ? 'fr' : 'en' ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($documentLayout): ?>
<style>
<?= pcvc_cedrick_document_css() ?>
body.doc-mode { padding: 18px 10px 72px; background: #cfcfcf; }
.paper { width: 8.5in; max-width: calc(100% - 8px); margin: 0 auto; background: #fff; box-sizing: border-box; padding: 0.68in 0.72in; box-shadow: 0 2px 12px rgba(0,0,0,.18); }
.savebar { position: sticky; bottom: 10px; z-index: 5; width: 8.5in; max-width: calc(100% - 8px); margin: 14px auto 0; font-family: "Segoe UI", sans-serif; background: #fff; border: 1px solid #dbe3ee; border-radius: 12px; padding: 12px 14px; box-shadow: 0 10px 28px rgba(15,23,42,.16); display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.savebar button { font-family: "Segoe UI", sans-serif; background: #166534; color: #fff; border: 0; border-radius: 8px; padding: 12px 22px; font-weight: 700; font-size: 16px; cursor: pointer; }
.savebar button:disabled { background: #9ca3af; }
#msg { font-family: "Segoe UI", sans-serif; margin: 0; }
</style>
</head>
<body class="doc-mode">
<article class="paper">
  <?= pcvc_cedrick_document_html($contract, !$locked, $activeSigner) ?>
</article>
<form id="party" hidden>
  <input id="company_name" value="<?= $val('company_name') ?>">
  <input id="representative_name" value="<?= $val('representative_name') ?>">
  <input id="representative_title" value="<?= $val('representative_title') !== '' ? $val('representative_title') : 'Client' ?>">
  <input id="company_address" value="<?= $val('company_address') ?>">
  <input id="company_registration" value="<?= $val('company_registration') ?>">
  <input id="company_email" value="<?= $val('company_email') ?>">
  <input id="company_phone" value="<?= $val('company_phone') ?>">
  <input id="governing_law" value="<?= $val('governing_law') ?>">
  <input id="jurisdiction" value="<?= $val('jurisdiction') ?>">
  <input id="signed_date" type="date" value="<?= $val('signed_date') ?>">
</form>
<?php if (!$locked): ?>
<div class="savebar">
  <button type="button" id="save"><?= $activeSigner === 'client' ? ($documentFrench ? 'Soumettre' : 'Submit') : 'Submit signature' ?></button>
  <div id="msg"></div>
</div>
<script>
const token = <?= json_encode($token) ?>;
const fillBlankMessage = <?= json_encode($documentFrench ? 'Remplissez toutes les cases de l\'accord avant de signer.' : 'Fill every blank on the agreement before you sign.') ?>;
const studentRole = <?= json_encode($documentFrench ? 'Étudiant' : 'Student') ?>;
const drawMessage = <?= json_encode($documentFrench ? 'Dessinez la signature de CCN, puis appuyez sur Soumettre.' : 'Draw the client signature, then press Submit.') ?>;
const drawStudentMessage = <?= json_encode($documentFrench ? 'Dessinez la signature de l\'étudiant, puis appuyez sur Soumettre.' : 'Draw the student signature, then press Submit.') ?>;
const activeSigner = <?= json_encode($activeSigner) ?>;
const witnessToken = <?= json_encode($witnessToken) ?>;
const existingClient = <?= json_encode((string) (($contract['signature_image'] ?? '') !== '' ? $contract['signature_image'] : '')) ?>;
if (!document.getElementById('signed_date').value) {
  document.getElementById('signed_date').value = new Date().toISOString().slice(0, 10);
}
function syncMirrors() {
  document.querySelectorAll('.doc-field').forEach((el) => {
    document.querySelectorAll('.doc-mirror[data-mirror="' + el.id + '"]').forEach((mirror) => {
      mirror.textContent = el.value;
    });
  });
}
document.querySelectorAll('.doc-field').forEach((el) => el.addEventListener('input', syncMirrors));
syncMirrors();
function bindPad(pad) {
  const ctx = pad.getContext('2d');
  let drawing = false;
  function size() {
    if (pad.dataset.dirty === '1') return;
    const rect = pad.getBoundingClientRect();
    if (rect.width < 2 || rect.height < 2) return;
    const ratio = window.devicePixelRatio || 1;
    pad.width = Math.max(1, rect.width * ratio);
    pad.height = Math.max(1, rect.height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#14532d';
  }
  size();
  window.addEventListener('resize', size);
  function pos(e) {
    const r = pad.getBoundingClientRect();
    const p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  }
  function start(e) { drawing = true; pad.dataset.dirty = '1'; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
  function move(e) { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
  function end() { drawing = false; }
  pad.addEventListener('mousedown', start);
  pad.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  pad.addEventListener('touchstart', start, { passive: false });
  pad.addEventListener('touchmove', move, { passive: false });
  pad.addEventListener('touchend', end);
}
document.querySelectorAll('.signpad').forEach(bindPad);
document.querySelectorAll('.sig-clear').forEach((btn) => {
  btn.addEventListener('click', () => {
    const pad = btn.parentElement ? btn.parentElement.querySelector('.signpad') : null;
    if (!pad) return;
    const ctx = pad.getContext('2d');
    ctx.clearRect(0, 0, pad.width, pad.height);
    pad.dataset.dirty = '1';
    const img = btn.parentElement.querySelector('img.doc-sig');
    if (img) img.remove();
  });
});
document.querySelectorAll('.wit-email').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const role = btn.getAttribute('data-role');
    const email = (document.getElementById(role + '_phone') || {}).value || '';
    const status = document.querySelector('.wit-status[data-role="' + role + '"]');
    btn.disabled = true;
    if (status) status.textContent = 'Sending...';
    try {
      const res = await fetch('share-service-agreement-witness.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token,
          role,
          email: email.trim(),
          name: ((document.getElementById(role + '_name') || {}).value || '').trim(),
          id: ((document.getElementById(role + '_id') || {}).value || '').trim(),
          date: ((document.getElementById(role + '_date') || {}).value || '').trim()
        })
      });
      const data = await res.json();
      if (!data.success) {
        if (status) status.textContent = data.error || 'Could not send the email.';
        btn.disabled = false;
        return;
      }
      if (status) status.textContent = 'Sent to ' + data.email + '. They can draw and submit from that email.';
    } catch (e) {
      if (status) status.textContent = 'Could not send the email.';
      btn.disabled = false;
    }
  });
});
document.getElementById('save').addEventListener('click', async () => {
  if (activeSigner !== 'client') {
    const pad = document.querySelector('.signpad');
    const msg = document.getElementById('msg');
    const btn = document.getElementById('save');
    if (!pad || pad.dataset.dirty !== '1') {
      msg.textContent = 'Draw your signature in your box, then press Submit signature.';
      return;
    }
    btn.disabled = true;
    msg.textContent = 'Saving...';
    try {
      const res = await fetch('service-agreement-witness.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token,
          role: activeSigner,
          w: witnessToken,
          name: ((document.getElementById(activeSigner + '_name') || {}).value || '').trim(),
          id: ((document.getElementById(activeSigner + '_id') || {}).value || '').trim(),
          phone: ((document.getElementById(activeSigner + '_phone') || {}).value || '').trim(),
          date: ((document.getElementById(activeSigner + '_date') || {}).value || '').trim(),
          signature: pad.toDataURL('image/png')
        })
      });
      const data = await res.json();
      if (!data.success) {
        msg.textContent = data.error || 'Could not save the signature.';
        btn.disabled = false;
        return;
      }
      msg.textContent = 'Your signature is saved.';
      setTimeout(() => location.reload(), 700);
    } catch (e) {
      msg.textContent = 'Could not save the signature. Please try again.';
      btn.disabled = false;
    }
    return;
  }
  const body = { token };
  ['company_name','representative_name','representative_title','company_address','company_registration','company_email','company_phone','governing_law','jurisdiction','signed_date'].forEach((id) => {
    body[id] = document.getElementById(id).value.trim();
  });
  document.querySelectorAll('.doc-field').forEach((el) => { body[el.id] = el.value.trim(); });
  const studentPad = document.querySelector('[data-sign="student_signature"]');
  const needsEveryField = !!document.getElementById('ccn_rep') || !!document.querySelector('[data-sign="ccn_signature"]') || !!studentPad || !!document.getElementById('student_name');
  if (needsEveryField) {
    let firstBlank = null;
    document.querySelectorAll('.doc-field').forEach((el) => {
      const empty = el.value.trim() === '' && el.dataset.optional !== '1';
      el.style.borderColor = empty ? '#b91c1c' : '';
      el.style.background = empty ? '#fef2f2' : '';
      if (empty && !firstBlank) firstBlank = el;
    });
    if (firstBlank) {
      document.getElementById('msg').textContent = fillBlankMessage;
      firstBlank.focus();
      return;
    }
  }
  document.querySelectorAll('.signpad').forEach((pad) => {
    const key = pad.getAttribute('data-sign');
    const prev = pad.previousElementSibling;
    const kept = prev && prev.tagName === 'IMG' ? prev.getAttribute('src') : '';
    body[key] = pad.dataset.dirty === '1' ? pad.toDataURL('image/png') : (kept || '');
  });
  if (body.ccn_rep) body.representative_name = body.ccn_rep;
  if (body.ccn_title) body.representative_title = body.ccn_title;
  if (body.ccn_contact && body.ccn_contact.indexOf('@') !== -1) body.company_email = body.ccn_contact;
  if (body.ccn_date && /^\d{4}-\d{2}-\d{2}$/.test(body.ccn_date)) body.signed_date = body.ccn_date;
  if (body.student_name) {
    body.company_name = body.student_name;
    body.representative_name = body.student_name;
    body.representative_title = studentRole;
  }
  if (body.email) body.company_email = body.email;
  if (body.phone) body.company_phone = body.phone;
  if (body.address) body.company_address = body.address;
  if (body.student_date && /^\d{4}-\d{2}-\d{2}$/.test(body.student_date)) body.signed_date = body.student_date;
  if (body.company_tin) body.company_registration = body.company_tin;
  if (body.parrot_tin && !body.company_registration) body.company_registration = body.parrot_tin;
  body.signature = body.client_signature || body.ccn_signature || body.student_signature || existingClient;
  const msg = document.getElementById('msg');
  if (!body.signature || !body.signature.startsWith('data:image/png')) {
    msg.textContent = studentPad ? drawStudentMessage : drawMessage;
    return;
  }
  const btn = document.getElementById('save');
  btn.disabled = true;
  msg.textContent = 'Saving...';
  try {
    const res = await fetch('submit-service-agreement.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json();
    if (!data.success) {
      msg.textContent = data.error || 'Could not save.';
      btn.disabled = false;
      return;
    }
    msg.textContent = 'Saved.';
    setTimeout(() => location.reload(), 700);
  } catch (e) {
    msg.textContent = 'Could not save. Please try again.';
    btn.disabled = false;
  }
});
</script>
<?php endif; ?>
<?php else: ?>
<style>
body { margin: 0; padding: 32px 14px 64px; background: #e8eef5; color: #111827; font-family: Georgia, "Times New Roman", serif; }
.page { max-width: 880px; margin: 0 auto; background: #fff; padding: 42px 46px; border-radius: 12px; box-shadow: 0 12px 40px rgba(15,23,42,.08); }
h1 { font-size: 22px; text-align: center; margin: 0 0 6px; }
.sub { text-align: center; font-weight: 700; margin: 0 0 22px; }
h2 { font-size: 16px; margin: 22px 0 8px; }
h3 { font-size: 14px; margin: 14px 0 4px; }
p input.annex-field { border: 0; border-bottom: 1px solid #111; font: inherit; width: min(460px, 100%); padding: 2px 4px; background: #fffef5; }
.banner { background: #ecfdf3; border: 1px solid #86efac; color: #166534; border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; font-family: "Segoe UI", sans-serif; }
.banner.edit { background: #fff7ed; border-color: #fdba74; color: #9a3412; }
form.party { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin: 8px 0 16px; font-family: "Segoe UI", sans-serif; }
label { display: block; font-size: 13px; font-weight: 650; margin-bottom: 4px; }
input { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 10px; font: inherit; }
.wide { grid-column: 1 / -1; }
.sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px; }
.box { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; }
canvas { width: 100%; height: 140px; border: 1px dashed #94a3b8; border-radius: 8px; background: #fff; touch-action: none; }
img.sig { height: 70px; }
button { font-family: "Segoe UI", sans-serif; background: #1d4ed8; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
button.ghost { background: #e5e7eb; color: #111827; margin-right: 8px; }
button:disabled { background: #9ca3af; }
#msg { font-family: "Segoe UI", sans-serif; margin-top: 10px; }
@media (max-width: 720px) {
  .page { padding: 22px 14px; }
  form.party, .sign-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="page">
  <?php if ($locked): ?>
    <div class="banner"><?= $isFrench ? 'Cet accord est signé. Le lien reste ici pour le lire.' : 'This agreement is signed. The link stays here so you can read it.' ?></div>
  <?php elseif ($editable && $signed): ?>
    <div class="banner edit"><?= $isFrench ? 'Cet accord est déjà signé. Vous pouvez encore le corriger sur ce même lien.' : 'This agreement is already signed. You can still correct it on this same link.' ?></div>
  <?php elseif ($editable): ?>
    <div class="banner edit"><?= $isFrench ? 'Ceci est le seul lien de signature de cet accord. Après la signature, vous pouvez encore revenir le modifier.' : 'This is the one sign link for this agreement. After you sign, you can still come back and edit it.' ?></div>
  <?php endif; ?>

  <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="sub"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>

  <?php if (!$documentLayout): ?>
  <h2><?= $isFrench ? '1. Parties' : '1. Parties' ?></h2>
  <p><strong><?= $isFrench ? 'Partie A — Parrot Canada Visa Consultant Co. Ltd.' : 'Party A — Parrot Canada Visa Consultant Co. Ltd.' ?></strong><br>
  <?= $isFrench ? 'Représentée par Dr Jean Pierre Twajamahoro, directeur général / propriétaire' : 'Represented by Dr Jean Pierre Twajamahoro, Managing Director / Owner' ?><br>
  Email: infos@visaconsultantcanada.com · WhatsApp: +1 (431) 302-0226<br>
  <?= $isFrench ? 'Site web' : 'Website' ?>: https://www.visaconsultantcanada.com</p>
  <p><strong><?= $isFrench ? 'Partie B' : 'Party B' ?></strong></p>
  <?php endif; ?>
  <form class="party" id="party" onsubmit="return false;">
    <?php if ($documentLayout): ?>
    <div class="wide"><label for="representative_name">Client full legal name</label><input id="representative_name" value="<?= $val('representative_name') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div class="wide" hidden><label for="company_name">Name</label><input id="company_name" value="<?= $val('company_name') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div hidden><label for="representative_title">Capacity</label><input id="representative_title" value="<?= $val('representative_title') !== '' ? $val('representative_title') : 'Client' ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div class="wide"><label for="company_address">Client address</label><input id="company_address" value="<?= $val('company_address') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_registration">Company registration or TIN</label><input id="company_registration" value="<?= $val('company_registration') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_email">Client email</label><input id="company_email" type="email" value="<?= $val('company_email') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_phone">Client telephone</label><input id="company_phone" value="<?= $val('company_phone') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div hidden><input id="governing_law" value="<?= $val('governing_law') ?>"></div>
    <div hidden><input id="jurisdiction" value="<?= $val('jurisdiction') ?>"></div>
    <div><label for="signed_date">Effective date</label><input id="signed_date" type="date" value="<?= $val('signed_date') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <?php else: ?>
    <div class="wide"><label for="company_name"><?= $isFrench ? 'Nom de la société' : 'Company name' ?></label><input id="company_name" value="<?= $val('company_name') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="representative_name"><?= $isFrench ? 'Représentant' : 'Representative' ?></label><input id="representative_name" value="<?= $val('representative_name') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="representative_title"><?= $isFrench ? 'Fonction' : 'Position' ?></label><input id="representative_title" value="<?= $val('representative_title') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div class="wide"><label for="company_address"><?= $isFrench ? 'Adresse enregistrée' : 'Registered address' ?></label><input id="company_address" value="<?= $val('company_address') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_registration"><?= $isFrench ? 'Numéro d\'immatriculation' : 'Company registration number' ?></label><input id="company_registration" value="<?= $val('company_registration') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_email"><?= $isFrench ? 'Courriel' : 'Email' ?></label><input id="company_email" type="email" value="<?= $val('company_email') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="company_phone"><?= $isFrench ? 'Téléphone' : 'Telephone' ?></label><input id="company_phone" value="<?= $val('company_phone') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="governing_law"><?= $isFrench ? 'Droit applicable' : 'Governing law' ?></label><input id="governing_law" value="<?= $val('governing_law') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="jurisdiction"><?= $isFrench ? 'Juridiction compétente' : 'Competent jurisdiction' ?></label><input id="jurisdiction" value="<?= $val('jurisdiction') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <div><label for="signed_date"><?= $isFrench ? 'Date' : 'Date' ?></label><input id="signed_date" type="date" value="<?= $val('signed_date') ?>" <?= $locked ? 'readonly' : '' ?>></div>
    <?php endif; ?>
  </form>

  <?= pcvc_service_agreement_body($contract, !$locked, false, true) ?>

  <h2><?= $isFrench ? 'Signatures' : 'Signatures' ?></h2>
  <div class="sign-grid">
    <div class="box">
      <strong>Parrot Canada Visa Consultant Co. Ltd.</strong>
      <p>Dr Jean Pierre Twajamahoro<br><?= $isFrench ? 'Directeur général / Propriétaire' : 'Managing Director / Owner' ?></p>
      <img class="sig" src="admin/signature-manager.png" alt="<?= $isFrench ? 'Signature de Parrot' : 'Parrot signature' ?>">
    </div>
    <div class="box">
      <strong id="party_b_name"><?= $documentLayout ? ($val('representative_name') !== '' ? $val('representative_name') : 'Client') : ($val('company_name') !== '' ? $val('company_name') : ($isFrench ? 'Partie B' : 'Party B')) ?></strong>
      <p id="party_b_person"><?= $val('representative_name') ?></p>
      <?php if ($signed && $val('signature_image') !== ''): ?>
        <img class="sig" id="savedSig" src="<?= $val('signature_image') ?>" alt="Signed signature">
      <?php endif; ?>
      <?php if (!$locked): ?>
        <canvas id="pad"></canvas>
        <p><button type="button" class="ghost" id="clear"><?= $isFrench ? 'Effacer la signature' : 'Clear signature' ?></button></p>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$locked): ?>
    <p><button type="button" id="save"><?= $signed ? ($isFrench ? 'Enregistrer' : 'Save changes') : ($isFrench ? 'Signer l\'accord' : 'Sign agreement') ?></button></p>
    <div id="msg"></div>
  <?php endif; ?>

  <?= pcvc_service_agreement_body($contract, true, true, false) ?>
  <?php if ($locked): ?>
    <p><button type="button" id="saveAnnex"><?= $isFrench ? 'Enregistrer les détails du candidat' : 'Save candidate details' ?></button></p>
    <div id="msg"></div>
  <?php endif; ?>
</div>
<?php if (!$locked): ?>
<script>
const ui = <?= json_encode($isFrench ? [
    'party' => 'Partie B',
    'missing' => 'Indiquez la société, la personne qui signe, sa fonction, le courriel et la date.',
    'draw' => 'Signez d\'abord dans le cadre.',
    'saving' => 'Enregistrement...',
    'saved' => 'Modifications enregistrées.',
    'signed' => 'Signé. Merci.',
    'fail' => 'Enregistrement impossible.',
    'retry' => 'Enregistrement impossible. Veuillez réessayer.',
    'annex' => 'Les détails du candidat sont enregistrés.',
] : [
    'party' => 'Party B',
    'missing' => 'Fill in the company, the person signing, their position, the email, and the date.',
    'draw' => 'Draw your signature first.',
    'saving' => 'Saving...',
    'saved' => 'Your changes are saved.',
    'signed' => 'Signed. Thank you.',
    'fail' => 'Could not save.',
    'retry' => 'Could not save. Please try again.',
    'annex' => 'Candidate details are saved.',
], JSON_UNESCAPED_UNICODE) ?>;
const token = <?= json_encode($token) ?>;
const existing = <?= json_encode((string) ($contract['signature_image'] ?? '')) ?>;
const pad = document.getElementById('pad');
const ctx = pad.getContext('2d');
let drawing = false;
let dirty = false;
function sizePad() {
  const rect = pad.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  pad.width = rect.width * ratio;
  pad.height = rect.height * ratio;
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#111827';
}
sizePad();
function pos(e) {
  const r = pad.getBoundingClientRect();
  const p = e.touches ? e.touches[0] : e;
  return { x: p.clientX - r.left, y: p.clientY - r.top };
}
function start(e) { drawing = true; dirty = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
function move(e) { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
function end() { drawing = false; }
pad.addEventListener('mousedown', start);
pad.addEventListener('mousemove', move);
window.addEventListener('mouseup', end);
pad.addEventListener('touchstart', start, { passive: false });
pad.addEventListener('touchmove', move, { passive: false });
pad.addEventListener('touchend', end);
document.getElementById('clear').addEventListener('click', () => { sizePad(); dirty = false; });
const documentLayout = <?= $documentLayout ? 'true' : 'false' ?>;
const ids = ['company_name','representative_name','representative_title','company_address','company_registration','company_email','company_phone','governing_law','jurisdiction','signed_date'];
function sync() {
  if (documentLayout) {
    const personNow = document.getElementById('representative_name').value.trim();
    if (personNow) document.getElementById('company_name').value = personNow;
  }
  document.querySelectorAll('[data-mirror]').forEach((el) => {
    const input = document.getElementById(el.getAttribute('data-mirror'));
    el.textContent = input && input.value.trim() ? input.value.trim() : '';
  });
  const company = document.getElementById('company_name').value.trim();
  const person = document.getElementById('representative_name').value.trim();
  document.getElementById('party_b_name').textContent = documentLayout ? (person || 'Client') : (company || ui.party);
  document.getElementById('party_b_person').textContent = documentLayout ? 'Client' : person;
}
ids.forEach((id) => document.getElementById(id).addEventListener('input', sync));
if (!document.getElementById('signed_date').value) {
  document.getElementById('signed_date').value = new Date().toISOString().slice(0, 10);
}
sync();
document.getElementById('save').addEventListener('click', async () => {
  const body = { token };
  ids.forEach((id) => { body[id] = document.getElementById(id).value.trim(); });
  document.querySelectorAll('.annex-field').forEach((el) => { body[el.id] = el.value.trim(); });
  body.signature = dirty ? pad.toDataURL('image/png') : existing;
  const msg = document.getElementById('msg');
  if (!body.company_name || !body.representative_name || !body.representative_title || !body.company_email || !body.signed_date) {
    msg.textContent = ui.missing;
    return;
  }
  if (!body.signature || !body.signature.startsWith('data:image/png')) {
    msg.textContent = ui.draw;
    return;
  }
  const btn = document.getElementById('save');
  btn.disabled = true;
  msg.textContent = ui.saving;
  try {
    const res = await fetch('submit-service-agreement.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json();
    if (!data.success) {
      msg.textContent = data.error || ui.fail;
      btn.disabled = false;
      return;
    }
    msg.textContent = data.status === 'updated' ? ui.saved : ui.signed;
    setTimeout(() => location.reload(), 700);
  } catch (e) {
    msg.textContent = ui.retry;
    btn.disabled = false;
  }
});
</script>
<?php else: ?>
<script>
document.getElementById('saveAnnex').addEventListener('click', async () => {
  const body = { token: <?= json_encode($token) ?>, annex_only: true };
  document.querySelectorAll('.annex-field').forEach((el) => { body[el.id] = el.value.trim(); });
  const msg = document.getElementById('msg');
  const btn = document.getElementById('saveAnnex');
  btn.disabled = true;
  msg.textContent = <?= json_encode($isFrench ? 'Enregistrement...' : 'Saving...', JSON_UNESCAPED_UNICODE) ?>;
  try {
    const res = await fetch('submit-service-agreement.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json();
    msg.textContent = data.success
      ? <?= json_encode($isFrench ? 'Les détails du candidat sont enregistrés.' : 'Candidate details are saved.', JSON_UNESCAPED_UNICODE) ?>
      : (data.error || <?= json_encode($isFrench ? 'Enregistrement impossible.' : 'Could not save.', JSON_UNESCAPED_UNICODE) ?>);
  } catch (e) {
    msg.textContent = <?= json_encode($isFrench ? 'Enregistrement impossible. Veuillez réessayer.' : 'Could not save. Please try again.', JSON_UNESCAPED_UNICODE) ?>;
  }
  btn.disabled = false;
});
</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>

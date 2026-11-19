<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/b2b_partnership_agreement.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit("Database connection error.");
}

if (!isset($_GET['token']) || trim($_GET['token']) === '') {
    http_response_code(400);
    exit("Invalid contract link.");
}

$token = trim($_GET['token']);

$sql = "SELECT * FROM partner_contracts WHERE contract_token = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit("Query preparation failed.");
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$contract = $result->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit("This contract link is invalid or expired.");
}

$isSigned = ($contract['status'] === 'signed');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Accord de partenariat stratégique B2B</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<style>
:root {
  --ink: #111827;
  --muted: #374151;
  --border: #d1d5db;
  --soft: #f9fafb;
  --paper: #ffffff;
  --link: #1d4ed8;
  --warn: #b91c1c;
  --success: #15803d;
  --radius-sm: 6px;
  --radius-md: 10px;
  --shadow-paper: 0 10px 40px rgba(0,0,0,.08);
}

body {
  margin: 0;
  padding: 48px 16px;
  background: linear-gradient(180deg, #eef2f7, #e5e7eb);
  font-family: "Inter", "Segoe UI", system-ui, sans-serif;
  color: var(--ink);
}

/* Enhanced Mobile Responsive Design */
@media screen and (max-width: 768px) {
  body {
    padding: 20px 12px !important;
  }
  
  .contract-page {
    padding: 28px 20px !important;
    margin: 8px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
  }
  
  .contract-page h1 {
    font-size: 18pt !important;
    margin-bottom: 20pt !important;
    line-height: 1.2 !important;
  }
  
  .contract-page h3 {
    font-size: 14pt !important;
    margin-top: 20pt !important;
    margin-bottom: 12pt !important;
    line-height: 1.3 !important;
  }
  
  .contract-page p {
    font-size: 11pt !important;
    line-height: 1.6 !important;
    margin: 0 0 12pt 0 !important;
  }
  
  .form-section {
    padding: 16px !important;
    margin: 12px 0 !important;
    border-radius: 6px !important;
  }
  
  .form-section h4 {
    font-size: 13pt !important;
    margin-bottom: 12px !important;
  }
  
  .signature-grid {
    grid-template-columns: 1fr !important;
    gap: 20px !important;
  }
  
  .signature-section {
    margin-bottom: 20px !important;
    padding: 16px !important;
  }
  
  .input-group {
    margin-bottom: 16px !important;
  }
  
  .input-group input {
    font-size: 14px !important;
    padding: 12px !important;
    max-width: 100% !important;
  }
  
  .btn {
    padding: 14px 20px !important;
    font-size: 14px !important;
    margin: 8px 4px !important;
  }
}

@media screen and (max-width: 480px) {
  body {
    padding: 12px 6px !important;
  }
  
  .contract-page {
    padding: 20px 12px !important;
    margin: 4px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
  }
  
  .contract-page h1 {
    font-size: 16pt !important;
    margin-bottom: 16pt !important;
    line-height: 1.2 !important;
  }
  
  .contract-page h3 {
    font-size: 12pt !important;
    margin-top: 16pt !important;
    margin-bottom: 8pt !important;
    line-height: 1.3 !important;
  }
  
  .contract-page p {
    font-size: 10pt !important;
    line-height: 1.5 !important;
    margin: 0 0 8pt 0 !important;
  }
  
  .form-section {
    padding: 12px !important;
    margin: 8px 0 !important;
    border-radius: 4px !important;
  }
  
  .form-section h4 {
    font-size: 12pt !important;
    margin-bottom: 10px !important;
  }
  
  .signature-canvas {
    width: 100% !important;
    max-width: 260px !important;
    height: 100px !important;
  }
  
  .btn {
    width: 100% !important;
    margin: 6px 0 !important;
    text-align: center !important;
    padding: 12px 16px !important;
  }
  
  .btn-clear {
    margin-bottom: 8px !important;
  }
  
  .input-group input {
    font-size: 16px !important;
    padding: 12px !important;
    max-width: 100% !important;
  }
}

.contract-page {
  max-width: 900px;
  margin: auto;
  background: var(--paper);
  padding: 64px 72px;
  box-shadow: var(--shadow-paper);
  border-radius: var(--radius-md);
  font-family: "Georgia", "Times New Roman", serif;
  font-size: 12.2pt;
  line-height: 1.75;
  overflow-wrap: anywhere;
  word-wrap: break-word;
}

@media (max-width: 768px) {
  .contract-page { padding: 40px 28px; }
}

.contract-page h1 {
  text-align: center;
  font-size: 24pt;
  font-weight: 700;
  letter-spacing: .6px;
  text-transform: uppercase;
  margin-bottom: 32pt;
}

.contract-page h2 {
  font-size: 15pt;
  font-weight: 700;
  margin-top: 28pt;
  margin-bottom: 12pt;
}

.contract-page h3 {
  font-size: 15pt;
  font-weight: 700;
  margin-top: 34pt;
  margin-bottom: 14pt;
}

.contract-page p {
  margin: 0 0 14pt 0;
  text-align: justify;
}

.contract-page strong {
  font-weight: 700;
}

.contract-page input[type="text"],
.contract-page input[type="email"],
.contract-page input[type="date"],
.contract-page input[type="tel"] {
  width: 100%;
  max-width: 520px;
  padding: 10px 12px;
  font-family: inherit;
  font-size: 12.2pt;
  border: none;
  border-bottom: 2px solid var(--ink);
  background: rgba(255, 255, 255, 0.9);
  border-radius: 4px;
  outline: none;
  transition: all 0.3s ease;
  box-sizing: border-box;
}

.contract-page input:focus {
  border-bottom-color: var(--link);
  background: rgba(255, 255, 255, 1);
  box-shadow: 0 0 0 3px rgba(31, 79, 216, 0.1);
}

.contract-page input::placeholder {
  color: #9ca3af;
  font-style: italic;
}

.contract-page input:required {
  border-left: 3px solid var(--link);
}

/* Smart form styling */
.form-section {
  background: rgba(31, 79, 216, 0.05);
  border-radius: 8px;
  padding: 20px;
  margin: 15px 0;
  border: 1px solid var(--border);
}

.form-section h4 {
  margin: 0 0 15px 0;
  color: var(--ink);
  font-size: 14pt;
  font-weight: 600;
}

.input-group {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 15px;
  align-items: center;
  margin-bottom: 15px;
}

/* Mobile input group layout */
@media screen and (max-width: 768px) {
  .input-group {
    grid-template-columns: 1fr !important;
    gap: 8px !important;
    margin-bottom: 16px !important;
  }
  
  .input-group label {
    margin-bottom: 6px !important;
    font-size: 12pt !important;
  }
}

@media screen and (max-width: 480px) {
  .input-group {
    gap: 6px !important;
    margin-bottom: 14px !important;
  }
  
  .input-group label {
    margin-bottom: 4px !important;
    font-size: 11pt !important;
  }
}

.input-group label {
  font-weight: 600;
  color: var(--muted);
  font-size: 11pt;
  margin-bottom: 5px;
  display: block;
}

.input-group input {
  flex: 1;
}

/* Date picker styling */
input[type="date"] {
  position: relative;
}

input[type="date"]::-webkit-calendar-picker-indicator {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 20px;
  height: 20px;
  cursor: pointer;
  background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="%231d4ed8" viewBox="0 0 24 24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>') no-repeat;
  background-size: contain;
}

input[type="date"]::-moz-calendar-picker {
  width: 20px;
  height: 20px;
  cursor: pointer;
}

.signature-canvas {
  border: 1px dashed #9ca3af;
  height: 130px;
  width: 100%;
  max-width: 400px;
  cursor: crosshair;
  background: #fafafa;
  border-radius: 4px;
  touch-action: none;
}

/* Mobile canvas optimization */
@media (max-width: 768px) {
  .signature-canvas {
    height: 120px;
    max-width: 280px;
  }
}

@media (max-width: 480px) {
  .signature-canvas {
    height: 100px;
    max-width: 240px;
  }
}

.signature-canvas:hover {
  border-color: var(--link);
  background: #f8fafc;
  transform: scale(1.02);
}

.signature-canvas.drawing {
  border-color: var(--link);
  background: #f0f9ff;
  box-shadow: 0 0 10px rgba(31, 79, 216, 0.1);
}

/* Signature section improvements */
.signature-section {
  background: linear-gradient(135deg, #f8fafc, #e0e7ff);
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 20px;
  margin: 20px 0;
}

.signature-section h4 {
  color: var(--ink);
  margin-bottom: 15px;
  font-size: 16px;
}

.btn {
  padding: 14px 28px;
  border: none;
  border-radius: var(--radius-md);
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  transition: all 0.3s ease;
}

/* Mobile button optimization */
@media (max-width: 768px) {
  .btn {
    padding: 12px 20px;
    font-size: 14px;
  }
}

@media (max-width: 480px) {
  .btn {
    padding: 14px 16px;
    font-size: 13px;
    width: 100%;
    margin: 6px 0;
  }
}

.btn-clear {
  background: #f3f4f6;
  color: var(--ink);
  border: 1px solid #d1d5db;
}

.btn-clear:hover {
  background: #e5e7eb;
  color: var(--ink);
}

.btn-submit {
  background: var(--link);
  color: #ffffff;
  border: 1px solid var(--link);
}

.btn-submit:hover {
  background: #1e40af;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(31, 79, 216, 0.15);
}

.btn-submit:disabled {
  background: #9ca3af;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

.signature-status {
  font-size: 12px;
  color: var(--success);
  margin-top: 10px;
  display: none;
}

.signature-status.show {
  display: block;
}

.signature-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40px;
  margin-top: 30px;
}

/* Mobile responsive signature grid */
@media (max-width: 768px) {
  .signature-grid {
    grid-template-columns: 1fr;
    gap: 20px;
  }
}

@media (max-width: 480px) {
  .signature-grid {
    gap: 15px;
  }
}

@media (max-width: 768px) {
  .signature-grid { grid-template-columns: 1fr; gap: 28pt; }
}

button {
  font-family: system-ui, sans-serif;
  font-size: 14px;
  font-weight: 600;
  padding: 10px 18px;
  border-radius: var(--radius-sm);
  border: none;
  cursor: pointer;
}

#clearSignature {
  background: #f3f4f6;
  color: var(--ink);
}

#signContract {
  background: var(--link);
  color: #ffffff;
}

#signContract:hover { background: #1e40af; }
#signContract:disabled { background: #9ca3af; cursor: not-allowed; }

.footer-ref {
  margin-top: 48pt;
  text-align: center;
  font-size: 10.5pt;
  color: #6b7280;
}

@media print {
  body { background: #ffffff; padding: 0; }
  .contract-page { box-shadow: none; border-radius: 0; }
  button { display: none; }
}
</style>
</head>
<body>

<div class="contract-page">

<h1>ACCORD DE PARTENARIAT STRATÉGIQUE B2B</h1>
<p style="text-align:center;font-weight:600;margin-top:-12pt;">Éducation internationale, coordination des services d’immigration et soutien à l’emploi à l’étranger</p>

<h2>1. PARTIES</h2>
<p>Le présent accord de partenariat stratégique est conclu entre :</p>
<p style="font-weight:700;">Entreprise partenaire</p>
<div class="form-section">
  <h4>Informations de l'Entreprise</h4>
  <div class="input-group">
    <label for="company_name">Nom de l'Entreprise *</label>
    <input type="text" id="company_name" name="company_name" required placeholder="Entrez le nom de votre entreprise" value="<?= htmlspecialchars($contract['company_name'] ?? '') ?>">
  </div>
  <div class="input-group">
    <label for="representative_name">Représentant *</label>
    <input type="text" id="representative_name" name="representative_name" required placeholder="Entrez le nom du représentant" value="<?= htmlspecialchars($contract['representative_name'] ?? '') ?>">
  </div>
  <div class="input-group">
    <label for="representative_title">Fonction *</label>
    <input type="text" id="representative_title" name="representative_title" required placeholder="Entrez la fonction" value="<?= htmlspecialchars($contract['representative_title'] ?? '') ?>">
  </div>
  <div class="input-group">
    <label for="company_email">Email *</label>
    <input type="email" id="company_email" name="company_email" required placeholder="Entrez l'email de l'entreprise" value="<?= htmlspecialchars($contract['company_email'] ?? '') ?>">
  </div>
  <div class="input-group">
    <label for="company_phone">Téléphone</label>
    <input type="tel" id="company_phone" name="company_phone" placeholder="Entrez le téléphone de l'entreprise" value="<?= htmlspecialchars($contract['company_phone'] ?? '') ?>">
  </div>
  <div class="input-group">
    <label for="company_address">Adresse complète</label>
    <input type="text" id="company_address" name="company_address" placeholder="Entrez l'adresse complète" value="<?= htmlspecialchars($contract['company_address'] ?? '') ?>">
  </div>
</div>
<p>Ci-après dénommée &laquo; le Partenaire &raquo;.</p>
<p style="font-weight:700;">Et :</p>
<p>
<strong>Parrot Canada Visa Consultant Co. Ltd</strong><br>
Représentant : Dr Jean Pierre Twajamahoro<br>
Fonction : Propriétaire et directeur général<br>
Courriel : infos@visaconsultantcanada.ca<br>
Téléphone : +1 (438) 290-6688<br>
<strong>Adresse au Rwanda :</strong><br>
Town Center Building, near Simba Supermarket<br>
2nd Floor, Door F2B-022C, Nyarugenge<br>
Kigali, Rwanda<br>
<strong>Adresse au Canada :</strong><br>
294 Rue Vezina, App 202<br>
Lasalle, Quebec H8R 3M9, Canada
</p>
<p>Ci-après dénommée &laquo; Parrot &raquo;.</p>
<p>Parrot et le Partenaire sont ensemble dénommés &laquo; les parties &raquo;.</p>

<?php echo pcvc_b2b_agreement_body('fr', $contract, true); ?>

<h2>16. SIGNATURES</h2>
<p>En signant ci-dessous, les représentants autorisés confirment avoir lu, compris et accepté le présent accord.</p>



<div class="signature-grid">

<!-- LEFT: Parrot Canada / Dr. Twajamahoro -->
<div class="signature-block">
<p style="font-weight:700;margin-bottom:18px;font-size:16px;">
Pour Parrot Canada Visa Consultant Co. Ltd
</p>

<div style="margin-bottom:18px;">
<strong>Nom :</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
Dr Jean Pierre Twajamahoro
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Fonction :</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
Propriétaire &amp; Directeur Général
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Signature :</strong>
<div style="margin-top:6px;width:85%;">
<img src="admin/signature-manager.png" alt="Signature du Directeur" style="max-height:70px;max-width:280px;display:block;">
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Cachet de l'entreprise :</strong>
<div style="margin-top:6px;width:85%;">
<img src="admin/employer-signature.png" alt="Cachet" style="max-height:140px;max-width:320px;display:block;">
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Date :</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;" id="parrot_date">
<?= date('Y/m/d') ?>
</div>
</div>
</div>

<!-- RIGHT: Partner Company — all details + e-sign -->
<div class="signature-block" style="
padding:20px;
background:#f9fafb;
border-radius:12px;
border:1px solid #e5e7eb;
">

<p style="font-weight:700;margin-bottom:8px;font-size:16px;">
Entreprise Partenaire
</p>
<p style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.5;">
Les informations ci-dessous sont copiées automatiquement depuis la section <strong>1.1 Informations de l'Entreprise</strong> en haut.
Vérifiez-les, puis signez seulement.
</p>

<?php
$sigLineStyle = 'border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;';
$sigCompany = htmlspecialchars($contract['company_name'] ?? '');
$sigRep = htmlspecialchars($contract['representative_name'] ?? '');
$sigTitle = htmlspecialchars($contract['representative_title'] ?? '');
$sigEmail = htmlspecialchars($contract['representative_email'] ?? $contract['company_email'] ?? '');
$sigPhone = htmlspecialchars($contract['company_phone'] ?? '');
$sigAddress = htmlspecialchars($contract['company_address'] ?? '');
$sigDateDisplay = !empty($contract['signed_date']) ? htmlspecialchars($contract['signed_date']) : date('Y/m/d');
?>

<div style="margin-bottom:18px;">
<strong>Nom de l'entreprise :</strong>
<div id="sig_display_company_name" style="<?= $sigLineStyle ?>"><?= $sigCompany ?></div>
</div>

<div style="margin-bottom:18px;">
<strong>Nom du représentant :</strong>
<div id="sig_display_representative_name" style="<?= $sigLineStyle ?>"><?= $sigRep ?></div>
</div>

<div style="margin-bottom:18px;">
<strong>Fonction :</strong>
<div id="sig_display_representative_title" style="<?= $sigLineStyle ?>"><?= $sigTitle ?></div>
</div>

<div style="margin-bottom:18px;">
<strong>Courriel :</strong>
<div id="sig_display_email" style="<?= $sigLineStyle ?>"><?= $sigEmail ?></div>
</div>

<div style="margin-bottom:18px;">
<strong>Téléphone :</strong>
<div id="sig_display_phone" style="<?= $sigLineStyle ?>"><?= $sigPhone ?></div>
</div>

<div style="margin-bottom:18px;">
<strong>Adresse de l'entreprise :</strong>
<div id="sig_display_address" style="<?= $sigLineStyle ?>"><?= $sigAddress ?></div>
</div>

<input type="hidden" id="contract_start_date" value="<?= date('Y-m-d') ?>">
<input type="hidden" id="sig_signed_date" value="<?= htmlspecialchars(!empty($contract['signed_date']) ? $contract['signed_date'] : date('Y-m-d')) ?>">

<div style="margin-bottom:16px;">
<label style="display:block;margin-bottom:12px;font-weight:600;color:#374151;">Signature :</label>
<div style="
border:2px dashed #9ca3af;
height:150px;
padding:8px;
margin-bottom:10px;
background:#ffffff;
border-radius:8px;
display:flex;
align-items:center;
justify-content:center;
position:relative;
">
<?php if ($isSigned && !empty($contract['signature_image'])): ?>
<img src="<?= $contract['signature_image'] ?>" style="max-height:130px;border:1px solid #e5e7eb;padding:5px;border-radius:4px;" alt="Signature du partenaire">
<?php else: ?>
<canvas class="signature-canvas"></canvas>
<div style="position:absolute;top:8px;right:8px;font-size:12px;color:#9ca3af;">
Dessinez votre signature ci-dessus
</div>
<?php endif; ?>
</div>
<div class="signature-status" id="signatureStatus">Signature capturée avec succès</div>
</div>

<div style="margin-bottom:20px;">
<strong>Date :</strong>
<div id="sig_display_date" style="<?= $sigLineStyle ?>"><?= $sigDateDisplay ?></div>
</div>

<?php if (!$isSigned): ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;">
<button id="clearSignature" type="button" style="flex:1;background:#f3f4f6;color:#374151;border:none;padding:12px 16px;border-radius:6px;font-weight:600;cursor:pointer;">Effacer la Signature</button>
<button id="signContract" type="button" style="flex:2;background:#3b82f6;color:#ffffff;border:none;padding:12px 16px;border-radius:6px;font-weight:600;cursor:pointer;">Signer &amp; Soumettre</button>
</div>
<?php endif; ?>

</div>

</div>

</div>

<div class="footer-ref">
Référence du Contrat : <?= htmlspecialchars($contract['contract_token']) ?>
</div>

</div>

<?php if (!$isSigned): ?>
<script>
(() => {
  const canvas = document.querySelector('.signature-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  const btnClear = document.getElementById('clearSignature');
  const btnSubmit = document.getElementById('signContract');

  const inputCompany = document.getElementById('company_name');
  const inputName = document.getElementById('representative_name');
  const inputTitle = document.getElementById('representative_title');
  const inputEmail = document.getElementById('company_email');
  const inputPhone = document.getElementById('company_phone');
  const inputAddress = document.getElementById('company_address');
  const inputDate = document.getElementById('sig_signed_date');
  const inputContractDate = document.getElementById('contract_start_date');

  const displayCompany = document.getElementById('sig_display_company_name');
  const displayName = document.getElementById('sig_display_representative_name');
  const displayTitle = document.getElementById('sig_display_representative_title');
  const displayEmail = document.getElementById('sig_display_email');
  const displayPhone = document.getElementById('sig_display_phone');
  const displayAddress = document.getElementById('sig_display_address');
  const displayDate = document.getElementById('sig_display_date');
  const parrotDate = document.getElementById('parrot_date');

  let drawing = false;
  let lastX = 0;
  let lastY = 0;
  let currentPoints = [];
  let canvasWidth = 0;
  let canvasHeight = 0;
  let scaleX = 1;
  let scaleY = 1;

  // Proper canvas initialization with device pixel ratio handling
  function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    
    canvasWidth = rect.width;
    canvasHeight = rect.height;
    
    canvas.width = canvasWidth * dpr;
    canvas.height = canvasHeight * dpr;
    
    scaleX = canvas.width / canvasWidth;
    scaleY = canvas.height / canvasHeight;
    
    ctx.setTransform(scaleX, 0, 0, scaleY, 0, 0);
    
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#000000';
    
    if (signatureData && signatureData.length > 0) {
      redrawSignature();
    }
  }

  // Store signature paths for persistence
  let signatureData = [];
  let currentPath = [];

  // Get accurate coordinates using getBoundingClientRect
  function getCanvasCoordinates(e) {
    const rect = canvas.getBoundingClientRect();
    let clientX, clientY;
    
    if (e.touches) {
      clientX = e.touches[0].clientX;
      clientY = e.touches[0].clientY;
    } else {
      clientX = e.clientX;
      clientY = e.clientY;
    }
    
    let x = clientX - rect.left;
    let y = clientY - rect.top;
    
    x = Math.max(0, Math.min(x, canvasWidth));
    y = Math.max(0, Math.min(y, canvasHeight));
    
    return { x, y };
  }

  // Start drawing with proper coordinate handling
  function startDrawing(e) {
    e.preventDefault();
    drawing = true;
    
    const coords = getCanvasCoordinates(e);
    lastX = coords.x;
    lastY = coords.y;
    
    currentPath = [{ x: lastX, y: lastY }];
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(lastX, lastY);
    ctx.stroke();
    
    canvas.classList.add('drawing');
  }

  // Smooth drawing with interpolation
  function draw(e) {
    if (!drawing) return;
    e.preventDefault();
    
    const coords = getCanvasCoordinates(e);
    const currentX = coords.x;
    const currentY = coords.y;
    
    currentPath.push({ x: currentX, y: currentY });
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(currentX, currentY);
    ctx.stroke();
    
    lastX = currentX;
    lastY = currentY;
  }

  // Stop drawing and save path
  function stopDrawing() {
    if (drawing && currentPath.length > 0) {
      signatureData.push([...currentPath]);
      currentPath = [];
    }
    drawing = false;
    canvas.classList.remove('drawing');
  }

  // Redraw all saved signatures
  function redrawSignature() {
    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
    
    ctx.beginPath();
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#000000';
    
    for (const path of signatureData) {
      if (path.length === 0) continue;
      
      ctx.beginPath();
      ctx.moveTo(path[0].x, path[0].y);
      
      for (let i = 1; i < path.length; i++) {
        ctx.lineTo(path[i].x, path[i].y);
      }
      ctx.stroke();
    }
  }

  // Enhanced signature detection
  function hasSignature() {
    if (signatureData.length > 0) {
      return true;
    }
    
    try {
      const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
      for (let i = 0; i < pixels.length; i += 4) {
        if (pixels[i] !== 255 || pixels[i+1] !== 255 || pixels[i+2] !== 255) {
          return true;
        }
      }
    } catch(e) {
      console.warn('Pixel check failed:', e);
    }
    
    return false;
  }

  // Setup event listeners
  function setupCanvasEvents() {
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseleave', stopDrawing);
    
    canvas.addEventListener('touchstart', startDrawing, { passive: false });
    canvas.addEventListener('touchmove', draw, { passive: false });
    canvas.addEventListener('touchend', stopDrawing);
    canvas.addEventListener('touchcancel', stopDrawing);
  }

  // Initialize canvas
  resizeCanvas();
  setupCanvasEvents();
  
  // Handle window resize
  let resizeTimeout;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
      resizeCanvas();
    }, 100);
  });

  // Clear signature
  btnClear.addEventListener('click', () => {
    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
    signatureData = [];
    currentPath = [];
    drawing = false;
    
    const status = document.getElementById('signatureStatus');
    if (status) {
      status.classList.remove('show');
    }
  });

  // Update signature status
  function updateSignatureStatus() {
    const status = document.getElementById('signatureStatus');
    const hasSig = hasSignature();
    
    if (hasSig && status && !status.classList.contains('show')) {
      status.textContent = 'Signature capturée avec succès';
      status.classList.add('show');
    } else if (!hasSig && status && status.classList.contains('show')) {
      status.classList.remove('show');
    }
  }

  // Monitor signature status
  setInterval(updateSignatureStatus, 500);

  function syncSignatureDisplay() {
    const companyValue = inputCompany ? inputCompany.value.trim() : '';
    const nameValue = inputName ? inputName.value.trim() : '';
    const titleValue = inputTitle ? inputTitle.value.trim() : '';
    const emailValue = inputEmail ? inputEmail.value.trim() : '';
    const phoneValue = inputPhone ? inputPhone.value.trim() : '';
    const addressValue = inputAddress ? inputAddress.value.trim() : '';
    const emptyHint = '—';

    if (displayCompany) displayCompany.textContent = companyValue || emptyHint;
    if (displayName) displayName.textContent = nameValue || emptyHint;
    if (displayTitle) displayTitle.textContent = titleValue || emptyHint;
    if (displayEmail) displayEmail.textContent = emailValue || emptyHint;
    if (displayPhone) displayPhone.textContent = phoneValue || emptyHint;
    if (displayAddress) displayAddress.textContent = addressValue || emptyHint;

    const mirrored = {
      company_name: companyValue,
      representative_name: nameValue,
      representative_title: titleValue,
      company_email: emailValue,
      company_phone: phoneValue,
      company_address: addressValue
    };
    document.querySelectorAll('[data-mirror]').forEach((el) => {
      const key = el.getAttribute('data-mirror');
      el.textContent = (mirrored[key] || '').trim() || emptyHint;
    });

    const inlineName = document.getElementById('display_company_name');
    if (inlineName) inlineName.textContent = companyValue || '______________________________';
  }

  function scrollToFirstMissingField() {
    const required = [
      { el: inputCompany, msg: 'Veuillez entrer le nom de l\'entreprise en haut du contrat.' },
      { el: inputName, msg: 'Veuillez entrer le nom du représentant en haut du contrat.' },
      { el: inputTitle, msg: 'Veuillez entrer la fonction en haut du contrat.' },
      { el: inputEmail, msg: 'Veuillez entrer l\'email de l\'entreprise en haut du contrat.' }
    ];
    for (const item of required) {
      if (!item.el || item.el.value.trim()) continue;
      item.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      item.el.focus();
      alert(item.msg);
      return true;
    }
    return false;
  }

  function setDefaultDate() {
    const today = new Date().toISOString().split('T')[0];
    if (inputDate) inputDate.value = today;
    if (inputContractDate) inputContractDate.value = today;
    if (displayDate) displayDate.textContent = today.replace(/-/g, '/');
    if (parrotDate) parrotDate.textContent = today.replace(/-/g, '/');
  }

  [inputCompany, inputName, inputTitle, inputEmail, inputPhone, inputAddress].forEach((el) => {
    if (!el) return;
    el.addEventListener('input', syncSignatureDisplay);
  });

  setDefaultDate();
  syncSignatureDisplay();

  // Form submission
  btnSubmit.addEventListener('click', async () => {
    if (!hasSignature()) {
      alert('Veuillez signer le contrat avant de soumettre.');
      return;
    }

    const finalCompany = inputCompany ? inputCompany.value.trim() : '';
    const finalName = inputName ? inputName.value.trim() : '';
    const finalTitle = inputTitle ? inputTitle.value.trim() : '';
    const finalEmail = inputEmail ? inputEmail.value.trim() : '';
    const finalPhone = inputPhone ? inputPhone.value.trim() : '';
    const finalAddress = inputAddress ? inputAddress.value.trim() : '';
    const signedDate = inputDate ? inputDate.value : '';

    if (!finalCompany || !finalName || !finalTitle || !finalEmail) {
      alert('Veuillez remplir tous les champs obligatoires en haut du contrat.');
      return;
    }

    if (!validateEmail(finalEmail)) {
      alert('Veuillez entrer une adresse email valide.');
      return;
    }

    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Soumission en cours...';

    try {
      const signatureDataUrl = canvas.toDataURL('image/png');
      
      const payload = {
        token: '<?= $token ?>',
        company_name: finalCompany,
        representative_name: finalName,
        representative_title: finalTitle,
        representative_email: finalEmail,
        company_email: finalEmail,
        company_phone: finalPhone,
        company_address: finalAddress,
        signed_date: signedDate,
        signature: signatureDataUrl
      };

      const response = await fetch('submit-partner-signature.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
      });

      const result = await response.json();

      if (result.success) {
        if (result.status === 'already_signed') {
          alert('Ce contrat a déjà été signé.');
        } else {
          alert('Contrat signé avec succès! Vous serez redirigé.');
          setTimeout(() => {
            window.location.reload();
          }, 2000);
        }
      } else {
        alert('Erreur lors de la signature: ' + (result.error || 'Erreur inconnue'));
      }
    } catch (error) {
      console.error('Submission error:', error);
      alert('Erreur de connexion. Veuillez réessayer.');
    } finally {
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Signer & Soumettre';
    }
  });

  function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
  }
})();
</script>
<?php endif; ?>

</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/country_flag.php';
require_once __DIR__ . '/helpers/student_contract_notice.php';
/**
 * 0. Safety check: DB connection
 */
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit("Database connection error.");
}

/**
 * 1. Validate token presence
 */
if (!isset($_GET['token']) || trim($_GET['token']) === '') {
    http_response_code(400);
    exit("Invalid contract link.");
}

$token = trim($_GET['token']);

/**
 * 2. Load contract session
 */
$sql = "
    SELECT *
    FROM student_contracts
    WHERE contract_token = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit("Query preparation failed.");
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result   = $stmt->get_result();
$contract = $result->fetch_assoc();
$stmt->close();

/**
 * 3. Token not found
 */
if (!$contract) {
    http_response_code(404);
    exit("This contract link is invalid or expired.");
}

/**
 * 4. Contract state flag (DO NOT EXIT)
 */
$isSigned = ($contract['status'] === 'signed');
$selectedPackageCode = trim((string)($contract['selected_package_code'] ?? ''));
$selectedPackageLabel = trim((string)($contract['selected_package_label'] ?? ''));
$contractPdfPath = trim((string)($contract['pdf_path'] ?? ''));

// Load student signature image (stored as data URL) when signed
$studentSignatureData = null;
if ($isSigned && !empty($contract['id'])) {
    $contractId = (int)$contract['id'];
    $stmt = $conn->prepare("
        SELECT signature_image
        FROM student_signatures
        WHERE contract_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $contractId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['signature_image']) && is_string($row['signature_image'])) {
            $studentSignatureData = $row['signature_image'];
        }
    }
}
?>
<?php
/* =====================================================
   LOAD STUDENT DATA FOR SERVER-SIDE RENDERING (SAFE)
===================================================== */
$student = null;
$externalStudent = null;

if (!empty($contract['student_id']) && is_numeric($contract['student_id'])) {

    $studentId = (int) $contract['student_id'];

    $stmt = $conn->prepare("
        SELECT
            first_name,
            last_name,
            email,
            dob,
            nationality,
            passport_number,
            phone_number
        FROM student_applications
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $studentId);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $student = $result->fetch_assoc();
        }

        $stmt->close();
    }
} else {
    // Load external student data from contract record
    $externalStudent = [
        'first_name' => $contract['external_student_name'] ?? '',
        'last_name' => '',
        'email' => $contract['external_student_email'] ?? '',
        'dob' => $contract['external_student_dob'] ?? '',
        'nationality' => $contract['external_student_nationality'] ?? '',
        'passport_number' => $contract['external_student_passport'] ?? '',
        'phone_number' => $contract['external_student_phone'] ?? ''
    ];
    
    // Split full name into first and last name if available
    if (!empty($externalStudent['first_name'])) {
        $nameParts = explode(' ', trim($externalStudent['first_name']), 2);
        $externalStudent['first_name'] = $nameParts[0] ?? '';
        $externalStudent['last_name'] = $nameParts[1] ?? '';
    }
}

// Determine which student data to use
$displayStudent = $student ?? $externalStudent ?? [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'dob' => '',
    'nationality' => '',
    'passport_number' => '',
    'phone_number' => ''
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Convention d'admission et de conseil en visa pour étudiants internationaux</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
/* =====================================================
   ROOT DESIGN TOKENS
===================================================== */
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

/* =====================================================
   PAGE BACKGROUND - MOBILE FIRST
===================================================== */
body {
  margin: 0;
  padding: 12px 8px;
  background: linear-gradient(180deg, #f8fafc, #e2e8f0);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  color: var(--ink);
  min-height: 100vh;
}

/* =====================================================
   CONTRACT SHEET - MOBILE FIRST
===================================================== */
.contract-page {
  max-width: 100%;
  margin: 0 auto;
  background: var(--paper);
  padding: 20px 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  border-radius: 12px;

  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size: 14px;
  line-height: 1.6;
  overflow-wrap: anywhere;
  word-break: break-word;
}

/* =====================================================
   RESPONSIVE BREAKPOINTS - MOBILE FIRST
===================================================== */

/* Tablet and up */
@media (min-width: 768px) {
  body {
    padding: 24px 20px;
  }

  .contract-page {
    max-width: 900px;
    padding: 40px 48px;
    font-size: 15px;
    line-height: 1.7;
  }
}

/* Desktop and up */
@media (min-width: 1024px) {
  body {
    padding: 48px 32px;
  }

  .contract-page {
    padding: 64px 72px;
    font-size: 16px;
    line-height: 1.75;
    font-family: "Georgia", "Times New Roman", serif;
  }
}

/* =====================================================
   MOBILE INPUT STYLES - CRITICAL FOR USABILITY
===================================================== */
.contract-page input[type="email"],
.contract-page input[type="text"],
.contract-page input[type="tel"],
.contract-page input[type="date"] {
  width: 100% !important;
  max-width: 100% !important;
  font-size: 16px !important; /* Prevents zoom on iOS */
  padding: 12px 16px !important;
  margin: 8px 0 !important;
  border: 2px solid #e2e8f0 !important;
  border-radius: 8px !important;
  background: #ffffff !important;
  box-sizing: border-box !important;
  transition: border-color 0.2s ease !important;
}

.contract-page input[type="email"]:focus,
.contract-page input[type="text"]:focus,
.contract-page input[type="tel"]:focus,
.contract-page input[type="date"]:focus {
  outline: none !important;
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* =====================================================
   MOBILE LABEL STYLES
===================================================== */
.contract-page strong {
  display: block !important;
  margin-bottom: 6px !important;
  font-size: 14px !important;
  font-weight: 600 !important;
  color: #374151 !important;
}

/* =====================================================
   MOBILE FORM CONTAINER - IMPROVED
===================================================== */
.contract-page div[style*="margin-bottom"] {
  margin-bottom: 24px !important;
}

/* =====================================================
   ENHANCED MOBILE RESPONSIVENESS
===================================================== */
@media (max-width: 767px) {
  .contract-page {
    padding: 12px 8px !important;
  }
  
  .contract-section {
    padding: 0 8px !important;
  }
  
  /* Improve signature text stacking on mobile */
  .signature-section p {
    font-size: 14px !important;
    line-height: 1.4 !important;
    margin-bottom: 12px !important;
    word-break: keep-all !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }
  
  .signature-section strong {
    font-size: 14px !important;
    display: inline !important;
    margin-right: 6px !important;
  }
  
  /* Fix package selection on mobile */
  .package-item {
    margin-bottom: 16px !important;
    padding: 12px !important;
  }
  
  .package-label {
    font-size: 14px !important;
    line-height: 1.4 !important;
  }
  
  .package-details {
    font-size: 13px !important;
    padding-left: 20px !important;
  }
  
  /* Student signature section mobile fixes */
  .signature-block {
    margin-bottom: 24px !important;
  }
  
  /* Input field improvements for mobile */
  .contract-page input[type="text"],
  .contract-page input[type="email"],
  .contract-page input[type="tel"],
  .contract-page input[type="date"] {
    font-size: 16px !important; /* Prevents zoom on iOS */
    padding: 14px 16px !important;
    border-radius: 8px !important;
    border: 2px solid #e5e7eb !important;
  }
  
  .contract-page input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
    outline: none !important;
  }
}

/* =====================================================
   TABLET OPTIMIZATIONS
===================================================== */
@media (min-width: 768px) and (max-width: 1023px) {
  .contract-page {
    padding: 24px 20px !important;
  }
  
  .signature-grid {
    gap: 32pt 48pt !important;
  }
  
  .signature-canvas {
    height: 170px !important;
  }
}

/* =====================================================
   MOBILE HEADINGS
===================================================== */
.contract-page h1 {
  font-size: 20px !important;
  line-height: 1.3 !important;
  margin-bottom: 16px !important;
  text-align: center !important;
}

.contract-page h3 {
  font-size: 16px !important;
  margin-top: 24px !important;
  margin-bottom: 12px !important;
}

/* =====================================================
   SIGNATURE CANVAS - MOBILE OPTIMIZED
===================================================== */
.signature-canvas {
  width: 100% !important;
  height: 150px !important;
  border: 2px dashed #cbd5e1 !important;
  border-radius: 8px !important;
  background: #ffffff !important;
  touch-action: none !important;
  margin: 16px 0 !important;
  cursor: crosshair;
}

@media (min-width: 768px) {
  .signature-canvas {
    height: 180px !important;
  }
}

@media (min-width: 1024px) {
  .signature-canvas {
    height: 200px !important;
  }
}

/* =====================================================
   BUTTON STYLES - MOBILE FRIENDLY ENHANCED
===================================================== */
.contract-page button {
  padding: 14px 28px !important;
  font-size: 16px !important;
  font-weight: 600 !important;
  border-radius: 8px !important;
  margin: 8px 4px !important;
  min-height: 52px !important;
  min-width: 120px !important;
  touch-action: manipulation !important;
  transition: all 0.2s ease !important;
}

@media (max-width: 767px) {
  .contract-page button {
    width: 100% !important;
    margin: 6px 0 !important;
    max-width: none !important;
  }
}

/* =====================================================
   PACKAGE SELECTION - MOBILE
===================================================== */
.package-label {
  line-height: 1.45 !important;
  font-size: 14px !important;
}

.package-details {
  padding-left: 12px !important;
  font-size: 14px !important;
  line-height: 1.5 !important;
}

/* =====================================================
   TEXT ELEMENTS
===================================================== */
.contract-page p {
  margin: 0 0 14pt 0;
  text-align: justify;
}

.contract-page strong {
  font-weight: 700;
}

/* =====================================================
   LISTS
===================================================== */
.contract-page ul,
.contract-page ol {
  margin: 0 0 16pt 26pt;
  padding: 0;
}

.contract-page li {
  margin-bottom: 8pt;
}

/* =====================================================
   LINKS
===================================================== */
.contract-page a {
  color: var(--link);
  text-decoration: underline;
  font-weight: 500;
}

/* =====================================================
   FORM INPUTS – CONTRACT STYLE
===================================================== */
.contract-page input[type="text"],
.contract-page input[type="email"],
.contract-page input[type="date"],
.contract-page input[type="tel"] {
  width: 100%;
  max-width: 520px;
  padding: 8px 6px;

  font-family: inherit;
  font-size: 12.2pt;

  border: none;
  border-bottom: 1.6px solid var(--ink);
  background: transparent;
  outline: none;
}

.contract-page input:focus {
  border-bottom-color: var(--link);
}

/* =====================================================
   SECTION WRAPPER
===================================================== */
.contract-section {
  max-width: 900px;
  margin: 0 auto;
  padding: 0 72px;
}

/* =====================================================
   ARTICLE 7 – PACKAGE SELECTION
===================================================== */
.package-item {
  margin-bottom: 18pt;
  padding: 12pt 14pt;
  border-radius: var(--radius-md);
  border: 2px solid #e5e7eb;
  transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}

.package-item:hover {
  background: var(--soft);
  border-color: #cbd5e1;
}

.package-item.is-selected {
  background: #f0fdf4;
  border-color: #16a34a;
  box-shadow: 0 4px 14px rgba(22, 163, 74, 0.12);
}

.package-label {
  font-weight: 700;
  cursor: pointer;
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 10px;
  align-items: start;
  line-height: 1.45;
}

.package-label input {
  margin-top: 4px;
  width: 18px;
  height: 18px;
  accent-color: #16a34a;
  flex-shrink: 0;
}

.package-label-text {
  min-width: 0;
  word-break: break-word;
}

.country-flag {
  display: inline-block;
  width: 22px;
  height: 16px;
  margin: 0 6px 2px 0;
  vertical-align: -3px;
  border-radius: 2px;
  object-fit: cover;
  box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.18);
}

.article-7-intro {
  padding: 14px 16px;
  margin-bottom: 20px;
  background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
  border: 1px solid #bfdbfe;
  border-radius: var(--radius-md);
  font-size: 14px;
  color: #1e3a5f;
}

.article-7-intro strong {
  color: #0f172a;
}

.signed-status-banner {
  max-width: 900px;
  margin: 0 auto 24px;
  padding: 18px 20px;
  background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
  border: 1px solid #86efac;
  border-radius: var(--radius-md);
  box-shadow: 0 4px 16px rgba(22, 163, 74, 0.1);
}

.signed-status-banner h2 {
  margin: 0 0 8px;
  font-size: 18px;
  color: #14532d;
}

.signed-status-banner p {
  margin: 0 0 14px;
  text-align: left;
  color: #166534;
}

.signed-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.signed-actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 12px 18px;
  border-radius: 8px;
  font-weight: 600;
  text-decoration: none;
  font-size: 14px;
}

.signed-actions .btn-download {
  background: #15803d;
  color: #fff;
}

.signed-actions .btn-view {
  background: #fff;
  color: #15803d;
  border: 2px solid #16a34a;
}

.contract-signed .package-label input {
  pointer-events: none;
}

.contract-signed #clearSignature,
.contract-signed #signContract {
  display: none;
}

.package-details {
  display: none;
  margin-top: 10pt;
  padding: 12px 14px 12px 28px;
  font-size: 11.6pt;
  line-height: 1.75;
  color: var(--muted);
  background: #f8faf8;
  border-left: 3px solid #2e7d32;
  border-radius: 0 8px 8px 0;
}

.package-notes {
  margin-top: 12pt;
  padding: 10pt 12pt;
  border-left: 4px solid #2e7d32;
  border-radius: 0 6px 6px 0;
  background: #eef8f0;
  font-size: 10.8pt;
  line-height: 1.6;
}

.package-notes > strong {
  color: #1b5e20;
  letter-spacing: .03em;
}

.package-notes ol {
  margin: 4pt 0 0 20pt;
  padding: 0;
}

.package-notes li {
  padding-left: 3pt;
  margin-bottom: 2pt;
}

/* Highlighted fee amounts (Article 7 packages) */
.fee-price {
  display: inline-block;
  font-weight: 700;
  color: #0d5c2e;
  background: linear-gradient(135deg, #e8f5e9 0%, #f4fbf4 100%);
  border: 1px solid #81c784;
  border-radius: 5px;
  padding: 2px 7px;
  margin: 0 2px;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em;
  box-shadow: 0 1px 2px rgba(46, 125, 50, 0.12);
}

.package-details strong .fee-price,
.package-details strong:has(.fee-price) {
  font-size: 1.05em;
}

.additional-fees .fee-price {
  font-size: 1em;
}

/* =====================================================
   WARNINGS & NOTES
===================================================== */
.contract-warning {
  margin-top: 22pt;
  font-size: 11.6pt;
  font-style: italic;
  color: var(--warn);
}

/* =====================================================
   ADDITIONAL FEES
===================================================== */
.additional-fees {
  margin-top: 30pt;
  padding-top: 18pt;
  border-top: 1px solid var(--border);
  font-size: 11.6pt;
}

.additional-fees h4 {
  font-size: 13pt;
  font-weight: 700;
  margin-bottom: 12pt;
}

/* =====================================================
   IMPORTANT NOTE BOX
===================================================== */
.important-note {
  margin-top: 18pt;
  padding: 14pt 16pt;
  border-left: 5px solid var(--warn);
  background: #fff5f5;
  font-size: 11.4pt;
  border-radius: var(--radius-sm);
}

/* =====================================================
   SIGNATURE CANVAS
===================================================== */
.signature-canvas {
  width: 100%;
  height: 140px;
  border: 2px dashed #9ca3af;
  border-radius: var(--radius-sm);
  background: #ffffff;
  cursor: crosshair;
}

/* =====================================================
   SIGNATURE GRID - MOBILE FIRST RESPONSIVE
===================================================== */
.signature-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 32pt;
  margin: 32pt 0;
}

@media (min-width: 768px) {
  .signature-grid {
    grid-template-columns: 1fr 1fr;
    gap: 40pt 64pt;
  }
}

@media (min-width: 1024px) {
  .signature-grid {
    gap: 48pt 80pt;
  }
}

/* =====================================================
   BUTTONS (SIGNATURE ACTIONS)
===================================================== */
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

#clearSignature:hover {
  background: #e5e7eb;
}

#signContract {
  background: var(--link);
  color: #ffffff;
}

#signContract:hover {
  background: #1e40af;
}

#signContract:disabled {
  background: #9ca3af;
  cursor: not-allowed;
}

/* =====================================================
   FOOTER
===================================================== */
.footer-ref {
  margin-top: 48pt;
  text-align: center;
  font-size: 10.5pt;
  color: #6b7280;
}

/* =====================================================
   PRINT OPTIMIZATION
===================================================== */
@media print {
  body {
    background: #ffffff;
    padding: 0;
  }

  .contract-page {
    box-shadow: none;
    border-radius: 0;
  }

  button {
    display: none;
  }
}
</style>

</head>

<body<?= $isSigned ? ' class="contract-signed"' : '' ?>>

<?php if ($isSigned): ?>
<div class="signed-status-banner">
  <h2>Convention signée avec succès</h2>
  <p>
    <?php if ($selectedPackageLabel !== ''): ?>
      Formule choisie : <strong><?= htmlspecialchars($selectedPackageLabel) ?></strong>
    <?php else: ?>
      Votre convention est enregistrée et le PDF signé est disponible.
    <?php endif; ?>
  </p>
  <div class="signed-actions">
    <a class="btn-download" href="download-contract.php?token=<?= urlencode($token) ?>">Télécharger le PDF signé</a>
    <a class="btn-view" href="download-contract.php?token=<?= urlencode($token) ?>&amp;inline=1" target="_blank" rel="noopener">Voir le PDF</a>
  </div>
</div>
<?php endif; ?>

<!-- ============================
     CONTRACT HEADER + ARTICLE 1
============================ -->
<div class="contract-page">

  <!-- MAIN TITLE -->
  <h1 style="
    text-align:center;
    font-size:30px;
    font-weight:700;
    letter-spacing:0.6px;
    margin-bottom:28px;
    text-transform:uppercase;
    color:#111827;
  ">
    ADMISSION D'ÉTUDIANTS INTERNATIONAUX<br>
    ET CONVENTION DE CONSEIL EN VISA
  </h1>

  <!-- INTRO PARAGRAPH -->
  <p style="
    font-size:16px;
    text-align:justify;
    margin-bottom:30px;
  ">
    La présente <strong>convention d’admission et de conseil en visa pour étudiants internationaux</strong>
    (la « <strong>Convention</strong> ») est conclue à la date de signature
    entre les parties suivantes :
  </p>

  <!-- ELECTRONIC NOTICE -->
  <p style="
    font-size:14px;
    font-style:italic;
    color:#374151;
    margin-bottom:40px;
  ">
    Veuillez lire attentivement cette convention. En signant par voie électronique, vous confirmez
    que vous comprenez et acceptez l’ensemble des conditions qu’elle contient.
  </p>

  <!-- ============================
       ARTICLE 1 – PARTIES
  ============================ -->
  <h3 style="
    font-size:20px;
    font-weight:700;
    margin-bottom:20px;
    color:#111827;
  ">
    1. LES PARTIES
  </h3>

  <!-- 1.1 Consultant -->
  <p style="font-size:16px; font-weight:700; margin-bottom:8px;">
    1.1 Le consultant
  </p>
<p>
  <strong>Parrot Canada Visa Consultant Company Ltd.</strong><br>
  Adresse du siège : Cellule Gasanze, Secteur Nduba, District de Gasabo, Kigali – Rwanda<br>
  Email:
  <a href="mailto:admission@visaconsultantcanada.com">admission@visaconsultantcanada.com</a>
  &amp;
  <a href="mailto:admission@visaconsultantcanada.com">admission@visaconsultantcanada.com</a><br>
  Website:
  <a href="https://www.visaconsultantcanada.com">www.visaconsultantcanada.com</a>
  &amp;
  <a href="https://www.visaconsultantcanada.com">www.visaconsultantcanada.com</a>
</p>


  <!-- 1.2 Student -->
  <p style="font-size:16px; font-weight:700; margin-bottom:12px;">
    1.2 L’étudiant
  </p>

  <!-- STUDENT DETAILS (AUTOFILL SAFE) -->
  <div style="font-size:15px; max-width:720px;">
<div style="margin-bottom:18px;">
      <strong>Courriel :</strong>
      <input
        type="email"
        id="student_email"
        name="student_email"
        required
        autocomplete="email"
        value="<?= htmlspecialchars($displayStudent['email'] ?? '') ?>"
        placeholder="Saisissez votre adresse courriel">
    </div>
    <div style="margin-bottom:12px;">
      <strong>Nom légal complet :</strong>
      <input
        type="text"
        id="student_name"
        name="student_name"
        required
        autocomplete="name"
        value="<?= htmlspecialchars(trim(($displayStudent['first_name'] ?? '') . ' ' . ($displayStudent['last_name'] ?? ''))) ?>"
        placeholder="Saisissez votre nom légal complet">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Date de naissance :</strong>
      <input
        type="date"
        id="student_dob"
        name="student_dob"
        required
        autocomplete="bday"
        value="<?= htmlspecialchars($displayStudent['dob'] ?? '') ?>"
        placeholder="Choisissez votre date de naissance">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Nationalité :</strong>
      <input
        type="text"
        id="student_nationality"
        name="student_nationality"
        required
        autocomplete="country-name"
        value="<?= htmlspecialchars($displayStudent['nationality'] ?? '') ?>"
        placeholder="Saisissez votre nationalité">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Numéro de passeport :</strong>
      <input
        type="text"
        id="student_passport"
        name="student_passport"
        autocomplete="off"
        value="<?= htmlspecialchars($displayStudent['passport_number'] ?? '') ?>"
        placeholder="Saisissez votre numéro de passeport">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Téléphone :</strong>
      <input
        type="tel"
        id="student_phone"
        name="student_phone"
        required
        autocomplete="tel"
        value="<?= htmlspecialchars($displayStudent['phone_number'] ?? '') ?>"
        placeholder="Saisissez votre numéro de téléphone">
    </div>

    

  </div>

  <p style="font-size:15px;">
    (Ci-après dénommé <strong>« l’étudiant »</strong>)
  </p>
<!-- ============================
     ARTICLES 2 – 6
============================ -->

<!-- ARTICLE 2 -->
<h3>2. OBJET DE LA CONVENTION</h3>

<p>
  La présente convention régit la fourniture de
  <strong>services d’admission aux études internationales, de conseil en visa et de services connexes</strong>
  à l’étudiant qui souhaite étudier ou séjourner à l’étranger, notamment au
  <strong>Canada, aux États-Unis d’Amérique, en Europe et en Corée du Sud</strong>.
  L’étudiant reconnaît expressément que
  <strong>
    les décisions finales appartiennent uniquement aux établissements d’enseignement, aux ambassades,
    aux autorités d’immigration et aux autres organismes tiers
  </strong>,
  et qu’elles échappent au contrôle du consultant.
</p>

<!-- ARTICLE 3 -->
<h3>3. ÉTENDUE DES SERVICES</h3>

<p>
  Selon la formule de services choisie par l’étudiant, le consultant
  fournit une assistance professionnelle qui peut comprendre, sans s’y limiter :
</p>

<p>
  <strong>
    l’orientation à l’admission, l’aide à la demande de visa, l’appui à la préparation des documents,
    la préparation à l’entrevue (le cas échéant), l’orientation pour les prêts des formules avec prêt,
    l’aide à la recherche d’emploi, l’aide à la recherche de logement et l’orientation avant le départ
  </strong>.
</p>

<!-- ARTICLE 4 -->
<h3>4. OBLIGATIONS DU CONSULTANT</h3>

<p>Le consultant s’engage à exécuter les services avec professionnalisme et intégrité. Il doit :</p>

<ol>
  <li>Fournir les services avec diligence, professionnalisme et bonne foi</li>
  <li>Respecter les règles d’immigration, d’ambassade et des établissements applicables</li>
  <li>Garder confidentiels tous les renseignements de l’étudiant, conformément à la présente convention</li>
  <li>Informer l’étudiant, avec transparence, des mises à jour importantes et de l’avancement du dossier</li>
  <li>S’abstenir de toute falsification, déclaration trompeuse ou pratique contraire à l’éthique</li>
</ol>

<!-- ARTICLE 5 -->
<h3>5. OBLIGATIONS DE L’ÉTUDIANT</h3>

<p>L’étudiant s’engage à :</p>

<ol>
  <li>Fournir des renseignements personnels et scolaires exacts, complets et véridiques</li>
  <li>Remettre uniquement des documents authentiques, valides et vérifiables</li>
  <li>Payer tous les frais applicables dans les délais prévus</li>
  <li>Collaborer pleinement avec le consultant pendant toute la procédure d’admission et de visa</li>
  <li>
    Assumer l’entière responsabilité des conséquences, retards, refus ou pertes
    résultant de renseignements faux, trompeurs ou incomplets fournis par l’étudiant
  </li>
</ol>

<!-- ARTICLE 6 -->
<h3>6. ABSENCE DE GARANTIE</h3>

<p>L’étudiant comprend expressément et accepte que :</p>

<ul>
  <li>
    Les résultats d’admission, les visas, les prêts et les délais de traitement
    ne sont <strong>pas garantis</strong>, en aucune circonstance
  </li>
  <li>
    Toutes les décisions sont prises de façon indépendante par les établissements d’enseignement, les ambassades,
    les autorités d’immigration et les autres organismes tiers
  </li>
  <li>
    Tout refus, retard ou résultat défavorable ne constitue pas un manquement
    du consultant à la présente convention
  </li>
</ul>


<!-- ============================
     ARTICLE 7 – FEES & PAYMENT TERMS
============================ -->

<div class="contract-section">

<h3>7. FRAIS ET MODALITÉS DE PAIEMENT (TARIFS REGROUPÉS)</h3>

<p>
  L’étudiant choisit <strong>une (1)</strong> seule formule de services parmi les options ci-dessous.
  Les frais s’appliquent <strong>uniquement</strong> à la formule choisie.
</p>

<div class="article-7-intro">
  <strong>Comment choisir :</strong> cochez une seule formule ci-dessous. Le détail des frais s’affiche sous votre choix.
  <?= $isSigned ? 'Cette convention est signée — la formule choisie est affichée ci-dessous.' : 'Vous devez choisir une formule avant de signer au bas de cette page.' ?>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" value="p70" onclick="showPkg('p70')">
    <span class="package-label-text">7.1 FRAIS PAYÉS PAR LES ÉTUDIANTS POUR NOS UNIVERSITÉS ET ENTREPRISES PARTENAIRES, ET POUR CERTAINS PAYS CIBLÉS</span>
  </label>
  <div id="p70" class="package-details">
    ➤ <?= pcvc_country_flag('us') ?> États-Unis – Frais d’inscription et de dossier : 150 USD (payés lorsque l’offre d’admission est émise)<br>
    ➤ <?= pcvc_country_flag('ca') ?> Canada – Frais d’inscription et de dossier : 225 CAD (payés lorsque l’offre d’admission est émise)<br>
    ➤ <?= pcvc_country_flag('eu') ?> Europe – Frais d’inscription et de dossier : 150 USD (payés avant le début du dossier)<br>
    ➤ <?= pcvc_country_flag('kr') ?> Corée du Sud – Frais d’inscription et de dossier : 250 USD (payés avant le début du dossier)<br>
    <em>Note : l’étudiant assume tous les frais supplémentaires liés à la demande de visa et à l’immigration, y compris les frais des ambassades, des centres de demande de visa, des organismes publics, des établissements médicaux ou de toute autre autorité compétente.</em>
  </div>
</div>

<?php if ($selectedPackageCode === 'p71'): ?>
<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p71')">
    7.2 <?= pcvc_country_flag('us') ?>  Études aux États-Unis (avec prêt)
  </label>
  <div id="p71" class="package-details">
    ✔ Accompagnement à l’admission<br>
    ➤ Frais d’inscription et de dossier : 150 USD (remboursables si l’admission n’est pas obtenue dans les 4 mois)<br>
    ➤ Après l’approbation du prêt : 1 200 USD<br>
    ➤ Frais de préparation à l’entrevue simulée : 150 USD<br>
    ➤ Après l’approbation du visa : 1 500 USD<br>
    <strong>Total de la formule : 3 000 USD</strong><br>
  </div>
</div>
<?php endif; ?>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p72')">
    7.2 <?= pcvc_country_flag('us') ?>  Études aux États-Unis (sans prêt)
  </label>
  <div id="p72" class="package-details">
    ✔ Accompagnement à l’admission<br>
    ➤ Frais d’inscription et de dossier : 150 USD (remboursables si l’admission n’est pas obtenue dans les 4 mois)<br>
    ➤ Frais de préparation à l’entrevue simulée : 150 USD<br>
    ➤ Après l’approbation du visa : 2 000 USD<br>
    <strong>Total de la formule : 2 300 USD</strong><br>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p73')">
    7.3 <?= pcvc_country_flag('eu') ?>  Études en Europe (sans prêt)
  </label>
  <div id="p73" class="package-details">
    ➤ Frais d’inscription et de dossier : EUR 250 (remboursables si l’admission n’est pas obtenue dans les 4 mois)<br>
    ➤ Avant la demande de visa : EUR 250<br>
    ➤ Après l’approbation du visa : EUR 1,500<br>
    <strong>Total de la formule : EUR 2,000</strong><br>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p74')">
    7.4 <?= pcvc_country_flag('ca') ?>  Études au Canada (avec prêt)
  </label>
  <div id="p74" class="package-details">
    ➤ Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 4 mois)<br>
    ➤ Après l’approbation du visa : 3 050 CAD<br>
    <strong>Total de la formule : 3 500 CAD</strong><br>
    <div class="package-notes">
      <strong>NOTE:</strong>
      <ol>
        <li>Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.</li>
        <li>Chaque personne à charge paie 1 000 CAD après l’approbation du visa.</li>
      </ol>
    </div>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p75')">
    7.5 <?= pcvc_country_flag('ca') ?>  Études au Canada (sans prêt)
  </label>
  <div id="p75" class="package-details">
    ➤ Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 4 mois)<br>
    ➤ Après l’approbation du visa : 2 050 CAD<br>
    <strong>Formule complète d’accompagnement visa : 2 500 CAD</strong><br>
    <div class="package-notes">
      <strong>NOTE:</strong>
      <ol>
        <li>Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.</li>
        <li>Chaque personne à charge paie 1 000 CAD après l’approbation du visa.</li>
      </ol>
    </div>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p76')">
    7.6 <?= pcvc_country_flag('ca') ?>  Canada – Diplômé du secondaire (avec prêt)
  </label>
  <div id="p76" class="package-details">
    ➤ Frais d’inscription et de dossier : CAD 450<br>
    ➤ Frais de permis d’études : 150 CAD<br>
    ➤ Frais de biométrie : 85 CAD<br>
    ➤ Frais de CAQ (Québec seulement) : 132 CAD<br>
    ➤ Frais de laissez-passer frontalier : 250 CAD<br>
    ➤ Frais de traitement du prêt : 1 000 CAD<br>
    ➤ Frais de service après l’approbation du visa : 1 933 CAD<br>
    <strong>Total de la formule : 4 000 CAD</strong><br>
    <div class="package-notes">
      <strong>NOTE:</strong>
      <ol>
        <li>Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.</li>
        <li>Chaque personne à charge paie 1 000 CAD après l’approbation du visa.</li>
      </ol>
    </div>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p77loa')">
    7.7 <?= pcvc_country_flag('ca') ?>  Études au Canada avec lettre d’admission (sans consultation d’avocat)
  </label>
  <div id="p77loa" class="package-details">
    ➤ Frais d’inscription au système : 225 CAD<br>
    ➤ Frais de préparation des documents : 375 CAD<br>
    ➤ Frais initiaux : 400 CAD<br>
    ➤ Après l’approbation du visa : 1 000 CAD<br>
    <strong>Total de la formule : 2 000 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p77loalawyer')">
    7.8 <?= pcvc_country_flag('ca') ?>  Études au Canada avec lettre d’admission (avec consultation d’avocat)
  </label>
  <div id="p77loalawyer" class="package-details">
    ➤ Frais d’inscription au système : 225 CAD<br>
    ➤ Frais de préparation des documents : 375 CAD<br>
    ➤ Frais de consultation d’avocat : 500 CAD<br>
    ➤ Frais initiaux : 400 CAD<br>
    ➤ Après l’approbation du visa : 1 000 CAD<br>
    <strong>Total de la formule : 2 500 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p77')">
    7.9 <?= pcvc_country_flag('kr') ?>  Études en Corée du Sud (autofinancées)
  </label>
  <div id="p77" class="package-details">
    ➤ Frais d’inscription et de suivi du dossier : 500 USD (à payer avant le début de l’admission ; remboursables si la lettre d’admission n’est pas obtenue)<br>
    ➤ Frais de service autofinancés – baccalauréat : 2 000 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)<br>
    ➤ Frais de service autofinancés – maîtrise : 2 400 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)<br>
    ➤ Frais de service autofinancés – doctorat : 2 800 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)<br>
    ✔ Lorsque la lettre d’acceptation finale est approuvée, la moitié (1/2) des frais de service applicables doit être payée avant la demande de visa<br>
    ✔ Une formation gratuite de coréen (3 mois) et une orientation avant le départ sont offertes au besoin
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p78')">
    7.10 <?= pcvc_country_flag('kr') ?>  Visa de visiteur pour la Corée du Sud
  </label>
  <div id="p78" class="package-details">
    ➤ Frais d’inscription et de dossier : USD 500<br>
    ➤ Frais de service (payés après réception de la lettre d’invitation et de la lettre de garantie) : 1 500 USD<br>
    ➤ Les frais de participation varient selon l’organisateur de l’événement.
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p79')">
    7.11 Transfert de crédits (baccalauréat, maîtrise, doctorat)
  </label>
  <div id="p79" class="package-details">
    ➤ Baccalauréat : 920 USD<br>
    ➤ Maîtrise : 1 220 USD<br>
    ➤ Doctorat : 1 620 USD
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p710')">
    7.12 <?= pcvc_country_flag('ca') ?>  Visa de visiteur pour le Canada
  </label>
  <div id="p710" class="package-details">
    ➤ Documents et lettre d’invitation : 1 000 USD<br>
    ➤ Frais de demande de visa : 100 CAD<br>
    ➤ Frais de biométrie : 85 CAD<br>
    ➤ Frais de service (après l’approbation du visa) : 2 000 CAD
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p710b')">
    7.13 <?= pcvc_country_flag('ca') ?>  Visa de visiteur pour le Canada – avec lettre d’invitation
  </label>
  <div id="p710b" class="package-details">
    ➤ Lettre d’invitation : déjà fournie par le demandeur<br>
    ➤ Préparation des documents et vérification de la demande de visa : 815 CAD<br>
    ➤ Frais de demande de visa : 100 CAD<br>
    ➤ Frais de biométrie : 85 CAD<br>
    ➤ Frais de service (après l’approbation du visa) : 2 000 CAD<br>
    <strong>Coût total : 3 000 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p710promo')">
    7.14 <?= pcvc_country_flag('ca') ?>  Visiter le Canada avec invitation, tarif promotionnel
  </label>
  <div id="p710promo" class="package-details">
    ➤ Frais de lettre d’invitation : 360 CAD<br>
    ➤ Frais de préparation des documents : 240 CAD<br>
    ➤ Frais de consultation d’avocat : 400 CAD<br>
    ➤ Service Fees Après l’approbation du visa : 1 000 CAD<br>
    <strong>Total de la formule : 2 000 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p711')">
    7.15 <?= pcvc_country_flag('us') ?>  Visa de visiteur pour les États-Unis
  </label>
  <div id="p711" class="package-details">
    ➤ Documents et lettre d’invitation : 1 000 USD<br>
    ➤ Frais de demande de visa : 185 USD<br>
    ➤ Frais de service (après l’approbation du visa) : 1 500 USD
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p712')">
    7.16 <?= pcvc_country_flag('eu') ?>  Visa de visiteur pour l’Europe
  </label>
  <div id="p712" class="package-details">
    ➤ Documents et lettre d’invitation : 600 €<br>
    ➤ Frais de demande de visa : 85 à 500 € (selon le pays)<br>
    ➤ Frais de service (après l’approbation du visa) : 1 000 €
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p713')">
    7.17 Visa de visiteur pour l’Asie
  </label>
  <div id="p713" class="package-details">
    ➤ Documents et lettre d’invitation : 800 USD<br>
    ➤ Frais de demande de visa : 85 à 500 USD<br>
    ➤ Frais de service (après l’approbation du visa) : 1 500 USD
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p714')">
    7.18 COURS DE COURTE DURÉE – CANADA
  </label>
  <div id="p714" class="package-details">
    ➤ Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 2 semaines)<br>
    ➤ Frais d’inscription et de dossier pour un membre de la famille : 200 CAD (s’il y a lieu)<br>
    ➤ Acompte de scolarité après la lettre d’offre : 535 CAD (payé directement à l’école)<br>
    ➤ Avant la demande de visa : 100 CAD pour la demande de visa de visiteur (payés à l’ambassade)<br>
    ➤ Biométrie : 85 CAD (payée à l’ambassade)<br>
    ➤ Après l’approbation du visa : 2 500 CAD<br>
    <strong>Total de la formule : 3 670 CAD</strong><br>
    <div class="package-notes">
      <strong>NOTE:</strong>
      <ol>
        <li>Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.</li>
        <li>Chaque personne à charge paie 1 000 CAD après l’approbation du visa.</li>
      </ol>
    </div>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p715')">
    7.19 DOCTORAT AU CANADA, AUX ÉTATS-UNIS, EN EUROPE ET EN ASIE
  </label>
  <div id="p715" class="package-details">
    ➤ Frais d’inscription et de dossier pour le Canada : 500 CAD (remboursables si l’admission n’est pas obtenue dans les 9 mois)<br>
    ➤ Frais d’inscription et de dossier pour les États-Unis, l’Europe et l’Asie : 350 USD (remboursables si l’admission n’est pas obtenue dans les 9 mois)<br>
    ➤ Frais de publication d’article (non remboursables) : 280 USD (s’il y a lieu)<br>
    ➤ Aide à la rédaction de la proposition de recherche de doctorat (non remboursable) : 300 USD (s’il y a lieu)<br>
    ➤ Frais de demande de visa pour un membre de la famille (non remboursables) : Canada 400 CAD (s’il y a lieu)<br>
    ➤ Frais de demande de visa pour un membre de la famille (non remboursables) : États-Unis, Europe et Asie 250 USD (s’il y a lieu)<br>
    ➤ Acompte de scolarité après la lettre d’offre pour le Canada : 1 000 à 5 000 CAD (payé directement à l’école après l’admission)<br>
    ➤ Acompte de scolarité après la lettre d’offre pour les États-Unis, l’Europe et l’Asie : 500 à 5 000 USD (payé directement à l’école après l’admission)<br>
    ➤ Après l’approbation du visa pour le Canada : 5 000 CAD<br>
    ➤ Après l’approbation du visa pour les États-Unis, l’Europe et l’Asie : 4 500 USD<br>
    <div class="package-notes">
      <strong>NOTE:</strong>
      <ol>
        <li>Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.</li>
        <li>Chaque personne à charge paie 1 000 CAD après l’approbation du visa.</li>
      </ol>
    </div>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p716')">
    7.20 ÉVALUATION WES – ÉQUIVALENCE INTERNATIONALE
  </label>
  <div id="p716" class="package-details">
    <strong>1. Honoraires professionnels : 200 CAD</strong><br>
    Ces honoraires comprennent la consultation, l’orientation, l’aide à la préparation des documents et un suivi personnalisé pendant toute l’évaluation WES.<br><br>
    <strong>2. Frais de dossier et de traitement : 300 CAD</strong><br>
    Ce montant couvre les dépenses liées au dossier, les échanges avec les établissements, le traitement des documents et le suivi pendant l’évaluation.<br><br>
    <strong>3. Coordination avec l’université et vérification : 100 CAD</strong><br>
    Ce service consiste à contacter les universités, les registraires et les bureaux autorisés afin que les relevés de notes soient vérifiés et transmis à WES.<br><br>
    <strong>4. Frais d’expédition des documents : 100 CAD</strong><br>
    Ce coût comprend le courrier, l’expédition, l’aide à la transmission électronique et le suivi, afin que les documents parviennent à WES à temps et en sécurité.<br><br>
    <strong>5. Temps, travail administratif et suivi : 200 CAD</strong><br>
    Un temps et un travail administratif importants sont nécessaires pour suivre les envois, corriger les problèmes, répondre aux mises à jour et accompagner le demandeur jusqu’à la fin de l’évaluation.<br><br>
    <strong>Total de la formule : 900 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p717')">
    7.21 ACCOMPAGNEMENT GARANTI POUR L’ÉVALUATION
  </label>
  <div id="p717" class="package-details">
    <strong>1. Honoraires professionnels : 200 CAD</strong><br>
    Ces honoraires comprennent la consultation, l’orientation, l’aide à la préparation des documents et un suivi personnalisé pendant toute l’évaluation.<br><br>
    <strong>2. Frais de dossier et de traitement : 300 CAD</strong><br>
    Ce montant couvre les dépenses liées au dossier, les échanges avec les établissements, le traitement des documents et le suivi pendant l’évaluation.<br><br>
    <strong>3. Coordination avec l’université et vérification : 100 CAD</strong><br>
    Ce service consiste à contacter les universités, les registraires et les bureaux autorisés afin que les relevés de notes soient vérifiés et transmis.<br><br>
    <strong>4. Frais d’expédition des documents : 100 CAD</strong><br>
    Ce coût comprend le courrier, l’expédition, l’aide à la transmission électronique et le suivi, afin que les documents parviennent à destination à temps et en sécurité.<br><br>
    <strong>5. Temps, travail administratif et suivi : 200 CAD</strong><br>
    Un temps et un travail administratif importants sont nécessaires pour suivre les envois, corriger les problèmes, répondre aux mises à jour et accompagner le demandeur jusqu’à la fin de l’évaluation.<br><br>
    <strong>Total des formules : 900 CAD</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p718')">
    7.22 🇲🇹 Cours professionnels à Malte – pays européen de l’espace Schengen
  </label>
  <div id="p718" class="package-details">
    ➤ Frais d’inscription et de dossier : 250 EUR<br>
    ➤ Après l’approbation du visa : 1 750 EUR<br>
    <strong>🔥 Formule complète d’accompagnement visa PARROT : 2 000 EUR</strong><br>
    <div class="package-notes">
      <strong>🎓 FORMULES DE FORMATION DISPONIBLES (14 SEMAINES CHACUNE)</strong>
      <ol>
        <li>Programme souple : frais de scolarité 4 500 EUR et acompte initial 1 350 EUR.</li>
        <li>Programme semi-intensif : frais de scolarité 6 900 EUR et acompte initial 2 070 EUR.</li>
        <li>Programme intensif : frais de scolarité 10 900 EUR et acompte initial 3 270 EUR.</li>
      </ol>
      <strong>NOTE :</strong>
      <ol>
        <li>Les frais de scolarité ci-dessus sont distincts de la formule de service PARROT de 2 000 EUR.</li>
        <li>L’acompte initial de scolarité (30 %) est payable à la signature du contrat d’inscription de l’académie, avant la demande de visa.</li>
        <li>Le solde de scolarité (70 %) est payable après la délivrance du visa et avant le début de la formation.</li>
        <li>L’hébergement, le voyage, l’assurance, les frais de subsistance et les autres frais de tiers ne sont pas inclus.</li>
        <li>L’approbation du visa n’est pas garantie.</li>
      </ol>
    </div>
  </div>
</div>

<p class="contract-warning">
  ⚠ <strong>Important :</strong> tous les frais gouvernementaux, frais d’ambassade, frais de biométrie,
  acomptes de scolarité, honoraires d’avocat, frais de laissez-passer frontalier et frais de tiers
  sont payés séparément par l’étudiant et ne sont pas remboursables une fois versés.
</p>

<div class="additional-fees">
  <h4>Tarifs supplémentaires (sans prêt et services particuliers)</h4>

  <p>1. Cours de courte durée du printemps, de l’hiver, de l’été ou de l’automne (monde entier)</p>
  <ul>
    <li>Frais de dossier et d’inscription : <strong>250 EUR</strong>, remboursables si l’approbation n’est pas obtenue dans un délai de quatre (4) mois.</li>
    <li>Frais de service : <strong>2 000 EUR</strong>, payables seulement lorsque le visa est approuvé.</li>
  </ul>

  <p>2. Avocat en immigration canadienne – demande de visa (Canada seulement)</p>
  <p>Frais supplémentaires de <strong>300 CAD par demandeur</strong>.</p>

  <p>3. Avocat en immigration canadienne – conseil ou consultation (Canada seulement)</p>
  <p>Frais de consultation de <strong>300 CAD</strong>.</p>

  <div class="important-note">
    <strong>⚠ Important:</strong>
    Tous les frais gouvernementaux, frais d’ambassade, frais de biométrie, frais SEVIS, acomptes de scolarité,
    honoraires d’avocat, frais de laissez-passer frontalier et frais de tiers ne sont pas remboursables une fois versés.
  </div>
</div>


</div>

<!-- ============================
     ARTICLE 8 – PAYMENT OF SERVICE FEES
============================ -->

<div class="contract-section">

<h3>8. PAIEMENT DES FRAIS DE SERVICE</h3>

<p>
  Lorsque cela s’applique, les frais de service finaux deviennent exigibles dès l’approbation du visa.
  Une fois le visa approuvé, l’étudiant paie tous les frais de service encore dus
  <strong>au plus tard cinq (5) jours</strong> après la date d’approbation.
  Le défaut de paiement dans ce délai constitue un
  <strong>manquement important à la présente convention</strong> et peut entraîner
  des poursuites ou une exécution conformément au droit applicable.
</p>

</div>

<!-- ============================
     ARTICLE 9 – REFUND POLICY
============================ -->

<div class="contract-section">

<h3>9. POLITIQUE DE REMBOURSEMENT</h3>

<p>
  Seuls les frais d’inscription sont remboursables, et uniquement dans les conditions
  expressément prévues par la présente convention. Tous les autres frais, notamment les
  <strong>frais de service, frais de traitement de prêt, frais juridiques et frais de tiers</strong>,
  ne sont pas remboursables, quel que soit le résultat du visa ou de l’admission.
</p>

</div>

<!-- ============================
     ARTICLE 10 – TERMINATION
============================ -->

<div class="contract-section">

<h3>10. RÉSILIATION</h3>

<p>
  Le consultant peut résilier immédiatement la présente convention
  en cas de non-paiement, de remise de documents frauduleux ou trompeurs,
  ou de manquement de l’étudiant à l’une de ses obligations. La résiliation ne libère pas
  l’étudiant de l’obligation de payer les frais déjà engagés et encore dus.
</p>

</div>

<!-- ============================
     ARTICLE 11 – LIMITATION OF LIABILITY
============================ -->

<div class="contract-section">

<h3>11. LIMITATION DE RESPONSABILITÉ</h3>

<p>
  Le consultant n’est pas responsable des décisions, retards, refus ou résultats
  émanant des ambassades, des établissements d’enseignement, des autorités publiques,
  des changements de politique ou d’autres organismes tiers indépendants de sa volonté.
</p>

</div>

<!-- ============================
     ARTICLE 12 – CONFIDENTIALITY
============================ -->

<div class="contract-section">

<h3>12. CONFIDENTIALITÉ</h3>

<p>
  Tous les renseignements échangés entre les parties sont confidentiels
  et ne sont communiqués à un tiers que si la loi ou une autorité compétente l’exige.
</p>

</div>

<!-- ============================
     ARTICLE 13 – GOVERNING LAW & JURISDICTION
============================ -->

<div class="contract-section">

<h3>13. DROIT APPLICABLE ET TRIBUNAUX COMPÉTENTS</h3>

<p>
  La présente convention est régie et interprétée selon le
  droit de la <strong>République du Rwanda</strong>. Les tribunaux compétents du Rwanda
  ont compétence exclusive.
</p>

</div>

<!-- ============================
     ARTICLE 14 – ENTIRE AGREEMENT
============================ -->

<div class="contract-section">

<h3>14. INTÉGRALITÉ DE LA CONVENTION</h3>

<p>
  La présente convention constitue l’intégralité de l’accord entre les parties
  et remplace toute discussion ou déclaration antérieure. Toute modification
  n’est valable que si elle est faite par écrit et signée par les deux parties.
</p>

</div>

<?= pcvc_student_contract_documents_notice('fr') ?>

<!-- ============================
     15. SIGNATURES - TWO COLUMNS
     Left: Dr. Twajamahoro | Right: Student e-sign
============================ -->
<div class="contract-section signature-section" style="margin-top:40px;">

<h3 style="font-size:20px;font-weight:700;margin-bottom:32px;">
15. SIGNATURES
</h3>

<div class="signature-grid">

<!-- LEFT: Parrot Canada / Dr. Twajamahoro -->
<div class="signature-block">
<p style="font-weight:700;margin-bottom:18px;font-size:16px;">
Pour Parrot Canada Visa Consultant Co. Ltd
</p>

<div style="margin-bottom:18px;">
<strong>Name:</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
Dr. Jean Pierre Twajamahoro
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Title:</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
Propriétaire et directeur général
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Signature:</strong>
<div style="margin-top:6px;width:85%;">
<img src="admin/signature-manager.png" alt="Signature du directeur général" style="max-height:70px;max-width:280px;display:block;">
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Cachet de la société :</strong>
<div style="margin-top:6px;width:85%;">
<img src="admin/employer-signature.png" alt="Cachet de la société" style="max-height:140px;max-width:320px;display:block;">
</div>
</div>

<div style="margin-bottom:18px;">
<strong>Date:</strong>
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;" id="parrot_date">
<?= date('Y/m/d') ?>
</div>
</div>
</div>

<!-- RIGHT: Student details + e-sign -->
<div class="signature-block" style="
padding:20px;
background:#f9fafb;
border-radius:12px;
border:1px solid #e5e7eb;
">

<p style="font-weight:700;margin-bottom:20px;font-size:16px;">
Pour l’étudiant
</p>

<div style="margin-bottom:20px;">
<label style="display:block;margin-bottom:8px;font-weight:600;color:#374151;">Nom complet :</label>
<input type="text" id="sig_student_name"
style="
width:100%;
border:2px solid #e5e7eb;
border-radius:6px;
padding:12px 16px;
font-size:16px;
box-sizing:border-box;
transition:border-color 0.2s ease;
"
placeholder="Saisissez votre nom légal complet">
</div>

<div style="margin-bottom:20px;">
<label style="display:block;margin-bottom:12px;font-weight:600;color:#374151;">Signature :</label>
<div style="
border:2px dashed #9ca3af;
height:150px;
padding:8px;
margin-bottom:14px;
background:#ffffff;
border-radius:8px;
display:flex;
align-items:center;
justify-content:center;
position:relative;
">

<?php if ($isSigned && !empty($studentSignatureData)): ?>
<img src="<?= $studentSignatureData ?>" style="max-height:130px;" alt="Signature de l’étudiant">
<?php else: ?>
<canvas class="signature-canvas"></canvas>
<div style="position:absolute;top:8px;right:8px;font-size:12px;color:#9ca3af;">
Signez dans l’espace ci-dessus
</div>
<?php endif; ?>

</div>
</div>

<div style="margin-bottom:24px;">
<label style="display:block;margin-bottom:8px;font-weight:600;color:#374151;">Date :</label>
<input type="date" id="sig_signed_date"
style="
width:100%;
border:2px solid #e5e7eb;
border-radius:6px;
padding:12px 16px;
font-size:16px;
box-sizing:border-box;
transition:border-color 0.2s ease;
">
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap;">
<button id="clearSignature" type="button" style="flex:1;background:#f3f4f6;color:#374151;border:none;">Effacer la signature</button>
<button id="signContract" type="button" style="flex:2;background:#3b82f6;color:#ffffff;border:none;">Signer et envoyer la convention</button>
<input type="hidden" id="signatureData">
<input type="hidden" id="selected_package_code" value="<?= htmlspecialchars($selectedPackageCode) ?>">
</div>

</div>

</div> <!-- End signature grid -->

</div>
  <!-- FOOTER REF -->
  <div style="
    text-align:center;
    margin-top:40px;
    font-size:12px;
    color:#6b7280;
  ">
    Référence de la convention : <?= htmlspecialchars($contract['contract_token']) ?>
  </div>

</div>
</div>
<script>
(() => {
  /* ==========================
     CONFIG & ELEMENTS
  ========================== */
  const canvas = document.querySelector('.signature-canvas');
  if (!canvas) {
    return;
  }
  const ctx = canvas.getContext('2d');

  const btnClear = document.getElementById('clearSignature');
  const btnSubmit = document.getElementById('signContract');

  const inputName = document.getElementById('sig_student_name');
  const inputDate = document.getElementById('sig_signed_date');
  const hiddenSignature = document.getElementById('signatureData');

  // Auto-fill signature name + date from main student fields
  const mainStudentName = document.getElementById('student_name');
  const todayIso = new Date().toISOString().slice(0, 10);
  if (inputDate && !inputDate.value) inputDate.value = todayIso;
  if (mainStudentName && inputName && !inputName.value) inputName.value = (mainStudentName.value || '').trim();
  if (mainStudentName && inputName) {
    mainStudentName.addEventListener('input', () => {
      inputName.value = (mainStudentName.value || '').trim();
    });
  }

  let drawing = false;
let points = [];


  /* ==========================
     CANVAS SETUP (RETINA SAFE)
  ========================== */
  function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;

    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    ctx.strokeStyle = "#000";
  }

  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);

  /* ==========================
     DRAWING HELPERS
  ========================== */
  function getPos(e) {
    const rect = canvas.getBoundingClientRect();

    if (e.touches) {
      return {
        x: e.touches[0].clientX - rect.left,
        y: e.touches[0].clientY - rect.top
      };
    }
    return { x: e.offsetX, y: e.offsetY };
  }

  function startDraw(e) {
  e.preventDefault();
  drawing = true;
  points = [];

  const pos = getPos(e);
  points.push(pos);

  ctx.beginPath();
  ctx.moveTo(pos.x, pos.y);
}

function draw(e) {
  if (!drawing) return;
  e.preventDefault();

  const pos = getPos(e);
  points.push(pos);

  // First points: draw simple line
  if (points.length < 3) {
    ctx.lineTo(pos.x, pos.y);
    ctx.stroke();
    return;
  }

  // Take last 3 points
  const p0 = points[points.length - 3];
  const p1 = points[points.length - 2];
  const p2 = points[points.length - 1];

  // Midpoint between p1 and p2
  const midX = (p1.x + p2.x) / 2;
  const midY = (p1.y + p2.y) / 2;

  ctx.beginPath();
  ctx.moveTo(p0.x, p0.y);
  ctx.quadraticCurveTo(p1.x, p1.y, midX, midY);
  ctx.stroke();
}

function stopDraw() {
  drawing = false;
  points = [];
}

  /* ==========================
     EVENT LISTENERS
  ========================== */
  canvas.addEventListener('mousedown', startDraw);
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDraw);
  canvas.addEventListener('mouseleave', stopDraw);

  canvas.addEventListener('touchstart', startDraw, { passive: false });
  canvas.addEventListener('touchmove', draw, { passive: false });
  canvas.addEventListener('touchend', stopDraw);

  /* ==========================
     CLEAR SIGNATURE
  ========================== */
  btnClear.addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  });

  /* ==========================
     VALIDATION HELPERS
  ========================== */
  function hasSignature() {
    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    return pixels.some(channel => channel !== 0);
  }

  /* ==========================
     SUBMIT SIGNATURE - ENHANCED VALIDATION
  ========================== */
 btnSubmit.addEventListener('click', () => {

  /* ==========================
     1. SAFETY CHECKS
  ========================== */
  if (!inputName || !inputDate || !canvas) {
    alert("Les champs de signature obligatoires sont absents. Veuillez recharger la page.");
    return;
  }

  /* ==========================
     2. PACKAGE SELECTION (ARTICLE 7)
  ========================== */
  const selectedRadio = document.querySelector('input[name="package"]:checked');

  if (!selectedRadio) {
    alert("Veuillez choisir une formule de services à l’article 7 avant de signer.");
    selectedRadio?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const selectedPackageLabel = selectedRadio
    .closest('label')
    ?.textContent
    ?.trim();

  if (!selectedPackageLabel) {
    alert("Choix de formule invalide. Veuillez sélectionner de nouveau votre formule.");
    return;
  }

  /* ==========================
     3. STUDENT NAME VALIDATION
  ========================== */
  const studentName = inputName.value.trim();
  if (!studentName) {
    alert("Veuillez saisir votre nom complet avant de signer.");
    inputName.focus();
    return;
  }

  /* ==========================
     4. SIGNING DATE VALIDATION
  ========================== */
  const signedDate = inputDate.value;
  if (!signedDate) {
    alert("Veuillez choisir la date de signature.");
    inputDate.focus();
    return;
  }

  /* ==========================
     5. SIGNATURE VALIDATION
  ========================== */
  if (!hasSignature()) {
    alert("Veuillez dessiner votre signature avant d’envoyer.");
    return;
  }

  /* ==========================
     6. STUDENT DATA VALIDATION (FOR NEW STUDENTS)
  ========================== */
  const emailInput = document.getElementById('student_email');
  const dobInput = document.getElementById('student_dob');
  const nationalityInput = document.getElementById('student_nationality');
  const passportInput = document.getElementById('student_passport');
  const phoneInput = document.getElementById('student_phone');

  if (!emailInput?.value.trim()) {
    alert("Veuillez saisir votre adresse courriel.");
    emailInput?.focus();
    return;
  }

  if (!emailInput.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
    alert("Veuillez saisir une adresse courriel valide.");
    emailInput?.focus();
    return;
  }

  /* ==========================
     7. SHOW SUBMISSION PROGRESS
  ========================== */
  btnSubmit.disabled = true;
  btnSubmit.textContent = 'Envoi en cours...';
  btnSubmit.style.background = '#6b7280';

  /* ==========================
     8. CAPTURE SIGNATURE
  ========================== */
  const signature = canvas.toDataURL("image/png");
  hiddenSignature.value = signature;

  /* ==========================
     9. SUBMIT (FINAL)
  ========================== */
  submitSignature(
    signature,
    studentName,
    signedDate,
    selectedPackageLabel
  );
});


  /* ==========================
     SEND TO BACKEND
  ========================== */
function submitSignature(signature, name, date, selectedPackage) {

  /* ==========================
     1. HARD SAFETY CHECKS
  ========================== */
  if (!signature || !name || !date || !selectedPackage) {
    alert("Des renseignements obligatoires manquent. Vérifiez le formulaire, puis réessayez.");
    return;
  }

  /* ==========================
     2. STUDENT FIELD REFERENCES
  ========================== */
  const emailInput       = document.getElementById('student_email');
  const dobInput         = document.getElementById('student_dob');
  const nationalityInput = document.getElementById('student_nationality');
  const passportInput    = document.getElementById('student_passport');
  const phoneInput       = document.getElementById('student_phone');

  if (!emailInput || !dobInput || !nationalityInput || !passportInput || !phoneInput) {
    alert("Les champs de renseignements de l’étudiant sont absents. Veuillez recharger la page.");
    return;
  }

  /* ==========================
   BUILD PAYLOAD
========================== */
const payload = {
  token: "<?= htmlspecialchars($token) ?>",

  /* ==========================
     📦 ARTICLE 7 – PACKAGE (LOCKED)
  ========================== */
  selected_package_label: selectedPackage,
  selected_package_code: document.getElementById('selected_package_code')?.value || null,

  /* ==========================
     ✍️ SIGNATURE DATA
  ========================== */
  student_name: name,
  signed_date: date,
  signature: signature,

  /* ==========================
     👤 STUDENT DATA
  ========================== */
  student_email: emailInput.value.trim(),
  student_dob: dobInput.value,
  student_nationality: nationalityInput.value.trim(),
  student_passport: passportInput.value.trim(),
  student_phone: phoneInput.value.trim()
};

/* ==========================
   FINAL VALIDATION - ENHANCED
========================== */
if (!payload.student_email) {
  alert("Le courriel de l’étudiant est obligatoire.");
  emailInput.focus();
  return;
}

if (!payload.student_email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
  alert("Veuillez saisir une adresse courriel valide.");
  emailInput.focus();
  return;
}

if (!payload.student_name || payload.student_name.trim().length < 2) {
  alert("Veuillez saisir votre nom complet (au moins 2 caractères).");
  return;
}

if (!payload.selected_package_label) {
  alert("La formule choisie est absente. Veuillez la sélectionner de nouveau à l’article 7.");
  return;
}

if (!payload.selected_package_code) {
  alert("Le code de la formule est absent. Cliquez de nouveau sur une formule à l’article 7, puis signez.");
  return;
}

function resetSubmitButton() {
  const btn = document.getElementById('signContract');
  if (!btn) return;
  btn.disabled = false;
  btn.textContent = 'Signer et envoyer la convention';
  btn.style.background = '#3b82f6';
}

function extractServerErrorMessage(responseText, fallback) {
  const trimmed = (responseText || '').trim();
  if (!trimmed) {
    return fallback;
  }

  try {
    const parsed = JSON.parse(trimmed);
    if (parsed && parsed.error) {
      return parsed.error;
    }
  } catch (e) {
    // not JSON
  }

  const textOnly = trimmed.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (textOnly.length > 0 && textOnly.length <= 500) {
    return textOnly;
  }

  return fallback;
}

/* ==========================
   SUBMIT TO BACKEND
========================== */
fetch("submit-signature.php", {
  method: "POST",
  headers: {
    "Content-Type": "application/json"
  },
  body: JSON.stringify(payload)
})
.then(async res => {
  let data;

  // Debug: Log the raw response before attempting to parse
  const responseText = await res.text();
  console.log('Raw server response:', responseText);
  console.log('Response status:', res.status);
  console.log('Response headers:', Object.fromEntries(res.headers.entries()));

  try {
    data = JSON.parse(responseText);
  } catch (e) {
    console.error('JSON parse error:', e);
    console.error('Response text that failed to parse:', responseText);
    resetSubmitButton();
    throw new Error(extractServerErrorMessage(
      responseText,
      'Server returned an invalid response while signing. Please try again or contact support.'
    ));
  }

  // HTTP-level error but JSON returned
  if (!res.ok) {
    resetSubmitButton();
    throw new Error(data.error || "Server error");
  }

  return data;
})
.then(data => {

  // ✅ SUCCESS
  if (data.success) {
    if (data.pdf_error) {
      alert(
        "La convention est signée, mais le PDF n’a pas pu être créé automatiquement.\n\n" +
        "Raison : " + data.pdf_error + "\n\n" +
        "Vous pouvez recharger la page et télécharger de nouveau."
      );
    } else {
      alert(
        "Convention signée avec succès.\n\n" +
        "Vous pouvez maintenant télécharger ou consulter la convention signée."
      );
    }
    window.location.reload();
    return;
  }

  resetSubmitButton();

  // ⚠️ EXPECTED CASE: already signed
  if (data.error && data.error.toLowerCase().includes("already signed")) {
    alert(
      "Cette convention est déjà signée.\n\n" +
      "Vous pouvez maintenant télécharger ou consulter la convention signée."
    );
    window.location.reload();
    return;
  }

  // ❌ OTHER BACKEND ERRORS
  alert(data.error || "L’envoi a échoué.");

})
.catch(err => {
  console.error("Signature submission error:", err);
  resetSubmitButton();

  alert(err?.message || (
    "Envoi impossible pour le moment.\n" +
    "Vérifiez votre connexion, puis réessayez."
  ));
});


}

})();
</script>
<script>
(() => {
  'use strict';

  /* =====================================================
     FIELD REFERENCES (REAL INPUTS ONLY)
  ===================================================== */
  const fields = {
    email: document.getElementById('student_email'),
    name: document.getElementById('student_name'),
    dob: document.getElementById('student_dob'),
    nationality: document.getElementById('student_nationality'),
    passportNumber: document.getElementById('student_passport'), // ✅ REAL TEXTBOX
    phone: document.getElementById('student_phone')
  };

  /* =====================================================
     SAFETY CHECK
  ===================================================== */
  if (!fields.email) {
    console.warn('Student autofill: email field not found');
    return;
  }

  const DEBOUNCE_DELAY = 500;
  let debounceTimer   = null;
  let emailConfirmed = false;
  let autofilled     = false;

  /* =====================================================
     EMAIL-ONLY LIVE SEARCH
  ===================================================== */
/* =====================================================
   EMAIL INPUT LISTENER (RESET + SEARCH)
===================================================== */
fields.email.addEventListener('input', () => {
  const email = fields.email.value.trim();

  // ⛔ Reset everything immediately on email change
  resetStudentFields();

  clearTimeout(debounceTimer);

  // Too short → do nothing, manual entry allowed
  if (email.length < 3) {
    return;
  }

  // ⏳ Debounced search
  debounceTimer = setTimeout(() => {
    searchByEmail(email);
  }, DEBOUNCE_DELAY);
});
function resetStudentFields() {
  autofilled = false;
  emailConfirmed = false;

  Object.entries(fields).forEach(([key, input]) => {
    if (!input) return;

    // Clear all except email
    if (key !== 'email') {
      input.value = '';
    }

    input.readOnly = false;
    input.style.backgroundColor = '#fff';
  });

  console.log('Student fields reset due to email change');
}

  /* =====================================================
     FETCH STUDENT BY EMAIL
  ===================================================== */
  function searchByEmail(email) {
    fetch('student-autofill.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    })
      .then(res => res.json())
      .then(data => {
        if (!data || !data.possible_match || !data.student) return;
        autofillStudent(data.student);
      })
      .catch(err => console.error('Student autofill error:', err));
  }

  /* =====================================================
     AUTOFILL (SAFE & CLEAN) - ENHANCED FOR NEW STUDENTS
  ===================================================== */
  function autofillStudent(student) {
    console.log('autofillStudent called with:', student);
    if (!student || autofilled) return;

    // Check if fields are already populated with REAL data (not placeholders)
    const nameValue = fields.name.value.trim();
    const dobValue = fields.dob.value;
    const nationalityValue = fields.nationality.value.trim();
    const phoneValue = fields.phone.value.trim();
    const passportValue = fields.passportNumber.value.trim();
    
    // Check for placeholder text vs real data
    const hasRealName = nameValue && nameValue !== 'Enter your full legal name';
    const hasRealDob = dobValue && dobValue !== 'mm/dd/yyyy';
    const hasRealNationality = nationalityValue && nationalityValue !== 'Select nationality';
    const hasRealPhone = phoneValue && phoneValue !== '+250780000000'; // Basic format check
    const hasRealPassport = passportValue && passportValue !== 'Enter your passport number';
    
    const hasExistingData = hasRealName || hasRealDob || hasRealNationality || hasRealPhone || hasRealPassport;
    
    // Only skip autofill if ALL fields have real data (not just placeholders)
    if (hasExistingData && hasRealName && hasRealDob && hasRealNationality && hasRealPhone && hasRealPassport) {
      console.log('All fields already populated with real data, skipping autofill');
      autofilled = true;
      confirmStudent();
      return;
    }
    
    console.log('Fields have placeholders or incomplete data, proceeding with autofill');

    // For new students without data, don't try to autofill from empty student object
    if (!student.email && !student.first_name && !student.last_name) {
      console.log('No student data to autofill, allowing manual entry');
      autofilled = true; // Mark as processed to prevent repeated attempts
      // Don't lock fields for new students - let them enter data manually
      return;
    }

    // Always overwrite email with full DB email
    if (student.email) {
      fields.email.value = student.email;
    }

    // Only fill fields that are empty or have placeholder text
    if (fields.name && (!hasRealName)) {
      const composed = (student.full_name && String(student.full_name).trim())
        || [student.first_name, student.middle_name, student.last_name]
            .map(v => (v == null ? '' : String(v).trim()))
            .filter(Boolean)
            .join(' ');
      if (composed) {
        console.log('Setting Name:', composed, 'to field:', fields.name);
        fields.name.value = composed;
        // Sync the signature "Name" input that auto-mirrors student_name
        fields.name.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }

    if (fields.dob && (!hasRealDob) && student.dob) {
      console.log('Setting DOB:', student.dob, 'to field:', fields.dob);
      fields.dob.value = student.dob;
      console.log('DOB field value after setting:', fields.dob.value);
    }

    if (fields.nationality && (!hasRealNationality) && student.nationality) {
      console.log('Setting Nationality:', student.nationality, 'to field:', fields.nationality);
      fields.nationality.value = student.nationality;
    }

    if (fields.phone && (!hasRealPhone) && student.phone_number) {
      console.log('Setting Phone:', student.phone_number, 'to field:', fields.phone);
      fields.phone.value = student.phone_number;
    }

    // ✅ REAL PASSPORT NUMBER (TEXT FIELD)
    if (fields.passportNumber && (!hasRealPassport) && student.passport_number) {
      console.log('Setting Passport:', student.passport_number, 'to field:', fields.passportNumber);
      fields.passportNumber.value = student.passport_number;
      console.log('Passport field value after setting:', fields.passportNumber.value);
    }

    autofilled = true;
    confirmStudent();
  }

  /* =====================================================
     CONFIRM & LOCK
  ===================================================== */
  function confirmStudent() {
    if (emailConfirmed) return;

    emailConfirmed = true;
    lockFields();
    console.log('Student confirmed via email autofill');
  }

  /* =====================================================
     LOCK FIELDS (ENHANCED LOGIC)
  ===================================================== */
function lockFields() {
  Object.entries(fields).forEach(([key, input]) => {
    if (!input || key === 'email') return;

    // 🔓 If value is empty, user must type it
    if (!input.value || input.value.trim() === '') {
      input.readOnly = false;
      input.style.backgroundColor = '#fff';
      input.style.borderColor = '#e5e7eb';
      return;
    }

    // 🔒 Lock only autofilled fields with visual feedback
    input.readOnly = true;
    input.style.backgroundColor = '#f0f9ff';
    input.style.borderColor = '#3b82f6';
    input.title = 'This field was auto-filled from your existing record';
  });
}


  /* =====================================================
     PUBLIC HELPER
  ===================================================== */
  window.isStudentConfirmed = () => emailConfirmed;

})();
</script>
<script>
/**
 * =====================================================
 * ARTICLE 7 – PACKAGE SELECTION CONTROLLER
 * =====================================================
 * ✔ Works with existing onclick="showPkg('p7x')"
 * ✔ Ensures ONLY ONE package is visible at a time
 * ✔ Prevents UI conflicts
 * ✔ Safe with autofill & signature scripts
 * ✔ Ready for backend binding later
 * =====================================================
 */

(function () {
  'use strict';

  /**
   * Hide all package detail blocks
   */
  function hideAllPackages() {
    const packages = document.querySelectorAll('[id^="p7"]');
    packages.forEach(pkg => {
      pkg.style.display = 'none';
    });
  }

  /**
   * Public function used by inline onclick
   * @param {string} id
   */
window.showPkg = function (id) {
  hideAllPackages();

  const selected = document.getElementById(id);
  if (selected) {
    selected.style.display = 'block';
  }

  document.querySelectorAll('.package-item').forEach(item => {
    item.classList.remove('is-selected');
  });

  const radio = document.querySelector(`input[onclick*="showPkg('${id}')"]`);
  if (radio) {
    radio.checked = true;
    radio.closest('.package-item')?.classList.add('is-selected');
    if (!radio.value) {
      radio.value = id;
    }
  }

  const holder = document.getElementById('selected_package_code');
  if (holder) {
    holder.value = id;
  }
};

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('input[name="package"]').forEach(radio => {
    const match = (radio.getAttribute('onclick') || '').match(/showPkg\('([^']+)'\)/);
    if (match && !radio.value) {
      radio.value = match[1];
    }

    radio.addEventListener('change', function() {
      const onclickMatch = (this.getAttribute('onclick') || '').match(/showPkg\('([^']+)'\)/);
      if (onclickMatch) {
        showPkg(onclickMatch[1]);
      }
    });
  });

  if (document.body.classList.contains('contract-signed')) {
    document.querySelectorAll('input[name="package"]').forEach(radio => {
      radio.disabled = true;
    });
  }

  const presetCode = document.getElementById('selected_package_code')?.value?.trim();
  const checkedRadio = document.querySelector('input[name="package"]:checked');

  if (presetCode) {
    const presetRadio = document.querySelector(`input[name="package"][value="${presetCode}"]`)
      || document.querySelector(`input[onclick*="showPkg('${presetCode}')"]`);
    if (presetRadio) {
      presetRadio.checked = true;
    }
    showPkg(presetCode);
    return;
  }

  if (checkedRadio) {
    const match = (checkedRadio.getAttribute('onclick') || '').match(/showPkg\('([^']+)'\)/);
    if (match) {
      showPkg(match[1]);
    }
  }
});


  /**
   * Optional helper: get selected package number (for backend)
   */
  window.getSelectedPackage = function () {
    const selectedRadio = document.querySelector('input[name="package"]:checked');
    if (!selectedRadio) return null;

    const label = selectedRadio.closest('label');
    return label ? label.textContent.trim() : null;
  };

})();

/**
 * Highlight currency amounts in package fee blocks (Article 7).
 */
(function () {
  'use strict';

  const NUM = '(?:\\d{1,3}(?:,\\d{3})*|\\d+)(?:\\.\\d+)?';
  const PRICE_RE = new RegExp(
    '(?:(?:USD|CAD|EUR)\\s+' + NUM +
      '(?:\\s*(?:–|-|to)\\s*(?:(?:USD|CAD|EUR)\\s+)?' + NUM + ')?' +
      '|€\\s*' + NUM +
      '(?:\\s*(?:–|-)\\s*€?\\s*' + NUM + ')?)',
    'gi'
  );

  function highlightPricesInContainer(container) {
    if (!container || container.dataset.pricesHighlighted === '1') {
      return;
    }
    container.innerHTML = container.innerHTML.replace(
      PRICE_RE,
      (match) => '<span class="fee-price">' + match + '</span>'
    );
    container.dataset.pricesHighlighted = '1';
  }

  function initPriceHighlights() {
    document.querySelectorAll('.package-details, .additional-fees').forEach(highlightPricesInContainer);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPriceHighlights);
  } else {
    initPriceHighlights();
  }
})();
</script>


</body>
</html>

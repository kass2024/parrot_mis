<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>International Student Admission & Visa Consultancy Agreement</title>
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
   PAGE BACKGROUND
===================================================== */
body {
  margin: 0;
  padding: 48px 16px;
  background: linear-gradient(180deg, #eef2f7, #e5e7eb);
  font-family: "Inter", "Segoe UI", system-ui, sans-serif;
  color: var(--ink);
}

/* =====================================================
   CONTRACT SHEET
===================================================== */
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
  word-break: break-word;
}

/* =====================================================
   RESPONSIVE LAYOUT (TABLET & PHONE)
===================================================== */
.contract-page,
.contract-section {
  box-sizing: border-box;
}

@media (max-width: 768px) {
  body {
    padding: 24px 12px;
  }

  .contract-page {
    padding: 32px 16px;
    max-width: 100%;
    overflow-x: hidden;
    font-size: 11.2pt;
    line-height: 1.65;
  }

  .contract-page h1 {
    font-size: clamp(15pt, 4.5vw, 21pt);
    line-height: 1.25;
    letter-spacing: 0.04em;
  }

  .contract-page h3 {
    font-size: 13pt;
    margin-top: 24pt;
  }

  .contract-section {
    padding: 0 16px;
    max-width: 100%;
    overflow-x: hidden;
  }

  .package-label {
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-start;
    line-height: 1.35;
  }

  .package-details {
    padding-left: 8px;
    overflow-wrap: anywhere;
    word-break: break-word;
  }

  .signature-canvas {
    max-width: 100%;
    height: 120px;
  }
}

@media (max-width: 480px) {
  body {
    padding: 16px 8px;
  }

  .contract-page {
    padding: 22px 12px;
    font-size: 10.5pt;
    border-radius: 8px;
  }

  .contract-page h1 {
    font-size: 13pt;
  }

  .contract-section {
    padding: 0 10px;
  }

  .package-details {
    font-size: 10.5pt;
    padding-left: 4px;
  }
}

/* =====================================================
   HEADINGS
===================================================== */
.contract-page h1 {
  text-align: center;
  font-size: 24pt;
  font-weight: 700;
  letter-spacing: .6px;
  text-transform: uppercase;
  margin-bottom: 32pt;
}

.contract-page h3 {
  font-size: 15pt;
  font-weight: 700;
  margin-top: 34pt;
  margin-bottom: 14pt;
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
  padding: 10pt 12pt;
  border-radius: var(--radius-sm);
  transition: background .15s ease;
}

.package-item:hover {
  background: var(--soft);
}

.package-label {
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
}

.package-label input {
  margin-right: 10px;
}

.package-details {
  display: none;
  margin-top: 10pt;
  padding-left: 28px;
  font-size: 11.6pt;
  line-height: 1.7;
  color: var(--muted);
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
   SIGNATURE GRID
===================================================== */
.signature-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40pt 64pt;
}

@media (max-width: 768px) {
  .signature-grid {
    grid-template-columns: 1fr;
    gap: 28pt;
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

<body>

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
    INTERNATIONAL STUDENT ADMISSION<br>
    &amp; VISA CONSULTANCY AGREEMENT
  </h1>

  <!-- INTRO PARAGRAPH -->
  <p style="
    font-size:16px;
    text-align:justify;
    margin-bottom:30px;
  ">
    This <strong>International Student Admission and Visa Consultancy Agreement</strong>
    (“<strong>Agreement</strong>”) is made and entered into on the date of signature
    by and between:
  </p>

  <!-- ELECTRONIC NOTICE -->
  <p style="
    font-size:14px;
    font-style:italic;
    color:#374151;
    margin-bottom:40px;
  ">
    Please read this Agreement carefully. By signing electronically, you acknowledge
    that you fully understand and agree to all the terms and conditions contained herein.
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
    1. PARTIES
  </h3>

  <!-- 1.1 Consultant -->
  <p style="font-size:16px; font-weight:700; margin-bottom:8px;">
    1.1 The Consultant
  </p>
<p>
  <strong>Parrot Canada Visa Consultant Company Ltd.</strong><br>
  Registered Address: Gasanze Cell, Nduba Sector, Gasabo District, Kigali – Rwanda<br>
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
    1.2 The Student
  </p>

  <!-- STUDENT DETAILS (AUTOFILL SAFE) -->
  <div style="font-size:15px; max-width:720px;">
<div style="margin-bottom:18px;">
      <strong>Email:</strong>
      <input
        type="email"
        id="student_email"
        name="student_email"
        required
        autocomplete="email"
        style="border:none; border-bottom:1.5px solid #1d4ed8;
               width:58%; font-size:15px; font-family:inherit;
               outline:none; font-weight:600; color:#1d4ed8;">
    </div>
    <div style="margin-bottom:12px;">
      <strong>Full Legal Name:</strong>
      <input
        type="text"
        id="student_name"
        name="student_name"
        required
        autocomplete="name"
        style="border:none; border-bottom:1.5px solid #111827;
               width:62%; font-size:15px; font-family:inherit; outline:none;">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Date of Birth:</strong>
      <input
        type="date"
        id="student_dob"
        name="student_dob"
        required
        autocomplete="bday"
        style="border:none; border-bottom:1.5px solid #111827;
               width:42%; font-size:15px; font-family:inherit; outline:none;">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Nationality:</strong>
      <input
        type="text"
        id="student_nationality"
        name="student_nationality"
        required
        autocomplete="country-name"
        style="border:none; border-bottom:1.5px solid #111827;
               width:52%; font-size:15px; font-family:inherit; outline:none;">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Passport Number:</strong>
      <input
        type="text"
        id="student_passport"
        name="student_passport"
        autocomplete="off"
        style="border:none; border-bottom:1.5px solid #111827;
               width:52%; font-size:15px; font-family:inherit; outline:none;">
    </div>

    <div style="margin-bottom:12px;">
      <strong>Phone:</strong>
      <input
        type="tel"
        id="student_phone"
        name="student_phone"
        required
        autocomplete="tel"
        style="border:none; border-bottom:1.5px solid #111827;
               width:48%; font-size:15px; font-family:inherit; outline:none;">
    </div>

    

  </div>

  <p style="font-size:15px;">
    (Hereinafter referred to as <strong>“The Student”</strong>)
  </p>
<!-- ============================
     ARTICLES 2 – 6
============================ -->

<!-- ARTICLE 2 -->
<h3>2. PURPOSE OF AGREEMENT</h3>

<p>
  This Agreement governs the provision of
  <strong>international study admission, visa consultancy, and related advisory services</strong>
  for the Student intending to study or visit foreign countries, including
  <strong>Canada, the United States of America, Europe, and South Korea</strong>.
  The Student expressly acknowledges and agrees that
  <strong>
    all final decisions rest solely with educational institutions, embassies,
    immigration authorities, and other third-party entities
  </strong>,
  and are beyond the control of the Consultant.
</p>

<!-- ARTICLE 3 -->
<h3>3. SCOPE OF SERVICES</h3>

<p>
  Subject to the specific service package selected by the Student, the Consultant
  shall provide professional assistance which may include, but is not limited to:
</p>

<p>
  <strong>
    admission guidance, visa application assistance, document preparation support,
    interview preparation (where applicable), loan guidance for loan-based programs,
    job search assistance, accommodation search support, and pre-departure orientation
  </strong>.
</p>

<!-- ARTICLE 4 -->
<h3>4. CONSULTANT’S OBLIGATIONS</h3>

<p>The Consultant agrees to perform the services with professionalism and integrity and shall:</p>

<ol>
  <li>Provide services diligently, professionally, and in good faith</li>
  <li>Comply with applicable immigration, embassy, and institutional regulations</li>
  <li>Maintain confidentiality of all Student information in accordance with this Agreement</li>
  <li>Communicate material updates and progress transparently to the Student</li>
  <li>Refrain from falsification, misrepresentation, or unethical practices</li>
</ol>

<!-- ARTICLE 5 -->
<h3>5. STUDENT’S OBLIGATIONS</h3>

<p>The Student agrees and undertakes to:</p>

<ol>
  <li>Provide accurate, complete, and truthful personal and academic information</li>
  <li>Submit only genuine, valid, and verifiable documents</li>
  <li>Pay all applicable fees strictly within the prescribed timelines</li>
  <li>Cooperate fully with the Consultant throughout the admission and visa process</li>
  <li>
    Accept full responsibility for any consequences, delays, refusals, or losses
    arising from false, misleading, or incomplete information provided by the Student
  </li>
</ol>

<!-- ARTICLE 6 -->
<h3>6. NO GUARANTEE DISCLAIMER</h3>

<p>The Student expressly understands and agrees that:</p>

<ul>
  <li>
    Admission outcomes, visa approvals, loan approvals, and processing timelines
    are <strong>not guaranteed</strong> under any circumstances
  </li>
  <li>
    All decisions are made independently by educational institutions, embassies,
    immigration authorities, and other third-party entities
  </li>
  <li>
    Any refusal, delay, or unfavorable outcome shall not constitute a breach
    of this Agreement by the Consultant
  </li>
</ul>


<!-- ============================
     ARTICLE 7 – FEES & PAYMENT TERMS
============================ -->

<div class="contract-section">

<h3>7. FEES & PAYMENT TERMS (CONSOLIDATED PRICING)</h3>

<p>
  The Student shall select <strong>one (1)</strong> applicable service package from the options below.
  Fees apply <strong>only</strong> to the selected package.
</p>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p71')">
    7.1 🇺🇸 Study in the USA (Loan-Based)
  </label>
  <div id="p71" class="package-details">
    ✔ Admission Support<br>
    ➤ Registration & Application Fee: USD 150 (Refundable if admission is not secured within 4 months)<br>
    ➤ After Loan Approval: USD 1,200<br>
    ➤ MOCK Interview Preparation Fees: USD 150<br>
    ➤ After Visa Approval: USD 1,500<br>
    <strong>Total Package: USD 3,000</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p72')">
    7.2 🇺🇸 Study in the USA (Without Loan)
  </label>
  <div id="p72" class="package-details">
    ✔ Admission Support<br>
    ➤ Registration & Application Fee: USD 150 (Refundable if admission is not secured within 4 months)<br>
    ➤ MOCK Interview Preparation Fees: USD 150<br>
    ➤ After Visa Approval: USD 2,000<br>
    <strong>Total Package: USD 2,300</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p73')">
    7.3 🇪🇺 Study in Europe (Without Loan)
  </label>
  <div id="p73" class="package-details">
    ➤ Registration & Application Fee: USD 250 (Refundable if admission is not secured within 4 months)<br>
    ➤ Before Visa Application: USD 250<br>
    ➤ After Visa Approval: USD 1,500<br>
    <strong>Total Package: USD 2,000</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p74')">
    7.4 🇨🇦 Study in Canada (Loan-Based)
  </label>
  <div id="p74" class="package-details">
    ➤ Registration & Application Fee: CAD 450 (Refundable if admission is not secured within 4 months)<br>
    ➤ After Visa Approval: CAD 3,050<br>
    <strong>Total Package: CAD 3,500</strong><br>
    <em>Note: Tuition deposit CAD 1,500–5,000 payable directly by the Student.</em>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p75')">
    7.5 🇨🇦 Study in Canada (Without Loan)
  </label>
  <div id="p75" class="package-details">
    ➤ Registration & Application Fee: CAD 450 (Refundable if admission is not secured within 4 months)<br>
    ➤ Before Visa Application: USD 550<br>
    ➤ After Visa Approval: CAD 1,500<br>
    <strong>Total Package: CAD 2,500</strong><br>
    <em>Note: Tuition deposit CAD 1,500–5,000 payable directly by the Student.</em>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p76')">
    7.6 🇨🇦 Canada – High School Graduate (Loan-Based)
  </label>
  <div id="p76" class="package-details">
    ➤ Registration & Application Fee: CAD 450<br>
    ➤ Study Permit Fees: CAD 150<br>
    ➤ Biometrics Fees: CAD 85<br>
    ➤ CAQ Fees (Quebec Only): CAD 132<br>
    ➤ Border Pass Fees: CAD 250<br>
    ➤ Loan Processing Fees: CAD 1,000<br>
    ➤ Service Fees After Visa Approval: CAD 1,933<br>
    <strong>Total Package: CAD 4,000</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p77ca')">
    7.7 🇨🇦 Study in Canada (With Your Own Admission Letter)
  </label>
  <div id="p77ca" class="package-details">
    ➤ Document Handling, Visa Application & Biometric Fees: CAD 735<br>
    ➤ Service Fees (payable after visa approval): CAD 1,000<br>
    <strong>🔥 Total Package: CAD 1,735</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p77')">
    7.8 🇰🇷 Study in South Korea (Self-Sponsored)
  </label>
  <div id="p77" class="package-details">
    <!-- ➤ Registration & Application Fee: USD 500 (Refundable if admission is not secured)<br> -->
    ➤ Registration & Application Fee: USD 1000 (Refundable if admission is not secured)<br> 
    ➤ Service Fees (After Visa Approval): USD 1,400<br>
    <!-- ➤ Service Fees – Bachelor’s: USD 1,800<br>
    ➤ Service Fees – Master’s: USD 2,000<br>
    ➤ Service Fees – PhD: USD 2,200<br> -->
    ✔ Free Korean language training (3 months)<br>
    <!-- ✔ 50% payable upon admission, balance before visa application -->
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p78')">
    7.9 🇰🇷 South Korea Visitor Visa
  </label>
  <div id="p78" class="package-details">
    ➤ Registration & Application Fee: USD 500<br>
    ➤ Service Fee (Paid After Receiving the Invitation Letter and Guarantee Letter): USD 1,500<br>
    ➤ Participation fees vary depending on the event organizer.
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p79')">
    7.10 Credit Transfer (Bachelor, Masters, PhD)
  </label>
  <div id="p79" class="package-details">
    ➤ Bachelor: USD 920<br>
    ➤ Masters: USD 1,220<br>
    ➤ PhD: USD 1,620
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p710')">
    7.11 🇨🇦 Canada Visit Visa
  </label>
  <div id="p710" class="package-details">
    ➤ Documents & Invitation Letter: USD 1,000<br>
    ➤ Visa Application Fees: CAD 100<br>
    ➤ Biometrics Fees: CAD 85<br>
    ➤ Service Fees (After Visa Approval): CAD 2,000
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p711')">
    7.12 🇺🇸 USA Visit Visa
  </label>
  <div id="p711" class="package-details">
    ➤ Documents & Invitation Letter: USD 1,000<br>
    ➤ Visa Application Fees: USD 185<br>
    ➤ Service Fees (After Visa Approval): USD 1,500
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p712')">
    7.13 🇪🇺 Europe Visit Visa
  </label>
  <div id="p712" class="package-details">
    ➤ Documents & Invitation Letter: €600<br>
    ➤ Visa Application Fees: €85 – €500 (depending on country)<br>
    ➤ Service Fees (After Visa Approval): €1,000
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p713')">
    7.14 Asia Visit Visa
  </label>
  <div id="p713" class="package-details">
    ➤ Documents & Invitation Letter: USD 800<br>
    ➤ Visa Application Fees: USD 85 – USD 500<br>
    ➤ Service Fees (After Visa Approval): USD 1,500
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p714')">
    7.15 SHORT COURSES-CANADA
  </label>
  <div id="p714" class="package-details">
    ➤ Registration & Application Fee: CAD 200 (Refundable if admission is not secured within 2 weeks)<br>
    ➤ Registration & Application Fee for Family Member: CAD 100 (If applicable)<br>
    ➤ Tuition Fees Deposit after getting offer letter: CAD 535 (Paid directly to school)<br>
    ➤ Before starting Visa Application: CAD 100 for visit visa application (Paid to embassy)<br>
    ➤ Biometrics: CAD 85 (Paid to embassy)<br>
    ➤ After Visa Approval: CAD 2,500<br>
    <strong>Total Package: CAD 3,420</strong>
  </div>
</div>

<div class="package-item">
  <label class="package-label">
    <input type="radio" name="package" onclick="showPkg('p715')">
    7.16 STUDY PhD IN CANADA-USA-EUROPE & ASIA
  </label>
  <div id="p715" class="package-details">
    ➤ Registration & Application Fee for Canada: CAD 500 (Refundable if admission is not secured within 9 months)<br>
    ➤ Registration & Application Fee for USA, Europe & Asia: USD 350 (Refundable if admission is not secured within 9 months)<br>
    ➤ Paper Publication Fee (Non-Refundable): USD 280 (If applicable)<br>
    ➤ Assistance for PhD Research Proposal Writing (Non-Refundable): USD 300 (If applicable)<br>
    ➤ Visa Application Fee for Family Member (Non-Refundable): Canada CAD 400 (If applicable)<br>
    ➤ Visa Application Fee for Family Member (Non-Refundable): USA, Europe & Asia USD 250 (If applicable)<br>
    ➤ Tuition Fees Deposit after getting offer letter for Canada: CAD 1,000 to CAD 5,000 (Paid directly to school after getting admission)<br>
    ➤ Tuition Fees Deposit after getting offer letter for USA, Europe & Asia: USD 500 to USD 5,000 (Paid directly to school after getting admission)<br>
    ➤ After Visa Approval for Canada: CAD 5,000<br>
    ➤ After Visa Approval for USA, Europe & Asia: USD 4,500<br>
    <em>Note: All visa application fees must be paid to the embassy by the applicant. An additional fee of CAD 800 (Canada) or USD 500 (USA, Europe, and Asia) applies for each family member after visa approval.</em>
  </div>
</div>

<p class="contract-warning">
  ⚠ <strong>Important:</strong> All government fees, embassy charges, biometric fees,
  tuition deposits, lawyer fees, border pass fees, and third-party charges
  are paid separately by the Student and are non-refundable once submitted.
</p>

<div class="additional-fees">
  <h4>Additional Pricing Provisions (Without Loan & Special Services)</h4>

  <p>1. Spring, Winter, Summer, or Fall Short Courses (Worldwide)</p>
  <ul>
    <li>Application and Registration Fees: <strong>EUR 250</strong>, refundable if approval is not secured within four (4) months.</li>
    <li>Service Fees: <strong>EUR 2,000</strong>, payable only once the visa is approved.</li>
  </ul>

  <p>2. Canadian Immigration Lawyer – Visa Application (Canada Only)</p>
  <p>Additional charge of <strong>CAD 300 per applicant</strong>.</p>

  <p>3. Canadian Immigration Lawyer – Legal Advice or Consultation (Canada Only)</p>
  <p>Consultation fee of <strong>CAD 300</strong>.</p>

  <div class="important-note">
    <strong>⚠ Important:</strong>
    All government fees, embassy charges, biometric fees, SEVIS fees, tuition deposits,
    lawyer fees, border pass fees, and third-party charges are non-refundable once submitted.
  </div>
</div>

<input type="hidden" id="selected_package_code" value="">

</div>

<!-- ============================
     ARTICLE 8 – PAYMENT OF SERVICE FEES
============================ -->

<div class="contract-section">

<h3>8. PAYMENT OF SERVICE FEES</h3>

<p>
  Where applicable, final service fees become immediately payable upon visa approval.
  Once the visa is approved, the Student shall pay all outstanding service fees
  <strong>no later than five (5) days</strong> from the date of approval.
  Failure to make payment within this period constitutes a
  <strong>material breach of this Agreement</strong> and may result in
  legal action and/or enforcement in accordance with applicable law.
</p>

</div>

<!-- ============================
     ARTICLE 9 – REFUND POLICY
============================ -->

<div class="contract-section">

<h3>9. REFUND POLICY</h3>

<p>
  Only registration fees are refundable strictly under the conditions expressly
  stated in this Agreement. All other fees, including but not limited to
  <strong>service fees, loan processing fees, legal fees, and third-party charges</strong>,
  are non-refundable regardless of visa or admission outcome.
</p>

</div>

<!-- ============================
     ARTICLE 10 – TERMINATION
============================ -->

<div class="contract-section">

<h3>10. TERMINATION</h3>

<p>
  The Consultant reserves the right to terminate this Agreement immediately
  in the event of non-payment, submission of fraudulent or misleading documents,
  or breach of any obligation by the Student. Termination shall not release
  the Student from the obligation to pay all outstanding fees already incurred.
</p>

</div>

<!-- ============================
     ARTICLE 11 – LIMITATION OF LIABILITY
============================ -->

<div class="contract-section">

<h3>11. LIMITATION OF LIABILITY</h3>

<p>
  The Consultant shall not be liable for decisions, delays, refusals, or outcomes
  issued by embassies, educational institutions, government authorities,
  policy changes, or other third-party entities beyond the Consultant’s control.
</p>

</div>

<!-- ============================
     ARTICLE 12 – CONFIDENTIALITY
============================ -->

<div class="contract-section">

<h3>12. CONFIDENTIALITY</h3>

<p>
  All information exchanged between the parties shall be treated as confidential
  and shall not be disclosed to any third party except where disclosure is
  required by law or by competent authorities.
</p>

</div>

<!-- ============================
     ARTICLE 13 – GOVERNING LAW & JURISDICTION
============================ -->

<div class="contract-section">

<h3>13. GOVERNING LAW &amp; JURISDICTION</h3>

<p>
  This Agreement shall be governed by and construed in accordance with the
  laws of the <strong>Republic of Rwanda</strong>, with exclusive jurisdiction
  vested in the competent courts of Rwanda.
</p>

</div>

<!-- ============================
     ARTICLE 14 – ENTIRE AGREEMENT
============================ -->

<div class="contract-section">

<h3>14. ENTIRE AGREEMENT</h3>

<p>
  This Agreement constitutes the entire understanding between the parties
  and supersedes all prior discussions or representations. Any amendment or
  modification shall be valid only if made in writing and signed by both parties.
</p>

</div>
<!-- ============================
     15. SIGNATURES
============================ -->
<div class="contract-section" style="margin-top:40px;">

<h3 style="font-size:20px;font-weight:700;margin-bottom:30px;">
<!-- ============================
     15. SIGNATURES
============================ -->
<div class="contract-section" style="margin-top:40px;">

<h3 style="font-size:20px;font-weight:700;margin-bottom:32px;">
15. SIGNATURES
</h3>

<!-- ============================
     ROW 1 : REPRESENTATIVE + NOTARY
============================ -->
<div style="
display:grid;
grid-template-columns:1fr 1fr;
gap:120px;
margin-bottom:60px;
">

<!-- CONSULTANT REPRESENTATIVE -->
<div>

<p style="font-weight:700;margin-bottom:18px;font-size:16px;">
For the Consultant Representative
</p>

<div style="margin-bottom:18px;">
Name: <strong>Jean Pierre TWAJAMAHORO</strong>
</div>

<div style="margin-bottom:18px;">
Title: <strong>Managing Director</strong>
</div>

<div style="margin-bottom:18px;">
Signature:
<div style="border-bottom:1px solid #000;height:60px;width:85%;margin-top:6px;position:relative;">
  <img src="admin/signature-manager.png" style="max-height:55px;position:absolute;bottom:2px;left:0;">
</div>
</div>

<div>
Date:
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;"></div>
</div>

</div>


<!-- NOTARY -->
<div>

<p style="font-weight:700;margin-bottom:18px;font-size:16px;">
For the Notary
</p>

<div style="margin-bottom:18px;">
Name:
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;"></div>
</div>

<div style="margin-bottom:18px;">
Signature:
<div style="border-bottom:1px solid #000;height:45px;width:85%;margin-top:6px;"></div>
</div>

<div>
Date:
<div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;"></div>
</div>

</div>

</div>


<!-- ============================
     ROW 2 : STUDENT (CENTERED)
============================ -->
<div style="
max-width:420px;
margin:auto;
text-align:left;
">

<p style="font-weight:700;margin-bottom:18px;font-size:16px;">
For the Student
</p>

<div style="margin-bottom:16px;">
Name:
<input type="text" id="sig_student_name"
style="
width:70%;
border:none;
border-bottom:1px solid #000;
margin-left:6px;
padding:2px 4px;
">
</div>

<p style="margin-top:16px;">Signature:</p>

<div style="
border:1px dashed #9ca3af;
height:130px;
padding:10px;
margin-bottom:14px;
background:#fafafa;
display:flex;
align-items:center;
justify-content:center;
">

<?php if ($isSigned && !empty($studentSignatureData)): ?>
<img src="<?= $studentSignatureData ?>" style="max-height:110px;">
<?php else: ?>
<canvas class="signature-canvas"></canvas>
<?php endif; ?>

</div>

<div style="margin-top:10px;">
Date:
<input type="date" id="sig_signed_date"
style="
width:60%;
border:none;
border-bottom:1px solid #000;
margin-left:6px;
padding:2px 4px;
">
</div>

<div style="margin-top:18px;">
<button id="clearSignature" type="button">Clear</button>
<button id="signContract" type="button">Sign & Submit</button>
<input type="hidden" id="signatureData">
</div>

</div>

</div>
  <!-- FOOTER REF -->
  <div style="
    text-align:center;
    margin-top:40px;
    font-size:12px;
    color:#6b7280;
  ">
    Contract Reference: <?= htmlspecialchars($contract['contract_token']) ?>
  </div>

</div>
</div>
<script>
(() => {
  /* ==========================
     CONFIG & ELEMENTS
  ========================== */
  const canvas = document.querySelector('.signature-canvas');
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
     SUBMIT SIGNATURE
  ========================== */
 btnSubmit.addEventListener('click', () => {

  /* ==========================
     1. SAFETY CHECKS
  ========================== */
  if (!inputName || !inputDate || !canvas) {
    alert("Required signature fields are missing. Please reload the page.");
    return;
  }

  /* ==========================
     2. PACKAGE SELECTION (ARTICLE 7)
  ========================== */
  const selectedRadio = document.querySelector('input[name="package"]:checked');

  if (!selectedRadio) {
    alert("Please select one service package under Article 7 before signing.");
    return;
  }

  const selectedPackageLabel = selectedRadio
    .closest('label')
    ?.textContent
    ?.trim();

  if (!selectedPackageLabel) {
    alert("Invalid package selection. Please reselect your package.");
    return;
  }

  /* ==========================
     3. STUDENT NAME VALIDATION
  ========================== */
  const studentName = inputName.value.trim();
  if (!studentName) {
    alert("Please enter your full name before signing.");
    inputName.focus();
    return;
  }

  /* ==========================
     4. SIGNING DATE VALIDATION
  ========================== */
  const signedDate = inputDate.value;
  if (!signedDate) {
    alert("Please select the signing date.");
    inputDate.focus();
    return;
  }

  /* ==========================
     5. SIGNATURE VALIDATION
  ========================== */
  if (!hasSignature()) {
    alert("Please draw your signature before submitting.");
    return;
  }

  /* ==========================
     6. CAPTURE SIGNATURE
  ========================== */
  const signature = canvas.toDataURL("image/png");
  hiddenSignature.value = signature;

  /* ==========================
     7. SUBMIT (FINAL)
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
    alert("Missing required data. Please review the form and try again.");
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
    alert("Student information fields are missing. Please reload the page.");
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
   FINAL VALIDATION
========================== */
if (!payload.student_email) {
  alert("Student email is required.");
  emailInput.focus();
  return;
}

if (!payload.selected_package_label) {
  alert("Selected package is missing. Please reselect a package under Article 7.");
  return;
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

  try {
    data = await res.json();
  } catch (e) {
    throw new Error("Invalid JSON response from server");
  }

  // HTTP-level error but JSON returned
  if (!res.ok) {
    throw new Error(data.error || "Server error");
  }

  return data;
})
.then(data => {

  // ✅ SUCCESS
  if (data.success) {
    alert(
      "Contract signed successfully.\n\n" +
      "You can now download or view the signed agreement."
    );
    window.location.reload();
    return;
  }

  // ⚠️ EXPECTED CASE: already signed
  if (data.error && data.error.toLowerCase().includes("already signed")) {
    alert(
      "This contract has already been signed.\n\n" +
      "You can now download or view the signed agreement."
    );
    window.location.reload();
    return;
  }

  // ❌ OTHER BACKEND ERRORS
  alert(data.error || "Submission failed.");

})
.catch(err => {
  console.error("Signature submission error:", err);

  alert(err?.message || (
    "Unable to submit at this time.\n" +
    "Please check your connection and try again."
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
     AUTOFILL (SAFE & CLEAN)
  ===================================================== */
  function autofillStudent(student) {
    if (!student || autofilled) return;

    // Always overwrite email with full DB email
    if (student.email) {
      fields.email.value = student.email;
    }

    if (fields.name && (student.first_name || student.last_name)) {
      fields.name.value = [student.first_name, student.last_name]
        .filter(Boolean)
        .join(' ');
    }

    if (fields.dob && student.dob) {
      fields.dob.value = student.dob;
    }

    if (fields.nationality && student.nationality) {
      fields.nationality.value = student.nationality;
    }

    if (fields.phone && student.phone_number) {
      fields.phone.value = student.phone_number;
    }

    // ✅ REAL PASSPORT NUMBER (TEXT FIELD)
    if (fields.passportNumber && student.passport_number) {
      fields.passportNumber.value = student.passport_number;
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
     LOCK FIELDS (EXCEPT EMAIL)
  ===================================================== */
function lockFields() {
  Object.entries(fields).forEach(([key, input]) => {
    if (!input || key === 'email') return;

    // 🔓 If value is empty, user must type it
    if (!input.value || input.value.trim() === '') {
      input.readOnly = false;
      input.style.backgroundColor = '#fff';
      return;
    }

    // 🔒 Lock only autofilled fields
    input.readOnly = true;
    input.style.backgroundColor = '#f7f9fc';
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

  // ✅ SAVE SELECTED PACKAGE CODE
  const holder = document.getElementById('selected_package_code');
  if (holder) {
    holder.value = id; // e.g. "p74"
  }
};


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
</script>
<script>
(function () {
  'use strict';

  const canvas = document.querySelector('.signature-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  const btnClear = document.getElementById('clearSignature');
  const btnSubmit = document.getElementById('signContract');

  const inputName = document.getElementById('sig_student_name');
  const inputDate = document.getElementById('sig_signed_date');

  const progressBox  = document.getElementById('signatureProgress');
  const progressBar  = document.getElementById('signatureProgressBar');
  const progressText = document.getElementById('signatureProgressText');

  let drawing = false;
  let progressTimer = null;

  /* ============================
     CANVAS SETUP
  ============================ */
  function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#111827';
  }

  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);

  function pos(e) {
    const r = canvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }

  canvas.addEventListener('mousedown', e => {
    drawing = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  });

canvas.addEventListener('mousemove', e => {
  if (!drawing) return;
  e.preventDefault();

  const p = pos(e);
  points.push(p);

  // Not enough points yet → simple line
  if (points.length < 3) {
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    return;
  }

  // Last 3 points
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
});


  canvas.addEventListener('mouseup', () => drawing = false);
  canvas.addEventListener('mouseleave', () => drawing = false);

  canvas.addEventListener('touchstart', e => {
    drawing = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }, { passive:false });

  canvas.addEventListener('touchmove', e => {
    if (!drawing) return;
    e.preventDefault();
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }, { passive:false });

  canvas.addEventListener('touchend', () => drawing = false);

  btnClear.addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  });

  function hasSignature() {
    return ctx.getImageData(0,0,canvas.width,canvas.height)
      .data.some(v => v !== 0);
  }

  /* ============================
     PROGRESS HELPERS
  ============================ */
  function startProgress() {
    btnSubmit.disabled = true;
    progressBox.style.display = 'block';
    progressBar.style.width = '10%';
    progressText.textContent = 'Submitting signature…';

    let p = 10;
    progressTimer = setInterval(() => {
      p += Math.random() * 12;
      if (p >= 90) {
        p = 90;
        clearInterval(progressTimer);
      }
      progressBar.style.width = p + '%';
    }, 400);
  }

  function finishProgress(success) {
    clearInterval(progressTimer);
    progressBar.style.width = '100%';
    progressBar.style.background = success ? '#16a34a' : '#dc2626';
    progressText.textContent = success
      ? 'Signature submitted successfully'
      : 'Submission failed';
  }

  /* ============================
     SUBMIT HANDLER
  ============================ */
  btnSubmit.addEventListener('click', () => {

    if (!inputName.value.trim()) {
      alert('Please enter your name.');
      return;
    }

    if (!inputDate.value) {
      alert('Please select a signing date.');
      return;
    }

    if (!hasSignature()) {
      alert('Please draw your signature.');
      return;
    }

    startProgress();

    // 👇 KEEP YOUR EXISTING submitSignature() CALL
    submitSignature(
      canvas.toDataURL('image/png'),
      inputName.value.trim(),
      inputDate.value,
      window.getSelectedPackage?.()
    );
  });

  // OPTIONAL: expose hooks for backend response
  window.signatureSubmitSuccess = () => finishProgress(true);
  window.signatureSubmitError   = () => finishProgress(false);

})();
</script>


</body>
</html>

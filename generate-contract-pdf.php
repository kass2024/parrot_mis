<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

/* =====================================================
   SAFE ESCAPE
===================================================== */
function esc(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =====================================================
   ARTICLE 7 – PACKAGE MAP (SINGLE SOURCE OF TRUTH)
===================================================== */
function getPackageDetails(string $code): array
{
    $packages = [

        /* =========================
           7.1 USA – Loan Based
        ========================== */
        'p71' => [
            'title' => '7.1 Study in the USA (Loan-Based)',
            'lines' => [
                'Registration & Application Fee: USD 150 (Refundable if admission is not secured within 4 months)',
                'After Loan Approval: USD 1,200',
                'Mock Interview Preparation Fees: USD 150',
                'After Visa Approval: USD 1,500',
            ],
            'total' => 'USD 3,000',
        ],

        /* =========================
           7.2 USA – Without Loan
        ========================== */
        'p72' => [
            'title' => '7.2 Study in the USA (Without Loan)',
            'lines' => [
                'Registration & Application Fee: USD 150 (Refundable if admission is not secured within 4 months)',
                'Mock Interview Preparation Fees: USD 150',
                'After Visa Approval: USD 2,000',
            ],
            'total' => 'USD 2,300',
        ],

        /* =========================
           7.3 Europe – Without Loan
        ========================== */
        'p73' => [
            'title' => '7.3 Study in Europe (Without Loan)',
            'lines' => [
                'Registration & Application Fee: USD 250 (Refundable if admission is not secured within 4 months)',
                'Before Visa Application: USD 250',
                'After Visa Approval: USD 1,500',
            ],
            'total' => 'USD 2,000',
        ],

        /* =========================
           7.4 Canada – Loan Based
        ========================== */
        'p74' => [
            'title' => '7.4 Study in Canada (Loan-Based)',
            'lines' => [
                'Registration & Application Fee: CAD 450 (Refundable if admission is not secured within 4 months)',
                'After Visa Approval: CAD 3,050',
                'Note: Tuition deposit CAD 1,500–5,000 payable directly by the Student',
            ],
            'total' => 'CAD 3,500',
        ],

        /* =========================
           7.5 Canada – Without Loan
        ========================== */
        'p75' => [
            'title' => '7.5 Study in Canada (Without Loan)',
            'lines' => [
                'Registration & Application Fee: CAD 450 (Refundable if admission is not secured within 4 months)',
                'Before Visa Application: USD 550',
                'After Visa Approval: CAD 1,500',
                'Note: Tuition deposit CAD 1,500–5,000 payable directly by the Student',
            ],
            'total' => 'CAD 2,500',
        ],

        /* =========================
           7.6 Canada – High School (Loan)
        ========================== */
        'p76' => [
            'title' => '7.6 Canada – High School Graduate (Loan-Based)',
            'lines' => [
                'Registration & Application Fee: CAD 450',
                'Study Permit Fees (Embassy): CAD 150',
                'Biometrics Fees (Embassy): CAD 85',
                'CAQ Fees (Quebec Only): CAD 132',
                'Border Pass Fees (Lawyer): CAD 250',
                'Loan Processing Fees: CAD 1,000',
                'Service Fees After Visa Approval: CAD 1,933',
            ],
            'total' => 'CAD 4,000',
        ],

        /* =========================
           7.7 Canada – Own Admission Letter
        ========================== */
        'p77ca' => [
            'title' => '7.7 Study in Canada (With Your Own Admission Letter)',
            'lines' => [
                'Document Handling, Visa Application & Biometric Fees: CAD 735',
                'Service Fees (payable after visa approval): CAD 1,000',
            ],
            'total' => 'CAD 1,735',
        ],

        /* =========================
           7.8 South Korea – Study
        ========================== */
        'p77' => [
            'title' => '7.8 Study in South Korea (Self-Sponsored)',
            'lines' => [
                // 'Registration & Application Fee: USD 500 (Refundable if admission is not secured)',
                'Registration & Application Fee: USD 1000 (Refundable if admission is not secured)',
                'Service Fees (After Visa Approval): USD 1,400',
                // 'Service Fees – Bachelor’s Program: USD 1,800',
                // 'Service Fees – Master’s Program: USD 2,000',
                // 'Service Fees – PhD Program: USD 2,200',
                'Includes free Korean language training (3 months)',
                // '50% payable upon admission, balance before visa application',
            ],
            'total' => null,
        ],

        /* =========================
           7.8 South Korea – Visit
        ========================== */
        'p78' => [
            'title' => '7.9 🇰🇷 South Korea Visitor Visa',
            'lines' => [
                'Registration & Application Fee: USD 500',
                'Service Fee (Paid After Receiving the Invitation Letter and Guarantee Letter): USD 1,500',
                'Participation fees vary depending on the event organizer.',
            ],
            'total' => null,
        ],

        /* =========================
           7.9 Credit Transfer
        ========================== */
        'p79' => [
            'title' => '7.10 Credit Transfer (Bachelor, Masters, PhD)',
            'lines' => [
                'Bachelor Program: USD 920',
                'Masters Program: USD 1,220',
                'PhD Program: USD 1,620',
            ],
            'total' => null,
        ],

        /* =========================
           7.10 Canada Visit Visa
        ========================== */
        'p710' => [
            'title' => '7.11 Canada Visit Visa',
            'lines' => [
                'Documents & Invitation Letter: USD 1,000',
                'Visa Application Fees: CAD 100',
                'Biometrics Fees: CAD 85',
                'Service Fees (After Visa Approval): CAD 2,000',
            ],
            'total' => null,
        ],

        /* =========================
           7.11 USA Visit Visa
        ========================== */
        'p711' => [
            'title' => '7.12 USA Visit Visa',
            'lines' => [
                'Documents & Invitation Letter: USD 1,000',
                'Visa Application Fees: USD 185',
                'Service Fees (After Visa Approval): USD 1,500',
            ],
            'total' => null,
        ],

        /* =========================
           7.12 Europe Visit Visa
        ========================== */
        'p712' => [
            'title' => '7.13 Europe Visit Visa',
            'lines' => [
                'Documents & Invitation Letter: €600',
                'Visa Application Fees: €85 – €500 (depending on country)',
                'Service Fees (After Visa Approval): €1,000',
            ],
            'total' => null,
        ],

        /* =========================
           7.13 Asia Visit Visa
        ========================== */
        'p713' => [
            'title' => '7.14 Asia Visit Visa',
            'lines' => [
                'Documents & Invitation Letter: USD 800',
                'Visa Application Fees: USD 85 – USD 500',
                'Service Fees (After Visa Approval): USD 1,500',
            ],
            'total' => null,
        ],

        /* =========================
           7.14 Short Courses - Canada
        ========================== */
        'p714' => [
            'title' => '7.15 SHORT COURSES-CANADA',
            'lines' => [
                'Registration & Application Fee: CAD 200 (Refundable if admission is not secured within 2 weeks)',
                'Registration & Application Fee for Family Member: CAD 100 (If applicable)',
                'Tuition Fees Deposit after getting offer letter: CAD 535 (Paid directly to school)',
                'Before starting Visa Application: CAD 100 for visit visa application (Paid to embassy)',
                'Biometrics: CAD 85 (Paid to embassy)',
                'After Visa Approval: CAD 2,500',
            ],
            'total' => 'CAD 3,420',
        ],

        /* =========================
           7.15 Study PhD in Multiple Destinations
        ========================== */
        'p715' => [
            'title' => '7.16 STUDY PhD IN CANADA-USA-EUROPE & ASIA',
            'lines' => [
                'Registration & Application Fee for Canada: CAD 500 (Refundable if admission is not secured within 9 months)',
                'Registration & Application Fee for USA, Europe & Asia: USD 350 (Refundable if admission is not secured within 9 months)',
                'Paper Publication Fee (Non-Refundable): USD 280 (If applicable)',
                'Assistance for PhD Research Proposal Writing (Non-Refundable): USD 300 (If applicable)',
                'Visa Application Fee for Family Member (Non-Refundable): Canada CAD 400 (If applicable)',
                'Visa Application Fee for Family Member (Non-Refundable): USA, Europe & Asia USD 250 (If applicable)',
                'Tuition Fees Deposit after getting offer letter for Canada: CAD 1,000 to CAD 5,000 (Paid directly to school after getting admission)',
                'Tuition Fees Deposit after getting offer letter for USA, Europe & Asia: USD 500 to USD 5,000 (Paid directly to school after getting admission)',
                'After Visa Approval for Canada: CAD 5,000',
                'After Visa Approval for USA, Europe & Asia: USD 4,500',
                'Note: All visa application fees must be paid to the embassy by the applicant. An additional fee of CAD 800 (Canada) or USD 500 (USA, Europe, and Asia) applies for each family member after visa approval.',
            ],
            'total' => null,
        ],
    ];

    return $packages[$code] ?? [];
}

/* =====================================================
   GENERATE FINAL SIGNED CONTRACT PDF
===================================================== */
function generateContractPDF(int $contractId): string
{
    global $conn;

    /* =====================================================
       1. LOAD FULL CONTRACT + STUDENT + SIGNATURE
    ===================================================== */
$stmt = $conn->prepare("
    SELECT
        c.contract_token,
        c.selected_package_code,

        /* ✅ SINGLE SOURCE OF TRUTH */
        TRIM(s.first_name) AS full_name,

        s.email,
        s.dob,
        s.nationality,
        s.passport_number,
        s.phone_number,

        sig.signed_date,
        sig.signature_image
    FROM student_contracts c
    INNER JOIN student_applications s ON s.id = c.student_id
    INNER JOIN student_signatures sig ON sig.contract_id = c.id
    WHERE c.id = ?
    LIMIT 1
");

    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) {
        throw new RuntimeException('Contract not found.');
    }

    /* =====================================================
       2. SIGNATURE SOURCES
    ===================================================== */
    if (
        empty($data['signature_image']) ||
        !str_starts_with($data['signature_image'], 'data:image')
    ) {
        throw new RuntimeException('Invalid student signature.');
    }

    $studentSignature = $data['signature_image'];

    $consultantSigPath = __DIR__ . '/admin/signature-manager.png';
    if (!file_exists($consultantSigPath)) {
        throw new RuntimeException('Consultant signature missing.');
    }

    $consultantSignature =
        'data:image/png;base64,' . base64_encode(file_get_contents($consultantSigPath));

    /* =====================================================
       3. ARTICLE 7 – SELECTED PACKAGE
    ===================================================== */
    $package = getPackageDetails($data['selected_package_code']);
    if (!$package) {
        throw new RuntimeException('Selected package not defined.');
    }

    /* =====================================================
       4. BUILD HTML (ALL ARTICLES INCLUDED)
    ===================================================== */
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
/* =========================
   PAGE SETUP (WORD A4)
========================= */
@page {
    size: A4;
    margin: 2.54cm 2.54cm 2.54cm 2.54cm; /* Word default margins */
}

/* =========================
   BASE BODY (WORD DEFAULT)
========================= */
body {
    font-family: "Times New Roman", Times, serif;
    font-size: 12pt;
    line-height: 1.6;
    color: #000;
}

/* =========================
   MAIN TITLE (WORD STYLE)
========================= */
h1 {
    text-align: center;
    font-size: 20pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 0 0 18pt 0;
}

/* =========================
   ARTICLE HEADINGS (1., 2., 3.)
========================= */
h2 {
    font-size: 14pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 22pt 0 10pt 0;
}

/* =========================
   SUB-HEADINGS (1.1, 1.2)
========================= */
h3 {
    font-size: 12pt;
    font-weight: bold;
    margin: 16pt 0 6pt 0;
}

/* =========================
   PARAGRAPHS (JUSTIFIED)
========================= */
p {
    text-align: justify;
    margin: 0 0 10pt 0;
}

/* =========================
   LISTS (WORD INDENTATION)
========================= */
ul, ol {
    margin: 0 0 12pt 32pt;
    padding: 0;
}

li {
    margin-bottom: 6pt;
}

/* =========================
   TABLES (SIGNATURE SECTION)
========================= */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 18pt;
}

td {
    vertical-align: top;
    padding: 10pt;
    font-size: 11.5pt;
}

/* =========================
   SIGNATURE BOX (WORD STYLE)
========================= */
.signature-box {
    width: 7cm;
    height: 4cm;
    margin-top: 8pt;
    margin-bottom: 6pt;
    border-bottom: 1px solid #000;
}

.signature-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

/* =========================
   FOOTER
========================= */
.footer {
    margin-top: 24pt;
    text-align: center;
    font-size: 10pt;
    color: #444;
}
/* =========================
   LINKS (EMAIL & WEBSITE)
========================= */
a {
    color: #0000EE;          /* Word default blue */
    text-decoration: underline;
}

</style>

</head>
<body>

<!-- =========================
     MAIN TITLE
========================= -->
<h1>
    INTERNATIONAL STUDENT ADMISSION<br>
    &amp; VISA CONSULTANCY AGREEMENT
</h1>

<!-- INTRO TEXT -->
<p>
    This <strong>International Student Admission and Visa Consultancy Agreement</strong>
    (“<strong>Agreement</strong>”) is made and entered into on the date of signature
    by and between:
</p>

<!-- DATE -->
<p>
    <strong>Date:</strong> <?= esc($data['signed_date']) ?>
</p>

<!-- =========================
     ARTICLE 1 – PARTIES
========================= -->
<h2>1. PARTIES</h2>

<!-- =========================
     1.1 CONSULTANT
========================= -->
<h3>1.1 The Consultant</h3>

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

<!-- =========================
     1.2 STUDENT
========================= -->
<h3>1.2 The Student</h3>

<p>
    <strong>Full Legal Name:</strong> <?= esc($data['full_name']) ?><br>
    <strong>Date of Birth:</strong> <?= esc($data['dob']) ?><br>
    <strong>Nationality:</strong> <?= esc($data['nationality']) ?><br>
    <strong>Passport Number:</strong> <?= esc($data['passport_number']) ?><br>
    <strong>Phone:</strong> <?= esc($data['phone_number']) ?><br>
    <strong>Email:</strong> <?= esc($data['email']) ?>
</p>

<p><em>(Hereinafter referred to as <strong>“The Student”</strong>)</em></p>

<!-- =========================
     ARTICLE 2 – PURPOSE OF AGREEMENT
========================= -->
<h2>2. PURPOSE OF AGREEMENT</h2>

<p>
This Agreement governs the provision of
<strong>international study admission, visa consultancy, and related advisory services</strong>
for the Student intending to study or visit foreign countries including
<strong>Canada, the United States of America, Europe, and South Korea</strong>.
The Student acknowledges that
<strong>final decisions rest solely with educational institutions, embassies,
immigration authorities, and third-party entities</strong>.
</p>

<!-- =========================
     ARTICLE 3 – SCOPE OF SERVICES
========================= -->
<h2>3. SCOPE OF SERVICES</h2>

<p>
The Consultant shall provide
<strong>admission guidance, visa application assistance, document preparation support,
interview preparation (where applicable), loan guidance (for loan-based programs),
job search assistance, accommodation search support, and pre-departure orientation</strong>,
subject to the specific service package selected by the Student under this Agreement.
</p>

<!-- =========================
     ARTICLE 4 – CONSULTANT’S OBLIGATIONS
========================= -->
<h2>4. CONSULTANT’S OBLIGATIONS</h2>

<p>The Consultant agrees to:</p>

<ol>
    <li>Provide services professionally and in good faith</li>
    <li>Follow official immigration and institutional guidelines</li>
    <li>Maintain confidentiality of Student information</li>
    <li>Communicate progress transparently</li>
    <li>Avoid falsification or misrepresentation</li>
</ol>

<!-- =========================
     ARTICLE 5 – STUDENT’S OBLIGATIONS
========================= -->
<h2>5. STUDENT’S OBLIGATIONS</h2>

<p>The Student agrees to:</p>

<ol>
    <li>Provide accurate, complete, and truthful information</li>
    <li>Submit genuine and verifiable documents</li>
    <li>Pay all required fees on time</li>
    <li>Cooperate fully throughout the process</li>
    <li>Accept responsibility for any consequences arising from false or incomplete information</li>
</ol>

<!-- =========================
     ARTICLE 6 – NO GUARANTEE DISCLAIMER
========================= -->
<h2>6. NO GUARANTEE DISCLAIMER</h2>

<p>The Student understands and agrees that:</p>

<ul>
    <li>Admission, visa approval, loan approval, and processing timelines are <strong>not guaranteed</strong></li>
    <li>Decisions are made solely by educational institutions, embassies, immigration authorities, and other third-party entities</li>
    <li>Refusal or delay does not constitute a breach of this Agreement by the Consultant</li>
</ul>
<!-- =========================
     ARTICLE 7 – FEES & PAYMENT TERMS
========================= -->
<h2>7. FEES &amp; PAYMENT TERMS (SELECTED PACKAGE)</h2>

<p><strong><?= esc($package['title']) ?></strong></p>

<ul>
<?php foreach ($package['lines'] as $line): ?>
    <li><?= esc($line) ?></li>
<?php endforeach; ?>
</ul>

<?php if (!empty($package['total'])): ?>
<p><strong>Total Package: <?= esc($package['total']) ?></strong></p>
<?php endif; ?>
<h3>Additional Pricing Provisions (Without Loan &amp; Special Services)</h3>

<p><strong>1. Spring, Winter, Summer, or Fall Short Courses (Worldwide)</strong></p>
<ul>
    <li>Application and Registration Fees: <strong>EUR 250</strong>, refundable if approval is not secured within four (4) months</li>
    <li>Service Fees: <strong>EUR 2,000</strong>, payable only once the visa is approved</li>
</ul>

<p><strong>2. Canadian Immigration Lawyer – Visa Application (Canada Only)</strong></p>
<p>
Where the Student requests that the visa application be handled by a licensed
Canadian Immigration Lawyer, an additional charge of
<strong>CAD 300 per applicant</strong> shall apply.
</p>

<p><strong>3. Canadian Immigration Lawyer – Legal Advice or Consultation (Canada Only)</strong></p>
<p>
Where the Student requires legal advice or consultation from a licensed
Canadian Immigration Lawyer, the Student shall pay a consultation fee of
<strong>CAD 300</strong>.
</p>

<p>
<strong>Important:</strong>
All government fees, embassy charges, biometric fees, SEVIS fees, tuition deposits,
lawyer fees, border pass fees, and third-party charges are paid separately by the Student
and are <strong>non-refundable once submitted</strong>.
</p>
<!-- =========================
     ARTICLE 8 – PAYMENT OF SERVICE FEES
========================= -->
<h2>8. PAYMENT OF SERVICE FEES</h2>

<p>
Where applicable, final service fees become immediately payable upon visa approval.
Once the visa is approved, the Student shall pay all outstanding service fees
<strong>no later than five (5) days</strong> from the date of approval.
Failure to make payment constitutes a <strong>material breach</strong> of this Agreement.
</p>

<!-- =========================
     ARTICLE 9 – REFUND POLICY
========================= -->
<h2>9. REFUND POLICY</h2>

<p>
Only registration fees are refundable strictly under the conditions expressly stated
in this Agreement. All other fees, including service fees, loan processing fees,
legal fees, and third-party charges, are <strong>non-refundable</strong>.
</p>

<!-- =========================
     ARTICLE 10 – TERMINATION
========================= -->
<h2>10. TERMINATION</h2>

<p>
The Consultant may terminate this Agreement immediately in the event of non-payment,
submission of fraudulent documents, or breach of any obligation by the Student.
Termination does not release the Student from outstanding payment obligations.
</p>

<!-- =========================
     ARTICLE 11 – LIMITATION OF LIABILITY
========================= -->
<h2>11. LIMITATION OF LIABILITY</h2>

<p>
The Consultant shall not be liable for decisions, delays, refusals, or outcomes issued
by embassies, educational institutions, or government authorities beyond its control.
</p>

<!-- =========================
     ARTICLE 12 – CONFIDENTIALITY
========================= -->
<h2>12. CONFIDENTIALITY</h2>

<p>
All information exchanged between the parties shall remain confidential except where
disclosure is required by law or competent authorities.
</p>

<!-- =========================
     ARTICLE 13 – GOVERNING LAW &amp; JURISDICTION
========================= -->
<h2>13. GOVERNING LAW &amp; JURISDICTION</h2>

<p>
This Agreement shall be governed by the laws of the <strong>Republic of Rwanda</strong>,
with exclusive jurisdiction vested in the competent courts of Rwanda.
</p>

<!-- =========================
     ARTICLE 14 – ENTIRE AGREEMENT
========================= -->
<h2>14. ENTIRE AGREEMENT</h2>

<p>
This Agreement constitutes the entire understanding between the parties and supersedes
all prior discussions. Any amendment must be in writing and signed by both parties.
</p>
<h2>15. SIGNATURES</h2>

<table style="width:100%; border-collapse:collapse;">

<!-- ================= ROW 1 ================= -->
<tr>

<!-- COMPANY REPRESENTATIVE -->
<td style="padding:20px; vertical-align:top; width:50%;">

<strong>For the Company Representative</strong><br><br>

Name: Jean Pierre TWAJAMAHORO<br>
Title: Managing Director<br><br>

Signature:<br>
<div style="border-bottom:1px solid #000; height:60px; width:70%; position:relative;">
  <img src="<?= $consultantSignature ?>" alt="Company Signature" style="max-height:55px; position:absolute; bottom:2px; left:0;">
</div>

<br>
Date: <?= esc($data['signed_date']) ?>

</td>


<!-- NOTARY -->
<td style="padding:20px; vertical-align:top; width:50%;">

<strong>For the Notary</strong><br><br>

Name: ___________________________<br><br>

Signature:<br>
<div style="border-bottom:1px solid #000; height:40px; width:70%;"></div>

<br>
Date: ___________________________

</td>

</tr>


<!-- ================= ROW 2 ================= -->
<tr>

<!-- STUDENT (CENTERED AREA) -->
<td colspan="2" style="padding:30px; text-align:left;">

<strong>For the Student</strong><br><br>

Name: <?= esc($data['full_name']) ?><br><br>

Signature:<br>

<div class="signature-box" style="
border:1px dashed #9ca3af;
height:120px;
width:320px;
padding:10px;
margin-top:10px;
margin-bottom:15px;
">

<?php if(!empty($studentSignature)): ?>
<img src="<?= $studentSignature ?>" alt="Student Signature" style="max-height:100px;">
<?php endif; ?>

</div>

Date: <?= esc($data['signed_date']) ?>

</td>

</tr>

</table>
<div class="footer">
Contract Reference: <?= esc($data['contract_token']) ?>
</div>

</body>
</html>
<?php
    $html = ob_get_clean();

    /* =====================================================
       5. RENDER PDF
    ===================================================== */
    $dompdf = new Dompdf(new Options(['isRemoteEnabled' => true]));
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = __DIR__ . '/uploads/contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . "/contract_{$contractId}.pdf";
    file_put_contents($path, $dompdf->output());

    return $path;
}

<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/contract_signature_image.php';
require_once __DIR__ . '/helpers/fm_mobility_contract_schema.php';

function fmEsc(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fmFormatDate(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '___________________________';
    }
    $ts = strtotime($date);
    return $ts ? date('F j, Y', $ts) : fmEsc($date);
}

function fmFormatDateShort(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '___________________________';
    }
    $ts = strtotime($date);
    return $ts ? date('m/d/Y', $ts) : fmEsc($date);
}

function fmPdfEmbedImage(string $absolutePath): string
{
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Image not found: ' . basename($absolutePath));
    }
    $mime = mime_content_type($absolutePath) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($absolutePath));
}

function fmPdfFieldRow(string $label, string $value, string $fallback = ''): string
{
    $display = trim($value) !== '' ? fmEsc($value) : fmEsc($fallback);
    return '<div class="field-row">
        <span class="field-label">' . fmEsc($label) . '</span>
        <span class="field-value">' . $display . '</span>
    </div>';
}

function generateFmContractPDF(int $contractId): string
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT
            c.contract_token,
            c.agreement_date,
            c.external_client_name,
            c.external_client_email,
            c.external_client_phone,
            c.external_client_dob,
            c.external_client_nationality,
            c.external_client_passport,
            c.external_client_address,
            c.application_id,
            c.contract_variant,
            sig.client_name,
            sig.client_email,
            sig.client_passport,
            sig.signed_date,
            sig.signature_image,
            a.reference_id
        FROM fm_mobility_contracts c
        INNER JOIN fm_mobility_signatures sig ON sig.contract_id = c.id
        LEFT JOIN francophonie_mobility_applications a ON a.id = c.application_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $contractId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to load contract data: ' . $stmt->error);
    }
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) {
        throw new RuntimeException('Contract not found.');
    }

    if (empty($data['signature_image']) || !str_starts_with($data['signature_image'], 'data:image')) {
        throw new RuntimeException('Invalid client signature.');
    }

    $clientName        = $data['client_name'] ?: ($data['external_client_name'] ?? '');
    $clientEmail       = $data['client_email'] ?: ($data['external_client_email'] ?? '');
    $clientPassport    = $data['client_passport'] ?: ($data['external_client_passport'] ?? '');
    $clientNationality = $data['external_client_nationality'] ?? '';
    $clientDob         = $data['external_client_dob'] ?? '';
    $clientPhone       = $data['external_client_phone'] ?? '';
    $clientAddress     = $data['external_client_address'] ?? '';
    $agreementDate     = $data['agreement_date'] ?: ($data['signed_date'] ?? '');
    $signedDate        = $data['signed_date'] ?? '';
    $referenceId       = $data['reference_id'] ?? '';

    $clientSignature = $data['signature_image'];
    $clientSignatureFile = contract_signature_save_standard_png($contractId, $clientSignature);
    if ($clientSignatureFile !== null && is_file($clientSignatureFile)) {
        $clientSigSrc = str_replace('\\', '/', $clientSignatureFile);
    } else {
        $normalizedPng = contract_signature_to_display_png($clientSignature);
        $clientSigSrc = $normalizedPng !== null
            ? 'data:image/png;base64,' . base64_encode($normalizedPng)
            : $clientSignature;
    }

    $managerSigPath = __DIR__ . '/admin/signature-manager.png';
    $companyStampPath = __DIR__ . '/admin/employer-signature.png';
    $managerSignature = fmPdfEmbedImage($managerSigPath);
    $companyStamp = fmPdfEmbedImage($companyStampPath);

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
@page {
    size: A4;
    margin: 2.54cm 2.54cm 2.54cm 2.54cm;
}

body {
    font-family: "Times New Roman", Times, serif;
    font-size: 12pt;
    line-height: 1.6;
    color: #000;
    margin: 0;
    padding: 0;
}

.doc-title {
    text-align: center;
    font-size: 14pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 0 0 4pt;
}

.doc-subtitle {
    text-align: center;
    font-size: 13pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 0 0 18pt;
    color: #1a1a1a;
}

p { margin: 0 0 10pt; text-align: justify; }

.section-title {
    font-size: 12pt;
    font-weight: bold;
    margin: 18pt 0 8pt;
}

.client-section-title {
    font-weight: bold;
    margin: 14pt 0 10pt;
    font-size: 12pt;
}

.field-row {
    margin: 0 0 8pt;
    line-height: 1.8;
}

.field-label {
    font-weight: normal;
}

.field-value {
    display: inline-block;
    min-width: 68%;
    border-bottom: 1px solid #000;
    padding: 0 2pt 2pt 6pt;
}

.party-block { margin: 12pt 0; }

ul.contract-list {
    margin: 6pt 0 12pt 0;
    padding-left: 22pt;
}

ul.contract-list li {
    margin-bottom: 5pt;
    text-align: justify;
}

.fee-total {
    text-align: center;
    font-weight: bold;
    font-size: 12pt;
    margin: 12pt 0;
}

.fee-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10pt 0 14pt;
    font-size: 11pt;
}

.fee-table th,
.fee-table td {
    border: 1px solid #000;
    padding: 8pt 10pt;
    vertical-align: top;
    text-align: left;
}

.fee-table th {
    font-weight: bold;
    background: #f5f5f5;
}

.fee-amount {
    font-weight: bold;
    white-space: nowrap;
}

.fee-table ul {
    margin: 6pt 0 0 0;
    padding-left: 16pt;
}

.sig-section {
    margin-top: 28pt;
    page-break-inside: avoid;
}

.sig-heading {
    font-weight: bold;
    text-transform: uppercase;
    margin: 0 0 12pt;
    font-size: 12pt;
}

.sig-name {
    font-weight: bold;
    margin: 0 0 2pt;
}

.sig-role {
    margin: 0 0 14pt;
}

.sig-field {
    margin: 0 0 12pt;
}

.sig-field-label {
    display: block;
    margin-bottom: 4pt;
}

.sig-line-box {
    border-bottom: 1px solid #000;
    min-height: 42pt;
    width: 72%;
    padding-top: 2pt;
}

.sig-line-box img {
    max-height: 40pt;
    max-width: 220pt;
}

.stamp-box {
    min-height: 70pt;
    width: 72%;
    padding-top: 4pt;
}

.stamp-box img {
    max-height: 68pt;
    max-width: 180pt;
}

.client-sig-block {
    margin-top: 36pt;
    page-break-inside: avoid;
}

.footer-ref {
    margin-top: 24pt;
    text-align: center;
    font-size: 9pt;
    color: #444;
    border-top: 1px solid #ccc;
    padding-top: 8pt;
}
</style>
</head>
<body>

<div class="doc-title">PARROT CANADA VISA CONSULTANT CO. LTD.</div>
<div class="doc-subtitle">FRANCOPHONE MOBILITY CLIENT SERVICE AGREEMENT</div>
<?php if (fm_contract_normalize_variant($data['contract_variant'] ?? 'standard') === 'special'): ?>
<p style="text-align:center;font-weight:bold;margin-top:-10pt;">Special fee schedule</p>
<?php endif; ?>

<p><strong>Agreement Date:</strong> <?= fmFormatDate($agreementDate) ?></p>
<?php if ($referenceId !== ''): ?>
<p><strong>Agreement reference:</strong> <?= fmEsc($referenceId) ?></p>
<?php endif; ?>

<p>This Service Agreement ("Agreement") is entered into on the date indicated above between:</p>

<div class="party-block">
<p><strong>PARROT CANADA VISA CONSULTANT CO. LTD.</strong><br>
Represented by: Dr. Jean Pierre Twajamahoro, Managing Director<br>
(Hereinafter referred to as "the Company")</p>
</div>

<p><strong>AND</strong></p>

<div class="client-section-title">CLIENT INFORMATION</div>

<?= fmPdfFieldRow('Full Name:', $clientName, '_______________________________________') ?>
<?= fmPdfFieldRow('Passport Number:', $clientPassport, '_______________________________') ?>
<?= fmPdfFieldRow('Nationality:', $clientNationality, '______________________________________') ?>
<?= fmPdfFieldRow('Date of Birth:', $clientDob !== '' ? fmFormatDateShort($clientDob) : '', '__________________________________') ?>
<?= fmPdfFieldRow('Address:', $clientAddress, '_______________________________________') ?>
<?= fmPdfFieldRow('Telephone:', $clientPhone, '_____________________________________') ?>
<?= fmPdfFieldRow('Email:', $clientEmail, '_________________________________________') ?>
<?php if ($referenceId !== ''): ?>
<?= fmPdfFieldRow('Application Ref:', $referenceId) ?>
<?php endif; ?>

<p>(Hereinafter referred to as "the Client")</p>

<p>The Company and the Client shall collectively be referred to as "the Parties."</p>

<div class="section-title">1. PURPOSE</div>
<p>This agreement records the scope and proposed fee schedule for services relating to the Client's exploration of employment in Canada through the Francophone Mobility program. It also explains the job-matching process that begins after the Client successfully passes the pre-assessment. Immigration approval and entry into Canada remain decisions of the competent Canadian authorities.</p>

<div class="section-title">2. SCOPE OF SERVICES</div>
<p>Subject to legal authorization and the Client's separate written instructions, services may include eligibility screening, CV review, profile organization, language-test guidance, interview preparation, administrative document organization, job-matching coordination, and general follow-up. Canadian immigration advice or representation, where required, will be provided only by an authorized Canadian representative under an appropriate retainer.</p>

<div class="section-title">2.1 JOB OFFER SEARCH AFTER SUCCESSFUL PRE-ASSESSMENT</div>
<p>Once the Client successfully passes the pre-assessment, Parrot Canada Visa Consultant Co. Ltd. confirms that its Canadian partner will accept the Client into the active job-matching process and will actively work to secure a suitable Canadian job offer for the Client.</p>
<p>The Canadian partner will present the Client's profile to suitable employers, coordinate employer communication, and continue reasonable job-search and matching efforts while the Client remains eligible, provides all required documents, responds promptly, and satisfies applicable language and employer requirements.</p>
<p>Parrot Canada Visa Consultant Co. Ltd. is confident in its Canadian partner's ability to obtain a suitable job offer for a Client who passes the pre-assessment and cooperates fully. However, the final job offer must be issued and signed by an independent Canadian employer. Therefore, neither Parrot Canada Visa Consultant Co. Ltd. nor its Canadian partner can legally guarantee the employer's final hiring decision, the timing of an offer, or immigration approval.</p>

<div class="section-title">3. RESPONSIBILITIES</div>
<p><strong>Client:</strong> Provide genuine documents and accurate information, attend agreed appointments, meet applicable language and program requirements, cooperate with interviews and employer requests, and promptly notify the Company of changes.</p>
<p><strong>Company:</strong> Act professionally, protect personal information, communicate material updates, conduct or coordinate the pre-assessment, begin job-matching coordination after a successful pre-assessment, and identify which services can lawfully be provided and charged to the Client.</p>

<div class="section-title">4. PROPOSED FEES AND PAYMENT MILESTONES</div>
<p>The parties propose the following total and timing, subject to confirmation that each charge is lawful, itemized, and relates solely to client-payable services.</p>
<?php $feeSchedule = fm_contract_fee_schedule((string) ($data['contract_variant'] ?? 'standard')); ?>
<table class="fee-table">
<tr>
<th style="width:24%;">Payment</th>
<th>Trigger</th>
<th style="width:18%;">Amount</th>
</tr>
<?php foreach ($feeSchedule['rows'] as $feeRow): ?>
<tr>
<td><?= fmEsc($feeRow[0]) ?></td>
<td><?= fmEsc($feeRow[1]) ?></td>
<td class="fee-amount"><?= fmEsc($feeRow[2]) ?></td>
</tr>
<?php endforeach; ?>
<tr>
<td><strong>TOTAL</strong></td>
<td></td>
<td class="fee-amount"><?= fmEsc($feeSchedule['total']) ?></td>
</tr>
</table>
<p><strong>Compliance condition:</strong> No amount is due for recruiting a foreign worker, securing a job offer, employer-side LMIA or employer-compliance costs, or other expenses that the law requires an employer or recruiter to bear. Any unlawful or prohibited charge is void and must not be collected. The parties must revise this schedule before signing if the stated amounts cannot lawfully be charged.</p>

<div class="section-title">5. THIRD PARTY EXPENSES</div>
<p>Government application fees, biometrics, medical examinations, language tests, translations, and travel expenses are separate unless specifically included in a written schedule. Each cost must be allocated in accordance with applicable law. No employer-payable cost may be transferred to the Client.</p>

<div class="section-title">6. REFUNDS AND CANCELLATION</div>
<p>The Company will provide the Client with a written description of each chargeable service, the work completed, and the applicable refund conditions before accepting payment.</p>
<p>If the work-permit application is refused directly and solely because of a documented error, omission, negligence, or failure by the Company or its Canadian partner, all service fees paid by the Client will be refunded, excluding:</p>
<ul class="contract-list">
<li>Government application and biometric fees;</li>
<li>Medical-examination fees;</li>
<li>Language-test fees;</li>
<li>Administrative fees for work already completed;</li>
<li>Translation, notarization, courier, and document-processing expenses; and</li>
<li>Other non-refundable third-party costs already incurred for the Client.</li>
</ul>
<p>No refund will be provided when the refusal results wholly or partly from the Client’s actions, omissions, personal circumstances, or ineligibility, including:</p>
<ul class="contract-list">
<li>Hiding or failing to disclose a previous visa or immigration refusal from Canada, the United States, or any other country;</li>
<li>Providing false, misleading, incomplete, inconsistent, or fraudulent information or documents;</li>
<li>Failing to disclose immigration history, criminal history, employment history, family information, or other material facts;</li>
<li>A medical condition, medical inadmissibility finding, or failure to complete a required medical examination;</li>
<li>Criminal inadmissibility, security concerns, or previous immigration violations;</li>
<li>Failure to meet the required French-language, employment, education, work-experience, or program requirements;</li>
<li>Failure to provide requested documents, attend appointments, or respond within the required time;</li>
<li>Withdrawal, cancellation, or abandonment of the process by the Client; or</li>
<li>Any decision or circumstance outside the reasonable control of the Company or its Canadian partner.</li>
</ul>
<p>The second installment will not become payable if the work permit is refused before the payment milestone is reached.</p>
<p>Either party may terminate this Agreement by written notice, subject to applicable law, services already completed, and non-refundable costs already incurred. Any refund due under this section shall be calculated and explained to the Client in writing.</p>

<div class="section-title">7. CONFIDENTIALITY AND CONSENT</div>
<p>Personal information may be used for agreed services and disclosed to appropriate institutions, prospective employers, Canadian partners, or authorized representatives only with valid consent or where required by law.</p>

<div class="section-title">8. JOB MATCHING COMMITMENT AND NO IMMIGRATION GUARANTEE</div>
<p>After a successful pre-assessment, the Canadian partner commits to actively undertake the job-matching and job-offer search described in section 2.1. The final hiring decision and issuance of a job offer belong exclusively to the employer. Immigration decisions belong to Immigration, Refugees and Citizenship Canada. Processing times, work-permit approval, and entry into Canada are not guaranteed.</p>
<p>Applicable federal and provincial recruitment, consumer-protection, employment, and immigration laws prevail over any conflicting contract term.</p>

<div class="section-title">9. CLIENT ACKNOWLEDGMENT</div>
<p>By signing, the Client confirms that the Client understands: (a) successful pre-assessment leads to active job matching by the Canadian partner; (b) Parrot Canada Visa Consultant Co. Ltd. is confident that the Canadian partner will work to obtain a suitable job offer; (c) the employer alone makes the final hiring decision; and (d) no promise of work-permit approval or entry into Canada is made.</p>

<!-- Company signature block (Word format) -->
<div class="sig-section">
<div class="sig-heading">SIGNED FOR PARROT CANADA VISA CONSULTANT CO. LTD.</div>
<p class="sig-name">Dr. Jean Pierre Twajamahoro</p>
<p class="sig-role">Managing Director</p>

<div class="sig-field">
<span class="sig-field-label">Signature:</span>
<div class="sig-line-box">
<img src="<?= $managerSignature ?>" alt="Managing Director Signature">
</div>
</div>

<div class="sig-field">
<span class="sig-field-label">Date:</span>
<div class="sig-line-box" style="min-height:24pt;line-height:24pt;padding-top:6pt;">
<?= fmFormatDate($agreementDate) ?>
</div>
</div>

<div class="sig-field">
<span class="sig-field-label">Company Stamp:</span>
<div class="stamp-box">
<img src="<?= $companyStamp ?>" alt="Company Stamp">
</div>
</div>
</div>

<!-- Client signature block (Word format) -->
<div class="client-sig-block">
<div class="sig-heading">SIGNED BY THE CLIENT</div>

<div class="sig-field">
<span class="sig-field-label">Full Name:</span>
<div class="sig-line-box" style="min-height:24pt;line-height:24pt;padding-top:6pt;">
<?= fmEsc($clientName) ?>
</div>
</div>

<div class="sig-field">
<span class="sig-field-label">Signature:</span>
<div class="sig-line-box">
<img src="<?= is_string($clientSigSrc) && str_starts_with($clientSigSrc, 'data:') ? $clientSigSrc : str_replace('\\', '/', (string) $clientSigSrc) ?>" alt="Client Signature">
</div>
</div>

<div class="sig-field">
<span class="sig-field-label">Date:</span>
<div class="sig-line-box" style="min-height:24pt;line-height:24pt;padding-top:6pt;">
<?= fmFormatDate($signedDate) ?>
</div>
</div>

<div class="sig-field">
<span class="sig-field-label">Passport Number:</span>
<div class="sig-line-box" style="min-height:24pt;line-height:24pt;padding-top:6pt;">
<?= fmEsc($clientPassport !== '' ? $clientPassport : '_______________________________') ?>
</div>
</div>
</div>

<div class="footer-ref">
Contract Reference: <?= fmEsc($data['contract_token']) ?>
<?php if ($referenceId !== ''): ?> | Application: <?= fmEsc($referenceId) ?><?php endif; ?>
</div>

</body>
</html>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Times New Roman');
    $options->set('chroot', __DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = __DIR__ . '/uploads/fm_contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filePath = $dir . '/fm_contract_' . $contractId . '.pdf';
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

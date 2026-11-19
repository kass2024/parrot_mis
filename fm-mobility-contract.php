<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/fm_mobility_contract_schema.php';

fm_ensure_schema($conn);
fm_contract_ensure_schema($conn);

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

if (!isset($_GET['token']) || trim($_GET['token']) === '') {
    http_response_code(400);
    exit('Invalid contract link.');
}

$token = trim($_GET['token']);

$stmt = $conn->prepare('SELECT * FROM fm_mobility_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit('This contract link is invalid or expired.');
}

$isSigned = ($contract['status'] === 'signed');
$referenceId = '';

$clientSignatureData = null;
if ($isSigned && !empty($contract['id'])) {
    $contractId = (int) $contract['id'];
    $stmt = $conn->prepare('SELECT signature_image FROM fm_mobility_signatures WHERE contract_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $sigRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($sigRow['signature_image'])) {
        $clientSignatureData = $sigRow['signature_image'];
    }
}

$applicant = null;
if (!empty($contract['application_id'])) {
    $appId = (int) $contract['application_id'];
    $stmt = $conn->prepare('SELECT * FROM francophonie_mobility_applications WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $appId);
    $stmt->execute();
    $applicant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($applicant) {
        $referenceId = $applicant['reference_id'] ?? '';
    }
}

$displayClient = [
    'name'        => $contract['external_client_name'] ?? '',
    'email'       => $contract['external_client_email'] ?? '',
    'phone'       => $contract['external_client_phone'] ?? '',
    'dob'         => $contract['external_client_dob'] ?? '',
    'nationality' => $contract['external_client_nationality'] ?? '',
    'passport'    => $contract['external_client_passport'] ?? '',
    'address'     => $contract['external_client_address'] ?? '',
];

if ($applicant && !$isSigned) {
    if ($displayClient['name'] === '') {
        $displayClient['name'] = trim(($applicant['first_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
    }
    if ($displayClient['email'] === '') {
        $displayClient['email'] = $applicant['email'] ?? '';
    }
    if ($displayClient['phone'] === '') {
        $area = ltrim((string) ($applicant['phone_area_code'] ?? ''), '+');
        $num  = trim((string) ($applicant['phone_number'] ?? ''));
        $displayClient['phone'] = ($area !== '' || $num !== '') ? '+' . $area . ' ' . $num : '';
    }
    if ($displayClient['nationality'] === '' && ($applicant['nationality'] ?? '') !== 'N/A') {
        $displayClient['nationality'] = $applicant['nationality'] ?? '';
    }
    if ($displayClient['dob'] === '' && !empty($applicant['date_of_birth'])) {
        $displayClient['dob'] = $applicant['date_of_birth'];
    }
    if ($displayClient['passport'] === '' && !empty($applicant['passport_number'])) {
        $displayClient['passport'] = $applicant['passport_number'];
    }
    if ($displayClient['address'] === '' && !empty($applicant['address'])) {
        $displayClient['address'] = $applicant['address'];
    } elseif ($displayClient['address'] === '') {
        $res = $applicant['country_of_residence'] ?? '';
        if ($res !== '' && $res !== 'N/A') {
            $displayClient['address'] = 'Country of Residence: ' . $res;
        }
    }
}

$agreementDate = $contract['agreement_date'] ?? date('Y-m-d');
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Francophone Mobility Client Service Agreement | Parrot Canada Visa Consultant</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --primary: #2d5a27;
  --primary-light: #427431;
  --accent: #c0392b;
  --ink: #1a2e1a;
  --muted: #4b5563;
  --border: #d4e4d4;
  --soft: #f0f7f0;
  --paper: #ffffff;
  --success: #15803d;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  padding: 16px 12px 40px;
  background: linear-gradient(160deg, #e8f5e9 0%, #f0f4f0 40%, #e2e8f0 100%);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: var(--ink);
  min-height: 100vh;
}

.contract-signed .sign-section,
.contract-signed #clearSignature,
.contract-signed #signContract { display: none !important; }

.signed-banner {
  max-width: 860px;
  margin: 0 auto 20px;
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  border: 1px solid #86efac;
  border-radius: 14px;
  padding: 20px 24px;
  text-align: center;
}

.signed-banner h2 { margin: 0 0 8px; color: var(--success); font-size: 20px; }
.signed-banner p { margin: 0 0 16px; color: #166534; font-size: 14px; }
.signed-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-dl, .btn-view {
  padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px;
  text-decoration: none; display: inline-block;
}
.btn-dl { background: var(--primary); color: #fff; }
.btn-view { background: #fff; color: var(--primary); border: 2px solid var(--primary); }

.contract-wrap {
  max-width: 860px;
  margin: 0 auto;
  background: var(--paper);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(45, 90, 39, 0.15);
  overflow: hidden;
  border-top: 5px solid var(--primary);
}

.contract-header {
  background: linear-gradient(135deg, var(--primary), var(--primary-light));
  color: #fff;
  padding: 28px 24px;
  text-align: center;
}

.contract-header .badge {
  display: inline-block;
  background: rgba(255,255,255,0.2);
  padding: 4px 14px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
  margin-bottom: 12px;
}

.contract-header h1 {
  margin: 0 0 6px;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.3px;
}

.contract-header h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 500;
  opacity: 0.95;
}

.contract-body { padding: 28px 24px 32px; }

.notice {
  background: #fffbeb;
  border-left: 4px solid #f59e0b;
  padding: 14px 16px;
  border-radius: 0 8px 8px 0;
  font-size: 13px;
  color: #92400e;
  margin-bottom: 24px;
  line-height: 1.5;
}

.section-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--primary);
  margin: 28px 0 14px;
  padding-bottom: 6px;
  border-bottom: 2px solid var(--border);
}

.section-title:first-of-type { margin-top: 0; }

p { font-size: 14px; line-height: 1.65; margin: 0 0 12px; text-align: justify; }

ul.contract-list {
  margin: 8px 0 16px;
  padding-left: 22px;
  font-size: 14px;
  line-height: 1.65;
}
ul.contract-list li { margin-bottom: 6px; }

.client-form {
  background: var(--soft);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px;
  margin: 16px 0 24px;
}

.client-form h3 {
  margin: 0 0 16px;
  font-size: 15px;
  color: var(--primary);
}

.form-row { margin-bottom: 14px; }
.form-row label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.form-row input, .form-row textarea {
  width: 100%;
  padding: 11px 14px;
  border: 2px solid var(--border);
  border-radius: 8px;
  font-size: 15px;
  transition: border-color 0.2s;
  font-family: inherit;
}

.form-row input:focus, .form-row textarea:focus {
  outline: none;
  border-color: var(--primary-light);
}

.form-row input[readonly] { background: #f3f4f6; }

.autofill-hint {
  font-size: 12px;
  color: var(--muted);
  margin-top: 6px;
}

.autofill-match {
  background: #e8f5e9;
  border: 1px solid #a5d6a7;
  border-radius: 8px;
  padding: 12px 14px;
  margin-top: 12px;
  font-size: 13px;
  display: none;
}

.autofill-match.visible { display: block; }
.autofill-match button {
  margin-top: 8px;
  background: var(--primary);
  color: #fff;
  border: none;
  padding: 8px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}

.fee-grid {
  display: grid;
  gap: 14px;
  margin: 16px 0;
}

@media (min-width: 640px) {
  .fee-grid { grid-template-columns: repeat(3, 1fr); }
}

.fee-card {
  border: 2px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  background: #fff;
}

.fee-card .amount {
  font-size: 22px;
  font-weight: 700;
  color: var(--primary);
  margin: 8px 0;
}

.fee-card .label { font-size: 13px; font-weight: 700; color: var(--ink); }
.fee-card ul { margin: 10px 0 0; padding-left: 16px; font-size: 12px; color: var(--muted); }
.fee-card ul li { margin-bottom: 4px; }

.fee-table { width: 100%; border-collapse: collapse; margin: 16px 0 8px; font-size: 14px; }
.fee-table th, .fee-table td { border: 1px solid var(--border); padding: 10px 12px; text-align: left; vertical-align: top; }
.fee-table th { background: var(--soft); color: var(--primary); font-size: 12px; letter-spacing: .03em; text-transform: uppercase; }
.fee-table td.amt { font-weight: 700; color: var(--primary); white-space: nowrap; }
.compliance-note { background: #fff8eb; border: 1px solid #f5d48a; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #7c4a03; }

.total-fee {
  text-align: center;
  background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
  border-radius: 12px;
  padding: 18px;
  margin: 20px 0;
  font-size: 18px;
  font-weight: 700;
  color: var(--primary);
}

.sign-section {
  max-width: 520px;
  margin: 32px auto 0;
  padding: 24px;
  background: #fafbfc;
  border-radius: 14px;
  border: 2px solid var(--border);
}

.sign-section h3 { text-align: center; margin: 0 0 20px; font-size: 17px; color: var(--primary); }

.sig-canvas-wrap {
  border: 2px dashed #9ca3af;
  border-radius: 10px;
  height: 150px;
  background: #fff;
  position: relative;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sig-canvas-wrap canvas { width: 100%; height: 148px; touch-action: none; }
.sig-hint { position: absolute; top: 8px; right: 10px; font-size: 11px; color: #9ca3af; }

.sign-btns { display: flex; gap: 10px; flex-wrap: wrap; }
.sign-btns button {
  flex: 1; min-width: 120px; padding: 13px; border: none; border-radius: 8px;
  font-size: 15px; font-weight: 600; cursor: pointer;
}
#clearSignature { background: #f3f4f6; color: #374151; }
#signContract { background: var(--primary); color: #fff; flex: 2; }

.footer-ref {
  text-align: center;
  margin-top: 28px;
  font-size: 11px;
  color: #9ca3af;
}

.applicant-ref {
  display: inline-block;
  background: #e8f5e9;
  color: var(--primary);
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  margin-left: 8px;
}
</style>
</head>
<body<?= $isSigned ? ' class="contract-signed"' : '' ?>>

<?php if ($isSigned): ?>
<div class="signed-banner">
  <h2>✓ Contract Signed Successfully</h2>
  <p>Your Francophonie Mobility Service Agreement has been recorded.<?php if ($referenceId): ?> Application Ref: <strong><?= htmlspecialchars($referenceId) ?></strong><?php endif; ?></p>
  <div class="signed-actions">
    <a class="btn-dl" href="download-fm-contract.php?token=<?= urlencode($token) ?>">Download Signed PDF</a>
    <a class="btn-view" href="download-fm-contract.php?token=<?= urlencode($token) ?>&amp;inline=1" target="_blank" rel="noopener">View PDF</a>
  </div>
</div>
<?php endif; ?>

<div class="contract-wrap">
  <div class="contract-header">
    <div class="badge"><?= (($contract['contract_variant'] ?? 'standard') === 'special') ? 'SPECIAL FEE SCHEDULE · CAD 10,000' : '🍁 FRANCOPHONIE MOBILITY PROGRAM' ?></div>
    <h1>PARROT CANADA VISA CONSULTANT CO. LTD.</h1>
    <h2>Francophone Mobility Client Service Agreement</h2>
  </div>

  <div class="contract-body">

    <?php if (!$isSigned): ?>
    <div class="notice">
      Please read this Agreement carefully. By signing electronically below, you acknowledge that you fully understand and voluntarily accept all terms and conditions contained herein.
    </div>
    <?php endif; ?>

    <p><strong>Agreement Date:</strong>
      <input type="date" id="agreement_date" value="<?= htmlspecialchars($agreementDate ?: $today) ?>" <?= $isSigned ? 'readonly' : '' ?> style="border:none;border-bottom:1px solid #ccc;padding:2px 6px;font-size:14px;">
    </p>
    <p><strong>Agreement reference:</strong> <?= htmlspecialchars($referenceId !== '' ? $referenceId : $contract['contract_token']) ?></p>

    <p>This Service Agreement ("Agreement") is entered into on the date indicated above between:</p>

    <p>
      <strong>PARROT CANADA VISA CONSULTANT CO. LTD.</strong><br>
      Represented by: Dr. Jean Pierre Twajamahoro, Managing Director<br>
      (Hereinafter referred to as "the Company")
    </p>

    <p><strong>AND</strong></p>

    <div class="client-form">
      <h3>CLIENT INFORMATION <?php if ($referenceId): ?><span class="applicant-ref"><?= htmlspecialchars($referenceId) ?></span><?php endif; ?></h3>

      <div class="form-row">
        <label for="client_email">Email</label>
        <input type="email" id="client_email" required autocomplete="email"
               value="<?= htmlspecialchars($displayClient['email']) ?>"
               placeholder="Enter your email address" <?= $isSigned ? 'readonly' : '' ?>>
        <p class="autofill-hint">Enter your email to auto-fill from your mobility application.</p>
        <div class="autofill-match" id="autofillMatch"></div>
      </div>

      <div class="form-row">
        <label for="client_name">Full Name</label>
        <input type="text" id="client_name" required autocomplete="name"
               value="<?= htmlspecialchars($displayClient['name']) ?>"
               placeholder="Full legal name" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="form-row">
        <label for="client_passport">Passport Number</label>
        <input type="text" id="client_passport" autocomplete="off"
               value="<?= htmlspecialchars($displayClient['passport']) ?>"
               placeholder="Passport number" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="form-row">
        <label for="client_nationality">Nationality</label>
        <input type="text" id="client_nationality" required autocomplete="country-name"
               value="<?= htmlspecialchars($displayClient['nationality']) ?>"
               placeholder="Nationality" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="form-row">
        <label for="client_dob">Date of Birth</label>
        <input type="date" id="client_dob" required autocomplete="bday"
               value="<?= htmlspecialchars($displayClient['dob']) ?>" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="form-row">
        <label for="client_address">Address</label>
        <textarea id="client_address" rows="2" placeholder="Full residential address"
                  <?= $isSigned ? 'readonly' : '' ?>><?= htmlspecialchars($displayClient['address']) ?></textarea>
      </div>

      <div class="form-row">
        <label for="client_phone">Telephone</label>
        <input type="tel" id="client_phone" required autocomplete="tel"
               value="<?= htmlspecialchars($displayClient['phone']) ?>"
               placeholder="Phone number" <?= $isSigned ? 'readonly' : '' ?>>
      </div>
    </div>

    <p>(Hereinafter referred to as "the Client")</p>
    <p>The Company and the Client shall collectively be referred to as "the Parties."</p>

    <div class="section-title">1. PURPOSE</div>
    <p>This agreement records the scope and proposed fee schedule for services relating to the Client's exploration of employment in Canada through the Francophone Mobility program. It also explains the job-matching process that begins after the Client successfully passes the pre-assessment. Immigration approval and entry into Canada remain decisions of the competent Canadian authorities.</p>

    <div class="section-title">2. SCOPE OF SERVICES</div>
    <p>Subject to legal authorization and the Client's separate written instructions, services may include eligibility screening, CV review, profile organization, language-test guidance, interview preparation, administrative document organization, job-matching coordination, and general follow-up. Canadian immigration advice or representation, where required, will be provided only by an authorized Canadian representative under an appropriate retainer.</p>

    <div class="section-title">2.1 Job offer search after successful pre-assessment</div>
    <p>Once the Client successfully passes the pre-assessment, Parrot Canada Visa Consultant Co. Ltd. confirms that its Canadian partner will accept the Client into the active job-matching process and will actively work to secure a suitable Canadian job offer for the Client.</p>
    <p>The Canadian partner will present the Client's profile to suitable employers, coordinate employer communication, and continue reasonable job-search and matching efforts while the Client remains eligible, provides all required documents, responds promptly, and satisfies applicable language and employer requirements.</p>
    <p>Parrot Canada Visa Consultant Co. Ltd. is confident in its Canadian partner's ability to obtain a suitable job offer for a Client who passes the pre-assessment and cooperates fully. However, the final job offer must be issued and signed by an independent Canadian employer. Therefore, neither Parrot Canada Visa Consultant Co. Ltd. nor its Canadian partner can legally guarantee the employer's final hiring decision, the timing of an offer, or immigration approval.</p>

    <div class="section-title">3. RESPONSIBILITIES</div>
    <p><strong>Client:</strong> Provide genuine documents and accurate information, attend agreed appointments, meet applicable language and program requirements, cooperate with interviews and employer requests, and promptly notify the Company of changes.</p>
    <p><strong>Company:</strong> Act professionally, protect personal information, communicate material updates, conduct or coordinate the pre-assessment, begin job-matching coordination after a successful pre-assessment, and identify which services can lawfully be provided and charged to the Client.</p>

    <div class="section-title">4. PROPOSED FEES AND PAYMENT MILESTONES</div>
    <p>The parties propose the following total and timing, subject to confirmation that each charge is lawful, itemized, and relates solely to client-payable services.</p>

    <?php $feeSchedule = fm_contract_fee_schedule((string) ($contract['contract_variant'] ?? 'standard')); ?>
    <table class="fee-table">
      <thead>
        <tr>
          <th>Payment</th>
          <th>Trigger</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($feeSchedule['rows'] as $feeRow): ?>
        <tr>
          <td><?= htmlspecialchars($feeRow[0]) ?></td>
          <td><?= htmlspecialchars($feeRow[1]) ?></td>
          <td class="amt"><?= htmlspecialchars($feeRow[2]) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td><strong>Total</strong></td>
          <td></td>
          <td class="amt"><?= htmlspecialchars($feeSchedule['total']) ?></td>
        </tr>
      </tbody>
    </table>

    <p class="compliance-note"><strong>Compliance condition:</strong> No amount is due for recruiting a foreign worker, securing a job offer, employer-side LMIA or employer-compliance costs, or other expenses that the law requires an employer or recruiter to bear. Any unlawful or prohibited charge is void and must not be collected. The parties must revise this schedule before signing if the stated amounts cannot lawfully be charged.</p>

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

    <div class="section-title">10. SIGNATURES</div>
    <p><strong>For Parrot Canada Visa Consultant Co. Ltd.</strong><br>
    Name: Dr. Jean Pierre Twajamahoro, Managing Director</p>
    <p>The Client signs below.</p>

    <div class="sign-section">
      <h3>SIGNED BY THE CLIENT</h3>

      <div class="form-row">
        <label for="sig_client_name">Full Name</label>
        <input type="text" id="sig_client_name" placeholder="Full legal name" <?= $isSigned ? 'readonly value="' . htmlspecialchars($displayClient['name']) . '"' : '' ?>>
      </div>

      <div class="form-row">
        <label>Signature</label>
        <div class="sig-canvas-wrap">
          <?php if ($isSigned && $clientSignatureData): ?>
            <img src="<?= $clientSignatureData ?>" style="max-height:130px;" alt="Client Signature">
          <?php else: ?>
            <canvas class="signature-canvas"></canvas>
            <span class="sig-hint">Draw your signature</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-row">
        <label for="sig_signed_date">Date</label>
        <input type="date" id="sig_signed_date" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="form-row">
        <label for="sig_passport">Passport Number</label>
        <input type="text" id="sig_passport" placeholder="Passport number"
               value="<?= htmlspecialchars($displayClient['passport']) ?>" <?= $isSigned ? 'readonly' : '' ?>>
      </div>

      <div class="sign-btns">
        <button id="clearSignature" type="button">Clear</button>
        <button id="signContract" type="button">Sign &amp; Submit Contract</button>
      </div>
      <input type="hidden" id="signatureData">
    </div>

    <div class="footer-ref">
      Contract Reference: <?= htmlspecialchars($contract['contract_token']) ?>
      <?php if ($referenceId): ?> | Application: <?= htmlspecialchars($referenceId) ?><?php endif; ?>
    </div>
  </div>
</div>

<script>
const CONTRACT_TOKEN = <?= json_encode($token) ?>;
const IS_SIGNED = <?= $isSigned ? 'true' : 'false' ?>;

<?php if (!$isSigned): ?>
const fmAutofill = {
  applicant: null,
  isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
  },
  apply(applicant) {
    if (!applicant) return;
    this.applicant = applicant;
    const emailField = document.getElementById('client_email');
    const matchBox = document.getElementById('autofillMatch');
    if (emailField && applicant.email) {
      emailField.value = applicant.email;
    }
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el && val !== undefined && val !== null && String(val).trim() !== '') {
        el.value = val;
      }
    };
    set('client_name', applicant.full_name);
    set('client_phone', applicant.phone);
    set('client_nationality', applicant.nationality);
    set('client_address', applicant.address);
    set('client_dob', applicant.date_of_birth);
    set('client_passport', applicant.passport_number);
    const sigName = document.getElementById('sig_client_name');
    const sigPassport = document.getElementById('sig_passport');
    const nameEl = document.getElementById('client_name');
    const passportEl = document.getElementById('client_passport');
    if (sigName && nameEl) sigName.value = nameEl.value;
    if (sigPassport && passportEl) sigPassport.value = passportEl.value;
    if (matchBox) {
      matchBox.innerHTML = '<span style="color:#15803d;">✓ Auto-filled from mobility application' +
        (applicant.reference_id ? ' (' + applicant.reference_id + ')' : '') + '.</span>';
      matchBox.classList.add('visible');
    }
  },
  clearFields() {
    this.applicant = null;
    ['client_name', 'client_phone', 'client_nationality', 'client_address', 'client_dob', 'client_passport']
      .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const matchBox = document.getElementById('autofillMatch');
    if (matchBox) {
      matchBox.classList.remove('visible');
      matchBox.innerHTML = '';
    }
  },
  async lookup(query) {
    const q = String(query || '').trim();
    if (q.length < 3) return null;
    const res = await fetch('fm-autofill.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: q })
    });
    const data = await res.json();
    if (!data.possible_match || !data.applicant) return null;
    this.apply(data.applicant);
    return data.applicant;
  },
  async resolveEmailForSubmit() {
    const emailField = document.getElementById('client_email');
    let email = emailField?.value.trim() || '';
    if (this.isValidEmail(email)) return email;
    if (this.applicant?.email && this.isValidEmail(this.applicant.email)) {
      if (emailField) emailField.value = this.applicant.email;
      return this.applicant.email;
    }
    if (email.length >= 3) {
      const applicant = await this.lookup(email);
      if (applicant?.email && this.isValidEmail(applicant.email)) {
        return applicant.email;
      }
    }
    return null;
  }
};

(() => {
  const canvas = document.querySelector('.signature-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const btnClear = document.getElementById('clearSignature');
  const btnSubmit = document.getElementById('signContract');
  const inputSigName = document.getElementById('sig_client_name');
  const inputSigDate = document.getElementById('sig_signed_date');
  const inputSigPassport = document.getElementById('sig_passport');
  const mainName = document.getElementById('client_name');
  const mainPassport = document.getElementById('client_passport');
  const todayIso = new Date().toISOString().slice(0, 10);
  if (inputSigDate && !inputSigDate.value) inputSigDate.value = todayIso;
  if (mainName && inputSigName && !inputSigName.value) inputSigName.value = mainName.value.trim();
  if (mainPassport && inputSigPassport && !inputSigPassport.value) inputSigPassport.value = mainPassport.value.trim();
  mainName?.addEventListener('input', () => { inputSigName.value = mainName.value.trim(); });
  mainPassport?.addEventListener('input', () => { inputSigPassport.value = mainPassport.value.trim(); });

  let drawing = false;
  function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#000';
  }
  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);

  function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    if (e.touches) return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
    return { x: e.offsetX, y: e.offsetY };
  }
  function startDraw(e) { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
  function draw(e) { if (!drawing) return; e.preventDefault(); const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
  function stopDraw() { drawing = false; }
  canvas.addEventListener('mousedown', startDraw);
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDraw);
  canvas.addEventListener('mouseleave', stopDraw);
  canvas.addEventListener('touchstart', startDraw, { passive: false });
  canvas.addEventListener('touchmove', draw, { passive: false });
  canvas.addEventListener('touchend', stopDraw);

  function hasSignature() {
    const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    for (let i = 3; i < d.length; i += 4) if (d[i] > 0) return true;
    return false;
  }

  btnClear?.addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  });

  btnSubmit?.addEventListener('click', async () => {
    const name = document.getElementById('client_name')?.value.trim();
    let email = document.getElementById('client_email')?.value.trim();
    const dob = document.getElementById('client_dob')?.value;
    const nationality = document.getElementById('client_nationality')?.value.trim();
    const phone = document.getElementById('client_phone')?.value.trim();
    const address = document.getElementById('client_address')?.value.trim();
    const passport = document.getElementById('client_passport')?.value.trim();
    const agreementDate = document.getElementById('agreement_date')?.value;
    const signedDate = inputSigDate?.value;
    const sigName = inputSigName?.value.trim() || name;

    if (!name || !dob || !nationality || !phone || !signedDate) {
      alert('Please complete all required client fields before signing.');
      return;
    }
    if (!hasSignature()) {
      alert('Please draw your signature before submitting.');
      return;
    }

    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Submitting...';

    if (!fmAutofill.isValidEmail(email)) {
      btnSubmit.textContent = 'Resolving email...';
      const resolved = await fmAutofill.resolveEmailForSubmit();
      if (!resolved) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Sign & Submit Contract';
        alert('Please enter a valid email address (type at least 3 characters to auto-fill from your application).');
        return;
      }
      email = resolved;
    }

    const payload = {
      token: CONTRACT_TOKEN,
      client_name: sigName,
      client_email: email,
      client_dob: dob,
      client_nationality: nationality,
      client_phone: phone,
      client_address: address,
      client_passport: passport || inputSigPassport?.value.trim(),
      agreement_date: agreementDate,
      signed_date: signedDate,
      signature: canvas.toDataURL('image/png')
    };

    fetch('submit-fm-signature.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        alert(data.pdf_error
          ? 'Contract signed, but PDF generation had an issue. Reload and try Download.'
          : 'Contract signed successfully! You can now download your signed agreement.');
        window.location.reload();
        return;
      }
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Sign & Submit Contract';
      alert(data.error || 'Submission failed.');
    })
    .catch(() => {
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Sign & Submit Contract';
      alert('Unable to submit. Please check your connection and try again.');
    });
  });
})();

(() => {
  const emailField = document.getElementById('client_email');
  if (!emailField) return;

  let debounce = null;
  let lastSearchQuery = '';

  emailField.addEventListener('input', () => {
    clearTimeout(debounce);
    const query = emailField.value.trim();

    if (query.length < 3) {
      fmAutofill.clearFields();
      lastSearchQuery = '';
      return;
    }

    const resolved = fmAutofill.applicant?.email || '';
    const stillSameLookup = resolved && (
      resolved.toLowerCase() === query.toLowerCase() ||
      resolved.toLowerCase().includes(query.toLowerCase())
    );

    if (!stillSameLookup && query !== lastSearchQuery) {
      fmAutofill.clearFields();
    }

    lastSearchQuery = query;
    debounce = setTimeout(async () => {
      const current = emailField.value.trim();
      if (current.length < 3) return;
      await fmAutofill.lookup(current);
    }, 400);
  });

  const initialEmail = emailField.value.trim();
  if (initialEmail.length >= 3) {
    fmAutofill.lookup(initialEmail);
  }
})();
<?php endif; ?>
</script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/agent_contract_schema.php';

agent_contract_ensure_schema($conn);

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

if (!isset($_GET['token']) || trim($_GET['token']) === '') {
    http_response_code(400);
    exit('Invalid contract link.');
}

$token = trim($_GET['token']);

$stmt = $conn->prepare('SELECT * FROM agent_contracts WHERE contract_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit('This contract link is invalid or expired.');
}

$isSigned = ($contract['status'] === 'signed');

$agentSignatureData = null;
$signedAgentName = '';
$signedDate = '';
$signedTitle = '';
if ($isSigned && !empty($contract['id'])) {
    $contractId = (int) $contract['id'];
    $stmt = $conn->prepare('
        SELECT agent_name, agent_title, signed_date, signature_image
        FROM agent_signatures
        WHERE contract_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $sigRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($sigRow) {
        $signedAgentName = $sigRow['agent_name'] ?? '';
        $signedTitle = $sigRow['agent_title'] ?? '';
        $signedDate = $sigRow['signed_date'] ?? '';
        if (!empty($sigRow['signature_image'])) {
            $agentSignatureData = $sigRow['signature_image'];
        }
    }
}

$display = [
    'name'    => (string) ($contract['agent_name'] ?? ''),
    'email'   => (string) ($contract['agent_email'] ?? ''),
    'phone'   => (string) ($contract['agent_phone'] ?? ''),
    'address' => (string) ($contract['agent_address'] ?? ''),
    'title'   => (string) ($contract['agent_title'] ?? ''),
];

if (!$isSigned && !empty($contract['admin_id'])) {
    $aid = (int) $contract['admin_id'];
    $stmt = $conn->prepare('
        SELECT first_name, last_name, full_name, email, phone_number, address, role
        FROM admins WHERE id = ? LIMIT 1
    ');
    if ($stmt) {
        $stmt->bind_param('i', $aid);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($admin) {
            if ($display['name'] === '') {
                $display['name'] = trim((string) ($admin['full_name'] ?? ''))
                    ?: trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
            }
            if ($display['email'] === '') {
                $display['email'] = (string) ($admin['email'] ?? '');
            }
            if ($display['phone'] === '') {
                $display['phone'] = (string) ($admin['phone_number'] ?? '');
            }
            if ($display['address'] === '') {
                $display['address'] = (string) ($admin['address'] ?? '');
            }
            if ($display['title'] === '') {
                $display['title'] = (($admin['role'] ?? '') === 'staff') ? 'Staff' : 'Agent';
            }
        }
    }
}

$effectiveDate = $contract['effective_date'] ?? date('Y-m-d');
$companyDate = $effectiveDate ? date('Y/m/d', strtotime((string) $effectiveDate)) : date('Y/m/d');
$ro = $isSigned ? 'readonly' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Referral and Commission Agreement | Parrot Canada Visa Consultant</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --primary: #427431;
  --primary-dark: #2d5a27;
  --primary-light: #5a9448;
  --ink: #1a2e1a;
  --muted: #4b5563;
  --border: #d4e4d4;
  --soft: #f0f7f0;
  --paper: #ffffff;
  --success: #15803d;
  --radius-sm: 8px;
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
.contract-signed #clearSignature,
.contract-signed #signContract { display: none !important; }
.signed-banner {
  max-width: 900px; margin: 0 auto 20px;
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  border: 1px solid #86efac; border-radius: 14px; padding: 20px 24px; text-align: center;
}
.signed-banner h2 { margin: 0 0 8px; color: var(--success); font-size: 20px; }
.signed-banner p { margin: 0 0 16px; color: #166534; font-size: 14px; }
.signed-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-dl, .btn-view {
  padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-block;
}
.btn-dl { background: var(--primary); color: #fff; }
.btn-view { background: #fff; color: var(--primary); border: 2px solid var(--primary); }
.contract-wrap {
  max-width: 900px; margin: 0 auto; background: var(--paper);
  border-radius: 16px; box-shadow: 0 20px 60px rgba(66, 116, 49, 0.15);
  overflow: hidden; border-top: 5px solid var(--primary);
}
.contract-header {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
  color: #fff; padding: 28px 24px; text-align: center;
}
.contract-header .badge {
  display: inline-block; background: rgba(255,255,255,0.2);
  padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600;
  letter-spacing: 0.5px; margin-bottom: 12px;
}
.contract-header h1 { margin: 0 0 6px; font-size: 22px; font-weight: 700; }
.contract-header h2 { margin: 0; font-size: 15px; font-weight: 500; opacity: 0.95; line-height: 1.4; }
.contract-body { padding: 28px 24px 32px; }
.notice {
  background: #fffbeb; border-left: 4px solid #f59e0b; padding: 14px 16px;
  border-radius: 0 8px 8px 0; font-size: 13px; color: #92400e; margin-bottom: 24px; line-height: 1.5;
}
.section-title {
  font-size: 16px; font-weight: 700; color: var(--primary);
  margin: 28px 0 14px; padding-bottom: 6px; border-bottom: 2px solid var(--border);
}
.section-title:first-of-type { margin-top: 0; }
p { font-size: 14px; line-height: 1.65; margin: 0 0 12px; text-align: justify; }
ul.contract-list { margin: 8px 0 16px; padding-left: 22px; font-size: 14px; line-height: 1.65; }
ul.contract-list li { margin-bottom: 6px; }
.parties-box, .agent-form {
  background: var(--soft); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin: 16px 0 24px;
}
.agent-form h3, .parties-box h3 {
  margin: 0 0 16px; font-size: 15px; color: var(--primary); text-transform: uppercase; letter-spacing: 0.3px;
}
.form-row { margin-bottom: 14px; }
.form-row label {
  display: block; font-size: 12px; font-weight: 600; color: var(--muted);
  margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px;
}
.form-row input, .form-row textarea {
  width: 100%; padding: 11px 14px; border: 2px solid var(--border); border-radius: 8px;
  font-size: 15px; font-family: inherit;
}
.form-row input:focus, .form-row textarea:focus { outline: none; border-color: var(--primary-light); }
.form-row input[readonly], .form-row textarea[readonly] { background: #f3f4f6; }
.fee-table-wrap { overflow-x: auto; margin: 16px 0; }
.fee-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 420px; }
.fee-table th, .fee-table td { border: 1px solid var(--border); padding: 10px 12px; text-align: left; }
.fee-table th { background: var(--soft); color: var(--primary); font-weight: 700; }
.fee-table .fee-amount { font-weight: 700; color: var(--primary); white-space: nowrap; }
.signature-canvas {
  width: 100%; height: 140px; border: none; background: #ffffff; cursor: crosshair; touch-action: none;
}
.signature-grid { display: grid; grid-template-columns: 1fr; gap: 32pt; margin: 32pt 0; }
@media (min-width: 768px) {
  .signature-grid { grid-template-columns: 1fr 1fr; gap: 40pt 64pt; }
}
button {
  font-family: system-ui, sans-serif; font-size: 14px; font-weight: 600;
  padding: 10px 18px; border-radius: var(--radius-sm); border: none; cursor: pointer;
}
#clearSignature { background: #f3f4f6; color: var(--ink); }
#signContract { background: #2563eb; color: #ffffff; }
#signContract:disabled { background: #9ca3af; cursor: not-allowed; }
.footer-ref { text-align: center; margin-top: 28px; font-size: 11px; color: #9ca3af; }
.sig-label { font-family: Georgia, 'Times New Roman', serif; color: #1e3a5f; font-weight: 700; }
</style>
</head>
<body<?= $isSigned ? ' class="contract-signed"' : '' ?>>

<?php if ($isSigned): ?>
<div class="signed-banner">
  <h2>✓ Contract Signed Successfully</h2>
  <p>Your Agent Referral and Commission Agreement has been recorded.</p>
  <div class="signed-actions">
    <a class="btn-dl" href="download-agent-contract.php?token=<?= urlencode($token) ?>">Download Signed PDF</a>
    <a class="btn-view" href="download-agent-contract.php?token=<?= urlencode($token) ?>&amp;inline=1" target="_blank" rel="noopener">View PDF</a>
  </div>
</div>
<?php endif; ?>

<div class="contract-wrap">
  <div class="contract-header">
    <div class="badge">AGENT REFERRAL &amp; COMMISSION</div>
    <h1>PARROT CANADA VISA CONSULTANT CO. LTD.</h1>
    <h2>AGENT REFERRAL AND COMMISSION AGREEMENT</h2>
  </div>

  <div class="contract-body">
    <?php if (!$isSigned): ?>
    <div class="notice">
      Please read this Agreement carefully. By signing electronically below, you acknowledge that you fully understand and voluntarily accept all terms and conditions contained herein.
    </div>
    <?php endif; ?>

    <p>This Agent Referral and Commission Agreement (the &ldquo;Agreement&rdquo;) is made effective as of
      <strong><?= htmlspecialchars($effectiveDate ? date('F j, Y', strtotime((string) $effectiveDate)) : '_______________') ?></strong>
      between:</p>

    <div class="parties-box">
      <h3>Company</h3>
      <p style="margin:0"><strong>Parrot Canada Visa Consultant Company Ltd.</strong>, with registered address at Gasanze Cell, Nduba Sector, Gasabo District, Kigali, Rwanda (the &ldquo;Company&rdquo;).</p>
      <p style="margin:8px 0 0">Company email: <a href="mailto:admission@visaconsultantcanada.com">admission@visaconsultantcanada.com</a><br>
      Company website: <a href="https://www.visaconsultantcanada.com" target="_blank" rel="noopener">www.visaconsultantcanada.com</a></p>
    </div>

    <div class="agent-form">
      <h3>Agent / Staff details</h3>
      <div class="form-row">
        <label for="agent_name">Full legal / business name *</label>
        <input type="text" id="agent_name" value="<?= htmlspecialchars($isSigned ? $signedAgentName : $display['name']) ?>" placeholder="Enter full legal or business name" <?= $ro ?>>
      </div>
      <div class="form-row">
        <label for="agent_email">Email (notices) *</label>
        <input type="email" id="agent_email" value="<?= htmlspecialchars($display['email']) ?>" placeholder="email@example.com" <?= $ro ?>>
      </div>
      <div class="form-row">
        <label for="agent_phone">Phone</label>
        <input type="text" id="agent_phone" value="<?= htmlspecialchars($display['phone']) ?>" <?= $ro ?>>
      </div>
      <div class="form-row">
        <label for="agent_address">Address *</label>
        <textarea id="agent_address" rows="2" placeholder="Physical / business address" <?= $ro ?>><?= htmlspecialchars($display['address']) ?></textarea>
      </div>
      <div class="form-row">
        <label for="agent_title">Title (if applicable)</label>
        <input type="text" id="agent_title" value="<?= htmlspecialchars($isSigned ? $signedTitle : $display['title']) ?>" placeholder="Agent / Staff / Director…" <?= $ro ?>>
      </div>
      <div class="form-row">
        <label for="effective_date">Effective date *</label>
        <input type="date" id="effective_date" value="<?= htmlspecialchars((string) $effectiveDate) ?>" <?= $ro ?>>
      </div>
    </div>

    <p>The Company and the Agent are each a &ldquo;Party&rdquo; and together the &ldquo;Parties.&rdquo;</p>

    <div class="section-title">1. Purpose and Appointment</div>
    <p>The Company appoints the Agent on a non-exclusive basis to identify and refer prospective students who may require admission, educational consulting, and/or visa-support services. The Agent accepts the appointment subject to this Agreement. Nothing in this Agreement grants the Agent authority to bind the Company, sign documents on the Company&rsquo;s behalf, make guarantees, collect money in the Company&rsquo;s name unless expressly authorized in writing, or represent that the Agent is an employee or legal representative of the Company.</p>

    <div class="section-title">2. Term</div>
    <p>This Agreement begins on the Effective Date and continues for an initial term of one year. It will renew automatically for successive one-year terms unless either Party gives written notice of non-renewal at least 30 days before the end of the current term, or the Agreement is terminated earlier under Section 15.</p>

    <div class="section-title">3. Agent Responsibilities</div>
    <p>The Agent shall:</p>
    <ul class="contract-list">
      <li>provide prospective students with accurate, current, and non-misleading information supplied or approved by the Company;</li>
      <li>refer only genuine applicants and conduct reasonable identity and document checks before submission;</li>
      <li>submit complete information and documents promptly and keep the Company informed of material developments;</li>
      <li>protect student information and obtain all consents required to share it with the Company, schools, government authorities, and service providers;</li>
      <li>avoid promises or guarantees of admission, scholarships, visas, processing times, employment, permanent residence, or any other outcome;</li>
      <li>avoid unauthorized immigration or legal advice and comply with all licensing requirements that apply to the Agent;</li>
      <li>use the Company&rsquo;s name, logo, materials, and pricing only as authorized in writing; and</li>
      <li>maintain professional conduct and comply with applicable anti-fraud, anti-bribery, sanctions, consumer-protection, privacy, advertising, education-recruitment, and immigration laws.</li>
    </ul>

    <div class="section-title">4. Company Responsibilities</div>
    <p>The Company shall:</p>
    <ul class="contract-list">
      <li>provide the Agent with current service information, approved marketing materials, document requirements, and fee information;</li>
      <li>review referred files and communicate material requirements or deficiencies within a reasonable time;</li>
      <li>maintain records sufficient to calculate fees and commissions under this Agreement;</li>
      <li>pay undisputed amounts owing to the Agent in accordance with this Agreement; and</li>
      <li>handle student applications with reasonable care, while recognizing that admission and visa decisions are made by independent institutions and government authorities.</li>
    </ul>

    <div class="section-title">5. Referral Registration and Ownership</div>
    <p>A referral is eligible only if the Agent identifies the student to the Company in writing before the student becomes an existing Company lead or client, and the Company confirms the referral. If more than one agent claims the same student, the Company&rsquo;s dated records and first confirmed referral will control, unless the Parties agree otherwise in writing. No commission is payable on an unconfirmed, duplicate, fraudulent, cancelled, or unpaid referral.</p>

    <div class="section-title">6. Services and Benefits Fees</div>
    <p>The applicable Benefits Fee depends on the service category selected by the student. All amounts are subject to applicable taxes and third-party charges, which are separate unless expressly stated otherwise.</p>

    <p><strong>6.1 Full Admission and Visa Services</strong></p>
    <p>These fees apply when the Company assists the student from the beginning of the admission process through visa approval.</p>
    <div class="fee-table-wrap">
      <table class="fee-table">
        <thead><tr><th>Region</th><th>Benefits Fee</th><th>Currency</th></tr></thead>
        <tbody>
          <tr><td>United States and Europe</td><td class="fee-amount">$1,000</td><td>USD</td></tr>
          <tr><td>Canada</td><td class="fee-amount">$1,300</td><td>CAD</td></tr>
          <tr><td>China</td><td class="fee-amount">$800</td><td>USD</td></tr>
          <tr><td>South Korea</td><td class="fee-amount">$800</td><td>USD</td></tr>
        </tbody>
      </table>
    </div>

    <p><strong>6.2 Visa Services Only</strong></p>
    <p>These fees apply when the student already has a valid admission letter and requires assistance with the visa application process through visa approval.</p>
    <div class="fee-table-wrap">
      <table class="fee-table">
        <thead><tr><th>Region</th><th>Benefits Fee</th><th>Currency</th></tr></thead>
        <tbody>
          <tr><td>United States and Europe</td><td class="fee-amount">$500</td><td>USD</td></tr>
          <tr><td>Canada</td><td class="fee-amount">$650</td><td>CAD</td></tr>
          <tr><td>China</td><td class="fee-amount">$400</td><td>USD</td></tr>
          <tr><td>South Korea</td><td class="fee-amount">$400</td><td>USD</td></tr>
        </tbody>
      </table>
    </div>

    <div class="section-title">7. Benefits Fee Distribution</div>
    <p>For every region and both service categories in Section 6, the Benefits Fee actually received and retained by the Company will be divided equally after any authorized deductions, refunds, reversals, chargebacks, taxes collected, and unrecoverable payment-processing charges:</p>
    <ul class="contract-list">
      <li><strong>Agent:</strong> 50% of the applicable net Benefits Fee.</li>
      <li><strong>Company:</strong> 50% of the applicable net Benefits Fee.</li>
    </ul>

    <div class="section-title">8. Additional Commission After Admission Letter Approval</div>
    <p>In addition to the Agent&rsquo;s share of the Benefits Fee under Section 7, and only after the student&rsquo;s admission letter has been approved and the applicable application fee has been paid in full and cleared, the Agent will receive the following additional commission:</p>
    <p><strong>8.1 Regional Commission Rates</strong></p>
    <div class="fee-table-wrap">
      <table class="fee-table">
        <thead><tr><th>Destination</th><th>Agent Rate</th><th>Commission Basis</th></tr></thead>
        <tbody>
          <tr><td>Canada</td><td class="fee-amount">15%</td><td>Application fee paid for Canada</td></tr>
          <tr><td>United States</td><td class="fee-amount">50%</td><td>Application fee paid for the United States</td></tr>
          <tr><td>South Korea</td><td class="fee-amount">10%</td><td>Application fee paid for South Korea</td></tr>
          <tr><td>China</td><td class="fee-amount">10%</td><td>Application fee paid for China</td></tr>
          <tr><td>Europe</td><td class="fee-amount">10%</td><td>Application fee paid for Europe</td></tr>
        </tbody>
      </table>
    </div>
    <p>For clarity, no additional commission under this Section becomes earned solely because an application was submitted. If an admission letter is withdrawn, cancelled, found to be based on inaccurate information, or the related payment is reversed before payment to the Agent, the additional commission is not payable.</p>

    <div class="section-title">9. Payment Schedule, Statements, and Disputes</div>
    <p>The Company will pay each undisputed Benefits Fee share and additional commission within one month after all applicable payment conditions have been satisfied. Payment will be made directly to the Agent&rsquo;s verified account; payment to third parties is not permitted. The Company will provide a statement identifying the student or reference number, service category, amount received, authorized deductions, commission rate, and net amount payable. The Agent must notify the Company in writing of any calculation dispute within 30 days after receiving the statement, with supporting details.</p>

    <div class="section-title">10. Refunds, Chargebacks, and Clawbacks</div>
    <p>Commissions are calculated only on funds finally received and retained. If the Company refunds a student, receives a chargeback, suffers payment reversal, or discovers fraud or material misrepresentation after paying the Agent, the corresponding overpayment becomes repayable by the Agent within 15 days after written notice. The Company may offset that amount against future payments, with a supporting statement. No clawback will exceed the commission or Benefits Fee share attributable to the affected student, except in cases of fraud, wilful misconduct, or unauthorized collection of funds by the Agent.</p>

    <div class="section-title">11. Taxes and Expenses</div>
    <p>The Agent is responsible for all taxes, registrations, filings, insurance, banking charges, and business expenses arising from payments received under this Agreement. The Company may withhold amounts where required by law and will provide available supporting documentation. Neither Party may incur expenses in the other Party&rsquo;s name without prior written approval.</p>

    <div class="section-title">12. Confidentiality, Privacy, and Records</div>
    <p>Each Party shall keep confidential all non-public business, pricing, student, financial, operational, and technical information received from the other Party and use it only to perform this Agreement. Each Party shall apply reasonable security safeguards, restrict access to personnel with a need to know, report suspected loss or unauthorized disclosure promptly, and securely return or destroy information when no longer required, subject to legal retention duties. The Agent shall retain referral and payment-supporting records for at least two years, or longer if required by applicable law. These obligations survive termination.</p>

    <div class="section-title">13. Intellectual Property and Marketing</div>
    <p>The Company retains all rights in its names, logos, forms, processes, websites, and materials. The Agent receives a limited, revocable, non-transferable right during the term to use Company-approved materials solely to perform this Agreement. The Agent shall not alter branding, register confusingly similar names or domains, publish unapproved advertisements, or imply a partnership, branch office, government affiliation, or guaranteed outcome.</p>

    <div class="section-title">14. Compliance, Conflicts, and Non-Circumvention</div>
    <p>The Agent shall disclose any actual or potential conflict of interest and shall not pay or accept undisclosed referral fees, bribes, kickbacks, or improper benefits. During the term, the Agent shall not knowingly bypass the Company to collect fees directly for a confirmed referral or divert that referral to another provider after the Company has begun substantive work, unless the student independently chooses otherwise or the Company materially fails to provide the agreed services. Nothing in this clause restricts lawful competition or a student&rsquo;s freedom of choice.</p>

    <div class="section-title">15. Termination</div>
    <p>Either Party may terminate this Agreement without cause on 30 days&rsquo; written notice. Either Party may terminate immediately for material breach that is not cured within 10 days after written notice, or immediately without a cure period for fraud, unlawful conduct, misuse of funds or personal information, serious reputational harm, insolvency, loss of a required licence, or repeated material misrepresentation. Termination does not affect amounts properly earned before the effective termination date, subject to payment conditions, refunds, chargebacks, offsets, and clawbacks. Sections intended by their nature to survive will remain in force.</p>

    <div class="section-title">16. Independent Contractor; No Guarantees</div>
    <p>The Agent is an independent contractor and not an employee, partner, joint venturer, franchisee, or legal representative of the Company. The Agent has no entitlement to wages, benefits, vacation pay, employment insurance, pension contributions, or workers&rsquo; compensation from the Company, except as required by law. The Company does not guarantee admission, visa approval, processing time, scholarships, travel, employment, or immigration outcomes, all of which may depend on third parties and government authorities.</p>

    <div class="section-title">17. Limitation of Liability and Indemnity</div>
    <p>To the maximum extent permitted by law, neither Party will be liable to the other for indirect, incidental, special, punitive, or consequential losses arising from this Agreement. Each Party will be responsible for direct losses, claims, penalties, and reasonable costs caused by its own breach, negligence, fraud, wilful misconduct, unlawful acts, or violation of privacy or intellectual-property rights. Nothing in this Agreement excludes liability that cannot lawfully be excluded.</p>

    <div class="section-title">18. Notices</div>
    <p>Formal notices must be in writing and delivered by personal delivery, recognized courier, or email with confirmation of transmission to the contacts below, or to any replacement contact notified in writing. Notice is effective on delivery, or for email, on the next business day after transmission unless a delivery failure is received.</p>
    <ul class="contract-list">
      <li>Company notice address: Gasanze Cell, Nduba Sector, Gasabo District, Kigali, Rwanda</li>
      <li>Company notice email: <a href="mailto:infos@visaconsultantcanada.com">infos@visaconsultantcanada.com</a></li>
      <li>Agent notice email: <span id="notice_agent_email"><?= htmlspecialchars($display['email'] !== '' ? $display['email'] : '[AGENT EMAIL]') ?></span></li>
    </ul>

    <div class="section-title">19. Governing Law and Dispute Resolution</div>
    <p>This Agreement is governed by the laws of the Republic of Rwanda, without regard to conflict-of-laws rules. The Parties will first attempt in good faith to resolve any dispute through written notice and management discussion within 15 days. If unresolved, the competent courts located in Kigali, Rwanda will have exclusive jurisdiction, unless the Parties agree in writing to mediation or arbitration.</p>

    <div class="section-title">20. General Provisions</div>
    <p>This Agreement constitutes the entire agreement between the Parties concerning its subject matter and replaces prior discussions or understandings. Any amendment or waiver must be in writing and signed by both Parties. Neither Party may assign this Agreement without the other Party&rsquo;s prior written consent, except that the Company may assign it as part of a merger, reorganization, or sale of substantially all relevant assets. If any provision is unenforceable, it will be limited or severed to the minimum extent necessary and the remainder will continue. A failure or delay to enforce a right is not a waiver. Headings are for convenience only. This Agreement may be signed in counterparts and by electronic signature, each of which is deemed an original.</p>

    <div class="section-title">21. Signatures</div>
    <p>The Parties confirm that they have read, understood, and agreed to the terms of this Agreement and have had the opportunity to obtain independent legal advice.</p>

    <div class="contract-section signature-section" style="margin-top:24px;">
      <div class="signature-grid">

        <!-- LEFT: Company -->
        <div class="signature-block">
          <p class="sig-label" style="margin-bottom:18px;font-size:16px;">
            For Parrot Canada Visa Consultant Co. Ltd
          </p>

          <div style="margin-bottom:18px;">
            <strong class="sig-label">Name:</strong>
            <div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
              Dr. Jean Pierre Twajamahoro
            </div>
          </div>

          <div style="margin-bottom:18px;">
            <strong class="sig-label">Title:</strong>
            <div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;">
              Owner &amp; Managing Director
            </div>
          </div>

          <div style="margin-bottom:18px;">
            <strong class="sig-label">Signature:</strong>
            <div style="margin-top:6px;width:85%;">
              <img src="admin/signature-manager.png" alt="Managing Director Signature" style="max-height:70px;max-width:280px;display:block;">
            </div>
          </div>

          <div style="margin-bottom:18px;">
            <strong class="sig-label">Company Stamp:</strong>
            <div style="margin-top:6px;width:85%;">
              <img src="admin/employer-signature.png" alt="Company Stamp" style="max-height:140px;max-width:320px;display:block;">
            </div>
          </div>

          <div style="margin-bottom:8px;">
            <strong class="sig-label">Date:</strong>
            <div style="border-bottom:1px solid #000;height:24px;width:85%;margin-top:6px;padding-top:2px;" id="parrot_date">
              <?= htmlspecialchars($companyDate) ?>
            </div>
          </div>
        </div>

        <!-- RIGHT: Agent / Staff -->
        <div class="signature-block" style="padding:20px;background:#f9fafb;border-radius:12px;border:1px solid #e5e7eb;">
          <p class="sig-label" style="margin-bottom:20px;font-size:16px;">
            For the Agent / Staff
          </p>

          <div style="margin-bottom:20px;">
            <label class="sig-label" style="display:block;margin-bottom:8px;">Full Name:</label>
            <input type="text" id="sig_agent_name"
              style="width:100%;border:2px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:16px;box-sizing:border-box;"
              placeholder="Enter your full legal name"
              value="<?= htmlspecialchars($isSigned ? $signedAgentName : $display['name']) ?>"
              <?= $ro ?>>
          </div>

          <div style="margin-bottom:20px;">
            <label class="sig-label" style="display:block;margin-bottom:12px;">Signature:</label>
            <div style="border:2px dashed #9ca3af;height:150px;padding:8px;margin-bottom:14px;background:#ffffff;border-radius:8px;display:flex;align-items:center;justify-content:center;position:relative;">
              <?php if ($isSigned && !empty($agentSignatureData)): ?>
              <img src="<?= htmlspecialchars($agentSignatureData) ?>" style="max-height:130px;" alt="Agent Signature">
              <?php else: ?>
              <canvas class="signature-canvas"></canvas>
              <div style="position:absolute;top:8px;right:8px;font-size:12px;color:#9ca3af;">
                Draw your signature above
              </div>
              <?php endif; ?>
            </div>
          </div>

          <div style="margin-bottom:24px;">
            <label class="sig-label" style="display:block;margin-bottom:8px;">Date:</label>
            <input type="date" id="sig_signed_date"
              style="width:100%;border:2px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:16px;box-sizing:border-box;"
              value="<?= htmlspecialchars($isSigned ? $signedDate : '') ?>"
              <?= $ro ?>>
          </div>

          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <button id="clearSignature" type="button" style="flex:1;background:#f3f4f6;color:#374151;border:none;">Clear Signature</button>
            <button id="signContract" type="button" style="flex:2;background:#2563eb;color:#ffffff;border:none;">Sign &amp; Submit Contract</button>
            <input type="hidden" id="signatureData">
          </div>
        </div>

      </div>
    </div>

    <div class="footer-ref">
      Contract Reference: <?= htmlspecialchars($contract['contract_token']) ?>
    </div>
  </div>
</div>

<script>
const CONTRACT_TOKEN = <?= json_encode($token) ?>;

const emailInput = document.getElementById('agent_email');
const noticeEmail = document.getElementById('notice_agent_email');
if (emailInput && noticeEmail) {
  emailInput.addEventListener('input', () => {
    noticeEmail.textContent = emailInput.value.trim() || '[AGENT EMAIL]';
  });
}

<?php if (!$isSigned): ?>
(() => {
  const canvas = document.querySelector('.signature-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const btnClear = document.getElementById('clearSignature');
  const btnSubmit = document.getElementById('signContract');
  const inputName = document.getElementById('sig_agent_name');
  const inputDate = document.getElementById('sig_signed_date');
  const hiddenSignature = document.getElementById('signatureData');
  const mainName = document.getElementById('agent_name');
  const todayIso = new Date().toISOString().slice(0, 10);
  if (inputDate && !inputDate.value) inputDate.value = todayIso;
  if (mainName && inputName && !inputName.value) inputName.value = (mainName.value || '').trim();
  if (mainName && inputName) {
    mainName.addEventListener('input', () => { inputName.value = (mainName.value || '').trim(); });
  }

  let drawing = false;
  let points = [];

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
    if (e.touches) {
      return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
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
    if (points.length < 3) {
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();
      return;
    }
    const p0 = points[points.length - 3];
    const p1 = points[points.length - 2];
    const p2 = points[points.length - 1];
    const midX = (p1.x + p2.x) / 2;
    const midY = (p1.y + p2.y) / 2;
    ctx.beginPath();
    ctx.moveTo(p0.x, p0.y);
    ctx.quadraticCurveTo(p1.x, p1.y, midX, midY);
    ctx.stroke();
  }
  function stopDraw() { drawing = false; points = []; }

  canvas.addEventListener('mousedown', startDraw);
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDraw);
  canvas.addEventListener('mouseleave', stopDraw);
  canvas.addEventListener('touchstart', startDraw, { passive: false });
  canvas.addEventListener('touchmove', draw, { passive: false });
  canvas.addEventListener('touchend', stopDraw);

  btnClear.addEventListener('click', () => ctx.clearRect(0, 0, canvas.width, canvas.height));

  function hasSignature() {
    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    return pixels.some(channel => channel !== 0);
  }

  btnSubmit.addEventListener('click', () => {
    const agentName = (document.getElementById('agent_name')?.value || '').trim();
    const agentEmail = (document.getElementById('agent_email')?.value || '').trim();
    const agentPhone = (document.getElementById('agent_phone')?.value || '').trim();
    const agentAddress = (document.getElementById('agent_address')?.value || '').trim();
    const agentTitle = (document.getElementById('agent_title')?.value || '').trim();
    const effectiveDate = (document.getElementById('effective_date')?.value || '').trim();
    const sigName = inputName.value.trim();
    const signedDate = inputDate.value;

    if (!agentName || !agentEmail || !agentAddress || !effectiveDate) {
      alert('Please complete name, email, address, and effective date before signing.');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(agentEmail)) {
      alert('Please enter a valid email address.');
      document.getElementById('agent_email')?.focus();
      return;
    }
    if (!sigName) {
      alert('Please enter your full name before signing.');
      inputName.focus();
      return;
    }
    if (!signedDate) {
      alert('Please select the signing date.');
      inputDate.focus();
      return;
    }
    if (!hasSignature()) {
      alert('Please draw your signature before submitting.');
      return;
    }

    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Submitting...';
    btnSubmit.style.background = '#6b7280';

    const signature = canvas.toDataURL('image/png');
    hiddenSignature.value = signature;

    fetch('submit-agent-signature.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: CONTRACT_TOKEN,
        agent_name: sigName,
        agent_email: agentEmail,
        agent_phone: agentPhone,
        agent_address: agentAddress,
        agent_title: agentTitle,
        effective_date: effectiveDate,
        signed_date: signedDate,
        signature: signature
      })
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
      btnSubmit.style.background = '#2563eb';
      alert(data.error || 'Submission failed.');
    })
    .catch(() => {
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Sign & Submit Contract';
      btnSubmit.style.background = '#2563eb';
      alert('Unable to submit. Please check your connection and try again.');
    });
  });
})();
<?php endif; ?>
</script>
</body>
</html>

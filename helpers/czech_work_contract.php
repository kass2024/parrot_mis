<?php
declare(strict_types=1);

function czech_work_contract_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS czech_work_contracts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            contract_token CHAR(32) NOT NULL,
            reference_number VARCHAR(48) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            client_name VARCHAR(255) DEFAULT NULL,
            date_of_birth VARCHAR(40) DEFAULT NULL,
            nationality VARCHAR(120) DEFAULT NULL,
            passport_number VARCHAR(80) DEFAULT NULL,
            phone VARCHAR(80) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            residential_address TEXT,
            agreement_date DATE DEFAULT NULL,
            client_place VARCHAR(160) DEFAULT NULL,
            signature_image MEDIUMTEXT,
            pdf_path VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            signed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_czech_work_token (contract_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function czech_work_contract_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function czech_work_contract_image(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode($raw);
}

function czech_work_contract_today(): string
{
    return (new DateTime('now', new DateTimeZone('Africa/Kigali')))->format('Y-m-d');
}

/**
 * @param array<string, mixed> $row
 */
function czech_work_contract_field(string $key, array $row, bool $live, string $placeholder, string $type = 'text'): string
{
    $value = trim((string) ($row[$key] ?? ''));
    if ($key === 'agreement_date' && $value === '') {
        $value = czech_work_contract_today();
    }
    if (!$live) {
        if ($key === 'agreement_date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTime::createFromFormat('Y-m-d', $value);
            $value = $day instanceof DateTime ? $day->format('j F Y') : $value;
        }

        return '<span class="cz-value">' . czech_work_contract_h($value !== '' ? $value : '—') . '</span>';
    }
    $inputType = $type === 'date' ? 'date' : ($type === 'email' ? 'email' : 'text');

    return '<input class="cz-field" id="' . czech_work_contract_h($key) . '" type="' . $inputType . '" value="' . czech_work_contract_h($value) . '" placeholder="' . czech_work_contract_h($placeholder) . '">';
}

function czech_work_contract_css(): string
{
    return <<<'CSS'
.cz { color: #000; background: #fff; font-family: "Times New Roman", Times, "Liberation Serif", "DejaVu Serif", serif; font-size: 12pt; line-height: 1.15; }
.cz-head { text-align: center; color: #243746; font-weight: 700; font-size: 8pt; border-bottom: 1.5px solid #243746; padding: 12px 22px 6px; }
.cz-title { text-align: center; padding: 16px 28px 2px; }
.cz-title .brand { margin: 0; color: #243746; font-size: 12pt; font-weight: 700; }
.cz-title h1 { margin: 6px 0 0; color: #C62828; font-size: 12pt; font-weight: 700; line-height: 1.25; }
.cz-title .sub { margin: 8px 0 0; font-size: 12pt; font-style: italic; font-weight: 400; color: #000; }
.cz-title .total { margin: 10px 0 0; font-size: 12pt; font-weight: 700; color: #000; }
.cz-title .meta { margin: 10px 0 0; font-size: 12pt; font-weight: 700; color: #000; }
.cz-body { padding: 4px 28px 28px; }
.cz h2 { margin: 14px 0 4px; color: #243746; font-size: 12pt; font-weight: 700; text-align: left; }
.cz h3 { margin: 10px 0 4px; color: #C62828; font-size: 12pt; font-weight: 700; text-align: left; }
.cz p { margin: 0 0 8px; text-align: justify; }
.cz-table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; table-layout: fixed; }
.cz-table td, .cz-table th { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; font-size: 12pt; font-weight: 400; text-align: left; word-wrap: break-word; overflow-wrap: anywhere; }
.cz-table td.cz-label { width: 34%; background: #F1F4F6; font-weight: 700; }
.cz-pay th, .cz-sign th { background: #243746; color: #fff; font-weight: 700; }
.cz-pay td:nth-child(1) { width: 8%; text-align: center; }
.cz-pay td:nth-child(2) { width: 12%; }
.cz-pay td:nth-child(3) { width: 24%; }
.cz-pay td:nth-child(4) { width: 24%; }
.cz ul { margin: 2px 0 8px 22px; padding: 0; }
.cz li { margin: 0 0 2px; }
.cz-sign td { width: 50%; vertical-align: top; }
.cz-sig { height: 58px; max-width: 180px; }
.cz-stamp { height: 86px; max-width: 110px; margin-left: 8px; vertical-align: top; }
canvas.cz-pad { display: block; width: 100%; height: 90px; border: 1px solid #243746; background: #fff; touch-action: none; margin-top: 4px; }
input.cz-field { width: 100%; box-sizing: border-box; border: 0; border-bottom: 1px solid #243746; background: transparent; font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; padding: 1px 2px; }
input.cz-field:focus { outline: none; border-bottom-color: #C62828; }
.cz-value { font-weight: 700; }
CSS;
}

/**
 * @param array<string, mixed> $row
 */
function czech_work_contract_html(array $row, bool $live): string
{
    $sig = czech_work_contract_image(dirname(__DIR__) . '/admin/signature-manager.png');
    $stamp = czech_work_contract_image(dirname(__DIR__) . '/admin/employer-signature.png');
    $companySign = '';
    if ($sig !== '') {
        $companySign .= '<img class="cz-sig" alt="Company signature" src="' . $sig . '">';
    }
    if ($stamp !== '') {
        $companySign .= '<img class="cz-stamp" alt="Company stamp" src="' . $stamp . '">';
    }
    $clientSign = '';
    $drawn = (string) ($row['signature_image'] ?? '');
    if (str_starts_with($drawn, 'data:image/png;base64,')) {
        $clientSign = '<img class="cz-sig" alt="Job seeker signature" src="' . czech_work_contract_h($drawn) . '">';
    }
    if ($live) {
        $clientSign .= '<canvas class="cz-pad" id="clientPad"></canvas>';
    } elseif ($clientSign === '') {
        $clientSign = '<span class="cz-value">—</span>';
    }
    $ref = trim((string) ($row['reference_number'] ?? ''));
    $f = static function (string $key, string $placeholder, string $type = 'text') use ($row, $live): string {
        return czech_work_contract_field($key, $row, $live, $placeholder, $type);
    };
    $h = static function (string $text): string {
        return czech_work_contract_h($text);
    };

    $bullets = static function (array $items) use ($h): string {
        $html = '<ul>';
        foreach ($items as $item) {
            $html .= '<li>' . $h($item) . '</li>';
        }

        return $html . '</ul>';
    };

    $html = '<article class="cz">';
    $html .= '<div class="cz-head">PARROT CANADA VISA CONSULTANT CO.LTD &nbsp;|&nbsp; CZECH REPUBLIC WORK PERMIT PACKAGE</div>';
    $html .= '<div class="cz-title">';
    $html .= '<p class="brand">PARROT CANADA VISA CONSULTANT CO.LTD</p>';
    $html .= '<h1>CZECH REPUBLIC WORK PERMIT AND RESIDENCE-CARD SERVICE AGREEMENT</h1>';
    $html .= '<p class="sub">Two-Year Application Package</p>';
    $html .= '<p class="total">TOTAL PACKAGE: €2,500 (TWO THOUSAND FIVE HUNDRED EUROS)</p>';
    $html .= '<p class="meta">Agreement Reference: ' . $h($ref !== '' ? $ref : 'PCVCCL/CZ-WORK/____________') . '<br>Effective Date: ' . $f('agreement_date', 'Date', 'date') . '</p>';
    $html .= '</div><div class="cz-body">';

    $html .= '<h2>1. PARTIES</h2>';
    $html .= '<table class="cz-table"><tr><td class="cz-label">Service Coordinator</td><td>PARROT CANADA VISA CONSULTANT CO.LTD ("PARROT")</td></tr>';
    $html .= '<tr><td class="cz-label">Official Email</td><td>infos@visaconsultantcanada.com</td></tr>';
    $html .= '<tr><td class="cz-label">Office / Address</td><td>GISOZI, KIGALI RWANDA</td></tr></table>';
    $html .= '<table class="cz-table">';
    $html .= '<tr><td class="cz-label">Job Seeker\'s Full Name</td><td>' . $f('client_name', 'Full name') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Date of Birth</td><td>' . $f('date_of_birth', 'Date of birth', 'date') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Nationality</td><td>' . $f('nationality', 'Nationality') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Passport Number</td><td>' . $f('passport_number', 'Passport number') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Residential Address</td><td>' . $f('residential_address', 'Residential address') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Email</td><td>' . $f('email', 'Email', 'email') . '</td></tr>';
    $html .= '<tr><td class="cz-label">Telephone / WhatsApp</td><td>' . $f('phone', 'Telephone / WhatsApp') . '</td></tr>';
    $html .= '</table>';
    $html .= '<p>PARROT and the Job Seeker are collectively referred to as the “Parties.”</p>';

    $html .= '<h2>2. PURPOSE OF THE AGREEMENT</h2>';
    $html .= '<p>This Agreement sets out the terms under which PARROT coordinates administrative services for the Job Seeker\'s Czech Republic work-permit and residence-card application package for a proposed two-year option, including coordination with disclosed employers, recruitment partners or document providers where required.</p>';
    $html .= '<p>This is a service agreement. It is not a government permit, employment contract or guarantee of a job, work authorization, residence card, visa, entry or continued residence in the Czech Republic.</p>';

    $html .= '<h2>3. SERVICES INCLUDED</h2>';
    $html .= $bullets([
        'Pre-assessment of the Job Seeker\'s basic eligibility and document readiness.',
        'Administrative coordination of the work-permit application documents.',
        'Administrative coordination of the residence-card application documents.',
        'Collection and review of the Job Seeker\'s required personal documents.',
        'Coordination with the relevant employer, recruitment partner, local partner or document provider.',
        'Sharing of available draft or final copies of the agreed work-permit and residence-card application documents.',
        'Administrative guidance concerning the visa-application checklist and appointment preparation.',
        'Reasonable follow-up and communication of material updates received from the relevant third parties.',
    ]);
    $html .= '<p>The anticipated document-preparation period is approximately 5–15 days after the first payment, successful pre-assessment, receipt of all required documents and confirmation that processing may begin. This is an estimate, not a guaranteed deadline, and may be extended by employer, partner or authority requirements.</p>';

    $html .= '<h2>4. DEFINITION OF WORK-PERMIT DOCUMENTS</h2>';
    $html .= '<p>For this Agreement, “Work-Permit Documents” means the agreed set of application documents, draft documents, employer or partner supporting documents, confirmations or related residence-card application documents made available for the Job Seeker\'s case. It does not mean that the Czech government has issued or approved a work permit, residence card or visa unless an official authority expressly confirms such approval.</p>';

    $html .= '<h2>5. TOTAL PACKAGE AND PAYMENT SCHEDULE</h2>';
    $html .= '<p>The total professional package payable to PARROT is €2,500. No installment beyond the stated stage becomes due before the corresponding milestone is reached.</p>';
    $html .= '<table class="cz-table cz-pay"><tr><th>Stage</th><th>Amount</th><th>Due Date</th><th>Refund Status</th><th>Milestone</th></tr>';
    $html .= '<tr><td>1</td><td>€500</td><td>Before processing begins</td><td>Refundable only under Article 6</td><td>Opening, assessment and document-process commencement</td></tr>';
    $html .= '<tr><td>2</td><td>€1,250</td><td>When the Work-Permit Documents are ready and made available</td><td>Non-refundable after the milestone is reached</td><td>Work-permit and residence-card application documents ready</td></tr>';
    $html .= '<tr><td>3</td><td>€750</td><td>After official visa approval</td><td>Not payable if the visa is refused</td><td>Verifiable visa approval</td></tr></table>';

    $html .= '<h2>6. FIRST INSTALLMENT — €500</h2>';
    $html .= '<p>The Job Seeker shall pay €500 before PARROT starts the agreed process. This amount covers case opening, pre-assessment, document review, partner coordination and commencement of document preparation.</p>';
    $html .= '<h3>6.1 Refund When the Documents Cannot Be Obtained</h3>';
    $html .= '<p>The €500 installment is refundable if PARROT and its disclosed processing partner fail to obtain or make available the agreed Work-Permit Documents for the Job Seeker, provided that the Job Seeker has fully satisfied the eligibility, documentation and cooperation requirements in this Agreement.</p>';
    $html .= '<p>An approved refund shall be processed within 15 business days after PARROT confirms in writing that the agreed documents cannot be obtained and receives the Job Seeker\'s written refund request and proof of payment.</p>';

    $html .= '<h2>7. SECOND INSTALLMENT — €1,250</h2>';
    $html .= '<p>The €1,250 installment becomes due when the agreed Work-Permit Documents are ready and PARROT provides, displays, transmits or otherwise makes available verifiable draft or final copies to the Job Seeker, as appropriate to the process.</p>';
    $html .= '<p>Once that milestone is reached and the documents are made available, the €1,250 installment is non-refundable because the document-preparation and coordination service has been performed. It remains non-refundable if the Job Seeker later withdraws, changes plans, fails to submit the visa application or receives a visa refusal, subject to mandatory law and any proven material breach by PARROT.</p>';

    $html .= '<h2>8. THIRD INSTALLMENT — €750</h2>';
    $html .= '<p>The final €750 installment becomes due only after the Job Seeker receives official or otherwise verifiable approval of the relevant Czech visa. It must be paid within five business days after approval is communicated or verified. If the visa application is refused, the €750 installment is not payable.</p>';

    $html .= '<h2>9. COSTS NOT INCLUDED</h2>';
    $html .= '<p>Unless a written invoice expressly states otherwise, the €2,500 package covers only the professional and administrative services listed in Article 3. It does not include:</p>';
    $html .= $bullets([
        'Government, embassy, consulate, visa, work-permit or residence-card filing fees.',
        'Biometrics, medical examinations, police certificates or insurance.',
        'Translations, notarization, legalization, apostille, courier or banking charges.',
        'Air tickets, accommodation, food, transportation or living expenses.',
        'Employer charges, recruitment-partner charges or other third-party expenses not expressly included in writing.',
    ]);
    $html .= '<p>Any additional charge must be disclosed in writing before payment. Third-party payments are governed by the relevant provider\'s terms and refund policy.</p>';

    $html .= '<h2>10. JOB SEEKER\'S RESPONSIBILITIES</h2>';
    $html .= $bullets([
        'Provide a valid passport and all requested documents in the required format and within the stated deadline.',
        'Provide complete, accurate and truthful information, including prior refusals, overstays, removals, criminal matters and relevant medical information when lawfully requested.',
        'Provide only authentic documents and promptly correct any error or inconsistency.',
        'Review draft documents and confirm names, passport details, dates and other information before use.',
        'Attend all embassy, biometrics, medical, interview and other required appointments.',
        'Maintain sufficient funds for all excluded costs and proof-of-funds requirements.',
        'Pay each installment when the corresponding milestone is reached.',
        'Inform PARROT immediately of any change affecting eligibility, documents, employment or travel plans.',
        'Comply with Czech immigration, employment and residence conditions and all lawful instructions from the relevant authorities.',
    ]);
    $html .= '<p>The Job Seeker bears responsibility for refusal, delay, cancellation or loss caused by false information, hidden history, forged documents, insufficient funds, missed appointments, ineligibility, non-cooperation or another matter within the Job Seeker\'s control.</p>';

    $html .= '<h2>11. PARROT\'S RESPONSIBILITIES</h2>';
    $html .= $bullets([
        'Explain the package, milestones and payment conditions before taking payment.',
        'Coordinate the agreed document process with reasonable professional care.',
        'Communicate material document requirements and updates received from relevant partners.',
        'Issue or arrange official invoices and receipts for payments received.',
        'Maintain reasonable service and payment records.',
        'Protect personal information and use it only for legitimate processing and administration.',
        'Avoid guaranteeing a job, work permit, residence card, visa, processing time or entry into the Czech Republic.',
    ]);

    $html .= '<h2>12. THIRD-PARTY PARTNERS</h2>';
    $html .= '<p>The Job Seeker understands that PARROT may use employers, recruitment partners, document providers, translators, lawyers, authorized representatives or other third parties to complete parts of the process. Their identity and material role shall be disclosed where required. PARROT remains responsible for its own contractual obligations but does not control independent government, employer or third-party decisions.</p>';

    $html .= '<h2>13. VISA REFUSAL AND WITHDRAWAL</h2>';
    $html .= '<p>A visa refusal does not automatically create a refund right for the first or second installments where the corresponding services and document milestones were completed. In case of refusal, the third installment of €750 is not payable. Where the Job Seeker voluntarily withdraws, any refund is limited to unearned amounts and mandatory rights under applicable law.</p>';

    $html .= '<h2>14. NO GUARANTEE</h2>';
    $html .= '<p>Decisions concerning employment, work authorization, residence cards, visas, entry and residence are made by employers and competent Czech or other authorities. Processing times and requirements may change. PARROT does not guarantee a job offer, work permit, residence card, visa approval, travel date, entry, salary, accommodation, contract duration or permanent residence.</p>';

    $html .= '<h2>15. LEGAL AND PROFESSIONAL BOUNDARIES</h2>';
    $html .= '<p>PARROT provides referral, coordination and administrative support within its lawful authority. Any regulated legal or immigration advice or representation must be provided by an appropriately authorized professional under a separate retainer or authorization where required.</p>';

    $html .= '<h2>16. PAYMENT METHODS AND RECEIPTS</h2>';
    $html .= '<p>Payments must be made only through an account or method officially approved in writing by PARROT. The Job Seeker shall not pay a staff member\'s personal account, an undisclosed third party, cryptocurrency or any unapproved recipient. Every recognized payment must be supported by verifiable proof and an official receipt or MIS entry.</p>';

    $html .= '<h2>17. CONFIDENTIALITY AND DATA CONSENT</h2>';
    $html .= '<p>The Job Seeker authorizes PARROT to collect, use and share relevant personal information with employers, partners, authorized professionals and service providers only as reasonably necessary for assessment, document preparation, work-permit, residence-card and visa-support purposes. PARROT shall apply reasonable safeguards and comply with applicable privacy obligations.</p>';

    $html .= '<h2>18. COMMUNICATION</h2>';
    $html .= '<p>Email, the PARROT MIS, WhatsApp and other designated written channels may be used for notices, invoices, document requests, approvals and case updates. The Job Seeker must keep contact information current and respond within reasonable deadlines. Electronic signatures and written electronic acceptance may be used to the extent permitted by law.</p>';

    $html .= '<h2>19. TERMINATION</h2>';
    $html .= '<p>Either Party may terminate this Agreement by written notice. PARROT may suspend or terminate immediately for fraud, forged documents, abusive conduct, unlawful instructions, non-payment or material breach. Termination does not remove fees earned for completed milestones, valid refund rights, confidentiality duties or obligations relating to active records.</p>';

    $html .= '<h2>20. LIMITATION OF RESPONSIBILITY</h2>';
    $html .= '<p>PARROT is responsible for its own proven breach, negligence, fraud or unauthorized acts to the extent required by applicable law. PARROT is not responsible for independent acts, decisions, delays, policy changes or failures of employers, recruitment partners, embassies, consulates, government authorities, banks, airlines, insurers or other disclosed third parties, except where the law makes PARROT responsible for its own conduct.</p>';

    $html .= '<h2>21. DISPUTE RESOLUTION AND GOVERNING LAW</h2>';
    $html .= '<p>The Parties shall first attempt to resolve any dispute through good-faith written discussion. If unresolved within 30 days, they may agree to mediation before court proceedings.</p>';

    $html .= '<h2>22. ENTIRE AGREEMENT AND CHANGES</h2>';
    $html .= '<p>This Agreement, its annexes and any signed written quotation contain the entire agreement for this package and replace earlier discussions concerning the same service. Any amendment must be written and accepted by both Parties. If one provision is unenforceable, the remaining provisions continue to the extent permitted by law.</p>';

    $html .= '<h2>23. ACKNOWLEDGMENT</h2>';
    $html .= '<p>By signing, the Job Seeker confirms that the €2,500 package, payment milestones, refund conditions, excluded costs, third-party involvement and absence of any guarantee have been explained and understood; the Job Seeker has had an opportunity to ask questions; and each Party will receive an identical signed copy.</p>';

    $html .= '<h2>SIGNATURES</h2>';
    if ($live) {
        $nameShown = '<span class="cz-mirror cz-value" data-from="client_name"></span>';
        $passShown = '<span class="cz-mirror cz-value" data-from="passport_number"></span>';
        $dateShown = '<span class="cz-mirror cz-value" data-from="agreement_date"></span>';
    } else {
        $nameShown = $f('client_name', 'Full name');
        $passShown = $f('passport_number', 'Passport number');
        $dateShown = $f('agreement_date', 'Date', 'date');
    }
    $html .= '<table class="cz-table cz-sign"><tr><th>FOR PARROT CANADA VISA CONSULTANT CO.LTD</th><th>JOB SEEKER</th></tr>';
    $html .= '<tr><td>Authorized representative: Dr. Jean Pierre Twajamahoro</td><td>Full name: ' . $nameShown . '</td></tr>';
    $html .= '<tr><td>Title: Owner and Managing Director</td><td>Passport number: ' . $passShown . '</td></tr>';
    $html .= '<tr><td>Signature:<br>' . $companySign . '</td><td>Signature:<br>' . $clientSign . '</td></tr>';
    $html .= '<tr><td>Date: ' . $dateShown . '<br>Place: GISOZI, KIGALI RWANDA</td><td>Date: ' . $dateShown . '<br>Place: ' . $f('client_place', 'Place of signing') . '</td></tr>';
    $html .= '</table>';
    $html .= '</div></article>';

    return $html;
}

function czech_work_contract_pdf(mysqli $conn, int $id): string
{
    $stmt = $conn->prepare('SELECT * FROM czech_work_contracts WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return '';
    }
    if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
        return '';
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $html = '<html><head><meta charset="UTF-8"><style>body{margin:0;}@page{margin:36px 32px;}' . czech_work_contract_css() . '</style></head><body>'
        . czech_work_contract_html($row, false) . '</body></html>';
    $dir = dirname(__DIR__) . '/uploads/czech-work-contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $relative = 'uploads/czech-work-contracts/czech-' . $id . '.pdf';
    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Serif']);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4');
    $dompdf->render();
    file_put_contents(dirname(__DIR__) . '/' . $relative, $dompdf->output());
    $save = $conn->prepare('UPDATE czech_work_contracts SET pdf_path = ? WHERE id = ?');
    if ($save) {
        $save->bind_param('si', $relative, $id);
        $save->execute();
        $save->close();
    }

    return $relative;
}

<?php
declare(strict_types=1);

function dubai_work_contract_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS dubai_work_contracts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            contract_token CHAR(32) NOT NULL,
            reference_number VARCHAR(40) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            client_name VARCHAR(255) DEFAULT NULL,
            nationality VARCHAR(120) DEFAULT NULL,
            passport_number VARCHAR(80) DEFAULT NULL,
            phone VARCHAR(80) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            residential_address TEXT,
            position VARCHAR(160) DEFAULT NULL,
            employer VARCHAR(255) DEFAULT NULL,
            salary_aed VARCHAR(80) DEFAULT NULL,
            contract_duration VARCHAR(80) DEFAULT NULL,
            agreement_date DATE DEFAULT NULL,
            signature_image MEDIUMTEXT,
            pdf_path VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            signed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dubai_work_token (contract_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function dubai_work_contract_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function dubai_work_contract_image(string $path): string
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

function dubai_work_contract_today(): string
{
    return (new DateTime('now', new DateTimeZone('Africa/Kigali')))->format('Y-m-d');
}

/**
 * @param array<string, mixed> $row
 */
function dubai_work_contract_field(string $key, array $row, bool $live, string $placeholder, string $type = 'text'): string
{
    $value = trim((string) ($row[$key] ?? ''));
    if ($key === 'agreement_date' && $value === '') {
        $value = dubai_work_contract_today();
    }
    if (!$live) {
        if ($key === 'agreement_date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTime::createFromFormat('Y-m-d', $value);
            $value = $day instanceof DateTime ? $day->format('j F Y') : $value;
        }

        return '<span class="dx-value">' . dubai_work_contract_h($value !== '' ? $value : '—') . '</span>';
    }
    $inputType = $type === 'date' ? 'date' : ($type === 'email' ? 'email' : 'text');

    return '<input class="dx-field" id="' . dubai_work_contract_h($key) . '" type="' . $inputType . '" value="' . dubai_work_contract_h($value) . '" placeholder="' . dubai_work_contract_h($placeholder) . '">';
}

function dubai_work_contract_css(): string
{
    return <<<'CSS'
.dx { color: #1c1917; font-family: "Segoe UI", "DejaVu Sans", sans-serif; font-size: 13.5px; line-height: 1.55; }
.dx-top { background: #1c1917; color: #f6e7c1; padding: 22px 26px 18px; }
.dx-kicker { letter-spacing: .18em; font-size: 11px; font-weight: 700; color: #e8c56b; }
.dx-top h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.15; color: #fff; }
.dx-place { margin-top: 6px; color: #f6e7c1; font-weight: 650; }
.dx-meta { width: 100%; border-collapse: collapse; background: #fffaf0; }
.dx-meta td { width: 50%; padding: 12px 18px; border-bottom: 1px solid #ead9b0; }
.dx-label { display: block; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #8a6a2f; font-weight: 700; }
.dx-body { padding: 8px 22px 22px; }
.dx-party { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 14px 0; }
.dx-party td { width: 50%; vertical-align: top; background: #faf7f2; border: 1px solid #eadfce; border-radius: 10px; padding: 12px 14px; }
.dx-party h2 { margin: 0 0 8px; font-size: 13px; letter-spacing: .06em; color: #8a6a2f; }
.dx h3 { margin: 16px 0 6px; font-size: 15px; color: #1c1917; }
.dx p { margin: 0 0 8px; }
.dx-grid { width: 100%; border-collapse: collapse; margin: 8px 0 4px; }
.dx-grid td { width: 50%; padding: 4px 8px 4px 0; vertical-align: top; }
.dx-pack { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 10px 0; }
.dx-pack td { width: 33.33%; background: #1c1917; color: #fff; border-radius: 10px; padding: 12px; vertical-align: top; }
.dx-pack strong { display: block; color: #e8c56b; font-size: 18px; margin-top: 2px; }
.dx-pack span { color: #f6e7c1; font-size: 12px; }
.dx-sign { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin-top: 8px; }
.dx-sign td { width: 50%; vertical-align: top; border: 1px solid #eadfce; border-top: 4px solid #c9a227; padding: 12px; }
.dx-sign h2 { margin: 0 0 8px; font-size: 13px; }
img.dx-sig { height: 64px; max-width: 180px; }
img.dx-stamp { height: 92px; max-width: 120px; margin-left: 8px; }
canvas.dx-pad { display: block; width: 100%; height: 110px; border: 1px dashed #c9a227; border-radius: 8px; background: #fffdf6; touch-action: none; }
.dx-note { margin-top: 14px; background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; border-radius: 8px; padding: 10px 12px; font-size: 12px; }
input.dx-field { width: 100%; box-sizing: border-box; margin-top: 3px; border: 1px solid #d6d3d1; border-radius: 7px; background: #fff; padding: 7px 8px; font: inherit; color: #1c1917; }
.dx-value { display: inline-block; min-width: 80px; border-bottom: 1px solid #d6d3d1; font-weight: 650; }
CSS;
}

/**
 * @param array<string, mixed> $row
 */
function dubai_work_contract_html(array $row, bool $live): string
{
    $sig = dubai_work_contract_image(dirname(__DIR__) . '/admin/signature-manager.png');
    $stamp = dubai_work_contract_image(dirname(__DIR__) . '/admin/employer-signature.png');
    $companySign = '';
    if ($sig !== '') {
        $companySign .= '<img class="dx-sig" alt="Company signature" src="' . $sig . '">';
    }
    if ($stamp !== '') {
        $companySign .= '<img class="dx-stamp" alt="Company stamp" src="' . $stamp . '">';
    }
    $clientSign = '';
    $drawn = (string) ($row['signature_image'] ?? '');
    if (str_starts_with($drawn, 'data:image/png;base64,')) {
        $clientSign = '<img class="dx-sig" alt="Client signature" src="' . dubai_work_contract_h($drawn) . '">';
    }
    if ($live) {
        $clientSign .= '<canvas class="dx-pad" id="clientPad"></canvas>';
    } elseif ($clientSign === '') {
        $clientSign = '<span class="dx-value">—</span>';
    }
    $ref = trim((string) ($row['reference_number'] ?? ''));
    $f = static function (string $key, string $placeholder, string $type = 'text') use ($row, $live): string {
        return dubai_work_contract_field($key, $row, $live, $placeholder, $type);
    };

    $html = '<article class="dx">';
    $html .= '<header class="dx-top"><div class="dx-kicker">PARROT CANADA VISA CONSULTANT CO. LTD</div>';
    $html .= '<h1>Work Abroad Service Agreement</h1><div class="dx-place">Dubai, United Arab Emirates</div></header>';
    $html .= '<table class="dx-meta"><tr><td><span class="dx-label">Contract number</span>' . dubai_work_contract_h($ref !== '' ? $ref : '—') . '</td>';
    $html .= '<td><span class="dx-label">Date</span>' . $f('agreement_date', 'Date', 'date') . '</td></tr></table>';
    $html .= '<div class="dx-body">';
    $html .= '<table class="dx-party"><tr>';
    $html .= '<td><h2>Party A — Company</h2><p><strong>PARROT CANADA VISA CONSULTANT CO. LTD</strong><br>Dr. Jean Pierre Twajamahoro<br>Owner and Managing Director<br>jobs@visaconsultantcanada.ca<br>WhatsApp +1 (431) 302-0226</p></td>';
    $html .= '<td><h2>Party B — Client</h2>';
    $html .= '<span class="dx-label">Full name</span>' . $f('client_name', 'Full name') ;
    $html .= '<span class="dx-label">Nationality</span>' . $f('nationality', 'Nationality');
    $html .= '<span class="dx-label">Passport number</span>' . $f('passport_number', 'Passport number');
    $html .= '<span class="dx-label">Phone</span>' . $f('phone', 'Phone');
    $html .= '<span class="dx-label">Email</span>' . $f('email', 'Email', 'email');
    $html .= '<span class="dx-label">Residential address</span>' . $f('residential_address', 'Residential address');
    $html .= '</td></tr></table>';

    $articles = [
        '1 — Purpose' => 'The Company will coordinate recruitment, employment documentation, visa application assistance and travel arrangements for potential employment in Dubai through authorized partners. Employment and visa approval depend on the employer and UAE authorities.',
        '6 — Company responsibilities' => 'The Company shall provide accurate job information, coordinate with authorized partners, assist with documentation, give reasonable updates, verify employment and visa documents, arrange the included flight after approval and lawful payment, and apply the refund terms. No visa or employment outcome is guaranteed.',
        '7 — Client responsibilities' => 'The Client shall provide a valid passport, genuine documents, updated CV and photograph; attend required interviews and lawful medical examinations; disclose accurate information; cooperate with processing; and follow UAE immigration and employment laws.',
        '8 — Visa and travel' => 'Processing times depend on the employer and UAE authorities. The Client must not travel for employment before obtaining and verifying the appropriate authorization and entry documents.',
        '9 — No guarantee' => 'Visa decisions are made by UAE authorities. The Company cannot guarantee visa approval, job placement or processing time. The refund provision applies in case of official visa refusal.',
        '10 — Disputes and law' => 'The parties will first seek an amicable resolution. Unresolved disputes shall be handled by competent authorities or courts under applicable law. Nothing in this agreement limits mandatory worker, immigration or consumer rights.',
        '11 — Acceptance' => 'The parties acknowledge the stated USD 850 package including airfare, the USD 425 initial installment, its full refund upon official visa refusal, and the USD 425 balance after approval where legally permissible.',
    ];
    $html .= '<h3>Article 1 — Purpose</h3><p>' . $articles['1 — Purpose'] . '</p>';
    $html .= '<h3>Article 2 — Employment</h3>';
    $html .= '<p>Possible roles include General Helper, Warehouse Helper, Cleaner, Factory Worker, Packing Worker and Construction Helper, subject to availability.</p>';
    $html .= '<table class="dx-grid"><tr><td><span class="dx-label">Selected position</span>' . $f('position', 'Selected position') . '</td>';
    $html .= '<td><span class="dx-label">Employer</span>' . $f('employer', 'Employer') . '</td></tr>';
    $html .= '<tr><td><span class="dx-label">Monthly salary (AED)</span>' . $f('salary_aed', 'Monthly salary') . '</td>';
    $html .= '<td><span class="dx-label">Contract duration</span>' . $f('contract_duration', 'Contract duration') . '</td></tr></table>';
    $html .= '<p>Working hours, accommodation, meals, insurance and benefits must be confirmed in the official employment offer.</p>';
    $html .= '<h3>Article 3 — Total package</h3>';
    $html .= '<p>The agreed package is USD 850, including recruitment coordination, employment documentation assistance, UAE visa processing coordination, a one-way economy air ticket to Dubai, pre-departure guidance and airport arrival coordination. Passport issuance and personal expenses are excluded. Any payment must comply with UAE laws restricting worker-paid recruitment and employment costs.</p>';
    $html .= '<table class="dx-pack"><tr>';
    $html .= '<td><span>Total package</span><strong>USD 850</strong></td>';
    $html .= '<td><span>On signing, refundable if the visa is refused</span><strong>USD 425</strong></td>';
    $html .= '<td><span>After visa approval, only where lawful</span><strong>USD 425</strong></td>';
    $html .= '</tr></table>';
    $html .= '<h3>Article 4 — Payment terms</h3><p>First installment: USD 425 (50%) upon signing, refundable in full if the UAE employment visa is officially refused. Second installment: USD 425 (50%) only after official visa approval and verification, before final ticket arrangements, and only where lawful. No second installment is due if the visa is refused.</p>';
    $html .= '<h3>Article 5 — Refund policy</h3><p>Upon official visa refusal, the Company will refund the full USD 425 first installment without administrative deductions within 14 business days after receiving and verifying the refusal. Voluntary withdrawal refunds will be determined under applicable law and documented lawful costs. False documents may suspend processing, without limiting statutory refund rights.</p>';
    foreach (['6 — Company responsibilities', '7 — Client responsibilities', '8 — Visa and travel', '9 — No guarantee', '10 — Disputes and law', '11 — Acceptance'] as $key) {
        $html .= '<h3>Article ' . dubai_work_contract_h($key) . '</h3><p>' . $articles[$key] . '</p>';
    }
    $html .= '<h3>Article 12 — Signatures</h3>';
    $html .= '<table class="dx-sign"><tr>';
    if ($live) {
        $signedLabel = '<span class="dx-value">The date entered above</span>';
        $clientLine = '<span class="dx-value">The name and passport entered above</span>';
    } else {
        $signedLabel = dubai_work_contract_field('agreement_date', $row, false, 'Date', 'date');
        $clientLine = dubai_work_contract_field('client_name', $row, false, 'Full name') . '<br>' . dubai_work_contract_field('passport_number', $row, false, 'Passport');
    }
    $html .= '<td><h2>For the Company</h2><p>Dr. Jean Pierre Twajamahoro<br>Owner and Managing Director</p>' . $companySign . '<p><span class="dx-label">Date</span>' . $signedLabel . '</p></td>';
    $html .= '<td><h2>For the Client</h2><p>' . $clientLine . '</p>' . $clientSign . '<p><span class="dx-label">Date</span>' . $signedLabel . '</p></td>';
    $html .= '</tr></table>';
    $html .= '<p class="dx-note">UAE rules generally prohibit charging workers recruitment and employment costs. Confirm legality with a qualified UAE professional before collecting any deposit.</p>';
    $html .= '</div></article>';

    return $html;
}

function dubai_work_contract_pdf(mysqli $conn, int $id): string
{
    $stmt = $conn->prepare('SELECT * FROM dubai_work_contracts WHERE id = ? LIMIT 1');
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
    $html = '<html><head><meta charset="UTF-8"><style>body{margin:0;}@page{margin:28px;}' . dubai_work_contract_css() . '</style></head><body>'
        . dubai_work_contract_html($row, false) . '</body></html>';
    $dir = dirname(__DIR__) . '/uploads/dubai-work-contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $relative = 'uploads/dubai-work-contracts/dubai-' . $id . '.pdf';
    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4');
    $dompdf->render();
    file_put_contents(dirname(__DIR__) . '/' . $relative, $dompdf->output());
    $save = $conn->prepare('UPDATE dubai_work_contracts SET pdf_path = ? WHERE id = ?');
    if ($save) {
        $save->bind_param('si', $relative, $id);
        $save->execute();
        $save->close();
    }

    return $relative;
}

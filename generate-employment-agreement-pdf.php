<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/employment_agreement_schema.php';
require_once __DIR__ . '/helpers/employment_agreement_document.php';

function generateEmploymentAgreementPDF(int $contractId): string
{
    global $conn;
    employment_agreement_ensure_schema($conn);

    $stmt = $conn->prepare('SELECT * FROM employment_agreements WHERE id = ? AND status = \'signed\' LIMIT 1');
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Signed agreement not found.');
    }
    if (!str_starts_with((string) ($row['signature_image'] ?? ''), 'data:image/')) {
        throw new RuntimeException('Client signature is missing.');
    }

    $managerPath = __DIR__ . '/admin/signature-manager.png';
    $stampPath = __DIR__ . '/admin/employer-signature.png';
    $managerSrc = is_file($managerPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($managerPath)) : '';
    $stampSrc = is_file($stampPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($stampPath)) : '';
    $managerImg = $managerSrc !== '' ? '<img src="' . $managerSrc . '" alt="Managing Director signature">' : '____________________';
    $stampImg = $stampSrc !== '' ? '<img class="stamp" src="' . $stampSrc . '" alt="Company stamp">' : '____________________';

    $signedOn = trim((string) ($row['agreement_date'] ?? ''));
    if ($signedOn === '' && !empty($row['signed_at'])) {
        $signedOn = substr((string) $row['signed_at'], 0, 10);
    }
    $signedOn = $signedOn !== '' ? ea_h($signedOn) : '____________________';
    $body = employment_agreement_document_html($row, 'pdf');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { size: A4; margin: 1.5cm 1.6cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 10.5pt; line-height: 1.4; color: #111; }
        .doc-company, h1, .doc-kicker { text-align: center; }
        .doc-company { font-weight: bold; margin: 0; }
        h1 { font-size: 14pt; margin: 6pt 0 2pt; }
        .doc-kicker { font-weight: bold; margin: 0 0 10pt; }
        h2 { font-size: 12pt; margin: 12pt 0 4pt; }
        h3 { font-size: 11pt; margin: 8pt 0 3pt; }
        p { margin: 0 0 6pt; text-align: justify; }
        ul { margin: 2pt 0 8pt 16pt; padding: 0; }
        li { margin: 0 0 2pt; }
        .field-line span { display: block; font-size: 9pt; color: #333; }
        .country-grid { margin: 6pt 0 8pt; }
        .country-item { display: inline-block; width: 31%; margin: 0 0 3pt; }
        .sig img { max-height: 58pt; max-width: 200pt; }
        .sig img.stamp { max-height: 80pt; max-width: 140pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
        td { vertical-align: top; width: 50%; padding: 6pt 10pt 6pt 0; }
        .sig-title { font-weight: bold; margin-bottom: 6pt; }
        .footer-note { text-align: center; margin-top: 16pt; font-size: 9pt; }
    </style></head><body>' . $body . '
    <h2>Article 16: Signatures</h2>
    <table>
    <tr>
        <td>
            <div class="sig-title">Client / job seeker</div>
            <p><strong>Full legal name:</strong> ' . ea_h((string) ($row['client_name'] ?? '')) . '<br>
            <strong>Passport number:</strong> ' . ea_h((string) ($row['passport_number'] ?? '')) . '<br>
            <strong>Selected destination country:</strong> ' . ea_h((string) ($row['destination_country'] ?? '')) . '</p>
            <p class="sig"><strong>Signature:</strong><br><img src="' . (string) $row['signature_image'] . '" alt="Client signature"></p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
        </td>
        <td>
            <div class="sig-title">Parrot Canada Visa Consultant Co. Ltd.</div>
            <p><strong>Authorized representative:</strong> Dr. Jean Pierre Twajamahoro<br>
            <strong>Position:</strong> Managing Director / Owner</p>
            <p class="sig"><strong>Signature:</strong><br>' . $managerImg . '</p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
            <p class="sig"><strong>Official company stamp:</strong><br>' . $stampImg . '</p>
        </td>
    </tr>
    </table>
    <p class="footer-note"><strong>Parrot Canada Visa Consultant Co. Ltd.</strong><br>
    Connecting Africa, Canada and the world<br>
    infos@visaconsultantcanada.com · jobs@visaconsultantcanada.ca · www.visaconsultantcanada.com · +1 (431) 302-0226</p>
    </body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Times New Roman');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = employment_agreement_upload_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filePath = $dir . '/employment_agreement_' . $contractId . '.pdf';
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

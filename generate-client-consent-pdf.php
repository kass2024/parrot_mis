<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';
require_once __DIR__ . '/helpers/client_consent_document.php';

function generateClientConsentPDF(int $contractId): string
{
    global $conn;
    client_consent_ensure_schema($conn);

    $stmt = $conn->prepare('SELECT * FROM client_consent_agreements WHERE id = ? AND status = \'signed\' LIMIT 1');
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
    $managerSrc = '';
    if (is_file($managerPath)) {
        $mime = mime_content_type($managerPath) ?: 'image/png';
        $managerSrc = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($managerPath));
    }

    $body = client_consent_document_html($row, 'pdf');
    $clientSig = (string) $row['signature_image'];
    $signedOn = trim((string) ($row['agreement_date'] ?? ''));
    if ($signedOn === '' && !empty($row['signed_at'])) {
        $signedOn = substr((string) $row['signed_at'], 0, 10);
    }
    $signedOn = $signedOn !== '' ? cc_h($signedOn) : '____________________';
    $clientName = cc_h((string) ($row['client_name'] ?? ''));
    $passport = cc_h((string) ($row['passport_number'] ?? ''));
    $blank = '____________________';
    $office = trim((string) ($row['office_location'] ?? ''));
    $officeName = trim((string) ($row['office_representative_name'] ?? ''));
    $officePosition = trim((string) ($row['office_representative_position'] ?? ''));
    $witnessName = trim((string) ($row['witness_name'] ?? ''));
    $witnessPassport = trim((string) ($row['witness_passport'] ?? ''));
    $managerImg = $managerSrc !== '' ? '<img src="' . $managerSrc . '" alt="Managing Director signature">' : $blank;

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { size: A4; margin: 1.6cm 1.7cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 11pt; line-height: 1.45; color: #111; }
        .doc-company, h1, .doc-kicker { text-align: center; }
        .doc-company { font-weight: bold; letter-spacing: .4px; margin: 0; }
        h1 { font-size: 15pt; margin: 6pt 0 2pt; }
        .doc-kicker { font-weight: bold; margin: 0 0 12pt; }
        h2 { font-size: 12pt; margin: 14pt 0 4pt; }
        p { margin: 0 0 7pt; text-align: justify; }
        ul { margin: 2pt 0 8pt 16pt; padding: 0; }
        li { margin: 0 0 2pt; }
        .field-line span { display: block; font-size: 9pt; color: #333; }
        .field-line { margin: 0 0 6pt; }
        .sig img { max-height: 58pt; max-width: 210pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
        td { vertical-align: top; width: 50%; padding: 6pt 10pt 6pt 0; }
        .sig-title { font-weight: bold; margin-bottom: 6pt; }
    </style></head><body>' . $body . '
    <h2>Signatures</h2>
    <table>
    <tr>
        <td>
            <div class="sig-title">For Parrot Canada Visa Consultant Co. Ltd.</div>
            <p><strong>Name:</strong> Dr. Jean Pierre Twajamahoro<br>
            <strong>Title:</strong> Managing Director/Owner</p>
            <p class="sig"><strong>Signature:</strong><br>' . $managerImg . '</p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
        </td>
        <td>
            <div class="sig-title">Authorized office representative</div>
            <p><strong>Office:</strong> ' . ($office !== '' ? cc_h($office) : 'Kigali Office / Musanze Office') . '<br>
            <strong>Full name:</strong> ' . ($officeName !== '' ? cc_h($officeName) : $blank) . '<br>
            <strong>Position:</strong> ' . ($officePosition !== '' ? cc_h($officePosition) : $blank) . '</p>
            <p class="sig"><strong>Signature:</strong><br>' . $blank . '</p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
        </td>
    </tr>
    <tr>
        <td>
            <div class="sig-title">Client</div>
            <p><strong>Full legal name:</strong> ' . $clientName . '<br>
            <strong>Passport/ID number:</strong> ' . $passport . '</p>
            <p class="sig"><strong>Signature:</strong><br><img src="' . $clientSig . '" alt="Client signature"></p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
        </td>
        <td>
            <div class="sig-title">Witness (optional)</div>
            <p><strong>Full name:</strong> ' . ($witnessName !== '' ? cc_h($witnessName) : $blank) . '<br>
            <strong>Passport/ID number:</strong> ' . ($witnessPassport !== '' ? cc_h($witnessPassport) : $blank) . '</p>
            <p class="sig"><strong>Signature:</strong><br>' . $blank . '</p>
            <p><strong>Date:</strong> ' . $signedOn . '</p>
        </td>
    </tr>
    </table>
    </body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Times New Roman');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = client_consent_upload_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filePath = $dir . '/client_consent_' . $contractId . '.pdf';
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

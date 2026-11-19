<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/contract_signature_image.php';
require_once __DIR__ . '/helpers/irs_agreement_document.php';

function generateIrsAgreementPDF(int $agreementId): string
{
    global $conn;

    $stmt = $conn->prepare(
        'SELECT a.*, s.client_name AS sig_name, s.signed_date, s.signature_image
         FROM irs_agreements a
         INNER JOIN irs_agreement_signatures s ON s.agreement_id = a.id
         WHERE a.id = ?
         ORDER BY s.id DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $agreementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Signed agreement not found.');
    }

    $signature = (string) ($row['signature_image'] ?? '');
    if ($signature === '' || !str_starts_with($signature, 'data:image')) {
        throw new RuntimeException('Client signature is missing.');
    }
    $png = contract_signature_to_display_png($signature);
    $clientSrc = $png !== null ? 'data:image/png;base64,' . base64_encode($png) : $signature;

    $embed = static function (string $path): string {
        if (!is_file($path)) {
            return '';
        }
        $mime = mime_content_type($path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    };

    $row['client_signature_src'] = $clientSrc;
    $row['parrot_signature_src'] = $embed(__DIR__ . '/admin/signature-manager.png');
    $row['company_stamp_src'] = $embed(__DIR__ . '/admin/employer-signature.png');

    $body = irs_agreement_document_html($row, true);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { size: A4; margin: 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111; }
        h2 { font-size: 13px; margin: 14px 0 6px; }
        p { margin: 0 0 8px; }
    </style></head><body>' . $body . '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', __DIR__);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $relative = 'uploads/irs_agreements/irs_agreement_' . $agreementId . '.pdf';
    $absolute = __DIR__ . '/' . $relative;
    $dir = dirname($absolute);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the agreement folder.');
    }
    file_put_contents($absolute, $dompdf->output());

    return $relative;
}

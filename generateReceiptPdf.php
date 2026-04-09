<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/brand_logo.php';
/**
 * =====================================================
 * GENERATE RECEIPT PDF (MATCHES RECORD LOGIC)
 * =====================================================
 * - Uses numeric application_id (INT)
 * - Joins via {source_table}.id
 * - application_id VARCHAR is display-only
 * - No silent fallbacks
 * =====================================================
 */
function generateReceiptPdf(string $thermalHtml, string $receiptNo): string
{
    global $conn;

    /* =================================================
       1. LOAD TEMPLATE
    ================================================= */
    $templatePath = __DIR__ . '/templates/receipt_pdf.html';
    if (!is_file($templatePath)) {
        throw new RuntimeException('PDF template not found');
    }

    $template = file_get_contents($templatePath);
    if ($template === false) {
        throw new RuntimeException('Unable to read PDF template');
    }

    /* =================================================
       2. EXTRACT NUMERIC APPLICATION ID (RECORD LOGIC)
    ================================================= */
    $plainText = strip_tags($thermalHtml);

    if (!preg_match('/Student\s*ID\s*:\s*(\d+)/i', $plainText, $m)) {
        throw new RuntimeException('Numeric application ID not found in receipt HTML');
    }

    $applicationId = (int) $m[1];
    if ($applicationId <= 0) {
        throw new RuntimeException('Invalid application ID');
    }

    /* =================================================
       3. DETERMINE SOURCE TABLE
    ================================================= */
    $stmt = $conn->prepare(
        "SELECT source_table
         FROM application_payments
         WHERE application_id = ?
         ORDER BY paid_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $stmt->bind_result($sourceTable);
    $stmt->fetch();
    $stmt->close();

    if (!$sourceTable) {
        throw new RuntimeException('Source table not found for application');
    }

    /* =================================================
       4. FETCH CUSTOMER NAME USING PRIMARY KEY (id)
    ================================================= */
    switch ($sourceTable) {

        case 'student_applications':
            $sql = "
                SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS customer_name
                FROM student_applications
                WHERE id = ?
                LIMIT 1
            ";
            break;

        case 'malta_applications':
            $sql = "
                SELECT TRIM(CONCAT_WS(' ', name, surname)) AS customer_name
                FROM malta_applications
                WHERE id = ?
                LIMIT 1
            ";
            break;

        case 'turkey_applications':
            $sql = "
                SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS customer_name
                FROM turkey_applications
                WHERE id = ?
                LIMIT 1
            ";
            break;

        default:
            throw new RuntimeException('Unsupported source table: ' . $sourceTable);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $stmt->bind_result($customerName);
    $stmt->fetch();
    $stmt->close();

    if (!$customerName) {
        throw new RuntimeException('Customer name not found — data integrity error');
    }

    /* =================================================
       5. FIND RECEIPT PAYMENT TIME
    ================================================= */
    $stmt = $conn->prepare(
        "SELECT MAX(paid_at)
         FROM application_payments
         WHERE application_id = ?
           AND status = 'PAID'"
    );
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $stmt->bind_result($receiptTime);
    $stmt->fetch();
    $stmt->close();

    if (!$receiptTime) {
        throw new RuntimeException('No payment time found');
    }

    /* =================================================
       6. FETCH PAID ITEMS
    ================================================= */
    $stmt = $conn->prepare(
        "SELECT
            fi.name AS item_name,
            fp.title AS package_title,
            ap.amount_paid,
            COALESCE(ap.currency, fp.currency, '') AS currency
         FROM application_payments ap
         JOIN fee_items fi ON fi.id = ap.fee_item_id
         JOIN fee_packages fp ON fp.id = fi.package_id
         WHERE ap.application_id = ?
           AND ap.status = 'PAID'
           AND ap.paid_at = ?"
    );
    $stmt->bind_param('is', $applicationId, $receiptTime);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$items) {
        throw new RuntimeException('No receipt items found');
    }

    /* =================================================
       7. BUILD ITEM HTML
    ================================================= */
    $itemsHtml = '';
    $total = 0.0;

    foreach ($items as $i => $item) {
        $amount = (float) $item['amount_paid'];
        $total += $amount;

        $itemsHtml .= '
        <tr>
            <td>' . ($i + 1) . '</td>
            <td>' . htmlspecialchars($item['item_name']) . '</td>
            <td class="amount">' .
                htmlspecialchars($item['currency']) . ' ' .
                number_format($amount, 2) .
            '</td>
        </tr>';
    }

    $packageTitle = $items[0]['package_title'] ?? 'N/A';
    $currency     = $items[0]['currency'] ?? '';

   

    /* =================================================
       9. INJECT TEMPLATE
    ================================================= */
    /* =================================================
   9. INJECT TEMPLATE
================================================= */
$html = str_replace([
    '{{RECEIPT_NO}}',
    '{{CUSTOMER_NAME}}',
    '{{APPLICATION_ID}}',
    '{{PACKAGE_TITLE}}',
    '{{DATE}}',
    '{{ITEM_ROWS}}',
    '{{CURRENCY}}',
    '{{TOTAL_AMOUNT}}',
    '{{PAYMENT_METHOD}}',
    '{{LOGO_PATH}}',
    '{{STAMP_PATH}}'
], [
    htmlspecialchars($receiptNo),
    htmlspecialchars($customerName),
    (string) $applicationId,
    htmlspecialchars($packageTitle),
    date('Y-m-d H:i', strtotime($receiptTime)),
    $itemsHtml,
    htmlspecialchars($currency),
    number_format($total, 2),
    'Cash',
    parrot_brand_logo_pdf_path(),
    '/assets/employer-signature.png'
], $template);

   /* =================================================
   10. RENDER PDF
================================================= */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('chroot', realpath(__DIR__));
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

    /* =================================================
       11. SAVE PDF
    ================================================= */
    $dir = __DIR__ . '/receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . $receiptNo . '.pdf';
    file_put_contents($file, $dompdf->output());

    return realpath($file) ?: $file;
}

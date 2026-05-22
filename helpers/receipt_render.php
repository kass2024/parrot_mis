<?php
declare(strict_types=1);

/**
 * Unified A4 receipt renderer for Parrot MIS.
 * Used by browser print (printReceipt.php), PDF generator (generateReceiptPdf.php),
 * and email body (sendReceiptEmail.php) so all three look identical.
 */

if (!function_exists('pcvc_receipt_image_data_uri')) {
    function pcvc_receipt_image_data_uri(string $absPath): string
    {
        if ($absPath === '' || !is_file($absPath)) {
            return '';
        }
        $bytes = @file_get_contents($absPath);
        if ($bytes === false) {
            return '';
        }
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'svg'  => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}

if (!function_exists('pcvc_receipt_brand_logo_path')) {
    function pcvc_receipt_brand_logo_path(): string
    {
        $root = dirname(__DIR__);
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'parrot-canada-logo.png',
            $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brand' . DIRECTORY_SEPARATOR . 'parrot-mark.png',
            $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brand' . DIRECTORY_SEPARATOR . 'parrot-mark.svg',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }
}

if (!function_exists('pcvc_receipt_stamp_path')) {
    function pcvc_receipt_stamp_path(): string
    {
        $root = dirname(__DIR__);
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'employer-signature.png',
            $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'employer-signature.png',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }
}

/**
 * Resolve applicant display name from application id + source table.
 */
function pcvc_receipt_customer_name(mysqli $conn, int $applicationId, string $sourceTable): string
{
    $allowedTables = ['student_applications', 'malta_applications', 'turkey_applications'];
    if (!in_array($sourceTable, $allowedTables, true)) {
        $sourceTable = 'student_applications';
    }

    switch ($sourceTable) {
        case 'malta_applications':
            $sql = "SELECT TRIM(CONCAT_WS(' ', name, surname)) AS n FROM malta_applications WHERE id = ? LIMIT 1";
            break;
        case 'turkey_applications':
            $sql = "SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS n FROM turkey_applications WHERE id = ? LIMIT 1";
            break;
        default:
            $sql = "SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS n FROM student_applications WHERE id = ? LIMIT 1";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 'Unknown';
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $name = trim((string) ($row['n'] ?? ''));
    return $name !== '' ? $name : 'Unknown';
}

/**
 * Load all receipt data from DB. Returns array on success, null on miss.
 */
function pcvc_load_receipt_data(mysqli $conn, string $receiptNo): ?array
{
    $receiptNo = trim($receiptNo);
    if ($receiptNo === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT receipt_no, application_id, source_table, package_id,
                total_amount, payment_method, created_at
         FROM payment_receipts
         WHERE TRIM(receipt_no) = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $receiptNo);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$receipt) {
        return null;
    }

    $applicationId = (int) $receipt['application_id'];
    $packageId     = (int) $receipt['package_id'];
    $sourceTable   = (string) $receipt['source_table'];

    $allowedTables = ['student_applications', 'malta_applications', 'turkey_applications'];
    if (!in_array($sourceTable, $allowedTables, true)) {
        $sourceTable = 'student_applications';
    }

    switch ($sourceTable) {
        case 'malta_applications':
            $sql = "SELECT TRIM(CONCAT_WS(' ', name, surname)) AS customer_name,
                           NULLIF(TRIM(email), '') AS customer_email
                    FROM malta_applications WHERE id = ? LIMIT 1";
            break;
        case 'turkey_applications':
            $sql = "SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS customer_name,
                           NULLIF(TRIM(email), '') AS customer_email
                    FROM turkey_applications WHERE id = ? LIMIT 1";
            break;
        default:
            $sql = "SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS customer_name,
                           NULLIF(TRIM(email), '') AS customer_email
                    FROM student_applications WHERE id = ? LIMIT 1";
    }

    $customerName  = 'Unknown';
    $customerEmail = '';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $customerName  = $row['customer_name'] !== '' ? $row['customer_name'] : 'Unknown';
            $customerEmail = (string) ($row['customer_email'] ?? '');
        }
        $stmt->close();
    }

    $packageTitle = 'N/A';
    $currency     = '';
    $stmt = $conn->prepare("SELECT title, currency FROM fee_packages WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $packageTitle = (string) ($row['title'] ?? 'N/A');
            $currency     = (string) ($row['currency'] ?? '');
        }
        $stmt->close();
    }

    $items = [];
    $stmt = $conn->prepare(
        "SELECT fi.name AS item_name, ap.amount_paid, ap.payment_comment
         FROM application_payments ap
         JOIN fee_items fi ON fi.id = ap.fee_item_id
         WHERE ap.application_id = ?
           AND ap.status = 'PAID'
           AND fi.package_id = ?
           AND ap.paid_at BETWEEN DATE_SUB(?, INTERVAL 30 SECOND)
                              AND DATE_ADD(?, INTERVAL 30 SECOND)
         ORDER BY ap.id ASC"
    );
    if ($stmt) {
        $stmt->bind_param('iiss', $applicationId, $packageId, $receipt['created_at'], $receipt['created_at']);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    if (!$items) {
        $items[] = [
            'item_name'       => $packageTitle,
            'amount_paid'     => $receipt['total_amount'],
            'payment_comment' => '',
        ];
    }

    return [
        'receipt_no'     => (string) $receipt['receipt_no'],
        'application_id' => $applicationId,
        'source_table'   => $sourceTable,
        'customer_name'  => $customerName,
        'customer_email' => $customerEmail,
        'package_title'  => $packageTitle,
        'currency'       => $currency,
        'payment_method' => (string) $receipt['payment_method'],
        'created_at'     => (string) $receipt['created_at'],
        'total_amount'   => (float) $receipt['total_amount'],
        'items'          => array_map(static function ($item) {
            return [
                'name'    => (string) ($item['item_name'] ?? ''),
                'amount'  => (float)  ($item['amount_paid'] ?? 0),
                'comment' => (string) ($item['payment_comment'] ?? ''),
            ];
        }, $items),
    ];
}

/**
 * Render the unified A4 receipt as a complete HTML document.
 *
 * Options:
 *   auto_print (bool)        — trigger window.print() on load (web only)
 *   include_print_button (bool) — show a print button (web only)
 *   company_name (string)
 *   company_sub  (string)
 *   support_email (string)
 *   support_phone (string)
 *   support_website (string)
 */
function pcvc_render_receipt_html(array $data, array $opts = []): string
{
    $opts += [
        'auto_print'           => false,
        'include_print_button' => false,
        'company_name'         => 'PARROT CANADA VISA CONSULTANT',
        'company_sub'          => 'Official Receipt of Payment',
        'support_email'        => 'admission@visaconsultantcanada.com',
        'support_phone'        => '',
        'support_website'      => 'visaconsultantcanada.com',
    ];

    $logoUri  = pcvc_receipt_image_data_uri(pcvc_receipt_brand_logo_path());
    $stampUri = pcvc_receipt_image_data_uri(pcvc_receipt_stamp_path());

    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $receiptNo     = $h($data['receipt_no'] ?? '');
    $customerName  = $h($data['customer_name'] ?? 'Customer');
    $applicationId = $h((string) ($data['application_id'] ?? ''));
    $packageTitle  = $h($data['package_title'] ?? 'N/A');
    $currency      = $h($data['currency'] ?? '');
    $paymentMethod = $h($data['payment_method'] ?? 'Cash');
    $createdAt     = $h(date('F j, Y · h:i A', strtotime((string) ($data['created_at'] ?? 'now'))));
    $totalAmount   = number_format((float) ($data['total_amount'] ?? 0), 2);

    $itemsHtml = '';
    $i = 1;
    foreach (($data['items'] ?? []) as $item) {
        $amount = number_format((float) ($item['amount'] ?? 0), 2);
        $name   = $h($item['name'] ?? '');
        $note   = trim((string) ($item['comment'] ?? ''));
        $noteHtml = $note !== ''
            ? '<div class="item-note">' . $h($note) . '</div>'
            : '';
        $itemsHtml .= '<tr>'
            . '<td class="col-no">' . $i++ . '</td>'
            . '<td class="col-desc"><div class="item-name">' . $name . '</div>' . $noteHtml . '</td>'
            . '<td class="col-amt">' . $currency . ' ' . $amount . '</td>'
            . '</tr>';
    }

    $companyName    = $h($opts['company_name']);
    $companySub     = $h($opts['company_sub']);
    $supportEmail   = $h($opts['support_email']);
    $supportPhone   = $h($opts['support_phone']);
    $supportWebsite = $h($opts['support_website']);

    $logoTag = $logoUri !== ''
        ? '<img src="' . $h($logoUri) . '" alt="Logo" class="logo">'
        : '<div class="logo logo-placeholder">PC</div>';

    $stampBlock = $stampUri !== ''
        ? '<div class="stamp-wrap">'
            . '<img src="' . $h($stampUri) . '" alt="Authorized Signature & Official Stamp" class="stamp-img">'
            . '<div class="stamp-caption">Authorized Signature &amp; Official Stamp</div>'
          . '</div>'
        : '<div class="stamp-wrap stamp-placeholder">Authorized Signature &amp; Official Stamp</div>';

    $printButton = $opts['include_print_button']
        ? '<div class="no-print print-bar">
             <button type="button" onclick="window.print()" class="btn-print">🖨 Print this receipt</button>
             <button type="button" onclick="window.close()" class="btn-close">Close</button>
           </div>'
        : '';

    $autoPrintScript = $opts['auto_print']
        ? '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 350); });
           window.onafterprint = function(){ setTimeout(function(){ window.close(); }, 400); };</script>'
        : '';

    $contactLine = $companyName . ' &nbsp;·&nbsp; ' . $supportEmail;
    if ($supportPhone !== '') {
        $contactLine .= ' &nbsp;·&nbsp; ' . $supportPhone;
    }
    $contactLine .= ' &nbsp;·&nbsp; ' . $supportWebsite;

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt {$receiptNo}</title>
<style>
@page { size: A4; margin: 14mm; }

* { box-sizing: border-box; }

html, body {
    margin: 0;
    padding: 0;
    background: #eef2f6;
    color: #1f2933;
    font-family: "DejaVu Sans", "Helvetica Neue", Arial, sans-serif;
    font-size: 12px;
}

.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto;
    padding: 14mm;
    background: #fff;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
    position: relative;
}

.no-print.print-bar {
    max-width: 210mm;
    margin: 16px auto 0;
    text-align: right;
    padding: 0 8mm;
}
.btn-print, .btn-close {
    border: 0;
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    margin-left: 8px;
}
.btn-print { background: #0b3c5d; color: #fff; }
.btn-close { background: #e5e7eb; color: #1f2933; }

.frame {
    border: 2px solid #0b3c5d;
    border-radius: 12px;
    padding: 14mm 12mm 30mm 12mm;
    position: relative;
    min-height: 250mm;
}

.watermark {
    position: absolute;
    top: 48%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-26deg);
    font-size: 130px;
    font-weight: 900;
    letter-spacing: 14px;
    color: rgba(11, 60, 93, 0.06);
    pointer-events: none;
    white-space: nowrap;
    z-index: 0;
}

.frame > * { position: relative; z-index: 1; }

.header {
    display: table;
    width: 100%;
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 10px;
}
.header .col-left, .header .col-right {
    display: table-cell;
    vertical-align: middle;
}
.header .col-right { text-align: right; }

.logo {
    width: 64px;
    height: 64px;
    object-fit: contain;
    vertical-align: middle;
    margin-right: 12px;
}
.logo-placeholder {
    display: inline-block;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #0b3c5d;
    color: #fff;
    text-align: center;
    line-height: 64px;
    font-weight: 800;
    font-size: 22px;
    margin-right: 12px;
    vertical-align: middle;
}

.company-block {
    display: inline-block;
    vertical-align: middle;
}
.company-name {
    font-size: 17px;
    font-weight: 800;
    color: #0b3c5d;
    letter-spacing: 0.5px;
    margin: 0;
}
.company-sub {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

.receipt-id {
    font-size: 11px;
    color: #6b7280;
    margin: 0 0 4px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
.receipt-no {
    font-size: 18px;
    font-weight: 800;
    color: #0b3c5d;
    margin: 0;
}

.title-bar {
    margin: 18px 0 14px;
    padding: 10px 14px;
    background: linear-gradient(135deg, #0b3c5d, #14629c);
    color: #fff;
    border-radius: 8px;
    text-align: center;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

.meta {
    width: 100%;
    margin-bottom: 14px;
    border-collapse: collapse;
}
.meta td {
    padding: 6px 8px;
    border-bottom: 1px dashed #e5e7eb;
    font-size: 12px;
    vertical-align: top;
}
.meta td.label {
    width: 28%;
    color: #6b7280;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10.5px;
    letter-spacing: 0.6px;
}
.meta td.value { color: #111827; font-weight: 600; }

.items {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
}
.items th {
    background: #0b3c5d;
    color: #fff;
    text-align: left;
    padding: 10px 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.items th.col-amt { text-align: right; }
.items td {
    padding: 10px 8px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
}
.items td.col-no { width: 36px; color: #6b7280; font-weight: 700; }
.items td.col-amt { text-align: right; white-space: nowrap; font-weight: 700; color: #0b3c5d; }
.item-name { font-weight: 600; }
.item-note { color: #6b7280; font-size: 10.5px; margin-top: 2px; }

.totals {
    width: 100%;
    margin-top: 10px;
}
.totals-inner {
    float: right;
    width: 55%;
    border-collapse: collapse;
}
.totals-inner td {
    padding: 8px 10px;
}
.totals-inner tr.grand td {
    background: #eef6fc;
    color: #0b3c5d;
    font-weight: 800;
    font-size: 14px;
    border-top: 2px solid #0b3c5d;
}
.totals-inner td.label { text-align: right; color: #6b7280; }
.totals-inner td.value { text-align: right; }

.payment-method {
    clear: both;
    margin-top: 18px;
    padding: 10px 12px;
    background: #f1f5f9;
    border-left: 4px solid #0b3c5d;
    border-radius: 4px;
    font-size: 12px;
}

.footer-grid {
    display: table;
    width: 100%;
    margin-top: 28px;
}
.footer-grid .col {
    display: table-cell;
    vertical-align: bottom;
    width: 50%;
}

.signed-by .label {
    font-size: 10.5px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 28px;
}
.signed-by .line {
    border-top: 1px solid #1f2933;
    width: 78%;
    padding-top: 4px;
    font-size: 11px;
}

.stamp-wrap {
    text-align: right;
    padding-right: 4px;
}
.stamp-img {
    max-width: 170px;
    max-height: 110px;
    object-fit: contain;
    opacity: 0.95;
}
.stamp-caption {
    font-size: 10px;
    color: #6b7280;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.stamp-placeholder {
    display: inline-block;
    border: 1.5px dashed #9ca3af;
    color: #9ca3af;
    padding: 30px 26px;
    font-size: 10.5px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.note {
    margin-top: 22px;
    padding: 10px 12px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    font-size: 10.5px;
    color: #475569;
    line-height: 1.5;
}

.contact-strip {
    margin-top: 14px;
    text-align: center;
    color: #6b7280;
    font-size: 10.5px;
}
.contact-strip strong { color: #0b3c5d; }

@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .page {
        margin: 0;
        box-shadow: none;
        padding: 0;
        width: auto;
        min-height: auto;
    }
    .frame { border-radius: 10px; }
}
</style>
</head>
<body>

{$printButton}

<div class="page">
    <div class="frame">
        <div class="watermark">PAID</div>

        <div class="header">
            <div class="col-left">
                {$logoTag}
                <div class="company-block">
                    <div class="company-name">{$companyName}</div>
                    <div class="company-sub">{$companySub}</div>
                </div>
            </div>
            <div class="col-right">
                <div class="receipt-id">Receipt No.</div>
                <div class="receipt-no">{$receiptNo}</div>
            </div>
        </div>

        <div class="title-bar">Official Payment Receipt</div>

        <table class="meta">
            <tr>
                <td class="label">Received From</td>
                <td class="value">{$customerName}</td>
                <td class="label">Application ID</td>
                <td class="value">#{$applicationId}</td>
            </tr>
            <tr>
                <td class="label">Package</td>
                <td class="value">{$packageTitle}</td>
                <td class="label">Date Issued</td>
                <td class="value">{$createdAt}</td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th class="col-no">#</th>
                    <th>Description</th>
                    <th class="col-amt">Amount</th>
                </tr>
            </thead>
            <tbody>
                {$itemsHtml}
            </tbody>
        </table>

        <div class="totals">
            <table class="totals-inner">
                <tr class="grand">
                    <td class="label">TOTAL PAID</td>
                    <td class="value">{$currency} {$totalAmount}</td>
                </tr>
            </table>
        </div>

        <div class="payment-method">
            <strong>Payment Method:</strong> {$paymentMethod}
        </div>

        <div class="footer-grid">
            <div class="col signed-by">
                <div class="label">Received By</div>
                <div class="line">Parrot Canada Visa Consultant — Finance Office</div>
            </div>
            <div class="col">
                {$stampBlock}
            </div>
        </div>

        <div class="note">
            This receipt confirms the payment received for the package indicated above.
            Please retain this document for your records. For any questions regarding this
            payment, contact our finance office quoting the Receipt No. shown.
        </div>

        <div class="contact-strip">{$contactLine}</div>
    </div>
</div>

{$autoPrintScript}
</body>
</html>
HTML;
}

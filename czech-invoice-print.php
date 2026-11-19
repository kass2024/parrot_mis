<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_invoice.php';

if (empty($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

cz_invoice_ensure_schema($conn);
$invoice = cz_invoice_load($conn, (string) ($_GET['no'] ?? ''));
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}
$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $h((string) $invoice['invoice_no']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; padding: 24px; background: #fff; color: #000; font-family: "Times New Roman", Times, serif; font-size: 12pt; }
.sheet { max-width: 720px; margin: 0 auto; }
h1 { font-size: 14pt; text-align: center; margin: 0 0 4px; }
.sub { text-align: center; margin: 0 0 16px; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
td, th { border: 1px solid #000; padding: 6px 8px; text-align: left; }
.meta td { border: 0; padding: 2px 0; }
</style>
</head>
<body>
<div class="sheet">
  <h1>PARROT CANADA VISA CONSULTANT CO.LTD</h1>
  <p class="sub">Czech Republic work permit package — official receipt<br><?= $h((string) $invoice['invoice_no']) ?></p>
  <table class="meta">
    <tr><td>Job seeker</td><td><?= $h((string) ($invoice['client_name'] ?: '—')) ?></td></tr>
    <tr><td>Agreement</td><td><?= $h((string) ($invoice['reference_number'] ?: '—')) ?></td></tr>
    <tr><td>Passport</td><td><?= $h((string) ($invoice['passport_number'] ?: '—')) ?></td></tr>
    <tr><td>Method</td><td><?= $h((string) $invoice['payment_method']) ?><?= $invoice['comment'] ? ' — ' . $h((string) $invoice['comment']) : '' ?></td></tr>
    <tr><td>Date</td><td><?= $h((string) $invoice['created_at']) ?></td></tr>
  </table>
  <table>
    <tr><th>Installment</th><th>Amount</th></tr>
    <?php foreach ($invoice['lines'] as $line): ?>
      <tr><td><?= $h((string) $line['item_name']) ?></td><td>EUR <?= number_format((float) $line['amount'], 2) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Total received</th><th>EUR <?= number_format((float) $invoice['total_amount'], 2) ?></th></tr>
  </table>
  <p>GISOZI, KIGALI RWANDA · infos@visaconsultantcanada.com</p>
</div>
</body>
</html>

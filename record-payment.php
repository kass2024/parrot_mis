<?php
declare(strict_types=1);

/* =====================================================
   0. BOOTSTRAP (NO OUTPUT EVER)
===================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/company_branding.php';
require_once __DIR__ . '/generateReceiptPdf.php';
require_once __DIR__ . '/helpers/custom_fee_package.php';

header('Content-Type: application/json; charset=utf-8');

/* =====================================================
   1. READ & DECODE JSON INPUT
===================================================== */
$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body']);
    exit;
}

$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

/* =====================================================
   2. SANITIZE INPUT
===================================================== */
$applicationId = (int) ($data['student_id'] ?? 0);
$sourceTable   = (string) ($data['table'] ?? '');
$packageId     = (int) ($data['package_id'] ?? 0);
$method        = trim((string) ($data['payment_method'] ?? ''));
$comment       = trim((string) ($data['comment'] ?? ''));
$items         = $data['items'] ?? [];

$isCustomPackage = !empty($data['custom_package']);
$customTitle     = trim((string) ($data['custom_title'] ?? ''));
$customItemName  = trim((string) ($data['custom_item_name'] ?? ''));
$customCurrency  = strtoupper(trim((string) ($data['custom_currency'] ?? '')));
$customAmount    = round((float) ($data['custom_amount'] ?? 0), 2);

/* =====================================================
   3. VALIDATION
===================================================== */
$allowedTables = [
    'student_applications',
    'malta_applications',
    'turkey_applications'
];

$allowedCurrencies = ['USD', 'CAD', 'EUR', 'GBP', 'GHS', 'RWF'];

if ($applicationId <= 0 || $method === '' || !in_array($sourceTable, $allowedTables, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing required fields']);
    exit;
}

if ($isCustomPackage) {
    if (
        $customTitle === '' ||
        $customAmount <= 0 ||
        !in_array($customCurrency, $allowedCurrencies, true) ||
        !is_array($items) ||
        empty($items)
    ) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter package name, currency, price, and payment amount']);
        exit;
    }

    $customPayTotal = 0.0;
    foreach ($items as $amount) {
        $customPayTotal += round((float) $amount, 2);
    }

    if ($customPayTotal <= 0 || $customPayTotal > $customAmount) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero and not exceed the proposed price']);
        exit;
    }
} elseif (
    $packageId <= 0 ||
    !is_array($items) ||
    empty($items)
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing required fields']);
    exit;
}

/* =====================================================
   4. START TRANSACTION
===================================================== */
$conn->begin_transaction();

try {

    $customItemLabel = null;

    if ($isCustomPackage) {
        $created = pcvc_create_custom_fee_package(
            $conn,
            $customTitle,
            $customItemName !== '' ? $customItemName : $customTitle,
            $customCurrency,
            $customAmount
        );
        $packageId = $created['package_id'];
        $customItemLabel = $created['item_name'];
        $items = [$created['fee_item_id'] => $customPayTotal];
    }

    /* =================================================
       5. ENSURE PACKAGE ASSIGNMENT
    ================================================= */
    $stmt = $conn->prepare(
        "SELECT id FROM application_packages
         WHERE application_id = ? AND source_table = ? AND package_id = ? LIMIT 1"
    );
    $stmt->bind_param('isi', $applicationId, $sourceTable, $packageId);
    $stmt->execute();
    $assigned = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assigned) {
        $stmt = $conn->prepare(
            "INSERT INTO application_packages
             (application_id, source_table, package_id, assigned_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->bind_param('isi', $applicationId, $sourceTable, $packageId);
        $stmt->execute();
        $stmt->close();
    }

    /* =================================================
       6. PROCESS ITEM PAYMENTS
    ================================================= */
    $totalRecorded = 0.0;
    $receiptItems  = [];

    foreach ($items as $feeItemId => $amount) {

        $feeItemId = (int) $feeItemId;
        $amount    = round((float) $amount, 2);

        if ($feeItemId <= 0 || $amount <= 0) {
            throw new RuntimeException('Invalid item payment data');
        }

        $stmt = $conn->prepare(
            "SELECT amount FROM fee_items WHERE id = ? AND package_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $feeItemId, $packageId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new RuntimeException('Fee item not found');
        }

        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount_paid),0)
             FROM application_payments
             WHERE application_id = ? AND source_table = ? AND fee_item_id = ? AND status = 'PAID'"
        );
        $stmt->bind_param('isi', $applicationId, $sourceTable, $feeItemId);
        $stmt->execute();
        $stmt->bind_result($alreadyPaid);
        $stmt->fetch();
        $stmt->close();

        if (($alreadyPaid + $amount) > (float) $item['amount']) {
            throw new RuntimeException('Overpayment detected');
        }

        $stmt = $conn->prepare(
            "INSERT INTO application_payments
             (application_id, source_table, fee_item_id, amount_paid,
              payment_method, payment_comment, status, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, 'PAID', NOW())"
        );
        $stmt->bind_param('isidss', $applicationId, $sourceTable, $feeItemId, $amount, $method, $comment);
        $stmt->execute();
        $stmt->close();

        $totalRecorded += $amount;

        $itemLabel = $customItemLabel ?? null;
        if ($itemLabel === null) {
            $stmt = $conn->prepare('SELECT name FROM fee_items WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $feeItemId);
            $stmt->execute();
            $nameRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $itemLabel = $nameRow['name'] ?? ('Item ' . $feeItemId);
        } else {
            $customItemLabel = null;
        }

        $receiptItems[] = [
            'label'  => (string) $itemLabel,
            'amount' => $amount
        ];
    }

    /* =================================================
       7. PACKAGE COMPLETION CHECK
    ================================================= */
    $stmt = $conn->prepare(
        "SELECT SUM(fi.amount) AS expected, COALESCE(SUM(p.amount_paid),0) AS paid
         FROM fee_items fi
         LEFT JOIN application_payments p
           ON p.fee_item_id = fi.id
          AND p.application_id = ?
          AND p.status = 'PAID'
         WHERE fi.package_id = ?"
    );
    $stmt->bind_param('ii', $applicationId, $packageId);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((float)$totals['paid'] >= (float)$totals['expected']) {
        $stmt = $conn->prepare("UPDATE {$sourceTable} SET app_paid = 1 WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
    }

    /* =================================================
       8. RECEIPT RECORD
    ================================================= */
    $receiptNo = 'RCT-' . date('Ymd-His') . '-' . random_int(100, 999);

    $receiptHtml = generateReceiptHtml([
        'receipt_no' => $receiptNo,
        'student_id' => $applicationId,
        'items'      => $receiptItems,
        'total'      => $totalRecorded,
        'method'     => $method
    ]);

    $stmt = $conn->prepare(
        "INSERT INTO payment_receipts
         (receipt_no, application_id, source_table, package_id,
          total_amount, payment_method, receipt_html)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sisidss', $receiptNo, $applicationId, $sourceTable, $packageId, $totalRecorded, $method, $receiptHtml);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    /* =================================================
       9. SEND RESPONSE IMMEDIATELY
    ================================================= */
    echo json_encode([
        'success'     => true,
        'message'     => 'Payment recorded successfully',
        'receipt_no'  => $receiptNo,
        'total_paid'  => number_format($totalRecorded, 2, '.', ''),
        'items_count' => count($items)
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    /* =================================================
       10. ASYNC BACKGROUND TASKS
    ================================================= */
    generateReceiptPdf($receiptHtml, $receiptNo);

    $emailUrl = pcvc_public_base_url() . '/sendReceiptEmail.php';
    $payload = http_build_query([
        'receipt_no' => $receiptNo,
        'secret'     => 'RCP_9fA8kKx_2026_SECURE'
    ]);

    $ch = curl_init($emailUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    curl_close($ch);

} catch (Throwable $e) {

    $conn->rollback();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Payment failed',
        'error'   => $e->getMessage()
    ]);
    exit;
}

/* =====================================================
   RECEIPT HTML GENERATOR (UNCHANGED)
===================================================== */
function generateReceiptHtml(array $data): string
{
    ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt</title>
<style>
@page { size: 80mm auto; margin: 0; }
body { width: 80mm; margin: 0; padding: 5mm; font-family: monospace; font-size: 12px; }
.center { text-align: center; }
.line { border-top: 1px dashed #000; margin: 6px 0; }
table { width: 100%; border-collapse: collapse; }
td { padding: 2px 0; }
.right { text-align: right; }
</style>
</head>
<body>
<div class="center"><strong>SCHOOL NAME</strong><br>OFFICIAL PAYMENT RECEIPT</div>
<div class="line"></div>
Receipt: <?= htmlspecialchars($data['receipt_no']) ?><br>
Student ID: <?= htmlspecialchars((string)$data['student_id']) ?><br>
Date: <?= date('Y-m-d H:i') ?><br>
<div class="line"></div>
<table>
<?php foreach ($data['items'] as $row): ?>
<tr><td><?= htmlspecialchars($row['label']) ?></td><td class="right"><?= number_format($row['amount'], 2) ?></td></tr>
<?php endforeach; ?>
</table>
<div class="line"></div>
<table><tr><td><strong>TOTAL</strong></td><td class="right"><strong><?= number_format($data['total'], 2) ?></strong></td></tr></table>
<div class="line"></div>
Payment: <?= htmlspecialchars($data['method']) ?><br>
<div class="center">Thank you<br>Keep this receipt</div>
</body>
</html>
<?php
    return ob_get_clean();
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/brand_logo.php';
class ReceiptGenerator
{
    private mysqli $conn;
    private string $receiptNo;
    private array $receipt = [];
    private string $studentName = 'Unknown Student';
    private string $packageTitle = 'N/A';
    private string $currency = '';
    private array $items = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }
public function generate(string $receiptNo): void
{
    // Auto-generate a 6-digit receipt number if not provided
    if ($receiptNo === '') {
        $receiptNo = $this->generateSixDigitReceiptNo();
    }

    $this->receiptNo = $receiptNo;

    $this->fetchReceipt();
    $this->fetchStudentInfo();
    $this->fetchPackageInfo();
    $this->fetchPaidItems();
    $this->render();
}

    private function fetchReceipt(): void
    {
        $stmt = $this->conn->prepare("
            SELECT receipt_no, application_id, package_id,
                   total_amount, payment_method, created_at
            FROM payment_receipts
            WHERE receipt_no = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $this->receiptNo);
        $stmt->execute();
        $this->receipt = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if (!$this->receipt) {
            $this->sendError(404, 'Receipt not found');
        }
    }

    private function fetchStudentInfo(): void
    {
        $stmt = $this->conn->prepare("
            SELECT first_name, last_name FROM (
                SELECT id, first_name, last_name FROM student_applications
                UNION ALL
                SELECT id, name AS first_name, surname AS last_name FROM malta_applications
                UNION ALL
                SELECT id, first_name, last_name FROM turkey_applications
            ) x
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $this->receipt['application_id']);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($student) {
            $this->studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
        }
    }

    private function fetchPackageInfo(): void
    {
        $stmt = $this->conn->prepare("
            SELECT title, currency
            FROM fee_packages
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $this->receipt['package_id']);
        $stmt->execute();
        $package = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($package) {
            $this->packageTitle = $package['title'] ?? 'N/A';
            $this->currency = $package['currency'] ?? '';
        }
    }

    private function fetchPaidItems(): void
    {
        $stmt = $this->conn->prepare("
            SELECT fi.name, ap.amount_paid
            FROM application_payments ap
            JOIN fee_items fi ON fi.id = ap.fee_item_id
            WHERE ap.application_id = ?
              AND ap.status = 'PAID'
              AND ap.paid_at >= (
                    SELECT created_at
                    FROM payment_receipts
                    WHERE receipt_no = ?
                    LIMIT 1
              )
        ");
        $stmt->bind_param('is', $this->receipt['application_id'], $this->receiptNo);
        $stmt->execute();
        $this->items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
private function generateSixDigitReceiptNo(): string
{
    $maxAttempts = 10;
    $attempt = 0;

    do {
        // Strict 6-digit numeric receipt number
        $receiptNo = (string) random_int(100000, 999999);

        $stmt = $this->conn->prepare(
            'SELECT receipt_no FROM payment_receipts WHERE receipt_no = ? LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare receipt check statement');
        }

        $stmt->bind_param('s', $receiptNo);
        $stmt->execute();
        $stmt->store_result();

        $exists = $stmt->num_rows > 0;
        $stmt->close();

        $attempt++;

        if ($attempt >= $maxAttempts) {
            throw new RuntimeException('Unable to generate unique receipt number');
        }

    } while ($exists);

    return $receiptNo;
}

    private function render(): void
    {
        $receiptNo     = htmlspecialchars($this->receiptNo);
        $studentName   = htmlspecialchars($this->studentName);
        $packageTitle  = htmlspecialchars($this->packageTitle);
        $currency      = htmlspecialchars($this->currency);
        $date          = date('Y-m-d H:i', strtotime($this->receipt['created_at']));
        $paymentMethod = htmlspecialchars($this->receipt['payment_method']);
        $totalAmount   = number_format((float)$this->receipt['total_amount'], 2);

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt <?= $receiptNo ?></title>

<style>
@page { size: 80mm auto; margin: 3mm; }

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: "Courier New", monospace;
    font-size: 10px;
    line-height: 1.35;
    color: #000;
}

/* SAFE PRINT AREA */
.receipt {
    width: 68mm;
    margin: 0 auto;
    padding: 2mm 1mm;
}

/* HEADER */
.header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 3mm;
    margin-bottom: 3mm;
    text-align: center;
}

.logo {
    width: 24px;
}

.company .name {
    font-weight: bold;
    font-size: 11px;
    text-transform: uppercase;
}

.company .sub {
    font-size: 10px;
}

/* TITLE */
.title {
    text-align: center;
    font-weight: bold;
    font-size: 12px;
    margin: 3mm 0;
    padding: 2mm 0;
    border-top: 1px dashed #000;
    border-bottom: 1px dashed #000;
}

/* META */
.meta { margin: 3mm 0; }
.meta div { margin-bottom: 1mm; }

/* ITEMS */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 2mm;
}

th {
    text-align: left;
    border-bottom: 1px dashed #000;
    padding-bottom: 1mm;
}

td {
    padding: 1mm 0;
}

.item-no { width: 6mm; }
.item-desc { width: 40mm; }
.amount { text-align: right; white-space: nowrap; }

/* TOTAL */
.total {
    border-top: 1px dashed #000;
    margin-top: 2mm;
    padding-top: 1mm;
    font-weight: bold;
    text-align: right;
}

/* FOOTER */
.footer {
    text-align: center;
    margin-top: 4mm;
    font-size: 9px;
}

.signature {
    margin-top: 3mm;
}

.signature img {
    height: 26px;
}
</style>
</head>

<body onload="window.print()">

<div class="receipt">

    <div class="header">
        <img src="<?= htmlspecialchars(parrot_brand_logo_href(__DIR__)) ?>" class="logo" alt="Parrot Canada Visa Consultant">
        <div class="company">
            <div class="name">PARROT CANADA</div>
            <div class="sub">VISA CONSULTANT</div>
        </div>
    </div>

    <div class="title">OFFICIAL PAYMENT RECEIPT</div>

    <div class="meta">
        <div><strong>Receipt No:</strong> <?= $receiptNo ?></div>
        <div><strong>Customer:</strong> <?= $studentName ?></div>
        <div><strong>Package:</strong> <?= $packageTitle ?></div>
        <div><strong>Date:</strong> <?= $date ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="item-no">#</th>
                <th class="item-desc">Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($this->items as $item): ?>
            <tr>
                <td class="item-no"><?= $i++ ?>.</td>
                <td class="item-desc"><?= htmlspecialchars($item['name']) ?></td>
                <td class="amount"><?= $currency . ' ' . number_format((float)$item['amount_paid'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total">
        TOTAL: <?= $currency . ' ' . $totalAmount ?>
    </div>

    <div class="meta">
        <div><strong>Payment Method:</strong> <?= $paymentMethod ?></div>
    </div>

    <div class="footer">
        <div>Thank you for your partnership!</div>
        <div>Please keep this receipt for your records</div>

        <!-- <div class="signature">
            <img src="admin/employer-signature.png" alt="Signature">
            <div>Authorized Signature</div>
        </div> -->
    </div>

</div>

<script>
window.onafterprint = () => setTimeout(() => window.close(), 300);
</script>

</body>
</html>
<?php
        echo ob_get_clean();
    }

    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        exit($message);
    }
}

$receiptNo = $_GET['receipt_no'] ?? '';
(new ReceiptGenerator($conn))->generate($receiptNo);

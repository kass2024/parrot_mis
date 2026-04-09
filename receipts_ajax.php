<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
/* =====================================================
   SAFETY (AJAX CONTEXT)
===================================================== */
error_reporting(0);
ini_set('display_errors', '0');

/* =====================================================
   INPUT
===================================================== */
$customer = trim($_GET['customer'] ?? '');
$range    = $_GET['range'] ?? '';

$fromDate = '';
$toDate   = '';

switch ($range) {
    case 'today':
        $fromDate = $toDate = date('Y-m-d');
        break;

    case 'week':
        $fromDate = date('Y-m-d', strtotime('monday this week'));
        $toDate   = date('Y-m-d');
        break;

    case 'month':
        $fromDate = date('Y-m-01');
        $toDate   = date('Y-m-d');
        break;
}

/* =====================================================
   BASE QUERY
===================================================== */
$sql = "
SELECT
    pr.receipt_no,
    pr.application_id,
    pr.total_amount,
    pr.payment_method,
    pr.created_at,
    pr.status,
    fp.currency
FROM payment_receipts pr
LEFT JOIN fee_packages fp ON fp.id = pr.package_id
WHERE 1=1
";

$params = [];
$types  = '';

/* =====================================================
   CUSTOMER SEARCH
===================================================== */
if ($customer !== '') {
    $sql .= "
    AND pr.application_id IN (
        SELECT id FROM student_applications
        WHERE CONCAT(first_name,' ',last_name) LIKE ?
        UNION
        SELECT id FROM malta_applications
        WHERE CONCAT(name,' ',surname) LIKE ?
        UNION
        SELECT id FROM turkey_applications
        WHERE CONCAT(first_name,' ',last_name) LIKE ?
    )";

    $like   = "%{$customer}%";
    $params = [$like, $like, $like];
    $types  = 'sss';
}

/* =====================================================
   DATE FILTERS
===================================================== */
if ($fromDate !== '') {
    $sql .= " AND DATE(pr.created_at) >= ?";
    $params[] = $fromDate;
    $types   .= 's';
}

if ($toDate !== '') {
    $sql .= " AND DATE(pr.created_at) <= ?";
    $params[] = $toDate;
    $types   .= 's';
}

$sql .= " ORDER BY pr.created_at DESC";

/* =====================================================
   EXECUTE QUERY
===================================================== */
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =====================================================
   HELPERS
===================================================== */
function getCustomerName(mysqli $conn, int $appId): string
{
    $sql = "
    SELECT first_name, last_name FROM (
        SELECT id, first_name, last_name FROM student_applications
        UNION ALL
        SELECT id, name, surname FROM malta_applications
        UNION ALL
        SELECT id, first_name, last_name FROM turkey_applications
    ) x
    WHERE id = ?
    LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $appId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row
        ? trim($row['first_name'] . ' ' . $row['last_name'])
        : 'Unknown';
}

function getReceiptItems(mysqli $conn, int $appId, string $date): array
{
    $stmt = $conn->prepare("
        SELECT
            fi.name,
            ap.amount_paid
        FROM application_payments ap
        JOIN fee_items fi ON fi.id = ap.fee_item_id
        WHERE ap.application_id = ?
          AND ap.status = 'PAID'
          AND ap.paid_at >= ?
    ");

    $stmt->bind_param('is', $appId, $date);
    $stmt->execute();

    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $items;
}

/* =====================================================
   EMPTY STATE
===================================================== */
if (empty($receipts)) {
    echo '<div class="state">No receipts found</div>';
    exit;
}

/* =====================================================
   OUTPUT (HTML FRAGMENT)
===================================================== */
foreach ($receipts as $r):

    $appId  = (int)$r['application_id'];
    $items  = getReceiptItems($conn, $appId, $r['created_at']);
    $name   = getCustomerName($conn, $appId);
    $cancel = ($r['status'] === 'CANCELED');

    $total  = (float)$r['total_amount'];
    $curr   = htmlspecialchars((string)$r['currency']);
?>
<div class="receipt-card <?= $cancel ? 'canceled' : '' ?>">

    <div class="header">
        <div>
            <strong>Parrot Canada</strong><br>
            Visa Consultant<br>
            POS Receipt<br>
            <?= date('Y-m-d H:i:s', strtotime($r['created_at'])) ?>
        </div>

        <div class="actions">
            <a class="btn-print" target="_blank"
               href="printReceipt.php?receipt_no=<?= urlencode($r['receipt_no']) ?>">
                Print
            </a>

            <a class="btn-edit"
               href="edit_receipt.php?receipt_no=<?= urlencode($r['receipt_no']) ?>">
                Edit
            </a>

            <form method="post" action="cancel_receipt.php" style="display:inline">
                <input type="hidden" name="receipt_no"
                       value="<?= htmlspecialchars($r['receipt_no']) ?>">
                <button class="btn-cancel" <?= $cancel ? 'disabled' : '' ?>>
                    Cancel
                </button>
            </form>
        </div>
    </div>

    <hr>

    <div><strong>Customer:</strong> <?= htmlspecialchars($name) ?></div>
    <div><strong>Payment:</strong> <?= htmlspecialchars($r['payment_method']) ?></div>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th style="text-align:right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['name']) ?></td>
                <td style="text-align:right">
                    <?= number_format((float)$it['amount_paid'], 2) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total">
        Grand Total: <?= number_format($total, 2) ?> <?= $curr ?><br>
        Amount Paid: <?= number_format($total, 2) ?> <?= $curr ?><br>
        Balance: <?= number_format(0, 2) ?> <?= $curr ?>
    </div>

</div>
<?php endforeach; ?>

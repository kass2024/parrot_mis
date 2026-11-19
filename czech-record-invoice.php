<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_work_contract.php';
require_once __DIR__ . '/helpers/czech_invoice.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

czech_work_contract_ensure_schema($conn);
cz_invoice_ensure_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['cz_invoice_flash'] ?? null;
unset($_SESSION['cz_invoice_flash']);

$error = '';
$contractId = (int) ($_GET['contract_id'] ?? $_POST['contract_id'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        $amounts = [];
        $posted = $_POST['amount'] ?? [];
        if (is_array($posted)) {
            foreach ($posted as $code => $value) {
                $amounts[(string) $code] = (float) $value;
            }
        }
        try {
            $result = cz_invoice_record(
                $conn,
                $contractId,
                $amounts,
                trim((string) ($_POST['payment_method'] ?? '')),
                trim((string) ($_POST['comment'] ?? ''))
            );
            $_SESSION['cz_invoice_flash'] = $result;
            header('Location: czech-record-invoice.php?contract_id=' . $contractId);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('czech invoice record: ' . $e->getMessage());
            $error = 'The invoice could not be saved. Try again.';
        }
    }
}

$contract = null;
$summary = null;
$history = [];
if ($contractId > 0) {
    $st = $conn->prepare(
        'SELECT id, reference_number, client_name, email, phone, passport_number, status
         FROM czech_work_contracts WHERE id = ? LIMIT 1'
    );
    if ($st) {
        $st->bind_param('i', $contractId);
        $st->execute();
        $contract = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
    if ($contract) {
        $summary = cz_invoice_summary($conn, $contractId);
        $history = cz_invoice_history($conn, $contractId);
    }
}

$list = [];
if (!$contract) {
    $where = '1=1';
    $params = [];
    $types = '';
    if ($search !== '') {
        $where = '(client_name LIKE ? OR email LIKE ? OR reference_number LIKE ? OR passport_number LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
        $types = 'ssss';
    }
    $sql = 'SELECT id, reference_number, client_name, email, status, created_at
            FROM czech_work_contracts WHERE ' . $where . ' ORDER BY id DESC LIMIT 40';
    $lst = $conn->prepare($sql);
    if ($lst) {
        if ($params) {
            $lst->bind_param($types, ...$params);
        }
        $lst->execute();
        $list = $lst->get_result()->fetch_all(MYSQLI_ASSOC);
        $lst->close();
    }
}
$paidMap = cz_invoice_paid_totals_map($conn);
$programTotal = cz_invoice_program_total();

function cz_inv_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cz_inv_money(float $amount): string
{
    return 'EUR ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Invoice · Czech Republic Work Permit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f1ea; color:#000; font-family: "Times New Roman", Times, serif; }
        .page-head { background:#000; color:#fff; padding:1.25rem 0 1.4rem; margin-bottom:1.25rem; }
        .panel { background:#fff; border:1px solid #000; }
        .candidate-strip { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; padding:1rem 1.15rem; border-bottom:1px solid #ddd; }
        .total-banner { margin:1rem 1.15rem 0; border:1px solid #000; text-align:center; padding:.85rem 1rem; font-weight:700; }
        .balance-row { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; padding:1rem 1.15rem 0; }
        .balance-box { border:1px solid #000; padding:.75rem .9rem; }
        .balance-box span { display:block; font-size:.75rem; }
        .fee-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem; padding:1rem 1.15rem 1.15rem; }
        .fee-card { border:1px solid #000; padding:.9rem .95rem 1rem; display:flex; flex-direction:column; min-width:0; }
        .fee-card.paid { background:#f3fbf6; }
        .fee-card .amount { font-size:1.35rem; font-weight:800; margin:.2rem 0 .45rem; }
        .pay-row { display:flex; gap:.4rem; margin-top:auto; }
        .action-bar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; padding:0 1.15rem 1.15rem; }
        @media (max-width: 991.98px) { .fee-grid, .balance-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <h1 class="h4 mb-1">Record Invoice</h1>
                <p class="mb-0 small">Czech Republic work-permit package: three installments, <?= cz_inv_money($programTotal) ?> in total.</p>
            </div>
            <a class="btn btn-light btn-sm" href="admin-czech-contracts.php">Agreements</a>
        </div>
    </div>
</div>
<div class="container-fluid px-3 px-md-4 pb-5">
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= cz_inv_h($error) ?></div><?php endif; ?>
    <?php if (is_array($flash) && !empty($flash['invoice_no'])): ?>
        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>Invoice <strong><?= cz_inv_h((string) $flash['invoice_no']) ?></strong> recorded for <?= cz_inv_money((float) ($flash['total'] ?? 0)) ?>.</div>
            <a class="btn btn-dark btn-sm" target="_blank" href="czech-invoice-print.php?no=<?= urlencode((string) $flash['invoice_no']) ?>">Print receipt</a>
        </div>
    <?php endif; ?>
    <?php if ($contractId > 0 && !$contract): ?>
        <div class="alert alert-warning">That agreement was not found.</div>
    <?php endif; ?>
    <?php if (!$contract): ?>
        <form class="row g-2 mb-3" method="get">
            <div class="col-12 col-md-8">
                <input type="search" name="q" class="form-control" placeholder="Search name, email, reference, or passport" value="<?= cz_inv_h($search) ?>">
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-dark w-100">Find client</button>
            </div>
        </form>
        <div class="panel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Job seeker</th><th>Paid</th><th>Remaining</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$list): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No Czech agreements found.</td></tr>
                    <?php else: foreach ($list as $row):
                        $paid = $paidMap[(int) $row['id']] ?? 0.0;
                        $left = max(0, $programTotal - $paid);
                        $name = trim((string) ($row['client_name'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= cz_inv_h($name !== '' ? $name : 'Not signed yet') ?></div>
                                <div class="small text-muted"><?= cz_inv_h((string) ($row['email'] ?? '')) ?></div>
                                <code class="small"><?= cz_inv_h((string) ($row['reference_number'] ?? '')) ?></code>
                            </td>
                            <td><?= cz_inv_money($paid) ?></td>
                            <td><?= cz_inv_money($left) ?></td>
                            <td class="text-end"><a class="btn btn-dark btn-sm" href="czech-record-invoice.php?contract_id=<?= (int) $row['id'] ?>">Record</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else:
        $name = trim((string) ($contract['client_name'] ?? ''));
    ?>
        <form method="post" id="invoiceForm" class="panel">
            <input type="hidden" name="csrf_token" value="<?= cz_inv_h((string) $_SESSION['csrf_token']) ?>">
            <input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>">
            <div class="candidate-strip">
                <div>
                    <div class="fw-bold fs-5"><?= cz_inv_h($name !== '' ? $name : 'Not signed yet') ?></div>
                    <div class="small"><?= cz_inv_h((string) ($contract['email'] ?? '')) ?> · <?= cz_inv_h((string) ($contract['reference_number'] ?? '')) ?></div>
                </div>
                <a class="small" href="czech-record-invoice.php">Choose another client</a>
            </div>
            <div class="total-banner">Two Thousand Five Hundred Euros (<?= cz_inv_money($programTotal) ?>)</div>
            <div class="balance-row">
                <div class="balance-box"><span>Expected</span><strong><?= cz_inv_money($summary['program_total']) ?></strong></div>
                <div class="balance-box"><span>Paid so far</span><strong><?= cz_inv_money($summary['paid_total']) ?></strong></div>
                <div class="balance-box"><span>Remaining</span><strong><?= cz_inv_money($summary['remaining_total']) ?></strong></div>
            </div>
            <div class="fee-grid">
                <?php foreach ($summary['items'] as $item): ?>
                    <article class="fee-card <?= cz_inv_h($item['status']) ?>">
                        <div class="fw-bold"><?= cz_inv_h($item['name']) ?></div>
                        <div class="amount"><?= cz_inv_money((float) $item['amount']) ?></div>
                        <p class="small"><?= cz_inv_h($item['when']) ?></p>
                        <div class="small mb-2">Paid <?= cz_inv_money((float) $item['paid']) ?> · Left <?= cz_inv_money((float) $item['remaining']) ?></div>
                        <div class="pay-row">
                            <input type="number" class="form-control amount-input" name="amount[<?= cz_inv_h($item['code']) ?>]" min="0" max="<?= cz_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>" step="0.01" placeholder="0.00" data-max="<?= cz_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>" <?= $item['remaining'] <= 0 ? 'disabled' : '' ?>>
                            <?php if ($item['remaining'] > 0): ?>
                                <button type="button" class="btn btn-outline-dark btn-sm pay-rest" data-amount="<?= cz_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>">Pay rest</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="action-bar">
                <div><strong id="entryTotal">EUR 0.00</strong></div>
                <div style="min-width:12rem;">
                    <label class="form-label small mb-1" for="payment_method">Payment method</label>
                    <select class="form-select" id="payment_method" name="payment_method" required>
                        <option value="Cash">Cash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Mobile Money">Mobile Money</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width:14rem;">
                    <label class="form-label small mb-1" for="comment">Comment / reference</label>
                    <input class="form-control" id="comment" name="comment" maxlength="500">
                </div>
                <button type="submit" class="btn btn-dark px-4" <?= $summary['remaining_total'] <= 0 ? 'disabled' : '' ?>>Record invoice</button>
            </div>
        </form>
        <div class="panel mt-3">
            <div class="p-3 pb-0"><h2 class="h6 mb-0">Invoices for this client</h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Receipt</th><th>Items</th><th>Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No invoice has been recorded yet.</td></tr>
                    <?php else: foreach ($history as $inv): ?>
                        <tr>
                            <td><code><?= cz_inv_h((string) $inv['invoice_no']) ?></code></td>
                            <td class="small"><?php foreach ($inv['lines'] as $line): ?><div><?= cz_inv_h((string) $line['item_name']) ?> · <?= cz_inv_money((float) $line['amount']) ?></div><?php endforeach; ?></td>
                            <td><?= cz_inv_money((float) $inv['total_amount']) ?></td>
                            <td><a class="btn btn-outline-dark btn-sm" target="_blank" href="czech-invoice-print.php?no=<?= urlencode((string) $inv['invoice_no']) ?>">Print</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    const form = document.getElementById('invoiceForm');
    if (!form) return;
    const totalEl = document.getElementById('entryTotal');
    const inputs = Array.from(form.querySelectorAll('.amount-input'));
    let entrySum = 0;
    function refresh() {
        entrySum = 0;
        inputs.forEach(function (input) {
            if (input.disabled) return;
            const max = Number(input.dataset.max || 0);
            let value = Number(input.value || 0);
            if (value < 0) value = 0;
            if (value > max) { value = max; input.value = max.toFixed(2); }
            entrySum += value;
        });
        totalEl.textContent = 'EUR ' + entrySum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    inputs.forEach(function (input) { input.addEventListener('input', refresh); });
    form.querySelectorAll('.pay-rest').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = btn.parentElement.querySelector('.amount-input');
            if (!input || input.disabled) return;
            input.value = Number(btn.dataset.amount || 0).toFixed(2);
            refresh();
        });
    });
    form.addEventListener('submit', function (event) {
        refresh();
        if (entrySum <= 0) {
            event.preventDefault();
            alert('Enter an amount on at least one installment.');
        }
    });
})();
</script>
</body>
</html>

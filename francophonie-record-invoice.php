<?php
/**
 * Record a Francophone Mobility invoice.
 * Fee items are the three installments from the service agreement.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_invoice.php';

fm_ensure_schema($conn);
fm_invoice_ensure_schema($conn);

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_require_francophonie_mobility($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['fm_invoice_flash'] ?? null;
unset($_SESSION['fm_invoice_flash']);

$error = '';
$applicationId = (int) ($_GET['application_id'] ?? $_POST['application_id'] ?? 0);
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
            $result = fm_invoice_record(
                $conn,
                $applicationId,
                $amounts,
                trim((string) ($_POST['payment_method'] ?? '')),
                trim((string) ($_POST['comment'] ?? '')),
                !empty($_POST['email_client'])
            );
            $_SESSION['fm_invoice_flash'] = $result;
            header('Location: francophonie-record-invoice.php?application_id=' . $applicationId);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('fm invoice record: ' . $e->getMessage());
            $error = 'The invoice could not be saved. Try again.';
        }
    }
}

$applicant = null;
$summary = null;
$history = [];
if ($applicationId > 0) {
    $st = $conn->prepare(
        'SELECT id, reference_id, first_name, last_name, email, phone_area_code, phone_number, profession, passport_number, status
         FROM francophonie_mobility_applications WHERE id = ? LIMIT 1'
    );
    if ($st) {
        $st->bind_param('i', $applicationId);
        $st->execute();
        $applicant = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
    if ($applicant) {
        $summary = fm_invoice_summary($conn, $applicationId);
        $history = fm_invoice_history($conn, $applicationId);
    }
}

$list = [];
if (!$applicant) {
    $where = '1=1';
    $params = [];
    $types = '';
    if ($search !== '') {
        $where = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR reference_id LIKE ? OR passport_number LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like];
        $types = 'sssss';
    }
    $sql = 'SELECT id, reference_id, first_name, last_name, email, profession, status, created_at
            FROM francophonie_mobility_applications
            WHERE ' . $where . ' ORDER BY id DESC LIMIT 40';
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
$paidMap = fm_invoice_paid_totals_map($conn);
$programTotal = fm_invoice_program_total();

function fm_inv_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fm_inv_money(float $amount): string
{
    return 'CAD ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Invoice · Francophonie Mobility</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --fm-green:#1e4d2b; --fm-blue:#3661B9; --fm-mint:#e8f6ee; }
        body { background:#f3f6f4; color:#1f2933; }
        .page-head {
            background:linear-gradient(135deg,var(--fm-green),var(--fm-blue));
            color:#fff; padding:1.25rem 0 1.4rem; margin-bottom:1.25rem;
        }
        .page-head p { max-width:42rem; }
        .panel {
            background:#fff; border:1px solid #e2e8f0; border-radius:16px;
            box-shadow:0 8px 24px rgba(15,23,42,.04);
        }
        .candidate-strip {
            display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;
            padding:1rem 1.15rem; border-bottom:1px solid #e8eee9;
        }
        .total-banner {
            margin:1rem 1.15rem 0; border-radius:12px; background:var(--fm-mint);
            border:1px solid #b7e0c4; color:var(--fm-green); text-align:center;
            padding:.85rem 1rem; font-weight:700;
        }
        .balance-row { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; padding:1rem 1.15rem 0; }
        .balance-box { background:#f8fafc; border-radius:12px; padding:.75rem .9rem; }
        .balance-box span { display:block; font-size:.75rem; color:#64748b; letter-spacing:.02em; }
        .balance-box strong { font-size:1.05rem; }
        .progress-wrap { padding:.75rem 1.15rem 0; }
        .progress { height:8px; background:#e8eee9; }
        .fee-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem; padding:1rem 1.15rem 1.15rem; }
        .fee-card {
            border:1px solid #d7e3da; border-radius:14px; padding:.9rem .95rem 1rem;
            background:#fff; display:flex; flex-direction:column; min-width:0;
        }
        .fee-card.partial { border-color:#f5c16c; background:#fffaf2; }
        .fee-card.paid { border-color:#8dcea6; background:#f3fbf6; }
        .fee-card .label { font-size:.78rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#64748b; }
        .fee-card .amount { font-size:1.45rem; font-weight:800; color:var(--fm-green); line-height:1.2; margin:.2rem 0 .45rem; }
        .fee-card .when { font-size:.82rem; color:#334155; margin-bottom:.55rem; }
        .fee-card ul { margin:0 0 .7rem; padding-left:1.05rem; font-size:.78rem; color:#475569; }
        .fee-card li { margin-bottom:.15rem; }
        .mini-stats { display:flex; justify-content:space-between; font-size:.78rem; margin-bottom:.45rem; }
        .pay-row { display:flex; gap:.4rem; margin-top:auto; }
        .pay-row input { font-weight:700; }
        .action-bar {
            display:flex; flex-wrap:wrap; gap:.75rem; align-items:end;
            padding:0 1.15rem 1.15rem;
        }
        .this-entry {
            min-width:11rem; background:#0f3d24; color:#fff; border-radius:12px;
            padding:.65rem .9rem;
        }
        .this-entry span { display:block; font-size:.72rem; opacity:.8; }
        .this-entry strong { font-size:1.2rem; }
        .history-table td, .history-table th { font-size:.88rem; }
        @media (max-width: 991.98px) {
            .fee-grid, .balance-row { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <h1 class="h4 mb-1"><i class="fas fa-file-invoice-dollar me-2"></i>Record Invoice</h1>
                <p class="mb-0 small opacity-75">Francophone Mobility fees come from the service agreement: three installments, <?= fm_inv_money($programTotal) ?> in total.</p>
            </div>
            <a class="btn btn-light btn-sm" href="francophonie-mobility-applications.php"><i class="fas fa-arrow-left me-1"></i>Applications</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-5">
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= fm_inv_h($error) ?></div>
    <?php endif; ?>
    <?php if (is_array($flash) && !empty($flash['invoice_no'])): ?>
        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                Invoice <strong><?= fm_inv_h((string) $flash['invoice_no']) ?></strong> recorded
                for <?= fm_inv_money((float) ($flash['total'] ?? 0)) ?>.
                <?php if (!empty($flash['emailed'])): ?>
                    A copy was emailed to the candidate.
                <?php elseif (!empty($flash['email_error'])): ?>
                    <?= fm_inv_h((string) $flash['email_error']) ?>
                <?php endif; ?>
            </div>
            <a class="btn btn-success btn-sm" target="_blank" href="francophonie-invoice-print.php?no=<?= urlencode((string) $flash['invoice_no']) ?>">
                <i class="fas fa-print me-1"></i>Print receipt
            </a>
        </div>
    <?php endif; ?>

    <?php if ($applicationId > 0 && !$applicant): ?>
        <div class="alert alert-warning">That application was not found.</div>
    <?php endif; ?>

    <?php if (!$applicant): ?>
        <form class="row g-2 mb-3" method="get">
            <div class="col-12 col-md-8">
                <input type="search" name="q" class="form-control" placeholder="Search name, email, reference, or passport" value="<?= fm_inv_h($search) ?>">
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Find candidate</button>
            </div>
        </form>
        <div class="panel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Candidate</th>
                            <th>Profession</th>
                            <th>Paid</th>
                            <th>Remaining</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$list): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No applications found.</td></tr>
                    <?php else: foreach ($list as $row):
                        $paid = $paidMap[(int) $row['id']] ?? 0.0;
                        $left = max(0, $programTotal - $paid);
                        $name = trim($row['first_name'] . ' ' . $row['last_name']);
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= fm_inv_h($name) ?></div>
                                <div class="small text-muted"><?= fm_inv_h((string) $row['email']) ?></div>
                                <code class="small"><?= fm_inv_h((string) $row['reference_id']) ?></code>
                            </td>
                            <td class="small"><?= fm_inv_h((string) $row['profession']) ?></td>
                            <td><?= fm_inv_money($paid) ?></td>
                            <td class="<?= $left <= 0 ? 'text-success fw-semibold' : '' ?>"><?= fm_inv_money($left) ?></td>
                            <td class="text-end">
                                <a class="btn btn-success btn-sm" href="francophonie-record-invoice.php?application_id=<?= (int) $row['id'] ?>">
                                    Record
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else:
        $name = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
        $phone = trim((string) ($applicant['phone_area_code'] ?? '') . ' ' . (string) ($applicant['phone_number'] ?? ''));
        $pct = $programTotal > 0 ? min(100, round(($summary['paid_total'] / $programTotal) * 100)) : 0;
    ?>
        <form method="post" id="invoiceForm" class="panel">
            <input type="hidden" name="csrf_token" value="<?= fm_inv_h((string) $_SESSION['csrf_token']) ?>">
            <input type="hidden" name="application_id" value="<?= (int) $applicant['id'] ?>">

            <div class="candidate-strip">
                <div>
                    <div class="fw-bold fs-5"><?= fm_inv_h($name) ?></div>
                    <div class="small text-muted">
                        <?= fm_inv_h((string) $applicant['email']) ?>
                        <?php if ($phone !== ''): ?> · <?= fm_inv_h($phone) ?><?php endif; ?>
                        <?php if (!empty($applicant['passport_number'])): ?> · Passport <?= fm_inv_h((string) $applicant['passport_number']) ?><?php endif; ?>
                    </div>
                    <code class="small"><?= fm_inv_h((string) $applicant['reference_id']) ?></code>
                </div>
                <div class="text-md-end">
                    <div class="small text-muted"><?= fm_inv_h((string) $applicant['profession']) ?></div>
                    <a class="small" href="francophonie-record-invoice.php">Choose another candidate</a>
                </div>
            </div>

            <div class="total-banner">Eight Thousand Canadian Dollars (<?= fm_inv_money($programTotal) ?>)</div>

            <div class="balance-row">
                <div class="balance-box"><span>Expected</span><strong><?= fm_inv_money($summary['program_total']) ?></strong></div>
                <div class="balance-box"><span>Paid so far</span><strong class="text-success"><?= fm_inv_money($summary['paid_total']) ?></strong></div>
                <div class="balance-box"><span>Remaining</span><strong class="text-danger"><?= fm_inv_money($summary['remaining_total']) ?></strong></div>
            </div>
            <div class="progress-wrap">
                <div class="progress" role="progressbar" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width:<?= (int) $pct ?>%;background:var(--fm-green);"></div>
                </div>
                <div class="small text-muted mt-1"><?= (int) $pct ?>% of the program fee is recorded.</div>
            </div>

            <div class="fee-grid">
                <?php foreach ($summary['items'] as $item): ?>
                    <article class="fee-card <?= fm_inv_h($item['status']) ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="label"><?= fm_inv_h($item['name']) ?></div>
                            <?php if ($item['status'] === 'paid'): ?>
                                <span class="badge text-bg-success">Paid</span>
                            <?php elseif ($item['status'] === 'partial'): ?>
                                <span class="badge text-bg-warning">Part paid</span>
                            <?php endif; ?>
                        </div>
                        <div class="amount"><?= fm_inv_money((float) $item['amount']) ?></div>
                        <p class="when"><?= fm_inv_h($item['when']) ?></p>
                        <?php if (!empty($item['note'])): ?>
                            <p class="when"><strong>Note:</strong> <?= fm_inv_h($item['note']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['includes'])): ?>
                        <ul>
                            <?php foreach ($item['includes'] as $line): ?>
                                <li><?= fm_inv_h($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <div class="mini-stats">
                            <span>Paid <?= fm_inv_money((float) $item['paid']) ?></span>
                            <span>Left <?= fm_inv_money((float) $item['remaining']) ?></span>
                        </div>
                        <div class="pay-row">
                            <input
                                type="number"
                                class="form-control amount-input"
                                name="amount[<?= fm_inv_h($item['code']) ?>]"
                                min="0"
                                max="<?= fm_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>"
                                step="0.01"
                                placeholder="0.00"
                                value=""
                                data-max="<?= fm_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>"
                                <?= $item['remaining'] <= 0 ? 'disabled' : '' ?>
                            >
                            <?php if ($item['remaining'] > 0): ?>
                                <button type="button" class="btn btn-outline-success btn-sm pay-rest" data-amount="<?= fm_inv_h(number_format((float) $item['remaining'], 2, '.', '')) ?>">Pay rest</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="action-bar">
                <div class="this-entry">
                    <span>This invoice</span>
                    <strong id="entryTotal">CAD 0.00</strong>
                </div>
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
                    <input class="form-control" id="comment" name="comment" maxlength="500" placeholder="Optional note or transfer reference">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="email_client" name="email_client" value="1" checked>
                    <label class="form-check-label small" for="email_client">Email receipt</label>
                </div>
                <button type="submit" class="btn btn-success px-4" id="saveBtn" <?= $summary['remaining_total'] <= 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-save me-1"></i>Record invoice
                </button>
            </div>
        </form>

        <div class="panel mt-3">
            <div class="p-3 pb-0"><h2 class="h6 mb-0">Invoices for this candidate</h2></div>
            <div class="table-responsive">
                <table class="table history-table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Receipt</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Recorded</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No invoice has been recorded yet.</td></tr>
                    <?php else: foreach ($history as $inv): ?>
                        <tr>
                            <td><code><?= fm_inv_h((string) $inv['invoice_no']) ?></code></td>
                            <td class="small">
                                <?php foreach ($inv['lines'] as $line): ?>
                                    <div><?= fm_inv_h((string) $line['item_name']) ?> · <?= fm_inv_money((float) $line['amount']) ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td class="fw-semibold"><?= fm_inv_money((float) $inv['total_amount']) ?></td>
                            <td class="small">
                                <?= fm_inv_h((string) $inv['payment_method']) ?>
                                <?php if (!empty($inv['comment'])): ?>
                                    <div class="text-muted"><?= fm_inv_h((string) $inv['comment']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= date('M j, Y', strtotime((string) $inv['created_at'])) ?>
                                <?php if (!empty($inv['recorded_by_name'])): ?>
                                    <div class="text-muted"><?= fm_inv_h((string) $inv['recorded_by_name']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($inv['emailed_at'])): ?>
                                    <div class="text-success">Emailed</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-outline-primary btn-sm" target="_blank" href="francophonie-invoice-print.php?no=<?= urlencode((string) $inv['invoice_no']) ?>">
                                    Print
                                </a>
                            </td>
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

    function money(n) {
        return 'CAD ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    let entrySum = 0;
    function refresh() {
        entrySum = 0;
        inputs.forEach(function (input) {
            if (input.disabled) return;
            const max = Number(input.dataset.max || 0);
            let value = Number(input.value || 0);
            if (value < 0) value = 0;
            if (value > max) {
                value = max;
                input.value = max.toFixed(2);
            }
            entrySum += value;
        });
        totalEl.textContent = money(entrySum);
    }
    inputs.forEach(function (input) {
        input.addEventListener('input', refresh);
    });
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

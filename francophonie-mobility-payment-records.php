<?php
/**
 * Francophonie Mobility payment records (Sultan fees).
 * Superadmin can edit/save. Manager portal can view via private link.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/fm_payment_records.php';

fm_ensure_payment_records_schema($conn);

$isPortal = fm_manager_portal_logged_in();
$isStaffFm = false;
$isSuper = false;

if (!empty($_SESSION['id']) || !empty($_SESSION['admin_id'])) {
    $isStaffFm = pcvc_can_access_francophonie_mobility($conn);
    $isSuper = pcvc_current_user_is_superadmin($conn);
}

if ($isPortal && fm_manager_portal_script_allowed()) {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
} elseif ($isSuper) {
    // ok
} else {
    if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
        header('Location: admin-login.php');
        exit;
    }
    pcvc_require_superadmin($conn);
}

$canEdit = $isSuper && (!empty($_SESSION['id']) || !empty($_SESSION['admin_id']));

$message = '';
$error = '';

if ($canEdit && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])) {
        $error = 'Session expired. Refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'save'));
        if ($action === 'delete') {
            $delId = (int) ($_POST['id'] ?? 0);
            if (fm_payment_record_delete($conn, $delId)) {
                $message = 'Record deleted.';
            } else {
                $error = 'Could not delete that record.';
            }
        } elseif ($action === 'add' || $action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $payload = [
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'full_name' => trim((string) ($_POST['full_name'] ?? '')),
                'job_position' => trim((string) ($_POST['job_position'] ?? '')),
                'total_fees' => (float) ($_POST['total_fees'] ?? 0),
                'upfront_fees_paid' => (float) ($_POST['upfront_fees_paid'] ?? 0),
                'upfront_fees_paid_2' => (float) ($_POST['upfront_fees_paid_2'] ?? 0),
                'paid_after_medical' => (float) ($_POST['paid_after_medical'] ?? 0),
                'balance_to_sultan' => $_POST['balance_to_sultan'] ?? '',
                'visa_status' => trim((string) ($_POST['visa_status'] ?? '')),
                'comment' => trim((string) ($_POST['comment'] ?? '')),
                'highlighted' => !empty($_POST['highlighted']) ? 1 : 0,
            ];
            if ($payload['full_name'] === '') {
                $error = 'Full name is required.';
            } elseif (fm_payment_record_save($conn, $action === 'add' ? 0 : $id, $payload)) {
                $message = $action === 'add' ? 'Student payment record added.' : 'Payment record saved.';
            } else {
                $error = 'Could not save the record.';
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$rows = fm_payment_records_list($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment records — Francophonie Mobility</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f3; color: #1e293b; }
        .page-head { background: linear-gradient(135deg, #1e4d2b, #3661B9); color: #fff; padding: 1.1rem 0; margin-bottom: 1rem; }
        .card-soft { border: 0; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); background: #fff; }
        .table-wrap { overflow-x: auto; }
        table.pay-table { min-width: 1100px; font-size: .82rem; }
        table.pay-table thead th {
            background: #0891b2; color: #fff; white-space: nowrap; vertical-align: middle;
            font-size: .72rem; position: sticky; top: 0;
        }
        tr.row-hi { background: #fef08a !important; }
        .form-control-sm, .form-select-sm { font-size: .78rem; }
        .hint { color: #64748b; font-size: .85rem; }
    </style>
</head>
<body>
<?= $isPortal ? fm_manager_portal_nav_html('payments') : '' ?>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <h1 class="h4 mb-1"><i class="fas fa-money-bill-wave me-2"></i>Payment records</h1>
        <p class="mb-0 opacity-75 small">
            Canada Francophonie Mobility — Sultan fees tracking (separate from Record invoice).
            <?= $canEdit ? 'Superadmin can edit and save.' : 'View only on the manager private link.' ?>
        </p>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-5">
    <?php if (!$isPortal): ?>
    <p class="mb-3"><a href="francophonie-mobility-applications.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a></p>
    <?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="card card-soft p-3 mb-3">
        <h2 class="h6 mb-2">Add student payment record</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-1"><label class="form-label small mb-0">S/N</label><input class="form-control form-control-sm" name="sort_order" type="number" value="<?= count($rows) + 1 ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Full names *</label><input class="form-control form-control-sm" name="full_name" required></div>
            <div class="col-md-2"><label class="form-label small mb-0">Job position</label><input class="form-control form-control-sm" name="job_position"></div>
            <div class="col-md-1"><label class="form-label small mb-0">Total</label><input class="form-control form-control-sm" name="total_fees" type="number" step="0.01" value="5000"></div>
            <div class="col-md-1"><label class="form-label small mb-0">Upfront</label><input class="form-control form-control-sm" name="upfront_fees_paid" type="number" step="0.01" value="0"></div>
            <div class="col-md-1"><label class="form-label small mb-0">Upfront 2</label><input class="form-control form-control-sm" name="upfront_fees_paid_2" type="number" step="0.01" value="0"></div>
            <div class="col-md-1"><label class="form-label small mb-0">After medical</label><input class="form-control form-control-sm" name="paid_after_medical" type="number" step="0.01" value="0"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Visa status</label><input class="form-control form-control-sm" name="visa_status"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Comment</label><input class="form-control form-control-sm" name="comment"></div>
            <div class="col-md-2 form-check mt-3 ms-2">
                <input class="form-check-input" type="checkbox" name="highlighted" value="1" id="hiNew">
                <label class="form-check-label small" for="hiNew">Highlight row</label>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-success w-100" type="submit"><i class="fas fa-plus me-1"></i> Add</button></div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card card-soft">
        <div class="table-wrap p-2">
            <table class="table table-bordered pay-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>FULL NAMES</th>
                        <th>JOB POSITION</th>
                        <th>TOTAL FEES TO BE PAID-SULTAN</th>
                        <th>UPFRONT FEES PAID-SULTAN</th>
                        <th>UPFRONT FEES PAID-SULTAN (2)</th>
                        <th>PAID FEES AFTER MEDICAL-SULTAN</th>
                        <th>BALANCE TO SULTAN</th>
                        <th>VISA STATUS</th>
                        <th>COMMENT</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $canEdit ? 11 : 10 ?>" class="text-muted p-3">No payment records yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <?php if ($canEdit): ?>
                    <tr class="<?= !empty($r['highlighted']) ? 'row-hi' : '' ?>">
                        <td colspan="11" class="p-2">
                            <form method="post" class="row g-1 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <div class="col-auto"><input class="form-control form-control-sm" name="sort_order" type="number" value="<?= (int) $r['sort_order'] ?>" style="width:4rem" title="S/N"></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" name="full_name" value="<?= htmlspecialchars((string) $r['full_name']) ?>" required title="Full name"></div>
                                <div class="col-md-1"><input class="form-control form-control-sm" name="job_position" value="<?= htmlspecialchars((string) $r['job_position']) ?>" title="Job"></div>
                                <div class="col-auto"><input class="form-control form-control-sm" name="total_fees" type="number" step="0.01" value="<?= htmlspecialchars((string) $r['total_fees']) ?>" style="width:5.5rem" title="Total"></div>
                                <div class="col-auto"><input class="form-control form-control-sm" name="upfront_fees_paid" type="number" step="0.01" value="<?= htmlspecialchars((string) $r['upfront_fees_paid']) ?>" style="width:5.5rem" title="Upfront"></div>
                                <div class="col-auto"><input class="form-control form-control-sm" name="upfront_fees_paid_2" type="number" step="0.01" value="<?= htmlspecialchars((string) $r['upfront_fees_paid_2']) ?>" style="width:5.5rem" title="Upfront 2"></div>
                                <div class="col-auto"><input class="form-control form-control-sm" name="paid_after_medical" type="number" step="0.01" value="<?= htmlspecialchars((string) $r['paid_after_medical']) ?>" style="width:5.5rem" title="After medical"></div>
                                <div class="col-auto"><input class="form-control form-control-sm" name="balance_to_sultan" type="number" step="0.01" value="<?= htmlspecialchars((string) $r['balance_to_sultan']) ?>" style="width:5.5rem" title="Balance"></div>
                                <div class="col-md-1"><input class="form-control form-control-sm" name="visa_status" value="<?= htmlspecialchars((string) $r['visa_status']) ?>" title="Visa status"></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" name="comment" value="<?= htmlspecialchars((string) ($r['comment'] ?? '')) ?>" title="Comment"></div>
                                <div class="col-auto">
                                    <label class="small me-1 mb-0"><input type="checkbox" name="highlighted" value="1" <?= !empty($r['highlighted']) ? 'checked' : '' ?>> Hi</label>
                                    <button class="btn btn-sm btn-primary" type="submit" title="Save"><i class="fas fa-save"></i></button>
                                </div>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment record?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger mt-1" type="submit" title="Delete"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr class="<?= !empty($r['highlighted']) ? 'row-hi' : '' ?>">
                        <td><?= (int) $r['sort_order'] ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['full_name']) ?></td>
                        <td><?= htmlspecialchars((string) $r['job_position']) ?></td>
                        <td><?= htmlspecialchars((string) $r['total_fees']) ?></td>
                        <td><?= htmlspecialchars((string) $r['upfront_fees_paid']) ?></td>
                        <td><?= htmlspecialchars((string) $r['upfront_fees_paid_2']) ?></td>
                        <td><?= htmlspecialchars((string) $r['paid_after_medical']) ?></td>
                        <td><?= htmlspecialchars((string) $r['balance_to_sultan']) ?></td>
                        <td><?= htmlspecialchars((string) $r['visa_status']) ?></td>
                        <td><?= htmlspecialchars((string) ($r['comment'] ?? '')) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="hint mt-2 mb-0">Balance is editable; saving recalculates from Total − Upfront − Upfront 2 − After medical when balance is left blank on new rows.</p>
</div>
</body>
</html>

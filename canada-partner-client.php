<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/canada_partner_monitor.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

canada_partner_ensure_schema($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
$today = (new DateTime('now', new DateTimeZone('America/Toronto')))->format('Y-m-d');
$error = '';
$notice = (string) ($_SESSION['canada_partner_notice'] ?? '');
unset($_SESSION['canada_partner_notice']);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$partnerId = (int) ($_GET['partner'] ?? $_POST['partner_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        try {
            if ($action === 'payment' || $action === 'commission') {
                $receipt = '';
                if ($action === 'payment') {
                    $receipt = canada_partner_store_file($_FILES['receipt'] ?? [], 'receipts');
                }
                canada_partner_add_money(
                    $conn,
                    $action === 'payment' ? 'canada_partner_payments' : 'canada_partner_commission_receipts',
                    $id,
                    (string) ($_POST['paid_on'] ?? ''),
                    (float) ($_POST['amount'] ?? 0),
                    (string) ($_POST['method'] ?? ''),
                    (string) ($_POST['note'] ?? ''),
                    $receipt,
                    $adminId
                );
                $_SESSION['canada_partner_notice'] = $action === 'payment' ? 'The client payment is recorded.' : 'The commission received is recorded.';
            } elseif ($action === 'remove_payment' || $action === 'remove_commission') {
                canada_partner_remove_money(
                    $conn,
                    $action === 'remove_payment' ? 'canada_partner_payments' : 'canada_partner_commission_receipts',
                    (int) ($_POST['row_id'] ?? 0),
                    $id
                );
                $_SESSION['canada_partner_notice'] = 'The entry is removed.';
            } else {
                $existing = $id > 0 ? canada_partner_client($conn, $id) : null;
                $document = $existing ? (string) ($existing['decision_document'] ?? '') : '';
                $uploaded = canada_partner_store_file($_FILES['decision_document'] ?? [], 'decisions');
                if ($uploaded !== '') {
                    $document = $uploaded;
                }
                $id = canada_partner_save_client($conn, $_POST, $id, $document);
                $_SESSION['canada_partner_notice'] = 'The client file is saved.';
            }
            header('Location: canada-partner-client.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$client = $id > 0 ? canada_partner_client($conn, $id) : null;
if ($id > 0 && $client === null) {
    http_response_code(404);
    exit('This client was not found.');
}
$partners = canada_partner_partners($conn);
$staff = canada_partner_staff($conn);
$payments = $client ? canada_partner_money_rows($conn, 'canada_partner_payments', $id) : [];
$commissions = $client ? canada_partner_money_rows($conn, 'canada_partner_commission_receipts', $id) : [];
$selectedPartner = (int) ($client['partner_id'] ?? $partnerId);
$value = static function (string $key, string $fallback = '') use ($client): string {
    if ($client && array_key_exists($key, $client)) {
        return $client[$key] === null ? '' : (string) $client[$key];
    }

    return (string) ($_POST[$key] ?? $fallback);
};
$statusValue = $value('progress_status', 'discussion');
$copy = '';
if ($client) {
    $copy = $client['client_code'] . "\n"
        . 'Partner service: ' . $client['service_name'] . "\n"
        . 'Client: ' . $client['full_name'] . "\n"
        . 'Progress: ' . $client['status_number'] . '. ' . $client['status_label'] . "\n"
        . 'Payment: ' . $client['payment_state'] . ' — ' . canada_partner_cad((float) $client['amount_paid']) . ' of ' . canada_partner_cad((float) $client['service_fee']) . "\n"
        . 'Commission outstanding: ' . canada_partner_cad((float) $client['commission_outstanding']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $client ? canada_partner_h((string) $client['client_code']) : 'New Canada client' ?></title>
<style><?= canada_partner_css() ?></style>
</head>
<body>
<div class="wrap">
  <p class="row-actions"><a class="btn ghost" href="canada-partners.php<?= $selectedPartner ? '?partner=' . $selectedPartner : '' ?>">Back to partners</a></p>
  <h1><?= $client ? canada_partner_h((string) $client['client_code']) : 'New client' ?></h1>
  <?php if ($client): ?><p class="muted"><?= canada_partner_h((string) $client['full_name']) ?> · <?= canada_partner_h((string) $client['service_name']) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?= canada_partner_h($error) ?></p><?php endif; ?>
  <?php if ($notice !== ''): ?><p class="ok"><?= canada_partner_h($notice) ?></p><?php endif; ?>
  <?php if ($copy !== ''): ?>
    <p class="row-actions"><button type="button" class="ghost" id="copy-status">Copy client status</button></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="card">
      <strong class="section-title">Client progress</strong>
      <div class="steps">
        <?php foreach (canada_partner_statuses() as $key => $meta): ?>
          <label class="step <?= $statusValue === $key ? 'current' : '' ?>">
            <input type="radio" name="progress_status" value="<?= canada_partner_h($key) ?>" <?= $statusValue === $key ? 'checked' : '' ?>>
            <strong><?= (int) $meta['n'] ?>. <?= canada_partner_h($meta['label']) ?></strong>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="grid">
        <div>
          <label>Partner</label>
          <select name="partner_id" required>
            <option value="">Choose</option>
            <?php foreach ($partners as $partner): ?>
              <option value="<?= (int) $partner['id'] ?>" data-type="<?= canada_partner_h((string) $partner['commission_type']) ?>" data-value="<?= canada_partner_h((string) $partner['commission_value']) ?>" data-label="<?= canada_partner_h(canada_partner_rule_label((string) $partner['commission_type'], (float) $partner['commission_value'])) ?>" <?= $selectedPartner === (int) $partner['id'] ? 'selected' : '' ?>><?= canada_partner_h((string) $partner['name']) ?> — <?= canada_partner_h((string) $partner['service_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Client full name</label><input name="full_name" required value="<?= canada_partner_h($value('full_name')) ?>"></div>
        <div><label>Phone</label><input name="phone" value="<?= canada_partner_h($value('phone')) ?>"></div>
        <div><label>Email</label><input name="email" value="<?= canada_partner_h($value('email')) ?>"></div>
        <div><label>Nationality</label><input name="nationality" value="<?= canada_partner_h($value('nationality')) ?>"></div>
        <div><label>Passport number</label><input name="passport_number" value="<?= canada_partner_h($value('passport_number')) ?>"></div>
        <div><label>Date of referral</label><input type="date" name="referral_date" value="<?= canada_partner_h($value('referral_date', $today)) ?>"></div>
        <div><label>Date discussion started</label><input type="date" name="discussion_date" value="<?= canada_partner_h($value('discussion_date', $today)) ?>"></div>
        <div><label>Date client accepted</label><input type="date" name="accepted_date" value="<?= canada_partner_h($value('accepted_date')) ?>"></div>
        <div><label>Service starting date</label><input type="date" name="service_start_date" value="<?= canada_partner_h($value('service_start_date')) ?>"></div>
        <div><label>Next follow-up</label><input type="date" name="next_followup" value="<?= canada_partner_h($value('next_followup')) ?>"></div>
        <div>
          <label>Responsible staff</label>
          <select name="responsible_admin_id">
            <option value="0">Not assigned</option>
            <?php $staffId = (int) $value('responsible_admin_id', (string) $adminId); ?>
            <?php foreach ($staff as $person): ?>
              <option value="<?= (int) $person['id'] ?>" <?= $staffId === (int) $person['id'] ? 'selected' : '' ?>><?= canada_partner_h($person['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <label>Partner remarks</label>
      <textarea name="partner_remarks"><?= canada_partner_h($value('partner_remarks')) ?></textarea>
    </div>

    <div class="split">
      <div class="card money">
        <strong class="section-title">Client service fee</strong>
        <?php if ($client): ?>
          <p>Paid <?= canada_partner_h(canada_partner_cad((float) $client['amount_paid'])) ?> · Balance <?= canada_partner_h(canada_partner_cad((float) $client['balance'])) ?> · <span class="badge <?= canada_partner_pay_class((string) $client['payment_state']) ?>"><?= canada_partner_h((string) $client['payment_state']) ?></span></p>
        <?php endif; ?>
        <label>Total service fee (CAD)</label>
        <input name="service_fee" type="number" min="0" step="0.01" value="<?= canada_partner_h($value('service_fee', '0')) ?>">
      </div>
      <div class="card commission">
        <strong class="section-title">Parrot commission</strong>
        <p id="commission-rule" class="muted"></p>
        <p id="commission-amount" class="commission-amount">CAD 0.00</p>
        <p id="commission-note" class="muted"></p>
      </div>
    </div>

    <div class="card">
      <strong>Decision or certificate</strong>
      <div class="grid">
        <div><label>Date</label><input type="date" name="decision_date" value="<?= canada_partner_h($value('decision_date')) ?>"></div>
        <div><label>Outcome</label><input name="decision_outcome" value="<?= canada_partner_h($value('decision_outcome')) ?>"></div>
      </div>
      <label>Supporting document</label>
      <input type="file" name="decision_document" accept=".pdf,.jpg,.jpeg,.png,.webp">
      <?php if ($client && (string) $client['decision_document'] !== ''): ?>
        <p><a href="<?= canada_partner_h((string) $client['decision_document']) ?>" target="_blank">Open the current document</a></p>
      <?php endif; ?>
      <p><button type="submit">Save client</button></p>
    </div>
  </form>

  <?php if ($client): ?>
  <div class="split">
    <div class="card money">
      <strong>Payment receipts</strong>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="payment">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="grid">
          <div><label>Date</label><input type="date" name="paid_on" value="<?= canada_partner_h($today) ?>" required></div>
          <div><label>Amount (CAD)</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
          <div><label>Method</label><select name="method"><option>Interac</option><option>Bank Transfer</option><option>Cash</option><option>Card</option><option>Other</option></select></div>
          <div><label>Receipt</label><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
        </div>
        <label>Note</label><input name="note">
        <p><button type="submit">Record client payment</button></p>
      </form>
      <table>
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $row): ?>
          <tr>
            <td><?= canada_partner_h((string) $row['paid_on']) ?><?php if ((string) $row['note'] !== ''): ?><br><span class="muted"><?= canada_partner_h((string) $row['note']) ?></span><?php endif; ?></td>
            <td><?= canada_partner_h(canada_partner_cad((float) $row['amount'])) ?></td>
            <td><?= canada_partner_h((string) $row['method']) ?><?php if ((string) $row['receipt_path'] !== ''): ?><br><a href="<?= canada_partner_h((string) $row['receipt_path']) ?>" target="_blank">Receipt</a><?php endif; ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="remove_payment">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
                <button class="warn" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card commission">
      <strong>Commission received by Parrot</strong>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="commission">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="grid">
          <div><label>Date</label><input type="date" name="paid_on" value="<?= canada_partner_h($today) ?>" required></div>
          <div><label>Amount (CAD)</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
          <div><label>Method</label><select name="method"><option>Interac</option><option>Bank Transfer</option><option>Cash</option><option>Other</option></select></div>
        </div>
        <label>Note</label><input name="note">
        <p><button type="submit">Record commission received</button></p>
      </form>
      <table>
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($commissions as $row): ?>
          <tr>
            <td><?= canada_partner_h((string) $row['paid_on']) ?><?php if ((string) $row['note'] !== ''): ?><br><span class="muted"><?= canada_partner_h((string) $row['note']) ?></span><?php endif; ?></td>
            <td><?= canada_partner_h(canada_partner_cad((float) $row['amount'])) ?></td>
            <td><?= canada_partner_h((string) $row['method']) ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="remove_commission">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
                <button class="warn" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php if ($copy !== ''): ?>
<script>
document.getElementById('copy-status').addEventListener('click', async () => {
  const text = <?= json_encode($copy) ?>;
  try {
    await navigator.clipboard.writeText(text);
    document.getElementById('copy-status').textContent = 'Copied';
  } catch (e) {
    document.getElementById('copy-status').textContent = 'Could not copy';
  }
});
</script>
<?php endif; ?>
<script>
const commissionReceived = <?= json_encode($client ? (float) $client['commission_received'] : 0.0) ?>;
const earnedStages = ['accepted', 'started', 'paid', 'decision'];
function cad(amount) {
  const value = Math.round((Number(amount) || 0) * 100) / 100;
  return 'CAD ' + value.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function refreshCommission() {
  const select = document.querySelector('[name="partner_id"]');
  const option = select.options[select.selectedIndex];
  const type = option ? (option.dataset.type || '') : '';
  const ruleValue = option ? parseFloat(option.dataset.value || '0') : 0;
  const label = option ? (option.dataset.label || '') : '';
  const fee = parseFloat(document.querySelector('[name="service_fee"]').value) || 0;
  const statusInput = document.querySelector('[name="progress_status"]:checked');
  const status = statusInput ? statusInput.value : 'discussion';
  const rule = document.getElementById('commission-rule');
  const amount = document.getElementById('commission-amount');
  const note = document.getElementById('commission-note');
  if (!type) {
    rule.textContent = '';
    amount.textContent = 'CAD 0.00';
    note.textContent = 'Choose a partner to calculate the commission.';
    return;
  }
  const full = type === 'percent'
    ? Math.round((fee * ruleValue / 100) * 100) / 100
    : Math.round(ruleValue * 100) / 100;
  const earned = earnedStages.indexOf(status) === -1 ? 0 : full;
  const outstanding = Math.round((earned - commissionReceived) * 100) / 100;
  rule.textContent = label;
  amount.textContent = cad(full);
  if (earned === 0 && full > 0) {
    note.textContent = 'Counted once the client is accepted.';
  } else {
    note.textContent = 'Earned ' + cad(earned) + ' · Received ' + cad(commissionReceived) + ' · Outstanding ' + cad(outstanding);
  }
}
document.querySelector('[name="service_fee"]').addEventListener('input', refreshCommission);
document.querySelector('[name="partner_id"]').addEventListener('change', refreshCommission);
document.querySelectorAll('[name="progress_status"]').forEach((input) => input.addEventListener('change', refreshCommission));
refreshCommission();
</script>
</body>
</html>

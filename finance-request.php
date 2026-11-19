<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/finance.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
$isSuper = pcvc_current_user_is_superadmin($conn);
finance_ensure_schema($conn);
$period = finance_period();
$budget = finance_budget($conn, $period['year'], $period['month']);
$usable = $budget && finance_budget_open_for_use($budget, $period);
$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'request');
        if ($action === 'request') {
            if (!$usable) {
                throw new InvalidArgumentException('There is no open budget for ' . $period['label'] . '.');
            }
            $lineIds = (array) ($_POST['line_id'] ?? []);
            $purposes = (array) ($_POST['purpose'] ?? []);
            $amounts = (array) ($_POST['amount'] ?? []);
            $count = max(count($lineIds), count($purposes), count($amounts));
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = [
                    'line_id' => (int) ($lineIds[$i] ?? 0),
                    'purpose' => (string) ($purposes[$i] ?? ''),
                    'amount' => (float) ($amounts[$i] ?? 0),
                ];
            }
            finance_submit_requests($conn, (int) $budget['id'], $adminId, $items);
            $saved = count(array_filter($items, static function (array $item): bool {
                return (int) $item['line_id'] > 0 || trim((string) $item['purpose']) !== '' || (float) $item['amount'] > 0;
            }));
            $notice = $saved === 1 ? 'The request is waiting for approval.' : $saved . ' items are waiting for approval.';
        } elseif ($isSuper) {
            finance_set_request_status($conn, (int) ($_POST['request_id'] ?? 0), $action, $adminId);
            $notice = 'The request is updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $budget = finance_budget($conn, $period['year'], $period['month']);
    $usable = $budget && finance_budget_open_for_use($budget, $period);
}

$lines = $budget ? finance_lines($conn, (int) $budget['id']) : [];
$requests = $budget ? finance_requests($conn, (int) $budget['id']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Budget request</title>
<style><?= finance_css() ?></style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Budget request</h1>
      <p class="sub"><?= finance_h($period['label']) ?> · one submission can include several items, on different lines or the same line.</p>
    </div>
  </div>
  <?php if ($error !== ''): ?><p class="err"><?= finance_h($error) ?></p><?php endif; ?>
  <?php if ($notice !== ''): ?><p class="ok"><?= finance_h($notice) ?></p><?php endif; ?>

  <div class="card" style="margin-bottom:16px">
    <?php if (!$usable): ?>
      <p class="muted"><?= $budget && ($budget['status'] ?? '') === 'closed' ? finance_h($period['label']) . ' is closed.' : 'Upload and open the ' . finance_h($period['label']) . ' budget before a request can be made.' ?></p>
    <?php else: ?>
      <form method="post" id="request-form">
        <input type="hidden" name="action" value="request">
        <p class="muted">Add one or more items. Each item can use a different line, or the same line again.</p>
        <div id="items"></div>
        <p><button type="button" class="ghost" id="add-item">Add another item</button></p>
        <button type="submit">Submit request</button>
      </form>
      <template id="item-template">
        <div class="req-item">
          <div class="req-head">
            <label>Budget line</label>
            <button type="button" class="ghost remove">Remove</button>
          </div>
          <select name="line_id[]" required>
            <option value="">Choose a line</option>
            <?php foreach ($lines as $line): ?>
              <option value="<?= (int) $line['id'] ?>" data-left="<?= finance_h((string) $line['remaining']) ?>">
                <?= finance_h((string) $line['line_code']) ?> · <?= finance_h((string) $line['category']) ?> · <?= finance_h(finance_money((float) $line['remaining'], finance_budget_currency())) ?> left
              </option>
            <?php endforeach; ?>
          </select>
          <div class="grid">
            <div>
              <label>What it is for</label>
              <input name="purpose[]" required maxlength="500" placeholder="Purpose of this item">
            </div>
            <div>
              <label>Amount (RWF)</label>
              <input name="amount[]" type="number" min="0.01" step="0.01" required>
            </div>
          </div>
          <p class="muted left"></p>
        </div>
      </template>
    <?php endif; ?>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>Line</th><th>Requester</th><th>Purpose</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if ($requests === []): ?>
        <tr><td colspan="6">No requests for <?= finance_h($period['label']) ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td><?= finance_h((string) $request['line_code']) ?><br><span class="muted"><?= finance_h((string) $request['category']) ?></span></td>
          <td><?= finance_h(trim((string) $request['requester']) !== '' ? (string) $request['requester'] : 'Staff') ?></td>
          <td><?= finance_h((string) $request['purpose']) ?></td>
          <td><?= finance_h(finance_money((float) $request['amount'], finance_budget_currency())) ?></td>
          <td><span class="pill <?= finance_h((string) $request['status']) ?>"><?= finance_h(ucfirst((string) $request['status'])) ?></span></td>
          <td>
            <?php if ($isSuper && $request['status'] === 'pending'): ?>
              <form method="post" class="row-actions">
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                <button name="action" value="approved" type="submit">Approve</button>
                <button class="warn" name="action" value="rejected" type="submit">Reject</button>
              </form>
            <?php elseif ($isSuper && $request['status'] === 'approved'): ?>
              <form method="post">
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                <button name="action" value="paid" type="submit">Mark paid</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<style>
.req-item { border: 1px solid #e7eeea; border-radius: 14px; padding: 12px 14px 4px; margin: 12px 0; background: #f8fbf9; }
.req-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.req-head label { margin-bottom: 0; }
.req-item .left.warn { color: #991b1b; }
button.ghost { background: #fff; color: #334155; border: 1px solid #e5eee8; }
</style>
<script>
const items = document.getElementById('items');
const template = document.getElementById('item-template');
const add = document.getElementById('add-item');
const money = (value) => 'RWF ' + Number(value).toLocaleString('en-RW', { maximumFractionDigits: 0 });

function addItem() {
  if (!items || !template) return;
  items.appendChild(template.content.cloneNode(true));
  refresh();
}

function refresh() {
  if (!items) return;
  const rows = [...items.querySelectorAll('.req-item')];
  const asked = {};
  rows.forEach((row) => {
    const select = row.querySelector('select');
    const amount = Number(row.querySelector('[name="amount[]"]')?.value || 0);
    const id = select?.value || '';
    if (id) asked[id] = (asked[id] || 0) + (Number.isFinite(amount) ? amount : 0);
  });
  rows.forEach((row) => {
    const select = row.querySelector('select');
    const note = row.querySelector('.left');
    const option = select?.selectedOptions?.[0];
    const id = select?.value || '';
    const left = Number(option?.dataset.left || 0);
    const remove = row.querySelector('.remove');
    if (remove) remove.hidden = rows.length < 2;
    if (!note) return;
    if (!id) {
      note.textContent = '';
      note.classList.remove('warn');
      return;
    }
    const total = asked[id] || 0;
    if (total - left > 0.001) {
      note.textContent = 'These items ask for ' + money(total) + '. This line has ' + money(left) + ' left.';
      note.classList.add('warn');
    } else {
      note.textContent = 'This line can still take ' + money(Math.max(0, left - total)) + ' after these items.';
      note.classList.remove('warn');
    }
  });
}

if (add) add.addEventListener('click', addItem);
if (items) {
  items.addEventListener('click', (event) => {
    const button = event.target.closest('.remove');
    if (!button) return;
    button.closest('.req-item')?.remove();
    refresh();
  });
  items.addEventListener('input', refresh);
  items.addEventListener('change', refresh);
  addItem();
}
</script>
</body>
</html>

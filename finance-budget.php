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
if (!pcvc_current_user_can_access_finance($conn)) {
    http_response_code(403);
    exit('The monthly budget is prepared by management.');
}

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
finance_ensure_schema($conn);
$period = finance_period();

if (isset($_GET['template'])) {
    $binary = finance_template_xlsx();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="parrot-monthly-budget.xlsx"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

$error = '';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'close') {
            $budget = finance_budget($conn, $period['year'], $period['month']);
            if (!$budget) {
                throw new InvalidArgumentException('Upload this month\'s budget before closing it.');
            }
            finance_close_budget($conn, (int) $budget['id'], $adminId);
            $notice = $period['label'] . ' is closed.';
        } else {
            $file = $_FILES['budget_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Choose the monthly budget file.');
            }
            $name = (string) ($file['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'], true)) {
                throw new InvalidArgumentException('Upload the Excel template or a CSV saved from Excel.');
            }
            $lines = finance_parse_budget_file((string) $file['tmp_name'], $name);
            finance_save_upload($conn, $lines, $adminId, $period);
            $notice = $period['label'] . ' now has ' . count($lines) . ' budget lines.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$budget = finance_budget($conn, $period['year'], $period['month']);
$lines = $budget ? finance_lines($conn, (int) $budget['id']) : [];
$open = $budget && ($budget['status'] ?? '') === 'open';
$requestCount = $budget ? finance_request_count($conn, (int) $budget['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly budget</title>
<style><?= finance_css() ?></style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Monthly budget</h1>
      <p class="sub"><?= finance_h($period['label']) ?> · upload at the start of the month, close at the end.</p>
    </div>
    <a class="btn ghost" href="finance-budget.php?template=1">Download Excel form</a>
  </div>
  <?php if ($error !== ''): ?><p class="err"><?= finance_h($error) ?></p><?php endif; ?>
  <?php if ($notice !== ''): ?><p class="ok"><?= finance_h($notice) ?></p><?php endif; ?>

  <div class="card" style="margin-bottom:16px">
    <p class="muted">Columns: Line code, Category, Description, Amount (RWF). One row is one budget line. Requests can only use these lines.</p>
    <?php if ($budget): ?>
      <p><span class="pill <?= finance_h((string) $budget['status']) ?>"><?= finance_h(ucfirst((string) $budget['status'])) ?></span></p>
    <?php endif; ?>
    <?php if (!$budget || ($open && $requestCount === 0)): ?>
      <form method="post" enctype="multipart/form-data">
        <label>Budget file for <?= finance_h($period['label']) ?></label>
        <input type="file" name="budget_file" accept=".xlsx,.csv" required>
        <p><button type="submit">Upload budget</button></p>
      </form>
    <?php elseif ($open): ?>
      <p class="muted">This month already has requests, so the file stays as uploaded.</p>
    <?php endif; ?>
    <?php if ($open): ?>
      <form method="post" onsubmit="return confirm('Close <?= finance_h($period['label']) ?>? New requests will stop.');">
        <input type="hidden" name="action" value="close">
        <button class="warn" type="submit">Close this month</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>Line</th><th>Category</th><th>Description</th><th>Budget</th><th>In use</th><th>Left</th></tr></thead>
      <tbody>
      <?php if ($lines === []): ?>
        <tr><td colspan="6">No budget has been uploaded for <?= finance_h($period['label']) ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($lines as $line): ?>
        <tr>
          <td><?= finance_h((string) $line['line_code']) ?></td>
          <td><?= finance_h((string) $line['category']) ?></td>
          <td><?= finance_h((string) $line['description']) ?></td>
          <td><?= finance_h(finance_money((float) $line['amount'], finance_budget_currency())) ?></td>
          <td><?= finance_h(finance_money((float) $line['reserved'], finance_budget_currency())) ?></td>
          <td><?= finance_h(finance_money((float) $line['remaining'], finance_budget_currency())) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

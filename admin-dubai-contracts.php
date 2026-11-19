<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/dubai_work_contract.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

dubai_work_contract_ensure_schema($conn);
$result = $conn->query('SELECT id, contract_token, reference_number, status, client_name, email, position, created_at, signed_at FROM dubai_work_contracts ORDER BY id DESC');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com')
    . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dubai work contracts</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; padding: 28px 16px; background: #f6f1e7; font-family: "Segoe UI", sans-serif; color: #1c1917; }
.wrap { max-width: 1100px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 12px 32px rgba(28,25,23,.08); }
h1 { margin: 0 0 14px; font-size: 22px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #f0e7d8; font-size: 14px; vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; color: #8a6a2f; }
a.btn { display: inline-block; margin: 0 6px 6px 0; background: #1c1917; color: #f6e7c1; text-decoration: none; border-radius: 6px; padding: 6px 10px; font-weight: 700; font-size: 13px; }
.empty { color: #78716c; padding: 28px 0; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Dubai work contracts</h1>
  <table>
    <thead><tr><th>Reference</th><th>Client</th><th>Position</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$result || $result->num_rows === 0): ?>
      <tr><td colspan="5" class="empty">No Dubai contracts yet. Create a sign link first.</td></tr>
    <?php endif; ?>
    <?php if ($result): while ($row = $result->fetch_assoc()): ?>
      <?php $url = $base . '/dubai-work-contract.php?token=' . rawurlencode((string) $row['contract_token']); ?>
      <tr>
        <td><?= htmlspecialchars((string) ($row['reference_number'] ?: '—'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($row['client_name'] ?: 'Not signed yet'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) ($row['email'] ?: ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($row['position'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <a class="btn" href="dubai-record-invoice.php?contract_id=<?= (int) $row['id'] ?>">Invoice</a>
          <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open</a>
          <?php if (($row['status'] ?? '') === 'signed'): ?>
            <a class="btn" href="admin-download-dubai-contract.php?id=<?= (int) $row['id'] ?>">PDF</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>

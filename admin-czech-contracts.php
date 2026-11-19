<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/czech_work_contract.php';

if (empty($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

czech_work_contract_ensure_schema($conn);
$result = $conn->query('SELECT id, contract_token, reference_number, status, client_name, email, created_at, signed_at FROM czech_work_contracts ORDER BY id DESC');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com')
    . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Czech Republic work permit agreements</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; padding: 28px 16px; background: #f4f1ea; font-family: "Times New Roman", Times, serif; color: #000; }
.wrap { max-width: 1100px; margin: 0 auto; background: #fff; border: 1px solid #000; padding: 22px; }
h1 { margin: 0 0 14px; font-size: 22px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #ddd; font-size: 14px; vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; }
a.btn { display: inline-block; margin: 0 6px 6px 0; background: #000; color: #fff; text-decoration: none; padding: 6px 10px; font-weight: 700; font-size: 13px; }
.empty { color: #444; padding: 28px 0; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Czech Republic work permit agreements</h1>
  <table>
    <thead><tr><th>Reference</th><th>Job seeker</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$result || $result->num_rows === 0): ?>
      <tr><td colspan="4" class="empty">No Czech agreements yet. Create a sign link first.</td></tr>
    <?php endif; ?>
    <?php if ($result): while ($row = $result->fetch_assoc()): ?>
      <?php $url = $base . '/czech-work-contract.php?token=' . rawurlencode((string) $row['contract_token']); ?>
      <tr>
        <td><?= htmlspecialchars((string) ($row['reference_number'] ?: '—'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($row['client_name'] ?: 'Not signed yet'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) ($row['email'] ?: ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <a class="btn" href="czech-record-invoice.php?contract_id=<?= (int) $row['id'] ?>">Invoice</a>
          <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open</a>
          <?php if (($row['status'] ?? '') === 'signed'): ?>
            <a class="btn" href="admin-download-czech-contract.php?id=<?= (int) $row['id'] ?>">PDF</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>

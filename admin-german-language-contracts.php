<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

pcvc_service_agreement_ensure_schema($conn);
$rows = [];
$res = $conn->query(
    "SELECT id, contract_token, status, language, company_name, representative_name, company_email, signed_at, created_at, pdf_path
     FROM service_agreements
     WHERE kind = 'german_language'
     ORDER BY id DESC"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>German language contracts</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: "Segoe UI", sans-serif; background: #f3f5f9; margin: 0; padding: 28px 16px; color: #1f2937; }
.card { max-width: 1100px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
h1 { margin-top: 0; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: 14px; }
a { color: #1f4fd8; }
.pending { color: #9a3412; }
.signed { color: #166534; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
  <h1>German language contracts</h1>
  <table>
    <thead><tr><th>Student</th><th>Language</th><th>Status</th><th>Link</th><th>Signed</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="5">No German language links yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
      <?php $url = pcvc_service_agreement_public_url((string) $row['contract_token']); ?>
      <tr>
        <td>
          <?= htmlspecialchars((string) ($row['company_name'] ?: 'Not signed yet'), ENT_QUOTES, 'UTF-8') ?><br>
          <span><?= htmlspecialchars((string) ($row['representative_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </td>
        <td><?= (($row['language'] ?? 'english') === 'french') ? 'French' : 'English' ?></td>
        <td class="<?= $row['status'] === 'signed' ? 'signed' : 'pending' ?>"><?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open link</a></td>
        <td>
          <?= htmlspecialchars((string) ($row['signed_at'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>
          <?php if ($row['status'] === 'signed'): ?>
            <br><a href="admin-download-service-agreement.php?id=<?= (int) $row['id'] ?>">Download PDF</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>

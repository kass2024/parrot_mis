<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}
pcvc_require_francophonie_mobility($conn);
irs_agreement_ensure_schema($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$result = $conn->query(
    "SELECT id, contract_token, agreement_reference, client_name, client_email, signed_at, sent_at
     FROM irs_agreements
     WHERE status = 'signed'
     ORDER BY id DESC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Signed Immigration Records Agreements | Parrot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: Segoe UI, sans-serif; background: #f0f4f0; padding: 28px 16px; }
.container { max-width: 1100px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 22px; border-top: 4px solid #2d5a27; }
.header { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
h1 { color: #2d5a27; font-size: 22px; }
a.btn, button.btn { border: 0; border-radius: 6px; padding: 8px 12px; color: #fff; text-decoration: none; font-weight: 700; font-size: 12px; cursor: pointer; }
.issue { background: #2d5a27; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; }
table { width: 100%; border-collapse: collapse; margin-top: 16px; }
th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid #e8eee8; font-size: 14px; }
th { color: #2d5a27; font-size: 12px; text-transform: uppercase; }
.pdf { background: #28a745; } .mail { background: #17a2b8; } .del { background: #dc3545; }
.alert { background: #d4edda; color: #155724; padding: 10px 12px; border-radius: 8px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Signed immigration records agreements</h1>
    <a class="issue" href="admin-generate-irs-agreement.php">+ Issue agreement</a>
  </div>
  <?php if (!empty($_GET['sent'])): ?><p class="alert">Agreement emailed.</p><?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?><p class="alert">Agreement deleted.</p><?php endif; ?>
  <table>
    <thead><tr><th>Reference</th><th>Client</th><th>Email</th><th>Signed</th><th></th></tr></thead>
    <tbody>
    <?php if (!$result || $result->num_rows === 0): ?>
      <tr><td colspan="5">No signed agreements yet.</td></tr>
    <?php else: while ($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($row['agreement_reference'] ?: $row['id'])) ?></td>
        <td><?= htmlspecialchars((string) $row['client_name']) ?></td>
        <td><?= htmlspecialchars((string) $row['client_email']) ?></td>
        <td><?= $row['signed_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime((string) $row['signed_at']))) : '' ?></td>
        <td>
          <a class="btn pdf" target="_blank" href="admin-download-irs-agreement.php?id=<?= (int) $row['id'] ?>">PDF</a>
          <form class="send-form" method="post" action="admin-send-irs-agreement.php" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="agreement_id" value="<?= (int) $row['id'] ?>">
            <button class="btn mail" type="button" onclick="sendAgreement(this)"><?= $row['sent_at'] ? 'Resend' : 'Email' ?></button>
          </form>
          <form method="post" action="admin-delete-irs-agreement.php" style="display:inline;" onsubmit="return confirm('Delete this agreement?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="agreement_id" value="<?= (int) $row['id'] ?>">
            <button class="btn del" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
</div>
<script>
function sendAgreement(btn) {
  const form = btn.closest('form');
  btn.disabled = true;
  fetch(form.action, { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(data => {
      if (data.success) window.location.href = 'admin-irs-agreements.php?sent=1';
      else { alert(data.error || 'Email failed.'); btn.disabled = false; }
    })
    .catch(() => { alert('Network error.'); btn.disabled = false; });
}
</script>
</body>
</html>

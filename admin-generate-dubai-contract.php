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
$link = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = bin2hex(random_bytes(16));
    $reference = 'DXB-' . date('Ymd') . '-' . strtoupper(substr($token, 0, 4));
    $stmt = $conn->prepare("INSERT INTO dubai_work_contracts (contract_token, reference_number, status, agreement_date, created_at) VALUES (?, ?, 'pending', CURDATE(), NOW())");
    if (!$stmt) {
        $error = 'Could not create the contract link.';
    } else {
        $stmt->bind_param('ss', $token, $reference);
        if ($stmt->execute()) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $link = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com')
                . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\')
                . '/dubai-work-contract.php?token=' . rawurlencode($token);
        } else {
            $error = 'Could not create the contract link.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issue Dubai work contract</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: "Segoe UI", sans-serif; background: #f6f1e7; margin: 0; padding: 28px 16px; color: #1c1917; }
.card { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 12px 32px rgba(28,25,23,.08); }
h1 { margin: 0 0 8px; font-size: 24px; }
p { color: #57534e; }
button, .copy { background: #1c1917; color: #f6e7c1; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
input { width: 100%; box-sizing: border-box; margin: 8px 0 12px; padding: 10px; font-family: Consolas, monospace; }
.err { background: #fee2e2; color: #991b1b; padding: 10px 12px; border-radius: 8px; }
</style>
</head>
<body>
<div class="card">
  <h1>Dubai work contract</h1>
  <p>Each click creates a new signing link for the Dubai work abroad agreement. The package is USD 850. The client fills the job details, draws a signature, and submits.</p>
  <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($link !== ''): ?>
    <p>Share this link:</p>
    <input id="link" readonly value="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="copy" onclick="navigator.clipboard.writeText(document.getElementById('link').value)">Copy link</button>
  <?php endif; ?>
  <form method="post" style="margin-top:16px;">
    <button type="submit">Create a sign link</button>
  </form>
</div>
</body>
</html>

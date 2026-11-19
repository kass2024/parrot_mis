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
$link = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = bin2hex(random_bytes(16));
    $reference = 'PCVCCL/CZ-WORK/' . strtoupper(substr($token, 0, 6));
    $stmt = $conn->prepare("INSERT INTO czech_work_contracts (contract_token, reference_number, status, agreement_date, created_at) VALUES (?, ?, 'pending', CURDATE(), NOW())");
    if (!$stmt) {
        $error = 'Could not create the contract link.';
    } else {
        $stmt->bind_param('ss', $token, $reference);
        if ($stmt->execute()) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $link = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com')
                . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\')
                . '/czech-work-contract.php?token=' . rawurlencode($token);
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
<title>Issue Czech Republic work permit agreement</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: "Times New Roman", Times, serif; background: #f4f1ea; margin: 0; padding: 28px 16px; color: #000; }
.card { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #000; padding: 28px; }
h1 { margin: 0 0 8px; font-size: 22px; }
p { line-height: 1.35; }
button, .copy { background: #000; color: #fff; border: 0; padding: 10px 16px; font-family: inherit; font-weight: 700; cursor: pointer; }
input { width: 100%; box-sizing: border-box; margin: 8px 0 12px; padding: 10px; font-family: Consolas, monospace; }
.err { background: #fee2e2; color: #991b1b; padding: 10px 12px; }
</style>
</head>
<body>
<div class="card">
  <h1>Czech Republic work permit agreement</h1>
  <p>Each click creates a new signing link for the Czech Republic work-permit and residence-card service agreement. The package is €2,500 in three installments. The job seeker fills the personal details, draws a signature, and submits.</p>
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

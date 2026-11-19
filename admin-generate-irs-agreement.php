<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized access.');
}
pcvc_require_francophonie_mobility($conn);

irs_agreement_ensure_schema($conn);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl = $scheme . '://' . $host . $basePath . '/irs-agreement.php';

$contractLink = null;
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare(
        "INSERT INTO irs_agreements (contract_token, status, created_at) VALUES (?, 'draft', NOW())"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    $reference = 'IRS-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    $upd = $conn->prepare('UPDATE irs_agreements SET agreement_reference = ? WHERE id = ?');
    $upd->bind_param('si', $reference, $id);
    $upd->execute();
    $upd->close();
    $contractLink = $baseUrl . '?token=' . $token;
    $message = 'Signing link issued. Reference ' . $reference . '.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issue Immigration Records Agreement | Parrot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: Segoe UI, sans-serif; background: #f0f4f0; margin: 0; padding: 32px 16px; color: #1a2e1a; }
.card { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px; border-top: 4px solid #2d5a27; box-shadow: 0 12px 30px rgba(45,90,39,.08); }
h1 { font-size: 22px; margin: 0 0 8px; color: #2d5a27; }
p { line-height: 1.5; }
button, .link { background: #2d5a27; color: #fff; border: 0; border-radius: 10px; padding: 12px 16px; font-weight: 700; font-size: 16px; cursor: pointer; text-decoration: none; display: inline-block; }
.note { background: #e8f5e9; padding: 12px 14px; border-radius: 8px; }
.link-box { display: flex; gap: 8px; align-items: stretch; }
input[type=text] { flex: 1; min-width: 0; padding: 12px; border: 1px solid #cfd8cf; border-radius: 10px; font-size: 15px; }
#copyMsg { color: #1b5e20; font-weight: 700; min-height: 1.2em; }
@media (max-width: 640px) { .link-box { flex-direction: column; } body { padding: 16px 12px; } }
</style>
</head>
<body>
<div class="card">
  <h1>Immigration records agreement</h1>
  <p>Issue a standalone signing link for the Immigration Application Records Recovery and Review Support Agreement. The client fills the particulars and signs. The fixed price is CAD 650. Parrot’s signature and stamp are added on the company side.</p>
  <?php if ($message): ?><p class="note"><?= htmlspecialchars($message) ?></p><?php endif; ?>
  <?php if ($contractLink): ?>
    <p><label for="signLink">Client signing link</label></p>
    <div class="link-box">
      <input id="signLink" type="text" readonly value="<?= htmlspecialchars($contractLink) ?>">
      <button type="button" id="copyLink">Copy link</button>
    </div>
    <p id="copyMsg"></p>
    <p><a class="link" href="<?= htmlspecialchars($contractLink) ?>" target="_blank">Open the agreement</a></p>
  <?php endif; ?>
  <form method="post">
    <button type="submit">Issue signing link</button>
  </form>
  <p style="margin-top:18px;"><a href="admin-irs-agreements.php">View signed agreements</a></p>
</div>
<script>
const copyBtn = document.getElementById('copyLink');
if (copyBtn) {
  copyBtn.addEventListener('click', async () => {
    const input = document.getElementById('signLink');
    const msg = document.getElementById('copyMsg');
    input.select();
    try {
      await navigator.clipboard.writeText(input.value);
    } catch (e) {
      document.execCommand('copy');
    }
    msg.textContent = 'Link copied.';
  });
}
</script>
</body>
</html>

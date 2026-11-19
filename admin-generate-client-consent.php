<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/client_consent_schema.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

client_consent_ensure_schema($conn);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$showSuccess = isset($_GET['generated']) && $_GET['generated'] === '1';
$showError = isset($_GET['error']) && $_GET['error'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($_SESSION['csrf_token'], $postedToken)) {
        header('Location: ?error=' . urlencode('Invalid request'));
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $conn->prepare('INSERT INTO client_consent_agreements (contract_token, status, created_at) VALUES (?, \'draft\', NOW())');
    $stmt->bind_param('s', $token);
    if ($stmt->execute()) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $fullPath = $protocol . '://' . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/client-consent-agreement.php?token=' . $token;
        header('Location: ?generated=1&link=' . urlencode($fullPath));
        exit;
    }
    header('Location: ?error=' . urlencode('Failed to generate the agreement link'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issue Client Consent Agreement | Parrot Canada Visa Consultant</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{ --blue:#1f4fd8; --bg:#f3f5f9; --border:#e5e7eb; --text:#374151; }
*{ box-sizing:border-box; margin:0; padding:0; }
body{ font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; background:var(--bg); padding:30px 20px; color:var(--text); }
.container{ max-width:800px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.08); padding:30px; }
.header{ display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid var(--border); }
.header h1{ font-size:22px; color:#1f2937; }
.back-btn{ background:#eef2ff; color:var(--blue); padding:8px 16px; border-radius:6px; text-decoration:none; font-weight:600; font-size:14px; }
.lead{ margin-bottom:18px; line-height:1.6; }
.btn{ padding:10px 18px; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; background:var(--blue); color:#fff; }
.alert{ padding:12px 16px; border-radius:6px; margin-bottom:16px; }
.alert-success{ background:#d1fae5; color:#065f46; }
.alert-error{ background:#fee2e2; color:#991b1b; }
.link-result{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:16px; margin-bottom:18px; }
.link-result input{ width:100%; margin-top:8px; padding:8px 12px; border:1px solid #d1d5db; border-radius:4px; font-family:monospace; font-size:13px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1>Issue client consent link</h1>
    <a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/admin-dashboard.php" class="back-btn">Back to Dashboard</a>
</div>
<p class="lead">Create a signing link for the Client Consent, Referral, and File-Transfer Agreement. Send the link to the client. The signed copy is stored with the other agreements and can be opened from View client consent agreements.</p>
<?php if ($showSuccess && isset($_GET['link'])): ?>
<div class="alert alert-success">Agreement link generated.</div>
<div class="link-result">
    <p>Share this link with the client:</p>
    <input type="text" id="contractLink" value="<?= htmlspecialchars((string) $_GET['link'], ENT_QUOTES, 'UTF-8') ?>" readonly onclick="this.select()">
    <p style="margin-top:10px;"><button type="button" class="btn" onclick="copyLink()">Copy link</button></p>
</div>
<?php endif; ?>
<?php if ($showError): ?>
<div class="alert alert-error"><?= htmlspecialchars(urldecode((string) $_GET['error']), ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="btn">Generate client consent link</button>
</form>
</div>
<script>
function copyLink() {
    const input = document.getElementById('contractLink');
    input.select();
    navigator.clipboard.writeText(input.value).catch(function () { document.execCommand('copy'); });
}
</script>
</body>
</html>

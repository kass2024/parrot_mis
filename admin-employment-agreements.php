<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/employment_agreement_schema.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

employment_agreement_ensure_schema($conn);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$result = $conn->query("SELECT id, reference_number, client_name, email, destination_country, preferred_job, signed_at FROM employment_agreements WHERE status = 'signed' ORDER BY id DESC");
if (!$result) {
    die('The agreements list could not be loaded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employment Agreements | Parrot Canada Visa Consultant</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
body { margin: 0; padding: 28px 16px; background: #f3f5f9; font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; }
.container { max-width: 1100px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
.header { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 18px; }
h1 { font-size: 22px; margin: 0; }
.back { background: #eef2ff; color: #1f4fd8; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid #eef2f6; font-size: 14px; }
th { font-size: 12px; text-transform: uppercase; color: #5a6a7e; }
.btn { display: inline-block; padding: 7px 10px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 700; border: 0; cursor: pointer; }
.pdf { background: #1f4fd8; color: #fff; }
.del { background: #fee2e2; color: #991b1b; }
.alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; background: #d1fae5; color: #065f46; }
.empty { text-align: center; color: #6b7280; padding: 36px 10px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1>Employment agreements</h1>
    <a class="back" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/admin-dashboard.php">Back to Dashboard</a>
</div>
<?php if (isset($_GET['deleted'])): ?><div class="alert">Agreement deleted.</div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert" style="background:#fee2e2;color:#991b1b;"><?= htmlspecialchars(urldecode((string) $_GET['error']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<table>
<thead>
<tr><th>#</th><th>Reference</th><th>Client</th><th>Country</th><th>Job</th><th>Signed</th><th>Actions</th></tr>
</thead>
<tbody>
<?php if ($result->num_rows === 0): ?>
<tr><td colspan="7" class="empty">No signed employment agreements yet. They appear here after a client signs the link.</td></tr>
<?php endif; ?>
<?php $i = 1; while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars((string) ($row['reference_number'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars((string) ($row['client_name'] ?: 'Unknown client'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) ($row['email'] ?: ''), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars((string) ($row['destination_country'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars((string) ($row['preferred_job'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars(!empty($row['signed_at']) ? date('Y-m-d H:i', strtotime((string) $row['signed_at'])) : '—', ENT_QUOTES, 'UTF-8') ?></td>
    <td>
        <a class="btn pdf" target="_blank" rel="noopener" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/admin-download-employment-agreement.php?id=<?= (int) $row['id'] ?>">PDF</a>
        <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/admin-delete-employment-agreement.php" style="display:inline;" onsubmit="return confirm('Delete this signed agreement?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="contract_id" value="<?= (int) $row['id'] ?>">
            <button class="btn del" type="submit">Delete</button>
        </form>
    </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</body>
</html>

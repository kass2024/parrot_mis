<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized access.');
}

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    exit('Database connection error.');
}

$languageColumn = $conn->query("SHOW COLUMNS FROM student_contracts LIKE 'contract_language'");
if ($languageColumn && $languageColumn->num_rows === 0) {
    $conn->query("ALTER TABLE student_contracts ADD COLUMN contract_language VARCHAR(8) NOT NULL DEFAULT 'en' AFTER status");
}
if ($languageColumn) {
    $languageColumn->free();
}

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl  = "{$scheme}://{$host}{$basePath}/student-contract-french.php";

$contractLink = null;
$message      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = !empty($_POST['student_id']) ? (int) $_POST['student_id'] : null;

    if ($studentId) {
        $stmt = $conn->prepare(
            "SELECT contract_token
             FROM student_contracts
             WHERE student_id = ?
               AND contract_language = 'fr'
               AND status IN ('draft','signed')
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $contractLink = $baseUrl . '?token=' . $existing['contract_token'];
            $message = 'Existing French contract found. Reusing the same link.';
        }
    }

    if (!$contractLink) {
        $contractToken = bin2hex(random_bytes(32));
        $stmt = $conn->prepare(
            "INSERT INTO student_contracts
             (contract_token, student_id, status, contract_language, created_at)
             VALUES (?, ?, 'draft', 'fr', NOW())"
        );
        $stmt->bind_param('si', $contractToken, $studentId);
        $stmt->execute();
        $stmt->close();

        $contractLink = $baseUrl . '?token=' . $contractToken;
        $message = 'New French contract issued. The English contract is unchanged.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issue French Student Contract</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root { --primary:#1d4ed8; --success:#15803d; --bg:#f8fafc; --card:#fff; --muted:#64748b; }
body { font-family: Inter, Segoe UI, sans-serif; background: var(--bg); }
.container { max-width: 640px; margin: 80px auto; background: var(--card); padding: 32px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,.08); }
h1 { text-align: center; margin-bottom: 6px; }
.subtitle { text-align: center; color: var(--muted); margin-bottom: 30px; font-size: 14px; }
.btn { width: 100%; padding: 14px; font-size: 16px; border-radius: 8px; border: none; cursor: pointer; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-success { background: var(--success); color: #fff; margin-top: 10px; }
.btn-back { background: #e2e8f0; margin-bottom: 20px; }
.alert-success { background: #e0f2fe; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 18px; }
.link-box { margin-top: 25px; padding: 16px; background: #f8fafc; border-radius: 8px; }
.link-box input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
    <a href="admin-dashboard.php"><button class="btn btn-back" type="button">← Back to Dashboard</button></a>
    <h1>Issue French student contract</h1>
    <p class="subtitle">The student opens a French contract. The English contract link stays the same.</p>
    <?php if ($message): ?>
        <div class="alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="post">
        <button class="btn btn-primary" type="submit">Issue French contract link</button>
    </form>
    <?php if ($contractLink): ?>
        <div class="link-box">
            <label>French contract link</label>
            <input type="text" id="contractLink" value="<?= htmlspecialchars($contractLink) ?>" readonly>
            <button class="btn btn-success" type="button" onclick="copyLink()">Copy link</button>
            <div id="copyMsg" style="display:none;text-align:center;color:green;margin-top:8px;">Link copied</div>
        </div>
    <?php endif; ?>
</main>
<?php include 'footer.php'; ?>
<script>
function copyLink() {
    const input = document.getElementById('contractLink');
    const msg = document.getElementById('copyMsg');
    input.select();
    document.execCommand('copy');
    msg.style.display = 'block';
    setTimeout(() => msg.style.display = 'none', 2000);
}
</script>
</body>
</html>

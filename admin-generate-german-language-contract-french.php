<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/service_agreement.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Accès refusé');
}

pcvc_service_agreement_ensure_schema($conn);
$link = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = bin2hex(random_bytes(16));
    $stmt = $conn->prepare(
        "INSERT INTO service_agreements (kind, contract_token, editable_after_sign, status, language, created_at)
         VALUES ('german_language', ?, 0, 'pending', 'french', NOW())"
    );
    if (!$stmt) {
        $error = 'Le lien n\'a pas pu être créé.';
    } else {
        $stmt->bind_param('s', $token);
        if ($stmt->execute()) {
            $link = pcvc_service_agreement_public_url($token);
        } else {
            $error = 'Le lien n\'a pas pu être créé.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Émettre le contrat de langue allemande en français</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: "Segoe UI", sans-serif; background: #f3f5f9; margin: 0; padding: 28px 16px; color: #1f2937; }
.card { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 8px 24px rgba(0,0,0,.06); }
h1 { margin: 0 0 8px; font-size: 24px; }
p { color: #4b5563; }
button, .copy { background: #1f4fd8; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
input { width: 100%; box-sizing: border-box; margin: 8px 0; padding: 10px; font-family: Consolas, monospace; }
.err { background: #fee2e2; color: #991b1b; padding: 10px 12px; border-radius: 8px; }
</style>
</head>
<body>
<div class="card">
  <h1>Contrat de langue allemande (français)</h1>
  <p>Chaque clic crée un nouveau lien de signature en français pour l'accord de service de l'étudiant en langue allemande. Envoyez un lien différent à chaque étudiant. Après la signature, ce lien ne peut plus être modifié.</p>
  <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($link !== ''): ?>
    <p>Partagez ce lien :</p>
    <input id="link" readonly value="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="copy" onclick="navigator.clipboard.writeText(document.getElementById('link').value)">Copier le lien</button>
  <?php endif; ?>
  <form method="post" style="margin-top:16px;">
    <button type="submit">Émettre un nouveau lien</button>
  </form>
</div>
</body>
</html>

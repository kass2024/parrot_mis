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
$only = trim((string) ($_GET['only'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue = trim((string) ($_POST['issue'] ?? ''));
    $catalog = pcvc_special_contract_catalog();
    if ($issue !== '' && isset($catalog[$issue]) && !empty($catalog[$issue]['many'])) {
        pcvc_service_agreement_insert_special($conn, $issue);
        header('Location: admin-special-contracts.php?only=' . rawurlencode($issue));
        exit;
    }
}
$items = [];
foreach (pcvc_special_contract_catalog() as $key => $meta) {
    if ($only !== '') {
        if ($key !== $only) {
            continue;
        }
    } elseif (!empty($meta['separate'])) {
        continue;
    }
    $many = !empty($meta['many']);
    $rows = $many
        ? pcvc_service_agreement_special_rows($conn, $key)
        : array_values(array_filter([pcvc_service_agreement_special($conn, $key)]));
    $items[] = ['key' => $key, 'meta' => $meta, 'rows' => $rows, 'many' => $many];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $only !== '' && isset($items[0]) ? htmlspecialchars($items[0]['meta']['title'], ENT_QUOTES, 'UTF-8') : 'Special contracts' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: "Segoe UI", sans-serif; background: #f3f5f9; margin: 0; padding: 28px 16px; color: #1f2937; }
.wrap { max-width: 860px; margin: 0 auto; }
h1 { margin-bottom: 6px; }
.lead { color: #4b5563; margin-top: 0; }
.item { background: #fff; border-radius: 12px; padding: 18px 18px 14px; margin: 12px 0; box-shadow: 0 8px 24px rgba(0,0,0,.05); }
h2 { margin: 0 0 6px; font-size: 18px; }
input { width: 100%; box-sizing: border-box; margin: 8px 0; padding: 10px; font-family: Consolas, monospace; }
button, a.btn { background: #1f4fd8; color: #fff; border: 0; border-radius: 8px; padding: 8px 12px; font-weight: 700; text-decoration: none; display: inline-block; cursor: pointer; }
form.issue { margin: 10px 0 16px; }
.link-row { border-top: 1px solid #e5e7eb; margin-top: 12px; padding-top: 12px; }
.room { margin-top: 18px; padding: 14px; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; }
.err { color: #991b1b; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?= $only !== '' && isset($items[0]) ? htmlspecialchars($items[0]['meta']['title'], ENT_QUOTES, 'UTF-8') : 'Special contract' ?></h1>
  <?php if ($only === ''): ?>
  <p class="lead">Each special agreement has one sign link. The same link can be edited after it is signed. More agreements can be added in this room later.</p>
  <?php endif; ?>
  <?php foreach ($items as $item): ?>
    <article class="item">
      <h2><?= htmlspecialchars($item['meta']['title'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p><?= htmlspecialchars($item['meta']['note'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php if (!empty($item['many'])): ?>
        <form class="issue" method="post">
          <input type="hidden" name="issue" value="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit">Create another sign link</button>
        </form>
      <?php endif; ?>
      <?php if ($item['rows'] === []): ?>
        <p class="err"><?= !empty($item['many']) ? 'No sign links yet. Create the first one.' : 'The sign link could not be created. Open this page again.' ?></p>
      <?php endif; ?>
      <?php foreach ($item['rows'] as $index => $row): ?>
        <?php
          $url = pcvc_service_agreement_public_url((string) $row['contract_token']);
          $fieldId = htmlspecialchars($item['key'] . '-' . (int) $row['id'], ENT_QUOTES, 'UTF-8');
          $label = !empty($item['many'])
              ? 'Sign link ' . (count($item['rows']) - $index)
              : 'This is the one sign link. Share it, and the same link can still be edited after signing.';
        ?>
        <div class="link-row">
          <p><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
          <input id="link-<?= $fieldId ?>" readonly value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('link-<?= $fieldId ?>').value)">Copy link</button>
          <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open</a>
          <?php if (($row['status'] ?? '') === 'signed'): ?>
            <a class="btn" href="admin-download-service-agreement.php?id=<?= (int) $row['id'] ?>">Download PDF</a>
          <?php endif; ?>
          <p>Status: <?= htmlspecialchars((string) ($row['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($row['created_at'])): ?> · <?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
        </div>
      <?php endforeach; ?>
    </article>
  <?php endforeach; ?>
  <?php if ($only === ''): ?>
  <div class="room">This room is ready for the next special contract. It will get its own single link, and that link will stay editable after signing.</div>
  <?php endif; ?>
</div>
</body>
</html>

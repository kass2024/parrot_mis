<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/weekly_advert.php';

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    header('Location: admin-login.php');
    exit;
}
weekly_advert_ensure_schema($conn);
if (empty($_SESSION['weekly_advert_token'])) {
    $_SESSION['weekly_advert_token'] = bin2hex(random_bytes(16));
}
$token = (string) $_SESSION['weekly_advert_token'];
$error = '';
$notice = '';
$current = null;
$editCode = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['edit'] ?? $_POST['return_set'] ?? '')) ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'add') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    try {
        if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
            throw new RuntimeException('The page expired. Refresh and try again.');
        }
        $set = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['set'] ?? '')) ?? '';
        if ($set === '') {
            $set = substr(bin2hex(random_bytes(6)), 0, 10);
        }
        $already = 0;
        $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM weekly_adverts WHERE set_code = ?');
        if ($countStmt) {
            $countStmt->bind_param('s', $set);
            $countStmt->execute();
            $already = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();
        }
        if ($already >= 30) {
            throw new RuntimeException('This advert already has 30 flyers.');
        }
        $join = $conn->prepare("UPDATE weekly_adverts SET set_code = ? WHERE code = ? AND (set_code IS NULL OR set_code = '')");
        if ($join) {
            $join->bind_param('ss', $set, $set);
            $join->execute();
            $join->close();
        }
        $stored = weekly_advert_store_upload($_FILES['flyer'] ?? []);
        $caption = weekly_advert_caption_from_flyer($stored['absolute'], $stored['mime']);
        $code = substr(bin2hex(random_bytes(6)), 0, 10);
        $stmt = $conn->prepare('INSERT INTO weekly_adverts (code, set_code, caption, flyer_file, preview_file, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Could not save the advert.');
        }
        $stmt->bind_param('sssssi', $code, $set, $caption, $stored['flyer'], $stored['preview'], $adminId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save the advert.');
        }
        $stmt->close();
        $count = $already + 1;
        echo json_encode([
            'ok' => true,
            'set' => $set,
            'count' => $count,
            'share' => weekly_advert_share_url($set),
            'image' => weekly_advert_image_url($stored['preview']),
            'name' => (string) ($_FILES['flyer']['name'] ?? 'flyer'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        $error = 'The page expired. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $stored = weekly_advert_store_upload($_FILES['flyer'] ?? []);
                $caption = weekly_advert_caption_from_flyer($stored['absolute'], $stored['mime']);
                $code = substr(bin2hex(random_bytes(6)), 0, 10);
                $stmt = $conn->prepare('INSERT INTO weekly_adverts (code, caption, flyer_file, preview_file, created_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssi', $code, $caption, $stored['flyer'], $stored['preview'], $adminId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not save the advert.');
                }
                $stmt->close();
                $current = weekly_advert_find($conn, $code);
                $notice = 'The advert is ready. Copy the link into the group.';
            } elseif ($action === 'update') {
                $code = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['code'] ?? '')) ?? '';
                $caption = trim((string) ($_POST['caption'] ?? ''));
                if ($caption === '') {
                    throw new RuntimeException('The advert text cannot be empty.');
                }
                $stmt = $conn->prepare('UPDATE weekly_adverts SET caption = ? WHERE code = ?');
                $stmt->bind_param('ss', $caption, $code);
                $stmt->execute();
                $stmt->close();
                $current = weekly_advert_find($conn, $code);
                $notice = 'The text was updated. The same link now shows the new wording.';
            } elseif ($action === 'remove_flyer') {
                $code = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['code'] ?? '')) ?? '';
                $row = weekly_advert_find($conn, $code);
                if ($row === null) {
                    throw new RuntimeException('That flyer is no longer in this advert.');
                }
                foreach (['flyer_file', 'preview_file'] as $col) {
                    $rel = str_replace('\\', '/', (string) $row[$col]);
                    if (str_starts_with($rel, 'weekly-adverts/') && !str_contains($rel, '..')) {
                        $path = __DIR__ . '/' . $rel;
                        if (is_file($path)) {
                            @unlink($path);
                        }
                    }
                }
                $stmt = $conn->prepare('DELETE FROM weekly_adverts WHERE code = ? LIMIT 1');
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $stmt->close();
                $notice = 'That flyer was removed. The link still opens the others.';
            } elseif ($action === 'delete') {
                $code = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['code'] ?? '')) ?? '';
                $bundle = weekly_advert_bundle($conn, $code);
                foreach ($bundle as $row) {
                    foreach (['flyer_file', 'preview_file'] as $col) {
                        $rel = str_replace('\\', '/', (string) $row[$col]);
                        if (str_starts_with($rel, 'weekly-adverts/') && !str_contains($rel, '..')) {
                            $path = __DIR__ . '/' . $rel;
                            if (is_file($path)) {
                                @unlink($path);
                            }
                        }
                    }
                }
                $stmt = $conn->prepare('DELETE FROM weekly_adverts WHERE set_code = ? OR code = ?');
                $stmt->bind_param('ss', $code, $code);
                $stmt->execute();
                $stmt->close();
                $notice = 'Advert removed.';
                if ($editCode === $code) {
                    $editCode = '';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if ($editCode === '' && $current !== null) {
    $editCode = (string) ($current['set_code'] ?: $current['code']);
}
$editing = $editCode !== '' ? weekly_advert_bundle($conn, $editCode) : [];
if ($editCode !== '' && $editing === [] && $error === '') {
    $error = 'That advert was not found.';
    $editCode = '';
}
if ($editing !== []) {
    $editCode = (string) (($editing[0]['set_code'] ?? '') !== '' && ($editing[0]['set_code'] ?? '') !== null
        ? $editing[0]['set_code']
        : $editing[0]['code']);
}
if (isset($_GET['saved']) && $notice === '' && $editing !== []) {
    $notice = 'The new flyers were added. The same link still opens all of them.';
}
$list = $conn->query("SELECT COALESCE(NULLIF(set_code, ''), code) AS share_code, COUNT(*) AS total, SUBSTRING_INDEX(GROUP_CONCAT(preview_file ORDER BY id SEPARATOR '||'), '||', 1) AS preview_file, SUBSTRING_INDEX(GROUP_CONCAT(caption ORDER BY id SEPARATOR '||'), '||', 1) AS caption, MAX(created_at) AS created_at FROM weekly_adverts GROUP BY COALESCE(NULLIF(set_code, ''), code) ORDER BY MAX(id) DESC LIMIT 12");
$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekly advert</title>
<style>
:root { --green:#1b4332; --ink:#142018; --muted:#5c6b63; --line:#d7e0da; }
* { box-sizing: border-box; }
body { margin: 0; background: #f3f6f4; color: var(--ink); font-family: "Segoe UI", system-ui, sans-serif; }
.wrap { max-width: 880px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
.card { background: #fff; border: 1px solid #e4ebe6; border-radius: 16px; padding: 1.15rem 1.2rem 1.25rem; margin-bottom: 1rem; box-shadow: 0 8px 24px rgba(18,32,24,.04); }
h1 { margin: 0 0 .35rem; font-size: 1.35rem; color: var(--green); }
h2 { margin: 0 0 .7rem; font-size: 1.02rem; color: var(--green); }
p { color: var(--muted); line-height: 1.45; }
.drop { border: 1.5px dashed #b7c7bd; border-radius: 14px; padding: 1rem; background: #fbfcfb; }
input[type="file"] { display: block; width: 100%; margin-top: .45rem; font-size: .95rem; }
button, .btn { border: 0; border-radius: 10px; min-height: 44px; padding: 0 14px; font-weight: 700; cursor: pointer; font-size: .95rem; }
.primary { background: var(--green); color: #fff; }
.ghost { background: #fff; color: var(--green); border: 1.5px solid var(--green); }
.row { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .8rem; }
textarea { width: 100%; min-height: 320px; border: 1.5px solid var(--line); border-radius: 12px; padding: .8rem .85rem; font: 500 15px/1.45 "Segoe UI", system-ui, sans-serif; color: var(--ink); }
textarea:focus, input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 4px rgba(27,67,50,.12); }
.linkbox { display: block; background: #f6faf7; border-radius: 10px; padding: .75rem .8rem; word-break: break-all; overflow-wrap: anywhere; font-weight: 650; color: var(--green); text-decoration: none; }
.flyer-preview { display: block; width: min(100%, 360px); height: auto; border-radius: 12px; margin: 0 0 .8rem; }
.err { background: #fef2f2; color: #991b1b; border-radius: 10px; padding: .75rem .85rem; }
.ok { background: #f3faf5; color: #14532d; border-radius: 10px; padding: .75rem .85rem; }
.item { display: grid; grid-template-columns: 72px 1fr; gap: .75rem; align-items: center; padding: .7rem 0; border-top: 1px solid #e7eee9; }
.item img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; background: #eee; }
.item small { color: var(--muted); }
.progress { margin-top: 1rem; }
.progress-top { display: flex; justify-content: space-between; gap: .75rem; font-weight: 700; color: var(--ink); }
.bar { height: 14px; margin-top: .45rem; background: #e7eee9; border-radius: 999px; overflow: hidden; }
.bar-fill { height: 100%; width: 0; border-radius: inherit; background: linear-gradient(90deg, #143326, #2d6a4f); transition: width .35s ease; position: relative; }
.bar-fill.waiting::after { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,.45), transparent); animation: shine 1.1s linear infinite; }
@keyframes shine { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.file-list { list-style: none; margin: .8rem 0 0; padding: 0; }
.file-list li { display: flex; justify-content: space-between; gap: .6rem; padding: .4rem 0; border-top: 1px solid #e7eee9; font-size: .92rem; }
.file-list .done { color: #14532d; font-weight: 700; }
.file-list .fail { color: #991b1b; font-weight: 700; }
.file-list .work { color: var(--green); font-weight: 700; }
.editor-flyer { border-top: 1px solid #e7eee9; padding: 1rem 0 0; margin-top: .4rem; }
.editor-flyer img { display: block; width: min(100%, 240px); height: auto; border-radius: 12px; margin-bottom: .7rem; }
.editor-flyer textarea { min-height: 160px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1><?= $editing !== [] ? 'Add flyers to this advert' : 'Weekly advert' ?></h1>
    <p><?= $editing !== []
        ? 'Choose more flyers. They are added to the link you already shared. That link does not change.'
        : 'Upload one flyer or several. Gemini reads each image and writes its text. You get one link that opens all of them together.' ?></p>
    <?php if ($error !== ''): ?><div class="err"><?= $esc($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="ok"><?= $esc($notice) ?></div><?php endif; ?>
    <form id="advert-form" method="post" enctype="multipart/form-data" onsubmit="return false;">
      <input type="hidden" name="token" value="<?= $esc($token) ?>">
      <div class="drop">
        <strong>Advert flyers</strong>
        <p style="margin:.25rem 0 0">Add a flyer, then add the next one. They stay on one link. JPG, PNG, or WEBP, up to 30, 8 MB each.</p>
        <input id="flyers" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <div class="row"><button class="ghost" type="button" id="add-files">Add flyer</button></div>
        <p id="queue-count">No flyers yet.</p>
        <ol class="file-list" id="file-list"></ol>
      </div>
      <div class="row"><button class="primary" type="button" id="generate"><?= $editing !== [] ? 'Add these flyers to this advert' : 'Generate one link' ?></button></div>
      <div class="progress" id="progress" hidden>
        <div class="progress-top"><span id="progress-title">Preparing flyers</span><span id="progress-pct">0%</span></div>
        <div class="bar"><div class="bar-fill" id="bar-fill"></div></div>
        <p id="progress-detail"></p>
      </div>
    </form>
    <div id="result" hidden>
      <h2 style="margin-top:1rem">One link for every flyer</h2>
      <a class="linkbox" id="result-link" href="#" target="_blank" rel="noopener"></a>
      <div class="row"><button class="primary" type="button" id="copy-share">Copy link</button></div>
    </div>
  </div>
  <script>
  (function () {
    var token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
    var setCode = <?= json_encode($editing !== [] ? $editCode : '', JSON_UNESCAPED_UNICODE) ?>;
    var existingCount = <?= count($editing) ?>;
    var editingExisting = existingCount > 0;
    var button = document.getElementById('generate');
    var addButton = document.getElementById('add-files');
    var input = document.getElementById('flyers');
    var progress = document.getElementById('progress');
    var fill = document.getElementById('bar-fill');
    var title = document.getElementById('progress-title');
    var pct = document.getElementById('progress-pct');
    var detail = document.getElementById('progress-detail');
    var fileList = document.getElementById('file-list');
    var queueCount = document.getElementById('queue-count');
    var result = document.getElementById('result');
    var resultLink = document.getElementById('result-link');
    var queue = [];
    var running = false;
    var creepTimer = null;
    function setBar(value, label, moving) {
      var shown = Math.max(0, Math.min(100, Math.round(value)));
      fill.style.width = shown + '%';
      fill.classList.toggle('waiting', moving);
      pct.textContent = shown + '%';
      title.textContent = label;
    }
    function stopCreep() {
      if (creepTimer) {
        clearInterval(creepTimer);
        creepTimer = null;
      }
    }
    function startCreep(index) {
      stopCreep();
      var begun = Date.now();
      var tick = function () {
        var total = Math.max(queue.length, 1);
        var moved = Math.min(1, (Date.now() - begun) / 35000);
        var value = ((index + 0.18 + (0.75 * moved)) / total) * 100;
        setBar(value, 'Gemini is writing flyer ' + (index + 1) + ' of ' + total, true);
      };
      tick();
      creepTimer = setInterval(tick, 350);
    }
    function drawQueue() {
      var pending = queue.filter(function (item) { return item.state === 'waiting'; }).length;
      queueCount.textContent = queue.length
        ? queue.length + ' flyer' + (queue.length === 1 ? '' : 's') + ' on this link' + (pending ? ', ' + pending + ' not started' : '')
        : 'No flyers yet. Add one, then add the next.';
    }
    function addRow(item, index) {
      var row = document.createElement('li');
      row.innerHTML = '<span></span><span class="work">Waiting</span>';
      row.firstChild.textContent = item.file.name;
      if (item.state === 'done') row.lastChild.className = 'done';
      if (item.state === 'fail') row.lastChild.className = 'fail';
      row.lastChild.textContent = item.state === 'done' ? 'Done' : (item.state === 'fail' ? 'Failed' : 'Waiting');
      fileList.appendChild(row);
      item.row = row;
      item.index = index;
    }
    addButton.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      Array.prototype.forEach.call(input.files || [], function (file) {
        if (existingCount + queue.length >= 30) return;
        var item = { file: file, state: 'waiting' };
        queue.push(item);
        addRow(item, queue.length - 1);
      });
      input.value = '';
      drawQueue();
      if (running) return;
    });
    button.addEventListener('click', async function () {
      if (!queue.length) {
        alert('Add a flyer first. You can add another after that.');
        return;
      }
      if (running) return;
      running = true;
      button.disabled = true;
      progress.hidden = false;
      var saved = queue.filter(function (item) { return item.state === 'done'; }).length;
      var share = resultLink.textContent || '';
      for (var i = 0; i < queue.length; i++) {
        var item = queue[i];
        if (item.state === 'done') continue;
        item.state = 'reading';
        item.row.lastChild.textContent = 'Reading';
        item.row.lastChild.className = 'work';
        detail.textContent = item.file.name;
        startCreep(i);
        var body = new FormData();
        body.append('action', 'add');
        body.append('token', token);
        body.append('set', setCode);
        body.append('flyer', item.file, item.file.name);
        try {
          var response = await fetch('weekly-advert.php', { method: 'POST', body: body, credentials: 'same-origin' });
          var data = await response.json();
          if (!data.ok) throw new Error(data.error || 'Could not read this flyer.');
          setCode = data.set;
          share = data.share;
          saved++;
          item.state = 'done';
          item.row.lastChild.textContent = 'Done';
          item.row.lastChild.className = 'done';
        } catch (err) {
          item.state = 'fail';
          item.row.lastChild.textContent = err.message || 'Failed';
          item.row.lastChild.className = 'fail';
        }
        stopCreep();
        setBar((saved / queue.length) * 100, saved + ' of ' + queue.length + ' flyers ready', false);
      }
      running = false;
      button.disabled = false;
      drawQueue();
      if (!share) {
        detail.textContent = 'No flyer could be saved. Add the file again.';
        return;
      }
      result.hidden = false;
      resultLink.href = share;
      resultLink.textContent = share;
      var failed = queue.some(function (item) { return item.state === 'fail'; });
      if (editingExisting && !failed) {
        window.location = 'weekly-advert.php?edit=' + encodeURIComponent(setCode) + '&saved=1';
        return;
      }
      detail.textContent = 'One link contains the flyers. Add another flyer to put it on this same link.';
      button.textContent = 'Add the new flyers to this link';
    });
    document.getElementById('copy-share').addEventListener('click', function () {
      var value = resultLink.textContent;
      var done = function () { document.getElementById('copy-share').textContent = 'Copied'; };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done);
      }
    });
  })();
  </script>

  <?php if ($editing !== []):
    $share = weekly_advert_share_url($editCode);
  ?>
  <div class="card">
    <h2>Saved flyers</h2>
    <p>Change the text of any flyer, remove one, or add more above. The shared link stays the same.</p>
    <a class="linkbox" href="<?= $esc($share) ?>" target="_blank" rel="noopener"><?= $esc($share) ?></a>
    <?php foreach ($editing as $index => $flyer):
      $flyerCode = (string) $flyer['code'];
      $preview = (string) $flyer['preview_file'];
    ?>
    <div class="editor-flyer">
      <strong>Flyer <?= (int) $index + 1 ?></strong>
      <a href="<?= $esc($preview) ?>" target="_blank" rel="noopener"><img src="<?= $esc($preview) ?>" alt="Flyer <?= (int) $index + 1 ?>"></a>
      <form method="post">
        <input type="hidden" name="token" value="<?= $esc($token) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="code" value="<?= $esc($flyerCode) ?>">
        <input type="hidden" name="return_set" value="<?= $esc($editCode) ?>">
        <label for="caption-<?= $esc($flyerCode) ?>"><strong>Advert text</strong></label>
        <textarea id="caption-<?= $esc($flyerCode) ?>" name="caption"><?= $esc((string) $flyer['caption']) ?></textarea>
        <div class="row">
          <button class="ghost" type="submit">Save text</button>
        </div>
      </form>
      <?php if (count($editing) > 1): ?>
      <form method="post" onsubmit="return confirm('Remove this flyer from the advert?');">
        <input type="hidden" name="token" value="<?= $esc($token) ?>">
        <input type="hidden" name="action" value="remove_flyer">
        <input type="hidden" name="code" value="<?= $esc($flyerCode) ?>">
        <input type="hidden" name="return_set" value="<?= $esc($editCode) ?>">
        <div class="row"><button class="ghost" type="submit">Remove this flyer</button></div>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Recent adverts</h2>
    <?php if ($list && $list->num_rows > 0): ?>
      <?php while ($item = $list->fetch_assoc()):
        $itemUrl = weekly_advert_share_url((string) $item['share_code']);
        $preview = trim(preg_replace('/\s+/', ' ', (string) $item['caption']) ?? '');
        if (mb_strlen($preview) > 110) {
            $preview = mb_substr($preview, 0, 107) . '...';
        }
        $countLabel = ((int) $item['total'] === 1) ? '1 flyer' : ((int) $item['total'] . ' flyers');
      ?>
      <div class="item">
        <img src="<?= $esc((string) $item['preview_file']) ?>" alt="">
        <div>
          <div><?= $esc($countLabel) ?> — <?= $esc($preview) ?></div>
          <small><?= $esc($itemUrl) ?></small>
          <div class="row">
            <a class="btn ghost" style="display:inline-flex;align-items:center;text-decoration:none" href="weekly-advert.php?edit=<?= $esc((string) $item['share_code']) ?>">Edit</a>
            <a class="btn ghost" style="display:inline-flex;align-items:center;text-decoration:none" href="<?= $esc($itemUrl) ?>" target="_blank" rel="noopener">Open</a>
            <form method="post" onsubmit="return confirm('Remove this advert?');">
              <input type="hidden" name="token" value="<?= $esc($token) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="code" value="<?= $esc((string) $item['share_code']) ?>">
              <button class="ghost" type="submit">Remove</button>
            </form>
          </div>
        </div>
      </div>
      <?php endwhile; ?>
    <?php else: ?>
      <p>No weekly advert yet.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

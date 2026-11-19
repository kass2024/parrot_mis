<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/weekly_advert.php';
require_once __DIR__ . '/helpers/mail_smtp.php';
require_once __DIR__ . '/helpers/daily_work_report.php';
require_once __DIR__ . '/vendor/autoload.php';

if (!isset($_SESSION['id'], $_SESSION['role'])) {
    header('Location: admin-login.php');
    exit;
}

$adminId = (int) $_SESSION['id'];
if (empty($_SESSION['daily_report_csrf'])) {
    $_SESSION['daily_report_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string) $_SESSION['daily_report_csrf'];

daily_report_ensure_table($conn);

if (isset($_GET['download'])) {
    $reportId = (int) $_GET['download'];
    $stmt = $conn->prepare('SELECT admin_id, pdf_file, report_date FROM daily_work_reports WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $isOwner = $row && ((int) $row['admin_id'] === $adminId || pcvc_current_user_is_superadmin($conn));
    $file = $row ? basename((string) $row['pdf_file']) : '';
    $path = $file !== '' ? daily_report_private_dir() . '/' . $file : '';
    if (!$isOwner || !is_file($path)) {
        http_response_code(404);
        echo 'Report not found.';
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="daily-work-report-' . $row['report_date'] . '.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

function daily_report_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function daily_report_check_csrf(string $csrf): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals($csrf, $sent)) {
        daily_report_json(['ok' => false, 'message' => 'The page expired. Reload it and try again.']);
    }
}

$staff = daily_report_staff($conn, $adminId);
$jobs = daily_report_jobs($conn, $adminId);
$recipients = daily_report_recipients($conn, $adminId);
$dateLabel = date('l, j F Y');
$draft = daily_report_local_html($staff, $jobs, $dateLabel);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    daily_report_check_csrf($csrf);
    if ($action === 'draft') {
        try {
            $html = daily_report_gemini_html($staff, $jobs, $dateLabel);
            daily_report_json(['ok' => true, 'html' => $html]);
        } catch (Throwable $e) {
            daily_report_json(['ok' => false, 'message' => $e->getMessage(), 'html' => $draft]);
        }
    }
    if ($action === 'submit') {
        if (daily_report_signed_today($conn, $adminId)) {
            daily_report_json(['ok' => false, 'message' => 'You already signed today’s report. A second report for the same day is not allowed.']);
        }
        $body = daily_report_clean_html((string) ($_POST['body_html'] ?? ''));
        $signature = (string) ($_POST['signature'] ?? '');
        $plain = trim(html_entity_decode(strip_tags($body)));
        if (mb_strlen($plain) < 20) {
            daily_report_json(['ok' => false, 'message' => 'Write the report before you submit it.']);
        }
        if (mb_strlen($body) > 80000) {
            daily_report_json(['ok' => false, 'message' => 'The report is too long to save.']);
        }
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $signature) || strlen($signature) > 400000) {
            daily_report_json(['ok' => false, 'message' => 'Sign the report before you submit it.']);
        }
        $people = $recipients['people'];
        if ($people === []) {
            daily_report_json(['ok' => false, 'message' => 'No supervisor with an email could be read from your contract, so the report was not sent.']);
        }
        $file = 'report-' . $adminId . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $path = daily_report_private_dir() . '/' . $file;
        try {
            daily_report_pdf($path, $staff, $dateLabel, $body, $signature, $people);
        } catch (Throwable $e) {
            daily_report_json(['ok' => false, 'message' => $e->getMessage()]);
        }
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $recipientJson = json_encode($people, JSON_UNESCAPED_UNICODE);
        $contractLine = mb_substr($recipients['contract_line'], 0, 500);
        $status = 'saved';
        $stmt = $conn->prepare('INSERT INTO daily_work_reports
            (admin_id, report_date, body_html, signature_image, pdf_file, recipients_json, contract_line, mail_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssssss', $adminId, $today, $body, $signature, $file, $recipientJson, $contractLine, $status, $now);
        $stmt->execute();
        $reportId = (int) $stmt->insert_id;
        $stmt->close();

        $to = $people[0]['email'];
        $cc = [];
        for ($i = 1, $n = count($people); $i < $n; $i++) {
            $cc[] = $people[$i]['email'];
        }
        $subject = 'Daily work report — ' . $staff['name'] . ' — ' . date('j M Y');
        $mailHtml = '<p>Please find attached the daily work report for <strong>'
            . daily_report_h($staff['name']) . '</strong> for ' . daily_report_h($dateLabel) . '.</p>'
            . '<p>The staff member signed this report in the MIS. The PDF is the copy to keep.</p>';
        $reply = filter_var($staff['email'], FILTER_VALIDATE_EMAIL) ? $staff['email'] : null;
        try {
            $sent = sendSMTPMailDetailed(
                $to,
                $subject,
                $mailHtml,
                [['path' => $path, 'name' => 'Daily-work-report-' . date('Y-m-d') . '.pdf']],
                [],
                null,
                $cc,
                $reply
            );
        } catch (Throwable $e) {
            $sent = ['ok' => false, 'error' => 'Email was not sent'];
        }
        $mailStatus = !empty($sent['ok']) ? 'sent' : 'saved';
        $mailError = !empty($sent['ok']) ? null : mb_substr((string) ($sent['error'] ?? 'Email was not sent'), 0, 280);
        $upd = $conn->prepare('UPDATE daily_work_reports SET mail_status = ?, mail_error = ? WHERE id = ?');
        $upd->bind_param('ssi', $mailStatus, $mailError, $reportId);
        $upd->execute();
        $upd->close();
        $names = array_map(static fn (array $person): string => $person['name'] . ' <' . $person['email'] . '>', $people);
        daily_report_json([
            'ok' => true,
            'mailed' => $mailStatus === 'sent',
            'download' => 'daily-work-report.php?download=' . $reportId,
            'recipients' => $names,
            'message' => $mailStatus === 'sent'
                ? 'The report was signed, saved as a PDF, and emailed.'
                : 'The PDF was saved. The email could not be sent, so download the PDF and send it if you still need to.',
        ]);
    }
    daily_report_json(['ok' => false, 'message' => 'Unknown action.']);
}

$signedToday = daily_report_signed_today($conn, $adminId);
$canViewAll = daily_report_can_view_all($conn, $adminId);
$history = daily_report_history($conn, $adminId, false);
$allReports = $canViewAll ? daily_report_history($conn, $adminId, true) : [];
$doneCount = 0;
foreach ($jobs as $job) {
    if ($job['status'] === 'completed') {
        $doneCount++;
    }
}
$openCount = count($jobs) - $doneCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report Daily work</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root { --green: #1b4332; --green-2: #2d6a4f; --paper: #fffdf8; --ink: #1d1a16; --line: #e4d8c4; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f3f0e8; color: var(--ink); font-family: "Segoe UI", sans-serif; }
  .wrap { max-width: 1080px; margin: 0 auto; padding: 22px 16px 48px; }
  h1 { margin: 0 0 4px; font-size: 28px; color: var(--green); }
  .lead { margin: 0 0 18px; color: #5c564c; }
  .grid { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px; }
  .card h2 { margin: 0 0 8px; font-size: 15px; color: var(--green); }
  .stats { display: flex; gap: 8px; margin-bottom: 10px; }
  .stat { flex: 1; background: #f6f3ec; border-radius: 10px; padding: 8px; }
  .stat b { display: block; font-size: 18px; color: var(--green); }
  .stat span { font-size: 12px; color: #6b645b; }
  .job { padding: 8px 0; border-top: 1px solid #eee; font-size: 13px; }
  .job strong { display: block; }
  .job em { color: #6b645b; font-style: normal; }
  .pill { display: inline-block; margin-top: 3px; font-size: 11px; border-radius: 99px; padding: 2px 7px; }
  .pill.done { background: #d8f3dc; color: #1b4332; }
  .pill.open { background: #fff3d6; color: #7a4e00; }
  .person { padding: 8px 0; border-top: 1px solid #eee; }
  .person b { display: block; }
  .person span { color: #5c564c; font-size: 13px; word-break: break-word; }
  .warn { background: #fff8e8; border: 1px solid #ecd9a2; color: #6a4b00; border-radius: 10px; padding: 8px 10px; font-size: 13px; }
  .paper-wrap { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 12px; }
  .toolbar { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
  button, .linkbtn { border: 0; border-radius: 9px; padding: 8px 12px; font: inherit; cursor: pointer; }
  .toolbar button { background: #f3f0e8; color: var(--ink); }
  .primary { background: var(--green); color: #fff; }
  .ghost { background: #fff; color: var(--green); border: 1px solid var(--green-2); }
  button:disabled { opacity: 0.55; cursor: wait; }
  .sheet { min-height: 520px; background: var(--paper); border: 1px solid #efe6d6; border-radius: 12px; padding: 28px 32px; outline: none; box-shadow: inset 0 0 0 1px rgba(255,255,255,.6); }
  .sheet h2 { margin: 0 0 8px; color: var(--green); text-align: center; }
  .sheet h3 { margin: 16px 0 6px; color: var(--green); }
  .sheet p, .sheet li { line-height: 1.45; }
  .sheet table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; }
  .sheet th, .sheet td { border: 1px solid #d9cbb6; padding: 6px; font-size: 13px; vertical-align: top; }
  .sheet th { background: #eef6f0; text-align: left; }
  .progress { height: 8px; background: #efe6d6; border-radius: 99px; overflow: hidden; margin: 8px 0; display: none; }
  .progress i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #2d6a4f, #95d5b2); }
  .sign-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; margin-top: 12px; align-items: end; }
  canvas { width: 100%; height: 140px; background: #fff; border: 1px dashed #b7c4b4; border-radius: 12px; touch-action: none; }
  .result { display: none; margin-top: 12px; padding: 12px; border-radius: 12px; background: #d8f3dc; color: #1b4332; }
  .history a { color: var(--green-2); }
  .history li { margin: 6px 0; }
  @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } .sheet { padding: 18px; } }
</style>
</head>
<body>
<div class="wrap">
  <h1>Report Daily work</h1>
  <p class="lead"><?= daily_report_h($staff['name']) ?> · <?= daily_report_h($dateLabel) ?>. Today’s job list fills the follow-up form. Edit any cell, then sign and send it.</p>
  <div class="grid">
    <div>
      <div class="card">
        <h2>Today’s job list</h2>
        <div class="stats">
          <div class="stat"><b><?= count($jobs) ?></b><span>Jobs</span></div>
          <div class="stat"><b><?= $doneCount ?></b><span>Completed</span></div>
          <div class="stat"><b><?= $openCount ?></b><span>Open</span></div>
        </div>
        <?php if ($jobs === []): ?>
          <p>No job was added to your list today. You can still write what you did.</p>
        <?php else: ?>
          <?php foreach ($jobs as $job): ?>
            <div class="job">
              <strong><?= daily_report_h($job['title'] !== '' ? $job['title'] : 'Untitled job') ?></strong>
              <em><?= daily_report_h(trim($job['applicant_name'] . ($job['job_type'] !== '' ? ' · ' . $job['job_type'] : ''))) ?></em>
              <span class="pill <?= $job['status'] === 'completed' ? 'done' : 'open' ?>"><?= $job['status'] === 'completed' ? 'Completed' : 'Open' ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="card" style="margin-top:16px">
        <h2>Report to</h2>
        <?php if (!$recipients['contract_found']): ?>
          <p class="warn">Your staff contract has no “Reports to” line, so this report cannot be emailed yet.</p>
        <?php else: ?>
          <p style="font-size:13px;color:#5c564c">From your contract: <?= daily_report_h($recipients['contract_line']) ?></p>
          <?php foreach ($recipients['people'] as $person): ?>
            <div class="person">
              <b><?= daily_report_h($person['name']) ?></b>
              <span><?= daily_report_h($person['email']) ?></span>
              <?php if ($person['position'] !== ''): ?><span><?= daily_report_h($person['position']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php foreach ($recipients['unmatched'] as $title): ?>
            <p class="warn"><?= daily_report_h($title) ?> is on your contract, and no staff profile uses that position, so that role is not emailed.</p>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="paper-wrap">
      <?php if ($signedToday): ?>
        <div class="result" style="display:block">You already signed today’s report. A second report for this day is not allowed.
          <a href="daily-work-report.php?download=<?= (int) $signedToday['id'] ?>">Open today’s PDF</a>
        </div>
      <?php else: ?>
      <div class="toolbar">
        <button type="button" class="primary" id="gemini"><i class="bi bi-arrow-clockwise"></i> Fill the form again</button>
        <button type="button" class="ghost" id="restore">Use the job list</button>
        <button type="button" data-cmd="bold"><b>B</b></button>
        <button type="button" data-cmd="italic"><i>I</i></button>
        <button type="button" data-cmd="insertUnorderedList">List</button>
        <button type="button" data-cmd="formatBlock" data-value="h3">Heading</button>
      </div>
      <div class="progress" id="progress"><i></i></div>
      <div class="sheet" id="sheet" contenteditable="true"><?= $draft ?></div>
      <div class="sign-row">
        <div>
          <h2 style="margin:0 0 6px;font-size:15px;color:#1b4332">Sign</h2>
          <canvas id="pad" width="640" height="140"></canvas>
          <button type="button" class="ghost" id="clear" style="margin-top:8px">Clear signature</button>
        </div>
        <button type="button" class="primary" id="submit" <?= $recipients['people'] === [] ? 'disabled' : '' ?>>Sign and submit</button>
      </div>
      <div class="result" id="result"></div>
      <?php endif; ?>
      <?php if ($history !== []): ?>
        <div class="history">
          <h2 style="font-size:15px;color:#1b4332">Your reports</h2>
          <ul>
            <?php foreach ($history as $row): ?>
              <li>
                <a href="daily-work-report.php?download=<?= (int) $row['id'] ?>"><?= daily_report_h((string) $row['report_date']) ?></a>
                · <?= $row['mail_status'] === 'sent' ? 'emailed' : 'saved' ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($canViewAll): ?>
        <div class="history">
          <h2 style="font-size:15px;color:#1b4332">All submitted reports</h2>
          <?php if ($allReports === []): ?>
            <p>No report has been signed yet.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($allReports as $row): ?>
                <li>
                  <a href="daily-work-report.php?download=<?= (int) $row['id'] ?>"><?= daily_report_h((string) ($row['staff_name'] ?? 'Staff')) ?> · <?= daily_report_h((string) $row['report_date']) ?></a>
                  · <?= $row['mail_status'] === 'sent' ? 'emailed' : 'saved' ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!$signedToday): ?>
<script>
const csrf = <?= json_encode($csrf) ?>;
const localDraft = <?= json_encode($draft, JSON_UNESCAPED_UNICODE) ?>;
const sheet = document.getElementById('sheet');
const progress = document.getElementById('progress');
const bar = progress.querySelector('i');
const geminiBtn = document.getElementById('gemini');
let creep = null;
function startBar() {
  progress.style.display = 'block';
  const begun = Date.now();
  clearInterval(creep);
  creep = setInterval(() => {
    const moved = Math.min(1, (Date.now() - begun) / 35000);
    bar.style.width = (8 + moved * 84) + '%';
  }, 350);
}
function stopBar() {
  clearInterval(creep);
  bar.style.width = '100%';
  setTimeout(() => { progress.style.display = 'none'; bar.style.width = '0'; }, 400);
}
document.querySelectorAll('[data-cmd]').forEach(btn => {
  btn.addEventListener('click', () => {
    const value = btn.getAttribute('data-value');
    document.execCommand(btn.getAttribute('data-cmd'), false, value || null);
    sheet.focus();
  });
});
let reportDirty = false;
sheet.addEventListener('input', () => { reportDirty = true; });
document.getElementById('restore').addEventListener('click', () => { reportDirty = false; sheet.innerHTML = localDraft; });
async function fillWithGemini(force) {
  geminiBtn.disabled = true;
  startBar();
  try {
    const body = new FormData();
    body.append('action', 'draft');
    body.append('csrf', csrf);
    const res = await fetch('daily-work-report.php', { method: 'POST', body });
    const data = await res.json();
    if (data.html && (force || !reportDirty)) sheet.innerHTML = data.html;
    if (!data.ok && data.message && force) alert(data.message);
  } catch (e) {
    if (force) alert('The form could not be filled. You can still edit this draft.');
  }
  stopBar();
  geminiBtn.disabled = false;
}
geminiBtn.addEventListener('click', () => fillWithGemini(true));
fillWithGemini(false);
const canvas = document.getElementById('pad');
const ctx = canvas.getContext('2d');
function fitCanvas() {
  const rect = canvas.getBoundingClientRect();
  const ink = canvas.toDataURL();
  canvas.width = Math.max(320, Math.floor(rect.width));
  canvas.height = 140;
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#1b4332';
  if (ink && ink.length > 80) {
    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    img.src = ink;
  }
}
fitCanvas();
let drawing = false;
let signed = false;
function point(e) {
  const r = canvas.getBoundingClientRect();
  const src = e.touches ? e.touches[0] : e;
  return { x: src.clientX - r.left, y: src.clientY - r.top };
}
function down(e) { drawing = true; signed = true; const p = point(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
function move(e) { if (!drawing) return; const p = point(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
function up() { drawing = false; }
canvas.addEventListener('mousedown', down);
canvas.addEventListener('mousemove', move);
window.addEventListener('mouseup', up);
canvas.addEventListener('touchstart', down, { passive: false });
canvas.addEventListener('touchmove', move, { passive: false });
canvas.addEventListener('touchend', up);
document.getElementById('clear').addEventListener('click', () => { signed = false; ctx.clearRect(0, 0, canvas.width, canvas.height); });
document.getElementById('submit').addEventListener('click', async () => {
  if (!signed) { alert('Sign in the box before you submit.'); return; }
  const btn = document.getElementById('submit');
  btn.disabled = true;
  const body = new FormData();
  body.append('action', 'submit');
  body.append('csrf', csrf);
  body.append('body_html', sheet.innerHTML);
  body.append('signature', canvas.toDataURL('image/png'));
  try {
    const res = await fetch('daily-work-report.php', { method: 'POST', body });
    const data = await res.json();
    const box = document.getElementById('result');
    box.style.display = 'block';
    if (!data.ok) {
      box.style.background = '#fff3d6';
      box.textContent = data.message || 'The report was not saved.';
      btn.disabled = false;
      return;
    }
    const who = (data.recipients || []).join(', ');
    box.innerHTML = data.message + ' This day is now closed. ' + (who ? 'Sent to ' + who + '. ' : '')
      + '<a href="' + data.download + '">Download the PDF</a>';
    setTimeout(() => window.location.reload(), 1200);
  } catch (e) {
    alert('The report could not be submitted. Try again.');
    btn.disabled = false;
  }
});
</script>
<?php endif; ?>
</body>
</html>

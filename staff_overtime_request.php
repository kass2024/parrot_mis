<?php
session_start();
require_once __DIR__ . '/db.php';

$staffId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
if ($staffId <= 0) {
    header('Location: admin-login.php');
    exit;
}

$today = (new DateTime('now', new DateTimeZone('America/Toronto')))->format('Y-m-d');

$stmt = $conn->prepare("
    SELECT
        o.id,
        o.request_date,
        o.reason,
        o.status,
        o.expected_hours,
        o.created_at,
        p.amount AS paid_amount,
        p.currency AS paid_currency
    FROM overtime_requests o
    LEFT JOIN overtime_payments p ON p.overtime_request_id = o.id
    WHERE o.staff_id = ?
    ORDER BY o.created_at DESC
");
$stmt->bind_param('i', $staffId);
$stmt->execute();
$history = $stmt->get_result();

$policy = [
    [
        'title' => '1. Rules and Conditions',
        'items' => [
            'All overtime requests must receive prior management approval.',
            'Each overtime request must be between 3 and 4 hours.',
            'Record accurate starting and finishing times.',
            'Personal activities are not permitted during overtime.',
        ],
    ],
    [
        'title' => '2. Important Notes',
        'items' => [
            'All requests are subject to management review and approval.',
            'Submit completed work to management for review.',
            'Provide evidence of completed tasks.',
            'Explain any unfinished assignments in the final report.',
        ],
    ],
    [
        'title' => '3. Performance and Accountability',
        'lead' => 'Every staff member is expected to demonstrate:',
        'points' => [
            ['Productivity', 'Complete assigned tasks efficiently.'],
            ['Responsibility', 'Respect approved working hours and deadlines.'],
            ['Transparency', 'Provide evidence of completed work.'],
            ['Results', 'Deliver work that supports company objectives.'],
        ],
    ],
    [
        'title' => '4. Final Reminder',
        'items' => [
            'Overtime is an additional responsibility that must deliver measurable results. Every approved request must demonstrate productivity, professionalism, and accountability while contributing to the company\'s growth and success.',
            'Management reserves the right to review, approve, or reject overtime requests based on business needs and company policy.',
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Overtime Request</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f3f6f4; color: #142033; font-family: Manrope, "Segoe UI", system-ui, sans-serif; }
.wrap { max-width: 860px; margin: 0 auto; padding: 22px 16px 48px; }
.hero { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 18px; }
.hero h1 { margin: 0; font-size: 28px; letter-spacing: -0.04em; }
.hero p { margin: 4px 0 0; color: #64748b; font-weight: 600; }
.btn { border: 0; border-radius: 12px; padding: 12px 18px; font: 800 14px Manrope, sans-serif; cursor: pointer; }
.btn-green { background: #16a34a; color: #fff; }
.btn-green:disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; }
.card { background: #fff; border: 1px solid #e7eeea; border-radius: 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
.filters { display: flex; gap: 8px; flex-wrap: wrap; }
.filters button { background: #fff; border: 1px solid #e5eee8; border-radius: 999px; padding: 7px 12px; font: 800 13px Manrope, sans-serif; color: #334155; cursor: pointer; }
.filters button.on { background: #16a34a; border-color: #16a34a; color: #fff; }
.section-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin: 22px 0 12px; }
.section-head h2 { margin: 0; font-size: 18px; }
.row { padding: 16px; margin-bottom: 10px; }
.row-top { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
.date { font-weight: 800; }
.pill { border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 800; background: #e2e8f0; color: #334155; }
.pill.approved, .pill.paid { background: #dcfce7; color: #166534; }
.pill.pending { background: #fef3c7; color: #92400e; }
.pill.rejected { background: #fee2e2; color: #991b1b; }
.meta { color: #64748b; font-size: 13px; font-weight: 700; margin: 8px 0; }
.tasks { background: #f8faf9; border-left: 4px solid #16a34a; border-radius: 10px; padding: 12px; white-space: pre-line; font-weight: 600; }
.empty { text-align: center; color: #64748b; padding: 28px; font-weight: 700; }
.modal { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 20; }
.modal.open { display: flex; }
.sheet { width: min(720px, 100%); max-height: min(86vh, 820px); overflow: auto; background: #fff; border-radius: 20px; padding: 22px; }
.sheet h2 { margin: 0 0 4px; font-size: 24px; letter-spacing: -0.03em; }
.sheet .sub { margin: 0 0 14px; color: #64748b; font-weight: 600; }
.policy { max-height: 46vh; overflow: auto; border: 1px solid #e7eeea; border-radius: 14px; padding: 8px 16px 16px; background: #fbfdfb; }
.policy h3 { margin: 16px 0 8px; font-size: 16px; }
.policy li { margin: 6px 0; font-weight: 600; line-height: 1.45; }
.policy p { font-weight: 600; line-height: 1.5; }
.accept-row { display: flex; justify-content: flex-end; margin-top: 14px; }
.hint { color: #64748b; font-size: 13px; font-weight: 700; margin-right: auto; align-self: center; }
label { display: block; font-size: 13px; font-weight: 800; margin: 12px 0 6px; }
input, select, textarea { width: 100%; border: 1px solid #d7e3dc; border-radius: 12px; padding: 12px; font: 600 15px Manrope, sans-serif; }
.task-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.task-row span { width: 22px; font-weight: 800; color: #15803d; }
[hidden] { display: none !important; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div>
      <h1>Overtime</h1>
      <p>Read the policy, accept it, then send your request.</p>
    </div>
    <button class="btn btn-green" type="button" id="open-request">New request</button>
  </div>

  <div class="section-head">
    <h2>My overtime history</h2>
    <div class="filters">
      <button type="button" data-filter="today">Today</button>
      <button type="button" data-filter="week">This week</button>
      <button type="button" data-filter="month">This month</button>
      <button type="button" class="on" data-filter="all">All</button>
    </div>
  </div>

  <?php if ($history->num_rows === 0): ?>
    <div class="card empty">No overtime requests yet.</div>
  <?php endif; ?>
  <?php while ($row = $history->fetch_assoc()): ?>
    <?php
      $status = (string) $row['status'];
      $paid = $row['paid_amount'] !== null;
      $pill = $paid ? 'paid' : $status;
      $label = $paid
        ? 'Paid ' . $row['paid_currency'] . ' ' . number_format((float) $row['paid_amount'], 2)
        : ucfirst($status);
    ?>
    <article class="card row" data-date="<?= htmlspecialchars(substr((string) $row['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>">
      <div class="row-top">
        <div class="date"><?= htmlspecialchars((string) $row['request_date'], ENT_QUOTES, 'UTF-8') ?></div>
        <span class="pill <?= htmlspecialchars($pill, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="meta">
        <?= $row['expected_hours'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $row['expected_hours'], 1, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') . ' hours · ' : '' ?>
        Sent <?= htmlspecialchars(date('M j, Y', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="tasks"><?= htmlspecialchars((string) $row['reason'], ENT_QUOTES, 'UTF-8') ?></div>
    </article>
  <?php endwhile; ?>
</div>

<div class="modal" id="request-modal">
  <div class="sheet">
    <section id="policy-step">
      <h2>Overtime Work Policy</h2>
      <p class="sub">Rules, conditions, and accountability. Read to the end, then accept.</p>
      <div class="policy" id="policy-scroll">
        <?php foreach ($policy as $section): ?>
          <h3><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h3>
          <?php if (!empty($section['lead'])): ?><p><?= htmlspecialchars($section['lead'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <?php if (!empty($section['items'])): ?>
            <ul>
              <?php foreach ($section['items'] as $item): ?>
                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!empty($section['points'])): ?>
            <ol>
              <?php foreach ($section['points'] as $point): ?>
                <li><strong><?= htmlspecialchars($point[0], ENT_QUOTES, 'UTF-8') ?>.</strong> <?= htmlspecialchars($point[1], ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="accept-row">
        <span class="hint" id="policy-hint">Read to the end to accept.</span>
        <button class="btn btn-green" type="button" id="accept-policy" disabled>Accept</button>
      </div>
    </section>

    <section id="form-step" hidden>
      <h2>New overtime request</h2>
      <p class="sub">The overtime policy is accepted for this request.</p>
      <form id="ot-form">
        <input type="hidden" name="policy_accepted" value="1">
        <input type="hidden" name="reason" id="reason">
        <label>Date</label>
        <input type="date" name="date" required value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
        <label>Hours</label>
        <select name="hours" required>
          <option value="">Select 3 to 4 hours</option>
          <option value="3">3 hours</option>
          <option value="3.5">3.5 hours</option>
          <option value="4">4 hours</option>
        </select>
        <label>Tasks</label>
        <div id="task-list">
          <div class="task-row"><span>1.</span><input class="task-field" placeholder="Describe the task" required></div>
        </div>
        <div class="accept-row">
          <button class="btn btn-green" type="submit" id="submit-request">Submit request</button>
        </div>
      </form>
    </section>
  </div>
</div>

<script>
const modal = document.getElementById('request-modal');
const policyBox = document.getElementById('policy-scroll');
const acceptBtn = document.getElementById('accept-policy');
const hint = document.getElementById('policy-hint');

function policyReady() {
  const atEnd = policyBox.scrollTop + policyBox.clientHeight >= policyBox.scrollHeight - 12;
  acceptBtn.disabled = !atEnd;
  hint.textContent = atEnd ? 'You can accept the policy.' : 'Read to the end to accept.';
}

document.getElementById('open-request').addEventListener('click', () => {
  document.getElementById('policy-step').hidden = false;
  document.getElementById('form-step').hidden = true;
  modal.classList.add('open');
  policyBox.scrollTop = 0;
  policyReady();
});

policyBox.addEventListener('scroll', policyReady);
acceptBtn.addEventListener('click', () => {
  if (acceptBtn.disabled) return;
  document.getElementById('policy-step').hidden = true;
  document.getElementById('form-step').hidden = false;
});

modal.addEventListener('click', (event) => {
  if (event.target === modal) modal.classList.remove('open');
});

document.querySelectorAll('[data-filter]').forEach((button) => {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-filter]').forEach((item) => item.classList.remove('on'));
    button.classList.add('on');
    const type = button.dataset.filter;
    const today = new Date();
    document.querySelectorAll('.row').forEach((row) => {
      const date = new Date(row.dataset.date + 'T00:00:00');
      let show = true;
      if (type === 'today') show = date.toDateString() === today.toDateString();
      if (type === 'week') {
        const start = new Date(today);
        start.setDate(today.getDate() - 6);
        show = date >= new Date(start.toDateString());
      }
      if (type === 'month') show = date.getMonth() === today.getMonth() && date.getFullYear() === today.getFullYear();
      row.hidden = !show;
    });
  });
});

const taskList = document.getElementById('task-list');
taskList.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter' || !event.target.classList.contains('task-field')) return;
  event.preventDefault();
  if (event.target.value.trim() === '') return;
  const row = document.createElement('div');
  row.className = 'task-row';
  row.innerHTML = '<span>' + (taskList.children.length + 1) + '.</span><input class="task-field" placeholder="Describe the task">';
  taskList.appendChild(row);
  row.querySelector('input').focus();
});

document.getElementById('ot-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const tasks = [];
  document.querySelectorAll('.task-field').forEach((field, index) => {
    if (field.value.trim()) tasks.push((index + 1) + '. ' + field.value.trim());
  });
  if (!tasks.length) return;
  document.getElementById('reason').value = tasks.join('\n');
  const button = document.getElementById('submit-request');
  button.disabled = true;
  button.textContent = 'Sending…';
  try {
    const response = await fetch('save_overtime_request.php', { method: 'POST', body: new FormData(event.target) });
    const data = await response.json();
    if (data.success) {
      location.reload();
      return;
    }
    button.disabled = false;
    button.textContent = 'Submit request';
    alert(data.message || 'The request could not be saved.');
  } catch (error) {
    button.disabled = false;
    button.textContent = 'Submit request';
    alert('The request could not be saved.');
  }
});
</script>
</body>
</html>

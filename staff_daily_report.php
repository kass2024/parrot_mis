<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/roster_staff.php';
require_once __DIR__ . '/helpers/staff_performance_review.php';
require_once __DIR__ . '/includes/company_branding.php';

date_default_timezone_set('Africa/Kigali');
set_time_limit(70);

$today = date('Y-m-d');
$date = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

$rows = pcvc_perf_attach_reviews($conn, pcvc_roster_daily_report($conn, $date), $date);
$pretty = (new DateTime($date))->format('l, j F Y');
$prev = (new DateTime($date))->modify('-1 day')->format('Y-m-d');
$next = (new DateTime($date))->modify('+1 day')->format('Y-m-d');

$linked = 0;
$clear = 0;
$openPeople = 0;
$money = 0;
$finishedToday = 0;
foreach ($rows as $row) {
    if ($row['linked']) {
        $linked++;
    }
    if ($row['list_clear']) {
        $clear++;
    } elseif ($row['linked']) {
        $openPeople++;
    }
    $money += (int) $row['salary'];
    $finishedToday += (int) $row['completed_today'];
}

function pcvc_report_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pcvc_report_clock(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);

    return $ts ? date('g:i A', $ts) : $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff performance</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
  --ink: #14222b;
  --teal: #0f3d4c;
  --sand: #f4efe6;
  --card: #fffdf9;
  --line: #e4d9c8;
  --good: #1f7a4d;
  --good-bg: #e7f6ee;
  --bad: #9f3a2f;
  --bad-bg: #fdeceb;
  --gold: #8a5a12;
  --muted: #6d645b;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  color: var(--ink);
  background:
    radial-gradient(900px 320px at 100% -10%, #d7ebe4 0%, transparent 60%),
    linear-gradient(180deg, #f7f3ec 0%, #efe6d8 100%);
  font-family: "Segoe UI", Candara, sans-serif;
}
.wrap { max-width: 1180px; margin: 0 auto; padding: 28px 20px 64px; }
.top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-end;
  margin-bottom: 22px;
}
h1 { margin: 0; font-size: 32px; letter-spacing: -0.04em; }
.eyebrow { margin: 0 0 6px; color: var(--teal); font-size: 13px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
.sub { margin: 6px 0 0; color: var(--muted); }
.back { color: var(--teal); font-weight: 700; text-decoration: none; }
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.stat {
  background: var(--teal);
  color: #f7f3ec;
  border-radius: 18px;
  padding: 16px 18px;
}
.stat b { display: block; font-size: 28px; letter-spacing: -0.04em; }
.stat span { color: #d5e4df; font-size: 13px; }
.stat.money { background: #1d4d3a; }
.bar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-bottom: 16px;
}
.bar a, .bar button {
  border: 1px solid var(--line);
  background: #fff;
  color: var(--ink);
  border-radius: 999px;
  padding: 8px 14px;
  text-decoration: none;
  font: inherit;
  cursor: pointer;
}
.bar a.on, .bar button.on { background: var(--ink); color: #fff; border-color: var(--ink); }
.bar input[type="date"], .bar input[type="search"] {
  border: 1px solid var(--line);
  background: #fff;
  border-radius: 999px;
  padding: 8px 14px;
  font: inherit;
}
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.card {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: 20px;
  padding: 16px 16px 12px;
  box-shadow: 0 10px 30px rgba(20, 34, 43, 0.04);
}
.card.clear { box-shadow: inset 4px 0 0 var(--good); }
.card.open { box-shadow: inset 4px 0 0 var(--bad); }
.who { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.name { font-size: 18px; font-weight: 750; letter-spacing: -0.03em; }
.role { color: var(--muted); font-size: 13px; margin-top: 2px; }
.badge {
  border-radius: 999px;
  padding: 6px 10px;
  font-size: 12px;
  font-weight: 750;
  white-space: nowrap;
}
.badge.clear { background: var(--good-bg); color: var(--good); }
.badge.open { background: var(--bad-bg); color: var(--bad); }
.badge.missing { background: #eee; color: #666; }
.badge.warn { background: #fff4df; color: var(--gold); }
.scores { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.score { border-radius: 14px; padding: 10px 12px; background: #fff; border: 1px solid var(--line); }
.score b { display: block; font-size: 26px; letter-spacing: -0.04em; }
.score span { color: var(--muted); font-size: 12px; }
.score small { display: block; margin-top: 4px; font-weight: 750; }
.score.good small { color: var(--good); }
.score.warn small { color: var(--gold); }
.score.bad small { color: var(--bad); }
.score.missing small { color: #666; }
.comment { margin: 8px 0 0; font-size: 14px; line-height: 1.45; }
.comment strong { color: var(--teal); }
.scale { margin: 0 0 16px; color: var(--muted); font-size: 13px; line-height: 1.5; }
.metrics { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 8px; margin: 14px 0 8px; }
.metric { background: var(--sand); border-radius: 14px; padding: 10px 12px; }
.metric b { display: block; font-size: 18px; }
.metric span { color: var(--muted); font-size: 12px; }
.track { height: 8px; background: #efe6d8; border-radius: 99px; overflow: hidden; margin: 8px 0 4px; }
.track i { display: block; height: 100%; background: var(--teal); border-radius: 99px; }
.meta { color: var(--muted); font-size: 13px; }
details { margin-top: 8px; }
summary { cursor: pointer; color: var(--teal); font-weight: 700; }
ul { margin: 8px 0 0; padding-left: 18px; }
li { margin: 4px 0; }
.empty { padding: 28px; text-align: center; color: var(--muted); }
@media (max-width: 860px) {
  .stats, .grid, .metrics, .scores { grid-template-columns: 1fr; }
  .top { flex-direction: column; align-items: flex-start; }
}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <p class="eyebrow"><?= pcvc_report_h(PCVC_COMPANY_DISPLAY_NAME) ?></p>
      <h1>Staff performance, <?= pcvc_report_h((new DateTime($date))->format('j M')) ?></h1>
      <p class="sub"><?= pcvc_report_h($pretty) ?>. Each note is spoken to that person: keep going, finish what is left, or tell me what got in the way.</p>
    </div>
  </div>
  <p class="scale">Full work: keep going. Close: finish what is left. About half: let us agree what you will finish next. Under half: let us plan how you catch up. Very little work: tell me what got in the way. This week is <?= pcvc_report_h($rows[0]['week_label'] ?? '') ?>.</p>

  <div class="stats">
    <div class="stat"><b><?= $clear ?></b><span>Completed the list</span></div>
    <div class="stat"><b><?= $openPeople ?></b><span>Still have open jobs</span></div>
    <div class="stat"><b><?= $finishedToday ?></b><span>Jobs finished this day</span></div>
    <div class="stat money"><b><?= pcvc_report_h(pcvc_roster_money($money)) ?></b><span>Money gained</span></div>
  </div>

  <form class="bar" method="get">
    <a href="?date=<?= pcvc_report_h($prev) ?>">Previous</a>
    <input type="date" name="date" value="<?= pcvc_report_h($date) ?>">
    <button type="submit">Show day</button>
    <a href="?date=<?= pcvc_report_h($next) ?>">Next</a>
    <a href="?date=<?= pcvc_report_h($today) ?>" class="<?= $date === $today ? 'on' : '' ?>">Today</a>
    <input id="finder" type="search" placeholder="Find a person" aria-label="Find a person">
    <button type="button" class="on" data-filter="all">All</button>
    <button type="button" data-filter="clear">Completed</button>
    <button type="button" data-filter="open">Not completed</button>
  </form>

  <div class="grid" id="board">
    <?php foreach ($rows as $row): ?>
      <?php
        $state = !$row['linked'] ? 'missing' : ($row['list_clear'] ? 'clear' : 'open');
        $pct = min(100, (int) $row['daily_percent']);
        $weekPctBar = min(100, (int) $row['weekly_percent']);
      ?>
      <article class="card <?= pcvc_report_h($state) ?>" data-state="<?= pcvc_report_h($state) ?>" data-name="<?= pcvc_report_h(strtolower($row['name'])) ?>">
        <div class="who">
          <div>
            <div class="name"><?= pcvc_report_h($row['name']) ?></div>
            <div class="role"><?= pcvc_report_h($row['position']) ?></div>
          </div>
          <span class="badge <?= pcvc_report_h($row['weekly_decision']['tone']) ?>"><?= pcvc_report_h($row['weekly_decision']['label']) ?></span>
        </div>
        <div class="metrics">
          <div class="metric"><b><?= pcvc_report_h($row['salary_label']) ?></b><span>Money this day</span></div>
          <div class="metric"><b><?= (int) $row['completed_today'] ?></b><span>Finished today</span></div>
          <div class="metric"><b><?= $row['linked'] ? (int) $row['open_jobs'] : '—' ?></b><span>Still open</span></div>
        </div>
        <div class="scores">
          <div class="score <?= pcvc_report_h($row['daily_decision']['tone']) ?>">
            <b><?= (int) $row['daily_percent'] ?>%</b>
            <span>Today · <?= (int) $row['completed_today'] ?> of <?= (int) $row['target'] ?></span>
            <small><?= pcvc_report_h($row['daily_decision']['label']) ?></small>
          </div>
          <div class="score <?= pcvc_report_h($row['weekly_decision']['tone']) ?>">
            <b><?= (int) $row['weekly_percent'] ?>%</b>
            <span>This week · <?= (int) $row['week_done'] ?> of <?= (int) $row['week_target'] ?></span>
            <small><?= pcvc_report_h($row['weekly_decision']['label']) ?></small>
          </div>
        </div>
        <div class="track" title="Today <?= (int) $row['daily_percent'] ?> percent"><i style="width: <?= $pct ?>%"></i></div>
        <div class="track" title="This week <?= (int) $row['weekly_percent'] ?> percent"><i style="width: <?= $weekPctBar ?>%; background:#8a5a12"></i></div>
        <p class="comment"><strong>Today.</strong> <?= pcvc_report_h($row['daily_comment']) ?></p>
        <p class="comment"><strong>This week.</strong> <?= pcvc_report_h($row['weekly_comment']) ?></p>
        <div class="meta">
          <?php if (!$row['linked']): ?>
            This name is on the contract list, but no staff login is linked yet.
          <?php else: ?>
            In <?= pcvc_report_h(pcvc_report_clock($row['check_in'])) ?>
            · Out <?= pcvc_report_h(pcvc_report_clock($row['check_out'])) ?>
            <?php if ($row['review_source'] === 'gemini'): ?> · Comment by Gemini<?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($row['jobs']): ?>
          <details>
            <summary>Work finished this day</summary>
            <ul>
              <?php foreach ($row['jobs'] as $job): ?>
                <li>
                  <strong><?= pcvc_report_h($job['title'] !== '' ? $job['title'] : 'Untitled job') ?></strong>
                  <?php if ($job['type'] !== ''): ?> <span class="meta">(<?= pcvc_report_h($job['type']) ?>)</span><?php endif; ?>
                </li>
              <?php endforeach; ?>
              <?php if ((int) $row['completed_today'] > count($row['jobs'])): ?>
                <li class="meta">And <?= (int) $row['completed_today'] - count($row['jobs']) ?> more.</li>
              <?php endif; ?>
            </ul>
          </details>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="empty" id="none" hidden>No one matches that filter.</p>
</div>
<script>
const finder = document.getElementById('finder');
const cards = [...document.querySelectorAll('.card')];
const none = document.getElementById('none');
let mode = 'all';
function paint() {
  const q = finder.value.trim().toLowerCase();
  let shown = 0;
  cards.forEach((card) => {
    const okMode = mode === 'all' || card.dataset.state === mode;
    const okName = q === '' || card.dataset.name.includes(q);
    const show = okMode && okName;
    card.hidden = !show;
    if (show) shown++;
  });
  none.hidden = shown !== 0;
}
finder.addEventListener('input', paint);
document.querySelectorAll('[data-filter]').forEach((btn) => {
  btn.addEventListener('click', () => {
    mode = btn.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach((b) => b.classList.toggle('on', b === btn));
    paint();
  });
});
</script>
</body>
</html>

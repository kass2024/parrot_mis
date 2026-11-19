<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/finance.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
if (!pcvc_current_user_can_access_finance($conn)) {
    http_response_code(403);
    exit('Income is available to management.');
}

finance_ensure_schema($conn);
$period = finance_period();
$range = (($_GET['range'] ?? 'month') === 'all') ? 'all' : 'month';
$rows = finance_income_rows($conn, $period['start'], $period['end'], $range === 'month');
$totals = finance_income_totals($rows);
$bySource = [];
foreach ($rows as $row) {
    $key = $row['source'] . '|' . $row['currency'];
    if (!isset($bySource[$key])) {
        $bySource[$key] = ['source' => $row['source'], 'currency' => $row['currency'], 'amount' => 0.0, 'count' => 0];
    }
    $bySource[$key]['amount'] = round($bySource[$key]['amount'] + $row['amount'], 2);
    $bySource[$key]['count']++;
}
uasort($bySource, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
$shown = array_slice($rows, 0, 80);
$invoiceCount = count($rows);
$scopeLabel = $range === 'month' ? $period['label'] : 'All time';

$sourceSlug = static function (string $source): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $source) ?? $source);

    return trim($slug, '-') ?: 'other';
};
$formatWhen = static function (string $paidAt): string {
    $paidAt = trim($paidAt);
    $when = DateTime::createFromFormat('Y-m-d H:i:s', $paidAt) ?: DateTime::createFromFormat('Y-m-d', substr($paidAt, 0, 10));

    return $when instanceof DateTime ? $when->format('d M Y') : substr($paidAt, 0, 10);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Finance income</title>
<style>
<?= finance_css() ?>
.income { max-width: 1080px; }
.income .top { align-items: center; margin-bottom: 18px; }
.income h1 { font-size: 30px; }
.seg { display: inline-flex; background: #fff; border: 1px solid #e5eee8; border-radius: 999px; padding: 4px; gap: 4px; }
.seg a { text-decoration: none; color: #475569; font-weight: 800; font-size: 13px; padding: 8px 14px; border-radius: 999px; }
.seg a.on { background: #14532d; color: #fff; }
.hero { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px; }
.money-card { position: relative; overflow: hidden; background: #fff; border: 1px solid #e7eeea; border-radius: 18px; padding: 18px 18px 16px; box-shadow: 0 8px 24px rgba(20, 83, 45, .05); }
.money-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background: #16a34a; }
.money-card[data-cur="USD"]::before { background: #2563eb; }
.money-card[data-cur="RWF"]::before { background: #d97706; }
.money-card[data-cur="EUR"]::before { background: #7c3aed; }
.money-card .code { font-size: 12px; font-weight: 800; letter-spacing: .08em; color: #64748b; }
.money-card strong { display: block; margin-top: 6px; font-size: 32px; letter-spacing: -0.04em; line-height: 1; }
.money-card span { display: block; margin-top: 8px; color: #64748b; font-size: 13px; font-weight: 700; }
.empty-card { display: flex; align-items: center; min-height: 92px; color: #64748b; font-weight: 700; }
.panel { background: #fff; border: 1px solid #e7eeea; border-radius: 18px; padding: 16px 16px 8px; box-shadow: 0 1px 2px rgba(15,23,42,.04); margin-bottom: 16px; }
.panel-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 8px; }
.panel-head h2 { margin: 0; font-size: 16px; letter-spacing: -0.02em; }
.panel-head p { margin: 2px 0 0; color: #64748b; font-size: 13px; font-weight: 600; }
.sources { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; margin: 8px 0 12px; }
.source { border: 1px solid #eef3f0; border-radius: 14px; padding: 12px 14px; background: #f8fbf9; }
.source .name { display: flex; justify-content: space-between; gap: 8px; align-items: center; font-weight: 800; }
.source .amt { margin-top: 8px; font-size: 20px; font-weight: 800; letter-spacing: -0.03em; }
.source .meta { color: #64748b; font-size: 12px; font-weight: 700; }
.chip { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 800; letter-spacing: .04em; background: #dcfce7; color: #166534; }
.chip.usd { background: #dbeafe; color: #1d4ed8; }
.chip.rwf { background: #ffedd5; color: #9a3412; }
.chip.eur { background: #ede9fe; color: #6d28d9; }
.tag { display: inline-flex; border-radius: 999px; padding: 4px 9px; font-size: 12px; font-weight: 800; background: #ecfdf3; color: #166534; }
.tag.canada-partner-fee { background: #ccfbf1; color: #0f766e; }
.tag.francophone-invoice { background: #e0e7ff; color: #3730a3; }
.tag.dubai-invoice { background: #ffedd5; color: #9a3412; }
.ledger { width: 100%; border-collapse: collapse; }
.ledger th { font-size: 11px; letter-spacing: .06em; padding: 8px 10px 10px; }
.ledger td { padding: 12px 10px; }
.ledger tbody tr:hover { background: #f8fbf9; }
.ledger .num, .ledger th.num { text-align: right; }
.ledger .ref { font-variant-numeric: tabular-nums; color: #334155; font-weight: 700; }
.ledger .when { color: #475569; font-weight: 700; white-space: nowrap; }
.find { width: 220px; border-radius: 999px; padding: 8px 12px; background: #f8fafc; }
.more { color: #64748b; font-size: 12px; font-weight: 700; padding: 4px 10px 12px; }
@media (max-width: 700px) {
  .income .top, .panel-head { align-items: stretch; }
  .find { width: 100%; }
  .money-card strong { font-size: 26px; }
}
</style>
</head>
<body>
<div class="wrap income">
  <div class="top">
    <div>
      <h1>Income</h1>
      <p class="sub"><?= (int) $invoiceCount ?> recorded invoice<?= $invoiceCount === 1 ? '' : 's' ?> · <?= finance_h($scopeLabel) ?></p>
    </div>
    <nav class="seg" aria-label="Period">
      <a class="<?= $range === 'month' ? 'on' : '' ?>" href="finance.php?range=month">This month</a>
      <a class="<?= $range === 'all' ? 'on' : '' ?>" href="finance.php?range=all">All time</a>
    </nav>
  </div>

  <div class="hero">
    <?php if ($totals === []): ?>
      <article class="money-card empty-card">No invoices in this view.</article>
    <?php endif; ?>
    <?php foreach ($totals as $currency => $amount): ?>
      <article class="money-card" data-cur="<?= finance_h((string) $currency) ?>">
        <div class="code"><?= finance_h((string) $currency) ?></div>
        <strong><?= finance_h(number_format((float) $amount, 2, '.', ',')) ?></strong>
        <span>Recorded income</span>
      </article>
    <?php endforeach; ?>
  </div>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>By source</h2>
        <p>Each currency stays on its own line.</p>
      </div>
    </div>
    <?php if ($bySource === []): ?>
      <p class="muted">No recorded invoices yet.</p>
    <?php else: ?>
      <div class="sources">
        <?php foreach ($bySource as $source):
            $cur = strtolower((string) $source['currency']);
            $count = (int) $source['count'];
        ?>
          <article class="source">
            <div class="name">
              <span class="tag <?= finance_h($sourceSlug((string) $source['source'])) ?>"><?= finance_h((string) $source['source']) ?></span>
              <span class="chip <?= finance_h($cur) ?>"><?= finance_h((string) $source['currency']) ?></span>
            </div>
            <div class="amt"><?= finance_h(number_format((float) $source['amount'], 2, '.', ',')) ?></div>
            <div class="meta"><?= $count ?> invoice<?= $count === 1 ? '' : 's' ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Invoices</h2>
        <p><?= $invoiceCount > 80 ? 'Latest 80 of ' . (int) $invoiceCount : 'Every recorded invoice in this view' ?></p>
      </div>
      <input class="find" id="find" type="search" placeholder="Search reference or source" <?= $shown === [] ? 'hidden' : '' ?>>
    </div>
    <table class="ledger">
      <thead><tr><th>Date</th><th>Source</th><th>Reference</th><th class="num">Amount</th></tr></thead>
      <tbody id="ledger">
      <?php foreach ($shown as $row):
          $cur = strtolower((string) $row['currency']);
      ?>
        <tr>
          <td class="when"><?= finance_h($formatWhen((string) $row['paid_at'])) ?></td>
          <td><span class="tag <?= finance_h($sourceSlug((string) $row['source'])) ?>"><?= finance_h((string) $row['source']) ?></span></td>
          <td class="ref"><?= finance_h((string) $row['ref']) ?></td>
          <td class="num"><span class="chip <?= finance_h($cur) ?>"><?= finance_h((string) $row['currency']) ?></span> <b><?= finance_h(number_format((float) $row['amount'], 2, '.', ',')) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="4">No recorded invoices yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <p class="more" id="none" hidden>No invoice matches that search.</p>
  </section>
</div>
<script>
const find = document.getElementById('find');
const ledger = document.getElementById('ledger');
const none = document.getElementById('none');
if (find && ledger) {
  find.addEventListener('input', () => {
    const q = find.value.trim().toLowerCase();
    let visible = 0;
    ledger.querySelectorAll('tr').forEach((row) => {
      const show = q === '' || row.textContent.toLowerCase().includes(q);
      row.hidden = !show;
      if (show) visible++;
    });
    if (none) none.hidden = visible !== 0;
  });
}
</script>
</body>
</html>

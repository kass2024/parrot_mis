<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/canada_partner_monitor.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

canada_partner_ensure_schema($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$today = (new DateTime('now', new DateTimeZone('America/Toronto')))->format('Y-m-d');
$error = '';
$notice = (string) ($_SESSION['canada_partner_notice'] ?? '');
unset($_SESSION['canada_partner_notice']);
$partnerId = (int) ($_GET['partner'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        try {
            $saved = canada_partner_save($conn, $_POST, (int) ($_POST['id'] ?? 0));
            $_SESSION['canada_partner_notice'] = 'The partner is saved.';
            header('Location: canada-partners.php?partner=' . $saved);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $editId = (int) ($_POST['id'] ?? 0);
        }
    }
}

$partners = canada_partner_partners($conn);
$clients = canada_partner_clients($conn, 0, '', '');
$byPartner = [];
foreach ($clients as $client) {
    $byPartner[(int) $client['partner_id']][] = $client;
}
$totals = canada_partner_totals($clients);
$visible = canada_partner_clients($conn, $partnerId, isset(canada_partner_statuses()[$status]) ? $status : '', $search);
$editing = $editId > 0 ? canada_partner_get($conn, $editId) : null;
if ($editing === null && $error !== '' && $editId > 0) {
    $editing = [
        'id' => $editId,
        'name' => (string) ($_POST['name'] ?? ''),
        'service_name' => (string) ($_POST['service_name'] ?? ''),
        'email' => (string) ($_POST['email'] ?? ''),
        'phone' => (string) ($_POST['phone'] ?? ''),
        'city' => (string) ($_POST['city'] ?? ''),
        'commission_type' => (string) ($_POST['commission_type'] ?? 'fixed'),
        'commission_value' => (string) ($_POST['commission_value'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
        'active' => !empty($_POST['active']) ? 1 : 0,
    ];
}
$followups = [];
foreach ($clients as $client) {
    $when = (string) ($client['next_followup'] ?? '');
    if ($when !== '' && $when <= $today) {
        $followups[] = $client;
    }
}
$scope = (($_GET['scope'] ?? 'active') === 'all') ? 'all' : 'active';
$period = (($_GET['period'] ?? 'month') === 'all') ? 'all' : 'month';
$tz = new DateTimeZone('America/Toronto');
$now = new DateTime('now', $tz);
$monthKey = $now->format('Y-m');
$lastMonthKey = (clone $now)->modify('first day of last month')->format('Y-m');
$createdThis = 0;
$createdLast = 0;
$activeCount = 0;
foreach ($partners as $partner) {
    if ((int) $partner['active'] === 1) {
        $activeCount++;
    }
    $stamp = substr((string) ($partner['created_at'] ?? ''), 0, 7);
    if ($stamp === $monthKey) {
        $createdThis++;
    }
    if ($stamp === $lastMonthKey) {
        $createdLast++;
    }
}
$partnerDelta = $createdThis - $createdLast;
$kpiClients = $clients;
if ($period === 'month') {
    $kpiClients = array_values(array_filter(
        $clients,
        static fn (array $client): bool => str_starts_with((string) ($client['created_at'] ?? ''), $monthKey)
    ));
}
$kpi = canada_partner_totals($kpiClients);
$needle = strtolower($search);
$listPartners = array_values(array_filter($partners, static function (array $partner) use ($scope, $needle): bool {
    if ($scope === 'active' && (int) $partner['active'] !== 1) {
        return false;
    }
    if ($needle === '') {
        return true;
    }
    $haystack = strtolower($partner['name'] . ' ' . $partner['service_name'] . ' ' . $partner['email'] . ' ' . $partner['phone']);

    return str_contains($haystack, $needle);
}));
$selectedPartner = $partnerId > 0 ? canada_partner_get($conn, $partnerId) : null;
$showClients = $partnerId > 0 || $search !== '' || $status !== '';
$serviceNow = (string) ($editing['service_name'] ?? '');
$serviceChoice = '';
if ($serviceNow === 'TCF Canada Training') {
    $serviceChoice = 'tcf';
} elseif ($serviceNow === 'Permanent Residence / Express Entry') {
    $serviceChoice = 'pr';
} elseif ($serviceNow !== '') {
    $serviceChoice = 'custom';
}
$shortStages = [
    'discussion' => 'Discussion',
    'accepted' => 'Accepted',
    'started' => 'Started',
    'paid' => 'Paid',
    'decision' => 'Cert. Received',
];
$initialsOf = static function (string $name): string {
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $words = array_values(array_filter($words, static fn (string $word): bool => !in_array(strtolower($word), ['and', 'of', 'the', '&'], true)));
    $first = (string) ($words[0] ?? 'P');
    if (strlen($first) <= 2) {
        return strtoupper($first);
    }
    $second = (string) ($words[1] ?? $first);

    return strtoupper(substr($first, 0, 1) . substr($second, 0, 1));
};
$keep = static function (array $extra = []) use ($search, $scope, $period, $partnerId, $status): string {
    $query = array_merge([
        'q' => $search,
        'scope' => $scope,
        'period' => $period,
        'partner' => $partnerId > 0 ? (string) $partnerId : '',
        'status' => $status,
    ], $extra);
    $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== null);

    return 'canada-partners.php' . ($query === [] ? '' : '?' . http_build_query($query));
};
$icon = static function (string $body): string {
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
};
$stageIcons = [
    'discussion' => $icon('<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>'),
    'accepted' => $icon('<path d="M5 3l14 9-14 9V3z"/>'),
    'started' => $icon('<circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>'),
    'paid' => $icon('<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>'),
    'decision' => $icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PARROT PartnerConnect</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap");
* { box-sizing: border-box; }
body { margin: 0; background: #f3f6f4; color: #142033; font-family: Manrope, "Segoe UI", system-ui, sans-serif; }
.pc { min-height: 100vh; }
.pc-main { padding: 16px 18px 36px; }
.pc-title { display: flex; justify-content: flex-end; margin: 0 0 14px; }
.pc-period { background: #fff; border: 1px solid #e5eee8; border-radius: 12px; padding: 8px 10px; font: 700 14px Manrope, sans-serif; }
.pc-stage { display: grid; grid-template-columns: minmax(0, 1fr) 292px; gap: 14px 16px; align-items: start; }
.pc-kpis, .pc-money, .pc-partners, .pc-form, .pc-clients { grid-column: 1; }
.pc-insights { grid-column: 2; grid-row: 2; }
.pc-pipeline { grid-column: 2; grid-row: 3; }
.pc-follow { grid-column: 2; grid-row: 4; }
.pc-kpis { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; }
.pc-kpi, .pc-card { background: #fff; border: 1px solid #e7eeea; border-radius: 16px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
.pc-kpi { padding: 14px 12px 12px; min-height: 108px; }
.pc-kpi.hot { background: #e9f9ef; border-color: #b7ebc9; }
.pc-kpi i, .pc-money i { width: 28px; height: 28px; display: grid; place-items: center; color: #16a34a; }
.pc-kpi b { display: block; margin-top: 8px; font-size: 28px; letter-spacing: -0.04em; line-height: 1; }
.pc-kpi span, .pc-money span { display: block; color: #64748b; font-size: 13px; font-weight: 700; margin-top: 4px; }
.pc-kpi em { display: block; color: #16a34a; font-style: normal; font-size: 12px; font-weight: 800; margin-top: 6px; }
.pc-money { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.pc-money article { display: flex; gap: 10px; align-items: center; padding: 14px; }
.pc-money article.hot { background: #e9f9ef; border-color: #b7ebc9; }
.pc-money b { display: block; font-size: 20px; letter-spacing: -0.03em; }
.pc-card { padding: 16px; }
.pc-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 12px; }
.pc-head h2 { margin: 0; font-size: 20px; letter-spacing: -0.03em; }
.pc-head p, .pc-sub { margin: 2px 0 0; color: #64748b; font-size: 13px; font-weight: 600; }
.pc-tools { display: flex; gap: 8px; align-items: center; }
.pc-pill { border: 1px solid #e5eee8; background: #fff; color: #334155; border-radius: 999px; padding: 8px 12px; font-weight: 800; font-size: 13px; text-decoration: none; }
.pc-pill.on { background: #16a34a; border-color: #16a34a; color: #fff; }
.pc-mini { display: flex; align-items: center; gap: 6px; background: #f8faf9; border: 1px solid #e5eee8; border-radius: 999px; padding: 7px 10px; }
.pc-mini input { border: 0; outline: none; background: transparent; width: 150px; font: 600 13px Manrope, sans-serif; }
.pc-person { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; gap: 14px; align-items: center; border: 1px solid #e7eeea; border-radius: 16px; padding: 12px; margin-top: 10px; }
.pc-avatar { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; background: #dcfce7; color: #166534; font-weight: 800; }
.pc-person h3 { margin: 0; font-size: 16px; }
.pc-person .meta { color: #64748b; font-size: 13px; font-weight: 600; }
.pc-counts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; color: #64748b; font-size: 12px; font-weight: 700; }
.pc-cash { text-align: right; font-size: 13px; font-weight: 700; color: #334155; line-height: 1.45; }
.pc-actions { display: flex; gap: 8px; align-items: center; }
.pc-btn, .pc-ghost, .pc-more summary { border-radius: 10px; font-weight: 800; font-size: 13px; font-family: inherit; text-decoration: none; cursor: pointer; }
.pc-btn { background: #16a34a; color: #fff; border: 0; padding: 9px 12px; display: inline-flex; align-items: center; gap: 6px; }
.pc-ghost { background: #fff; color: #334155; border: 1px solid #e5eee8; padding: 9px 12px; display: inline-flex; align-items: center; gap: 6px; }
.pc-more { position: relative; }
.pc-more summary { list-style: none; width: 36px; height: 36px; display: grid; place-items: center; border: 1px solid #e5eee8; background: #fff; }
.pc-more summary::-webkit-details-marker { display: none; }
.pc-more a { position: absolute; right: 0; top: 40px; background: #fff; border: 1px solid #e5eee8; border-radius: 10px; padding: 8px 12px; text-decoration: none; color: #142033; font-weight: 700; white-space: nowrap; z-index: 2; }
.pc-insights h2, .pc-pipeline h2, .pc-form h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 18px; }
.pc-beta { margin-left: auto; background: #ecfdf3; color: #15803d; border-radius: 999px; font-size: 11px; padding: 3px 8px; }
[hidden] { display: none !important; }
.pc-insights li { display: flex; justify-content: space-between; gap: 8px; align-items: center; list-style: none; margin: 0; padding: 8px 0; border-top: 1px solid #f1f5f3; font-size: 13px; font-weight: 700; }
.pc-insights ul { margin: 0; padding: 0; }
.pc-pipe { display: flex; align-items: center; gap: 8px; padding: 7px 0; font-size: 14px; font-weight: 700; color: #334155; text-decoration: none; }
.pc-pipe b { margin-left: auto; }
.pc-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.pc-bar { height: 6px; background: #eef2f0; border-radius: 99px; overflow: hidden; margin: -2px 0 6px 17px; }
.pc-bar span { display: block; height: 100%; background: #86efac; }
.pc-form h2 { margin-bottom: 2px; }
.pc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; margin-top: 14px; }
.pc-grid label { display: block; font-size: 13px; font-weight: 800; margin-bottom: 6px; }
.pc-input { display: flex; align-items: center; gap: 8px; border: 1px solid #e5eee8; border-radius: 12px; padding: 0 12px; background: #fff; }
.pc-input input, .pc-input select { border: 0; outline: none; width: 100%; padding: 12px 0; font: 600 14px Manrope, sans-serif; background: transparent; }
.pc-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.pc-clear { background: #fff; border: 1px solid #e5eee8; border-radius: 12px; padding: 10px 16px; font-weight: 800; text-decoration: none; color: #334155; cursor: pointer; font-family: inherit; }
.pc-extra { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 180px 160px; gap: 12px; }
.pc-note, .err, .ok { margin: 0 0 12px; border-radius: 12px; padding: 10px 12px; font-weight: 700; }
.err { background: #fef2f2; color: #991b1b; }
.ok { background: #ecfdf3; color: #166534; }
.pc-table { width: 100%; border-collapse: collapse; }
.pc-table th, .pc-table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #eef3f0; font-size: 14px; }
.pc-table th { font-size: 12px; text-transform: uppercase; color: #64748b; }
.pc-open { background: #16a34a; color: #fff; border-radius: 10px; padding: 8px 12px; text-decoration: none; font-weight: 800; }
@media (max-width: 1100px) {
  .pc-stage, .pc-kpis, .pc-money, .pc-grid, .pc-extra, .pc-person { grid-template-columns: 1fr; }
  .pc-insights, .pc-pipeline, .pc-follow, .pc-kpis, .pc-money, .pc-partners, .pc-form, .pc-clients { grid-column: 1; grid-row: auto; }
}
</style>
</head>
<body>
<div class="pc">
  <div class="pc-main">
    <?php if ($error !== ''): ?><p class="err"><?= canada_partner_h($error) ?></p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="ok"><?= canada_partner_h($notice) ?></p><?php endif; ?>

    <div class="pc-title">
      <form method="get">
        <input type="hidden" name="scope" value="<?= canada_partner_h($scope) ?>">
        <input type="hidden" name="q" value="<?= canada_partner_h($search) ?>">
        <select class="pc-period" name="period" onchange="this.form.submit()">
          <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
          <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All time</option>
        </select>
      </form>
    </div>

    <div class="pc-stage">
      <section class="pc-kpis">
        <article class="pc-kpi hot"><i><?= $icon('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') ?></i><b><?= count($partners) ?></b><span>Partners</span><em><?= $partnerDelta > 0 ? '+' : '' ?><?= (int) $partnerDelta ?> vs last month</em></article>
        <article class="pc-kpi"><i><?= $icon('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="3"/>') ?></i><b><?= (int) $kpi['clients'] ?></b><span>Clients</span></article>
        <?php foreach (canada_partner_statuses() as $key => $meta): ?>
          <article class="pc-kpi"><i><?= $stageIcons[$key] ?></i><b><?= (int) $kpi[$key] ?></b><span><?= canada_partner_h($meta['label']) ?></span></article>
        <?php endforeach; ?>
      </section>

      <section class="pc-money" id="commissions">
        <article class="pc-card"><i><?= $icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>') ?></i><div><b><?= canada_partner_h(canada_partner_cad((float) $kpi['earned'])) ?></b><span>Commission earned</span></div></article>
        <article class="pc-card hot"><i><?= $icon('<path d="M3 7h18v10H3z"/><path d="M3 11h18"/><path d="M7 15h4"/>') ?></i><div><b><?= canada_partner_h(canada_partner_cad((float) $kpi['received'])) ?></b><span>Commission received</span></div></article>
        <article class="pc-card"><i><?= $icon('<path d="M4 19V5M4 19h16"/><path d="M8 16l4-5 3 3 5-7"/>') ?></i><div><b><?= canada_partner_h(canada_partner_cad((float) $kpi['outstanding'])) ?></b><span>Commission outstanding</span></div></article>
      </section>

      <section class="pc-card pc-insights">
        <h2><?= $icon('<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 14l.7 1.8L20.5 16.5 18.7 17.2 18 19l-.7-1.8-1.8-.7.7-1.8z"/>') ?> Insights <span class="pc-beta">Beta</span></h2>
        <ul>
          <?php if ((int) $kpi['clients'] === 0 && $followups === []): ?>
            <li><span>No clients yet. Add clients to start your pipeline.</span></li>
            <li><span>Set a reminder to follow up with your partners.</span></li>
            <li><span>Update service progress to keep commissions accurate.</span></li>
          <?php else: ?>
            <li><span><?= (int) $kpi['clients'] === 0 ? 'No clients yet. Add clients to start your pipeline.' : (int) $kpi['clients'] . ' clients in this view.' ?></span></li>
            <li><span><?= count($followups) === 0 ? 'No follow-ups are due.' : count($followups) . ' follow-ups are due.' ?></span></li>
            <li><span>Commission outstanding <?= canada_partner_h(canada_partner_cad((float) $kpi['outstanding'])) ?>.</span></li>
          <?php endif; ?>
        </ul>
      </section>

      <section class="pc-card pc-partners" id="partners">
        <div class="pc-head">
          <div>
            <h2>Partner Consultants</h2>
            <p>Manage consultant services, progress, and commissions.</p>
          </div>
          <div class="pc-tools">
            <a class="pc-pill <?= $scope === 'active' ? 'on' : '' ?>" href="<?= canada_partner_h($keep(['scope' => 'active'])) ?>">Active (<?= (int) $activeCount ?>)</a>
            <a class="pc-pill <?= $scope === 'all' ? 'on' : '' ?>" href="<?= canada_partner_h($keep(['scope' => 'all'])) ?>">All (<?= count($partners) ?>)</a>
            <form class="pc-mini" method="get">
              <input type="hidden" name="scope" value="<?= canada_partner_h($scope) ?>">
              <input type="hidden" name="period" value="<?= canada_partner_h($period) ?>">
              <input name="q" value="<?= canada_partner_h($search) ?>" placeholder="Search consultants...">
            </form>
          </div>
        </div>
        <?php foreach ($listPartners as $partner): ?>
          <?php $mineTotals = canada_partner_totals($byPartner[(int) $partner['id']] ?? []); ?>
          <article class="pc-person">
            <div class="pc-avatar"><?= canada_partner_h($initialsOf((string) $partner['name'])) ?></div>
            <div>
              <h3><?= canada_partner_h((string) $partner['name']) ?><?php if ((int) $partner['active'] !== 1): ?> <span class="meta">Inactive</span><?php endif; ?></h3>
              <div class="meta"><?= canada_partner_h((string) $partner['service_name']) ?> · <?= canada_partner_h(canada_partner_rule_label((string) $partner['commission_type'], (float) $partner['commission_value'])) ?></div>
              <div class="pc-counts">
                <?php foreach (canada_partner_statuses() as $key => $meta): ?>
                  <span><?= (int) $meta['n'] ?>. <?= canada_partner_h($shortStages[$key]) ?> <?= (int) $mineTotals[$key] ?></span>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="pc-cash">
              Earned <?= canada_partner_h(canada_partner_cad((float) $mineTotals['earned'])) ?><br>
              Received <?= canada_partner_h(canada_partner_cad((float) $mineTotals['received'])) ?><br>
              Outstanding <?= canada_partner_h(canada_partner_cad((float) $mineTotals['outstanding'])) ?>
            </div>
            <div class="pc-actions">
              <a class="pc-btn" href="canada-partner-client.php?partner=<?= (int) $partner['id'] ?>">Add Client</a>
              <a class="pc-ghost" href="<?= canada_partner_h($keep(['partner' => (string) $partner['id']])) ?>#clients">Clients</a>
              <details class="pc-more">
                <summary aria-label="More">···</summary>
                <a href="<?= canada_partner_h($keep(['edit' => (string) $partner['id']])) ?>#partner-form">Edit</a>
              </details>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="pc-card pc-pipeline" id="pipeline">
        <h2>Pipeline at a Glance</h2>
        <?php
        $pipeColors = ['discussion' => '#94a3b8', 'accepted' => '#60a5fa', 'started' => '#2563eb', 'paid' => '#22c55e', 'decision' => '#a855f7'];
        $pipeMax = max(1, (int) $kpi['clients']);
        foreach (canada_partner_statuses() as $key => $meta):
            $count = (int) $kpi[$key];
            $width = (int) round($count * 100 / $pipeMax);
        ?>
          <a class="pc-pipe" href="<?= canada_partner_h($keep(['status' => $key])) ?>#clients">
            <i class="pc-dot" style="background:<?= $pipeColors[$key] ?>"></i>
            <?= (int) $meta['n'] ?>. <?= canada_partner_h($meta['label']) ?>
            <b><?= $count ?></b>
          </a>
          <div class="pc-bar"><span style="width:<?= $width ?>%;background:<?= $pipeColors[$key] ?>"></span></div>
        <?php endforeach; ?>
      </section>

      <section class="pc-card pc-form" id="partner-form">
        <h2><?= $editing ? 'Edit a Partner' : 'Add a Partner' ?></h2>
        <p class="pc-sub"><?= $editing ? 'Update this consultant.' : 'Register a new partner consultant to start tracking services and commissions.' ?></p>
        <form method="post" id="partner-save">
          <input type="hidden" name="csrf_token" value="<?= canada_partner_h((string) $_SESSION['csrf_token']) ?>">
          <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
          <input type="hidden" name="city" value="<?= canada_partner_h((string) ($editing['city'] ?? 'Canada')) ?>">
          <input type="hidden" name="notes" value="<?= canada_partner_h((string) ($editing['notes'] ?? '')) ?>">
          <div class="pc-grid">
            <div>
              <label>Partner Name *</label>
              <div class="pc-input"><span>&#128100;</span><input name="name" required value="<?= canada_partner_h((string) ($editing['name'] ?? '')) ?>" placeholder="Enter partner name"></div>
            </div>
            <div>
              <label>Service *</label>
              <div class="pc-input">
                <span>&#128188;</span>
                <select id="service_choice" required>
                  <option value="">Select a service</option>
                  <option value="tcf" <?= $serviceChoice === 'tcf' ? 'selected' : '' ?>>TCF Canada Training</option>
                  <option value="pr" <?= $serviceChoice === 'pr' ? 'selected' : '' ?>>Permanent Residence / Express Entry</option>
                  <option value="custom" <?= $serviceChoice === 'custom' ? 'selected' : '' ?>>Other service</option>
                </select>
              </div>
            </div>
            <div>
              <label>Email</label>
              <div class="pc-input"><span>&#9993;</span><input name="email" value="<?= canada_partner_h((string) ($editing['email'] ?? '')) ?>" placeholder="Enter email address"></div>
            </div>
            <div>
              <label>Phone</label>
              <div class="pc-input"><span>&#128222;</span><input name="phone" value="<?= canada_partner_h((string) ($editing['phone'] ?? '')) ?>" placeholder="Enter phone number"></div>
            </div>
            <div class="pc-extra" id="custom-fields" <?= $serviceChoice === 'custom' || $editing ? '' : 'hidden' ?>>
              <div>
                <label>Service name</label>
                <div class="pc-input"><input id="service_name" name="service_name" value="<?= canada_partner_h($serviceNow) ?>" placeholder="Service name"></div>
              </div>
              <div>
                <label>Commission</label>
                <div class="pc-input">
                  <?php $type = (string) ($editing['commission_type'] ?? 'fixed'); ?>
                  <select name="commission_type" id="commission_type">
                    <option value="fixed" <?= $type === 'fixed' ? 'selected' : '' ?>>Fixed CAD</option>
                    <option value="percent" <?= $type === 'percent' ? 'selected' : '' ?>>Percent</option>
                  </select>
                </div>
              </div>
              <div>
                <label>Amount</label>
                <div class="pc-input"><input name="commission_value" id="commission_value" type="number" min="0" step="0.01" required value="<?= canada_partner_h((string) ($editing['commission_value'] ?? '50')) ?>"></div>
              </div>
            </div>
          </div>
          <?php if ($editing): ?>
            <div class="pc-grid">
              <div>
                <label>Active</label>
                <div class="pc-input">
                  <select name="active">
                    <option value="1" <?= (int) ($editing['active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= (int) ($editing['active'] ?? 1) !== 1 ? 'selected' : '' ?>>Inactive</option>
                  </select>
                </div>
              </div>
            </div>
          <?php else: ?>
            <input type="hidden" name="active" value="1">
          <?php endif; ?>
          <div class="pc-form-actions">
            <?php if ($editing): ?>
              <a class="pc-clear" href="canada-partners.php#partner-form">Clear</a>
            <?php else: ?>
              <button class="pc-clear" type="reset">Clear</button>
            <?php endif; ?>
            <button class="pc-btn" type="submit"><?= $editing ? 'Save Partner' : 'Add Partner' ?></button>
          </div>
        </form>
      </section>

      <section class="pc-card pc-follow" id="followups">
        <h2>Follow-ups</h2>
        <?php if ($followups === []): ?>
          <p class="pc-sub">No follow-ups are due.</p>
        <?php else: ?>
          <?php foreach ($followups as $client): ?>
            <a class="pc-pipe" href="canada-partner-client.php?id=<?= (int) $client['id'] ?>">
              <?= canada_partner_h((string) $client['full_name']) ?>
              <b><?= canada_partner_h((string) $client['next_followup']) ?></b>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <?php if ($showClients): ?>
      <section class="pc-card pc-clients" id="clients">
        <div class="pc-head">
          <div><h2><?= $selectedPartner ? canada_partner_h((string) $selectedPartner['name']) : 'Clients' ?></h2></div>
        </div>
        <table class="pc-table">
          <thead><tr><th>Client</th><th>Partner</th><th>Stage</th><th>Payment</th><th>Commission</th><th>Follow-up</th><th></th></tr></thead>
          <tbody>
          <?php if ($visible === []): ?>
            <tr><td colspan="7">No clients yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($visible as $client): ?>
            <tr>
              <td><?= canada_partner_h((string) $client['client_code']) ?><br><?= canada_partner_h((string) $client['full_name']) ?></td>
              <td><?= canada_partner_h((string) $client['partner_name']) ?><br><span class="meta"><?= canada_partner_h((string) $client['service_name']) ?></span></td>
              <td><?= (int) $client['status_number'] ?>. <?= canada_partner_h((string) $client['status_label']) ?></td>
              <td><?= canada_partner_h((string) $client['payment_state']) ?><br><?= canada_partner_h(canada_partner_cad((float) $client['amount_paid'])) ?> of <?= canada_partner_h(canada_partner_cad((float) $client['service_fee'])) ?></td>
              <td><?= canada_partner_h(canada_partner_cad((float) $client['commission_outstanding'])) ?></td>
              <td><?= canada_partner_h((string) ($client['next_followup'] ?: '—')) ?></td>
              <td><a class="pc-open" href="canada-partner-client.php?id=<?= (int) $client['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
const presets = {
  tcf: { name: 'TCF Canada Training', type: 'fixed', value: '50' },
  pr: { name: 'Permanent Residence / Express Entry', type: 'percent', value: '25' }
};
const choice = document.getElementById('service_choice');
const custom = document.getElementById('custom-fields');
function fields() {
  return {
    name: document.getElementById('service_name') || document.getElementById('service_name_hidden'),
    type: document.getElementById('commission_type') || document.getElementById('commission_type_hidden'),
    value: document.getElementById('commission_value') || document.getElementById('commission_value_hidden')
  };
}
function applyService() {
  const picked = choice.value;
  const box = fields();
  if (picked === 'custom') {
    custom.hidden = false;
    if (box.name) box.name.required = true;
    return;
  }
  if (box.name) box.name.required = false;
  if (!presets[picked]) {
    custom.hidden = true;
    return;
  }
  custom.hidden = <?= $editing ? 'false' : 'true' ?>;
  if (box.name) box.name.value = presets[picked].name;
  if (box.type) box.type.value = presets[picked].type;
  if (box.value) box.value.value = presets[picked].value;
}
if (choice) {
  choice.addEventListener('change', applyService);
  document.getElementById('partner-save').addEventListener('submit', applyService);
  applyService();
}
</script>
</body>
</html>

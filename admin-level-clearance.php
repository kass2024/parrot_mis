<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/staff_level_clearance.php';
require_once __DIR__ . '/helpers/staff_self_register.php';
require_once __DIR__ . '/helpers/university_admins_schema.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_ensure_account_status_column($conn);
pcvc_ensure_staff_account_columns($conn);
if (!pcvc_current_user_is_superadmin($conn)) {
    if (!function_exists('pcvc_deny_page')) {
        require_once __DIR__ . '/helpers/role.php';
    }
    pcvc_deny_page();
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$catalog = pcvc_staff_level_clearance_catalog();
$segment = strtolower(trim((string) ($_GET['segment'] ?? $_POST['segment'] ?? 'staff')));
if (!in_array($segment, ['staff', 'agent', 'company'], true)) {
    $segment = 'staff';
}

$people = ['staff' => [], 'agent' => [], 'company' => []];
$result = $conn->query(
    'SELECT id, first_name, last_name, full_name, email, position, role, account_status,
            account_type, company_name
     FROM admins
     ORDER BY company_name, first_name, last_name, id'
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
        if (in_array($status, ['inactive', 'pending'], true)) {
            continue;
        }
        $seg = pcvc_level_clearance_segment_for_row($row);
        if ($seg === '' || !isset($people[$seg])) {
            continue;
        }
        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['full_name'] ?? ''));
        }
        $companyName = trim((string) ($row['company_name'] ?? ''));
        if ($seg === 'company' && $companyName !== '') {
            $row['display_name'] = $companyName;
            $row['role_label'] = 'Company';
            $row['subtitle'] = $name !== '' ? $name : (string) ($row['email'] ?? '');
        } else {
            if ($name === '') {
                $name = ($seg === 'company' ? 'Company' : 'Account') . ' #' . (int) $row['id'];
            }
            $row['display_name'] = $name;
            $roleKey = strtolower(trim((string) ($row['role'] ?? '')));
            $row['role_label'] = [
                'staff' => 'Staff',
                'accountant' => 'Accountant',
                'secretary' => 'Secretary',
                'agent' => 'Agent',
            ][$roleKey] ?? ucfirst($roleKey !== '' ? $roleKey : $seg);
            $row['subtitle'] = trim((string) ($row['position'] ?? ''));
            if ($row['subtitle'] === '') {
                $row['subtitle'] = (string) ($row['email'] ?? '');
            }
        }
        $people[$seg][] = $row;
    }
}

$list = $people[$segment];
// Companies: seed default denials (unchecked / hidden) if not configured yet.
if ($segment === 'company') {
    foreach ($list as $companyRow) {
        pcvc_company_ensure_default_clearance($conn, (int) ($companyRow['id'] ?? 0));
    }
}
$selectedId = (int) ($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$knownIds = array_map(static fn(array $row): int => (int) $row['id'], $list);
if (!in_array($selectedId, $knownIds, true)) {
    $selectedId = $knownIds[0] ?? 0;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Reload the page and save again.';
    } elseif (!in_array($selectedId, $knownIds, true)) {
        $error = 'Choose an account from this list.';
    } else {
        $allowed = array_fill_keys(array_map('strval', (array) ($_POST['allow'] ?? [])), true);
        $denied = [];
        foreach ($catalog as $menuKey => $menu) {
            $menuKeyName = pcvc_staff_level_clearance_menu_key($menuKey);
            $subDenied = 0;
            foreach ($menu['items'] as $url => $label) {
                $subKey = pcvc_staff_level_clearance_sub_key($menuKey, $url);
                if (!isset($allowed[$subKey])) {
                    $denied[] = $subKey;
                    $subDenied++;
                }
            }
            if (!isset($allowed[$menuKeyName]) || $subDenied === count($menu['items'])) {
                $denied[] = $menuKeyName;
            }
        }
        // Keep company HQ-defaults marker so ensure() does not re-force denials after SA enables menus.
        if ($segment === 'company') {
            $denied[] = pcvc_company_hq_defaults_marker();
        }
        pcvc_staff_level_clearance_save_configured($conn, $selectedId, $denied);
        header('Location: admin-level-clearance.php?segment=' . rawurlencode($segment) . '&id=' . $selectedId . '&saved=1');
        exit;
    }
}
$saved = !empty($_GET['saved']);
$denied = $selectedId > 0 ? array_fill_keys(pcvc_staff_level_clearance_denied($conn, $selectedId), true) : [];
unset($denied[pcvc_level_clearance_configured_key()], $denied[pcvc_company_hq_defaults_marker()]);
$selected = null;
foreach ($list as $row) {
    if ((int) $row['id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
$groups = [];
foreach ($catalog as $menuKey => $menu) {
    $groups[$menu['group']][$menuKey] = $menu;
}

$segmentLabels = [
    'staff' => 'Staff',
    'agent' => 'Agents',
    'company' => 'Companies',
];
$segmentHints = [
    'staff' => 'Staff, accountant, and secretary accounts.',
    'agent' => 'Personal agent accounts (not companies).',
    'company' => 'Company partner accounts. Checked menus appear when they sign in or when you switch into their dashboard. HQ-only modules start unchecked (hidden) until you enable them.',
];
$companyDefaultDenied = array_fill_keys(pcvc_company_level_clearance_default_denied_menu_keys(), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Level Clearance | Parrot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; font-family: Segoe UI, sans-serif; background: #f3f6f3; color: #1a2e1a; }
.layout { display: grid; grid-template-columns: 300px 1fr; min-height: 100vh; }
.people { background: #fff; border-right: 1px solid #e1ebe1; padding: 16px; }
.people h1, .editor h1 { font-size: 18px; margin: 0 0 8px; color: #2d5a27; }
.people p, .note { color: #4d624d; font-size: 13px; line-height: 1.45; }
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 10px 0 12px; }
.tabs a { text-decoration: none; padding: 7px 10px; border-radius: 999px; background: #f1f5f1; color: #334155; font-size: 12px; font-weight: 700; }
.tabs a.active { background: #14532d; color: #fff; }
.tabs a .n { opacity: .8; margin-left: 4px; }
input[type=search] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d5e0d5; border-radius: 8px; }
.person { display: block; padding: 10px 8px; border-radius: 8px; text-decoration: none; color: inherit; }
.person.active, .person:hover { background: #e8f5e9; }
.person strong { display: block; }
.person span { color: #5c705c; font-size: 12px; }
.editor { padding: 18px 20px 48px; }
.group { margin: 16px 0; background: #fff; border-radius: 12px; padding: 12px 14px; }
.group h2 { font-size: 14px; margin: 0 0 8px; color: #2d5a27; }
.menu { border-top: 1px solid #eef3ee; padding: 8px 0; }
.menu.is-company-default-off { background: #fff7ed; border-radius: 8px; padding: 8px; margin-top: 6px; }
.menu label.menu-label { font-weight: 700; }
label { display: flex; gap: 8px; align-items: flex-start; margin: 4px 0; font-size: 14px; }
.subs { margin-left: 22px; }
button { background: #2d5a27; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
.alert { background: #d4edda; color: #155724; padding: 10px 12px; border-radius: 8px; }
.err { background: #f8d7da; color: #721c24; padding: 10px 12px; border-radius: 8px; }
@media (max-width: 800px) { .layout { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="layout">
  <aside class="people">
    <h1>Level Clearance</h1>
    <p><?= htmlspecialchars($segmentHints[$segment], ENT_QUOTES, 'UTF-8') ?> A person with nothing unchecked still has every menu once saved.</p>
    <div class="tabs">
      <?php foreach ($segmentLabels as $key => $label): ?>
      <a class="<?= $segment === $key ? 'active' : '' ?>" href="admin-level-clearance.php?segment=<?= rawurlencode($key) ?>">
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?><span class="n"><?= count($people[$key]) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <input type="search" id="filterStaff" placeholder="Find <?= htmlspecialchars(strtolower($segmentLabels[$segment]), ENT_QUOTES, 'UTF-8') ?>" oninput="filterStaff(this.value)">
    <?php foreach ($list as $row): ?>
      <a class="person<?= (int) $row['id'] === $selectedId ? ' active' : '' ?>"
         href="admin-level-clearance.php?segment=<?= rawurlencode($segment) ?>&id=<?= (int) $row['id'] ?>"
         data-name="<?= htmlspecialchars(strtolower($row['display_name'] . ' ' . ($row['email'] ?? '') . ' ' . ($row['subtitle'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
        <strong><?= htmlspecialchars($row['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <span><?= htmlspecialchars((string) ($row['role_label'] ?? ucfirst($segment)), ENT_QUOTES, 'UTF-8') ?><?= !empty($row['subtitle']) ? ' · ' . htmlspecialchars((string) $row['subtitle'], ENT_QUOTES, 'UTF-8') : '' ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($list === []): ?><p>No active <?= htmlspecialchars(strtolower($segmentLabels[$segment]), ENT_QUOTES, 'UTF-8') ?> accounts.</p><?php endif; ?>
  </aside>
  <main class="editor">
    <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($saved): ?><p class="alert">Clearance saved. Menus update the next time their dashboard loads (or when you switch into a company view).</p><?php endif; ?>
    <?php if ($selected): ?>
      <h1><?= htmlspecialchars($selected['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="note"><?= htmlspecialchars((string) ($selected['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($selected['subtitle']) ? ' · ' . htmlspecialchars((string) $selected['subtitle'], ENT_QUOTES, 'UTF-8') : '' ?></p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="segment" value="<?= htmlspecialchars($segment, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="staff_id" value="<?= (int) $selected['id'] ?>">
        <?php foreach ($groups as $groupName => $menus): ?>
          <section class="group">
            <h2><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h2>
            <?php foreach ($menus as $menuKey => $menu):
                $menuName = pcvc_staff_level_clearance_menu_key($menuKey);
                $menuOn = !isset($denied[$menuName]);
                $isCompanyDefaultOff = $segment === 'company' && isset($companyDefaultDenied[$menuKey]);
            ?>
              <div class="menu<?= $isCompanyDefaultOff ? ' is-company-default-off' : '' ?>" data-menu="<?= htmlspecialchars($menuKey, ENT_QUOTES, 'UTF-8') ?>">
                <label class="menu-label"><input type="checkbox" name="allow[]" value="<?= htmlspecialchars($menuName, ENT_QUOTES, 'UTF-8') ?>" <?= $menuOn ? 'checked' : '' ?> data-parent="<?= htmlspecialchars($menuKey, ENT_QUOTES, 'UTF-8') ?>"> <?= htmlspecialchars($menu['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($isCompanyDefaultOff && !$menuOn): ?> <span style="color:#9a3412;font-weight:650;font-size:12px">(hidden for company by default)</span><?php endif; ?></label>
                <div class="subs">
                  <?php foreach ($menu['items'] as $url => $label):
                      $subName = pcvc_staff_level_clearance_sub_key($menuKey, $url);
                      $subOn = $menuOn && !isset($denied[$subName]);
                  ?>
                    <label><input type="checkbox" name="allow[]" value="<?= htmlspecialchars($subName, ENT_QUOTES, 'UTF-8') ?>" <?= $subOn ? 'checked' : '' ?> data-child="<?= htmlspecialchars($menuKey, ENT_QUOTES, 'UTF-8') ?>"> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
        <button type="submit">Save clearance</button>
      </form>
    <?php endif; ?>
  </main>
</div>
<script>
function filterStaff(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.person').forEach(el => {
    el.style.display = el.dataset.name.includes(q) ? '' : 'none';
  });
}
document.querySelectorAll('[data-parent]').forEach(parent => {
  parent.addEventListener('change', () => {
    document.querySelectorAll('[data-child="' + parent.dataset.parent + '"]').forEach(child => {
      child.checked = parent.checked;
    });
  });
});
document.querySelectorAll('[data-child]').forEach(child => {
  child.addEventListener('change', () => {
    const key = child.dataset.child;
    const kids = [...document.querySelectorAll('[data-child="' + key + '"]')];
    const parent = document.querySelector('[data-parent="' + key + '"]');
    const checked = kids.filter(el => el.checked).length;
    parent.checked = checked > 0;
    parent.indeterminate = checked > 0 && checked < kids.length;
  });
});
</script>
</body>
</html>

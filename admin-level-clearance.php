<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/staff_level_clearance.php';
require_once __DIR__ . '/helpers/university_admins_schema.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_ensure_account_status_column($conn);
if (!pcvc_current_user_is_superadmin($conn)) {
    if (!function_exists('pcvc_deny_page')) { require_once __DIR__ . '/helpers/role.php'; }
    pcvc_deny_page();
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$catalog = pcvc_staff_level_clearance_catalog();
$staff = [];
$result = $conn->query('SELECT id, first_name, last_name, full_name, email, position, role, account_status FROM admins ORDER BY first_name, last_name, id');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!pcvc_staff_level_clearance_is_target((string) ($row['role'] ?? ''))) {
            continue;
        }
        $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
        if (in_array($status, ['inactive', 'pending'], true)) {
            continue;
        }
        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['full_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Staff #' . (int) $row['id'];
        }
        $row['display_name'] = $name;
        $roleKey = strtolower(trim((string) ($row['role'] ?? '')));
        $row['role_label'] = [
            'staff' => 'Staff',
            'accountant' => 'Accountant',
            'secretary' => 'Secretary',
        ][$roleKey] ?? ucfirst($roleKey);
        $staff[] = $row;
    }
}

$selectedId = (int) ($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$knownIds = array_map(static fn(array $row): int => (int) $row['id'], $staff);
if (!in_array($selectedId, $knownIds, true)) {
    $selectedId = $knownIds[0] ?? 0;
}

$saved = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Reload the page and save again.';
    } elseif (!in_array($selectedId, $knownIds, true)) {
        $error = 'Choose a staff account.';
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
        pcvc_staff_level_clearance_save($conn, $selectedId, $denied);
        header('Location: admin-level-clearance.php?id=' . $selectedId . '&saved=1');
        exit;
    }
}
$saved = $saved || !empty($_GET['saved']);
$denied = $selectedId > 0 ? array_fill_keys(pcvc_staff_level_clearance_denied($conn, $selectedId), true) : [];
$selected = null;
foreach ($staff as $row) {
    if ((int) $row['id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
$groups = [];
foreach ($catalog as $menuKey => $menu) {
    $groups[$menu['group']][$menuKey] = $menu;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Level Clearance | Parrot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; font-family: Segoe UI, sans-serif; background: #f3f6f3; color: #1a2e1a; }
.layout { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.people { background: #fff; border-right: 1px solid #e1ebe1; padding: 16px; }
.people h1, .editor h1 { font-size: 18px; margin: 0 0 8px; color: #2d5a27; }
.people p, .note { color: #4d624d; font-size: 13px; line-height: 1.45; }
input[type=search] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d5e0d5; border-radius: 8px; }
.person { display: block; padding: 10px 8px; border-radius: 8px; text-decoration: none; color: inherit; }
.person.active, .person:hover { background: #e8f5e9; }
.person strong { display: block; }
.person span { color: #5c705c; font-size: 12px; }
.editor { padding: 18px 20px 48px; }
.group { margin: 16px 0; background: #fff; border-radius: 12px; padding: 12px 14px; }
.group h2 { font-size: 14px; margin: 0 0 8px; color: #2d5a27; }
.menu { border-top: 1px solid #eef3ee; padding: 8px 0; }
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
    <p>Staff, accountant, and secretary accounts. A person with nothing unchecked still has every menu.</p>
    <input type="search" id="filterStaff" placeholder="Find a staff member" oninput="filterStaff(this.value)">
    <?php foreach ($staff as $row): ?>
      <a class="person<?= (int) $row['id'] === $selectedId ? ' active' : '' ?>" href="admin-level-clearance.php?id=<?= (int) $row['id'] ?>" data-name="<?= htmlspecialchars(strtolower($row['display_name'] . ' ' . ($row['email'] ?? ''))) ?>">
        <strong><?= htmlspecialchars($row['display_name']) ?></strong>
        <span><?= htmlspecialchars((string) ($row['role_label'] ?? 'Staff')) ?><?= $row['position'] ? ' · ' . htmlspecialchars((string) $row['position']) : '' ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($staff === []): ?><p>No active staff, accountant, or secretary accounts.</p><?php endif; ?>
  </aside>
  <main class="editor">
    <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($saved): ?><p class="alert">Clearance saved. That staff member will see the checked menus the next time the dashboard loads.</p><?php endif; ?>
    <?php if ($selected): ?>
      <h1><?= htmlspecialchars($selected['display_name']) ?></h1>
      <p class="note"><?= htmlspecialchars((string) ($selected['email'] ?? '')) ?><?= $selected['position'] ? ' · ' . htmlspecialchars((string) $selected['position']) : '' ?></p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="staff_id" value="<?= (int) $selected['id'] ?>">
        <?php foreach ($groups as $groupName => $menus): ?>
          <section class="group">
            <h2><?= htmlspecialchars($groupName) ?></h2>
            <?php foreach ($menus as $menuKey => $menu):
                $menuName = pcvc_staff_level_clearance_menu_key($menuKey);
                $menuOn = !isset($denied[$menuName]);
            ?>
              <div class="menu" data-menu="<?= htmlspecialchars($menuKey) ?>">
                <label class="menu-label"><input type="checkbox" name="allow[]" value="<?= htmlspecialchars($menuName) ?>" <?= $menuOn ? 'checked' : '' ?> data-parent="<?= htmlspecialchars($menuKey) ?>"> <?= htmlspecialchars($menu['label']) ?></label>
                <div class="subs">
                  <?php foreach ($menu['items'] as $url => $label):
                      $subName = pcvc_staff_level_clearance_sub_key($menuKey, $url);
                      $subOn = $menuOn && !isset($denied[$subName]);
                  ?>
                    <label><input type="checkbox" name="allow[]" value="<?= htmlspecialchars($subName) ?>" <?= $subOn ? 'checked' : '' ?> data-child="<?= htmlspecialchars($menuKey) ?>"> <?= htmlspecialchars($label) ?></label>
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

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/malta_courses_schema.php';
require_once __DIR__ . '/helpers/secure_file.php';

malta_ensure_schema($conn);

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    header('Location: admin-login.php');
    exit;
}

$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $appId = (int) ($_POST['application_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['pending', 'under_review', 'approved', 'rejected'];
    if ($appId > 0 && in_array($status, $allowed, true)) {
        $note = trim((string) ($_POST['admin_notes'] ?? ''));
        $stmt = $conn->prepare('UPDATE malta_courses_applications SET status = ?, admin_notes = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $note, $appId);
            $stmt->execute();
            $stmt->close();
            $notice = 'Application updated.';
        }
    } else {
        $error = 'Could not update that application.';
    }
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'under_review', 'approved', 'rejected'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}
$search = trim((string) ($_GET['q'] ?? ''));
$where = ['1=1'];
$params = [];
$types = '';
if ($statusFilter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR reference_id LIKE ? OR country LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$sql = 'SELECT * FROM malta_courses_applications WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 300';
$stmt = $conn->prepare($sql);
if ($stmt && $params) {
    $stmt->bind_param($types, ...$params);
}
$rows = [];
if ($stmt) {
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function malta_doc_link(string $path, string $label): string
{
    $rel = function_exists('pcvc_norm_upload_rel_path') ? pcvc_norm_upload_rel_path($path) : $path;
    if ($rel === '') {
        return '';
    }
    $url = function_exists('pcvc_secure_file_url') ? pcvc_secure_file_url($rel) : $rel;
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Malta professional courses</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f4f7f4; font-family:system-ui,sans-serif; }
.wrap { max-width:1200px; margin:0 auto; padding:1.2rem; }
.badge-pending { background:#fef3c7; color:#92400e; }
.badge-under_review { background:#dbeafe; color:#1e40af; }
.badge-approved { background:#d1fae5; color:#065f46; }
.badge-rejected { background:#fee2e2; color:#991b1b; }
</style>
</head>
<body>
<div class="wrap">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Malta — professional courses</h1>
      <p class="text-muted mb-0">Passport, CV, and academic documents.</p>
    </div>
    <a class="btn btn-success" href="malta-courses-request.php?staff=1">Add student</a>
  </div>
  <?php if ($notice !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
      <select class="form-select" name="status">
        <?php foreach (['all' => 'All', 'pending' => 'Pending', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <input class="form-control" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name, email, reference, country">
    </div>
    <div class="col-md-2"><button class="btn btn-outline-success w-100">Filter</button></div>
  </form>
  <div class="table-responsive bg-white border rounded">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Student</th><th>Program</th><th>Documents</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No Malta applications yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars((string) $row['full_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <span class="small text-muted"><?= htmlspecialchars((string) $row['reference_id'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?><br>
            <?= htmlspecialchars((string) $row['phone'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $row['country'], ENT_QUOTES, 'UTF-8') ?></span>
          </td>
          <td><?= htmlspecialchars(malta_program_label((string) $row['program']), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="small">
            <?= malta_doc_link((string) $row['passport_file'], 'Passport') ?>
            · <?= malta_doc_link((string) $row['cv_file'], 'CV') ?>
            · <?= malta_doc_link((string) $row['academic_file'], 'Academic') ?>
          </td>
          <td><span class="badge badge-<?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(str_replace('_', ' ', (string) $row['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td>
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="application_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="admin_notes" value="<?= htmlspecialchars((string) ($row['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['pending', 'under_review', 'approved', 'rejected'] as $st): ?>
                <option value="<?= $st ?>" <?= $row['status'] === $st ? 'selected' : '' ?>><?= str_replace('_', ' ', $st) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-success">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

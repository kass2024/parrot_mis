<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/eci_malta_schema.php';
require_once __DIR__ . '/helpers/secure_file.php';

eci_ensure_schema($conn);

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
        $stmt = $conn->prepare('UPDATE eci_malta_applications SET status = ?, admin_notes = ? WHERE id = ?');
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
    $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR reference_id LIKE ? OR course_title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
$sql = 'SELECT * FROM eci_malta_applications WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 300';
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

function eci_doc_link(string $path, string $label): string
{
    $rel = function_exists('pcvc_norm_upload_rel_path') ? pcvc_norm_upload_rel_path($path) : $path;
    if ($rel === '') {
        return '';
    }
    $url = function_exists('pcvc_secure_file_url') ? pcvc_secure_file_url($rel) : $rel;
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

function eci_cell(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ECI Malta applications</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f4f7f4; font-family:system-ui,sans-serif; }
.wrap { max-width:1280px; margin:0 auto; padding:1.2rem; }
.badge-pending { background:#fef3c7; color:#92400e; }
.badge-under_review { background:#dbeafe; color:#1e40af; }
.badge-approved { background:#d1fae5; color:#065f46; }
.badge-rejected { background:#fee2e2; color:#991b1b; }
details summary { cursor:pointer; }
</style>
</head>
<body>
<div class="wrap">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Malta — ECI student application</h1>
      <p class="text-muted mb-0">European College of Innovation application form.</p>
    </div>
    <a class="btn btn-dark" href="eci-malta-application.php?staff=1">Add student</a>
  </div>
  <?php if ($notice !== ''): ?><div class="alert alert-success"><?= eci_cell($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= eci_cell($error) ?></div><?php endif; ?>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
      <select class="form-select" name="status">
        <?php foreach (['all' => 'All', 'pending' => 'Pending', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <input class="form-control" name="q" value="<?= eci_cell($search) ?>" placeholder="Name, email, reference, course">
    </div>
    <div class="col-md-2"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>
  <div class="table-responsive bg-white border rounded">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Student</th><th>Course</th><th>Documents</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No ECI applications yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php
          $quals = json_decode((string) ($row['qualifications_json'] ?? ''), true);
          if (!is_array($quals)) {
              $quals = [];
          }
          $docs = array_filter([
              eci_doc_link((string) $row['passport_file'], 'Passport'),
              eci_doc_link((string) $row['transcripts_file'], 'Transcripts'),
              eci_doc_link((string) $row['cv_file'], 'CV'),
              eci_doc_link((string) $row['english_file'], 'English'),
              eci_doc_link((string) $row['photo_file'], 'Photo'),
          ]);
        ?>
        <tr>
          <td>
            <strong><?= eci_cell(trim((string) $row['title'] . ' ' . (string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?></strong><br>
            <span class="small text-muted"><?= eci_cell((string) $row['reference_id']) ?> · <?= eci_cell((string) $row['email']) ?><br>
            <?= eci_cell((string) $row['phone']) ?> · <?= eci_cell((string) $row['nationality']) ?></span>
          </td>
          <td>
            <?= eci_cell((string) $row['course_title']) ?><br>
            <span class="small text-muted"><?= eci_cell((string) $row['entry_month'] . ' ' . (string) $row['entry_year']) ?> · <?= eci_cell((string) $row['study_mode']) ?></span>
          </td>
          <td class="small"><?= $docs === [] ? '—' : implode(' · ', $docs) ?></td>
          <td>
            <span class="badge badge-<?= eci_cell((string) $row['status']) ?>"><?= eci_cell(str_replace('_', ' ', (string) $row['status'])) ?></span>
            <form method="post" class="d-flex gap-1 mt-2">
              <input type="hidden" name="application_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="admin_notes" value="<?= eci_cell((string) ($row['admin_notes'] ?? '')) ?>">
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['pending', 'under_review', 'approved', 'rejected'] as $st): ?>
                <option value="<?= $st ?>" <?= $row['status'] === $st ? 'selected' : '' ?>><?= str_replace('_', ' ', $st) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-dark">Save</button>
            </form>
          </td>
        </tr>
        <tr>
          <td colspan="4" class="bg-light">
            <details>
              <summary>Full application</summary>
              <div class="row small py-2">
                <div class="col-md-6">
                  <div>Previous family name: <?= eci_cell((string) $row['previous_family_name']) ?></div>
                  <div>Gender: <?= eci_cell((string) $row['gender']) ?> · Date of birth: <?= eci_cell((string) $row['date_of_birth']) ?></div>
                  <div>English first language: <?= eci_cell((string) $row['english_first']) ?></div>
                  <div>Disability: <?= eci_cell((string) $row['disability']) ?></div>
                  <div>Studied at ECI: <?= eci_cell((string) $row['studied_before']) ?> <?= eci_cell((string) $row['studied_details']) ?></div>
                  <div>Permanent address: <?= nl2br(eci_cell((string) $row['permanent_address'])) ?></div>
                  <div>Correspondence: <?= nl2br(eci_cell((string) $row['correspondence_address'])) ?></div>
                  <div>WhatsApp / Skype: <?= eci_cell((string) $row['whatsapp']) ?></div>
                  <div>LinkedIn: <?= eci_cell((string) $row['linkedin']) ?></div>
                </div>
                <div class="col-md-6">
                  <div>Criminal conviction: <?= eci_cell((string) $row['criminal_conviction']) ?></div>
                  <div>Ethnic origin: <?= eci_cell((string) $row['ethnic_origin']) ?></div>
                  <div>English test: <?= eci_cell((string) $row['english_test']) ?> <?= eci_cell((string) $row['english_result']) ?></div>
                  <div>Agent: <?= eci_cell((string) $row['via_agent']) ?> <?= eci_cell((string) $row['agent_details']) ?></div>
                  <div>Signature: <?= eci_cell((string) $row['signature_name']) ?> · <?= eci_cell((string) $row['signature_date']) ?></div>
                  <div class="mt-1"><strong>Qualifications</strong>
                    <ul class="mb-0">
                      <?php foreach ($quals as $qual): ?>
                      <li><?= eci_cell((string) ($qual['qualification'] ?? '')) ?> — <?= eci_cell((string) ($qual['grade'] ?? '')) ?> — <?= eci_cell((string) ($qual['completion'] ?? '')) ?> — <?= eci_cell((string) ($qual['establishment'] ?? '')) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

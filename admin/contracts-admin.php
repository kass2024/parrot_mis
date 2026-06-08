<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';

if (!pcvc_is_superadmin_role($_SESSION['role'] ?? '')) {
    http_response_code(403);
    exit('Superadmin access required');
}

pcvc_staff_contract_ensure_schema($conn);

$sql = "
    SELECT
        a.id,
        a.full_name,
        a.email,
        a.role,
        a.position,
        c.id AS contract_id,
        c.status,
        c.contract_title,
        c.source_docx_path,
        c.source_pdf_path,
        c.signed_pdf_path,
        c.pdf_path,
        c.signed_at,
        c.uploaded_at
    FROM admins a
    LEFT JOIN employment_contracts c ON c.admin_id = a.id
    WHERE LOWER(TRIM(COALESCE(a.role, ''))) NOT IN ('superadmin')
    ORDER BY a.full_name ASC
";
$staffRows = $conn->query($sql)?->fetch_all(MYSQLI_ASSOC) ?? [];
$totalStaff = count($staffRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Employment Contracts</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --primary: #427431; --secondary: #3661B9; }
    body { background: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }
    .page-header {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
    }
    .card-panel { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.06); padding: 1.25rem; }
    .upload-row { border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; margin-bottom: .75rem; }
    .upload-row.signed { border-color: #86efac; background: #f0fdf4; }
    .upload-row.pending { border-color: #fde68a; background: #fffbeb; }
    .upload-row.empty { border-color: #e2e8f0; background: #fafbfc; }
    .upload-row.search-hidden { display: none !important; }
    .search-wrap {
      position: relative; max-width: 520px;
    }
    .search-wrap .bi-search {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      color: #64748b; pointer-events: none;
    }
    .search-wrap input {
      padding-left: 2.25rem; border-radius: 10px; border: 1px solid #cbd5e1;
    }
    .search-wrap input:focus {
      border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(54, 97, 185, .15);
    }
    #searchEmpty { display: none; }
  </style>
</head>
<body>
<div class="container-fluid py-4 px-3 px-md-4">
  <div class="page-header">
    <h4 class="mb-1 fw-bold"><i class="bi bi-file-earmark-person me-2"></i>Staff Employment Contracts</h4>
    <p class="mb-0 small opacity-90">
      Upload a Word (.docx) contract per staff member. Details are auto-filled from their profile; staff review and e-sign.
    </p>
  </div>

  <div class="card-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
      <h6 class="fw-bold mb-0">All staff contracts</h6>
      <span class="text-muted small" id="staffCount"><?= $totalStaff ?> staff</span>
    </div>

    <div class="search-wrap mb-3">
      <i class="bi bi-search"></i>
      <input type="search" id="staffSearch" class="form-control"
        placeholder="Search by name, email, role, position, or contract status…" autocomplete="off">
    </div>

    <?php if (!$staffRows): ?>
      <p class="text-muted mb-0">No staff accounts found.</p>
    <?php endif; ?>

    <div id="staffList">
    <?php foreach ($staffRows as $row):
      $status = pcvc_staff_contract_row_status($row);
      $rowClass = $status['code'] === 'signed' ? 'signed' : ($status['code'] === 'pending_signature' ? 'pending' : 'empty');
      $staffId = (int) $row['id'];
      $hasTemplate = !empty($row['source_docx_path']) || !empty($row['source_pdf_path']);
      $searchBlob = strtolower(implode(' ', [
        (string) ($row['full_name'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['role'] ?? ''),
        (string) ($row['position'] ?? ''),
        (string) ($row['contract_title'] ?? ''),
        $status['label'],
        $status['code'],
      ]));
    ?>
    <div class="upload-row <?= $rowClass ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>">
      <div class="row g-3 align-items-center">
        <div class="col-lg-3">
          <div class="fw-semibold"><?= htmlspecialchars((string) $row['full_name']) ?></div>
          <div class="small text-muted"><?= htmlspecialchars((string) $row['email']) ?></div>
          <div class="small text-muted"><?= htmlspecialchars((string) ($row['position'] ?: $row['role'])) ?></div>
        </div>
        <div class="col-lg-2">
          <span class="badge text-bg-<?= $status['badge'] ?>"><?= htmlspecialchars($status['label']) ?></span>
          <?php if (!empty($row['signed_at'])): ?>
            <div class="small text-muted mt-1">Signed <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string) $row['signed_at']))) ?></div>
          <?php elseif (!empty($row['uploaded_at'])): ?>
            <div class="small text-muted mt-1">Uploaded <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string) $row['uploaded_at']))) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-lg-4">
          <form class="upload-form" enctype="multipart/form-data">
            <input type="hidden" name="staff_id" value="<?= $staffId ?>">
            <div class="mb-2">
              <input type="text" class="form-control form-control-sm" name="contract_title"
                placeholder="Contract title (optional)"
                value="<?= htmlspecialchars((string) ($row['contract_title'] ?? '')) ?>">
            </div>
            <input type="file" class="form-control form-control-sm" name="contract_docx"
              accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
          </form>
        </div>
        <div class="col-lg-3 text-lg-end">
          <button type="button" class="btn btn-success btn-sm btn-upload mb-1" data-staff-id="<?= $staffId ?>">
            <i class="bi bi-cloud-upload"></i> Upload Word
          </button>
          <?php if ($hasTemplate): ?>
            <a class="btn btn-outline-primary btn-sm mb-1" target="_blank"
              href="view-staff-contract-pdf.php?staff_id=<?= $staffId ?>&type=source">View filled PDF</a>
            <button type="button" class="btn btn-outline-danger btn-sm mb-1 btn-delete-contract"
              data-staff-id="<?= $staffId ?>"
              data-staff-name="<?= htmlspecialchars((string) $row['full_name'], ENT_QUOTES) ?>"
              data-is-signed="<?= $status['code'] === 'signed' ? '1' : '0' ?>">
              <i class="bi bi-trash"></i> Delete
            </button>
          <?php endif; ?>
          <?php if ($status['code'] === 'signed'): ?>
            <a class="btn btn-primary btn-sm mb-1"
              href="download-staff-contract.php?staff_id=<?= $staffId ?>&type=signed">Download signed</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <p class="text-muted text-center py-4 mb-0" id="searchEmpty">
      <i class="bi bi-search me-1"></i> No staff match your search.
    </p>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
(function () {
  const total = <?= (int) $totalStaff ?>;
  const $search = $('#staffSearch');
  const $rows = $('#staffList .upload-row');
  const $count = $('#staffCount');
  const $empty = $('#searchEmpty');

  function runSearch() {
    const q = $search.val().trim().toLowerCase();
    const terms = q ? q.split(/\s+/).filter(Boolean) : [];
    let visible = 0;

    $rows.each(function () {
      const hay = String($(this).data('search') || '');
      const match = terms.length === 0 || terms.every(t => hay.indexOf(t) !== -1);
      $(this).toggleClass('search-hidden', !match);
      if (match) visible++;
    });

    if (terms.length === 0) {
      $count.text(total + ' staff');
    } else {
      $count.text(visible + ' of ' + total + ' staff');
    }
    $empty.toggle(terms.length > 0 && visible === 0);
  }

  let timer = null;
  $search.on('input', function () {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 120);
  });
  runSearch();

  $('.btn-upload').on('click', function () {
    const staffId = $(this).data('staff-id');
    const row = $(this).closest('.upload-row');
    const form = row.find('form.upload-form')[0];
    const fd = new FormData(form);
    const btn = $(this);
    btn.prop('disabled', true).text('Uploading…');

    $.ajax({
      url: 'upload-staff-contract.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json'
    }).done(function (data) {
      btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Upload Word');
      if (!data || !data.success) {
        alert(data?.message || 'Upload failed');
        return;
      }
      alert(data.message || 'Uploaded');
      location.reload();
    }).fail(function (xhr) {
      btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Upload Word');
      let msg = 'Upload failed';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
      alert(msg);
    });
  });

  $('.btn-delete-contract').on('click', function () {
    const staffId = $(this).data('staff-id');
    const staffName = $(this).data('staff-name') || 'this staff member';
    const isSigned = String($(this).data('is-signed')) === '1';
    let msg = 'Delete the contract for ' + staffName + '?';
    if (isSigned) {
      msg += '\n\nThis contract is already signed. The signed PDF and signature will also be permanently removed.';
    } else {
      msg += '\n\nThe Word template and generated files will be permanently removed.';
    }
    if (!confirm(msg)) return;

    const btn = $(this);
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.ajax({
      url: 'delete-staff-contract.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ staff_id: staffId }),
      dataType: 'json'
    }).done(function (data) {
      if (!data || !data.success) {
        btn.prop('disabled', false).html('<i class="bi bi-trash"></i> Delete');
        alert(data?.message || 'Delete failed');
        return;
      }
      alert(data.message || 'Deleted');
      location.reload();
    }).fail(function (xhr) {
      btn.prop('disabled', false).html('<i class="bi bi-trash"></i> Delete');
      let errMsg = 'Delete failed';
      try { errMsg = JSON.parse(xhr.responseText).message || errMsg; } catch (e) {}
      alert(errMsg);
    });
  });
})();
</script>
</body>
</html>
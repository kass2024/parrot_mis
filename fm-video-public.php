<?php
declare(strict_types=1);

/**
 * Public Francophonie Mobility video page — shareable link with owner details.
 * Usage: fm-video-public.php?t=TOKEN
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/secure_file.php';

fm_ensure_schema($conn);

$token = trim((string) ($_GET['t'] ?? ''));
$row = null;
if ($token !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
    $st = $conn->prepare(
        'SELECT id, reference_id, first_name, last_name, email, phone_area_code, phone_number,
                nationality, country_of_residence, profession,
                video_file, video_source, video_pcloud_link, video_pcloud_fileid, created_at
         FROM francophonie_mobility_applications
         WHERE video_public_token = ? LIMIT 1'
    );
    $st->bind_param('s', $token);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}

$ownerName = $row ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) : '';
$pcloudUrl = $row ? trim((string) ($row['video_pcloud_link'] ?? '')) : '';
$downloadUrl = $pcloudUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $row ? htmlspecialchars($ownerName . ' — Intro Video', ENT_QUOTES, 'UTF-8') : 'Video not found' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:#f4f6f3;font-family:Segoe UI,system-ui,sans-serif}
.hero{background:linear-gradient(135deg,#1e4d2b,#3661B9);color:#fff;padding:1.5rem 0;margin-bottom:1.25rem}
.card{border:0;border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
.meta dt{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.meta dd{margin-bottom:.75rem;font-weight:600;color:#0f172a}
</style>
</head>
<body>
<div class="hero">
  <div class="container">
    <div class="small opacity-75">Canada Francophonie Mobility</div>
    <h1 class="h4 mb-0">Candidate Introduction Video</h1>
  </div>
</div>
<div class="container pb-5" style="max-width:820px">
<?php if (!$row): ?>
  <div class="alert alert-warning">This video link is invalid or no longer available.</div>
<?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-3">
        <div>
          <h2 class="h5 mb-1"><?= htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') ?></h2>
          <div class="text-muted small">Reference <code><?= htmlspecialchars((string) $row['reference_id'], ENT_QUOTES, 'UTF-8') ?></code></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($downloadUrl !== ''): ?>
          <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <i class="fas fa-download me-1"></i> Download video
          </a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-primary btn-sm" id="copyDetailsBtn">
            <i class="fas fa-copy me-1"></i> Copy owner + link
          </button>
        </div>
      </div>

      <dl class="row meta mb-0">
        <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?></dd>
        <dt class="col-sm-4">Phone</dt><dd class="col-sm-8">+<?= htmlspecialchars(trim(($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? '')), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt class="col-sm-4">Nationality</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($row['nationality'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt class="col-sm-4">Residence</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($row['country_of_residence'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt class="col-sm-4">Profession</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($row['profession'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt class="col-sm-4">Video source</dt><dd class="col-sm-8"><?= htmlspecialchars(ucfirst((string) ($row['video_source'] ?? 'upload')), ENT_QUOTES, 'UTF-8') ?> · pCloud only</dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <?php if ($pcloudUrl !== ''): ?>
        <div class="alert alert-info mb-0">
          This video is stored on pCloud (not on the MIS server).
          <a class="fw-semibold" href="<?= htmlspecialchars($pcloudUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open / download video</a>
        </div>
      <?php else: ?>
        <div class="alert alert-secondary mb-0">No video file is attached to this application.</div>
      <?php endif; ?>
    </div>
  </div>

<script>
(function () {
  const text = <?= json_encode(
      "Francophonie Mobility — Candidate Video\n"
      . "Owner: {$ownerName}\n"
      . "Reference: " . ($row['reference_id'] ?? '') . "\n"
      . "Email: " . ($row['email'] ?? '') . "\n"
      . "Phone: +" . trim(($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? '')) . "\n"
      . "Nationality: " . ($row['nationality'] ?? '') . "\n"
      . "Public page: " . ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/fm-video-public.php?t=' . $token) . "\n"
      . ($pcloudUrl !== '' ? ("pCloud download: {$pcloudUrl}\n") : ''),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  ) ?>;
  const btn = document.getElementById('copyDetailsBtn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(text);
      btn.innerHTML = '<i class="fas fa-check me-1"></i> Copied';
      setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy me-1"></i> Copy owner + link'; }, 1800);
    } catch (e) {
      prompt('Copy this text:', text);
    }
  });
})();
</script>
<?php endif; ?>
</div>
</body>
</html>

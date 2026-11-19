<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/staff_contract_witness.php';
require_once __DIR__ . '/helpers/contract_signature_image.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$contract = $token !== '' ? pcvc_staff_contract_witness_by_token($conn, $token) : null;
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$contract) {
        $error = 'This witness link is not valid.';
    } elseif (trim((string) ($contract['witness_signed_at'] ?? '')) !== '') {
        $error = 'This witness section is already signed.';
    } else {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $nationalId = trim((string) ($_POST['national_id'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        $place = trim((string) ($_POST['place'] ?? ''));
        $signature = trim((string) ($_POST['signature'] ?? ''));
        if (strlen($name) < 2) {
            $error = 'Enter your full name.';
        } elseif ($nationalId === '') {
            $error = 'Enter your national ID or passport number.';
        } elseif ($contact === '') {
            $error = 'Enter your telephone or email.';
        } elseif ($place === '') {
            $error = 'Enter the place of signing.';
        } elseif (contract_signature_raw_bytes($signature) === null) {
            $error = 'Draw your signature.';
        } else {
            try {
                pcvc_staff_contract_apply_witness_to_saved_files(
                    $contract,
                    $name,
                    $nationalId,
                    $contact,
                    $place,
                    $signature
                );
                $sigRel = 'uploads/staff_contracts/signatures/witness_' . (int) $contract['admin_id'] . '_' . time() . '.png';
                $sigAbs = pcvc_staff_contract_abs_path($sigRel);
                $png = contract_signature_to_display_png($signature) ?? contract_signature_raw_bytes($signature);
                if ($png) {
                    file_put_contents($sigAbs, $png);
                }
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $id = (int) $contract['id'];
                $stmt = $conn->prepare(
                    'UPDATE employment_contracts
                     SET witness_name = ?, witness_national_id = ?, witness_contact = ?, witness_place = ?,
                         witness_signature_file = ?, witness_signed_at = NOW(), witness_signed_ip = ?
                     WHERE id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('Database error');
                }
                $stmt->bind_param('ssssssi', $name, $nationalId, $contact, $place, $sigRel, $ip, $id);
                $stmt->execute();
                $stmt->close();
                $done = true;
                $contract['witness_signed_at'] = date('Y-m-d H:i:s');
                $contract['witness_name'] = $name;
            } catch (Throwable $e) {
                $error = 'The signature could not be saved. Please try again.';
            }
        }
    }
}

$already = $contract && trim((string) ($contract['witness_signed_at'] ?? '')) !== '';
$staffName = trim((string) ($contract['full_name'] ?? ''));
$position = trim((string) ($contract['position'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Witness signature</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }
    .wrap { max-width: 720px; margin: 2rem auto; }
    .card-panel { background: #fff; border-radius: 14px; box-shadow: 0 8px 28px rgba(15, 23, 42, .08); padding: 1.5rem; }
    .hero { background: linear-gradient(135deg, #3661B9, #427431); color: #fff; border-radius: 14px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; }
    #signaturePad { width: 100%; height: 180px; border: 2px dashed #94a3b8; border-radius: 10px; background: #fff; touch-action: none; }
  </style>
</head>
<body>
<div class="wrap px-3">
  <div class="hero">
    <div class="small text-uppercase opacity-75">Parrot Canada Visa Consultant</div>
    <h1 class="h4 mb-1">Witness the employment contract</h1>
    <p class="mb-0 small">You are signing only as witness. Draw your own signature and enter your own details.</p>
  </div>
  <div class="card-panel">
    <?php if (!$contract): ?>
      <h2 class="h5">Link not valid</h2>
      <p class="text-muted mb-0">Ask the employee to send you a new witness link.</p>
    <?php elseif ($done || $already): ?>
      <h2 class="h5 text-success">Witness signature saved</h2>
      <p class="mb-0">Thank you<?= $staffName !== '' ? ', the contract for <strong>' . htmlspecialchars($staffName) . '</strong> now includes your witness details' : '' ?>.</p>
    <?php else: ?>
      <p class="mb-3">Contract for <strong><?= htmlspecialchars($staffName) ?></strong><?= $position !== '' ? ' · ' . htmlspecialchars($position) : '' ?>.</p>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" id="witnessForm">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="signature" id="signatureInput">
        <label class="form-label fw-semibold">Full name</label>
        <input class="form-control mb-3" name="full_name" required value="<?= htmlspecialchars((string) ($_POST['full_name'] ?? '')) ?>">
        <label class="form-label fw-semibold">National ID or passport</label>
        <input class="form-control mb-3" name="national_id" required value="<?= htmlspecialchars((string) ($_POST['national_id'] ?? '')) ?>">
        <label class="form-label fw-semibold">Telephone or email</label>
        <input class="form-control mb-3" name="contact" required value="<?= htmlspecialchars((string) ($_POST['contact'] ?? '')) ?>">
        <label class="form-label fw-semibold">Place of signing</label>
        <input class="form-control mb-3" name="place" required placeholder="City, country" value="<?= htmlspecialchars((string) ($_POST['place'] ?? '')) ?>">
        <label class="form-label fw-semibold">Your signature</label>
        <canvas id="signaturePad"></canvas>
        <button type="button" class="btn btn-outline-secondary btn-sm my-2" id="clearSig">Clear</button>
        <button type="submit" class="btn btn-primary w-100 btn-lg">Sign as witness</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php if ($contract && !$done && !$already): ?>
<script>
(function () {
  const canvas = document.getElementById('signaturePad');
  const ctx = canvas.getContext('2d');
  let drawing = false;
  let hasInk = false;
  function resize() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
  }
  function pos(e) {
    const r = canvas.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  }
  function start(e) { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
  function move(e) {
    if (!drawing) return;
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    hasInk = true;
    e.preventDefault();
  }
  function end() { drawing = false; }
  resize();
  window.addEventListener('resize', resize);
  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', end);
  document.getElementById('clearSig').addEventListener('click', function () {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasInk = false;
  });
  document.getElementById('witnessForm').addEventListener('submit', function (e) {
    if (!hasInk) {
      e.preventDefault();
      alert('Draw your signature first.');
      return;
    }
    document.getElementById('signatureInput').value = canvas.toDataURL('image/png');
  });
})();
</script>
<?php endif; ?>
</body>
</html>

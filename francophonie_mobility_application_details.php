<?php
/**
 * francophonie_mobility_application_details.php — Admin detail + document viewer.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
fm_ensure_schema($conn);
require_once __DIR__ . '/helpers/francophonie_mobility_notify.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/secure_file.php';

if (empty($_SESSION['id'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="alert alert-danger">Invalid ID</div>';
    exit;
}

$st = $conn->prepare('SELECT * FROM francophonie_mobility_applications WHERE id = ? LIMIT 1');
$st->bind_param('i', $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    echo '<div class="alert alert-danger">Not found</div>';
    exit;
}

function fm_doc_link(string $relPath, string $label): string
{
    return pcvc_secure_file_links_html($relPath, $label);
}

$name = htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name']), ENT_QUOTES, 'UTF-8');
$ref = htmlspecialchars($row['reference_id'], ENT_QUOTES, 'UTF-8');
$status = htmlspecialchars(ucwords(str_replace('_', ' ', $row['status'])), ENT_QUOTES, 'UTF-8');

$frenchCerts = [];
if ($row['french_tef']) $frenchCerts[] = 'TEF';
if ($row['french_tcf']) $frenchCerts[] = 'TCF';
$englishCerts = [];
if ($row['english_toefl']) $englishCerts[] = 'TOEFL';
if ($row['english_ielts']) $englishCerts[] = 'IELTS';

$videoToken = trim((string) ($row['video_public_token'] ?? ''));
$videoPcloud = trim((string) ($row['video_pcloud_link'] ?? ''));
$videoLocal = trim((string) ($row['video_file'] ?? ''));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$publicVideoUrl = $videoToken !== ''
    ? $scheme . '://' . $host . $basePath . '/fm-video-public.php?t=' . rawurlencode($videoToken)
    : '';
$ownerPlain = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$copyBundle = $publicVideoUrl !== ''
    ? "Francophonie Mobility — Candidate Video\n"
        . "Owner: {$ownerPlain}\n"
        . "Reference: " . ($row['reference_id'] ?? '') . "\n"
        . "Email: " . ($row['email'] ?? '') . "\n"
        . "Phone: +" . trim(($row['phone_area_code'] ?? '') . ' ' . ($row['phone_number'] ?? '')) . "\n"
        . "Nationality: " . ($row['nationality'] ?? '') . "\n"
        . "Public page: {$publicVideoUrl}\n"
        . ($videoPcloud !== '' ? "pCloud download: {$videoPcloud}\n" : '')
    : '';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <?= fm_build_form_summary_html($row) ?>
        <?php if (!empty($row['admin_notes'])): ?>
        <div class="alert alert-secondary mt-3">
            <strong>Admin notes:</strong><br><?= nl2br(htmlspecialchars($row['admin_notes'], ENT_QUOTES, 'UTF-8')) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['approval_package_sent_at'])): ?>
        <p class="small text-success"><i class="fas fa-envelope-circle-check"></i> Approval package emailed on <?= htmlspecialchars($row['approval_package_sent_at']) ?></p>
        <?php endif; ?>

        <?php if ($videoLocal !== '' || $videoPcloud !== '' || $publicVideoUrl !== ''): ?>
        <div class="card mt-3 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-video me-2 text-danger"></i>Introduction Video</h6>
                <p class="small text-muted mb-2">
                    Source: <strong><?= htmlspecialchars(ucfirst((string) ($row['video_source'] ?: 'upload')), ENT_QUOTES, 'UTF-8') ?></strong>
                    · stored on pCloud only (not on server disk)
                </p>
                <?php if ($videoPcloud !== ''): ?>
                <div class="alert alert-light border mb-3">
                    <i class="fas fa-cloud me-1 text-primary"></i>
                    Preview / download is available from pCloud.
                    <a href="<?= htmlspecialchars($videoPcloud, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open video</a>
                </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php if ($publicVideoUrl !== ''): ?>
                    <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($publicVideoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <i class="fas fa-external-link-alt me-1"></i> Open public page
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="fmCopyVideoLink"
                            data-copy="<?= htmlspecialchars($copyBundle, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-copy me-1"></i> Copy public link + owner details
                    </button>
                    <?php endif; ?>
                    <?php if ($videoPcloud !== ''): ?>
                    <a class="btn btn-sm btn-success" href="<?= htmlspecialchars($videoPcloud, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <i class="fas fa-cloud-download-alt me-1"></i> pCloud download
                    </a>
                    <?php endif; ?>
                </div>
                <?php if ($publicVideoUrl !== ''): ?>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="fmPublicVideoUrl" readonly value="<?= htmlspecialchars($publicVideoUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn btn-outline-secondary" id="fmCopyPublicUrl">Copy URL</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function () {
          async function copyText(text, btn) {
            try {
              await navigator.clipboard.writeText(text);
              if (btn) {
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Copied';
                setTimeout(() => { btn.innerHTML = old; }, 1600);
              }
            } catch (e) {
              prompt('Copy this:', text);
            }
          }
          document.getElementById('fmCopyVideoLink')?.addEventListener('click', function () {
            copyText(this.getAttribute('data-copy') || '', this);
          });
          document.getElementById('fmCopyPublicUrl')?.addEventListener('click', function () {
            const input = document.getElementById('fmPublicVideoUrl');
            copyText(input ? input.value : '', this);
          });
        })();
        </script>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Quick actions</h6>
                <p class="small text-muted mb-2">All actions notify the candidate by <strong>email only</strong>.</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-warning btn-sm" onclick="setStatus(<?= (int)$row['id'] ?>, 'under_review')">Mark Under Review</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="setStatus(<?= (int)$row['id'] ?>, 'approved')">Approve &amp; Send Package</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="setStatus(<?= (int)$row['id'] ?>, 'rejected')">Reject</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="resendEmail(<?= (int)$row['id'] ?>)">
                        <i class="fas fa-paper-plane"></i> Resend status email
                    </button>
                    <?php if (($row['status'] ?? '') === 'approved'): ?>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="resendPackage(<?= (int)$row['id'] ?>)">
                        <i class="fas fa-file-export"></i> Resend approval package
                    </button>
                    <?php endif; ?>
                    <a href="admin-generate-fm-contract.php?application_id=<?= (int)$row['id'] ?>" target="_blank" class="btn btn-outline-dark btn-sm">
                        <i class="fas fa-file-signature"></i> Issue E-Sign Contract
                    </a>
                    <a href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-envelope"></i> Open in mail client
                    </a>
                </div>
                <hr>
                <p class="small mb-1"><strong>Reference:</strong> <code><?= $ref ?></code></p>
                <p class="small mb-1"><strong>Status:</strong> <?= $status ?></p>
                <p class="small mb-0"><strong>User ID:</strong> <code><?= htmlspecialchars($row['user_id']) ?></code></p>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title">Documents</h6>
                <?= fm_doc_link($row['cv_file'] ?? '', 'CV') ?>
                <?= fm_doc_link($row['french_cert_file'] ?? '', 'French Certificate') ?>
                <?= fm_doc_link($row['english_cert_file'] ?? '', 'English Certificate') ?>
                <?php
                $academicList = fm_parse_stored_files((string) ($row['academic_docs_file'] ?? ''));
                if ($academicList === []) {
                    echo '<div class="text-muted small">Academic Documents: none uploaded</div>';
                } else {
                    foreach ($academicList as $i => $apath) {
                        $label = count($academicList) > 1 ? 'Academic Document ' . ($i + 1) : 'Academic Documents';
                        echo fm_doc_link($apath, $label);
                    }
                }
                ?>
                <?php if ($videoLocal !== '' || $videoPcloud !== ''): ?>
                <hr>
                <div class="small fw-semibold mb-1">Video</div>
                <?php if ($publicVideoUrl !== ''): ?>
                <a href="<?= htmlspecialchars($publicVideoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger w-100 mb-1">
                    <i class="fas fa-play me-1"></i> Public video page
                </a>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

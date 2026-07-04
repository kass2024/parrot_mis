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
            </div>
        </div>
    </div>
</div>

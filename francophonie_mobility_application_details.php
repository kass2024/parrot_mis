<?php
/**
 * francophonie_mobility_application_details.php — Admin detail + document viewer.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
fm_ensure_schema($conn);
require_once __DIR__ . '/helpers/francophonie_mobility_notify.php';
require_once __DIR__ . '/helpers/francophonie_mobility_files.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';
require_once __DIR__ . '/helpers/fm_public_share.php';
require_once __DIR__ . '/helpers/secure_file.php';
fm_ensure_documents_schema($conn);

fm_require_staff_or_manager_portal($conn);

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
$status = htmlspecialchars(fm_application_status_label((string) ($row['status'] ?? '')), ENT_QUOTES, 'UTF-8');

$frenchCerts = [];
if ($row['french_tef']) $frenchCerts[] = 'TEF';
if ($row['french_tcf']) $frenchCerts[] = 'TCF';
$englishCerts = [];
if ($row['english_toefl']) $englishCerts[] = 'TOEFL';
if ($row['english_ielts']) $englishCerts[] = 'IELTS';

$videoToken = trim((string) ($row['video_public_token'] ?? ''));
$videoPcloud = trim((string) ($row['video_pcloud_link'] ?? ''));
$videoLocal = trim((string) ($row['video_file'] ?? ''));
$hasVideo = $videoLocal !== '' || $videoPcloud !== '';
$inviteToken = trim((string) ($row['video_invite_token'] ?? ''));
$inviteUsed = !empty($row['video_invite_used_at']);

// Ensure dual-token secured share credentials exist for copy / view-details.
[$shareToken, $shareSecret] = fm_ensure_public_share_tokens($conn, $row);
$publicDetailsUrl = ($shareToken !== '' && $shareSecret !== '')
    ? fm_public_details_url($shareToken, $shareSecret)
    : '';
$secureVideoUrl = ($hasVideo && $shareToken !== '' && $shareSecret !== '')
    ? fm_public_video_url($shareToken, $shareSecret)
    : '';

$inviteUrl = ($inviteToken !== '' && !$inviteUsed && !$hasVideo)
    ? fm_public_base_url() . '/fm-video-invite.php?t=' . rawurlencode($inviteToken)
    : '';
$ownerPlain = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$phoneDigits = preg_replace('/\D+/', '', (string) (($row['phone_area_code'] ?? '') . ($row['phone_number'] ?? ''))) ?: '';
$inviteWaText = $inviteUrl !== ''
    ? "Hello {$ownerPlain},\n\n"
        . "Please upload or record your introduction video for Canada Francophonie Mobility.\n"
        . "Reference: " . ($row['reference_id'] ?? '') . "\n"
        . "One-time link (upload/record only): {$inviteUrl}\n\n"
        . "This link can be used once. Thank you."
    : '';
$inviteWaUrl = $inviteWaText !== ''
    ? ($phoneDigits !== ''
        ? 'https://wa.me/' . $phoneDigits . '?text=' . rawurlencode($inviteWaText)
        : 'https://wa.me/?text=' . rawurlencode($inviteWaText))
    : '';
$copyBundle = $publicDetailsUrl !== ''
    ? fm_public_copy_bundle($row, $publicDetailsUrl)
    : '';
$appId = (int) $row['id'];
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

        <?php if ($publicDetailsUrl !== ''): ?>
        <div class="card mt-3 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title mb-2"><i class="fas fa-lock me-2 text-primary"></i>Secured view details</h6>
                <p class="small text-muted mb-3">
                    Full application + attachments<?= $hasVideo ? ' + video' : '' ?>.
                    Link uses two keys — pCloud download URL is never shared.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($publicDetailsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <i class="fas fa-external-link-alt me-1"></i> Open view details
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-copy="<?= htmlspecialchars($copyBundle, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="copyFmFromBtn(this)">
                        <i class="fas fa-copy me-1"></i> Copy view details + owner
                    </button>
                    <?php if ($secureVideoUrl !== ''): ?>
                    <a class="btn btn-sm btn-outline-danger" href="<?= htmlspecialchars($secureVideoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <i class="fas fa-play me-1"></i> Open video
                    </a>
                    <?php endif; ?>
                </div>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="fmPublicVideoUrl" readonly value="<?= htmlspecialchars($publicDetailsUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn btn-outline-secondary" onclick="copyFmInput('fmPublicVideoUrl', this)">Copy URL</button>
                </div>
                <?php if ($hasVideo): ?>
                <p class="small text-muted mt-2 mb-0">
                    Video source: <strong><?= htmlspecialchars(ucfirst((string) ($row['video_source'] ?: 'upload')), ENT_QUOTES, 'UTF-8') ?></strong>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$hasVideo): ?>
        <div class="card mt-3 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title mb-2"><i class="fas fa-video me-2 text-muted"></i>No introduction video yet</h6>
                <p class="small text-muted mb-3">Create a one-time link so this candidate can only upload or record a video (not the full form).</p>
                <?php if ($inviteUrl !== ''): ?>
                <div class="alert alert-info py-2 small mb-3">
                    Invite ready
                    <?php if (!empty($row['video_invite_opened_at'])): ?>
                    · opened <?= htmlspecialchars((string) $row['video_invite_opened_at'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if (!empty($row['video_invite_created_at'])): ?>
                    · created <?= htmlspecialchars((string) $row['video_invite_created_at'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" class="form-control" id="fmInviteUrl" readonly value="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn btn-outline-secondary"
                            data-copy="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="copyFmFromBtn(this)">Copy link</button>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-copy="<?= htmlspecialchars($inviteWaText, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="copyFmFromBtn(this)">
                        <i class="fas fa-copy me-1"></i> Copy message + reference
                    </button>
                    <?php if ($inviteWaUrl !== ''): ?>
                    <a class="btn btn-sm btn-success" href="<?= htmlspecialchars($inviteWaUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <i class="fab fa-whatsapp me-1"></i> WhatsApp
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="createVideoInvite(<?= $appId ?>, true)">
                        <i class="fas fa-sync me-1"></i> Regenerate link
                    </button>
                </div>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-primary" onclick="createVideoInvite(<?= $appId ?>)">
                    <i class="fas fa-link me-1"></i> Create video upload link
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Quick actions</h6>
                <p class="small text-muted mb-2">Status actions notify the candidate by <strong>email only</strong>.</p>
                <div class="mb-2">
                    <label class="form-label small fw-semibold mb-1">Client group / status</label>
                    <select class="form-select form-select-sm" id="fmStatusSelect_<?= $appId ?>">
                        <?php foreach (fm_application_statuses() as $statusKey => $statusLab): ?>
                        <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($statusLab, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm w-100 mt-2" onclick="fmSetStatusFromSelect(<?= $appId ?>)">
                        <i class="fas fa-exchange-alt me-1"></i> Move to selected status
                    </button>
                </div>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setStatus(<?= $appId ?>, 'waiting_list')">Waiting List</button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="setStatus(<?= $appId ?>, 'ready2start')">Ready2start</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="setStatus(<?= $appId ?>, 'new_clients')">New clients</button>
                    <button type="button" class="btn btn-outline-dark btn-sm" onclick="setStatus(<?= $appId ?>, 'cancelled')">Cancelled</button>
                    <hr class="my-1">
                    <button type="button" class="btn btn-warning btn-sm" onclick="setStatus(<?= $appId ?>, 'under_review')">Mark Under Review</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="setStatus(<?= $appId ?>, 'approved')">Approve &amp; Send Package</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="setStatus(<?= $appId ?>, 'rejected')">Reject</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="resendEmail(<?= $appId ?>)">
                        <i class="fas fa-paper-plane"></i> Resend status email
                    </button>
                    <?php if (($row['status'] ?? '') === 'approved'): ?>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="resendPackage(<?= $appId ?>)">
                        <i class="fas fa-file-export"></i> Resend approval package
                    </button>
                    <?php endif; ?>
                    <?php if (!$hasVideo): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="createVideoInvite(<?= $appId ?><?= $inviteUrl !== '' ? ', true' : '' ?>)">
                        <i class="fas fa-video"></i> <?= $inviteUrl !== '' ? 'Regenerate video link' : 'Send video upload link' ?>
                    </button>
                    <?php endif; ?>
                    <a href="francophonie-record-invoice.php?application_id=<?= $appId ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-invoice-dollar"></i> Record invoice
                    </a>
                    <a href="admin-generate-fm-contract.php?application_id=<?= $appId ?>" target="_blank" class="btn btn-outline-dark btn-sm">
                        <i class="fas fa-file-signature"></i> Issue E-Sign Contract
                    </a>
                    <a href="admin-generate-fm-contract.php?variant=special&amp;application_id=<?= $appId ?>" target="_blank" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-file-signature"></i> Issue special e-sign
                    </a>
                    <a href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-envelope"></i> Open in mail client
                    </a>
                    <?php if (!(fm_manager_portal_logged_in() && !pcvc_can_access_francophonie_mobility($conn))): ?>
                    <hr class="my-1">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteApplication(<?= $appId ?>, <?= json_encode($row['reference_id']) ?>)">
                        <i class="fas fa-trash"></i> Delete full application
                    </button>
                    <?php endif; ?>
                </div>
                <hr>
                <p class="small mb-1"><strong>Reference:</strong> <code><?= $ref ?></code></p>
                <p class="small mb-1"><strong>Status:</strong> <?= $status ?></p>
                <p class="small mb-0"><strong>User ID:</strong> <code><?= htmlspecialchars($row['user_id']) ?></code></p>
            </div>
        </div>
        <?php
        $groupedDocs = fm_documents_grouped($conn, $appId);
        $visaGroups = fm_visa_submission_groups();

        $buildGroupOptions = static function (string $selected = '', bool $includeAttached = true) use ($visaGroups): string {
            $html = '<option value="">Move to group…</option>';
            if ($includeAttached) {
                $html .= '<optgroup label="Inbox"><option value="attached"'
                    . ($selected === 'attached' ? ' selected' : '') . '>Attached</option></optgroup>';
            }
            $html .= '<optgroup label="Visa submission documents">';
            foreach ($visaGroups as $slug => $lab) {
                $html .= '<option value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"'
                    . ($selected === $slug ? ' selected' : '') . '>'
                    . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            $html .= '</optgroup>';
            return $html;
        };

        $renderClientDocRow = static function (string $field, string $label, string $path, int $academicIndex = -1) use ($appId, $buildGroupOptions): string {
            $path = trim($path);
            if ($path === '') {
                return '<div class="text-muted small mb-2">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': none uploaded</div>';
            }
            $links = pcvc_secure_file_links_html($path, $label);
            $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
            return '<div class="border rounded p-2 mb-2 bg-white">'
                . '<div class="small fw-semibold mb-1">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
                . $links
                . '<div class="d-flex flex-wrap gap-1 mt-2 align-items-center">'
                . '<select class="form-select form-select-sm" style="max-width:240px" data-fm-client-field="' . $safeField . '" data-fm-academic-index="' . (int) $academicIndex . '">'
                . $buildGroupOptions('', true)
                . '</select>'
                . '<button type="button" class="btn btn-sm btn-outline-primary" onclick="fmFileClientDoc(' . $appId . ', this)">'
                . '<i class="fas fa-folder-open me-1"></i> Move to group</button>'
                . '</div></div>';
        };

        $renderManagedDoc = static function (array $doc) use ($buildGroupOptions, $appId): string {
            $id = (int) ($doc['id'] ?? 0);
            $name = htmlspecialchars((string) ($doc['original_name'] ?: basename((string) ($doc['stored_path'] ?? ''))), ENT_QUOTES, 'UTF-8');
            $path = (string) ($doc['stored_path'] ?? '');
            $links = pcvc_secure_file_links_html($path, $name);
            $group = (string) ($doc['doc_group'] ?? 'attached');
            return '<div class="border rounded p-2 mb-2 bg-white">'
                . '<div class="small fw-semibold mb-1">' . $name . '</div>'
                . $links
                . '<div class="d-flex flex-wrap gap-1 mt-2 align-items-center">'
                . '<select class="form-select form-select-sm" style="max-width:240px" data-fm-transfer="' . $id . '" '
                . 'onchange="fmTransferDoc(' . $appId . ',' . $id . ', this)">'
                . $buildGroupOptions($group, true)
                . '</select>'
                . '<button type="button" class="btn btn-sm btn-outline-primary" onclick="fmTransferDoc(' . $appId . ',' . $id . ', this)">Move</button>'
                . '<button type="button" class="btn btn-sm btn-outline-danger" onclick="fmDeleteDoc(' . $appId . ',' . $id . ')">Delete</button>'
                . '</div></div>';
        };
        ?>
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title">Client form documents</h6>
                <p class="small text-muted mb-2">Public-form uploads stay here. Use <strong>Move to group</strong> to file a copy into Attached or Visa submission documents.</p>
                <?= $renderClientDocRow('cv_file', 'CV', (string) ($row['cv_file'] ?? '')) ?>
                <?= $renderClientDocRow('french_cert_file', 'French Certificate', (string) ($row['french_cert_file'] ?? '')) ?>
                <?= $renderClientDocRow('english_cert_file', 'English Certificate', (string) ($row['english_cert_file'] ?? '')) ?>
                <?php
                $academicList = fm_parse_stored_files((string) ($row['academic_docs_file'] ?? ''));
                if ($academicList === []) {
                    echo '<div class="text-muted small mb-2">Academic Documents: none uploaded</div>';
                } else {
                    foreach ($academicList as $i => $apath) {
                        $label = count($academicList) > 1 ? 'Academic Document ' . ($i + 1) : 'Academic Documents';
                        echo $renderClientDocRow('academic_docs_file', $label, (string) $apath, (int) $i);
                    }
                }
                ?>
                <?php if ($videoLocal !== '' || $videoPcloud !== ''): ?>
                <hr>
                <div class="small fw-semibold mb-1">Video</div>
                <?php if ($publicDetailsUrl !== ''): ?>
                <a href="<?= htmlspecialchars($publicDetailsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger w-100 mb-1">
                    <i class="fas fa-id-card me-1"></i> View details
                </a>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3 border-primary-subtle">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-paperclip me-1 text-primary"></i> Attached</h6>
                <p class="small text-muted mb-2">Inbox for staff uploads and filed client docs. Choose a file — it saves automatically. Then move into a Visa group if needed.</p>
                <?php if ($groupedDocs['attached'] === []): ?>
                <div class="text-muted small mb-2">No files in Attached yet.</div>
                <?php else: ?>
                <?php foreach ($groupedDocs['attached'] as $doc) { echo $renderManagedDoc($doc); } ?>
                <?php endif; ?>
                <div class="mt-2">
                    <input
                        type="file"
                        class="form-control form-control-sm"
                        id="fmAttachFile_<?= $appId ?>"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*"
                        multiple
                        onchange="fmUploadAttached(<?= $appId ?>, this)"
                    >
                    <div class="fm-upload-progress" id="fmAttachProgress_<?= $appId ?>" hidden>
                        <div class="fm-upload-track"><div class="fm-upload-bar" id="fmAttachBar_<?= $appId ?>"></div></div>
                        <div class="fm-upload-label" id="fmAttachStatus_<?= $appId ?>">Preparing…</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-folder me-1 text-success"></i> Visa submission documents</h6>
                <p class="small text-muted mb-3">File docs here from Attached, from client form uploads, or choose a file in a group — it auto-saves right away.</p>
                <?php foreach ($visaGroups as $slug => $lab): ?>
                <div class="mb-3 border-bottom pb-2">
                    <div class="small fw-bold text-uppercase text-secondary mb-1">
                        <i class="fas fa-folder me-1"></i><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?>
                        <span class="badge text-bg-light"><?= count($groupedDocs['visa'][$slug] ?? []) ?></span>
                    </div>
                    <?php if (empty($groupedDocs['visa'][$slug])): ?>
                    <div class="text-muted small ps-1 mb-2">Empty</div>
                    <?php else: ?>
                    <?php foreach ($groupedDocs['visa'][$slug] as $doc) { echo $renderManagedDoc($doc); } ?>
                    <?php endif; ?>
                    <div>
                        <input
                            type="file"
                            class="form-control form-control-sm"
                            id="fmGroupFile_<?= $appId ?>_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*"
                            multiple
                            onchange="fmUploadToGroup(<?= $appId ?>, <?= json_encode($slug) ?>, this)"
                        >
                        <div class="fm-upload-progress" id="fmGroupProgress_<?= $appId ?>_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" hidden>
                            <div class="fm-upload-track"><div class="fm-upload-bar" id="fmGroupBar_<?= $appId ?>_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"></div></div>
                            <div class="fm-upload-label" id="fmGroupStatus_<?= $appId ?>_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">Preparing…</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

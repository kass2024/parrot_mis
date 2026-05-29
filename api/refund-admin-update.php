<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers/role.php';
require_once dirname(__DIR__) . '/helpers/refund_requests_schema.php';

function refund_admin_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
if ($adminId < 1) {
    refund_admin_respond(['ok' => false, 'error' => 'Not authenticated.'], 401);
}

$st = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
if (!$st) {
    refund_admin_respond(['ok' => false, 'error' => 'Database error.'], 500);
}
$st->bind_param('i', $adminId);
$st->execute();
$roleRow = $st->get_result()->fetch_assoc();
$st->close();
$role = (string) ($roleRow['role'] ?? '');
if (!pcvc_is_superadmin_role($role)) {
    refund_admin_respond(['ok' => false, 'error' => 'Only superadmin can manage refund requests.'], 403);
}

$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    refund_admin_respond(['ok' => false, 'error' => 'Invalid JSON.'], 400);
}

$csrf = isset($input['csrf']) && is_string($input['csrf']) ? $input['csrf'] : '';
$sess = $_SESSION['refund_admin_csrf'] ?? '';
if ($sess === '' || !hash_equals((string) $sess, $csrf)) {
    refund_admin_respond(['ok' => false, 'error' => 'Invalid security token. Reload the page.'], 403);
}

$allowedStatus = ['pending', 'under_review', 'approved', 'rejected', 'paid'];

$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id < 1) {
    refund_admin_respond(['ok' => false, 'error' => 'Invalid request id.'], 400);
}

pcvc_ensure_refund_requests_schema($conn);

$notifyEmail = !empty($input['notify_email']);
$notifyWhatsapp = !empty($input['notify_whatsapp']);
$notifyMessage = isset($input['notify_message']) && is_string($input['notify_message'])
    ? trim($input['notify_message'])
    : '';
$adminComment = isset($input['admin_comment']) && is_string($input['admin_comment'])
    ? trim($input['admin_comment'])
    : '';
$internalNote = '';
$touchInternal = array_key_exists('internal_note', $input);
if ($touchInternal && is_string($input['internal_note'])) {
    $internalNote = trim($input['internal_note']);
}

$newStatus = isset($input['request_status']) && is_string($input['request_status'])
    ? trim($input['request_status'])
    : '';
if ($newStatus === '' || !in_array($newStatus, $allowedStatus, true)) {
    refund_admin_respond(['ok' => false, 'error' => 'Invalid status.'], 400);
}

if ($newStatus === 'rejected' && ($notifyEmail || $notifyWhatsapp) && $notifyMessage === '' && $adminComment === '') {
    refund_admin_respond(['ok' => false, 'error' => 'Enter a comment or message before notifying the student about a rejection.'], 400);
}

$q = $conn->prepare('SELECT id, request_status, admin_comment FROM refund_requests WHERE id = ? LIMIT 1');
$q->bind_param('i', $id);
$q->execute();
$row = $q->get_result()->fetch_assoc();
$q->close();
if (!$row) {
    refund_admin_respond(['ok' => false, 'error' => 'Request not found.'], 404);
}

$oldStatus = (string) ($row['request_status'] ?? 'pending');
$commentForStudent = $adminComment !== '' ? $adminComment : $notifyMessage;

if ($touchInternal) {
    $upd = $conn->prepare(
        'UPDATE refund_requests SET request_status = ?, admin_comment = NULLIF(?, ""), internal_note = NULLIF(?, ""), updated_at = NOW() WHERE id = ? LIMIT 1'
    );
    $upd->bind_param('sssi', $newStatus, $adminComment, $internalNote, $id);
} else {
    $upd = $conn->prepare(
        'UPDATE refund_requests SET request_status = ?, admin_comment = NULLIF(?, ""), updated_at = NOW() WHERE id = ? LIMIT 1'
    );
    $upd->bind_param('ssi', $newStatus, $adminComment, $id);
}
if (!$upd->execute()) {
    $upd->close();
    refund_admin_respond(['ok' => false, 'error' => 'Update failed.'], 500);
}
$upd->close();

$logComment = $commentForStudent !== '' ? $commentForStudent : ($internalNote !== '' ? $internalNote : null);
$log = $conn->prepare(
    'INSERT INTO refund_request_status_logs (refund_request_id, old_status, new_status, admin_id, comment, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
);
if ($log) {
    $log->bind_param('issis', $id, $oldStatus, $newStatus, $adminId, $logComment);
    $log->execute();
    $log->close();
}

$labels = [
    'pending' => 'Pending',
    'under_review' => 'Under review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'paid' => 'Refund paid',
];
$label = $labels[$newStatus] ?? $newStatus;

$notifyResult = null;
if ($notifyEmail || $notifyWhatsapp) {
    try {
        require_once dirname(__DIR__) . '/helpers/refund_status_notify.php';
        $notifyResult = pcvc_notify_refund_request_change(
            $conn,
            $id,
            $label,
            $notifyEmail,
            $notifyWhatsapp,
            $commentForStudent
        );
    } catch (Throwable $e) {
        error_log('[refund-admin-update] notify: ' . $e->getMessage());
    }
}

$out = ['ok' => true, 'request_status' => $newStatus];
if ($notifyResult !== null) {
    $out['notify'] = $notifyResult;
}
refund_admin_respond($out);

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/self_registration.php';

header('Content-Type: application/json; charset=utf-8');

function sr_admin_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    sr_admin_json(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
if ($adminId <= 0) {
    sr_admin_json(['ok' => false, 'error' => 'Unauthorized.'], 403);
}

$isSuper = pcvc_current_user_is_superadmin($conn);
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));
pcvc_self_reg_ensure_schema($conn);

if ($action === 'issue_link' || $action === 'issue_manual') {
    if (!$isSuper) {
        sr_admin_json(['ok' => false, 'error' => 'Only superadmin can create self-registration links.'], 403);
    }
    try {
        $source = $action === 'issue_manual' ? 'manual' : 'link';
        $created = pcvc_self_reg_issue_link($conn, $adminId, $source);
        $url = pcvc_self_reg_public_url($created['token']);
        sr_admin_json([
            'ok' => true,
            'id' => $created['id'],
            'token' => $created['token'],
            'source' => $source,
            'url' => $url,
        ]);
    } catch (Throwable $e) {
        sr_admin_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'transfer') {
    if (!$isSuper) {
        sr_admin_json(['ok' => false, 'error' => 'Only superadmin can transfer applications.'], 403);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $assignee = (int) ($_POST['assigned_to_admin_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $reg = pcvc_self_reg_find_by_id($conn, $id);
    if (!$reg) {
        sr_admin_json(['ok' => false, 'error' => 'Application not found.'], 404);
    }
    try {
        pcvc_self_reg_transfer($conn, $id, $assignee, $adminId, $notes);
        sr_admin_json(['ok' => true, 'message' => 'Transferred for follow-up.']);
    } catch (Throwable $e) {
        sr_admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

if ($action === 'delete') {
    if (!$isSuper) {
        sr_admin_json(['ok' => false, 'error' => 'Only superadmin can delete applications.'], 403);
    }
    $id = (int) ($_POST['id'] ?? 0);
    try {
        pcvc_self_reg_delete($conn, $id);
        sr_admin_json(['ok' => true, 'message' => 'Application deleted.']);
    } catch (Throwable $e) {
        sr_admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

if ($action === 'set_status') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = trim((string) ($_POST['followup_status'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $reg = pcvc_self_reg_find_by_id($conn, $id);
    if (!$reg) {
        sr_admin_json(['ok' => false, 'error' => 'Application not found.'], 404);
    }

    $assignedTo = (int) ($reg['assigned_to_admin_id'] ?? 0);
    if (!$isSuper && $assignedTo !== $adminId) {
        sr_admin_json(['ok' => false, 'error' => 'You can only update applications assigned to you.'], 403);
    }

    try {
        pcvc_self_reg_update_followup($conn, $id, $status, $notes);
        sr_admin_json(['ok' => true, 'message' => 'Status updated.']);
    } catch (Throwable $e) {
        sr_admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

if ($action === 'kpis') {
    if (!$isSuper) {
        sr_admin_json(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
    sr_admin_json(['ok' => true, 'kpis' => pcvc_self_reg_kpis($conn)]);
}

sr_admin_json(['ok' => false, 'error' => 'Unknown action.'], 400);

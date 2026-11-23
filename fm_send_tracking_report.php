<?php
/**
 * JSON endpoint: build + email Francophonie Mobility full Excel report.
 */
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/fm_manager_portal.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/fm_payment_records.php';

fm_ensure_schema($conn);
fm_ensure_payment_records_schema($conn);
fm_require_staff_or_manager_portal($conn, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired. Refresh the page and try again.']);
    exit;
}

@set_time_limit(180);
@ini_set('max_execution_time', '180');

$picked = $_POST['report_to'] ?? [];
if (!is_array($picked)) {
    $picked = [];
}

try {
    $link = fm_manager_portal_private_url($conn);
    $result = fm_send_manager_full_report($conn, $link, $picked);
    echo json_encode([
        'success' => !empty($result['ok']),
        'message' => (string) ($result['message'] ?? ''),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Report send failed: ' . $e->getMessage(),
    ]);
}

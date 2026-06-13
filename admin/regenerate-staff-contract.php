<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';
require_once __DIR__ . '/../helpers/staff_contract_word.php';

header('Content-Type: application/json; charset=utf-8');

pcvc_require_superadmin($conn, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$staffId = (int) ($data['staff_id'] ?? 0);
$mode = (($data['mode'] ?? 'preview') === 'signed') ? 'signed' : 'preview';

if ($staffId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing staff member']);
    exit;
}

$contract = pcvc_staff_contract_for_admin($conn, $staffId);
if (!$contract || trim((string) ($contract['source_docx_path'] ?? '')) === '') {
    echo json_encode(['success' => false, 'message' => 'No Word contract template for this staff member']);
    exit;
}

try {
    $docxAbs = pcvc_staff_contract_abs_path((string) $contract['source_docx_path']);
    $templateWarning = pcvc_staff_contract_ensure_rich_template($docxAbs);

    $result = pcvc_staff_contract_regenerate($conn, $staffId, $contract, $mode);
    $message = $result['message'];
    if ($templateWarning !== '') {
        $message .= ' ' . $templateWarning;
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

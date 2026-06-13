<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@set_time_limit(300);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';
require_once __DIR__ . '/../helpers/staff_contract_word.php';

header('Content-Type: application/json; charset=utf-8');

function pcvc_regenerate_json_error(string $message, int $code = 500): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

try {
    pcvc_require_superadmin($conn, true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pcvc_regenerate_json_error('Invalid request', 405);
    }

    if (!class_exists('ZipArchive')) {
        pcvc_regenerate_json_error('PHP Zip extension is required for contract regeneration.');
    }

    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $staffId = (int) ($data['staff_id'] ?? 0);
    $mode = (($data['mode'] ?? 'preview') === 'signed') ? 'signed' : 'preview';

    if ($staffId <= 0) {
        pcvc_regenerate_json_error('Missing staff member', 400);
    }

    $contract = pcvc_staff_contract_for_admin($conn, $staffId);
    if (!$contract || trim((string) ($contract['source_docx_path'] ?? '')) === '') {
        pcvc_regenerate_json_error('No Word contract template for this staff member', 404);
    }

    $docxAbs = pcvc_staff_contract_abs_path((string) $contract['source_docx_path']);
    if (!is_file($docxAbs)) {
        pcvc_regenerate_json_error('Stored Word template file is missing on the server. Re-upload the contract.');
    }

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
    pcvc_regenerate_json_error($e->getMessage());
}

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/role.php';
require_once __DIR__ . '/../helpers/staff_contract_witness.php';

header('Content-Type: application/json; charset=utf-8');

try {
    pcvc_require_superadmin($conn, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    $raw = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($raw)) {
        $raw = $_POST;
    }
    $staffId = (int) ($raw['staff_id'] ?? 0);
    $email = trim((string) ($raw['witness_email'] ?? ''));
    if ($staffId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing staff member']);
        exit;
    }
    $result = pcvc_staff_contract_send_witness_email($conn, $staffId, $email);
    if (empty($result['ok'])) {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Could not email the witness']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => 'Witness link sent to ' . $result['email'] . '. They will fill their own details and sign.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not send the witness link']);
}

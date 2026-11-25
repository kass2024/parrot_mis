<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/company_view_as.php';

header('Content-Type: application/json; charset=utf-8');

function pcvc_company_view_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    pcvc_company_view_json(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

$adminId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($adminId < 1 || !pcvc_current_user_is_superadmin($conn)) {
    pcvc_company_view_json(['ok' => false, 'error' => 'Only a superadmin can switch company view.'], 403);
}

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'enter'));
if ($action === 'exit' || $action === 'clear') {
    pcvc_exit_company_view();
    pcvc_company_view_json(['ok' => true, 'message' => 'Returned to superadmin dashboard.', 'redirect' => 'admin-dashboard.php']);
}

$companyId = (int) ($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
$result = pcvc_enter_company_view($conn, $companyId, $adminId);
if (empty($result['ok'])) {
    pcvc_company_view_json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not open company view.')], 422);
}

$company = $result['company'] ?? [];
$name = trim((string) ($company['company_name'] ?? ''));
if ($name === '') {
    $name = trim((string) (($company['first_name'] ?? '') . ' ' . ($company['last_name'] ?? '')));
}
// Land on the same executive admin home (KPIs, payments, roster), scoped to this company.
// Do not auto-open company-dashboard.php in the iframe — that hid the real dashboard.
pcvc_company_view_json([
    'ok' => true,
    'company_id' => $companyId,
    'company_name' => $name,
    'redirect' => 'admin-dashboard.php?company_home=1',
]);

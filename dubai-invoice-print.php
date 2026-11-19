<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/dubai_invoice.php';
require_once __DIR__ . '/helpers/receipt_render.php';

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

dxb_invoice_ensure_schema($conn);
$invoice = dxb_invoice_load($conn, trim((string) ($_GET['no'] ?? '')));
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found');
}

header('Content-Type: text/html; charset=utf-8');
echo pcvc_render_receipt_html(dxb_invoice_receipt_data($invoice), [
    'auto_print' => true,
    'include_print_button' => true,
]);

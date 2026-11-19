<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/irs_agreement_schema.php';

if (!isset($_SESSION['admin_id']) && empty($_SESSION['id'])) {
    http_response_code(403);
    exit('Unauthorized');
}
pcvc_require_francophonie_mobility($conn);
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
    http_response_code(403);
    exit('Invalid request');
}

irs_agreement_ensure_schema($conn);
$id = (int) ($_POST['agreement_id'] ?? 0);
$stmt = $conn->prepare('SELECT pdf_path FROM irs_agreements WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    exit('Agreement not found');
}

$relative = trim((string) ($row['pdf_path'] ?? ''));
if ($relative !== '' && !str_contains($relative, '..')) {
    $absolute = __DIR__ . '/' . ltrim($relative, '/');
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}
$delSig = $conn->prepare('DELETE FROM irs_agreement_signatures WHERE agreement_id = ?');
$delSig->bind_param('i', $id);
$delSig->execute();
$delSig->close();
$del = $conn->prepare('DELETE FROM irs_agreements WHERE id = ? LIMIT 1');
$del->bind_param('i', $id);
$del->execute();
$del->close();
header('Location: admin-irs-agreements.php?deleted=1');
exit;

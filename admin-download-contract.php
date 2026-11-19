<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
/* ===============================
   ADMIN SECURITY
================================ */
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit("Unauthorized");
}

/* ===============================
   VALIDATE INPUT
================================ */
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    http_response_code(400);
    exit("Invalid request");
}

$contractId = (int) $_GET['id'];

/* ===============================
   LOAD CONTRACT
================================ */
$stmt = $conn->prepare("
    SELECT pdf_path
    FROM student_contracts
    WHERE id = ?
      AND status = 'signed'
    LIMIT 1
");
$stmt->bind_param("i", $contractId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit("PDF not found");
}

$pdfPath = trim((string) ($row['pdf_path'] ?? ''));
$storedPdf = $pdfPath;
try {
    require_once __DIR__ . '/helpers/student_contract_pdf.php';
    $freshPdf = pcvc_generate_student_contract_pdf($conn, $contractId);
    if ($freshPdf !== '' && is_file($freshPdf)) {
        $pdfPath = $freshPdf;
        if ($pdfPath !== $storedPdf) {
            $update = $conn->prepare('UPDATE student_contracts SET pdf_path = ? WHERE id = ?');
            if ($update) {
                $update->bind_param('si', $pdfPath, $contractId);
                $update->execute();
                $update->close();
            }
        }
    }
} catch (Throwable $e) {
    $pdfPath = $storedPdf;
}
unset($storedPdf, $freshPdf);
/* pcvc_notice_rebuild */

if ($pdfPath === '' || !is_file($pdfPath)) {
    require_once __DIR__ . '/helpers/student_contract_pdf.php';
    try {
        $pdfPath = pcvc_generate_student_contract_pdf($conn, $contractId);
    } catch (Throwable $e) {
        http_response_code(404);
        exit("PDF not found");
    }
    if ($pdfPath !== '' && is_file($pdfPath)) {
        $update = $conn->prepare('UPDATE student_contracts SET pdf_path = ? WHERE id = ?');
        if ($update) {
            $update->bind_param('si', $pdfPath, $contractId);
            $update->execute();
            $update->close();
        }
    }
}

if ($pdfPath === '' || !is_file($pdfPath)) {
    http_response_code(404);
    exit("PDF not found");
}

/* ===============================
   FORCE DOWNLOAD
================================ */
header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"" . basename($pdfPath) . "\"");
header("Content-Length: " . filesize($pdfPath));
header("Cache-Control: no-store");
header("Pragma: no-cache");

readfile($pdfPath);
exit;

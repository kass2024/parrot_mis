<?php
session_start();
require_once __DIR__ . '/../db.php';
/* =====================================================
   AUTH CHECK
===================================================== */
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$adminId = (int) $_SESSION['admin_id'];

/* =====================================================
   FETCH ADMIN
===================================================== */
$sql = "SELECT * FROM admins WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    http_response_code(404);
    exit('Admin not found');
}

/* =====================================================
   ENSURE ACTIVE CONTRACT TEMPLATE (AUTO)
===================================================== */
$sql = "SELECT * FROM contract_templates WHERE is_active = 1 LIMIT 1";
$result = $conn->query($sql);
$template = $result->fetch_assoc();

if (!$template) {
    $sql = "
        INSERT INTO contract_templates (version, title, html_template, is_active)
        VALUES ('v1.0', 'Employment Contract', 'AUTO', 1)
    ";
    $conn->query($sql);
    header("Location: contract.php");
    exit;
}

/* =====================================================
   FETCH OR CREATE CONTRACT (AUTO)
===================================================== */
$sql = "SELECT * FROM employment_contracts WHERE admin_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();

if (!$contract) {
    $sql = "
        INSERT INTO employment_contracts (admin_id, template_id, status)
        VALUES (?, ?, 'pending')
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $adminId, $template['id']);
    $stmt->execute();
    header("Location: contract.php");
    exit;
}

/* =====================================================
   IF ALREADY SIGNED → DOWNLOAD ONLY
===================================================== */
if ($contract['status'] === 'signed') {
    ?>
    <h3>Contract already signed</h3>
    <a href="<?= htmlspecialchars($contract['pdf_path']) ?>" target="_blank">
        Download Signed Contract
    </a>
    <?php
    exit;
}

/* =====================================================
   FETCH STAFF TASKS
===================================================== */
$tasks = [];
$sql = "SELECT task_name FROM staff_tasks WHERE staff_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}

/* =====================================================
   FETCH SIGNATURE (OPTIONAL AT THIS STAGE)
===================================================== */
$sql = "SELECT * FROM admin_signatures WHERE admin_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$signature = $stmt->get_result()->fetch_assoc();

$hasSignature = $signature ? true : false;

/* =====================================================
   PREPARE SIGNATURE HTML (IF EXISTS)
===================================================== */
$signatureHtml = '';
if ($hasSignature) {
    $signatureHtml = '
        <div style="
            font-family: ' . htmlspecialchars($signature['signature_font']) . ';
            font-size: 32px;
            margin-top: 10px;
        ">
            ' . htmlspecialchars($signature['typed_name']) . '
        </div>
    ';
}

/* =====================================================
   SIGN DATE (ONLY USED WHEN SIGNING)
===================================================== */
$signDate = date('Y-m-d');

/* =====================================================
   RENDER CONTRACT (READ FIRST, SIGN LAST)
===================================================== */
include __DIR__ . '/../contracts/contract_template.php';

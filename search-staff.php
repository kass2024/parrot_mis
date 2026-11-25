<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/company_view_as.php';

$q = trim((string) ($_POST['query'] ?? ''));
if ($q === '') {
    echo '<div>No staff found</div>';
    exit;
}

$like = '%' . $q . '%';
$companyCtx = pcvc_effective_company_context($conn);
if ($companyCtx) {
    $inList = pcvc_company_member_admin_ids_sql($conn, (int) ($companyCtx['id'] ?? 0));
    $stmt = $conn->prepare(
        "SELECT full_name FROM admins
         WHERE id IN ({$inList})
           AND (full_name LIKE ? OR CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ?)
         LIMIT 10"
    );
    if (!$stmt) {
        echo '<div>No staff found</div>';
        exit;
    }
    $stmt->bind_param('ss', $like, $like);
} else {
    $stmt = $conn->prepare('SELECT full_name FROM admins WHERE full_name LIKE ? LIMIT 10');
    if (!$stmt) {
        echo '<div>No staff found</div>';
        exit;
    }
    $stmt->bind_param('s', $like);
}
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo '<div>No staff found</div>';
    exit;
}

while ($row = $res->fetch_assoc()) {
    echo '<div>' . htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
}

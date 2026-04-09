<?php
session_start();
require_once __DIR__ . '/../db.php';
/* ===============================
   AUTHORIZATION
================================ */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    exit('Forbidden');
}

/* ===============================
   FETCH ALL CONTRACTS
================================ */
$sql = "
    SELECT 
        c.id,
        c.status,
        c.signed_at,
        c.pdf_path,
        a.full_name,
        a.email
    FROM employment_contracts c
    JOIN admins a ON a.id = c.admin_id
    ORDER BY c.signed_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$contracts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All Employment Contracts</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
body {
  font-family: 'Inter', sans-serif;
  background: #f1f5f9;
  padding: 40px;
}

.container {
  max-width: 1100px;
  margin: auto;
  background: #fff;
  padding: 30px;
  border-radius: 14px;
  box-shadow: 0 20px 40px rgba(0,0,0,.08);
}

h1 {
  margin-bottom: 20px;
  font-size: 24px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th, td {
  padding: 14px;
  border-bottom: 1px solid #e5e7eb;
  text-align: left;
}

th {
  background: #f8fafc;
  font-weight: 600;
}

.status-signed {
  color: #16a34a;
  font-weight: 600;
}

.status-pending {
  color: #dc2626;
  font-weight: 600;
}

.btn {
  padding: 8px 14px;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
}

.btn-download {
  background: #2563eb;
  color: #fff;
}
</style>
</head>

<body>

<div class="container">
  <h1>📄 Employment Contracts</h1>

  <table>
    <thead>
      <tr>
        <th>Employee</th>
        <th>Email</th>
        <th>Status</th>
        <th>Signed At</th>
        <th>PDF</th>
      </tr>
    </thead>
    <tbody>

    <?php if (empty($contracts)): ?>
      <tr>
        <td colspan="5">No contracts found.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($contracts as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['full_name']) ?></td>
        <td><?= htmlspecialchars($c['email']) ?></td>
        <td class="<?= $c['status'] === 'signed' ? 'status-signed' : 'status-pending' ?>">
          <?= strtoupper($c['status']) ?>
        </td>
        <td><?= $c['signed_at'] ? date('Y-m-d H:i', strtotime($c['signed_at'])) : '—' ?></td>
        <td>
          <?php if ($c['status'] === 'signed' && !empty($c['pdf_path'])): ?>
            <a class="btn btn-download" href="<?= htmlspecialchars($c['pdf_path']) ?>" target="_blank">
              Download PDF
            </a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>

    </tbody>
  </table>
</div>

</body>
</html>

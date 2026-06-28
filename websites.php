<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/websites_schema.php';

pcvc_ensure_websites_schema($conn);

$admin_id = $_SESSION['id'] ?? null;
if (!$admin_id) {
    header('Location: admin-login.php');
    exit;
}

$role = 'standard';
$admin_id_safe = mysqli_real_escape_string($conn, $admin_id);
$result = mysqli_query($conn, "SELECT role FROM admins WHERE id = '$admin_id_safe'");
if ($result && mysqli_num_rows($result) > 0) {
    $admin = mysqli_fetch_assoc($result);
    $role = $admin['role'] ?? 'standard';
}

$isSuperAdmin = ($role === 'superadmin');

function generateWebsiteSerial(mysqli $conn): string
{
    $year = date('Y');
    $sql = $conn->query("SELECT COUNT(*) AS total FROM websites WHERE YEAR(created_at) = $year");
    $row = $sql->fetch_assoc();
    $count = (int) ($row['total'] ?? 0) + 1;
    return 'WEB-' . $year . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
}

if (isset($_POST['action']) && $_POST['action'] === 'add' && $isSuperAdmin) {
    $serial = generateWebsiteSerial($conn);
    $name = trim($_POST['website_name'] ?? '');
    $link = trim($_POST['website_link'] ?? '');
    $user = trim($_POST['admin_username'] ?? '');
    $pass = trim($_POST['admin_password'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare('
        INSERT INTO websites
        (serial_no, website_name, website_link, admin_username, admin_password, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('sssssssi', $serial, $name, $link, $user, $pass, $status, $notes, $admin_id);
    $stmt->execute();
    $stmt->close();

    header('Location: ' . $_SERVER['PHP_SELF'] . '?added=1');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'edit' && $isSuperAdmin) {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['website_name'] ?? '');
    $link = trim($_POST['website_link'] ?? '');
    $user = trim($_POST['admin_username'] ?? '');
    $pass = trim($_POST['admin_password'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare('
        UPDATE websites
        SET website_name=?, website_link=?, admin_username=?, admin_password=?, status=?, notes=?
        WHERE id=?
    ');
    $stmt->bind_param('ssssssi', $name, $link, $user, $pass, $status, $notes, $id);
    $stmt->execute();
    $stmt->close();

    header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1');
    exit;
}

if (isset($_GET['delete']) && $isSuperAdmin) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM websites WHERE id=$id");
    header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1');
    exit;
}

function previewLink(string $url): string
{
    if ($url === '') {
        return '';
    }
    $clean = preg_replace('(^https?://)', '', $url);
    return strlen($clean) > 22 ? substr($clean, 0, 22) . '…' : $clean;
}

$openAdd = isset($_GET['open']) && $_GET['open'] === 'add';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Website Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        body { background: #F5F9F3; font-family: 'Segoe UI', sans-serif; }
        .page-title { font-weight: 700; color: #1E64B7; }
        .card { border-radius: 14px; border: none; }
        th { background: #2E6A2C !important; color: white; }
        .modal-header { background: #2E6A2C; color: white; }
        .copy-btn { cursor: pointer; }
        .link-preview {
            max-width: 180px;
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body class="p-4">

<div class="container">
    <h2 class="page-title text-center mb-3">Website Management</h2>

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Website added successfully.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Website updated successfully.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-warning">Website deleted.</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <p class="text-muted mb-0">Manage website links and admin credentials.</p>
        <?php if ($isSuperAdmin): ?>
        <button class="btn btn-primary px-3" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i> Add New
        </button>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm p-3">
        <table id="webTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Serial</th>
                    <th>Website Name</th>
                    <th>Website Link</th>
                    <th>Admin Username</th>
                    <th>Admin Password</th>
                    <th>Status</th>
                    <th width="150">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $q = $conn->query('SELECT * FROM websites ORDER BY id DESC');
            while ($row = $q->fetch_assoc()):
                $safeRow = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['serial_no']) ?></td>
                    <td><?= htmlspecialchars($row['website_name']) ?></td>
                    <td class="nowrap text-center">
                        <?php if (!empty($row['website_link'])): ?>
                            <a href="<?= htmlspecialchars($row['website_link']) ?>" target="_blank" class="text-primary text-decoration-none" title="<?= htmlspecialchars($row['website_link']) ?>">
                                <i class="bi bi-link-45deg"></i>
                                <span class="link-preview"><?= htmlspecialchars(previewLink($row['website_link'])) ?></span>
                            </a>
                            <i class="bi bi-clipboard text-success ms-2 copy-btn" onclick="copyText('<?= htmlspecialchars($row['website_link'], ENT_QUOTES) ?>')"></i>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['admin_username']) ?></td>
                    <td class="nowrap text-center">
                        <span id="pwd_<?= (int) $row['id'] ?>" style="letter-spacing:2px;">•••••••</span>
                        <i class="bi bi-eye ms-2 text-primary" style="cursor:pointer;" onclick="togglePassword('<?= htmlspecialchars($row['admin_password'], ENT_QUOTES) ?>', <?= (int) $row['id'] ?>)"></i>
                        <i class="bi bi-clipboard text-success ms-1 copy-btn" onclick="copyText('<?= htmlspecialchars($row['admin_password'], ENT_QUOTES) ?>')"></i>
                    </td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td class="text-center">
                        <?php if ($isSuperAdmin): ?>
                            <button class="btn btn-warning btn-sm me-1" onclick='editWebsite(<?= $safeRow ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <a href="?delete=<?= (int) $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this website?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="addModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg" style="border-radius:15px;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Add Website</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="form-floating mb-3">
                        <input type="text" name="website_name" class="form-control" required>
                        <label>Website Name</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="url" name="website_link" class="form-control" placeholder="https://">
                        <label>Website Link</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" name="admin_username" class="form-control" required>
                        <label>Admin Username</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" name="admin_password" class="form-control" required>
                        <label>Admin Password</label>
                    </div>
                    <label class="fw-semibold">Status</label>
                    <select class="form-select mb-3" name="status" required>
                        <option value="Active">Active</option>
                        <option value="Not Active">Not Active</option>
                    </select>
                    <div class="form-floating">
                        <textarea name="notes" class="form-control" style="height:80px;"></textarea>
                        <label>Notes (optional)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary px-4">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg" style="border-radius:15px;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Website</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body p-4">
                    <div class="form-floating mb-3">
                        <input type="text" name="website_name" id="edit_name" class="form-control" required>
                        <label>Website Name</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="url" name="website_link" id="edit_link" class="form-control">
                        <label>Website Link</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" name="admin_username" id="edit_user" class="form-control" required>
                        <label>Admin Username</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" name="admin_password" id="edit_pass" class="form-control" required>
                        <label>Admin Password</label>
                    </div>
                    <label class="fw-semibold">Status</label>
                    <select class="form-select mb-3" id="edit_status" name="status" required>
                        <option value="Active">Active</option>
                        <option value="Not Active">Not Active</option>
                    </select>
                    <div class="form-floating">
                        <textarea name="notes" id="edit_notes" class="form-control" style="height:80px;"></textarea>
                        <label>Notes (optional)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary px-4">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function () {
    $('#webTable').DataTable({ order: [[0, 'desc']] });
    <?php if ($openAdd && $isSuperAdmin): ?>
    new bootstrap.Modal(document.getElementById('addModal')).show();
    <?php endif; ?>
});

function copyText(text) {
    navigator.clipboard.writeText(text);
    alert('Copied');
}

function togglePassword(real, id) {
    var span = document.getElementById('pwd_' + id);
    if (span.innerHTML.includes('•')) {
        span.innerHTML = real;
    } else {
        span.innerHTML = '•••••••';
    }
}

function editWebsite(row) {
    $('#edit_id').val(row.id);
    $('#edit_name').val(row.website_name);
    $('#edit_link').val(row.website_link);
    $('#edit_user').val(row.admin_username);
    $('#edit_pass').val(row.admin_password);
    $('#edit_status').val(row.status);
    $('#edit_notes').val(row.notes || '');
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
</body>
</html>

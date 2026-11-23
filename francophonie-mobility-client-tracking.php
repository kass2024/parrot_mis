<?php
/**
 * Client tracking — Overall report.
 * Lists FM clients vs Clients tracking.xlsx checklist; green = found (clickable), red = missing.
 * Gemini maps files inside existing document-group folders (groups are not changed).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';
require_once __DIR__ . '/helpers/francophonie_mobility_documents.php';
require_once __DIR__ . '/helpers/fm_client_tracking.php';

fm_ensure_schema($conn);
fm_ensure_documents_schema($conn);
fm_ensure_tracking_schema($conn);

if (empty($_SESSION['id']) && empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
pcvc_require_francophonie_mobility($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$checklist = fm_tracking_checklist();
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$forceScan = isset($_GET['rescan']) && $_GET['rescan'] === '1';
$scanOne = (int) ($_GET['scan_id'] ?? 0);
$geminiReady = pcvc_docvision_is_configured();
$scanErrors = [];
if ($forceScan || $scanOne > 0) {
    @set_time_limit(300);
}

$where = '1=1';
$types = '';
$params = [];
if ($statusFilter !== 'all' && fm_is_valid_application_status($statusFilter)) {
    $where .= ' AND status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

$sql = "SELECT id, reference_id, first_name, last_name, email, status, source,
               cv_file, french_cert_file, english_cert_file, academic_docs_file, updated_at
        FROM francophonie_mobility_applications
        WHERE {$where}
        ORDER BY updated_at DESC, id DESC
        LIMIT 500";
$st = $conn->prepare($sql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$apps = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$st->close();

$rows = [];
foreach ($apps as $app) {
    $appId = (int) $app['id'];
    $force = $forceScan || ($scanOne > 0 && $scanOne === $appId);
    $resolved = fm_tracking_resolve_for_app($conn, $app, $force);
    if (!empty($resolved['error'])) {
        $scanErrors[$appId] = (string) $resolved['error'];
    }
    $matches = $resolved['matches'];
    $present = 0;
    $cells = [];
    foreach ($checklist as $key => $label) {
        $file = $matches[$key] ?? null;
        $ok = is_array($file) && !empty($file['path']);
        if ($ok) {
            $present++;
        }
        $urls = fm_tracking_cell_urls($ok ? $file : null);
        $cells[$key] = [
            'ok' => $ok,
            'label' => $label,
            'name' => $ok ? (string) ($file['name'] ?? $label) : '',
            'group' => $ok ? (string) ($file['group'] ?? '') : '',
            'view' => $urls['view'],
            'download' => $urls['download'],
        ];
    }
    $total = count($checklist);
    $rows[] = [
        'id' => $appId,
        'name' => trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')),
        'ref' => (string) ($app['reference_id'] ?? ''),
        'status' => (string) ($app['status'] ?? ''),
        'status_label' => fm_application_status_label((string) ($app['status'] ?? '')),
        'source' => (string) ($resolved['source'] ?? ''),
        'present' => $present,
        'total' => $total,
        'cells' => $cells,
    ];
}

$statuses = fm_application_statuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client tracking — Overall report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f3; color: #1e293b; }
        .page-head {
            background: linear-gradient(135deg, #1e4d2b, #3661B9);
            color: #fff;
            padding: 1.1rem 0;
            margin-bottom: 1rem;
        }
        .card-soft { border: 0; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); background: #fff; }
        .hint { color: #64748b; font-size: .85rem; }
        .table-wrap { overflow: auto; max-height: calc(100vh - 220px); }
        table.track-table { border-collapse: separate; border-spacing: 0; min-width: 1400px; }
        table.track-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: #1e4d2b; color: #fff; font-size: .72rem;
            font-weight: 600; white-space: nowrap; vertical-align: middle;
            padding: .55rem .45rem;
        }
        table.track-table thead th.sticky-col,
        table.track-table tbody td.sticky-col {
            position: sticky; left: 0; z-index: 1; background: #fff;
            min-width: 200px; max-width: 240px;
        }
        table.track-table thead th.sticky-col { z-index: 3; background: #1e4d2b; }
        table.track-table tbody td {
            padding: .35rem .3rem; vertical-align: middle; text-align: center;
            border-bottom: 1px solid #e2e8f0; font-size: .75rem;
        }
        table.track-table tbody td.sticky-col { text-align: left; background: #fff; }
        .cell-ok {
            background: #dcfce7 !important;
            color: #166534;
            font-weight: 600;
        }
        .cell-ok a {
            color: #166534;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .cell-ok a:hover { color: #14532d; }
        .cell-miss {
            background: #fee2e2 !important;
            color: #991b1b;
            font-weight: 600;
        }
        .pct { font-size: .72rem; color: #64748b; }
        .badge-src { font-size: .65rem; }
    </style>
</head>
<body>
<div class="page-head">
    <div class="container-fluid px-3 px-md-4">
        <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>Client tracking — Overall report</h1>
        <p class="mb-0 opacity-75 small">
            Checklist matches <code>Clients tracking.xlsx</code>. Files live in existing Visa submission folders (groups unchanged; multiple files per folder allowed).
            Green = found (preview / download). Red = missing. No document is mandatory.
        </p>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 pb-4">
    <div class="card card-soft p-3 mb-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Filter by status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All clients</option>
                    <?php foreach ($statuses as $slug => $lab): ?>
                    <option value="<?= htmlspecialchars($slug) ?>" <?= $statusFilter === $slug ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
                <a class="btn btn-sm btn-success" href="?status=<?= urlencode($statusFilter) ?>&amp;rescan=1">
                    <i class="fas fa-robot me-1"></i> Rescan folders with Gemini
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="francophonie-mobility-applications.php">Applications</a>
                <span class="align-self-center hint">
                    <?= $geminiReady ? '<i class="fas fa-check-circle text-success"></i> Gemini ready' : '<i class="fas fa-exclamation-triangle text-warning"></i> Gemini key missing — heuristic only' ?>
                    · <?= count($rows) ?> client<?= count($rows) === 1 ? '' : 's' ?>
                </span>
            </div>
        </form>
        <?php if ($scanErrors !== []): ?>
        <div class="alert alert-warning mt-3 mb-0 py-2 small">
            Some Gemini scans fell back to filename/folder heuristics.
        </div>
        <?php endif; ?>
    </div>

    <div class="card card-soft">
        <div class="table-wrap">
            <table class="table track-table mb-0">
                <thead>
                    <tr>
                        <th class="sticky-col">Client name</th>
                        <th>Status</th>
                        <th>Done</th>
                        <?php foreach ($checklist as $lab): ?>
                        <th><?= htmlspecialchars($lab) ?></th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= 4 + count($checklist) ?>" class="text-muted p-4">No clients found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="sticky-col">
                            <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name'] !== '' ? $r['name'] : '—') ?></div>
                            <div class="pct"><code><?= htmlspecialchars($r['ref']) ?></code>
                                <?php if ($r['source'] !== ''): ?>
                                <span class="badge text-bg-light badge-src"><?= htmlspecialchars($r['source']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="small"><?= htmlspecialchars($r['status_label']) ?></span></td>
                        <td>
                            <strong><?= (int) $r['present'] ?>/<?= (int) $r['total'] ?></strong>
                            <div class="pct"><?= $r['total'] > 0 ? (int) round(100 * $r['present'] / $r['total']) : 0 ?>%</div>
                        </td>
                        <?php foreach ($checklist as $key => $_lab): ?>
                            <?php $c = $r['cells'][$key]; ?>
                            <?php if ($c['ok']): ?>
                            <td class="cell-ok" title="<?= htmlspecialchars($c['name'] . ($c['group'] !== '' ? ' · ' . $c['group'] : '')) ?>">
                                <a href="<?= htmlspecialchars($c['view']) ?>" target="_blank" rel="noopener">View</a>
                                ·
                                <a href="<?= htmlspecialchars($c['download']) ?>">DL</a>
                            </td>
                            <?php else: ?>
                            <td class="cell-miss">Missing</td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td>
                            <a class="btn btn-outline-secondary btn-sm" href="?status=<?= urlencode($statusFilter) ?>&amp;scan_id=<?= (int) $r['id'] ?>" title="Rescan this client">
                                <i class="fas fa-sync-alt"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>

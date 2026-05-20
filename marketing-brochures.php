<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/marketing_brochure_schema.php';

pcvc_marketing_brochure_ensure_schema($conn);

if (!isset($_SESSION['id'])) {
    header('Location: admin-login.php');
    exit;
}

$csrfToken = pcvc_csrf_token();

$regions = [];
if ($r = $conn->query('SELECT id, name FROM regions ORDER BY name ASC')) {
    while ($row = $r->fetch_assoc()) {
        $regions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Smart Brochure Sharing | Marketing Materials</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --brand:#427431;
    --brand-dark:#2f5a26;
    --brand-soft:#e8f1e1;
    --accent:#E21D1E;
    --accent-soft:#fde4e4;
    --whatsapp:#25D366;
    --info:#3661B9;
    --bg:#eef2f7;
    --surface:#ffffff;
    --border:#e2e8f0;
    --text:#1e293b;
    --muted:#64748b;
    --shadow-sm:0 1px 2px rgba(15,23,42,.06);
    --shadow:0 8px 24px -8px rgba(15,23,42,.12), 0 0 0 1px rgba(15,23,42,.04);
    --shadow-lg:0 24px 60px -20px rgba(15,23,42,.25);
    --radius:16px;
    --radius-sm:10px;
}
*{box-sizing:border-box}
body{
    font-family:'Inter',system-ui,sans-serif;
    background:var(--bg);
    color:var(--text);
    margin:0;
    padding:0;
    line-height:1.5;
}
.page-wrap{max-width:1400px;margin:0 auto;padding:24px}
.hero{
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    border-radius:var(--radius);
    color:#fff;
    padding:28px 32px;
    position:relative;
    overflow:hidden;
    box-shadow:var(--shadow-lg);
}
.hero::before{
    content:'';position:absolute;right:-60px;top:-60px;
    width:240px;height:240px;border-radius:50%;
    background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);
}
.hero h1{
    font-size:1.6rem;font-weight:800;margin:0 0 6px;
    display:flex;align-items:center;gap:12px;
}
.hero p{margin:0;opacity:.85;font-size:.95rem;max-width:720px}
.hero .badge-strip{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}
.hero .chip{
    background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.25);
    padding:6px 12px;border-radius:999px;font-size:.78rem;font-weight:600;
    backdrop-filter:blur(4px);
}
.stats-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:14px;margin:20px 0;
}
.stat-card{
    background:var(--surface);border-radius:var(--radius-sm);
    padding:18px 20px;border:1px solid var(--border);
    box-shadow:var(--shadow-sm);
    display:flex;align-items:center;gap:14px;
}
.stat-card .icon{
    width:46px;height:46px;border-radius:12px;
    display:grid;place-items:center;font-size:1.4rem;color:#fff;
}
.stat-card .icon.green{background:linear-gradient(135deg,#427431,#2f5a26)}
.stat-card .icon.red{background:linear-gradient(135deg,#E21D1E,#a31313)}
.stat-card .icon.blue{background:linear-gradient(135deg,#3661B9,#1f3f80)}
.stat-card .icon.gold{background:linear-gradient(135deg,#f59e0b,#b45309)}
.stat-card .num{font-size:1.5rem;font-weight:800;line-height:1}
.stat-card .label{font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}

.toolbar{
    background:var(--surface);border-radius:var(--radius);
    padding:18px 22px;border:1px solid var(--border);
    box-shadow:var(--shadow-sm);
    display:flex;flex-wrap:wrap;align-items:center;gap:14px;
    margin-bottom:18px;
}
.toolbar .search-box{
    position:relative;flex:1 1 280px;
}
.toolbar .search-box i{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);
}
.toolbar input,.toolbar select{
    border:1px solid var(--border);border-radius:10px;
    padding:10px 12px 10px 38px;font-size:.92rem;width:100%;
    background:#f8fafc;outline:none;transition:.2s;
}
.toolbar select{padding-left:14px}
.toolbar input:focus,.toolbar select:focus{
    border-color:var(--brand);background:#fff;
    box-shadow:0 0 0 3px rgba(66,116,49,.15);
}
.btn-brand{
    background:var(--brand);color:#fff;border:none;
    padding:10px 18px;border-radius:10px;
    font-weight:600;font-size:.92rem;display:inline-flex;align-items:center;gap:8px;
    cursor:pointer;transition:.2s;text-decoration:none;
}
.btn-brand:hover{background:var(--brand-dark);transform:translateY(-1px);color:#fff}
.btn-accent{background:var(--accent);color:#fff;border:none;padding:10px 18px;border-radius:10px;font-weight:600;font-size:.92rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.btn-accent:hover{background:#a31313;color:#fff}
.btn-ghost{background:transparent;border:1px solid var(--border);padding:9px 16px;border-radius:10px;font-weight:600;font-size:.88rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:var(--text);text-decoration:none}
.btn-ghost:hover{background:var(--surface);border-color:var(--brand);color:var(--brand)}

/* ---------- Upload card ---------- */
.upload-card{
    background:var(--surface);border-radius:var(--radius);
    padding:24px;border:1px solid var(--border);
    box-shadow:var(--shadow);margin-bottom:18px;
}
.upload-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:24px}
@media (max-width:860px){.upload-grid{grid-template-columns:1fr}}
.drop-zone{
    border:2px dashed #cbd5e1;border-radius:var(--radius-sm);
    padding:32px 20px;text-align:center;cursor:pointer;
    transition:.2s;background:#fafbfd;min-height:220px;
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;
}
.drop-zone:hover,.drop-zone.dragover{
    border-color:var(--brand);background:var(--brand-soft);
}
.drop-zone .big-icon{font-size:3.2rem;color:var(--brand);line-height:1}
.drop-zone strong{display:block;margin-top:8px;font-size:1.05rem}
.drop-zone small{color:var(--muted)}
.file-chip{
    margin-top:10px;background:#fff;border:1px solid var(--border);
    padding:8px 12px;border-radius:8px;font-size:.85rem;
    display:inline-flex;align-items:center;gap:8px;
}
.form-label{font-weight:600;font-size:.84rem;margin-bottom:6px;color:var(--text)}
.form-control,.form-select{
    border:1px solid var(--border);border-radius:10px;
    padding:10px 12px;font-size:.92rem;background:#f8fafc;
}
.form-control:focus,.form-select:focus{
    border-color:var(--brand);background:#fff;
    box-shadow:0 0 0 3px rgba(66,116,49,.15);
}
.region-row{display:flex;gap:8px}
.region-row .form-select{flex:1}

/* Pretty tick row */
.tick-row{
    display:flex;align-items:flex-start;gap:12px;cursor:pointer;
    background:#f8fafc;border:1px solid var(--border);border-radius:12px;
    padding:12px 14px;user-select:none;transition:.2s;
}
.tick-row:hover{border-color:var(--brand);background:var(--brand-soft)}
.tick-row input[type=checkbox]{position:absolute;opacity:0;pointer-events:none}
.tick-row .check-visual{
    flex:0 0 22px;width:22px;height:22px;border-radius:6px;
    border:2px solid #cbd5e1;background:#fff;
    display:grid;place-items:center;color:#fff;font-size:1rem;
    transition:.2s;margin-top:2px;
}
.tick-row input[type=checkbox]:checked + .check-visual{
    background:var(--brand);border-color:var(--brand);
}
.tick-row strong{display:block;font-weight:600;font-size:.92rem;color:var(--text);margin-bottom:2px}
.tick-row small{color:var(--muted);font-size:.78rem;display:block;line-height:1.4}

/* ---------- Brochure grid ---------- */
.brochure-grid{
    display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;
}
.brochure-card{
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--radius);overflow:hidden;
    transition:.2s;display:flex;flex-direction:column;
    box-shadow:var(--shadow-sm);
}
.brochure-card:hover{box-shadow:var(--shadow);transform:translateY(-2px)}
.brochure-card .cover{
    height:170px;
    background:linear-gradient(135deg,#427431 0%,#2f5a26 100%);
    color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;
    position:relative;padding:18px;
}
.brochure-card .cover .pdf-icon{font-size:3rem;opacity:.85}
.brochure-card .cover .region-tag{
    position:absolute;top:12px;left:12px;
    background:rgba(255,255,255,.22);backdrop-filter:blur(4px);
    border:1px solid rgba(255,255,255,.35);
    color:#fff;font-weight:600;padding:4px 10px;border-radius:999px;font-size:.72rem;
}
.brochure-card .cover .menu-btn{
    position:absolute;top:10px;right:10px;
    background:rgba(0,0,0,.25);border:none;color:#fff;
    width:32px;height:32px;border-radius:50%;cursor:pointer;
}
.brochure-card .body{padding:16px 18px;flex:1;display:flex;flex-direction:column;gap:6px}
.brochure-card .title{font-weight:700;font-size:1rem;color:var(--text);line-height:1.3}
.brochure-card .meta{font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.brochure-card .share-block{
    padding:12px 14px;border-top:1px solid var(--border);
    background:linear-gradient(180deg,#fafbfd 0%,#f1f5f9 100%);
}
.brochure-card .share-label{
    display:flex;align-items:center;gap:6px;
    font-size:.7rem;font-weight:700;color:var(--muted);
    text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;
}
.brochure-card .link-row{
    display:flex;gap:6px;margin-bottom:8px;
}
.brochure-card .link-row input{
    flex:1;border:1px solid var(--border);border-radius:8px;
    background:#fff;padding:7px 10px;font-size:.74rem;
    font-family:'Courier New',monospace;color:var(--text);outline:none;
    min-width:0;cursor:text;
}
.brochure-card .link-row .copy-btn{
    flex:0 0 auto;border:none;background:var(--brand);color:#fff;
    padding:7px 12px;border-radius:8px;cursor:pointer;font-size:.78rem;
    font-weight:600;display:inline-flex;align-items:center;gap:5px;transition:.2s;
}
.brochure-card .link-row .copy-btn:hover{background:var(--brand-dark)}
.brochure-card .share-actions{
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;
}
.brochure-card .share-actions a,
.brochure-card .share-actions button{
    border:1px solid var(--border);background:#fff;color:var(--text);
    padding:8px 6px;border-radius:8px;cursor:pointer;text-decoration:none;
    display:inline-flex;align-items:center;justify-content:center;gap:5px;
    font-size:.78rem;font-weight:600;transition:.2s;
}
.brochure-card .share-actions a.wa{color:var(--whatsapp);border-color:#d4f5dc}
.brochure-card .share-actions a.wa:hover{background:var(--whatsapp);color:#fff;border-color:var(--whatsapp)}
.brochure-card .share-actions a.em{color:var(--info);border-color:#dde6f6}
.brochure-card .share-actions a.em:hover{background:var(--info);color:#fff;border-color:var(--info)}
.brochure-card .share-actions button.send{color:var(--accent);border-color:#fbd8d8}
.brochure-card .share-actions button.send:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

.brochure-card .footer{
    padding:10px 14px;border-top:1px solid var(--border);
    display:flex;gap:6px;background:#fff;
}
.brochure-card .footer button{
    flex:1;border:none;background:transparent;padding:6px 4px;
    border-radius:8px;font-size:.78rem;font-weight:600;cursor:pointer;
    color:var(--text);transition:.2s;
    display:inline-flex;align-items:center;justify-content:center;gap:5px;
}
.brochure-card .footer button:hover{background:var(--brand-soft);color:var(--brand)}
.brochure-card .footer button.danger:hover{background:var(--accent-soft);color:var(--accent)}
.brochure-card .attach-toggle{
    display:flex;align-items:center;gap:6px;font-size:.74rem;color:var(--muted);cursor:pointer;
    padding:6px 10px;border-radius:8px;transition:.2s;
}
.brochure-card .attach-toggle:hover{background:var(--brand-soft);color:var(--brand)}
.brochure-card .attach-toggle input{margin:0;accent-color:var(--brand)}

/* ---------- Modal ---------- */
.modal-mask{
    position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1050;
    display:none;align-items:center;justify-content:center;padding:20px;
    backdrop-filter:blur(4px);
}
.modal-mask.show{display:flex}
.modal-box{
    background:#fff;border-radius:var(--radius);max-width:560px;width:100%;
    max-height:92vh;overflow-y:auto;box-shadow:var(--shadow-lg);
    animation:popIn .2s ease;
}
@keyframes popIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal-head{
    padding:18px 22px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    color:#fff;border-radius:var(--radius) var(--radius) 0 0;
}
.modal-head h3{margin:0;font-size:1.1rem;font-weight:700;display:flex;gap:10px;align-items:center}
.modal-head .close{background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.2rem;cursor:pointer}
.modal-body{padding:22px}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}

.share-stepper{display:flex;gap:6px;margin-bottom:16px}
.share-stepper .step{
    flex:1;height:6px;border-radius:6px;background:#e2e8f0;
}
.share-stepper .step.active{background:var(--brand)}

.match-card{
    border:1px solid var(--border);border-radius:10px;
    padding:12px 14px;margin-top:8px;cursor:pointer;
    transition:.15s;background:#fafbfd;
}
.match-card:hover{border-color:var(--brand);background:var(--brand-soft)}
.match-card.selected{
    border-color:var(--brand);background:var(--brand-soft);
    box-shadow:0 0 0 3px rgba(66,116,49,.12);
}
.match-card .name{font-weight:700;font-size:.94rem}
.match-card .row-meta{font-size:.75rem;color:var(--muted);margin-top:2px}
.match-card .table-tag{
    display:inline-block;background:#fff;border:1px solid var(--border);
    padding:2px 8px;border-radius:999px;font-size:.7rem;color:var(--muted);
    margin-left:6px;
}

.share-url-box{
    background:#f8fafc;border:1px dashed var(--border);
    padding:10px 12px;border-radius:8px;font-size:.85rem;
    display:flex;gap:8px;align-items:center;word-break:break-all;
    margin-top:6px;
}
.share-url-box input{flex:1;border:none;background:transparent;outline:none;font-family:'Courier New',monospace;font-size:.82rem}

.channel-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
.channel-btn{
    background:#fff;border:1px solid var(--border);
    padding:14px 8px;border-radius:12px;cursor:pointer;
    display:flex;flex-direction:column;align-items:center;gap:6px;
    font-size:.82rem;font-weight:600;color:var(--text);
    transition:.2s;text-decoration:none;
}
.channel-btn i{font-size:1.6rem}
.channel-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.channel-btn.wa:hover{border-color:var(--whatsapp);color:var(--whatsapp)}
.channel-btn.em:hover{border-color:var(--info);color:var(--info)}
.channel-btn.cp:hover{border-color:var(--brand);color:var(--brand)}

.empty-state{
    background:var(--surface);border-radius:var(--radius);
    padding:64px 20px;text-align:center;border:1px dashed var(--border);
}
.empty-state i{font-size:4rem;color:#cbd5e1}
.empty-state h4{margin:14px 0 6px;color:var(--text)}
.empty-state p{color:var(--muted);margin:0}

.toast-stack{position:fixed;bottom:24px;right:24px;z-index:2000;display:flex;flex-direction:column;gap:10px}
.toast-item{
    background:#1e293b;color:#fff;padding:12px 18px;border-radius:10px;
    box-shadow:var(--shadow-lg);font-size:.88rem;min-width:240px;
    display:flex;align-items:center;gap:10px;
    animation:slideIn .25s ease;
}
.toast-item.success{background:linear-gradient(135deg,#16a34a,#15803d)}
.toast-item.error{background:linear-gradient(135deg,#dc2626,#991b1b)}
.toast-item.info{background:linear-gradient(135deg,#3661B9,#1f3f80)}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.spinner-mini{
    display:inline-block;width:14px;height:14px;border:2px solid #fff;
    border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

.upload-progress{
    height:6px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin-top:10px;display:none;
}
.upload-progress .bar{height:100%;background:linear-gradient(90deg,var(--brand),#2f5a26);width:0%;transition:width .2s}
</style>
</head>
<body>
<div class="page-wrap">

    <div class="hero">
        <h1><i class="bi bi-megaphone-fill"></i> Smart Brochure Sharing</h1>
        <p>Upload your region-specific PDF brochures, get a beautified shareable page automatically, and send it straight to customers via WhatsApp or email. Existing applicants are matched instantly across all application tables.</p>
        <div class="badge-strip">
            <span class="chip"><i class="bi bi-cloud-upload"></i> Drag &amp; drop PDF</span>
            <span class="chip"><i class="bi bi-geo-alt-fill"></i> Region-aware</span>
            <span class="chip"><i class="bi bi-whatsapp"></i> WhatsApp share</span>
            <span class="chip"><i class="bi bi-envelope-fill"></i> Email share</span>
            <span class="chip"><i class="bi bi-link-45deg"></i> Auto share link</span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon green"><i class="bi bi-file-earmark-pdf"></i></div>
            <div>
                <div class="num" id="stat-total">0</div>
                <div class="label">Brochures</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon red"><i class="bi bi-geo-alt"></i></div>
            <div>
                <div class="num" id="stat-regions"><?= count($regions) ?></div>
                <div class="label">Regions</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon blue"><i class="bi bi-eye"></i></div>
            <div>
                <div class="num" id="stat-views">0</div>
                <div class="label">Total Views</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon gold"><i class="bi bi-share"></i></div>
            <div>
                <div class="num" id="stat-shares">0</div>
                <div class="label">Total Shares</div>
            </div>
        </div>
    </div>

    <!-- ============ Upload card ============ -->
    <div class="upload-card">
        <h5 style="margin:0 0 16px;display:flex;gap:8px;align-items:center;font-weight:700">
            <i class="bi bi-cloud-arrow-up-fill" style="color:var(--brand);font-size:1.4rem"></i>
            Upload a new brochure
        </h5>
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="upload_brochure">

            <div class="upload-grid">
                <div>
                    <label class="form-label">PDF file</label>
                    <div class="drop-zone" id="dropZone">
                        <i class="bi bi-file-earmark-pdf-fill big-icon"></i>
                        <strong>Drop your PDF here</strong>
                        <small>or click to browse (max 25 MB)</small>
                        <div id="fileChip" class="file-chip" style="display:none">
                            <i class="bi bi-file-earmark-pdf-fill" style="color:var(--accent)"></i>
                            <span id="fileChipName"></span>
                        </div>
                    </div>
                    <input type="file" id="pdfInput" name="pdf" accept="application/pdf" hidden required>
                    <div class="upload-progress" id="uploadProgress"><div class="bar"></div></div>
                </div>

                <div style="display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label class="form-label">Brochure title</label>
                        <input type="text" name="title" id="titleInput" class="form-control" placeholder="e.g. Details for Common Documents Needed for Admission in Canada" required>
                    </div>
                    <div>
                        <label class="form-label">Region</label>
                        <div class="region-row">
                            <select name="region_id" id="regionSelect" class="form-select" required>
                                <option value="">— Choose region —</option>
                                <?php foreach ($regions as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-ghost" onclick="openNewRegion()" title="Add new region">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Short description (optional)</label>
                        <textarea name="description" id="descInput" class="form-control" rows="2" placeholder="What's inside this brochure? Shown on the public page."></textarea>
                    </div>
                    <label class="tick-row" for="attachPdfChk">
                        <input type="checkbox" name="attach_pdf" id="attachPdfChk" value="1" checked>
                        <span class="check-visual"><i class="bi bi-check2"></i></span>
                        <span>
                            <strong>Attach original PDF on the public page</strong>
                            <small>Customers will see the beautified HTML version and the embedded PDF + a download link. Uncheck to share only the HTML.</small>
                        </span>
                    </label>
                    <div style="display:flex;gap:8px;margin-top:auto">
                        <button type="submit" class="btn-brand" id="uploadBtn">
                            <i class="bi bi-magic"></i> Upload &amp; auto-generate share page
                        </button>
                        <button type="reset" class="btn-ghost" onclick="resetUpload()">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- ============ Toolbar ============ -->
    <div class="toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchBox" placeholder="Search by title or region…">
        </div>
        <select id="filterRegion" class="form-select" style="max-width:240px">
            <option value="0">All regions</option>
            <?php foreach ($regions as $r): ?>
                <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn-ghost" onclick="loadBrochures()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <!-- ============ Brochures grid ============ -->
    <div id="brochureContainer">
        <div class="empty-state">
            <i class="bi bi-hourglass-split"></i>
            <h4>Loading brochures…</h4>
            <p>Hang on a second.</p>
        </div>
    </div>
</div>

<!-- ============ Add Region Modal ============ -->
<div class="modal-mask" id="newRegionModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head">
            <h3><i class="bi bi-geo-alt-fill"></i> Add a new region</h3>
            <button class="close" onclick="closeModal('newRegionModal')">&times;</button>
        </div>
        <div class="modal-body">
            <label class="form-label">Region name</label>
            <input type="text" id="newRegionName" class="form-control" placeholder="e.g. Middle East" autofocus>
            <small class="text-muted" style="display:block;margin-top:6px">It will be added to the global regions list and reusable everywhere else in the system.</small>
        </div>
        <div class="modal-foot">
            <button class="btn-ghost" onclick="closeModal('newRegionModal')">Cancel</button>
            <button class="btn-brand" onclick="saveNewRegion()" id="saveRegionBtn">
                <i class="bi bi-check2-circle"></i> Save region
            </button>
        </div>
    </div>
</div>

<!-- ============ Share Modal ============ -->
<div class="modal-mask" id="shareModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-share-fill"></i> Share <span id="shareTitle" style="font-weight:400;opacity:.85"></span></h3>
            <button class="close" onclick="closeModal('shareModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="share-stepper">
                <div class="step active" id="step1"></div>
                <div class="step" id="step2"></div>
                <div class="step" id="step3"></div>
            </div>

            <!-- Step 1: phone -->
            <div id="sharePane1">
                <label class="form-label">Customer WhatsApp / phone number</label>
                <input type="tel" id="sharePhone" class="form-control" placeholder="e.g. +250 788 123 456" autofocus>
                <small class="text-muted">We'll first look this number up across all application tables. If we don't find it, you can create a new contact.</small>

                <div id="lookupResults" style="margin-top:14px"></div>

                <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                    <button class="btn-ghost" onclick="closeModal('shareModal')">Cancel</button>
                    <button class="btn-brand" id="lookupBtn" onclick="runLookup()">
                        <i class="bi bi-search"></i> Look up number
                    </button>
                </div>
            </div>

            <!-- Step 2: confirm / add new -->
            <div id="sharePane2" style="display:none">
                <h6 style="font-weight:700;margin-bottom:10px"><i class="bi bi-person-plus"></i> Add this contact</h6>
                <p class="text-muted" style="font-size:.85rem">No existing applicant matched. Please add the customer details — we'll save them to your contacts.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label class="form-label">Full name</label>
                        <input type="text" id="newName" class="form-control" placeholder="Jane Doe">
                    </div>
                    <div>
                        <label class="form-label">Email (optional)</label>
                        <input type="email" id="newEmail" class="form-control" placeholder="jane@example.com">
                    </div>
                </div>
                <div style="margin-top:18px;display:flex;gap:8px;justify-content:space-between">
                    <button class="btn-ghost" onclick="backToPane(1)">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                    <button class="btn-brand" onclick="goToShare(true)">
                        Continue <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Step 3: pick channel -->
            <div id="sharePane3" style="display:none">
                <h6 style="font-weight:700;margin-bottom:6px"><i class="bi bi-send-fill"></i> Send the brochure</h6>
                <div id="shareRecipientBox" class="match-card selected" style="cursor:default"></div>
                <label class="form-label" style="margin-top:14px">Share link</label>
                <div class="share-url-box">
                    <input type="text" id="shareLinkBox" readonly>
                    <button class="btn-ghost" onclick="copyShareLink()" title="Copy"><i class="bi bi-clipboard"></i></button>
                </div>

                <label class="form-label" style="margin-top:14px">Choose how to send</label>
                <div class="channel-row">
                    <a href="#" class="channel-btn wa" id="chWhatsapp" target="_blank" rel="noopener" onclick="trackShare('whatsapp');">
                        <i class="bi bi-whatsapp" style="color:var(--whatsapp)"></i>
                        WhatsApp
                    </a>
                    <a href="#" class="channel-btn em" id="chEmail" onclick="trackShare('email');">
                        <i class="bi bi-envelope-fill" style="color:var(--info)"></i>
                        Email
                    </a>
                    <button type="button" class="channel-btn cp" onclick="copyShareLink(true);trackShare('copy');">
                        <i class="bi bi-clipboard-check" style="color:var(--brand)"></i>
                        Copy link
                    </button>
                </div>

                <div style="margin-top:18px;display:flex;gap:8px;justify-content:space-between">
                    <button class="btn-ghost" onclick="backToPane(1)">
                        <i class="bi bi-arrow-counterclockwise"></i> Start over
                    </button>
                    <button class="btn-brand" onclick="closeModal('shareModal')">
                        <i class="bi bi-check2-all"></i> Done
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast-stack" id="toastStack"></div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const ENDPOINT = 'marketing-brochure-actions.php';
let BROCHURES = [];
let SHARE_STATE = {};

/* ---------- Toast ---------- */
function toast(msg, type='info'){
    const t=document.createElement('div');
    t.className='toast-item '+type;
    t.innerHTML='<i class="bi bi-'+(type==='success'?'check-circle-fill':type==='error'?'exclamation-octagon-fill':'info-circle-fill')+'"></i>'+msg;
    document.getElementById('toastStack').appendChild(t);
    setTimeout(()=>t.remove(),4500);
}

/* ---------- Modals ---------- */
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
function openNewRegion(){
    document.getElementById('newRegionName').value='';
    openModal('newRegionModal');
    setTimeout(()=>document.getElementById('newRegionName').focus(),100);
}

/* ---------- New region ---------- */
async function saveNewRegion(){
    const name = document.getElementById('newRegionName').value.trim();
    if(!name){toast('Please enter a region name.','error');return;}
    const btn=document.getElementById('saveRegionBtn');
    btn.innerHTML='<span class="spinner-mini"></span> Saving…';
    btn.disabled=true;
    try{
        const fd=new FormData();
        fd.append('action','add_region');
        fd.append('csrf_token',CSRF);
        fd.append('name',name);
        const res=await fetch(ENDPOINT,{method:'POST',body:fd});
        const d=await res.json();
        if(!d.ok){toast(d.error||'Could not add region.','error');return;}
        addRegionToSelects(d.region);
        toast('Region saved: '+d.region.name,'success');
        closeModal('newRegionModal');
        document.getElementById('regionSelect').value=d.region.id;
    }catch(e){toast('Network error: '+e.message,'error');}
    finally{btn.innerHTML='<i class="bi bi-check2-circle"></i> Save region';btn.disabled=false;}
}
function addRegionToSelects(region){
    [document.getElementById('regionSelect'),document.getElementById('filterRegion')].forEach(sel=>{
        if(!sel)return;
        const o=document.createElement('option');
        o.value=region.id; o.textContent=region.name;
        sel.appendChild(o);
    });
}

/* ---------- Drop zone ---------- */
const dropZone=document.getElementById('dropZone');
const pdfInput=document.getElementById('pdfInput');
const fileChip=document.getElementById('fileChip');
const fileChipName=document.getElementById('fileChipName');
dropZone.addEventListener('click',()=>pdfInput.click());
dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.classList.add('dragover')});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop',e=>{
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if(e.dataTransfer.files.length){
        pdfInput.files=e.dataTransfer.files;
        showFileChip(e.dataTransfer.files[0]);
    }
});
pdfInput.addEventListener('change',()=>{
    if(pdfInput.files.length)showFileChip(pdfInput.files[0]);
});
function showFileChip(f){
    fileChip.style.display='inline-flex';
    fileChipName.textContent=f.name+' ('+(f.size/1024/1024).toFixed(2)+' MB)';
    if(!document.getElementById('titleInput').value){
        document.getElementById('titleInput').value=f.name.replace(/\.pdf$/i,'').replace(/[-_]+/g,' ');
    }
}
function resetUpload(){
    fileChip.style.display='none';
    document.getElementById('uploadProgress').style.display='none';
}

/* ---------- Upload submit ---------- */
document.getElementById('uploadForm').addEventListener('submit',async e=>{
    e.preventDefault();
    if(!pdfInput.files.length){toast('Please pick a PDF file first.','error');return;}
    const btn=document.getElementById('uploadBtn');
    const prog=document.getElementById('uploadProgress');
    const bar=prog.querySelector('.bar');
    btn.disabled=true;
    btn.innerHTML='<span class="spinner-mini"></span> Uploading…';
    prog.style.display='block';bar.style.width='5%';
    try{
        const fd=new FormData(e.target);
        const xhr=new XMLHttpRequest();
        xhr.open('POST',ENDPOINT);
        xhr.upload.onprogress=evt=>{
            if(evt.lengthComputable){
                const pct=Math.min(95,Math.round(evt.loaded/evt.total*100));
                bar.style.width=pct+'%';
            }
        };
        xhr.onload=()=>{
            bar.style.width='100%';
            try{
                const d=JSON.parse(xhr.responseText);
                if(!d.ok){toast(d.error||'Upload failed.','error');return;}
                toast('Brochure uploaded successfully!','success');
                e.target.reset();resetUpload();
                loadBrochures();
            }catch(err){toast('Bad server response.','error')}
            finally{
                btn.disabled=false;
                btn.innerHTML='<i class="bi bi-cloud-upload-fill"></i> Upload & generate page';
                setTimeout(()=>{prog.style.display='none';bar.style.width='0%'},900);
            }
        };
        xhr.onerror=()=>{toast('Network error.','error');btn.disabled=false;btn.innerHTML='<i class="bi bi-cloud-upload-fill"></i> Upload & generate page';prog.style.display='none'};
        xhr.send(fd);
    }catch(err){
        toast('Upload failed: '+err.message,'error');
        btn.disabled=false;btn.innerHTML='<i class="bi bi-cloud-upload-fill"></i> Upload & generate page';
        prog.style.display='none';
    }
});

/* ---------- Load brochures ---------- */
async function loadBrochures(){
    const q=document.getElementById('searchBox').value.trim();
    const region=document.getElementById('filterRegion').value;
    const url=new URL(ENDPOINT,window.location.href);
    url.searchParams.set('action','list_brochures');
    if(q) url.searchParams.set('q',q);
    if(region&&region!=='0') url.searchParams.set('region_id',region);
    try{
        const res=await fetch(url);
        const d=await res.json();
        if(!d.ok){renderEmpty(d.error||'Failed to load.');return;}
        BROCHURES=d.brochures||[];
        renderBrochures();
        updateStats();
    }catch(e){renderEmpty('Network error: '+e.message)}
}

function updateStats(){
    document.getElementById('stat-total').textContent=BROCHURES.length;
    document.getElementById('stat-views').textContent=BROCHURES.reduce((a,b)=>a+(b.view_count||0),0);
    document.getElementById('stat-shares').textContent=BROCHURES.reduce((a,b)=>a+(b.share_count||0),0);
}

function renderBrochures(){
    const c=document.getElementById('brochureContainer');
    if(!BROCHURES.length){renderEmpty();return;}
    let html='<div class="brochure-grid">';
    for(const b of BROCHURES){
        const sizeMb=(b.pdf_size_bytes/1024/1024).toFixed(2);
        const attachOn = (b.attach_pdf||0) === 1;
        const extStatus = b.extraction_status||'pending';
        const extBadge = extStatus==='ok'
            ? '<span class="table-tag" style="background:var(--brand-soft);color:var(--brand);border-color:transparent">HTML ready</span>'
            : '<span class="table-tag" style="background:#fef3c7;color:#92400e;border-color:transparent">PDF only</span>';
        html += `
            <div class="brochure-card">
                <div class="cover">
                    <span class="region-tag"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(b.region_name||'—')}</span>
                    <i class="bi bi-file-earmark-richtext-fill pdf-icon"></i>
                    <div style="font-size:.75rem;opacity:.85;margin-top:8px">PDF · ${sizeMb} MB</div>
                </div>
                <div class="body">
                    <div class="title">${escapeHtml(b.title)}</div>
                    <div class="meta">
                        <span><i class="bi bi-eye"></i> ${b.view_count}</span>
                        <span><i class="bi bi-share"></i> ${b.share_count}</span>
                        <span><i class="bi bi-clock"></i> ${formatDate(b.created_at)}</span>
                        ${extBadge}
                    </div>
                </div>
                <div class="share-block">
                    <div class="share-label"><i class="bi bi-link-45deg"></i> Auto-generated share link</div>
                    <div class="link-row">
                        <input type="text" value="${escapeHtml(b.share_url)}" readonly onclick="this.select()" title="Share link">
                        <button class="copy-btn" onclick="quickCopyLink(${b.id},'${escapeJs(b.share_url)}')"><i class="bi bi-clipboard-check"></i> Copy</button>
                    </div>
                    <div class="share-actions">
                        <a class="wa" href="https://wa.me/?text=${encodeURIComponent('Hi! Please find our brochure: '+b.title+'\\n'+b.share_url)}" target="_blank" rel="noopener" onclick="logQuickShare(${b.id},'whatsapp')">
                            <i class="bi bi-whatsapp"></i> WhatsApp
                        </a>
                        <a class="em" href="mailto:?subject=${encodeURIComponent(b.title)}&body=${encodeURIComponent('Hi,\\n\\nPlease find our brochure: '+b.title+'\\n'+b.share_url+'\\n\\nBest regards,\\nParrot Canada Visa Consultant')}" onclick="logQuickShare(${b.id},'email')">
                            <i class="bi bi-envelope-fill"></i> Email
                        </a>
                        <button type="button" class="send" onclick="openShareModal(${b.id})">
                            <i class="bi bi-person-lines-fill"></i> Send to customer
                        </button>
                    </div>
                </div>
                <div class="footer">
                    <button onclick="previewBrochure('${escapeJs(b.share_url)}')"><i class="bi bi-eye-fill"></i> Preview</button>
                    <label class="attach-toggle" title="When ON, the customer page also shows the original PDF and a download button.">
                        <input type="checkbox" ${attachOn?'checked':''} onchange="toggleAttachPdf(${b.id}, this.checked)">
                        <i class="bi bi-file-earmark-pdf"></i> Attach PDF
                    </label>
                    <button class="danger" onclick="deleteBrochure(${b.id})" title="Delete"><i class="bi bi-trash3-fill"></i></button>
                </div>
            </div>`;
    }
    html+='</div>';
    c.innerHTML=html;
}

function renderEmpty(msg){
    document.getElementById('brochureContainer').innerHTML = `
        <div class="empty-state">
            <i class="bi bi-folder2-open"></i>
            <h4>${msg?escapeHtml(msg):'No brochures yet'}</h4>
            <p>${msg?'':'Upload your first PDF above and a public, branded share link is generated automatically.'}</p>
        </div>`;
}

function previewBrochure(url){window.open(url,'_blank');}
async function deleteBrochure(id){
    if(!confirm('Delete this brochure? This cannot be undone.'))return;
    const fd=new FormData();
    fd.append('action','delete_brochure');
    fd.append('csrf_token',CSRF);
    fd.append('id',id);
    const res=await fetch(ENDPOINT,{method:'POST',body:fd});
    const d=await res.json();
    if(!d.ok){toast(d.error||'Failed to delete.','error');return;}
    toast('Brochure removed.','success');
    loadBrochures();
}

/* ---------- Share ---------- */
function openShareModal(brochureId){
    const b=BROCHURES.find(x=>x.id===brochureId);
    if(!b){toast('Brochure not found.','error');return;}
    SHARE_STATE={brochure:b,match:null,is_new:false,name:'',email:'',phone:'',links:null};
    document.getElementById('shareTitle').textContent=' — '+b.title;
    document.getElementById('sharePhone').value='';
    document.getElementById('lookupResults').innerHTML='';
    document.getElementById('newName').value='';
    document.getElementById('newEmail').value='';
    showPane(1);
    openModal('shareModal');
    setTimeout(()=>document.getElementById('sharePhone').focus(),120);
}
function showPane(n){
    [1,2,3].forEach(i=>{
        document.getElementById('sharePane'+i).style.display = (i===n)?'block':'none';
        document.getElementById('step'+i).classList.toggle('active',i<=n);
    });
}
function backToPane(n){showPane(n)}

async function runLookup(){
    const phone=document.getElementById('sharePhone').value.trim();
    if(!phone){toast('Please enter a phone number.','error');return;}
    SHARE_STATE.phone=phone;
    const btn=document.getElementById('lookupBtn');
    btn.disabled=true;btn.innerHTML='<span class="spinner-mini"></span> Searching…';
    try{
        const url=new URL(ENDPOINT,window.location.href);
        url.searchParams.set('action','lookup_phone');
        url.searchParams.set('phone',phone);
        const res=await fetch(url);
        const d=await res.json();
        const box=document.getElementById('lookupResults');
        if(!d.ok){toast(d.error||'Lookup failed.','error');return;}
        if(!d.matches.length){
            box.innerHTML = `
                <div class="match-card" style="cursor:default;border-color:var(--accent);background:var(--accent-soft)">
                    <div class="name"><i class="bi bi-person-x"></i> No applicant found for this number.</div>
                    <div class="row-meta">Click "Add new contact" to send them the brochure anyway.</div>
                </div>
                <div style="margin-top:12px;display:flex;justify-content:flex-end">
                    <button class="btn-accent" onclick="goToAddNew()">
                        <i class="bi bi-person-plus-fill"></i> Add new contact
                    </button>
                </div>`;
        }else{
            let h='<small class="text-muted">Found '+d.count+' matching applicant(s). Pick one to autofill:</small>';
            d.matches.forEach((m,idx)=>{
                h += `<div class="match-card" onclick="selectMatch(${idx})" data-idx="${idx}">
                    <div class="name">${escapeHtml(m.name||'(no name)')}<span class="table-tag">${escapeHtml(m.table)}</span></div>
                    <div class="row-meta"><i class="bi bi-telephone"></i> ${escapeHtml(m.phone||'-')} ${m.email?' · <i class=\"bi bi-envelope\"></i> '+escapeHtml(m.email):''}</div>
                </div>`;
            });
            h += `<div style="margin-top:14px;display:flex;justify-content:space-between;gap:8px">
                    <button class="btn-ghost" onclick="goToAddNew()">
                        <i class="bi bi-person-plus"></i> Use a different contact
                    </button>
                    <button class="btn-brand" onclick="goToShare(false)" id="useMatchBtn" disabled>
                        Continue with selected <i class="bi bi-arrow-right"></i>
                    </button>
                </div>`;
            box.innerHTML=h;
            SHARE_STATE.matches=d.matches;
        }
    }catch(e){toast('Lookup failed: '+e.message,'error')}
    finally{btn.disabled=false;btn.innerHTML='<i class="bi bi-search"></i> Look up number';}
}
function selectMatch(idx){
    SHARE_STATE.match=SHARE_STATE.matches[idx];
    document.querySelectorAll('.match-card[data-idx]').forEach(c=>c.classList.remove('selected'));
    document.querySelector('.match-card[data-idx="'+idx+'"]').classList.add('selected');
    document.getElementById('useMatchBtn').disabled=false;
}
function goToAddNew(){
    SHARE_STATE.is_new=true;
    SHARE_STATE.match=null;
    showPane(2);
}
async function goToShare(viaNew){
    if(viaNew){
        SHARE_STATE.name=document.getElementById('newName').value.trim();
        SHARE_STATE.email=document.getElementById('newEmail').value.trim();
        if(!SHARE_STATE.name){toast('Please enter a name.','error');return;}
    }else if(SHARE_STATE.match){
        SHARE_STATE.name=SHARE_STATE.match.name;
        SHARE_STATE.email=SHARE_STATE.match.email;
        SHARE_STATE.is_new=false;
    }
    const fd=new FormData();
    fd.append('action','share_brochure');
    fd.append('csrf_token',CSRF);
    fd.append('brochure_id',SHARE_STATE.brochure.id);
    fd.append('channel','copy');
    fd.append('name',SHARE_STATE.name||'');
    fd.append('phone',SHARE_STATE.phone||'');
    fd.append('email',SHARE_STATE.email||'');
    fd.append('is_new_contact',SHARE_STATE.is_new?'1':'0');
    if(SHARE_STATE.match){
        fd.append('matched_table',SHARE_STATE.match.table||'');
        fd.append('matched_row_id',SHARE_STATE.match.row_id||0);
    }
    try{
        const res=await fetch(ENDPOINT,{method:'POST',body:fd});
        const d=await res.json();
        if(!d.ok){toast(d.error||'Share failed.','error');return;}
        SHARE_STATE.links=d;
        document.getElementById('shareLinkBox').value=d.share_url;
        document.getElementById('chWhatsapp').href=d.whatsapp_url||'#';
        if(!d.whatsapp_url){
            document.getElementById('chWhatsapp').onclick=e=>{e.preventDefault();toast('Phone could not be normalized for WhatsApp.','error')};
        }
        document.getElementById('chEmail').href=d.email_url||'#';
        if(!d.email_url){
            document.getElementById('chEmail').onclick=e=>{e.preventDefault();toast('No email captured for this contact.','error')};
        }
        document.getElementById('shareRecipientBox').innerHTML = `
            <div class="name"><i class="bi bi-person-fill"></i> ${escapeHtml(SHARE_STATE.name||'New contact')}</div>
            <div class="row-meta">
                <i class="bi bi-telephone"></i> ${escapeHtml(SHARE_STATE.phone||'-')}
                ${SHARE_STATE.email?'· <i class=\"bi bi-envelope\"></i> '+escapeHtml(SHARE_STATE.email):''}
                ${SHARE_STATE.is_new?' · <span class=\"table-tag\" style=\"background:var(--accent-soft);color:var(--accent);border-color:transparent\">NEW</span>':''}
            </div>`;
        showPane(3);
        loadBrochures(); // refresh stats
    }catch(e){toast('Share failed: '+e.message,'error')}
}

function copyShareLink(silent){
    const inp=document.getElementById('shareLinkBox');
    inp.select();inp.setSelectionRange(0,inp.value.length);
    try{
        document.execCommand('copy');
        if(!silent)toast('Share link copied!','success');
        else toast('Share link copied to clipboard.','success');
    }catch(e){
        navigator.clipboard.writeText(inp.value).then(()=>toast('Link copied!','success'));
    }
}
function trackShare(channel){
    if(!SHARE_STATE.brochure)return;
    const fd=new FormData();
    fd.append('action','share_brochure');
    fd.append('csrf_token',CSRF);
    fd.append('brochure_id',SHARE_STATE.brochure.id);
    fd.append('channel',channel);
    fd.append('name',SHARE_STATE.name||'');
    fd.append('phone',SHARE_STATE.phone||'');
    fd.append('email',SHARE_STATE.email||'');
    fd.append('is_new_contact','0');
    if(SHARE_STATE.match){
        fd.append('matched_table',SHARE_STATE.match.table||'');
        fd.append('matched_row_id',SHARE_STATE.match.row_id||0);
    }
    fetch(ENDPOINT,{method:'POST',body:fd}).catch(()=>{});
}

/* ---------- Quick share (no lookup required) ---------- */
function quickCopyLink(id,url){
    if(navigator.clipboard){
        navigator.clipboard.writeText(url).then(()=>toast('Share link copied!','success'));
    }else{
        const ta=document.createElement('textarea');ta.value=url;document.body.appendChild(ta);
        ta.select();document.execCommand('copy');document.body.removeChild(ta);
        toast('Share link copied!','success');
    }
    logQuickShare(id,'copy');
}
function quickWhatsApp(id,title,url){
    const msg = 'Hi! Please find our brochure: '+title+'\n'+url;
    window.open('https://wa.me/?text='+encodeURIComponent(msg),'_blank');
    logQuickShare(id,'whatsapp');
}
function logQuickShare(brochureId,channel){
    const fd=new FormData();
    fd.append('action','share_brochure');
    fd.append('csrf_token',CSRF);
    fd.append('brochure_id',brochureId);
    fd.append('channel',channel);
    fd.append('notes','quick-share');
    fetch(ENDPOINT,{method:'POST',body:fd})
      .then(r=>r.json()).then(()=>setTimeout(loadBrochures,400)).catch(()=>{});
}
async function toggleAttachPdf(id,checked){
    const fd=new FormData();
    fd.append('action','set_attach_pdf');
    fd.append('csrf_token',CSRF);
    fd.append('id',id);
    if(checked) fd.append('attach_pdf','1');
    try{
        const res=await fetch(ENDPOINT,{method:'POST',body:fd});
        const d=await res.json();
        if(!d.ok){toast(d.error||'Failed to update.','error');return;}
        toast(checked?'Original PDF will be attached on the public page.':'Public page will hide the PDF (HTML only).','success');
        loadBrochures();
    }catch(e){toast('Network error: '+e.message,'error')}
}

/* ---------- Utils ---------- */
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function escapeJs(s){return String(s??'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/\r?\n/g,' ')}
function formatDate(s){if(!s)return'';const d=new Date(s.replace(' ','T'));return d.toLocaleDateString('en',{month:'short',day:'numeric',year:'numeric'})}

document.getElementById('searchBox').addEventListener('input',()=>{clearTimeout(window.__searchT);window.__searchT=setTimeout(loadBrochures,260)});
document.getElementById('filterRegion').addEventListener('change',loadBrochures);
document.addEventListener('click',e=>{
    if(e.target.classList && e.target.classList.contains('modal-mask'))closeModal(e.target.id);
});

loadBrochures();
</script>
</body>
</html>

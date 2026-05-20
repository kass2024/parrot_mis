<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/marketing_brochure_schema.php';

pcvc_marketing_brochure_ensure_schema($conn);

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(400);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:40px">Missing brochure reference.</h1>';
    exit;
}

$stmt = $conn->prepare(
    'SELECT b.id, b.title, b.slug, b.description, b.pdf_filename, b.pdf_path,
            b.html_content, b.attach_pdf, b.extraction_status,
            b.view_count, b.share_count, b.created_at, b.region_id, r.name AS region_name
     FROM marketing_brochures b
     LEFT JOIN regions r ON r.id = b.region_id
     WHERE b.slug = ? AND b.is_active = 1
     LIMIT 1'
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$brochure = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$brochure) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:60px">Brochure not found.</h1>';
    exit;
}

$conn->query('UPDATE marketing_brochures SET view_count = view_count + 1 WHERE id = ' . (int) $brochure['id']);

$shareToken = trim((string) ($_GET['s'] ?? ''));
if ($shareToken !== '') {
    $u = $conn->prepare('UPDATE marketing_brochure_shares
                         SET open_count = open_count + 1, last_opened_at = NOW()
                         WHERE share_token = ?');
    $u->bind_param('s', $shareToken);
    $u->execute();
    $u->close();
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script  = $_SERVER['SCRIPT_NAME'] ?? '';
$baseDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
$pdfUrl  = $baseDir . '/' . ltrim((string) $brochure['pdf_path'], '/');
$pageUrl = $scheme . '://' . $host . $baseDir . '/brochure-view.php?slug=' . urlencode((string) $brochure['slug']);

$regionName = trim((string) ($brochure['region_name'] ?? '')) ?: 'Global';
$createdAt  = !empty($brochure['created_at']) ? date('F j, Y', strtotime((string) $brochure['created_at'])) : '';

$title       = (string) $brochure['title'];
$description = trim((string) ($brochure['description'] ?? '')) ?: ($title . ' — official brochure for ' . $regionName . '.');
$htmlContent = (string) ($brochure['html_content'] ?? '');
$attachPdf   = (int) ($brochure['attach_pdf'] ?? 1) === 1;
$hasHtml     = trim($htmlContent) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($regionName) ?> | Parrot Canada Visa Consultant</title>
<meta name="description" content="<?= htmlspecialchars($description) ?>">

<meta property="og:type" content="article">
<meta property="og:title" content="<?= htmlspecialchars($title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($description) ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageUrl) ?>">
<meta property="og:image" content="<?= htmlspecialchars($baseDir) ?>/parrot-canada-logo.png">
<meta name="twitter:card" content="summary_large_image">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
:root{
    --brand:#427431;--brand-dark:#2f5a26;--brand-soft:#e8f1e1;
    --accent:#E21D1E;--whatsapp:#25D366;--info:#3661B9;
    --text:#1e293b;--muted:#64748b;--border:#e2e8f0;
    --bg:#f5f7fb;--surface:#fff;
    --shadow-sm:0 1px 3px rgba(15,23,42,.07);
    --shadow:0 12px 30px -12px rgba(15,23,42,.18);
    --shadow-lg:0 28px 70px -20px rgba(15,23,42,.25);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
    font-family:'Inter',system-ui,sans-serif;
    background:var(--bg);color:var(--text);line-height:1.6;
}

/* ---------- Header ---------- */
.site-header{
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    color:#fff;padding:14px 0;
    box-shadow:0 4px 20px rgba(0,0,0,.18);
    border-bottom:3px solid var(--accent);
    position:sticky;top:0;z-index:50;
}
.container{max-width:1180px;margin:0 auto;padding:0 22px}
.site-header .row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px}
.brand-mark{
    width:42px;height:42px;border-radius:10px;background:#fff;
    display:grid;place-items:center;color:var(--brand);font-weight:800;font-size:1.2rem;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
}
.brand-name{font-weight:800;font-size:1.1rem;letter-spacing:.4px}
.brand-tag{font-size:.74rem;opacity:.85;text-transform:uppercase;letter-spacing:1.2px}
.site-header .tools{display:flex;gap:8px;flex-wrap:wrap}
.btn-pill{
    background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.28);
    color:#fff;padding:8px 14px;border-radius:999px;font-size:.82rem;font-weight:600;
    text-decoration:none;display:inline-flex;align-items:center;gap:6px;
    transition:.2s;cursor:pointer;
}
.btn-pill:hover{background:rgba(255,255,255,.28);color:#fff}
.btn-pill.solid{background:#fff;color:var(--brand)}
.btn-pill.solid:hover{background:#fdfdfd}

/* ---------- Hero ---------- */
.hero{
    background:linear-gradient(135deg,#fff 0%,#f0f4f8 100%);
    padding:36px 0 26px;
    position:relative;overflow:hidden;
}
.hero::before{
    content:'';position:absolute;right:-120px;top:-120px;
    width:380px;height:380px;border-radius:50%;
    background:radial-gradient(circle,rgba(66,116,49,.08) 0%,transparent 70%);
}
.hero-inner{display:grid;grid-template-columns:1.4fr 1fr;gap:36px;align-items:center}
@media (max-width:860px){.hero-inner{grid-template-columns:1fr}}
.hero .region-pill{
    display:inline-flex;align-items:center;gap:6px;
    background:var(--brand-soft);color:var(--brand);
    padding:6px 14px;border-radius:999px;font-weight:700;font-size:.78rem;
    text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;
}
.hero h1{
    font-size:2.2rem;line-height:1.18;font-weight:800;
    color:var(--text);margin-bottom:14px;
}
.hero .lead{font-size:1.05rem;color:var(--muted);max-width:600px;margin-bottom:18px}
.hero .meta-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;color:var(--muted);font-size:.85rem}
.hero .meta-row span{display:inline-flex;align-items:center;gap:6px}
.hero .actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{
    border:none;padding:12px 20px;border-radius:12px;font-weight:600;font-size:.92rem;
    cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;
    transition:.2s;
}
.btn-primary{background:var(--brand);color:#fff;box-shadow:var(--shadow-sm)}
.btn-primary:hover{background:var(--brand-dark);transform:translateY(-1px)}
.btn-wa{background:var(--whatsapp);color:#fff}
.btn-wa:hover{background:#1da750;color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--brand);color:var(--brand)}
.btn-outline:hover{background:var(--brand-soft)}

.hero-card{
    background:#fff;border-radius:20px;padding:22px;border:1px solid var(--border);
    box-shadow:var(--shadow-lg);
    position:relative;
}
.hero-card .pdf-thumb{
    border-radius:14px;overflow:hidden;
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    color:#fff;padding:36px 20px;text-align:center;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.1);
}
.hero-card .pdf-thumb i{font-size:5rem;opacity:.9}
.hero-card .pdf-thumb .file-name{
    margin-top:14px;font-weight:600;font-size:.95rem;
    word-break:break-all;opacity:.95;
}
.hero-card .qr-box{
    margin-top:14px;background:#fafbfd;border-radius:12px;padding:14px;
    display:flex;align-items:center;gap:14px;border:1px solid var(--border);
}
.hero-card .qr-box img{width:90px;height:90px;border-radius:8px;background:#fff;padding:4px;border:1px solid var(--border)}
.hero-card .qr-box .label{font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.hero-card .qr-box .url{font-size:.78rem;color:var(--text);word-break:break-all}

/* ---------- Reader section ---------- */
.section{padding:38px 0}
.section h2{
    font-size:1.4rem;font-weight:700;margin-bottom:8px;
    display:flex;align-items:center;gap:10px;color:var(--text);
}
.section h2::before{
    content:'';width:6px;height:24px;background:var(--brand);border-radius:3px;
}
.section .lead2{color:var(--muted);font-size:.92rem;margin-bottom:18px}

/* ---------- Article (extracted HTML) ---------- */
.article-card{
    background:#fff;border:1px solid var(--border);border-radius:18px;
    padding:34px 42px;box-shadow:var(--shadow);
    max-width:880px;margin:0 auto;font-size:1.02rem;line-height:1.75;
}
@media (max-width:680px){.article-card{padding:24px 22px}}
.article-card .brochure-heading{
    font-size:1.35rem;font-weight:800;color:var(--brand-dark);
    margin:28px 0 8px;letter-spacing:.3px;
    padding-bottom:8px;border-bottom:2px solid var(--brand-soft);
}
.article-card .brochure-subheading{
    font-size:1.08rem;font-weight:700;color:var(--text);margin:22px 0 6px;
}
.article-card .brochure-para{
    margin:0 0 14px;color:var(--text);
}
.article-card .brochure-list{
    margin:0 0 18px;padding-left:22px;
}
.article-card .brochure-list li{
    margin-bottom:8px;color:var(--text);
}
.article-card ul.brochure-list li::marker{color:var(--brand)}
.article-card ol.brochure-list li::marker{color:var(--brand);font-weight:700}
.article-card a{color:var(--info);text-decoration:underline}
.article-card a:hover{color:var(--brand-dark)}
.article-card > :first-child{margin-top:0}

.pdf-viewer{
    background:#1e293b;border-radius:16px;overflow:hidden;
    box-shadow:var(--shadow-lg);position:relative;
}
.pdf-viewer .toolbar{
    background:#0f172a;color:#fff;padding:10px 16px;
    display:flex;justify-content:space-between;align-items:center;
    font-size:.85rem;
}
.pdf-viewer .toolbar a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-weight:600;opacity:.85;transition:.2s}
.pdf-viewer .toolbar a:hover{opacity:1}
.pdf-viewer iframe{width:100%;height:800px;border:none;background:#fff;display:block}
@media (max-width:860px){.pdf-viewer iframe{height:560px}}

/* ---------- Features ---------- */
.features{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:18px;margin-top:24px;
}
.feature{
    background:#fff;border:1px solid var(--border);border-radius:14px;
    padding:20px;box-shadow:var(--shadow-sm);transition:.2s;
}
.feature:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.feature .icon{
    width:46px;height:46px;border-radius:12px;color:#fff;
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    display:grid;place-items:center;font-size:1.3rem;margin-bottom:12px;
}
.feature h5{font-size:1rem;font-weight:700;margin-bottom:6px}
.feature p{font-size:.85rem;color:var(--muted);margin:0}

/* ---------- CTA ---------- */
.cta{
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
    color:#fff;border-radius:20px;padding:38px;text-align:center;
    box-shadow:var(--shadow-lg);position:relative;overflow:hidden;
}
.cta::before{
    content:'';position:absolute;left:-80px;bottom:-80px;
    width:300px;height:300px;border-radius:50%;
    background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);
}
.cta h2{font-size:1.6rem;font-weight:800;margin-bottom:8px;justify-content:center}
.cta h2::before{display:none}
.cta p{max-width:620px;margin:0 auto 22px;opacity:.92;font-size:1rem}
.cta .actions{justify-content:center;display:flex;gap:10px;flex-wrap:wrap}
.cta .btn-primary{background:#fff;color:var(--brand)}
.cta .btn-primary:hover{background:#fafafa}
.cta .btn-outline{border-color:#fff;color:#fff}
.cta .btn-outline:hover{background:rgba(255,255,255,.12)}

/* ---------- Footer ---------- */
.site-footer{
    background:#0f172a;color:#cbd5e1;padding:30px 0;margin-top:38px;
}
.site-footer .row{display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:center}
.site-footer .links a{color:#cbd5e1;text-decoration:none;margin-right:18px;font-size:.85rem}
.site-footer .links a:hover{color:#fff}
.site-footer small{font-size:.78rem;opacity:.7}

/* ---------- Toast for copy ---------- */
.fly-toast{
    position:fixed;bottom:28px;left:50%;transform:translateX(-50%);
    background:#1e293b;color:#fff;padding:12px 22px;border-radius:999px;
    box-shadow:var(--shadow-lg);font-size:.88rem;font-weight:600;
    z-index:200;opacity:0;transition:.25s;pointer-events:none;
}
.fly-toast.show{opacity:1;transform:translateX(-50%) translateY(-6px)}
</style>
</head>
<body>

<header class="site-header">
    <div class="container">
        <div class="row">
            <div class="brand">
                <div class="brand-mark">P</div>
                <div>
                    <div class="brand-name">Parrot Canada Visa Consultant</div>
                    <div class="brand-tag">Official brochure · <?= htmlspecialchars($regionName) ?></div>
                </div>
            </div>
            <div class="tools">
                <?php if ($attachPdf): ?>
                    <a href="<?= htmlspecialchars($pdfUrl) ?>" download class="btn-pill"><i class="bi bi-download"></i> Download PDF</a>
                <?php endif; ?>
                <button class="btn-pill" onclick="copyPageLink()"><i class="bi bi-link-45deg"></i> Copy link</button>
                <button class="btn-pill solid" onclick="shareNative()"><i class="bi bi-share-fill"></i> Share</button>
            </div>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container hero-inner">
        <div>
            <span class="region-pill"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($regionName) ?></span>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p class="lead"><?= nl2br(htmlspecialchars($description)) ?></p>
            <div class="meta-row">
                <?php if ($createdAt): ?><span><i class="bi bi-calendar3"></i> Published <?= htmlspecialchars($createdAt) ?></span><?php endif; ?>
                <span><i class="bi bi-eye"></i> <?= number_format((int) $brochure['view_count']) ?> views</span>
                <span><i class="bi bi-share"></i> <?= number_format((int) $brochure['share_count']) ?> shares</span>
                <span><i class="bi bi-file-earmark-pdf"></i> Official PDF</span>
            </div>
            <div class="actions">
                <a href="#read" class="btn btn-primary"><i class="bi bi-book-half"></i> Read brochure</a>
                <?php if ($attachPdf): ?>
                    <a href="<?= htmlspecialchars($pdfUrl) ?>" download class="btn btn-outline"><i class="bi bi-download"></i> Download PDF</a>
                <?php endif; ?>
                <button class="btn btn-wa" onclick="shareWhatsApp()"><i class="bi bi-whatsapp"></i> Send via WhatsApp</button>
            </div>
        </div>
        <div class="hero-card">
            <div class="pdf-thumb">
                <i class="bi bi-file-earmark-richtext-fill"></i>
                <div class="file-name"><?= htmlspecialchars($title) ?></div>
            </div>
            <div class="qr-box">
                <img alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($pageUrl) ?>">
                <div>
                    <div class="label">Scan to share</div>
                    <div class="url" id="pageUrlText"><?= htmlspecialchars($pageUrl) ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ Beautified article rendered from the PDF ============ -->
<main class="container section" id="read">
    <h2><i class="bi bi-book-fill" style="color:var(--brand)"></i> <?= htmlspecialchars($title) ?></h2>
    <p class="lead2">Region: <strong><?= htmlspecialchars($regionName) ?></strong><?php if ($createdAt): ?> · Published <?= htmlspecialchars($createdAt) ?><?php endif; ?></p>

    <article class="article-card">
        <?php if ($hasHtml): ?>
            <?= $htmlContent /* sanitized inline by extractor */ ?>
        <?php else: ?>
            <h3 class="brochure-heading">About this brochure</h3>
            <p class="brochure-para"><?= nl2br(htmlspecialchars($description)) ?></p>
            <p class="brochure-para">The full content is available in the original document below — open it in your browser, download it, or contact our team for a personalised walk-through.</p>
        <?php endif; ?>
    </article>
</main>

<?php if ($attachPdf): ?>
<!-- ============ Original PDF (attached) ============ -->
<section class="container section" id="pdf">
    <h2><i class="bi bi-file-earmark-pdf-fill" style="color:var(--accent)"></i> Original PDF document</h2>
    <p class="lead2">The official PDF version is attached below — open it inline, zoom, or download.</p>
    <div class="pdf-viewer">
        <div class="toolbar">
            <span><i class="bi bi-file-earmark-pdf-fill" style="color:var(--accent)"></i> <?= htmlspecialchars($brochure['pdf_filename']) ?></span>
            <span>
                <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open in new tab</a>
                &nbsp;·&nbsp;
                <a href="<?= htmlspecialchars($pdfUrl) ?>" download><i class="bi bi-download"></i> Download</a>
            </span>
        </div>
        <iframe src="<?= htmlspecialchars($pdfUrl) ?>#view=FitH&toolbar=1" title="Brochure PDF"></iframe>
    </div>
</section>
<?php endif; ?>

<section class="container section">
    <h2><i class="bi bi-stars" style="color:var(--accent)"></i> Why choose Parrot Canada</h2>
    <p class="lead2">Trusted by hundreds of students applying to <?= htmlspecialchars($regionName) ?> and beyond.</p>
    <div class="features">
        <div class="feature">
            <div class="icon"><i class="bi bi-people-fill"></i></div>
            <h5>Personalised guidance</h5>
            <p>Dedicated counsellors guide you through the documents required for <?= htmlspecialchars($regionName) ?> admissions.</p>
        </div>
        <div class="feature">
            <div class="icon"><i class="bi bi-shield-check"></i></div>
            <h5>Verified universities</h5>
            <p>We only work with accredited universities and recognised institutions, ensuring credibility every step of the way.</p>
        </div>
        <div class="feature">
            <div class="icon"><i class="bi bi-clock-history"></i></div>
            <h5>Fast turnaround</h5>
            <p>Our team responds within hours and follows the full application timeline so deadlines are never missed.</p>
        </div>
        <div class="feature">
            <div class="icon"><i class="bi bi-cash-coin"></i></div>
            <h5>Affordable plans</h5>
            <p>Transparent pricing and flexible payment plans tailored to suit your financial situation.</p>
        </div>
    </div>
</section>

<section class="container">
    <div class="cta">
        <h2>Ready to take the next step?</h2>
        <p>Get in touch with our team and we'll walk you through the documents above and prepare a tailored admission plan for you.</p>
        <div class="actions">
            <button class="btn btn-primary" onclick="shareWhatsApp()"><i class="bi bi-whatsapp"></i> Chat on WhatsApp</button>
            <a href="mailto:admission@visaconsultantcanada.com?subject=<?= rawurlencode($title) ?>" class="btn btn-outline"><i class="bi bi-envelope-fill"></i> Email us</a>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div class="container row">
        <div>
            <strong>Parrot Canada Visa Consultant</strong>
            <div><small>© <?= date('Y') ?> · All rights reserved · <?= htmlspecialchars($regionName) ?> brochure</small></div>
        </div>
        <div class="links">
            <a href="mailto:admission@visaconsultantcanada.com">Contact</a>
            <a href="<?= htmlspecialchars($pdfUrl) ?>" download>Download PDF</a>
            <a href="#pdf">Read again</a>
        </div>
    </div>
</footer>

<div class="fly-toast" id="flyToast">Link copied!</div>

<script>
const PAGE_URL  = <?= json_encode($pageUrl) ?>;
const SHARE_MSG = <?= json_encode("Hi! Have a look at our brochure: " . $title . " — " . $pageUrl) ?>;

function showToast(msg){
    const t=document.getElementById('flyToast');
    t.textContent=msg;t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),2200);
}
function copyPageLink(){
    if(navigator.clipboard){
        navigator.clipboard.writeText(PAGE_URL).then(()=>showToast('Link copied to clipboard!'));
    }else{
        const ta=document.createElement('textarea');ta.value=PAGE_URL;document.body.appendChild(ta);
        ta.select();document.execCommand('copy');document.body.removeChild(ta);
        showToast('Link copied to clipboard!');
    }
}
function shareWhatsApp(){
    window.open('https://wa.me/?text='+encodeURIComponent(SHARE_MSG),'_blank');
}
async function shareNative(){
    if(navigator.share){
        try{ await navigator.share({title:document.title,text:<?= json_encode($description) ?>,url:PAGE_URL}); }
        catch(e){}
    }else{copyPageLink();}
}
</script>
</body>
</html>

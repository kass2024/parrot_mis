<?php
declare(strict_types=1);

/* =====================================================
   SESSION & AUTH
===================================================== */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_id'])) {
    header('Location: /parrot/admin-login.php');
    exit;
}

$loggedInAdminId = (int) $_SESSION['admin_id'];
$loggedInRole    = $_SESSION['role'] ?? 'staff';

/* =====================================================
   BOOTSTRAP
===================================================== */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
use setasign\Fpdi\Fpdi;

/* =====================================================
   RESOLVE TARGET STAFF LIST (ORIGINAL LOGIC + UI SUPPORT)
===================================================== */
$staffIds = [];

/* ---- STAFF: only own card ---- */
if ($loggedInRole === 'staff') {
    $staffIds[] = $loggedInAdminId;
}

/* ---- SUPERADMIN INPUT ---- */
if ($loggedInRole === 'superadmin') {

    if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
        $staffIds[] = (int) $_GET['id'];
    }

    elseif (isset($_GET['ids'])) {

        // UI checkboxes: ids[]
        if (is_array($_GET['ids'])) {
            $ids = array_filter($_GET['ids'], 'ctype_digit');
            $staffIds = array_map('intval', $ids);
        }

        // Legacy: ids=1,2,3
        elseif (is_string($_GET['ids'])) {
            $ids = array_filter(
                array_map('trim', explode(',', $_GET['ids'])),
                'ctype_digit'
            );
            $staffIds = array_map('intval', $ids);
        }
    }

    elseif (isset($_GET['all'])) {
        // handled below
    }
}

/* =====================================================
   SUPERADMIN UI FALLBACK (ONLY WHEN NOTHING SELECTED)
===================================================== */
if ($loggedInRole === 'superadmin' && empty($staffIds) && !isset($_GET['all'])) {

    $result = $conn->query("
        SELECT id, full_name, role
        FROM admins
        WHERE role IN ('staff','superadmin')
        ORDER BY full_name
    ");
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Generate Staff Cards</title>
        <style>
            body { font-family: Arial, sans-serif; background:#f5f6fa; }
            .box {
                max-width: 650px;
                margin: 50px auto;
                background: #fff;
                padding: 25px;
                border-radius: 8px;
                box-shadow: 0 10px 25px rgba(0,0,0,.08);
            }
            h2 { margin-bottom: 10px; }
            .list { max-height: 300px; overflow-y:auto; border:1px solid #ddd; padding:10px; }
            label { display:block; padding:6px 0; }
            .actions { margin-top:20px; text-align:center; }
            button {
                background:#c00; color:#fff; border:none;
                padding:10px 20px; border-radius:5px; cursor:pointer;
            }
        </style>
        <script>
            function toggleAll(source) {
                document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = source.checked);
            }
        </script>
    </head>
    <body>
        <div class="box">
            <h2>Generate Staff Cards</h2>
            <p>Select staff members before generating ID cards.</p>

            <form method="get">
                <label>
                    <input type="checkbox" onclick="toggleAll(this)"> <strong>Select All</strong>
                </label>

                <div class="list">
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <label>
                            <input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>">
                            <?= htmlspecialchars($row['full_name']) ?> (<?= htmlspecialchars($row['role']) ?>)
                        </label>
                    <?php endwhile; ?>
                </div>

                <div class="actions">
                    <button type="submit">Generate Cards</button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ---- SUPERADMIN DEFAULT: ALL STAFF + SUPERADMIN ---- */
if ($loggedInRole === 'superadmin' && empty($staffIds)) {
    $res = $conn->query("
        SELECT id
        FROM admins
        WHERE role IN ('staff','superadmin')
    ");
    while ($r = $res->fetch_assoc()) {
        $staffIds[] = (int) $r['id'];
    }
}

$staffIds = array_values(array_unique($staffIds));

if (empty($staffIds)) {
    http_response_code(403);
    exit('Access denied');
}

/* =====================================================
   ORIGINAL GD TRUE CIRCLE CROP (RESTORED 100%)
===================================================== */
function gdTrueCircleCrop(string $src, string $dest, int $size = 600): bool
{
    if (!file_exists($src)) return false;
    $info = getimagesize($src);
    if (!$info) return false;

    $srcImg = match ($info['mime']) {
        'image/jpeg' => imagecreatefromjpeg($src),
        'image/png'  => imagecreatefrompng($src),
        default      => null
    };
    if (!$srcImg) return false;

    $w = imagesx($srcImg);
    $h = imagesy($srcImg);

    $side  = min($w, $h);
    $cropX = (int)(($w - $side) / 2);
    $cropY = max(0, (int)(($h - $side) * 0.25));

    $square = imagecreatetruecolor($size, $size);
    imagecopyresampled($square, $srcImg, 0, 0, $cropX, $cropY, $size, $size, $side, $side);

    $circle = imagecreatetruecolor($size, $size);
    imagesavealpha($circle, true);
    imagealphablending($circle, false);

    $transparent = imagecolorallocatealpha($circle, 0, 0, 0, 127);
    imagefill($circle, 0, 0, $transparent);

    $r = $size / 2;
    for ($x = 0; $x < $size; $x++) {
        for ($y = 0; $y < $size; $y++) {
            if ((($x - $r) ** 2 + ($y - $r) ** 2) <= ($r ** 2)) {
                imagesetpixel($circle, $x, $y, imagecolorat($square, $x, $y));
            }
        }
    }

    imagepng($circle, $dest);
    imagedestroy($srcImg);
    imagedestroy($square);
    imagedestroy($circle);
    return true;
}

/* =====================================================
   PDF INIT (ORIGINAL SETTINGS)
===================================================== */
$pdf = new FPDI('P', 'mm', [69.85, 101.6]);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);

$templatePath = __DIR__ . '/templates/PARROT-STAFF CARD TEMPLATE.pdf';
$pdf->setSourceFile($templatePath);
$templateId = $pdf->importPage(1);

/* =====================================================
   FETCH & RENDER STAFF (ORIGINAL – FULL INFO)
===================================================== */
$stmt = $conn->prepare("
    SELECT full_name, phone_number, position, profile_photo
    FROM admins
    WHERE id = ? AND role IN ('staff','superadmin')
    LIMIT 1
");

foreach ($staffIds as $staffId) {

    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    if (!$staff) continue;

    $fullName   = strtoupper($staff['full_name']);
    $position   = strtoupper(trim($staff['position'] ?? 'STAFF'));
    $cleanPhone = preg_replace('/[^0-9+]/', '', $staff['phone_number'] ?? '');

    $pdf->AddPage();
    $pdf->useTemplate($templateId, 0, 0, 69.85, 101.6);

    /* PHOTO */
    if (!empty($staff['profile_photo'])) {
        $src = __DIR__ . '/../uploads/' . $staff['profile_photo'];
        $tmp = sys_get_temp_dir() . "/staff_{$staffId}.png";
        if (file_exists($src) && gdTrueCircleCrop($src, $tmp)) {
            $pdf->Image($tmp, 19.5, 26, 30, 30);
        }
    }

    /* NAME BAR */
    $pdf->SetFillColor(200, 0, 0);
    $pdf->Rect(0, 61.5, 69.85, 7, 'F');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(0, 63.3);
    $pdf->Cell(69.85, 4, $fullName, 0, 0, 'C');

    /* POSITION */
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(200, 0, 0);
    $pdf->SetXY(5, 71);
    $pdf->Cell(59.85, 5, $position, 0, 0, 'C');

    /* PHONE */
    if ($cleanPhone !== '') {
        $formatted = preg_replace('/(\d)(?=(\d{3})+(?!\d))/', '$1 ', $cleanPhone);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(26, 80);
        $pdf->Cell(26, 5, $formatted, 0, 0, 'C');
    }
}

$stmt->close();

/* =====================================================
   OUTPUT
===================================================== */
$pdf->Output('I', 'staff_cards.pdf');
exit;

<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/env_load.php';
require_once __DIR__ . '/helpers/phone_whatsapp_normalize.php';
require_once __DIR__ . '/helpers/student_status_notify.php';
require_once __DIR__ . '/helpers/marketing_brochure_schema.php';
require_once __DIR__ . '/helpers/marketing_brochure_extract.php';

pcvc_marketing_brochure_ensure_schema($conn);

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated. Please log in again.']);
    exit;
}

$adminId = (int) $_SESSION['id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Respond as JSON and stop.
 */
function pcvc_brochure_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Slugify a piece of text for filenames / URLs.
 */
function pcvc_brochure_slugify(string $text): string
{
    $text = trim($text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? $text;
    $text = trim($text, '-');
    if (function_exists('iconv')) {
        $tr = @iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        if ($tr !== false && $tr !== '') {
            $text = $tr;
        }
    }
    $text = strtolower($text);
    $text = preg_replace('~[^-a-z0-9]+~', '', $text) ?? $text;
    $text = preg_replace('~-+~', '-', $text) ?? $text;

    return $text !== '' ? $text : ('brochure-' . bin2hex(random_bytes(4)));
}

/**
 * Ensure the brochures uploads folder exists and return its absolute path.
 * Delegates to the schema helper so deployment safeguards stay in one place.
 */
function pcvc_brochure_uploads_dir(): string
{
    return pcvc_marketing_brochure_ensure_uploads_dir();
}

/**
 * Build the absolute public share URL for a given brochure slug.
 */
function pcvc_brochure_public_url(string $slug): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . $base . '/brochure-view.php?slug=' . urlencode($slug);
}

switch ($action) {
    // -------------------------------------------------------------
    case 'list_regions':
        $rows = [];
        if ($r = $conn->query('SELECT id, name FROM regions ORDER BY name')) {
            while ($row = $r->fetch_assoc()) {
                $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        pcvc_brochure_respond(['ok' => true, 'regions' => $rows]);
        break;

    // -------------------------------------------------------------
    case 'add_region':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Region name is required.'], 400);
        }
        $stmt = $conn->prepare('SELECT id FROM regions WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            pcvc_brochure_respond([
                'ok'     => true,
                'region' => ['id' => (int) $existing['id'], 'name' => $name],
                'note'   => 'Region already existed; reusing it.',
            ]);
        }
        $ins = $conn->prepare('INSERT INTO regions (name) VALUES (?)');
        $ins->bind_param('s', $name);
        if (!$ins->execute()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Could not create region: ' . $conn->error], 500);
        }
        $newId = (int) $conn->insert_id;
        $ins->close();
        pcvc_brochure_respond([
            'ok'     => true,
            'region' => ['id' => $newId, 'name' => $name],
        ]);
        break;

    // -------------------------------------------------------------
    case 'list_brochures':
        $regionId = isset($_GET['region_id']) ? (int) $_GET['region_id'] : 0;
        $q = trim((string) ($_GET['q'] ?? ''));

        $where  = ['b.is_active = 1'];
        $types  = '';
        $params = [];
        if ($regionId > 0) {
            $where[]  = 'b.region_id = ?';
            $types   .= 'i';
            $params[] = $regionId;
        }
        if ($q !== '') {
            $where[]  = '(b.title LIKE ? OR r.name LIKE ?)';
            $types   .= 'ss';
            $like     = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT b.id, b.title, b.slug, b.description, b.pdf_filename, b.pdf_path,
                       b.pdf_size_bytes, b.attach_pdf, b.extraction_status,
                       b.view_count, b.share_count, b.created_at,
                       b.region_id, r.name AS region_name
                FROM marketing_brochures b
                LEFT JOIN regions r ON r.id = b.region_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY b.created_at DESC
                LIMIT 200';
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res  = $stmt->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $row['id']             = (int) $row['id'];
            $row['region_id']      = (int) ($row['region_id'] ?? 0);
            $row['view_count']     = (int) $row['view_count'];
            $row['share_count']    = (int) $row['share_count'];
            $row['pdf_size_bytes'] = (int) $row['pdf_size_bytes'];
            $row['attach_pdf']     = (int) ($row['attach_pdf'] ?? 1);
            $row['share_url']      = pcvc_brochure_public_url((string) $row['slug']);
            $list[]                = $row;
        }
        $stmt->close();
        pcvc_brochure_respond(['ok' => true, 'brochures' => $list]);
        break;

    // -------------------------------------------------------------
    case 'upload_brochure':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }

        $title       = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $regionId    = isset($_POST['region_id']) ? (int) $_POST['region_id'] : 0;
        $attachPdf   = !empty($_POST['attach_pdf']) ? 1 : 0;

        if ($title === '') {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Title is required.'], 400);
        }
        if ($regionId <= 0) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Please select or create a region.'], 400);
        }
        if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'A PDF file is required.'], 400);
        }

        $f = $_FILES['pdf'];
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Only PDF files are allowed.'], 400);
        }
        if ((int) $f['size'] > 25 * 1024 * 1024) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'PDF must be 25 MB or less.'], 400);
        }

        $stmt = $conn->prepare('SELECT id, name FROM regions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $regionId);
        $stmt->execute();
        $region = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$region) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Selected region no longer exists.'], 400);
        }

        $baseSlug = pcvc_brochure_slugify($title);
        $slug     = $baseSlug;
        $suffix   = 1;
        while (true) {
            $check = $conn->prepare('SELECT id FROM marketing_brochures WHERE slug = ? LIMIT 1');
            $check->bind_param('s', $slug);
            $check->execute();
            $taken = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$taken) {
                break;
            }
            $slug = $baseSlug . '-' . (++$suffix);
        }

        $dir       = pcvc_brochure_uploads_dir();
        $safeName  = $slug . '-' . time() . '.pdf';
        $absPath   = $dir . DIRECTORY_SEPARATOR . $safeName;
        $relPath   = 'uploads/brochures/' . $safeName;

        if (!@move_uploaded_file($f['tmp_name'], $absPath)) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Failed to move uploaded file.'], 500);
        }

        $size = (int) @filesize($absPath);

        // PDF → HTML extraction (best-effort; failure is non-fatal).
        $extract = pcvc_brochure_extract_pdf($absPath);
        $extractedText = (string) ($extract['text'] ?? '');
        $htmlContent   = (string) ($extract['html'] ?? '');
        $extEngine     = (string) ($extract['engine'] ?? 'none');
        $extStatus     = $htmlContent !== '' ? 'ok' : 'failed';

        $sql = 'INSERT INTO marketing_brochures
                  (region_id, title, slug, description, pdf_filename, pdf_path, pdf_size_bytes,
                   attach_pdf, extracted_text, html_content, extraction_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $ins  = $conn->prepare($sql);
        $orig = (string) $f['name'];
        $ins->bind_param(
            'isssssiissssi',
            $regionId, $title, $slug, $description, $orig, $relPath, $size,
            $attachPdf, $extractedText, $htmlContent, $extStatus, $adminId
        );
        if (!$ins->execute()) {
            @unlink($absPath);
            pcvc_brochure_respond(['ok' => false, 'error' => 'Database insert failed: ' . $conn->error], 500);
        }
        $newId = (int) $conn->insert_id;
        $ins->close();

        pcvc_brochure_respond([
            'ok'        => true,
            'brochure'  => [
                'id'                => $newId,
                'title'             => $title,
                'slug'              => $slug,
                'description'       => $description,
                'region_id'         => (int) $region['id'],
                'region_name'       => (string) $region['name'],
                'pdf_filename'      => $orig,
                'pdf_path'          => $relPath,
                'pdf_size_bytes'    => $size,
                'attach_pdf'        => $attachPdf,
                'extraction_status' => $extStatus,
                'extraction_engine' => $extEngine,
                'view_count'        => 0,
                'share_count'       => 0,
                'share_url'         => pcvc_brochure_public_url($slug),
                'created_at'        => date('Y-m-d H:i:s'),
            ],
        ]);
        break;

    // -------------------------------------------------------------
    case 'set_attach_pdf':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }
        $id    = (int) ($_POST['id'] ?? 0);
        $value = !empty($_POST['attach_pdf']) ? 1 : 0;
        if ($id <= 0) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid brochure ID.'], 400);
        }
        $u = $conn->prepare('UPDATE marketing_brochures SET attach_pdf = ? WHERE id = ?');
        $u->bind_param('ii', $value, $id);
        $u->execute();
        $u->close();
        pcvc_brochure_respond(['ok' => true, 'attach_pdf' => $value]);
        break;

    // -------------------------------------------------------------
    case 'regenerate_html':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid brochure ID.'], 400);
        }
        $stmt = $conn->prepare('SELECT pdf_path FROM marketing_brochures WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Brochure not found.'], 404);
        }
        $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['pdf_path']);
        $extract = pcvc_brochure_extract_pdf($abs);
        $status  = !empty($extract['html']) ? 'ok' : 'failed';
        $u = $conn->prepare('UPDATE marketing_brochures
                              SET extracted_text = ?, html_content = ?, extraction_status = ?
                              WHERE id = ?');
        $u->bind_param('sssi', $extract['text'], $extract['html'], $status, $id);
        $u->execute();
        $u->close();
        pcvc_brochure_respond([
            'ok'                => true,
            'extraction_status' => $status,
            'extraction_engine' => $extract['engine'] ?? 'none',
        ]);
        break;

    // -------------------------------------------------------------
    case 'delete_brochure':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid brochure ID.'], 400);
        }
        $stmt = $conn->prepare('SELECT pdf_path FROM marketing_brochures WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Brochure not found.'], 404);
        }
        $del = $conn->prepare('DELETE FROM marketing_brochures WHERE id = ?');
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();
        $full = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['pdf_path']);
        if (is_file($full)) {
            @unlink($full);
        }
        pcvc_brochure_respond(['ok' => true]);
        break;

    // -------------------------------------------------------------
    case 'lookup_phone':
        $phone = trim((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''));
        if ($phone === '') {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Phone is required.'], 400);
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 6) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Phone number is too short.'], 400);
        }
        $shortDigits = ltrim(substr($digits, -10), '0');
        $likeAny     = '%' . $shortDigits . '%';

        // Tables to search across, each declares its phone columns + name columns.
        $sources = [
            [
                'table'   => 'student_applications',
                'phone'   => ['phone_number', 'emergency_phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'malta_applications',
                'phone'   => ['contact_number'],
                'name'    => "TRIM(CONCAT(IFNULL(name,''),' ',IFNULL(surname,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'turkey_applications',
                'phone'   => ['mobile'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'budapest_applications',
                'phone'   => ['phone'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(middle_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'credit_transfer_applications',
                'phone'   => ['phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'master_loan_applications',
                'phone'   => ['phone_number', 'ref_phone'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'georgia_applications',
                'phone'   => ['phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'form_17_applications',
                'phone'   => ['phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'form_20_applications',
                'phone'   => ['phone_number', 'emergency_phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'canada_medical_exams_requests',
                'phone'   => ['phone_number', 'emergency_phone_number'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'job_applications',
                'phone'   => ['telephone'],
                'name'    => "TRIM(CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')))",
                'email'   => 'email',
                'id_col'  => 'id',
            ],
            [
                'table'   => 'contacts',
                'phone'   => ['phone'],
                'name'    => 'name',
                'email'   => 'NULL',
                'id_col'  => 'id',
            ],
        ];

        $matches    = [];
        $seenKeys   = [];
        foreach ($sources as $src) {
            $tableNameSafe = $src['table'];
            $cleanedConds  = [];
            foreach ($src['phone'] as $col) {
                $cleanedConds[] = "REPLACE(REPLACE(REPLACE(REPLACE(IFNULL($col,''),' ',''),'-',''),'(',''),')','') LIKE ?";
            }
            $where = '(' . implode(' OR ', $cleanedConds) . ')';
            $cols  = $src['phone'];
            $coalesce = 'COALESCE(' . implode(',', array_map(fn($c) => "NULLIF($c,'')", $cols)) . ')';
            $sql = "SELECT {$src['id_col']} AS rid,
                           {$src['name']} AS full_name,
                           {$coalesce} AS phone_match,
                           {$src['email']} AS email_match
                    FROM `{$tableNameSafe}`
                    WHERE $where
                    LIMIT 5";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $bindVals = array_fill(0, count($cleanedConds), $likeAny);
            $stmt->bind_param(str_repeat('s', count($bindVals)), ...$bindVals);
            if (!$stmt->execute()) {
                $stmt->close();
                continue;
            }
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $key = strtolower(trim((string) ($row['full_name'] ?? ''))) . '|' . preg_replace('/\D+/', '', (string) ($row['phone_match'] ?? ''));
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;
                $matches[] = [
                    'table'      => $tableNameSafe,
                    'row_id'     => (int) ($row['rid'] ?? 0),
                    'name'       => trim((string) ($row['full_name'] ?? '')),
                    'phone'      => (string) ($row['phone_match'] ?? ''),
                    'email'      => (string) ($row['email_match'] ?? ''),
                ];
            }
            $stmt->close();
        }

        $dcc        = trim(xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE'));
        $defaultCc  = $dcc !== '' ? $dcc : null;
        $waE164     = xander_format_phone_for_whatsapp_e164($phone, $defaultCc);

        pcvc_brochure_respond([
            'ok'             => true,
            'normalized_e164'=> $waE164,
            'whatsapp_url'   => $waE164 ? 'https://wa.me/' . rawurlencode($waE164) : null,
            'matches'        => $matches,
            'count'          => count($matches),
        ]);
        break;

    // -------------------------------------------------------------
    case 'share_brochure':
        if (!pcvc_csrf_validate_post()) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid security token.'], 403);
        }
        $brochureId = (int) ($_POST['brochure_id'] ?? 0);
        $channel    = strtolower(trim((string) ($_POST['channel'] ?? 'copy')));
        $name       = trim((string) ($_POST['name'] ?? ''));
        $phone      = trim((string) ($_POST['phone'] ?? ''));
        $email      = trim((string) ($_POST['email'] ?? ''));
        $notes      = trim((string) ($_POST['notes'] ?? ''));
        $matchTable = trim((string) ($_POST['matched_table'] ?? ''));
        $matchId    = (int) ($_POST['matched_row_id'] ?? 0);
        $isNew      = (int) (!empty($_POST['is_new_contact']) ? 1 : 0);

        if ($brochureId <= 0) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Invalid brochure.'], 400);
        }
        if (!in_array($channel, ['copy', 'whatsapp', 'email', 'sms', 'other'], true)) {
            $channel = 'copy';
        }
        // Quick share from the brochure card (no specific recipient) is allowed:
        // phone/email are only required when a "Send to customer" payload is built.
        $quickShare = ($notes === 'quick-share');
        if (!$quickShare) {
            if ($channel === 'whatsapp' && $phone === '') {
                pcvc_brochure_respond(['ok' => false, 'error' => 'Phone is required for WhatsApp.'], 400);
            }
            if ($channel === 'email' && $email === '') {
                pcvc_brochure_respond(['ok' => false, 'error' => 'Email is required for email channel.'], 400);
            }
        }

        $stmt = $conn->prepare('SELECT id, slug, title FROM marketing_brochures WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $brochureId);
        $stmt->execute();
        $brochure = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$brochure) {
            pcvc_brochure_respond(['ok' => false, 'error' => 'Brochure not found.'], 404);
        }

        $shareUrl = pcvc_brochure_public_url((string) $brochure['slug']);
        $token    = bin2hex(random_bytes(8));

        if ($isNew && $phone !== '') {
            $digits  = preg_replace('/\D+/', '', $phone) ?? '';
            $segment = 'customer';
            $check   = $conn->prepare('SELECT id FROM contacts WHERE phone = ? LIMIT 1');
            $check->bind_param('s', $digits);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                $ins = $conn->prepare('INSERT INTO contacts (name, phone, segment, opted_in, status) VALUES (?, ?, ?, 1, "active")');
                $cleanName = $name !== '' ? $name : 'Brochure Contact';
                $ins->bind_param('sss', $cleanName, $digits, $segment);
                @$ins->execute();
                $ins->close();
            }
            $check->close();
        }

        $sql = 'INSERT INTO marketing_brochure_shares
                  (brochure_id, share_token, recipient_name, recipient_phone, recipient_email,
                   channel, matched_table, matched_row_id, is_new_contact, shared_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $ins  = $conn->prepare($sql);
        $ins->bind_param(
            'issssssiiis',
            $brochureId,
            $token,
            $name,
            $phone,
            $email,
            $channel,
            $matchTable,
            $matchId,
            $isNew,
            $adminId,
            $notes
        );
        $ins->execute();
        $shareId = (int) $conn->insert_id;
        $ins->close();

        $conn->query("UPDATE marketing_brochures SET share_count = share_count + 1 WHERE id = $brochureId");

        $dcc       = trim(xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE'));
        $defaultCc = $dcc !== '' ? $dcc : null;
        $waE164    = $phone !== '' ? xander_format_phone_for_whatsapp_e164($phone, $defaultCc) : null;

        $msgLines  = [];
        $greeting  = $name !== '' ? ('Hello ' . $name . ',') : 'Hello,';
        $msgLines[] = $greeting;
        $msgLines[] = '';
        $msgLines[] = 'Please find our brochure: ' . (string) $brochure['title'];
        $msgLines[] = $shareUrl;
        $msgLines[] = '';
        $msgLines[] = 'Reach out any time if you have questions.';
        $msgLines[] = '— Parrot Canada Visa Consultant';
        $waMessage  = implode("\n", $msgLines);

        $whatsappLink = $waE164 ? ('https://wa.me/' . rawurlencode($waE164) . '?text=' . rawurlencode($waMessage)) : null;
        $emailLink    = $email !== ''
            ? 'mailto:' . rawurlencode($email)
                . '?subject=' . rawurlencode((string) $brochure['title'])
                . '&body=' . rawurlencode($waMessage)
            : null;

        pcvc_brochure_respond([
            'ok'            => true,
            'share_id'      => $shareId,
            'share_url'     => $shareUrl,
            'whatsapp_url'  => $whatsappLink,
            'email_url'     => $emailLink,
            'message'       => $waMessage,
        ]);
        break;

    // -------------------------------------------------------------
    default:
        pcvc_brochure_respond(['ok' => false, 'error' => 'Unknown action.'], 400);
}

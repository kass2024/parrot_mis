<?php
declare(strict_types=1);

/**
 * ============================================================
 * STAFF WARNING LETTER — helper
 * ------------------------------------------------------------
 *  - Auto schema for `staff_warning_letters`
 *  - PDF generator with header.png + footer.png from /cards
 *  - Email (PHPMailer via app_mailer()) and WhatsApp (Cloud API
 *    template + document) delivery.
 * ============================================================
 */

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php'; // xander_whatsapp_* helpers
require_once __DIR__ . '/marketing_brochure_send.php'; // pcvc_brochure_wa_sanitize_param

use Dompdf\Dompdf;
use Dompdf\Options;

/* ============================================================
   SCHEMA BOOTSTRAP
============================================================ */
function pcvc_swl_ensure_schema(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS staff_warning_letters (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id        INT UNSIGNED NOT NULL,
            staff_name      VARCHAR(255) DEFAULT NULL,
            staff_email     VARCHAR(255) DEFAULT NULL,
            staff_phone     VARCHAR(64)  DEFAULT NULL,
            subject         VARCHAR(255) NOT NULL,
            content_html    MEDIUMTEXT   NOT NULL,
            pdf_path        VARCHAR(500) DEFAULT NULL,
            email_sent      TINYINT(1)   NOT NULL DEFAULT 0,
            email_error     VARCHAR(500) DEFAULT NULL,
            whatsapp_sent   TINYINT(1)   NOT NULL DEFAULT 0,
            whatsapp_method VARCHAR(32)  DEFAULT NULL,
            whatsapp_error  VARCHAR(500) DEFAULT NULL,
            created_by      INT UNSIGNED DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_id (staff_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    @$conn->query($sql);
}

/* ============================================================
   PUBLIC URL FOR THIS APP (used to make PDF link for WhatsApp)
============================================================ */
function pcvc_swl_public_base_url(): string
{
    $env = trim((string) (function_exists('xander_env_get') ? xander_env_get('APP_PUBLIC_URL') : ''));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    // Strip /api or /helpers tail if present (we want the app root)
    $dir    = preg_replace('#/(api|helpers)$#', '', $dir);
    return rtrim($scheme . '://' . $host . $dir, '/');
}

/* ============================================================
   LOAD STAFF ROW
============================================================ */
function pcvc_swl_load_staff(mysqli $conn, int $staffId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, full_name, first_name, last_name, email, phone_number, position
        FROM admins WHERE id = ? LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ============================================================
   IMG → DATA URI (so Dompdf inlines images, no remote fetch)
============================================================ */
function pcvc_swl_img_data_uri(string $absPath): string
{
    if (!is_file($absPath)) return '';
    $bin = @file_get_contents($absPath);
    if ($bin === false) return '';
    $mime = 'image/png';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($absPath);
        if ($detected) $mime = $detected;
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/* ============================================================
   BUILD HTML FOR PDF (letterhead + footer)
============================================================ */
function pcvc_swl_build_html(array $staff, string $subject, string $contentHtml, string $referenceCode): string
{
    $headerUri = pcvc_swl_img_data_uri(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'header.png');
    $footerUri = pcvc_swl_img_data_uri(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'footer.png');
    $name      = htmlspecialchars(trim(($staff['full_name'] ?? '') ?: trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
    $position  = htmlspecialchars((string) ($staff['position'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email     = htmlspecialchars((string) ($staff['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subjectH  = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $refH      = htmlspecialchars($referenceCode, ENT_QUOTES, 'UTF-8');
    $issuedOn  = date('F j, Y');

    // Allow only safe HTML the editor produces
    $bodyHtml  = $contentHtml;

    $css = <<<'CSS'
        @page { margin: 165px 35px 130px 35px; size: A4 portrait; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11.5pt; color: #1f2937; line-height: 1.55; }
        header { position: fixed; top: -150px; left: 0; right: 0; height: 130px; text-align: center; }
        header img { width: 100%; max-height: 130px; }
        footer { position: fixed; bottom: -110px; left: 0; right: 0; height: 100px; text-align: center; }
        footer img { width: 100%; max-height: 100px; }
        .stamp { position: fixed; top: 0; right: 0; font-size: 9pt; color: #6b7280; }
        .meta { margin-bottom: 24px; }
        .meta-row { margin: 2px 0; }
        .meta-label { color: #6b7280; }
        .ref { float: right; color: #6b7280; font-size: 10pt; }
        h1.title {
            font-size: 16pt; margin: 16px 0 18px 0; color: #b91c1c; text-transform: uppercase;
            letter-spacing: 0.04em; text-align: center; border-bottom: 2px solid #b91c1c; padding-bottom: 8px;
        }
        .recipient { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; margin-bottom:18px; }
        .recipient .name { font-weight: 700; }
        .body { text-align: justify; }
        .body p { margin: 0 0 10px; }
        .body strong { color: #111827; }
        .signature-block { margin-top: 40px; }
        .signature-line { width: 220px; border-bottom: 1px solid #111; margin-top: 36px; padding-top: 4px; }
        .small { font-size: 9.5pt; color: #6b7280; }
    CSS;

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style><?= $css ?></style></head>
<body>

<?php if ($headerUri): ?>
<header><img src="<?= $headerUri ?>" alt="Letterhead"></header>
<?php endif; ?>

<?php if ($footerUri): ?>
<footer><img src="<?= $footerUri ?>" alt="Footer"></footer>
<?php endif; ?>

<div class="meta">
    <span class="ref">Ref: <?= $refH ?></span>
    <div class="meta-row"><span class="meta-label">Date:</span> <?= $issuedOn ?></div>
</div>

<h1 class="title">Warning Letter</h1>

<div class="recipient">
    <div class="meta-row"><span class="meta-label">To:</span> <span class="name"><?= $name ?: '[Staff]' ?></span></div>
    <?php if ($position !== ''): ?>
    <div class="meta-row"><span class="meta-label">Position:</span> <?= $position ?></div>
    <?php endif; ?>
    <?php if ($email !== ''): ?>
    <div class="meta-row"><span class="meta-label">Email:</span> <?= $email ?></div>
    <?php endif; ?>
    <div class="meta-row"><span class="meta-label">Subject:</span> <strong><?= $subjectH ?></strong></div>
</div>

<div class="body">
    <?= $bodyHtml ?>
</div>

<div class="signature-block">
    <p>Yours sincerely,</p>
    <div class="signature-line"><strong>Prof. Marc-Logan</strong></div>
    <p class="small">Company Advisor and Shareholder<br>Parrot Canada Visa Consultant Co. Ltd.</p>
</div>

</body>
</html>
    <?php
    return (string) ob_get_clean();
}

/* ============================================================
   RENDER PDF — returns ['path' => relative, 'abs' => absolute, 'filename' => …]
============================================================ */
function pcvc_swl_render_pdf(string $html, int $staffId, string $referenceCode): array
{
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'warnings';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $safeRef  = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $referenceCode) ?: 'WL';
    $filename = 'warning_' . $staffId . '_' . $safeRef . '.pdf';
    $absPath  = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    $relPath  = 'uploads/warnings/' . $filename;

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $options = new Options([
        'isRemoteEnabled' => true,
        'isHtml5ParserEnabled' => true,
        'defaultFont' => 'DejaVu Sans',
    ]);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($absPath, $dompdf->output());

    return ['abs' => $absPath, 'path' => $relPath, 'filename' => $filename];
}

/* ============================================================
   EMAIL WITH PDF ATTACHMENT
============================================================ */
function pcvc_swl_send_email(string $toEmail, string $toName, string $subject, string $contentHtml, string $pdfAbsPath, string $pdfFilename): array
{
    try {
        require_once __DIR__ . '/mailer.php';
        $mail = app_mailer();
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->Subject = 'Warning Letter — ' . $subject;
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeSubj = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

        $mail->Body = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:640px;margin:0 auto;color:#1e293b;line-height:1.6;">'
            . '<p>Dear ' . ($safeName ?: 'Colleague') . ',</p>'
            . '<p>Please find attached an official warning letter regarding: <strong>' . $safeSubj . '</strong>.</p>'
            . '<p>Kindly read the attached letter carefully. If you have any questions, you may reach out directly.</p>'
            . '<p>Regards,<br><strong>Prof. Marc-Logan</strong><br>Company Advisor and Shareholder<br>Parrot Canada Visa Consultant Co. Ltd.</p>'
            . '</div>';
        $mail->AltBody = "Dear {$toName},\n\nAttached is an official warning letter regarding: {$subject}.\n\nProf. Marc-Logan\nCompany Advisor and Shareholder\nParrot Canada Visa Consultant Co. Ltd.";

        if (is_file($pdfAbsPath)) {
            $mail->addAttachment($pdfAbsPath, $pdfFilename);
        }

        $mail->send();
        return ['sent' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('[warning_letter] email: ' . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

/* ============================================================
   WHATSAPP — template + document
   - First send approved template (intro)
   - Then send the PDF as a document (requires public URL)
============================================================ */
function pcvc_swl_send_whatsapp(string $phoneRaw, string $staffName, string $subject, string $pdfPublicUrl, string $referenceCode): array
{
    $token   = trim((string) xander_env_get('WHATSAPP_ACCESS_TOKEN'));
    if ($token === '') $token = trim((string) xander_env_get('WHATSAPP_TOKEN'));
    $phoneId = trim((string) xander_env_get('WHATSAPP_PHONE_NUMBER_ID'));

    $result = ['sent' => false, 'method' => '', 'error' => '', 'not_on_whatsapp' => false];
    if ($token === '' || $phoneId === '') {
        $result['error'] = 'WhatsApp is not configured (token/phone-id missing).';
        return $result;
    }

    $dcc       = trim((string) xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE'));
    $defaultCc = $dcc !== '' ? $dcc : null;
    $to        = xander_format_phone_for_whatsapp_e164($phoneRaw, $defaultCc);
    if ($to === null || $to === '') {
        $result['error'] = 'Staff phone number is missing or invalid.';
        return $result;
    }

    $version = trim((string) xander_env_get('META_GRAPH_VERSION')) ?: 'v19.0';
    $url     = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneId) . '/messages';

    $tplName = trim((string) xander_env_get('WHATSAPP_WARNING_LETTER_TEMPLATE_NAME')) ?: 'pcvc_warning_letter';
    $tplLang = trim((string) xander_env_get('WHATSAPP_WARNING_LETTER_TEMPLATE_LANG')) ?: 'en';

    $bodyTexts = [
        pcvc_brochure_wa_sanitize_param($staffName ?: 'Colleague'),
        pcvc_brochure_wa_sanitize_param($subject),
        pcvc_brochure_wa_sanitize_param($referenceCode),
    ];

    /* ---------- 1) try approved template (intro) ---------- */
    $sessionBody = "Hello {$staffName},\n\nPlease find the official warning letter regarding: {$subject}.\nReference: {$referenceCode}\n\nKind regards,\nProf. Marc-Logan\nCompany Advisor and Shareholder\nParrot Canada Visa Consultant Co. Ltd.";
    $tpl = xander_whatsapp_send_template_or_session($to, $url, $token, $tplName, $tplLang, 3, $bodyTexts, $sessionBody);

    $tplSent = (bool) ($tpl['sent'] ?? false);
    $tplMethod = (string) ($tpl['method'] ?? '');
    $tplErr  = (string) ($tpl['error']  ?? '');
    $tplDet  = (string) ($tpl['detail'] ?? '');

    // Detect "not on WhatsApp"
    $notOnWa = false;
    foreach (['131026', '131045', '131051', '131047'] as $c) {
        if (strpos($tplDet, $c) !== false) { $notOnWa = true; break; }
    }
    if (!$notOnWa && stripos($tplErr . ' ' . $tplDet, 'not on WhatsApp') !== false) $notOnWa = true;
    if ($notOnWa) {
        $result['error'] = 'This number is not on WhatsApp — message not delivered.';
        $result['not_on_whatsapp'] = true;
        return $result;
    }

    if (!$tplSent) {
        $result['error']  = $tplErr ?: 'WhatsApp template failed.';
        $result['method'] = $tplMethod ?: 'template';
        return $result;
    }

    /* ---------- 2) send PDF document (public URL required) ---------- */
    if ($pdfPublicUrl !== '') {
        $docPayload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'document',
            'document'          => [
                'link'     => $pdfPublicUrl,
                'filename' => 'Warning_Letter_' . $referenceCode . '.pdf',
                'caption'  => 'Warning Letter — ' . $subject,
            ],
        ];
        $res2 = xander_whatsapp_graph_post($url, $token, $docPayload);
        error_log('[warning_letter] wa doc HTTP ' . $res2['http'] . ' body: ' . $res2['body']);
        $docSent = ($res2['http'] >= 200 && $res2['http'] < 300 && xander_whatsapp_response_has_message_id($res2['json']));

        // If template went through but doc failed, we still consider the WhatsApp leg "sent" (intro delivered)
        $result['sent']   = true;
        $result['method'] = $docSent ? 'template+document' : 'template_only';
        if (!$docSent) {
            $err = xander_whatsapp_extract_error($res2['json']);
            $result['error'] = 'PDF attachment failed' . ($err ? (': ' . ($err['message'] ?? 'unknown')) : '.');
        }
        return $result;
    }

    $result['sent']   = true;
    $result['method'] = 'template';
    return $result;
}

/* ============================================================
   PERSIST RECORD
============================================================ */
function pcvc_swl_save_record(mysqli $conn, array $r): int
{
    pcvc_swl_ensure_schema($conn);
    $stmt = $conn->prepare("
        INSERT INTO staff_warning_letters
            (staff_id, staff_name, staff_email, staff_phone,
             subject, content_html, pdf_path,
             email_sent, email_error, whatsapp_sent, whatsapp_method, whatsapp_error,
             created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
    ");
    if (!$stmt) return 0;
    $staffId   = (int) $r['staff_id'];
    $staffName = (string) ($r['staff_name'] ?? '');
    $staffMail = (string) ($r['staff_email'] ?? '');
    $staffPhone= (string) ($r['staff_phone'] ?? '');
    $subj      = (string) ($r['subject'] ?? '');
    $html      = (string) ($r['content_html'] ?? '');
    $pdfPath   = (string) ($r['pdf_path'] ?? '');
    $emSent    = !empty($r['email_sent']) ? 1 : 0;
    $emErr     = (string) ($r['email_error'] ?? '');
    $waSent    = !empty($r['whatsapp_sent']) ? 1 : 0;
    $waMethod  = (string) ($r['whatsapp_method'] ?? '');
    $waErr     = (string) ($r['whatsapp_error'] ?? '');
    $createdBy = (int) ($r['created_by'] ?? 0);

    // Types: i,s,s,s,s,s,s,i,s,i,s,s,i  (13 params)
    $stmt->bind_param(
        'issssssisissi',
        $staffId, $staffName, $staffMail, $staffPhone,
        $subj, $html, $pdfPath,
        $emSent, $emErr, $waSent, $waMethod, $waErr,
        $createdBy
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

/* ============================================================
   REFERENCE CODE
============================================================ */
function pcvc_swl_make_reference(int $staffId): string
{
    return 'WL-' . date('Ymd') . '-' . $staffId . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

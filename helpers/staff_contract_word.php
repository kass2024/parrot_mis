<?php
declare(strict_types=1);

if (!defined('PHP_OS_FAMILY')) {
    define('PHP_OS_FAMILY', DIRECTORY_SEPARATOR === '\\' ? 'Windows' : 'Linux');
}

require_once __DIR__ . '/staff_contract_schema.php';
require_once __DIR__ . '/../includes/company_branding.php';

function pcvc_staff_contract_require_autoload(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $pcvcAutoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($pcvcAutoload)) {
        require_once $pcvcAutoload;
    }
    $loaded = true;
}

function pcvc_staff_contract_require_pdf_helpers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    pcvc_staff_contract_require_autoload();
    require_once __DIR__ . '/staff_contract_pdf.php';
    require_once __DIR__ . '/contract_signature_image.php';
    $loaded = true;
}

/**
 * Whether LibreOffice/soffice is available for server-side DOCX→PDF.
 */
function pcvc_staff_contract_libreoffice_available(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        foreach ([
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ] as $bin) {
            if (is_file($bin)) {
                $cached = true;
                return true;
            }
        }
    }

    foreach (['/usr/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/libreoffice'] as $bin) {
        if (is_file($bin)) {
            $cached = true;
            return true;
        }
    }

    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    if (!in_array('exec', $disabled, true)) {
        foreach (['libreoffice', 'soffice'] as $cmd) {
            @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $cached = true;
                return true;
            }
            $out = [];
        }
    }

    $cached = false;
    return false;
}

/**
 * Shared hosting (e.g. Namecheap): render filled Word in the browser instead of server PDF.
 */
function pcvc_staff_contract_use_docx_preview(): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        return false;
    }
    return !pcvc_staff_contract_libreoffice_available();
}

/**
 * Build inline Word drawing XML for an embedded PNG.
 */
function pcvc_staff_contract_inline_image_xml(string $rid, string $label, int $cx, int $cy): string
{
    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<wp:docPr id="' . (9000 + crc32($label) % 1000) . '" name="' . htmlspecialchars($label, ENT_XML1) . '"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="0" name="' . htmlspecialchars($label, ENT_XML1) . '"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/**
 * Replace the single w:r run that contains a ${placeholder} token (safe — no cross-document regex).
 */
function pcvc_staff_contract_replace_placeholder_run_in_xml(string $xml, string $placeholderKey, string $replacementXml): string
{
    $token = '${' . $placeholderKey . '}';
    $needle = '<w:t>' . $token . '</w:t>';
    $pos = strpos($xml, $needle);
    if ($pos === false) {
        $pos = strpos($xml, $token);
        if ($pos === false) {
            return $xml;
        }
        return substr($xml, 0, $pos) . $replacementXml . substr($xml, $pos + strlen($token));
    }

    $before = substr($xml, 0, $pos);
    $rStart = strrpos($before, '<w:r');
    $rEnd = strpos($xml, '</w:r>', $pos);
    if ($rStart === false || $rEnd === false) {
        return str_replace($needle, $replacementXml, $xml);
    }
    $rEnd += strlen('</w:r>');

    return substr($xml, 0, $rStart) . $replacementXml . substr($xml, $rEnd);
}

/**
 * Replace ${placeholder_key} in document.xml with an embedded PNG image.
 */
function pcvc_staff_contract_embed_image_at_placeholder(
    string $docxAbs,
    string $placeholderKey,
    string $mediaFileName,
    string $pngBytes,
    int $widthEmu = 1371600,
    int $heightEmu = 457200
): void {
    if ($pngBytes === '') {
        throw new RuntimeException('Signature image is empty.');
    }

    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) {
        throw new RuntimeException('Could not open contract document for image embed.');
    }

    $mediaPath = 'word/media/' . $mediaFileName;
    if ($zip->locateName($mediaPath) !== false) {
        $zip->deleteName($mediaPath);
    }
    $zip->addFromString($mediaPath, $pngBytes);

    $relsPath = 'word/_rels/document.xml.rels';
    $rels = (string) $zip->getFromName($relsPath);
    if ($rels === '') {
        $zip->close();
        throw new RuntimeException('Contract relationships file missing.');
    }

    $target = 'media/' . $mediaFileName;
    $newRid = '';
    if (preg_match('/Id="(rId\d+)"[^>]+Target="' . preg_quote($target, '/') . '"/', $rels, $existing)) {
        $newRid = $existing[1];
    } else {
        $nextId = 1;
        if (preg_match_all('/Id="rId(\d+)"/', $rels, $matches)) {
            $nextId = max(array_map('intval', $matches[1])) + 1;
        }
        $newRid = 'rId' . $nextId;
        $rels = str_replace(
            '</Relationships>',
            '<Relationship Id="' . $newRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . $target . '"/></Relationships>',
            $rels
        );
        $zip->deleteName($relsPath);
        $zip->addFromString($relsPath, $rels);
    }

    $xml = (string) $zip->getFromName('word/document.xml');
    if ($xml === '') {
        $zip->close();
        throw new RuntimeException('Contract document body missing.');
    }

    $drawing = pcvc_staff_contract_inline_image_xml(
        $newRid,
        ucfirst(str_replace('_', ' ', $placeholderKey)),
        $widthEmu,
        $heightEmu
    );
    $updated = pcvc_staff_contract_replace_placeholder_run_in_xml($xml, $placeholderKey, $drawing);

    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $updated);
    $zip->close();
}

/**
 * Remove a text placeholder line (e.g. employee signature before signing).
 */
function pcvc_staff_contract_clear_text_placeholder(string $docxAbs, string $placeholderKey): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) {
        return;
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    if ($xml === '') {
        $zip->close();
        return;
    }
    $blank = '<w:r><w:t xml:space="preserve"> </w:t></w:r>';
    $updated = pcvc_staff_contract_replace_placeholder_run_in_xml($xml, $placeholderKey, $blank);
    if ($updated !== $xml) {
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $updated);
    }
    $zip->close();
}

function pcvc_staff_contract_manager_signature_bytes(): ?string
{
    $path = pcvc_staff_contract_manager_signature_path();
    if (!is_file($path)) {
        return null;
    }
    $bytes = file_get_contents($path);
    return $bytes !== false && $bytes !== '' ? $bytes : null;
}

/**
 * Embed employer signature image; embed or clear employee signature placeholder.
 */
function pcvc_staff_contract_apply_signature_images(string $docxAbs, ?string $employeeSignatureDataUrl = null): void
{
    $employerPng = pcvc_staff_contract_manager_signature_bytes();
    if ($employerPng !== null) {
        pcvc_staff_contract_embed_image_at_placeholder(
            $docxAbs,
            'employer_signature',
            'employer_signature.png',
            $employerPng,
            1371600,
            457200
        );
    }

    if ($employeeSignatureDataUrl !== null && $employeeSignatureDataUrl !== '') {
        if (!function_exists('contract_signature_to_display_png')) {
            require_once __DIR__ . '/contract_signature_image.php';
        }
        $sigPng = contract_signature_to_display_png($employeeSignatureDataUrl);
        if ($sigPng === null) {
            $sigPng = contract_signature_raw_bytes($employeeSignatureDataUrl);
        }
        if ($sigPng !== null && $sigPng !== '') {
            pcvc_staff_contract_embed_image_at_placeholder(
                $docxAbs,
                'employee_signature',
                'employee_signature.png',
                $sigPng,
                1371600,
                457200
            );
            return;
        }
    }

    pcvc_staff_contract_clear_text_placeholder($docxAbs, 'employee_signature');
}

/**
 * @deprecated Use pcvc_staff_contract_embed_image_at_placeholder()
 */
function pcvc_staff_contract_embed_signature_in_docx(string $docxAbs, string $pngBytes): void
{
    pcvc_staff_contract_embed_image_at_placeholder(
        $docxAbs,
        'employee_signature',
        'employee_signature.png',
        $pngBytes,
        1371600,
        457200
    );
}

/**
 * Placeholders supported in Word templates (${placeholder_name}).
 *
 * @return list<string>
 */
function pcvc_staff_contract_placeholder_keys(): array
{
    return [
        'full_name', 'first_name', 'last_name', 'email', 'phone_number', 'username',
        'role', 'position', 'employment_type', 'employment_start_date',
        'national_id', 'date_of_birth', 'marital_status', 'nationality',
        'place_of_birth', 'address', 'monthly_salary', 'salary_currency',
        'company_name', 'signing_date', 'probation_end_date',
        'employer_name', 'employer_position', 'employer_date', 'employer_signature',
        'employee_signature',
    ];
}

function pcvc_staff_contract_employer_defaults(): array
{
    return [
        'name' => 'TWAJAMAHORO JEAN PIERRE',
        'position' => 'Managing director',
    ];
}

function pcvc_staff_contract_manager_signature_path(): string
{
    return dirname(__DIR__) . '/admin/signature-manager.png';
}

function pcvc_staff_contract_format_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return date('F j, Y', strtotime($value));
    }
    return $value;
}

/**
 * Resolve job title for contract merge (admins.position).
 */
function pcvc_staff_contract_resolve_position(array $admin): string
{
    return trim((string) ($admin['position'] ?? ''));
}

/**
 * Agreement reference suffix (PCVC-PROB-… in Word templates).
 */
function pcvc_staff_contract_agreement_reference(array $admin): string
{
    $nationalId = trim((string) ($admin['national_id'] ?? ''));
    if ($nationalId !== '') {
        return $nationalId;
    }
    $adminId = (int) ($admin['id'] ?? 0);
    return $adminId > 0 ? (string) $adminId : '';
}

function pcvc_staff_contract_canonical_template_path(): string
{
    return dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
}

/**
 * @return array{has_media:bool, media_count:int, num_pr_count:int}
 */
function pcvc_staff_contract_docx_stats(string $docxAbs): array
{
    $stats = ['has_media' => false, 'media_count' => 0, 'num_pr_count' => 0];
    if (!is_file($docxAbs)) {
        return $stats;
    }
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) {
        return $stats;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (strpos($name, 'word/media/') === 0) {
            $stats['has_media'] = true;
            $stats['media_count']++;
        }
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    if ($xml !== '') {
        $stats['num_pr_count'] = substr_count($xml, 'w:numPr');
    }
    $zip->close();
    return $stats;
}

/**
 * Pick the template used for merge (canonical Parrot file when upload lacks stamp/images).
 */
function pcvc_staff_contract_resolve_template_path(string $docxAbs): string
{
    $stats = pcvc_staff_contract_docx_stats($docxAbs);
    if ($stats['has_media']) {
        return $docxAbs;
    }

    $canonical = pcvc_staff_contract_canonical_template_path();
    return is_file($canonical) ? $canonical : $docxAbs;
}

/**
 * Warn when upload is a stripped export (stamp applied at merge time via canonical template).
 */
function pcvc_staff_contract_ensure_rich_template(string $docxAbs): string
{
    $stats = pcvc_staff_contract_docx_stats($docxAbs);
    if ($stats['has_media']) {
        return '';
    }

    $canonical = pcvc_staff_contract_canonical_template_path();
    if (!is_file($canonical)) {
        return 'Uploaded contract has no embedded images (company stamp may be missing). '
            . 'Save the contract from Word as .docx with images included, or add '
            . 'admin/Parrot Contract for Mutware.docx on the server.';
    }

    return 'Uploaded file had no company stamp/images. The standard Parrot contract template will be used when filling placeholders.';
}

/**
 * Extract visible text from a Word XML fragment (w:t nodes only).
 */
function pcvc_staff_contract_xml_fragment_text(string $fragment): string
{
    if (!preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/', $fragment, $matches)) {
        return '';
    }
    return implode('', $matches[1]);
}

/**
 * Strip spell-check tags only (safe on large documents).
 */
function pcvc_staff_contract_strip_proof_err(string $xml): string
{
    return preg_replace('/<w:proofErr[^>]*\/>/', '', $xml) ?? $xml;
}

/**
 * Replace ${placeholder} values in DOCX XML without destroying list formatting.
 *
 * @param array<string, string> $values
 * @param list<string> $imageKeys
 */
function pcvc_staff_contract_apply_placeholder_values(string $xml, array $values, array $imageKeys): string
{
    $xml = pcvc_staff_contract_strip_proof_err($xml);

    foreach ($values as $key => $value) {
        if (in_array($key, $imageKeys, true)) {
            continue;
        }
        $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = str_replace('${' . $key . '}', $safe, $xml);
    }

    if (strpos($xml, '${') !== false) {
        $splitKeys = [
            'employer_name', 'employer_position', 'full_name', 'first_name', 'last_name',
            'employment_start_date', 'probation_end_date', 'national_id', 'company_name',
            'signing_date', 'position', 'monthly_salary', 'email', 'phone_number',
        ];
        foreach ($splitKeys as $key) {
            if (!isset($values[$key]) || in_array($key, $imageKeys, true)) {
                continue;
            }
            if (strpos($xml, '${' . $key . '}') !== false) {
                continue;
            }
            $safe = htmlspecialchars((string) $values[$key], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $next = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
            if ($next !== $xml) {
                $xml = $next;
            }
        }
    }

    return $xml;
}

/**
 * Replace placeholders Word split across runs without collapsing XML structure.
 */
function pcvc_staff_contract_replace_split_placeholder(string $xml, string $key, string $safe): string
{
    if (strpos($xml, '${' . $key . '}') !== false) {
        return $xml;
    }

    $keyWt = '/<w:t(?:\s[^>]*)?>' . preg_quote($key, '/') . '<\/w:t>/';
    if (!preg_match($keyWt, $xml, $match, PREG_OFFSET_CAPTURE)) {
        return $xml;
    }

    $keyPos = (int) $match[0][1];
    $before = substr($xml, max(0, $keyPos - 3000), $keyPos - max(0, $keyPos - 3000));
    if (strpos($before, '${') === false) {
        return $xml;
    }

    $safeXml = htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // "Name: ${" -> "Name: "
    $xml = preg_replace(
        '/<w:t(?:\s[^>]*)?>([^<]*)\$\{<\/w:t>/',
        '<w:t xml:space="preserve">$1</w:t>',
        $xml,
        1
    ) ?? $xml;

    // Lone "${" run
    $xml = preg_replace(
        '/<w:t(?:\s[^>]*)?>\$\{<\/w:t>/',
        '',
        $xml,
        1
    ) ?? $xml;

    // Key run -> value
    $xml = preg_replace(
        $keyWt,
        '<w:t xml:space="preserve">' . $safeXml . '</w:t>',
        $xml,
        1
    ) ?? $xml;

    // Lone "}" run
    $xml = preg_replace(
        '/<w:t(?:\s[^>]*)?>\}<\/w:t>/',
        '',
        $xml,
        1
    ) ?? $xml;

    return $xml;
}

/**
 * Copy template to a temp file (no heavy XML rewriting).
 */
function pcvc_staff_contract_prepare_template(string $templateAbs): string
{
    pcvc_staff_contract_ensure_dirs();
    $work = pcvc_staff_contract_upload_dir() . '/tmp_tpl_' . bin2hex(random_bytes(8)) . '.docx';
    if (!copy($templateAbs, $work)) {
        throw new RuntimeException('Could not copy contract template.');
    }
    return $work;
}

/**
 * @param array<string, mixed> $admin
 * @return array<string, string>
 */
function pcvc_staff_contract_merge_values(
    array $admin,
    ?string $signingDate = null,
    ?string $signatureDataUrl = null
): array {
    $signingDate = trim((string) ($signingDate ?? date('Y-m-d')));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $signingDate)) {
        $signingDateDisplay = date('F j, Y', strtotime($signingDate));
    } else {
        $signingDateDisplay = $signingDate;
    }

    $monthly = $admin['monthly_salary'] ?? '';
    $monthlyDisplay = ($monthly !== '' && $monthly !== null) ? number_format((float) $monthly, 2) : '';

    $startDateRaw = trim((string) ($admin['employment_start_date'] ?? ''));
    $startDate = pcvc_staff_contract_format_date($startDateRaw);
    $probationEnd = '';
    if ($startDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateRaw)) {
        $probationEnd = date('F j, Y', strtotime($startDateRaw . ' +3 months'));
    }

    $employer = pcvc_staff_contract_employer_defaults();

    return [
        'full_name' => trim((string) ($admin['full_name'] ?? '')),
        'first_name' => trim((string) ($admin['first_name'] ?? '')),
        'last_name' => trim((string) ($admin['last_name'] ?? '')),
        'email' => trim((string) ($admin['email'] ?? '')),
        'phone_number' => trim((string) ($admin['phone_number'] ?? '')),
        'username' => trim((string) ($admin['username'] ?? '')),
        'role' => trim((string) ($admin['role'] ?? '')),
        'position' => pcvc_staff_contract_resolve_position($admin),
        'employment_type' => trim((string) ($admin['employment_type'] ?? '')),
        'employment_start_date' => $startDate,
        'probation_end_date' => $probationEnd,
        'national_id' => pcvc_staff_contract_agreement_reference($admin) !== ''
            ? pcvc_staff_contract_agreement_reference($admin)
            : trim((string) ($admin['national_id'] ?? '')),
        'date_of_birth' => pcvc_staff_contract_format_date((string) ($admin['date_of_birth'] ?? '')),
        'marital_status' => trim((string) ($admin['marital_status'] ?? '')),
        'nationality' => trim((string) ($admin['nationality'] ?? '')),
        'place_of_birth' => trim((string) ($admin['place_of_birth'] ?? '')),
        'address' => trim((string) ($admin['address'] ?? '')),
        'monthly_salary' => $monthlyDisplay,
        'salary_currency' => trim((string) ($admin['salary_currency'] ?? '')),
        'company_name' => PCVC_COMPANY_DISPLAY_NAME,
        'signing_date' => $signingDateDisplay,
        'employer_name' => $employer['name'],
        'employer_position' => $employer['position'],
        'employer_date' => date('F j, Y'),
        'employer_signature' => '',
        'employee_signature' => '',
    ];
}

/**
 * Fill text placeholders by editing DOCX XML directly (preserves bullets, stamp, layout).
 *
 * @param array<string, string> $values
 */
function pcvc_staff_contract_fill_docx_text(string $docxAbs, array $values): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) {
        throw new RuntimeException('Could not open contract document for text merge.');
    }

    $imageKeys = ['employer_signature', 'employee_signature'];
    $parts = ['word/document.xml'];

    foreach ($parts as $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false || $xml === '') {
            continue;
        }
        $xml = pcvc_staff_contract_apply_placeholder_values($xml, $values, $imageKeys);
        $zip->deleteName($name);
        $zip->addFromString($name, $xml);
        unset($xml);
    }

    $zip->close();
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

/**
 * @param array<string, mixed> $admin
 */
function pcvc_staff_contract_fill_docx(
    string $templateAbs,
    string $outputDocxAbs,
    array $admin,
    ?string $signingDate = null,
    ?string $signatureDataUrl = null
): void {
    if (!is_file($templateAbs)) {
        throw new RuntimeException('Contract Word template not found.');
    }

    pcvc_staff_contract_ensure_dirs();
    $outDir = dirname($outputDocxAbs);
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException('Could not create generated contract directory.');
    }

    $effectiveTemplate = pcvc_staff_contract_resolve_template_path($templateAbs);
    $preparedTemplate = pcvc_staff_contract_prepare_template($effectiveTemplate);
    try {
        if (!copy($preparedTemplate, $outputDocxAbs)) {
            throw new RuntimeException('Could not copy prepared contract template.');
        }

        $values = pcvc_staff_contract_merge_values($admin, $signingDate, $signatureDataUrl);
        pcvc_staff_contract_fill_docx_text($outputDocxAbs, $values);
        pcvc_staff_contract_apply_signature_images($outputDocxAbs, $signatureDataUrl);
    } finally {
        if (is_file($preparedTemplate)) {
            @unlink($preparedTemplate);
        }
    }
}

function pcvc_staff_contract_docx_to_pdf(string $docxAbs, string $pdfAbs): string
{
    if (!is_file($docxAbs)) {
        throw new RuntimeException('Generated contract document not found.');
    }

    $outDir = dirname($pdfAbs);
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException('Could not create PDF output directory.');
    }

    $errors = [];
    $converters = PHP_OS_FAMILY === 'Windows'
        ? [
            'pcvc_staff_contract_docx_to_pdf_msword',
            'pcvc_staff_contract_docx_to_pdf_vbscript',
            'pcvc_staff_contract_docx_to_pdf_libreoffice',
            'pcvc_staff_contract_docx_to_pdf_phpword',
        ]
        : [
            'pcvc_staff_contract_docx_to_pdf_libreoffice',
        ];

    $docxSize = filesize($docxAbs) ?: 0;
    $minPdfSize = max(400, (int) ($docxSize * 0.35));

    foreach ($converters as $converter) {
        if (!is_callable($converter)) {
            continue;
        }
        try {
            $converter($docxAbs, $pdfAbs);
            if (!is_file($pdfAbs)) {
                continue;
            }
            $pdfSize = filesize($pdfAbs) ?: 0;
            if ($pdfSize < 400) {
                $errors[] = $converter . ': PDF too small';
                @unlink($pdfAbs);
                continue;
            }
            if ($converter === 'pcvc_staff_contract_docx_to_pdf_phpword' && $pdfSize < $minPdfSize) {
                @unlink($pdfAbs);
                $errors[] = 'DomPDF output is too small — bullets/stamp may be missing.';
                continue;
            }
            return $converter;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Last resort on Windows only: basic DomPDF (shared hosting without Word/LibreOffice).
    if (PHP_OS_FAMILY === 'Windows') {
        try {
            pcvc_staff_contract_docx_to_pdf_phpword($docxAbs, $pdfAbs);
            if (is_file($pdfAbs) && filesize($pdfAbs) > 400) {
                return 'pcvc_staff_contract_docx_to_pdf_phpword_fallback';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    throw new RuntimeException(
        'Could not convert contract to PDF. ' .
        (implode(' | ', $errors) ?: 'Install LibreOffice on the server (recommended for cPanel/Linux), or enable PHP exec().')
    );
}

function pcvc_staff_contract_pdf_engine_warning(string $engine): string
{
    if ($engine === 'pcvc_staff_contract_docx_to_pdf_phpword'
        || $engine === 'pcvc_staff_contract_docx_to_pdf_phpword_fallback') {
        return ' PDF was generated in basic mode (bullets/stamp may be simplified). '
            . 'For full Word layout, install LibreOffice on the server or regenerate from a Windows machine with Microsoft Word.';
    }
    return '';
}

function pcvc_staff_contract_docx_to_pdf_vbscript(string $docxAbs, string $pdfAbs): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('VBScript Word conversion is only available on Windows.');
    }

    $script = dirname(__DIR__) . '/tools/convert-docx-to-pdf.vbs';
    if (!is_file($script)) {
        throw new RuntimeException('Word VBScript converter missing.');
    }

    if (is_file($pdfAbs)) {
        @unlink($pdfAbs);
    }

    $cmd = 'cscript //nologo ' . escapeshellarg($script)
        . ' ' . escapeshellarg($docxAbs)
        . ' ' . escapeshellarg($pdfAbs);

    @exec($cmd . ' 2>&1', $output, $code);
    if (!is_file($pdfAbs) || filesize($pdfAbs) < 400) {
        throw new RuntimeException(
            'VBScript Word conversion failed' . ($output ? ': ' . implode(' ', $output) : '.')
        );
    }
}

function pcvc_staff_contract_docx_to_pdf_msword(string $docxAbs, string $pdfAbs): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Microsoft Word conversion is only available on Windows.');
    }

    $script = dirname(__DIR__) . '/tools/convert-docx-to-pdf.ps1';
    if (!is_file($script)) {
        throw new RuntimeException('Word conversion script missing.');
    }

    $outDir = dirname($pdfAbs);
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException('Could not create PDF output directory.');
    }
    if (is_file($pdfAbs)) {
        @unlink($pdfAbs);
    }

    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($script)
        . ' -DocxPath ' . escapeshellarg($docxAbs)
        . ' -PdfPath ' . escapeshellarg($pdfAbs);

    @exec($cmd . ' 2>&1', $output, $code);
    if (!is_file($pdfAbs) || filesize($pdfAbs) < 400) {
        throw new RuntimeException(
            'Microsoft Word PDF conversion failed' . ($output ? ': ' . implode(' ', $output) : '.')
        );
    }
}

function pcvc_staff_contract_docx_to_pdf_phpword(string $docxAbs, string $pdfAbs): void
{
    pcvc_staff_contract_require_autoload();
    if (!class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
        throw new RuntimeException('PhpWord library missing.');
    }

    $dompdfPath = dirname(__DIR__) . '/vendor/dompdf/dompdf';
    if (!is_dir($dompdfPath)) {
        throw new RuntimeException('DomPDF library missing.');
    }

    \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF);
    \PhpOffice\PhpWord\Settings::setPdfRendererPath($dompdfPath);

    $phpWord = \PhpOffice\PhpWord\IOFactory::load($docxAbs);
    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
    $writer->save($pdfAbs);
    unset($phpWord, $writer);
}

function pcvc_staff_contract_docx_to_pdf_libreoffice(string $docxAbs, string $pdfAbs): void
{
    $candidates = [
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        '/usr/bin/libreoffice',
        '/usr/bin/soffice',
        '/usr/local/bin/libreoffice',
        '/usr/local/bin/soffice',
        '/snap/bin/libreoffice',
        'soffice',
        'libreoffice',
    ];

    $outDir = dirname($pdfAbs);
    $escapedOut = escapeshellarg($outDir);
    $escapedDocx = escapeshellarg($docxAbs);

    foreach ($candidates as $bin) {
        if ($bin !== 'soffice' && $bin !== 'libreoffice' && !is_file($bin)) {
            continue;
        }
        $cmd = escapeshellarg($bin) . ' --headless --convert-to pdf --outdir ' . $escapedOut . ' ' . $escapedDocx;
        @exec($cmd . ' 2>&1', $output, $code);
        $base = pathinfo($docxAbs, PATHINFO_FILENAME);
        $generated = $outDir . '/' . $base . '.pdf';
        if (is_file($generated)) {
            if ($generated !== $pdfAbs) {
                @rename($generated, $pdfAbs);
            }
            if (is_file($pdfAbs)) {
                return;
            }
        }
    }

    throw new RuntimeException('LibreOffice conversion unavailable.');
}

/**
 * Fetch full admin row for merge.
 *
 * @return array<string, mixed>|null
 */
function pcvc_staff_contract_admin_row(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, username, first_name, last_name, full_name, email, phone_number, role,
                position, employment_type, employment_start_date, national_id, date_of_birth,
                marital_status, nationality, place_of_birth, address, monthly_salary, salary_currency
         FROM admins WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Generate prefilled DOCX and optionally preview PDF from stored Word template.
 *
 * @return array{filled_docx:string, preview_pdf:?string, position_warning?:string, pdf_warning?:string}
 */
function pcvc_staff_contract_generate_preview(
    mysqli $conn,
    int $adminId,
    array $contract,
    ?string $signingDate = null,
    bool $makePdf = true
): array {
    $admin = pcvc_staff_contract_admin_row($conn, $adminId);
    if (!$admin) {
        throw new RuntimeException('Staff account not found.');
    }

    $docxRel = trim((string) ($contract['source_docx_path'] ?? ''));
    if ($docxRel === '') {
        throw new RuntimeException('No Word contract template uploaded.');
    }

    $docxAbs = pcvc_staff_contract_abs_path($docxRel);
    $stamp = time();
    $filledDocxRel = 'uploads/staff_contracts/generated/filled_' . $adminId . '_' . $stamp . '.docx';
    $previewPdfRel = 'uploads/staff_contracts/generated/preview_' . $adminId . '_' . $stamp . '.pdf';
    $filledDocxAbs = pcvc_staff_contract_abs_path($filledDocxRel);
    $previewPdfAbs = pcvc_staff_contract_abs_path($previewPdfRel);

    pcvc_staff_contract_fill_docx($docxAbs, $filledDocxAbs, $admin, $signingDate, null);

    $engine = '';
    $pdfWarning = '';
    if ($makePdf && !pcvc_staff_contract_use_docx_preview()) {
        $engine = pcvc_staff_contract_docx_to_pdf($filledDocxAbs, $previewPdfAbs);
        $pdfWarning = pcvc_staff_contract_pdf_engine_warning($engine);
    } else {
        $previewPdfRel = null;
    }

    $positionWarning = '';
    if (pcvc_staff_contract_resolve_position($admin) === '') {
        $positionWarning = ' Note: staff Position is empty in Staff Management — fill Position and save, then regenerate the contract.';
    }

    $oldFilled = trim((string) ($contract['filled_docx_path'] ?? ''));
    $oldPreview = trim((string) ($contract['source_pdf_path'] ?? ''));
    if ($oldFilled !== '' && $oldFilled !== $filledDocxRel) {
        $oldAbs = pcvc_staff_contract_abs_path($oldFilled);
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }
    if ($makePdf && $oldPreview !== '' && $oldPreview !== $previewPdfRel && ($contract['status'] ?? '') !== 'signed') {
        $oldAbs = pcvc_staff_contract_abs_path($oldPreview);
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    $contractId = (int) ($contract['id'] ?? 0);
    if ($makePdf) {
        $stmt = $conn->prepare(
            'UPDATE employment_contracts
             SET filled_docx_path = ?, source_pdf_path = ?
             WHERE admin_id = ? AND id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('ssii', $filledDocxRel, $previewPdfRel, $adminId, $contractId);
    } else {
        $stmt = $conn->prepare(
            'UPDATE employment_contracts
             SET filled_docx_path = ?
             WHERE admin_id = ? AND id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('sii', $filledDocxRel, $adminId, $contractId);
    }
    $stmt->execute();
    $stmt->close();

    return [
        'filled_docx' => $filledDocxRel,
        'preview_pdf' => $previewPdfRel,
        'position_warning' => $positionWarning,
        'pdf_warning' => $pdfWarning,
    ];
}

/**
 * Generate final signed contract from Word template + signature.
 *
 * @return array{docx:string, pdf:?string}
 */
function pcvc_staff_contract_generate_signed(
    mysqli $conn,
    int $adminId,
    array $contract,
    string $signatureDataUrl,
    string $typedName,
    string $signedDate
): array {
    $admin = pcvc_staff_contract_admin_row($conn, $adminId);
    if (!$admin) {
        throw new RuntimeException('Staff account not found.');
    }
    if ($typedName !== '') {
        $admin['full_name'] = $typedName;
    }

    $docxRel = trim((string) ($contract['source_docx_path'] ?? ''));
    if ($docxRel === '') {
        throw new RuntimeException('No Word contract template uploaded.');
    }

    pcvc_staff_contract_require_pdf_helpers();
    pcvc_staff_contract_ensure_dirs();
    $docxAbs = pcvc_staff_contract_abs_path($docxRel);
    $stamp = time();
    $signedDocxRel = 'uploads/staff_contracts/signed/signed_staff_' . $adminId . '_' . $stamp . '.docx';
    $signedPdfRel = 'uploads/staff_contracts/signed/signed_staff_' . $adminId . '_' . $stamp . '.pdf';
    $signedDocxAbs = pcvc_staff_contract_abs_path($signedDocxRel);
    $signedPdfAbs = pcvc_staff_contract_abs_path($signedPdfRel);

    pcvc_staff_contract_fill_docx($docxAbs, $signedDocxAbs, $admin, $signedDate, $signatureDataUrl);

    $signedPdfOut = null;
    if (!pcvc_staff_contract_use_docx_preview()) {
        $previewPdfAbs = pcvc_staff_contract_abs_path(
            'uploads/staff_contracts/generated/tmp_sign_' . $adminId . '_' . $stamp . '.pdf'
        );
        pcvc_staff_contract_docx_to_pdf($signedDocxAbs, $previewPdfAbs);
        try {
            pcvc_staff_contract_stamp_employee_signature_pdf($previewPdfAbs, $signatureDataUrl, $signedPdfAbs);
            $signedPdfOut = $signedPdfRel;
        } catch (Throwable $e) {
            if (is_file($previewPdfAbs)) {
                @copy($previewPdfAbs, $signedPdfAbs);
                if (is_file($signedPdfAbs)) {
                    $signedPdfOut = $signedPdfRel;
                }
            }
            if ($signedPdfOut === null) {
                throw new RuntimeException('Could not stamp signature on contract PDF: ' . $e->getMessage());
            }
        }
        if (is_file($previewPdfAbs)) {
            @unlink($previewPdfAbs);
        }
    }

    return [
        'docx' => $signedDocxRel,
        'pdf' => $signedPdfOut,
    ];
}

/**
 * Rebuild preview or signed PDF from stored template + current profile data.
 *
 * @return array{message:string, preview_pdf?:string, signed_pdf?:string}
 */
function pcvc_staff_contract_regenerate(
    mysqli $conn,
    int $adminId,
    array $contract,
    string $mode = 'preview'
): array {
    $mode = $mode === 'signed' ? 'signed' : 'preview';

    if ($mode === 'signed') {
        if (($contract['status'] ?? '') !== 'signed') {
            throw new RuntimeException('Contract is not signed yet.');
        }
        $sigRel = trim((string) ($contract['signature_file'] ?? ''));
        $sigAbs = $sigRel !== '' ? pcvc_staff_contract_abs_path($sigRel) : '';
        if ($sigAbs === '' || !is_file($sigAbs)) {
            throw new RuntimeException('Stored signature image not found — cannot rebuild signed PDF.');
        }
        $signatureDataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($sigAbs));
        $signed = pcvc_staff_contract_generate_signed(
            $conn,
            $adminId,
            $contract,
            $signatureDataUrl,
            trim((string) ($contract['staff_typed_name'] ?? '')),
            !empty($contract['signed_at']) ? date('Y-m-d', strtotime((string) $contract['signed_at'])) : date('Y-m-d')
        );

        $oldSignedPdf = trim((string) ($contract['signed_pdf_path'] ?? ''));
        if ($oldSignedPdf !== '' && $signed['pdf'] !== null && $oldSignedPdf !== $signed['pdf']) {
            $oldAbs = pcvc_staff_contract_abs_path($oldSignedPdf);
            if (is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }
        $oldSignedDocx = trim((string) ($contract['signed_docx_path'] ?? ''));
        if ($oldSignedDocx !== '' && $oldSignedDocx !== $signed['docx']) {
            $oldAbs = pcvc_staff_contract_abs_path($oldSignedDocx);
            if (is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }

        $contractId = (int) ($contract['id'] ?? 0);
        $signedPdf = $signed['pdf'] ?? '';
        $stmt = $conn->prepare(
            'UPDATE employment_contracts SET signed_docx_path = ?, signed_pdf_path = ?, pdf_path = ? WHERE admin_id = ? AND id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('sssii', $signed['docx'], $signedPdf, $signedPdf, $adminId, $contractId);
        $stmt->execute();
        $stmt->close();

        $message = pcvc_staff_contract_use_docx_preview()
            ? 'Signed contract Word file regenerated with current staff details.'
            : 'Signed contract PDF regenerated with current staff details.';

        return [
            'message' => $message,
            'signed_pdf' => $signedPdf !== '' ? $signedPdf : null,
            'signed_docx' => $signed['docx'],
        ];
    }

    $makePdf = !pcvc_staff_contract_use_docx_preview();
    $preview = pcvc_staff_contract_generate_preview($conn, $adminId, $contract, null, $makePdf);
    $message = $makePdf
        ? 'Contract preview PDF regenerated.'
        : 'Contract preview Word file regenerated.';
    if (!empty($preview['position_warning'])) {
        $message .= $preview['position_warning'];
    }
    if (!empty($preview['pdf_warning'])) {
        $message .= $preview['pdf_warning'];
    }

    return [
        'message' => $message,
        'preview_pdf' => $preview['preview_pdf'],
        'position_warning' => $preview['position_warning'] ?? '',
        'pdf_warning' => $preview['pdf_warning'] ?? '',
    ];
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/staff_contract_schema.php';
require_once __DIR__ . '/staff_contract_pdf.php';
require_once __DIR__ . '/contract_signature_image.php';
require_once __DIR__ . '/../includes/company_branding.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

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
 * Use the full Parrot template (stamp, drawings) when upload is a stripped export.
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

    if (!copy($canonical, $docxAbs)) {
        return 'Could not apply the standard contract template with company stamp.';
    }

    return 'Uploaded file had no company stamp/images. The standard Parrot contract template was applied automatically.';
}

/**
 * Word sometimes splits ${placeholder} across runs and spell-check tags.
 */
function pcvc_staff_contract_repair_docx_xml(string $xml): string
{
    $xml = preg_replace('/<w:proofErr[^>]*\/>/', '', $xml) ?? $xml;

    $runBoundary = '(?:<\/w:t><\/w:r><w:r[^>]*>(?:<w:rPr>.*?<\/w:rPr>)?<w:t(?:\s+xml:space="preserve")?[^>]*>)*';

    foreach (pcvc_staff_contract_placeholder_keys() as $key) {
        $quoted = preg_quote($key, '/');
        $pattern = '/\$\{' . $runBoundary . $quoted . $runBoundary . '\}/s';
        $replaced = preg_replace($pattern, '${' . $key . '}', $xml);
        if (is_string($replaced)) {
            $xml = $replaced;
        }
    }

    return $xml;
}

/**
 * Copy template and repair split placeholders before PhpWord merge.
 */
function pcvc_staff_contract_prepare_template(string $templateAbs): string
{
    pcvc_staff_contract_ensure_dirs();
    $work = pcvc_staff_contract_upload_dir() . '/tmp_tpl_' . bin2hex(random_bytes(8)) . '.docx';
    if (!copy($templateAbs, $work)) {
        throw new RuntimeException('Could not copy contract template.');
    }

    $zip = new ZipArchive();
    if ($zip->open($work) !== true) {
        @unlink($work);
        throw new RuntimeException('Could not open contract template.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!preg_match('/^word\/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$/', $name)) {
            continue;
        }
        $content = $zip->getFromName($name);
        if ($content === false) {
            continue;
        }
        $repaired = pcvc_staff_contract_repair_docx_xml($content);
        if ($repaired !== $content) {
            $zip->deleteName($name);
            $zip->addFromString($name, $repaired);
        }
    }
    $zip->close();

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

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!preg_match('/^word\/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$/', $name)) {
            continue;
        }
        $xml = $zip->getFromName($name);
        if ($xml === false) {
            continue;
        }
        $xml = pcvc_staff_contract_repair_docx_xml($xml);
        foreach ($values as $key => $value) {
            if (in_array($key, $imageKeys, true)) {
                continue;
            }
            $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = str_replace('${' . $key . '}', $safe, $xml);
        }
        $zip->deleteName($name);
        $zip->addFromString($name, $xml);
    }

    $zip->close();
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

    $preparedTemplate = pcvc_staff_contract_prepare_template($templateAbs);
    try {
        if (!copy($preparedTemplate, $outputDocxAbs)) {
            throw new RuntimeException('Could not copy prepared contract template.');
        }

        $values = pcvc_staff_contract_merge_values($admin, $signingDate, $signatureDataUrl);
        pcvc_staff_contract_fill_docx_text($outputDocxAbs, $values);
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
            'pcvc_staff_contract_docx_to_pdf_phpword',
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

    // Last resort: basic DomPDF (shared hosting without Word/LibreOffice).
    try {
        pcvc_staff_contract_docx_to_pdf_phpword($docxAbs, $pdfAbs);
        if (is_file($pdfAbs) && filesize($pdfAbs) > 400) {
            return 'pcvc_staff_contract_docx_to_pdf_phpword_fallback';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    throw new RuntimeException(
        'Could not convert contract to PDF. ' .
        (implode(' | ', $errors) ?: 'Install LibreOffice on the server, or enable PHP exec().')
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
    $dompdfPath = dirname(__DIR__) . '/vendor/dompdf/dompdf';
    if (!is_dir($dompdfPath)) {
        throw new RuntimeException('DomPDF library missing.');
    }

    Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
    Settings::setPdfRendererPath($dompdfPath);

    $phpWord = IOFactory::load($docxAbs);
    $writer = IOFactory::createWriter($phpWord, 'PDF');
    $writer->save($pdfAbs);
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
 * Generate prefilled DOCX + preview PDF from stored Word template.
 *
 * @return array{filled_docx:string, preview_pdf:string}
 */
function pcvc_staff_contract_generate_preview(
    mysqli $conn,
    int $adminId,
    array $contract,
    ?string $signingDate = null
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
    $engine = pcvc_staff_contract_docx_to_pdf($filledDocxAbs, $previewPdfAbs);
    $pdfWarning = pcvc_staff_contract_pdf_engine_warning($engine);

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
    if ($oldPreview !== '' && $oldPreview !== $previewPdfRel && ($contract['status'] ?? '') !== 'signed') {
        $oldAbs = pcvc_staff_contract_abs_path($oldPreview);
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    $contractId = (int) ($contract['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE employment_contracts
         SET filled_docx_path = ?, source_pdf_path = ?
         WHERE admin_id = ? AND id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Database error');
    }
    $stmt->bind_param('ssii', $filledDocxRel, $previewPdfRel, $adminId, $contractId);
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
 * Generate final signed PDF from Word template + signature.
 */
function pcvc_staff_contract_generate_signed(
    mysqli $conn,
    int $adminId,
    array $contract,
    string $signatureDataUrl,
    string $typedName,
    string $signedDate
): string {
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

    pcvc_staff_contract_ensure_dirs();
    $docxAbs = pcvc_staff_contract_abs_path($docxRel);
    $stamp = time();
    $filledDocxRel = 'uploads/staff_contracts/generated/signed_' . $adminId . '_' . $stamp . '.docx';
    $signedPdfRel = 'uploads/staff_contracts/signed/signed_staff_' . $adminId . '_' . $stamp . '.pdf';
    $filledDocxAbs = pcvc_staff_contract_abs_path($filledDocxRel);
    $signedPdfAbs = pcvc_staff_contract_abs_path($signedPdfRel);

    // Fill Word template, convert with Microsoft Word (keeps bullets + stamp), then stamp e-signature on PDF.
    pcvc_staff_contract_fill_docx($docxAbs, $filledDocxAbs, $admin, $signedDate, null);
    $previewPdfAbs = pcvc_staff_contract_abs_path(
        'uploads/staff_contracts/generated/tmp_sign_' . $adminId . '_' . $stamp . '.pdf'
    );
    pcvc_staff_contract_docx_to_pdf($filledDocxAbs, $previewPdfAbs);
    try {
        pcvc_staff_contract_stamp_employee_signature_pdf($previewPdfAbs, $signatureDataUrl, $signedPdfAbs);
    } catch (Throwable $e) {
        if (is_file($previewPdfAbs)) {
            @copy($previewPdfAbs, $signedPdfAbs);
        }
        if (!is_file($signedPdfAbs)) {
            throw new RuntimeException('Could not stamp signature on contract PDF: ' . $e->getMessage());
        }
    }
    if (is_file($previewPdfAbs)) {
        @unlink($previewPdfAbs);
    }

    return $signedPdfRel;
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
        $signedRel = pcvc_staff_contract_generate_signed(
            $conn,
            $adminId,
            $contract,
            $signatureDataUrl,
            trim((string) ($contract['staff_typed_name'] ?? '')),
            !empty($contract['signed_at']) ? date('Y-m-d', strtotime((string) $contract['signed_at'])) : date('Y-m-d')
        );

        $oldSigned = trim((string) ($contract['signed_pdf_path'] ?? ''));
        if ($oldSigned !== '' && $oldSigned !== $signedRel) {
            $oldAbs = pcvc_staff_contract_abs_path($oldSigned);
            if (is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }

        $contractId = (int) ($contract['id'] ?? 0);
        $stmt = $conn->prepare(
            'UPDATE employment_contracts SET signed_pdf_path = ?, pdf_path = ? WHERE admin_id = ? AND id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('ssii', $signedRel, $signedRel, $adminId, $contractId);
        $stmt->execute();
        $stmt->close();

        return [
            'message' => 'Signed contract PDF regenerated with current staff details.',
            'signed_pdf' => $signedRel,
        ];
    }

    $preview = pcvc_staff_contract_generate_preview($conn, $adminId, $contract);
    $message = 'Contract preview PDF regenerated.';
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

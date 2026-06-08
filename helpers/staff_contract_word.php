<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/staff_contract_schema.php';
require_once __DIR__ . '/staff_contract_pdf.php';
require_once __DIR__ . '/contract_signature_image.php';
require_once __DIR__ . '/../includes/company_branding.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

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
        'position' => trim((string) ($admin['position'] ?? '')),
        'employment_type' => trim((string) ($admin['employment_type'] ?? '')),
        'employment_start_date' => $startDate,
        'probation_end_date' => $probationEnd,
        'national_id' => trim((string) ($admin['national_id'] ?? '')),
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

    $processor = new TemplateProcessor($templateAbs);
    $values = pcvc_staff_contract_merge_values($admin, $signingDate, $signatureDataUrl);

    $imagePlaceholders = ['employee_signature', 'employer_signature'];
    foreach ($values as $key => $value) {
        if (in_array($key, $imagePlaceholders, true)) {
            continue;
        }
        $processor->setValue($key, $value);
    }

    $tmpFiles = [];

    $managerSig = pcvc_staff_contract_manager_signature_path();
    if (is_file($managerSig)) {
        try {
            $processor->setImageValue('employer_signature', [
                'path' => $managerSig,
                'width' => 95,
                'height' => 38,
                'ratio' => false,
            ]);
        } catch (Throwable $e) {
            $processor->setValue('employer_signature', '');
        }
    } else {
        $processor->setValue('employer_signature', '');
    }

    if ($signatureDataUrl && contract_signature_raw_bytes($signatureDataUrl) !== null) {
        $sigPng = contract_signature_to_display_png($signatureDataUrl)
            ?? contract_signature_raw_bytes($signatureDataUrl);
        if ($sigPng) {
            $tmpSig = pcvc_staff_contract_upload_dir() . '/signatures/tmp_merge_' . bin2hex(random_bytes(8)) . '.png';
            file_put_contents($tmpSig, $sigPng);
            $tmpFiles[] = $tmpSig;
            try {
                $processor->setImageValue('employee_signature', [
                    'path' => $tmpSig,
                    'width' => 85,
                    'height' => 32,
                    'ratio' => false,
                ]);
            } catch (Throwable $e) {
                $processor->setValue('employee_signature', '');
            }
        } else {
            $processor->setValue('employee_signature', '');
        }
    } else {
        $processor->setValue('employee_signature', '');
    }

    $processor->saveAs($outputDocxAbs);
    foreach ($tmpFiles as $tmp) {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function pcvc_staff_contract_docx_to_pdf(string $docxAbs, string $pdfAbs): void
{
    if (!is_file($docxAbs)) {
        throw new RuntimeException('Generated contract document not found.');
    }

    $outDir = dirname($pdfAbs);
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException('Could not create PDF output directory.');
    }

    $errors = [];

    try {
        pcvc_staff_contract_docx_to_pdf_phpword($docxAbs, $pdfAbs);
        if (is_file($pdfAbs) && filesize($pdfAbs) > 400) {
            return;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    try {
        pcvc_staff_contract_docx_to_pdf_libreoffice($docxAbs, $pdfAbs);
        if (is_file($pdfAbs) && filesize($pdfAbs) > 400) {
            return;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    throw new RuntimeException(
        'Could not convert contract to PDF. ' .
        (implode(' | ', $errors) ?: 'Install LibreOffice or ensure DomPDF is available.')
    );
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
    pcvc_staff_contract_docx_to_pdf($filledDocxAbs, $previewPdfAbs);

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

    return ['filled_docx' => $filledDocxRel, 'preview_pdf' => $previewPdfRel];
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

    // Embed signature in the Word template under the employee name, then convert to PDF.
    pcvc_staff_contract_fill_docx($docxAbs, $filledDocxAbs, $admin, $signedDate, $signatureDataUrl);
    pcvc_staff_contract_docx_to_pdf($filledDocxAbs, $signedPdfAbs);

    return $signedPdfRel;
}

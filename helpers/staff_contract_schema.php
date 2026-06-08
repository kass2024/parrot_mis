<?php
declare(strict_types=1);

function pcvc_staff_contract_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/staff_contracts';
}

function pcvc_staff_contract_ensure_dirs(): void
{
    $base = pcvc_staff_contract_upload_dir();
    foreach ([$base, $base . '/source', $base . '/generated', $base . '/signed', $base . '/signatures'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create staff contract directory.');
        }
    }
}

function pcvc_staff_contract_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS employment_contracts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT UNSIGNED NOT NULL,
            template_id INT UNSIGNED NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending_signature',
            source_docx_path VARCHAR(500) NULL,
            filled_docx_path VARCHAR(500) NULL,
            source_pdf_path VARCHAR(500) NULL,
            signed_pdf_path VARCHAR(500) NULL,
            pdf_path VARCHAR(500) NULL,
            contract_title VARCHAR(255) NULL,
            staff_typed_name VARCHAR(255) NULL,
            signature_file VARCHAR(500) NULL,
            uploaded_by INT UNSIGNED NULL,
            uploaded_at DATETIME NULL,
            signed_at DATETIME NULL,
            signed_ip VARCHAR(64) NULL,
            field_layout LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_employment_contract_admin (admin_id),
            KEY idx_employment_contract_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'source_docx_path' => "VARCHAR(500) NULL AFTER status",
        'filled_docx_path' => "VARCHAR(500) NULL AFTER source_docx_path",
        'source_pdf_path' => "VARCHAR(500) NULL AFTER filled_docx_path",
        'signed_pdf_path' => "VARCHAR(500) NULL AFTER source_pdf_path",
        'contract_title' => "VARCHAR(255) NULL AFTER pdf_path",
        'staff_typed_name' => "VARCHAR(255) NULL AFTER contract_title",
        'signature_file' => "VARCHAR(500) NULL AFTER staff_typed_name",
        'uploaded_by' => "INT UNSIGNED NULL AFTER signature_file",
        'uploaded_at' => "DATETIME NULL AFTER uploaded_by",
        'field_layout' => "LONGTEXT NULL AFTER signed_ip",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    $existing = [];
    $res = $conn->query('SHOW COLUMNS FROM employment_contracts');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existing[(string) ($row['Field'] ?? '')] = true;
        }
        $res->free();
    }

    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $conn->query("ALTER TABLE employment_contracts ADD COLUMN {$name} {$definition}");
        }
    }
}

/**
 * @return array<string, mixed>|null
 */
function pcvc_staff_contract_for_admin(mysqli $conn, int $adminId): ?array
{
    pcvc_staff_contract_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM employment_contracts WHERE admin_id = ? LIMIT 1');
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
 * @return array<string, mixed>
 */
function pcvc_staff_contract_has_template(?array $row): bool
{
    if (!$row) {
        return false;
    }
    if (trim((string) ($row['source_docx_path'] ?? '')) !== '') {
        return true;
    }
    return trim((string) ($row['source_pdf_path'] ?? '')) !== '';
}

function pcvc_staff_contract_row_status(?array $row): array
{
    if (!pcvc_staff_contract_has_template($row)) {
        return ['code' => 'no_contract', 'label' => 'No contract uploaded', 'badge' => 'secondary'];
    }
    if (($row['status'] ?? '') === 'signed' && trim((string) ($row['signed_pdf_path'] ?? $row['pdf_path'] ?? '')) !== '') {
        return ['code' => 'signed', 'label' => 'Signed', 'badge' => 'success'];
    }
    return ['code' => 'pending_signature', 'label' => 'Awaiting signature', 'badge' => 'warning'];
}

function pcvc_staff_contract_signed_path(array $row): string
{
    $path = trim((string) ($row['signed_pdf_path'] ?? ''));
    if ($path !== '') {
        return $path;
    }
    return trim((string) ($row['pdf_path'] ?? ''));
}

function pcvc_staff_contract_abs_path(string $relative): string
{
    return dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

/**
 * Remove all PDF/signature files linked to a contract row.
 *
 * @param array<string, mixed>|null $contract
 */
function pcvc_staff_contract_remove_files(?array $contract): void
{
    if (!$contract) {
        return;
    }

    $paths = [
        trim((string) ($contract['source_docx_path'] ?? '')),
        trim((string) ($contract['filled_docx_path'] ?? '')),
        trim((string) ($contract['source_pdf_path'] ?? '')),
        trim((string) ($contract['signed_pdf_path'] ?? '')),
        trim((string) ($contract['pdf_path'] ?? '')),
        trim((string) ($contract['signature_file'] ?? '')),
    ];

    foreach ($paths as $rel) {
        if ($rel === '') {
            continue;
        }
        $abs = pcvc_staff_contract_abs_path($rel);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

/**
 * Delete contract files and remove the database row for a staff member.
 */
function pcvc_staff_contract_delete_for_admin(mysqli $conn, int $adminId): bool
{
    pcvc_staff_contract_ensure_schema($conn);
    $contract = pcvc_staff_contract_for_admin($conn, $adminId);
    if (!$contract) {
        return false;
    }

    pcvc_staff_contract_remove_files($contract);

    $stmt = $conn->prepare('DELETE FROM employment_contracts WHERE admin_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Database error');
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

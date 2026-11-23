<?php
declare(strict_types=1);

/**
 * Francophonie Mobility managed documents.
 * Client form columns stay untouched; staff-added files land in Attached,
 * then managers move them into Visa submission document subgroups.
 */

require_once __DIR__ . '/francophonie_mobility_files.php';

/** Inbox bucket for staff / pending classification. */
function fm_doc_attached_group(): string
{
    return 'attached';
}

/**
 * Visa Submission Docs subgroups (manager destination folders).
 *
 * @return array<string, string> slug => label
 */
function fm_visa_submission_groups(): array
{
    return [
        'client_information' => 'Client information',
        'employment_documents' => 'Employment documents',
        'imm1295_form' => 'IMM1295 form',
        'imm5645_family_information_form' => 'IMM5645 family information form',
        'job_offer' => 'Job offer',
        'login_information' => 'Login information',
        'passport' => 'Passport',
        'photo' => 'Photo',
        'proof_of_meeting_requirements' => 'Proof of meeting requirements',
        'questionnaire' => 'Questionnaire',
        'resume' => 'Resume',
    ];
}

function fm_is_valid_doc_group(string $group): bool
{
    return $group === fm_doc_attached_group() || isset(fm_visa_submission_groups()[$group]);
}

function fm_doc_group_label(string $group): string
{
    if ($group === fm_doc_attached_group()) {
        return 'Attached';
    }
    return fm_visa_submission_groups()[$group] ?? $group;
}

function fm_ensure_documents_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $sql = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_documents` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `application_id` int(11) NOT NULL,
      `doc_group` varchar(64) NOT NULL DEFAULT 'attached',
      `original_name` varchar(255) NOT NULL DEFAULT '',
      `stored_path` varchar(255) NOT NULL,
      `mime_type` varchar(120) DEFAULT NULL,
      `file_size` int(11) NOT NULL DEFAULT 0,
      `source` enum('staff','client','manager') NOT NULL DEFAULT 'staff',
      `uploaded_by` int(11) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `application_id` (`application_id`),
      KEY `doc_group` (`doc_group`),
      KEY `source` (`source`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('fm_ensure_documents_schema: ' . $conn->error);
    }

    $srcCol = $conn->query("SHOW COLUMNS FROM francophonie_mobility_applications LIKE 'source'");
    if ($srcCol && $srcCol->num_rows === 0) {
        $conn->query("ALTER TABLE francophonie_mobility_applications ADD COLUMN `source` varchar(16) NOT NULL DEFAULT 'public' AFTER `status`");
    }
    if ($srcCol) {
        $srcCol->free();
    }
}

/**
 * @return list<array<string, mixed>>
 */
function fm_list_documents(mysqli $conn, int $applicationId, ?string $group = null): array
{
    if ($group !== null) {
        $st = $conn->prepare(
            'SELECT * FROM francophonie_mobility_documents WHERE application_id = ? AND doc_group = ? ORDER BY id ASC'
        );
        $st->bind_param('is', $applicationId, $group);
    } else {
        $st = $conn->prepare(
            'SELECT * FROM francophonie_mobility_documents WHERE application_id = ? ORDER BY FIELD(doc_group, \'attached\') DESC, doc_group ASC, id ASC'
        );
        $st->bind_param('i', $applicationId);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows ?: [];
}

/**
 * @return array{attached: list<array<string,mixed>>, visa: array<string, list<array<string,mixed>>>}
 */
function fm_documents_grouped(mysqli $conn, int $applicationId): array
{
    $attached = [];
    $visa = [];
    foreach (array_keys(fm_visa_submission_groups()) as $slug) {
        $visa[$slug] = [];
    }
    foreach (fm_list_documents($conn, $applicationId) as $row) {
        $g = (string) ($row['doc_group'] ?? '');
        if ($g === fm_doc_attached_group()) {
            $attached[] = $row;
        } elseif (isset($visa[$g])) {
            $visa[$g][] = $row;
        } else {
            $attached[] = $row;
        }
    }
    return ['attached' => $attached, 'visa' => $visa];
}

function fm_insert_document(
    mysqli $conn,
    int $applicationId,
    string $storedPath,
    string $originalName,
    string $group = 'attached',
    string $source = 'staff',
    ?int $uploadedBy = null,
    ?string $mime = null,
    int $size = 0
): int {
    if (!fm_is_valid_doc_group($group)) {
        $group = fm_doc_attached_group();
    }
    $mimeVal = $mime !== null && $mime !== '' ? $mime : '';
    $uploader = $uploadedBy !== null && $uploadedBy > 0 ? $uploadedBy : 0;
    $st = $conn->prepare(
        'INSERT INTO francophonie_mobility_documents
        (application_id, doc_group, original_name, stored_path, mime_type, file_size, source, uploaded_by)
        VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, NULLIF(?, 0))'
    );
    if (!$st) {
        throw new RuntimeException('Could not save document record.');
    }
    $st->bind_param(
        'issssisi',
        $applicationId,
        $group,
        $originalName,
        $storedPath,
        $mimeVal,
        $size,
        $source,
        $uploader
    );
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new RuntimeException('Could not save document: ' . $err);
    }
    $id = (int) $st->insert_id;
    $st->close();
    return $id;
}

function fm_transfer_document(mysqli $conn, int $docId, int $applicationId, string $newGroup): bool
{
    if (!fm_is_valid_doc_group($newGroup)) {
        return false;
    }
    $st = $conn->prepare(
        'UPDATE francophonie_mobility_documents SET doc_group = ? WHERE id = ? AND application_id = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('sii', $newGroup, $docId, $applicationId);
    $ok = $st->execute() && $st->affected_rows >= 0;
    $st->close();
    return $ok;
}

function fm_delete_document_row(mysqli $conn, int $docId, int $applicationId, bool $unlinkFile = true): bool
{
    $st = $conn->prepare(
        'SELECT stored_path FROM francophonie_mobility_documents WHERE id = ? AND application_id = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('ii', $docId, $applicationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return false;
    }
    $del = $conn->prepare('DELETE FROM francophonie_mobility_documents WHERE id = ? AND application_id = ? LIMIT 1');
    if (!$del) {
        return false;
    }
    $del->bind_param('ii', $docId, $applicationId);
    $ok = $del->execute() && $del->affected_rows > 0;
    $del->close();
    if ($ok && $unlinkFile) {
        $abs = fm_abs_upload_path((string) $row['stored_path']);
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }
    return $ok;
}

function fm_delete_all_documents_for_application(mysqli $conn, int $applicationId): void
{
    $rows = fm_list_documents($conn, $applicationId);
    foreach ($rows as $row) {
        $abs = fm_abs_upload_path((string) ($row['stored_path'] ?? ''));
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }
    $del = $conn->prepare('DELETE FROM francophonie_mobility_documents WHERE application_id = ?');
    if ($del) {
        $del->bind_param('i', $applicationId);
        $del->execute();
        $del->close();
    }
}

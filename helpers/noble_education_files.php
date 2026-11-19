<?php
declare(strict_types=1);

/**
 * Noble Education Group — Canada upload path helpers.
 */

function neg_normalize_rel_path(string $path): string
{
    return str_replace('\\', '/', ltrim(trim($path), '/'));
}

function neg_project_root(): string
{
    return dirname(__DIR__);
}

function neg_abs_upload_path(string $relativePath): ?string
{
    $rel = neg_normalize_rel_path($relativePath);
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    if (!str_starts_with(strtolower($rel), 'uploads/noble_education/')) {
        return null;
    }
    $candidate = neg_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $full = realpath($candidate);
    return ($full && is_file($full)) ? $full : null;
}

function neg_validate_stored_path(string $rel): bool
{
    return neg_abs_upload_path($rel) !== null;
}

/**
 * @return list<string>
 */
function neg_decode_other_docs(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $path) {
        $rel = neg_normalize_rel_path((string) $path);
        if ($rel !== '' && neg_validate_stored_path($rel)) {
            $out[] = $rel;
        }
    }
    return array_values(array_unique($out));
}

function neg_safe_rel_path(string $path): string
{
    $rel = neg_normalize_rel_path($path);
    if ($rel === '' || str_contains($rel, '..')) {
        return '';
    }
    if (!str_starts_with(strtolower($rel), 'uploads/noble_education/')) {
        return '';
    }
    return $rel;
}

/**
 * Stored other-document paths, including files missing on disk so an admin can remove them.
 *
 * @return list<string>
 */
function neg_list_other_doc_paths(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $path) {
        $rel = neg_safe_rel_path((string) $path);
        if ($rel !== '') {
            $out[] = $rel;
        }
    }
    return array_values(array_unique($out));
}

/**
 * @param array<string, mixed> $file
 */
function neg_store_uploaded_file(array $file, string $field): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file received');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Allowed types: PDF, JPG, PNG, WEBP, DOC, DOCX');
    }
    if ((int) ($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new RuntimeException('File too large (max 15MB)');
    }
    $field = preg_replace('/[^a-z0-9_]/', '', strtolower($field)) ?: 'file';
    $permDir = neg_project_root() . '/uploads/noble_education';
    if (!is_dir($permDir) && !mkdir($permDir, 0755, true) && !is_dir($permDir)) {
        throw new RuntimeException('Cannot create upload directory');
    }
    $filename = 'neg_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '_' . $field . '.' . $ext;
    $dest = $permDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save file');
    }
    return 'uploads/noble_education/' . $filename;
}

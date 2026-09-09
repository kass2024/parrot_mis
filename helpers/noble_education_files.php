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

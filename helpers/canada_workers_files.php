<?php
declare(strict_types=1);

/**
 * Canada general-workers recruitment upload path helpers.
 */

function cuw_normalize_rel_path(string $path): string
{
    return str_replace('\\', '/', ltrim(trim($path), '/'));
}

function cuw_project_root(): string
{
    return dirname(__DIR__);
}

function cuw_abs_upload_path(string $relativePath): ?string
{
    $rel = cuw_normalize_rel_path($relativePath);
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    if (!str_starts_with(strtolower($rel), 'uploads/canada_workers/')) {
        return null;
    }
    $candidate = cuw_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $full = realpath($candidate);
    return ($full && is_file($full)) ? $full : null;
}

function cuw_validate_stored_path(string $rel): bool
{
    return cuw_abs_upload_path($rel) !== null;
}

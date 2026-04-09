<?php
declare(strict_types=1);

/**
 * Web-relative path (for HTML src) from a calling script's directory (__DIR__) to the Parrot Canada logo.
 */
function parrot_brand_logo_href(string $callerDir): string
{
    $callerDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $callerDir), DIRECTORY_SEPARATOR);
    $dir = $callerDir;
    $projectRoot = null;

    for ($i = 0; $i < 16; $i++) {
        if (is_file($dir . DIRECTORY_SEPARATOR . 'header.php') && is_dir($dir . DIRECTORY_SEPARATOR . 'includes')) {
            $projectRoot = $dir;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    if ($projectRoot === null) {
        return 'assets/brand/parrot-mark.svg';
    }

    $depth = 0;
    $walk = $callerDir;
    while ($walk !== $projectRoot) {
        $parent = dirname($walk);
        if ($parent === $walk) {
            return 'assets/brand/parrot-mark.svg';
        }
        $depth++;
        $walk = $parent;
    }

    $suffix = is_file($projectRoot . DIRECTORY_SEPARATOR . 'parrot-canada-logo.png')
        ? 'parrot-canada-logo.png'
        : 'assets/brand/parrot-mark.svg';

    return str_repeat('../', $depth) . $suffix;
}

/**
 * Path relative to project root for Dompdf/HTML templates (chroot = project root).
 */
function parrot_brand_logo_pdf_path(): string
{
    $root = dirname(__DIR__);
    if (is_file($root . DIRECTORY_SEPARATOR . 'parrot-canada-logo.png')) {
        return 'parrot-canada-logo.png';
    }
    return 'assets/brand/parrot-mark.svg';
}

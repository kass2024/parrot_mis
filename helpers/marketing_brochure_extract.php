<?php
declare(strict_types=1);

/**
 * Smart Brochure Sharing — PDF → text → beautified HTML extractor.
 *
 * Strategy (best → fallback):
 *   1) `pdftotext` (xpdf / poppler-utils) — works on most cPanel / xampp boxes.
 *   2) Naive PHP regex extraction from PDF text streams (uncompressed only).
 *
 * The resulting HTML is intentionally simple: headings, paragraphs, lists.
 * Hosts that don't have either path simply get a friendly placeholder, and the
 * upload action still falls back to the embedded PDF viewer.
 */

/**
 * Public entry point. Returns ['text' => string, 'html' => string, 'engine' => string, 'ai_used' => bool].
 *
 * When the OpenAI key is configured in .env, the cleaned text is sent to the
 * AI formatter to produce a mobile-first, semantically marked-up HTML
 * fragment. Falls back to the regex formatter on any failure.
 */
function pcvc_brochure_extract_pdf(string $pdfAbsolutePath, string $title = '', string $regionName = ''): array
{
    if (!is_file($pdfAbsolutePath)) {
        return ['text' => '', 'html' => '', 'engine' => 'missing', 'ai_used' => false];
    }

    $text   = '';
    $engine = '';

    $cli = pcvc_brochure_run_pdftotext($pdfAbsolutePath);
    if ($cli !== null && trim($cli) !== '') {
        $text   = $cli;
        $engine = 'pdftotext';
    } else {
        $php = pcvc_brochure_php_extract($pdfAbsolutePath);
        if ($php !== null && trim($php) !== '') {
            $text   = $php;
            $engine = 'php-regex';
        }
    }

    if ($text === '') {
        return ['text' => '', 'html' => '', 'engine' => 'none', 'ai_used' => false];
    }

    $text   = pcvc_brochure_clean_extracted_text($text);
    $html   = '';
    $aiUsed = false;

    if (function_exists('pcvc_brochure_ai_enabled') && pcvc_brochure_ai_enabled()) {
        $aiHtml = pcvc_brochure_ai_html_from_text($text, $title, $regionName);
        if (is_string($aiHtml) && trim($aiHtml) !== '') {
            $html   = $aiHtml;
            $engine .= '+ai';
            $aiUsed = true;
        }
    }

    if ($html === '') {
        $html = pcvc_brochure_text_to_html($text);
    }

    return ['text' => $text, 'html' => $html, 'engine' => $engine, 'ai_used' => $aiUsed];
}

/**
 * Try the `pdftotext` binary in a few common locations.
 */
function pcvc_brochure_run_pdftotext(string $pdf): ?string
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $candidates = ['pdftotext'];
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\Program Files\\xpdf\\bin64\\pdftotext.exe';
        $candidates[] = 'C:\\Program Files (x86)\\xpdf\\bin\\pdftotext.exe';
    } else {
        $candidates[] = '/usr/bin/pdftotext';
        $candidates[] = '/usr/local/bin/pdftotext';
    }

    foreach ($candidates as $bin) {
        $cmd = escapeshellarg($bin) . ' -layout -enc UTF-8 -nopgbrk ' . escapeshellarg($pdf) . ' -';
        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            continue;
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0 && is_string($out) && $out !== '') {
            return $out;
        }
        // Some hosts return non-zero even on success; try output anyway when present.
        if (is_string($out) && trim($out) !== '' && stripos((string) $err, 'not found') === false) {
            return $out;
        }
    }

    return null;
}

/**
 * Minimalist pure-PHP fallback. Pulls text from BT...ET blocks of uncompressed
 * content streams. Good enough for simple, non-encrypted PDFs.
 */
function pcvc_brochure_php_extract(string $pdf): ?string
{
    $raw = @file_get_contents($pdf);
    if ($raw === false || $raw === '') {
        return null;
    }

    // Decompress FlateDecode streams when possible (no external deps).
    if (function_exists('gzuncompress')) {
        $raw = preg_replace_callback(
            '/stream\\r?\\n(.*?)\\r?\\nendstream/s',
            static function (array $m): string {
                $data = $m[1] ?? '';
                $un   = @gzuncompress($data);
                if ($un !== false && $un !== '') {
                    return "stream\n" . $un . "\nendstream";
                }
                return $m[0];
            },
            $raw
        ) ?? $raw;
    }

    if (!preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $blocks)) {
        return null;
    }

    $out = [];
    foreach ($blocks[1] as $block) {
        // Strings: (text) Tj   OR   [(t)(e)(x)(t)] TJ
        if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)\s*T[jJ]/', $block, $hits)) {
            $line = '';
            foreach ($hits[0] as $h) {
                if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)/', $h, $strs)) {
                    foreach ($strs[0] as $s) {
                        $s = substr($s, 1, -1);
                        $s = str_replace(
                            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
                            ['(', ')', '\\', "\n", "\r", "\t"],
                            $s
                        );
                        $line .= $s . ' ';
                    }
                }
            }
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
    }
    if (!$out) {
        return null;
    }

    return implode("\n", $out);
}

/**
 * Normalise extracted text: collapse stray whitespace, fix common artifacts.
 */
function pcvc_brochure_clean_extracted_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Common ligature replacements
    $text = strtr($text, [
        'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl',
        "\u{00A0}" => ' ',
    ]);
    // Remove form-feed and other control chars except newline/tab.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
    // Collapse 3+ blank lines to 2.
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    // Right-strip spaces on every line.
    $lines = preg_split("/\n/", $text) ?: [];
    $lines = array_map(static fn(string $l): string => rtrim($l), $lines);
    return trim(implode("\n", $lines));
}

/**
 * Convert the plaintext into a small set of safe HTML blocks.
 */
function pcvc_brochure_text_to_html(string $text): string
{
    $blocks = preg_split("/\n\s*\n/", $text) ?: [];
    $html   = '';
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        $lines = preg_split("/\n/", $block) ?: [];

        // Try list detection: every line starts with a bullet or numbering.
        $bulletPattern  = '/^\s*([\x{2022}\x{25CF}\x{25CB}\x{25A0}\x{2013}\x{2014}\x{00B7}\-\*o])\s+(.+)$/u';
        $numberedPattern = '/^\s*(\d{1,3})[\.\)]\s+(.+)$/';
        $allBullets = true;
        $allNumbered = true;
        foreach ($lines as $ln) {
            if (!preg_match($bulletPattern, $ln))   { $allBullets  = false; }
            if (!preg_match($numberedPattern, $ln)) { $allNumbered = false; }
            if (!$allBullets && !$allNumbered) break;
        }
        if ($allBullets && count($lines) > 1) {
            $html .= '<ul class="brochure-list">';
            foreach ($lines as $ln) {
                if (preg_match($bulletPattern, $ln, $m)) {
                    $html .= '<li>' . pcvc_brochure_escape_inline($m[2]) . '</li>';
                }
            }
            $html .= '</ul>';
            continue;
        }
        if ($allNumbered && count($lines) > 1) {
            $html .= '<ol class="brochure-list">';
            foreach ($lines as $ln) {
                if (preg_match($numberedPattern, $ln, $m)) {
                    $html .= '<li>' . pcvc_brochure_escape_inline($m[2]) . '</li>';
                }
            }
            $html .= '</ol>';
            continue;
        }

        // Single short uppercase line → heading.
        if (count($lines) === 1) {
            $only = trim($lines[0]);
            $isUpper = $only !== ''
                       && preg_match('/^[A-Z0-9 \-\&\:\(\)\/\,\.\']{4,80}$/', $only)
                       && strtoupper($only) === $only
                       && preg_match('/[A-Z]/', $only);
            if ($isUpper) {
                $html .= '<h3 class="brochure-heading">' . pcvc_brochure_escape_inline($only) . '</h3>';
                continue;
            }
            $isShortTitle = mb_strlen($only) > 0
                            && mb_strlen($only) <= 70
                            && preg_match('/^[A-Z][^.!?]{2,}$/u', $only)
                            && substr($only, -1) !== '.';
            if ($isShortTitle) {
                $html .= '<h4 class="brochure-subheading">' . pcvc_brochure_escape_inline($only) . '</h4>';
                continue;
            }
        }

        // Default: paragraph (single line breaks become <br>).
        $body = implode("\n", $lines);
        $body = pcvc_brochure_escape_inline($body);
        $body = nl2br($body, false);
        $html .= '<p class="brochure-para">' . $body . '</p>';
    }

    if ($html === '') {
        $html = '<p class="brochure-para">' . pcvc_brochure_escape_inline($text) . '</p>';
    }
    return $html;
}

/**
 * Escape user text and turn URLs into clickable links.
 */
function pcvc_brochure_escape_inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace_callback(
        '~\b(https?://[^\s<]+|www\.[^\s<]+)~i',
        static function (array $m): string {
            $url = $m[1];
            $href = stripos($url, 'http') === 0 ? $url : 'http://' . $url;
            return '<a href="' . $href . '" target="_blank" rel="noopener">' . $url . '</a>';
        },
        $s
    ) ?? $s;
    $s = preg_replace_callback(
        '~([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})~',
        static fn(array $m): string => '<a href="mailto:' . $m[1] . '">' . $m[1] . '</a>',
        $s
    ) ?? $s;
    return $s;
}

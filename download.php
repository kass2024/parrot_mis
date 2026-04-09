<?php
/**
 * Secure file proxy for uploaded documents.
 *
 * Usage (client side):
 *   const href = 'download.php?f=' + encodeURIComponent(btoa('uploads/applications/abc123/transcript.pdf'));
 *   // Optional: &inline=1   -> try to open in browser (PDF/images)
 *   // Optional: &name=...   -> override display filename
 *
 * Why:
 * - Avoids direct /uploads access that can hit 403 due to Apache rules.
 * - Sanitizes/normalizes paths; blocks traversal outside allowed roots.
 * - Sets correct headers for inline/attachment and UTF-8 filenames.
 */

// --------- 1) CONFIG: allowlist base directories (relative to this script) ---------
$ALLOWED_BASES = [
  // main apps
  realpath(__DIR__ . '/uploads/applications'),
  // if you also keep one-page or country-specific uploads elsewhere, add them:
  realpath(__DIR__ . '/uploads'),                // general
  realpath(__DIR__ . '/uploads/malta'),          // malta
  realpath(__DIR__ . '/uploads/turkey'),         // turkey
  realpath(__DIR__ . '/uploads/georgia'),        // georgia
];
// Remove false entries (in case a folder doesn’t exist yet)
$ALLOWED_BASES = array_values(array_filter($ALLOWED_BASES));

// Hard-fail if no base exists
if (empty($ALLOWED_BASES)) {
  http_response_code(500);
  exit('No upload base directories are configured.');
}

// --------- 2) Read and validate input ---------
$enc = $_GET['f'] ?? '';
if ($enc === '' || !is_string($enc)) {
  http_response_code(400);
  exit('Missing file parameter.');
}

// base64 (URL-safe or regular) -> decode
$decoded = base64_decode(strtr($enc, ' ', '+'), true);
if ($decoded === false) {
  http_response_code(400);
  exit('Invalid file parameter.');
}

// Optional friendly name (for download filename)
$overrideName = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$forceInline  = isset($_GET['inline']) && $_GET['inline'] == '1';

// --------- 3) Normalize path (strip quotes, backslashes, clip before "uploads/") ---------
$path = str_replace("\0", '', $decoded);      // remove any NUL bytes
$path = trim($path, "\"' \t\r\n");
$path = str_replace('\\', '/', $path);

// if string contains 'uploads/', cut to relative portion
$pos = stripos($path, 'uploads/');
if ($pos !== false) {
  $path = substr($path, $pos);
}

// Remove leading slashes to enforce "relative"
$path = ltrim($path, '/');

// Reject if nothing left
if ($path === '') {
  http_response_code(400);
  exit('Empty path.');
}

// --------- 4) Resolve real path and ensure it stays inside allowlisted roots ---------
$full = realpath(__DIR__ . '/' . $path);
if ($full === false || !is_file($full)) {
  http_response_code(404);
  exit('File not found.');
}

// Check $full is inside at least one allowed base dir
$insideAllowed = false;
foreach ($ALLOWED_BASES as $base) {
  if ($base && strpos($full, $base) === 0) {
    $insideAllowed = true;
    break;
  }
}
if (!$insideAllowed) {
  http_response_code(403);
  exit('Forbidden.');
}

// --------- 5) Headers ---------
$size = @filesize($full);
$mime = function_exists('mime_content_type') ? @mime_content_type($full) : 'application/octet-stream';
if (!$mime) $mime = 'application/octet-stream';

// Determine filename to show
$displayName = $overrideName !== '' ? $overrideName : basename($full);

// Build filename header (ASCII + RFC 5987 UTF-8)
$asciiName = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $displayName);
$utf8Name  = rawurlencode($displayName);

// Decide inline vs attachment
$dispositionType = $forceInline ? 'inline' : 'attachment';

// Cache policy (download links can be cacheable; change to no-store if you prefer)
header('Content-Type: ' . $mime);
if ($size !== false) header('Content-Length: ' . $size);
header(sprintf('Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $dispositionType, $asciiName, $utf8Name));
header('X-Content-Type-Options: nosniff');
// Safer default: private, no-transform (tweak as needed)
header('Cache-Control: private, max-age=3600, no-transform');

// --------- 6) (Optional) X-Sendfile / X-Accel-Redirect support ---------
// If your server is configured for it, uncomment ONE of these for much faster sends:
//
// Apache (mod_xsendfile):
// header('X-Sendfile: ' . $full);
// exit;
//
// Nginx (X-Accel):
// $internalPrefix = '/protected'; // map this to your uploads root in nginx
// $internalPath   = $internalPrefix . str_replace(realpath(__DIR__), '', $full);
// header('X-Accel-Redirect: ' . $internalPath);
// exit;

// --------- 7) Read the file in chunks (memory friendly) ---------
$fp = @fopen($full, 'rb');
if (!$fp) {
  http_response_code(500);
  exit('Could not open file.');
}

// Clean output buffers to avoid corruption
while (ob_get_level() > 0) { ob_end_clean(); }

$chunk = 8192;
set_time_limit(0);
while (!feof($fp)) {
  $buf = fread($fp, $chunk);
  if ($buf === false) break;
  echo $buf;
  flush();
}
fclose($fp);
exit;

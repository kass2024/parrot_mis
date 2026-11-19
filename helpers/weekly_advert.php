<?php
declare(strict_types=1);

function weekly_advert_ensure_schema(mysqli $conn): void
{
    $dir = dirname(__DIR__) . '/weekly-adverts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    $htBody = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n<IfModule mod_headers.c>\nHeader set Access-Control-Allow-Origin \"*\"\nHeader set Cache-Control \"public, max-age=86400\"\n</IfModule>\n";
    if (!is_file($ht) || file_get_contents($ht) !== $htBody) {
        @file_put_contents($ht, $htBody);
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS weekly_adverts (
            id INT NOT NULL AUTO_INCREMENT,
            code VARCHAR(16) NOT NULL,
            caption MEDIUMTEXT NOT NULL,
            flyer_file VARCHAR(255) NOT NULL,
            preview_file VARCHAR(255) NOT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $column = $conn->query("SHOW COLUMNS FROM weekly_adverts LIKE 'set_code'");
    if ($column && $column->num_rows === 0) {
        $conn->query("ALTER TABLE weekly_adverts ADD COLUMN set_code VARCHAR(16) NULL DEFAULT NULL AFTER code, ADD KEY set_code (set_code)");
    }
}

function weekly_advert_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com');
    return ($https ? 'https' : 'http') . '://' . $host;
}

function weekly_advert_share_url(string $code): string
{
    return 'https://mis.visaconsultantcanada.com/advert.php?c=' . rawurlencode($code) . '&v=5';
}

function weekly_advert_gemini_key(): string
{
    $path = dirname(__DIR__, 2) . '/private/gemini_api_key';
    if (is_file($path)) {
        $key = trim((string) file_get_contents($path));
        if ($key !== '') {
            return $key;
        }
    }
    foreach (['GEMINI_API_KEY', 'GOOGLE_AI_API_KEY'] as $name) {
        $key = trim((string) getenv($name));
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

function weekly_advert_caption_from_flyer(string $absolutePath, string $mime): string
{
    $key = weekly_advert_gemini_key();
    if ($key === '') {
        throw new RuntimeException('Gemini is not configured on the server.');
    }
    $bytes = file_get_contents($absolutePath);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not read the flyer.');
    }
    $prompt = "Read the attached flyer and write one WhatsApp post for that flyer only.\n\n"
        . "The flyer can be about anything: a country, a course, a job, an event, a product, a church, a school, or another offer. Do not assume it is about Malta.\n\n"
        . "Use this shape only as empty formatting. Fill every line from the flyer. Delete a section the flyer does not have:\n\n"
        . "🚨 [headline printed on the flyer]\n\n"
        . "[one short paragraph: who is announcing, and what is being offered]\n\n"
        . "[urgency line only if the flyer shows limited places, a deadline, or a similar call]\n\n"
        . "[section title taken from the flyer, such as programs, services, destinations, dates, or offers]\n\n"
        . "1. [item printed on the flyer]\n"
        . "2. [next item]\n\n"
        . "[benefits or reasons, only the ones printed]\n\n"
        . "[phone, email, website, or address, only if printed]\n\n"
        . "[organization name and slogan, only if printed]\n\n"
        . "#Hashtags about this flyer\n\n"
        . "Rules:\n"
        . "- The image is the only source of facts. Do not reuse Malta, Schengen, electrician, plumber, or any other topic unless those words are visible on this flyer.\n"
        . "- Include the important text on the flyer: headline, list items, prices, dates, locations, restrictions, and contact details.\n"
        . "- If a restriction is printed, such as employment is not guaranteed, keep it.\n"
        . "- Do not invent programs, countries, fees, deadlines, phone numbers, or guarantees.\n"
        . "- Write in the language used on the flyer.\n"
        . "- Use emojis and short lines so it is easy to read in WhatsApp.\n"
        . "- Output only the post. No introduction and no quotation marks.";

    $payload = [
        'systemInstruction' => [
            'parts' => [[
                'text' => 'You write a WhatsApp announcement from whatever flyer image you are shown. Never default to Malta, study abroad, or a previous advert. If the flyer is about something else, the post must be about that something else.',
            ]],
        ],
        'contents' => [[
            'parts' => [
                ['inlineData' => [
                    'mimeType' => $mime,
                    'data' => base64_encode($bytes),
                ]],
                ['text' => $prompt],
            ],
        ]],
        'generationConfig' => [
            'temperature' => 0.6,
            'maxOutputTokens' => 4096,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ],
    ];
    $model = 'gemini-2.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('Gemini could not be reached. Try the flyer again.');
    }
    $data = json_decode($raw, true);
    if ($status >= 400 || !is_array($data)) {
        $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        error_log('weekly advert gemini HTTP ' . $status . ' ' . substr($message, 0, 240));
        throw new RuntimeException('Gemini could not write the advert. Try again in a moment.');
    }
    $text = '';
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (is_string($part['text'] ?? null)) {
            $text .= $part['text'];
        }
    }
    $text = trim($text);
    if ($text === '') {
        throw new RuntimeException('Gemini returned an empty advert. Try the flyer again.');
    }
    return $text;
}

/**
 * @return array{0:string,1:int,2:int} relative preview path, width, height
 */
function weekly_advert_make_preview(string $source, string $dest): array
{
    $info = @getimagesize($source);
    if ($info === false) {
        throw new RuntimeException('The flyer must be a JPG, PNG, or WEBP image.');
    }
    $mime = (string) ($info['mime'] ?? '');
    $loader = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => function_exists('imagecreatefromwebp') ? 'imagecreatefromwebp' : '',
        default => '',
    };
    if ($loader === '' || !function_exists('imagejpeg')) {
        if (!@copy($source, $dest)) {
            throw new RuntimeException('Could not prepare the flyer preview.');
        }
        return ['copied', (int) $info[0], (int) $info[1]];
    }
    $src = @$loader($source);
    if (!$src) {
        throw new RuntimeException('Could not read the flyer image.');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1000 / max(1, $w), 1400 / max(1, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $frame = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($frame, 255, 255, 255);
    imagefilledrectangle($frame, 0, 0, $nw, $nh, $white);
    imagecopyresampled($frame, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    imageinterlace($frame, false);
    $quality = 78;
    do {
        imagejpeg($frame, $dest, $quality);
        clearstatcache(true, $dest);
        $size = filesize($dest) ?: 0;
        $quality -= 6;
    } while ($size > 160000 && $quality >= 42);
    $outW = imagesx($frame);
    $outH = imagesy($frame);
    imagedestroy($frame);
    $out = @getimagesize($dest);
    return ['ok', (int) ($out[0] ?? $outW), (int) ($out[1] ?? $outH)];
}

function weekly_advert_image_url(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', $relativePath);
    return 'https://mis.visaconsultantcanada.com/' . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}

function weekly_advert_store_upload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose an advert flyer to upload.');
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('The flyer must be 8 MB or smaller.');
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $mime = (string) ($info['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Upload a JPG, PNG, or WEBP flyer.');
    }
    $dir = dirname(__DIR__) . '/weekly-adverts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $token = bin2hex(random_bytes(8));
    $flyerName = 'flyer_' . $token . '.' . $ext;
    $previewName = 'preview_' . $token . '.jpg';
    $flyerPath = $dir . '/' . $flyerName;
    if (!move_uploaded_file((string) $file['tmp_name'], $flyerPath)) {
        throw new RuntimeException('Could not store the flyer.');
    }
    weekly_advert_make_preview($flyerPath, $dir . '/' . $previewName);
    return [
        'mime' => $mime,
        'flyer' => 'weekly-adverts/' . $flyerName,
        'preview' => 'weekly-adverts/' . $previewName,
        'absolute' => $flyerPath,
    ];
}

/** @return list<array<string,mixed>> */
function weekly_advert_bundle(mysqli $conn, string $code): array
{
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '';
    if ($code === '') {
        return [];
    }
    $stmt = $conn->prepare('SELECT id, code, set_code, caption, flyer_file, preview_file, created_at FROM weekly_adverts WHERE set_code = ? ORDER BY id ASC');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows) {
        return $rows;
    }
    $one = weekly_advert_find($conn, $code);
    return $one ? [$one] : [];
}

function weekly_advert_find(mysqli $conn, string $code): ?array
{
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '';
    if ($code === '') {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, code, set_code, caption, flyer_file, preview_file, created_at FROM weekly_adverts WHERE code = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

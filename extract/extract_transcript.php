<?php
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(120);

$LOG_FILE = __DIR__ . '/debug.log';

function log_msg($msg): void
{
    global $LOG_FILE;
    @file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . print_r($msg, true) . PHP_EOL,
        FILE_APPEND
    );
}

function json_exit(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function call_openai_vision(string $dataUrl, string $systemPrompt, string $userPrompt, string $apiKey, string $model): array
{
    $payload = [
        'model'             => $model,
        'temperature'       => 0,
        'response_format'   => ['type' => 'json_object'],
        'max_tokens'        => 800,
        'messages'          => [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userPrompt],
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url'    => $dataUrl,
                            'detail' => 'high',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['error' => "Network error: {$error}"];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        return ['error' => 'Invalid response from OpenAI'];
    }

    if ($httpCode >= 400 || isset($data['error'])) {
        $msg = (string) ($data['error']['message'] ?? "OpenAI HTTP {$httpCode}");
        return ['error' => $msg];
    }

    $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        return ['error' => 'No text returned from OpenAI'];
    }

    $json = json_decode($text, true);
    if (!is_array($json)) {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $json = json_decode(trim($text), true);
    }

    if (!is_array($json)) {
        return ['error' => 'Could not parse OpenAI JSON', 'raw' => $text];
    }

    return ['data' => $json];
}

function image_to_data_url(string $filePath, string $mime): string
{
    $imageData = @file_get_contents($filePath);
    if ($imageData === false) {
        throw new RuntimeException('Unable to read image file.');
    }

    return 'data:' . $mime . ';base64,' . base64_encode($imageData);
}

function pdf_first_page_to_data_url(string $filePath): ?string
{
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setResolution(200, 200);
            $im->readImage($filePath . '[0]');
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(90);
            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            if ($blob) {
                return 'data:image/jpeg;base64,' . base64_encode($blob);
            }
        } catch (Throwable $e) {
            log_msg('Imagick PDF conversion failed: ' . $e->getMessage());
        }
    }

    $gsCandidates = ['gswin64c', 'gswin32c', 'gs'];
    foreach ($gsCandidates as $gs) {
        $tmpImg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transcript_' . uniqid('', true) . '.png';
        $cmd = escapeshellarg($gs)
            . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r200 '
            . '-dFirstPage=1 -dLastPage=1 '
            . '-sOutputFile=' . escapeshellarg($tmpImg) . ' '
            . escapeshellarg($filePath);

        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($tmpImg)) {
            $data = @file_get_contents($tmpImg);
            @unlink($tmpImg);
            if ($data !== false) {
                return 'data:image/png;base64,' . base64_encode($data);
            }
        }
    }

    return null;
}

log_msg('===== REQUEST START =====');

require_once dirname(__DIR__) . '/helpers/env_bootstrap.php';

$API_KEY = pcvc_env('OPENAI_API_KEY');
if ($API_KEY === '') {
    json_exit(['status' => 'error', 'message' => 'OpenAI not configured. Set OPENAI_API_KEY in .env']);
}

$model = pcvc_env('OPENAI_MODEL');
if ($model === '') {
    $model = 'gpt-4o-mini';
}

if (
    empty($_FILES['transcript']['tmp_name']) ||
    (int) ($_FILES['transcript']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
) {
    json_exit(['status' => 'error', 'message' => 'Transcript file is required']);
}

$filePath = (string) $_FILES['transcript']['tmp_name'];
$fileName = (string) $_FILES['transcript']['name'];
$fileSize = (int) ($_FILES['transcript']['size'] ?? 0);
$ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mime     = mime_content_type($filePath) ?: 'application/octet-stream';

log_msg("File received: {$fileName} ({$fileSize} bytes, {$mime})");

$imageExts = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif'];
$dataUrl   = null;

try {
    if (in_array($ext, $imageExts, true)) {
        $dataUrl = image_to_data_url($filePath, $mime);
    } elseif ($ext === 'pdf') {
        $dataUrl = pdf_first_page_to_data_url($filePath);
        if ($dataUrl === null) {
            json_exit([
                'status'  => 'error',
                'message' => 'PDF conversion failed on server. Reload the page and try again — your browser will convert the PDF automatically.',
            ]);
        }
    } else {
        json_exit(['status' => 'error', 'message' => 'Unsupported file type. Use PDF or image (JPG, PNG, WEBP).']);
    }
} catch (RuntimeException $e) {
    log_msg('Document prep failed: ' . $e->getMessage());
    json_exit(['status' => 'error', 'message' => $e->getMessage()]);
}

$systemPrompt = <<<'PROMPT'
You are a STRICT academic transcript extractor.

IMPORTANT:
- The document may be scanned.
- Read ONLY THE FIRST PAGE / header section.

Rules:
- Extract ONLY header information
- Do NOT infer or guess
- Ignore tables, grades, stamps, signatures
- Stop after the Class line
- Output VALID JSON ONLY
PROMPT;

$userPrompt = <<<'PROMPT'
Return exactly:

{
  "name": "",
  "surname": "",
  "registration_number": "",
  "academic_year_start": "",
  "date_of_birth": "",
  "program": "",
  "class": ""
}

Rules:
- Academic year: FIRST year only
- Class must be "Bac I" if shown
PROMPT;

log_msg('Calling OpenAI vision');
$result = call_openai_vision($dataUrl, $systemPrompt, $userPrompt, $API_KEY, $model);

if (isset($result['error'])) {
    log_msg('API error: ' . $result['error']);
    json_exit(['status' => 'error', 'message' => $result['error']]);
}

$json = $result['data'];
if (empty($json['name'])) {
    log_msg('Invalid JSON output');
    json_exit(['status' => 'error', 'message' => 'Extraction failed — could not read transcript header']);
}

log_msg('===== SUCCESS =====');

json_exit([
    'status' => 'success',
    'data'   => $json,
]);

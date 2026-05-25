<?php
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$LOG_FILE = __DIR__ . '/debug.log';

function log_msg($msg): void
{
    global $LOG_FILE;
    file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . print_r($msg, true) . PHP_EOL,
        FILE_APPEND
    );
}

function json_exit(array $payload, int $code = 200): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function upload_openai_file(string $filePath, string $mime, string $fileName, string $apiKey): string
{
    $ch = curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$apiKey}"],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'purpose' => 'assistants',
            'file'    => new CURLFile($filePath, $mime, $fileName),
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('OpenAI file upload failed: ' . $error);
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data) || empty($data['id'])) {
        $apiMsg = is_array($data) ? ($data['error']['message'] ?? 'unknown error') : 'invalid response';
        throw new RuntimeException('OpenAI file upload failed: ' . $apiMsg);
    }

    return (string) $data['id'];
}

function call_responses_api(array $payload, string $apiKey, int $maxRetries = 3, int $delayMs = 800): array
{
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$apiKey}",
                'Content-Type: application/json',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 180,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => ['message' => $error]];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['error' => ['message' => 'Invalid API response']];
        }

        if (!isset($data['error'])) {
            return $data;
        }

        $message = strtolower((string) ($data['error']['message'] ?? ''));
        if (str_contains($message, 'ownership') && $attempt < ($maxRetries - 1)) {
            usleep($delayMs * 1000);
            continue;
        }

        return $data;
    }

    return ['error' => ['message' => 'Extraction failed after retries']];
}

function response_text(array $data): string
{
    if (!empty($data['output_text']) && is_string($data['output_text'])) {
        return $data['output_text'];
    }

    foreach ($data['output'] ?? [] as $entry) {
        if (!empty($entry['text']) && is_string($entry['text'])) {
            return $entry['text'];
        }

        foreach ($entry['content'] ?? [] as $content) {
            if (!empty($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
        }
    }

    return '';
}

function build_transcript_content(string $filePath, string $fileName, string $apiKey): array
{
    $ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mime = mime_content_type($filePath) ?: 'application/octet-stream';

    $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif'];
    if (in_array($ext, $imageExts, true)) {
        $imageData = @file_get_contents($filePath);
        if ($imageData === false) {
            throw new RuntimeException('Unable to read image file.');
        }

        return [[
            'type'      => 'input_image',
            'image_url' => 'data:' . $mime . ';base64,' . base64_encode($imageData),
        ]];
    }

    if ($ext === 'pdf') {
        $fileId = upload_openai_file($filePath, $mime, $fileName, $apiKey);

        return [[
            'type'    => 'input_file',
            'file_id' => $fileId,
        ]];
    }

    throw new RuntimeException('Unsupported file type. Use PDF or image (JPG, PNG, WEBP).');
}

log_msg('===== REQUEST START =====');

require_once dirname(__DIR__) . '/helpers/env_bootstrap.php';

$API_KEY = pcvc_env('OPENAI_API_KEY');
if ($API_KEY === '') {
    log_msg('Missing OPENAI_API_KEY');
    json_exit(['status' => 'error', 'message' => 'OpenAI not configured. Set OPENAI_API_KEY in .env']);
}

$model = pcvc_env('OPENAI_MODEL');
if ($model === '') {
    $model = 'gpt-4o-mini';
}

log_msg($_FILES);

if (
    empty($_FILES['transcript']['tmp_name']) ||
    (int) ($_FILES['transcript']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
) {
    log_msg('File missing or upload error');
    json_exit(['status' => 'error', 'message' => 'Transcript file is required']);
}

$filePath = (string) $_FILES['transcript']['tmp_name'];
$fileName = (string) $_FILES['transcript']['name'];
$fileSize = (int) ($_FILES['transcript']['size'] ?? 0);

log_msg("File received: {$fileName} ({$fileSize} bytes)");

try {
    $documentContent = build_transcript_content($filePath, $fileName, $API_KEY);
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

$userContent = array_merge(
    [['type' => 'input_text', 'text' => $userPrompt]],
    $documentContent
);

$payload = [
    'model'       => $model,
    'temperature' => 0,
    'text'        => [
        'format' => ['type' => 'json_object'],
    ],
    'input'       => [
        [
            'role'    => 'system',
            'content' => [
                ['type' => 'input_text', 'text' => $systemPrompt],
            ],
        ],
        [
            'role'    => 'user',
            'content' => $userContent,
        ],
    ],
];

log_msg('Payload ready');

$data = call_responses_api($payload, $API_KEY);

log_msg('CURL response received');
log_msg($data);

if (isset($data['error'])) {
    $msg = (string) ($data['error']['message'] ?? 'OpenAI request failed');
    log_msg('API error: ' . $msg);
    json_exit(['status' => 'error', 'message' => $msg]);
}

$text = response_text($data);
if ($text === '') {
    log_msg('No text returned from model');
    json_exit(['status' => 'error', 'message' => 'No text returned from OpenAI']);
}

log_msg('MODEL TEXT:');
log_msg($text);

$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
$json = json_decode((string) $text, true);

if (!is_array($json) || empty($json['name'])) {
    log_msg('Invalid JSON output');
    json_exit(['status' => 'error', 'message' => 'Extraction failed — could not read transcript header']);
}

log_msg('===== SUCCESS =====');

json_exit([
    'status' => 'success',
    'data'   => $json,
]);

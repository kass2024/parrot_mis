<?php
declare(strict_types=1);

/* =========================
   DEBUG CONFIG
========================= */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

$LOG_FILE = __DIR__ . '/debug.log';

function log_msg($msg) {
    global $LOG_FILE;
    file_put_contents(
        $LOG_FILE,
        "[" . date('Y-m-d H:i:s') . "] " . print_r($msg, true) . PHP_EOL,
        FILE_APPEND
    );
}

header('Content-Type: application/json');
log_msg("===== REQUEST START =====");

require_once dirname(__DIR__) . '/helpers/env_bootstrap.php';
$API_KEY = pcvc_env('OPENAI_API_KEY');

/* =========================
   FILE CHECK
========================= */
log_msg($_FILES);

if (
    empty($_FILES['transcript']['tmp_name']) ||
    $_FILES['transcript']['error'] !== UPLOAD_ERR_OK
) {
    log_msg("File missing or upload error");
    exit(json_encode(['status'=>'error','message'=>'Transcript required']));
}

$filePath = $_FILES['transcript']['tmp_name'];
$fileName = $_FILES['transcript']['name'];
$fileSize = $_FILES['transcript']['size'];

log_msg("File received: $fileName ($fileSize bytes)");

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    log_msg("Invalid file type");
    exit(json_encode(['status'=>'error','message'=>'PDF only']));
}

/* =========================
   PDF → IMAGE (FIRST PAGE)
========================= */
$tmpImg = sys_get_temp_dir() . '/transcript_' . uniqid() . '.png';
$pdf    = escapeshellarg($filePath);
$img    = escapeshellarg($tmpImg);

$cmd = "gs -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r300 "
     . "-dFirstPage=1 -dLastPage=1 "
     . "-sOutputFile=$img $pdf";

log_msg("GS CMD: $cmd");
exec($cmd, $out, $rc);

log_msg("GS RC: $rc");
log_msg("GS OUT:");
log_msg($out);

if ($rc !== 0 || !file_exists($tmpImg)) {
    log_msg("Ghostscript failed");
    exit(json_encode(['status'=>'error','message'=>'PDF to image conversion failed']));
}

/* =========================
   IMAGE → BASE64
========================= */
$mime = 'image/png';
$b64  = base64_encode(file_get_contents($tmpImg));
unlink($tmpImg); // 🔥 AUTO CLEANUP

log_msg("Image converted and cleaned");

/* =========================
   OPENAI PAYLOAD
========================= */
$payload = [
    "model" => "gpt-4.1-mini",
    "temperature" => 0,
    "text" => [
        "format" => ["type" => "json_object"]
    ],
    "input" => [
        [
            "role" => "system",
            "content" => [
                [
                    "type" => "input_text",
                    "text" =>
                        "You are a STRICT academic transcript extractor.

                         IMPORTANT:
                         - The document is SCANNED.
                         - VISUALLY read ONLY THE FIRST PAGE.

                         Rules:
                         - Extract ONLY header information
                         - Do NOT infer or guess
                         - Ignore tables, grades, stamps, signatures
                         - Stop after the Class line
                         - Output VALID JSON ONLY"
                ]
            ]
        ],
        [
            "role" => "user",
            "content" => [
                [
                    "type" => "input_text",
                    "text" =>
                        "Return exactly:

                        {
                          \"name\": \"\",
                          \"surname\": \"\",
                          \"registration_number\": \"\",
                          \"academic_year_start\": \"\",
                          \"date_of_birth\": \"\",
                          \"program\": \"\",
                          \"class\": \"\"
                        }

                        Rules:
                        - Academic year: FIRST year only
                        - Class must be \"Bac I\" if shown"
                ],
                [
                    "type" => "input_image",
                    "image_url" => "data:$mime;base64,$b64"
                ]
            ]
        ]
    ]
];

log_msg("Payload ready");

/* =========================
   CURL CALL
========================= */
$ch = curl_init('https://api.openai.com/v1/responses');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $API_KEY",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload)
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
$info     = curl_getinfo($ch);
curl_close($ch);

log_msg("CURL INFO:");
log_msg($info);

if ($error) {
    log_msg("CURL ERROR: $error");
    exit(json_encode(['status'=>'error','message'=>'Curl failed']));
}

log_msg("RAW RESPONSE:");
log_msg($response);

/* =========================
   PARSE RESPONSE
========================= */
$data = json_decode($response, true);
$text = $data['output'][0]['content'][0]['text'] ?? null;

if (!$text) {
    log_msg("No text returned");
    exit(json_encode(['status'=>'error','message'=>'No text returned']));
}

log_msg("MODEL TEXT:");
log_msg($text);

$json = json_decode($text, true);

if (!$json || empty($json['name'])) {
    log_msg("Invalid JSON output");
    exit(json_encode(['status'=>'error','message'=>'Extraction failed']));
}

log_msg("===== SUCCESS =====");

/* =========================
   SUCCESS
========================= */
echo json_encode([
    'status' => 'success',
    'data'   => $json
], JSON_UNESCAPED_UNICODE);

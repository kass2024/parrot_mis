<?php
session_start();
require_once 'config_ai.php';

header('Content-Type: application/json');

// ===============================
// SECURITY
// ===============================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ===============================
// INPUT
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

$text = trim($input['text'] ?? '');

if ($text === '') {
    echo json_encode(['error' => 'Empty input']);
    exit;
}

// ===============================
// UTILS
// ===============================
function chunkText($text, $maxChars = 3500) {
    $chunks = [];
    $current = '';

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (strlen($current . $line) > $maxChars) {
            $chunks[] = $current;
            $current = '';
        }
        $current .= $line . "\n";
    }

    if (trim($current) !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

function extractJsonText(array $response): string {
    $out = '';
    foreach ($response['output'] ?? [] as $block) {
        foreach ($block['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'output_text') {
                $out .= $c['text'];
            }
        }
    }
    return $out;
}

// ===============================
// STRONG PROMPT (ANTI-COMPRESSION)
// ===============================
function buildPrompt(string $text): string {
    return <<<PROMPT
You are performing HIGH-ACCURACY DATA EXTRACTION.

TASK:
Extract EVERY academic program name exactly as written.

STRICT RULES (NO EXCEPTIONS):
- DO NOT summarize
- DO NOT merge similar programs
- DO NOT remove Fall / Spring / Intake variations
- Preserve wording exactly
- One program = one array item
- Extract ALL programs, even if more than 200
- Do NOT invent or guess programs
- Ignore headings, numbering, bullets

Accepted degrees include (but are not limited to):
BA, BSc, BEng, MA, MSc, MBA, MEng, PhD, Diploma, Certificate

Return ONLY valid JSON:

{
  "programs": [
    "Exact program name 1",
    "Exact program name 2"
  ]
}

TEXT:
$text
PROMPT;
}

// ===============================
// CHUNK INPUT (CRITICAL)
// ===============================
$chunks = chunkText($text);
$allPrograms = [];

// ===============================
// PROCESS EACH CHUNK
// ===============================
foreach ($chunks as $chunk) {

    $payload = [
        "model" => "gpt-4.1",
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" => "You extract university program names with absolute completeness."]
                ]
            ],
            [
                "role" => "user",
                "content" => [
                    ["type" => "input_text", "text" => buildPrompt($chunk)]
                ]
            ]
        ],
        "text" => [
            "format" => ["type" => "json_object"]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . AI_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 45
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) continue;

    // Debug log (keep this!)
    file_put_contents(
        __DIR__ . '/ai_program_debug.log',
        "\n==== " . date('Y-m-d H:i:s') . " ====\nCHUNK:\n$chunk\nAI RESPONSE:\n$response\n",
        FILE_APPEND
    );

    $decoded = json_decode($response, true);
    $jsonText = extractJsonText($decoded);
    $ai = json_decode($jsonText, true);

    if (!empty($ai['programs']) && is_array($ai['programs'])) {
        $allPrograms = array_merge($allPrograms, $ai['programs']);
    }
}

// ===============================
// STRONG FALLBACK (INTERNATIONAL)
// ===============================
if (count($allPrograms) === 0) {
    $lines = preg_split('/\r\n|\r|\n|,/', $text);

    foreach ($lines as $line) {
        $line = trim(preg_replace('/^[\d\.\-\•]+\s*/', '', $line));

        if (
            preg_match(
                '/\b(BA|BSc|BEng|MA|MSc|MBA|MEng|PhD|Diploma|Certificate)\b/i',
                $line
            )
            && strlen($line) > 6
        ) {
            $allPrograms[] = $line;
        }
    }
}

// ===============================
// FINAL NORMALIZATION
// ===============================
$allPrograms = array_values(array_unique(array_map('trim', $allPrograms)));

// ===============================
// RESPONSE
// ===============================
echo json_encode([
    'programs' => $allPrograms,
    'count'    => count($allPrograms),
    'fallback' => false
]);

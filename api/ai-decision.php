<?php
declare(strict_types=1);

/* ======================================================
   BOOTSTRAP & HEADERS
====================================================== */
ob_start();
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../helpers/db.php';
require_once "../helpers/response.php";
require_once __DIR__ . '/../helpers/env_bootstrap.php';

/* ======================================================
   GLOBAL EXCEPTION HANDLER
====================================================== */
set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Internal server error"
    ]);
    exit;
});

/* ======================================================
   INPUT VALIDATION
====================================================== */
$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
if (!$applicationId) {
    jsonResponse("Invalid application ID", false, 400);
}

/* ======================================================
   APPLICATION → COUNTRY CONTEXT
====================================================== */
$stmt = $conn->prepare("
    SELECT DISTINCT
        c.id   AS country_id,
        c.name AS country_name
    FROM application_study_choices ascx
    JOIN universities u ON u.id = ascx.university_id
    JOIN countries c ON c.id = u.country_id
    WHERE ascx.application_id = ?
");
$stmt->bind_param("i", $applicationId);
$stmt->execute();
$countries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$countries) {
    jsonResponse("No country found for this application", false, 404);
}

$countryName = $countries[0]['country_name'];

/* ======================================================
   LOAD PLATFORMS + REAL ADMINS
====================================================== */
$stmt = $conn->prepare("
    SELECT
        p.id AS platform_id,
        p.platform_name,
        p.platform_link,
        up.is_preferred,

        a.id AS admin_id,
        a.full_name,
        a.email,
        a.phone_number,
        a.role
    FROM universities u
    JOIN university_platforms up ON up.university_id = u.id
    JOIN platforms p ON p.id = up.platform_id
    JOIN admins a ON a.id = p.person_in_charge
    WHERE
        u.country_id = ?
        AND p.status = 'Active'
    ORDER BY
        up.is_preferred DESC,
        p.platform_name ASC
");

$platformMap = [];

foreach ($countries as $country) {
    $stmt->bind_param("i", $country['country_id']);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        // deterministic de-duplication by platform_id
        $platformMap[(int)$row['platform_id']] = $row;
    }
}

$platforms = array_values($platformMap);

/* ======================================================
   NO PLATFORMS — SAFE EMPTY RESPONSE
====================================================== */
if (!$platforms) {
    jsonResponse([
        "success" => true,
        "confidence" => 100,
        "country" => $countryName,
        "platforms" => [],
        "note" => "No platforms mapped to the applied country."
    ]);
}

/* ======================================================
   DEFAULT REASONS (ALWAYS PRESENT)
====================================================== */
foreach ($platforms as &$p) {
    $p['reason'] = "Official application platform for {$countryName}.";
}
unset($p);

/* ======================================================
   AI EXPLANATION (OPTIONAL, NON-BLOCKING)
====================================================== */
$confidence = 1.0;

$openaiKey = pcvc_env('OPENAI_API_KEY');
if ($openaiKey !== '') {
    try {
        $systemPrompt = <<<SYS
You are an international admissions officer.

Explain briefly why each platform is suitable for applications in this country.
Rules:
- Do not invent platforms
- One short factual sentence per platform
Return strict JSON only:
{
  "platforms": {
    "Platform Name": "Reason"
  },
  "confidence": 0.95
}
SYS;

        $payload = json_encode([
            "model" => "gpt-4o-mini",
            "temperature" => 0.1,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => json_encode([
                    "country" => $countryName,
                    "platforms" => array_column($platforms, 'platform_name')
                ])]
            ]
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$openaiKey}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $raw = curl_exec($ch);
        curl_close($ch);

        $ai = json_decode($raw, true);
        $content = json_decode($ai['choices'][0]['message']['content'] ?? '', true);

        if (!empty($content['platforms'])) {
            foreach ($platforms as &$p) {
                if (isset($content['platforms'][$p['platform_name']])) {
                    $p['reason'] = $content['platforms'][$p['platform_name']];
                }
            }
            unset($p);
        }

        if (isset($content['confidence'])) {
            $confidence = (float)$content['confidence'];
        }

    } catch (Throwable $e) {
        // AI failure is intentionally ignored
    }
}

/* ======================================================
   FINAL RESPONSE (FRONTEND-SAFE)
====================================================== */
ob_clean();
jsonResponse([
    "success" => true,
    "confidence" => (int)round($confidence * 100),
    "country" => $countryName,
    "platforms" => array_map(static function (array $p): array {
        return [
            "platform_id" => (int)$p['platform_id'],
            "platform_name" => $p['platform_name'],
            "platform_link" => $p['platform_link'],
            "is_preferred" => (bool)$p['is_preferred'],
            "person_in_charge" => [
                "id" => (int)$p['admin_id'],
                "full_name" => $p['full_name'],
                "email" => $p['email'],
                "phone_number" => $p['phone_number'],
                "role" => $p['role']
            ],
            "reason" => $p['reason']
        ];
    }, $platforms)
]);

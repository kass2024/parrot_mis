<?php
/**
 * Find Kyambogo University and other Ugandan degree/transcript files
 * on student_applications, then confirm them with Gemini.
 *
 *   php scan_uganda_degree_transcripts.php
 *   php scan_uganda_degree_transcripts.php --run --limit=25
 */
declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/env_bootstrap.php';
require_once __DIR__ . '/helpers/document_vision_gemini.php';
require_once __DIR__ . '/helpers/secure_file.php';

function ug_paths($raw): array
{
    if (is_array($raw)) {
        $paths = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $paths[] = trim($item);
            }
        }
        return $paths;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $trimmed = trim($raw);
    if ($trimmed[0] === '[') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return ug_paths($decoded);
        }
    }
    return [$trimmed];
}

function ug_signals(string $blob): array
{
    $patterns = [
        'kyambogo' => '/kyambogo/i',
        'uganda'   => '/\buganda(?:n)?\b/i',
        'kampala'  => '/\bkampala\b/i',
        'phone'    => '/(?:\+?256|0)7\d{8}\b/',
        'domain'   => '/\b[a-z0-9.-]+\.(?:ac|go|or|co)\.ug\b/i',
        'univ'     => '/\b(?:Makerere|Mbarara|Busitema|Gulu University|Ndejje|Uganda Christian|Uganda Martyrs|Kyambogo|Nkumba|Cavendish University Uganda)\b/i',
    ];
    $found = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $blob)) {
            $found[] = $label;
        }
    }
    return $found;
}

function ug_text(string $abs, string $name): string
{
    $blob = $name . "\n";
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if ($ext === 'pdf' && is_file($abs)) {
        $blob .= pcvc_docvision_pdf_extract_text($abs);
    }
    return $blob;
}

$opts = getopt('', ['run', 'limit:']);
$run = array_key_exists('run', $opts);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 20;

$sql = "SELECT id, first_name, last_name, email, nationality, country_of_birth,
        previous_institution_name, previous_institution_city, previous_institution_country,
        degree_transcripts
        FROM student_applications
        WHERE degree_transcripts IS NOT NULL
          AND TRIM(degree_transcripts) NOT IN ('', 'null', '[]', 'NULL')";
$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, $conn->error . PHP_EOL);
    exit(1);
}

$candidates = [];
$missing = 0;
$scanned = 0;
while ($row = $res->fetch_assoc()) {
    $profile = trim(implode(' ', [
        $row['nationality'] ?? '',
        $row['country_of_birth'] ?? '',
        $row['previous_institution_name'] ?? '',
        $row['previous_institution_city'] ?? '',
        $row['previous_institution_country'] ?? '',
    ]));
    foreach (ug_paths($row['degree_transcripts']) as $path) {
        $scanned++;
        $abs = pcvc_secure_file_resolve($path);
        $name = basename(str_replace('\\', '/', $path));
        $text = ($abs && is_file($abs)) ? ug_text($abs, $name) : $name;
        if (!$abs || !is_file($abs)) {
            $missing++;
        }
        $signals = ug_signals($profile . "\n" . $text);
        if ($signals === []) {
            continue;
        }
        $candidates[] = [
            'id' => (int) $row['id'],
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email' => (string) ($row['email'] ?? ''),
            'institution' => trim((string) ($row['previous_institution_name'] ?? '')),
            'city' => trim((string) ($row['previous_institution_city'] ?? '')),
            'file' => $path,
            'abs' => $abs,
            'signals' => $signals,
            'kyambogo' => in_array('kyambogo', $signals, true),
        ];
    }
}

echo "Degree files scanned: {$scanned}\n";
echo "Files missing on disk: {$missing}\n";
echo "Uganda-related candidates: " . count($candidates) . "\n";
$ky = array_values(array_filter($candidates, static fn ($c) => $c['kyambogo']));
echo "Kyambogo text matches: " . count($ky) . "\n\n";

$queue = $candidates;
usort($queue, static function ($a, $b) {
    return ($b['kyambogo'] <=> $a['kyambogo']);
});
if (!$run) {
    foreach (array_slice($queue, 0, 40) as $c) {
        echo "#{$c['id']} {$c['name']} | {$c['institution']} {$c['city']} | " . implode(',', $c['signals']) . " | {$c['file']}\n";
    }
    echo "\nDry list only. Re-run with --run to ask Gemini.\n";
    exit(0);
}

$prompt = <<<'PROMPT'
You are an OCR analyst for university degree certificates and academic transcripts.
Read the full document, including scans, stamps, and letterheads.
Decide if it was issued in Uganda or by a Ugandan institution.
Mark is_kyambogo=true only when the issuer is Kyambogo University in Kampala, Uganda.
Return ONLY JSON:
{
  "is_degree_or_transcript": true,
  "document_type": "degree|transcript|diploma|certificate|other",
  "is_uganda_related": false,
  "is_ugandan_university": false,
  "is_kyambogo": false,
  "university_name": "",
  "university_city": "",
  "country": "",
  "student_name": "",
  "program_or_degree": "",
  "confidence": 0.0,
  "summary": ""
}
PROMPT;

$done = 0;
foreach (array_slice($queue, 0, $limit) as $c) {
    if (!$c['abs'] || !is_file($c['abs'])) {
        echo "MISSING #{$c['id']} {$c['file']}\n";
        continue;
    }
    $cleanup = [];
    $ext = strtolower(pathinfo($c['abs'], PATHINFO_EXTENSION));
    $blocks = [['type' => 'input_text', 'text' => 'File: ' . basename($c['abs']) . "\nApplicant: {$c['name']}\nLocal signals: " . implode(', ', $c['signals'])]];
    try {
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || ($ext === 'pdf' && mb_strlen(pcvc_docvision_pdf_extract_text($c['abs'])) < 80)) {
            $blocks = array_merge($blocks, pcvc_docvision_build_document_content($c['abs'], basename($c['abs']), $cleanup, 4, 150));
        } else {
            $raw = (string) file_get_contents($c['abs']);
            if ($ext === 'pdf' && $raw !== '') {
                $blocks[] = ['type' => 'input_pdf', 'mime' => 'application/pdf', 'data' => base64_encode($raw)];
            } else {
                $blocks[0]['text'] .= "\n\n" . mb_substr(ug_text($c['abs'], basename($c['abs'])), 0, 12000);
            }
        }
        $result = pcvc_docvision_generate_json($prompt, $blocks, 2, 600, 0.0);
    } catch (Throwable $e) {
        echo "ERROR #{$c['id']} {$e->getMessage()}\n";
        continue;
    } finally {
        foreach ($cleanup as $tmp) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
    $json = is_array($result['json'] ?? null) ? $result['json'] : [];
    $done++;
    $flag = !empty($json['is_kyambogo']) ? 'KYAMBOGO' : (!empty($json['is_uganda_related']) || !empty($json['is_ugandan_university']) ? 'UGANDA' : 'other');
    echo "{$flag} #{$c['id']} {$c['name']} | " . ($json['university_name'] ?? '') . ' | ' . ($json['university_city'] ?? '') . ' | ' . ($json['document_type'] ?? '') . ' | ' . ($json['program_or_degree'] ?? '') . ' | ' . ($json['summary'] ?? ($result['error']['message'] ?? '')) . "\n";
}
echo "\nGemini checked: {$done}\n";

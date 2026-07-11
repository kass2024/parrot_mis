<?php
/**
 * scan_tanzania_degree_transcripts.php
 *
 * Admin tool: browse all uploaded degree / transcript documents in the MIS,
 * analyze each with Gemini (GEMINI_API_KEY or GOOGLE_AI_API_KEY from .env),
 * and flag documents issued by Tanzanian universities.
 *
 * Usage (browser):
 *   /scan_tanzania_degree_transcripts.php              ← safe preview (no Gemini calls)
 *   /scan_tanzania_degree_transcripts.php?run=1&limit=10
 *   /scan_tanzania_degree_transcripts.php?format=json&dry_run=1
 *
 * Usage (CLI):
 *   php scan_tanzania_degree_transcripts.php
 *   php scan_tanzania_degree_transcripts.php --limit=50 --only-tanzania
 */

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/env_bootstrap.php';
require_once __DIR__ . '/helpers/document_vision_gemini.php';
require_once __DIR__ . '/helpers/secure_file.php';

const TANZANIA_SCAN_LOG = __DIR__ . '/logs/tanzania_degree_scan.log';
const TANZANIA_SCAN_DEFAULT_LIMIT = 10;
const TANZANIA_SCAN_MAX_LIMIT = 30;

function tanzania_scan_is_cli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

/** @return array<string, mixed> */
function tanzania_scan_options(): array
{
    if (tanzania_scan_is_cli()) {
        $opts = getopt('', ['limit:', 'only-tanzania', 'format:', 'application-id:', 'source-table:', 'dry-run', 'run']);
        $dryRun = array_key_exists('dry-run', $opts);
        if (!$dryRun && !array_key_exists('run', $opts)) {
            $dryRun = true;
        }
        $limit = isset($opts['limit']) ? max(1, min(TANZANIA_SCAN_MAX_LIMIT, (int) $opts['limit'])) : TANZANIA_SCAN_DEFAULT_LIMIT;
        if ($dryRun) {
            $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
        }
        return [
            'limit'          => $limit,
            'only_tanzania'  => array_key_exists('only-tanzania', $opts),
            'format'         => (string) ($opts['format'] ?? 'html'),
            'application_id' => isset($opts['application-id']) ? (int) $opts['application-id'] : 0,
            'source_table'   => trim((string) ($opts['source-table'] ?? '')),
            'dry_run'        => $dryRun,
            'run'            => !$dryRun,
            'total_found'    => 0,
        ];
    }

    $run = !empty($_GET['run']);
    $dryRun = !$run || !empty($_GET['dry_run']);
    $limit = isset($_GET['limit']) ? max(1, min(TANZANIA_SCAN_MAX_LIMIT, (int) $_GET['limit'])) : TANZANIA_SCAN_DEFAULT_LIMIT;
    if ($dryRun && !isset($_GET['limit'])) {
        $limit = 50;
    }

    return [
        'limit'          => $limit,
        'only_tanzania'  => !empty($_GET['only_tanzania']),
        'format'         => strtolower(trim((string) ($_GET['format'] ?? 'html'))),
        'application_id' => isset($_GET['application_id']) ? (int) $_GET['application_id'] : 0,
        'source_table'   => trim((string) ($_GET['source_table'] ?? '')),
        'dry_run'        => $dryRun,
        'run'            => $run && !$dryRun,
        'total_found'    => 0,
    ];
}

function tanzania_scan_log(string $message): void
{
    $dir = dirname(TANZANIA_SCAN_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(
        TANZANIA_SCAN_LOG,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function tanzania_scan_system_prompt(): string
{
    return <<<'PROMPT'
You are an academic document analyst for university admissions screening in East Africa.

Analyze the uploaded document and determine whether it is a degree certificate, diploma, or academic transcript,
and whether it was issued by a university or college in Tanzania (United Republic of Tanzania).

Known Tanzanian institutions include (not exhaustive):
University of Dar es Salaam (UDSM), University of Dodoma (UDOM), Sokoine University of Agriculture (SUA),
Ardhi University, Muhimbili University of Health and Allied Sciences, Open University of Tanzania,
Nelson Mandela African Institution of Science and Technology (NM-AIST), State University of Zanzibar (SUZA),
Catholic University of Health and Allied Sciences (CUHAS), Hubert Kairuki Memorial University,
St. Augustine University of Tanzania, University of Iringa, Teofilo Kisanji University, etc.

Use visible text, logos, seals, letterheads, Swahili/English wording, cities (Dar es Salaam, Dodoma, Morogoro, etc.),
and "United Republic of Tanzania" references. Do NOT require online verification.

Return ONLY valid JSON with this exact schema:
{
  "is_degree_or_transcript": true or false,
  "document_type": "degree|transcript|diploma|certificate|other",
  "is_tanzania_university": true or false,
  "university_name": "",
  "university_city": "",
  "country": "",
  "student_name": "",
  "program_or_degree": "",
  "graduation_or_issue_date": "",
  "appears_authentic": true or false,
  "confidence": 0.0,
  "summary": "",
  "issues": []
}
PROMPT;
}

function tanzania_scan_user_instruction(): string
{
    return 'Classify this document. If it is not a university degree or academic transcript, set is_degree_or_transcript=false and is_tanzania_university=false.';
}

function tanzania_scan_resolve_abs_path(string $storedPath): ?string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') {
        return null;
    }

    return pcvc_secure_file_resolve($storedPath);
}

/** @param mixed $raw */
function tanzania_scan_paths_from_value($raw): array
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
            return tanzania_scan_paths_from_value($decoded);
        }
    }

    return [$trimmed];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tanzania_scan_collect_from_db(mysqli $conn, array $options): array
{
    $sources = [
        'student_applications' => [
            'id_col'   => 'id',
            'name_sql' => "TRIM(CONCAT_WS(' ', first_name, last_name))",
            'email_col'=> 'email',
            'fields'   => [
                'degree_transcripts' => 'Degree / Transcripts',
                'high_school_degree' => 'High School Degree',
            ],
        ],
        'credit_transfer_applications' => [
            'id_col'   => 'id',
            'name_sql' => "TRIM(CONCAT_WS(' ', first_name, middle_name, last_name))",
            'email_col'=> 'email',
            'fields'   => [
                'current_degree'      => 'Current Degree',
                'current_transcripts' => 'Current Transcripts',
            ],
        ],
        'master_loan_applications' => [
            'id_col'   => 'id',
            'name_sql' => "TRIM(CONCAT_WS(' ', first_name, last_name))",
            'email_col'=> 'email',
            'fields'   => [
                'bachelor_degree'     => 'Bachelor Degree',
                'bachelor_transcript' => 'Bachelor Transcript',
            ],
        ],
    ];

    $filterTable = $options['source_table'] ?? '';
    if ($filterTable !== '') {
        if (!isset($sources[$filterTable])) {
            throw new RuntimeException('Unknown source_table: ' . $filterTable);
        }
        $sources = [$filterTable => $sources[$filterTable]];
    }

    $items = [];
    $seen = [];

    foreach ($sources as $table => $cfg) {
        $check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$check || $check->num_rows === 0) {
            continue;
        }

        $fieldList = array_keys($cfg['fields']);
        $selectFields = implode(', ', array_map(static fn ($f) => "`$f`", $fieldList));
        $sql = "SELECT `{$cfg['id_col']}` AS record_id, {$cfg['name_sql']} AS applicant_name, `{$cfg['email_col']}` AS applicant_email, $selectFields FROM `$table`";
        $params = [];
        $types = '';

        if (!empty($options['application_id']) && $table === ($options['source_table'] ?: 'student_applications')) {
            $sql .= ' WHERE `' . $cfg['id_col'] . '` = ?';
            $params[] = (int) $options['application_id'];
            $types .= 'i';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            foreach ($cfg['fields'] as $column => $label) {
                foreach (tanzania_scan_paths_from_value($row[$column] ?? null) as $path) {
                    $key = md5($table . '|' . $path);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $items[] = [
                        'source_table'   => $table,
                        'record_id'      => (int) ($row['record_id'] ?? 0),
                        'field'          => $column,
                        'field_label'    => $label,
                        'stored_path'    => $path,
                        'applicant_name' => trim((string) ($row['applicant_name'] ?? '')),
                        'applicant_email'=> trim((string) ($row['applicant_email'] ?? '')),
                    ];
                }
            }
        }
        $stmt->close();
    }

    return $items;
}

/** @return array<string, mixed> */
function tanzania_scan_analyze_one(array $item): array
{
    $abs = tanzania_scan_resolve_abs_path((string) $item['stored_path']);
    if ($abs === null) {
        return [
            'ok'    => false,
            'error' => 'File missing or outside uploads/',
            'item'  => $item,
        ];
    }

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        return [
            'ok'    => false,
            'error' => 'Unsupported file type: ' . $ext,
            'item'  => $item,
        ];
    }

    $cleanup = [];
    try {
        $userContent = pcvc_docvision_build_analysis_content(
            $abs,
            basename($abs),
            $cleanup,
            tanzania_scan_user_instruction(),
            '',
            4,
            168
        );

        $result = pcvc_docvision_generate_json(tanzania_scan_system_prompt(), $userContent, 2, 600, 0.0);
    } catch (Throwable $e) {
        return [
            'ok'    => false,
            'error' => $e->getMessage(),
            'item'  => $item,
        ];
    } finally {
        foreach ($cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    if (!empty($result['error'])) {
        return [
            'ok'    => false,
            'error' => (string) ($result['error']['message'] ?? 'Gemini error'),
            'item'  => $item,
        ];
    }

    $json = is_array($result['json'] ?? null) ? $result['json'] : [];

    return [
        'ok'       => true,
        'item'     => $item,
        'analysis' => $json,
        'model'    => pcvc_docvision_model(),
        'file'     => [
            'absolute' => $abs,
            'size'     => filesize($abs) ?: 0,
            'mtime'    => filemtime($abs) ?: 0,
        ],
    ];
}

function tanzania_scan_is_tanzania_match(array $analysis): bool
{
    return !empty($analysis['is_tanzania_university'])
        && !empty($analysis['is_degree_or_transcript']);
}

/** @param array<int, array<string, mixed>> $results */
function tanzania_scan_render_html(array $report): void
{
    header('Content-Type: text/html; charset=utf-8');
    $results = $report['results'] ?? [];
    $stats = $report['stats'] ?? [];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tanzania Degree &amp; Transcript Scan</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;margin:24px;background:#f8fafc;color:#0f172a}
h1{margin:0 0 8px;font-size:24px}
.meta{color:#64748b;margin-bottom:20px;font-size:14px}
.stats{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px}
.stat{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;min-width:140px}
.stat strong{display:block;font-size:22px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
th,td{padding:10px 12px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top;font-size:13px}
th{background:#f1f5f9;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.tag-yes{background:#dcfce7;color:#166534}
.tag-no{background:#fee2e2;color:#991b1b}
.tag-warn{background:#fef3c7;color:#92400e}
.err{color:#b91c1c;font-size:12px}
.summary{max-width:320px;line-height:1.45}
a{color:#2563eb}
.notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:14px;line-height:1.5}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.actions a{display:inline-block;background:#2563eb;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600}
.actions a.secondary{background:#64748b}
</style>
</head>
<body>
<h1>Tanzania university degree &amp; transcript scan</h1>
<p class="meta">Gemini model: <?= htmlspecialchars((string) ($report['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
 · Scanned at <?= htmlspecialchars((string) ($report['scanned_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>

<?php if (!empty($report['options']['dry_run'])): ?>
<div class="notice">
  <strong>Preview mode.</strong> No Gemini API calls were made (safe for the server).
  Found <?= (int) ($report['stats']['total_in_db'] ?? 0) ?> document(s) in the database.
  Use the buttons below to analyze a small batch only.
</div>
<div class="actions">
  <a href="?run=1&limit=10">Analyze 10 with Gemini</a>
  <a href="?run=1&limit=20">Analyze 20 with Gemini</a>
  <a href="?run=1&limit=10&only_tanzania=1">Analyze 10 (Tanzania only)</a>
  <a class="secondary" href="/">Back to MIS home</a>
</div>
<?php else: ?>
<div class="notice">
  Gemini analysis ran on <?= (int) ($report['stats']['analyzed'] ?? 0) ?> file(s)
  (limit <?= (int) ($report['options']['limit'] ?? 0) ?>).
</div>
<div class="actions">
  <a class="secondary" href="?">Back to safe preview</a>
  <a class="secondary" href="/">MIS home</a>
</div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><strong><?= (int) ($stats['total'] ?? 0) ?></strong>Documents found</div>
  <div class="stat"><strong><?= (int) ($stats['analyzed'] ?? 0) ?></strong>Analyzed</div>
  <div class="stat"><strong><?= (int) ($stats['tanzania_matches'] ?? 0) ?></strong>Tanzania university</div>
  <div class="stat"><strong><?= (int) ($stats['errors'] ?? 0) ?></strong>Errors</div>
</div>

<table>
<thead>
<tr>
  <th>Applicant</th>
  <th>Source</th>
  <th>Document</th>
  <th>Type</th>
  <th>Tanzania</th>
  <th>University</th>
  <th>Authentic</th>
  <th>Summary</th>
</tr>
</thead>
<tbody>
<?php foreach ($results as $row): ?>
<?php
    $item = $row['item'] ?? [];
    $analysis = $row['analysis'] ?? [];
    $isTz = tanzania_scan_is_tanzania_match($analysis);
?>
<tr>
  <td>
    <?= htmlspecialchars((string) ($item['applicant_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
    <small><?= htmlspecialchars((string) ($item['applicant_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
  </td>
  <td>
    <?= htmlspecialchars((string) ($item['source_table'] ?? ''), ENT_QUOTES, 'UTF-8') ?> #<?= (int) ($item['record_id'] ?? 0) ?><br>
    <small><?= htmlspecialchars((string) ($item['field_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
  </td>
  <td>
    <?php if (!empty($item['stored_path'])): ?>
    <a href="<?= htmlspecialchars(pcvc_secure_file_url((string) $item['stored_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
      <?= htmlspecialchars(basename((string) $item['stored_path']), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>
    <?php if (!empty($row['error'])): ?><div class="err"><?= htmlspecialchars((string) $row['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  </td>
  <td><?= htmlspecialchars((string) ($analysis['document_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
  <td>
    <?php if (($row['ok'] ?? false) && $isTz): ?>
      <span class="tag tag-yes">Yes</span>
    <?php elseif ($row['ok'] ?? false): ?>
      <span class="tag tag-no">No</span>
    <?php else: ?>
      <span class="tag tag-warn">—</span>
    <?php endif; ?>
  </td>
  <td><?= htmlspecialchars((string) ($analysis['university_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
  <td>
    <?php if (!empty($analysis['appears_authentic'])): ?>
      <span class="tag tag-yes">Yes</span>
    <?php elseif ($row['ok'] ?? false): ?>
      <span class="tag tag-no">No</span>
    <?php else: ?>—<?php endif; ?>
  </td>
  <td class="summary"><?= htmlspecialchars((string) ($analysis['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
<?php
}

// ── Main ──────────────────────────────────────────────────────────────────

$options = tanzania_scan_options();

if (!pcvc_docvision_is_configured()) {
    $msg = 'Gemini is not configured. Set GEMINI_API_KEY or GOOGLE_AI_API_KEY in .env.';
    if ($options['format'] === 'json' || tanzania_scan_is_cli()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $msg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(503);
        echo $msg;
    }
    exit(1);
}

try {
    $catalog = tanzania_scan_collect_from_db($conn, $options);
} catch (Throwable $e) {
    $payload = ['ok' => false, 'message' => $e->getMessage()];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$totalInDb = count($catalog);
$options['total_found'] = $totalInDb;

if ($options['limit'] > 0) {
    $catalog = array_slice($catalog, 0, $options['limit']);
}

$results = [];
$stats = [
    'total'            => count($catalog),
    'total_in_db'      => $totalInDb,
    'analyzed'         => 0,
    'tanzania_matches' => 0,
    'errors'           => 0,
];

if ($options['dry_run']) {
    foreach ($catalog as $item) {
        $results[] = ['ok' => true, 'item' => $item, 'analysis' => ['summary' => 'Dry run — not sent to Gemini.']];
    }
} else {
    foreach ($catalog as $item) {
        tanzania_scan_log('Analyzing ' . ($item['stored_path'] ?? '') . ' [' . ($item['source_table'] ?? '') . '#' . ($item['record_id'] ?? '') . ']');
        $row = tanzania_scan_analyze_one($item);

        if (!($row['ok'] ?? false)) {
            $stats['errors']++;
            $results[] = $row;
            continue;
        }

        $stats['analyzed']++;
        $analysis = is_array($row['analysis'] ?? null) ? $row['analysis'] : [];

        if ($options['only_tanzania'] && !tanzania_scan_is_tanzania_match($analysis)) {
            continue;
        }

        if (tanzania_scan_is_tanzania_match($analysis)) {
            $stats['tanzania_matches']++;
        }

        $results[] = $row;
    }
}

$report = [
    'ok'         => true,
    'model'      => pcvc_docvision_model(),
    'scanned_at' => gmdate('c'),
    'options'    => $options,
    'stats'      => $stats,
    'results'    => $results,
];

if ($options['format'] === 'json' || tanzania_scan_is_cli()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

tanzania_scan_render_html($report);

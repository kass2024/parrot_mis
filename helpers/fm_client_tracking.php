<?php
declare(strict_types=1);

/**
 * Francophonie Mobility — Client tracking checklist (matches Clients tracking.xlsx).
 * Document groups stay as folders; Gemini maps files inside those folders to checklist items.
 */

require_once __DIR__ . '/francophonie_mobility_documents.php';
require_once __DIR__ . '/francophonie_mobility_files.php';
require_once __DIR__ . '/document_vision_gemini.php';
require_once __DIR__ . '/secure_file.php';

/**
 * Checklist columns from Clients tracking.xlsx (order preserved).
 *
 * @return array<string, string> slug => display label
 */
function fm_tracking_checklist(): array
{
    return [
        'questionnaire' => 'Questionnaire',
        'employment_letter' => 'Employment letter',
        'police_certificate' => 'Police certificate',
        'passport' => 'Passport',
        'photo' => 'Photo',
        'french_test' => 'French test',
        'diplomas' => 'Diplomas',
        'visa_resume' => 'Visa Resume',
        'form_1295' => '1295 form',
        'form_4645' => '4645 form',
        'marriage_cert' => 'Marriage cert',
        'child_birth_certs' => 'Child birth certs',
        'bank_deposit' => 'Bank deposit',
        'job_offer' => 'Job offer',
    ];
}

function fm_ensure_tracking_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $sql = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_tracking_scan` (
      `application_id` int(11) NOT NULL,
      `fingerprint` varchar(64) NOT NULL DEFAULT '',
      `matches_json` longtext NOT NULL,
      `source` varchar(16) NOT NULL DEFAULT 'heuristic',
      `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`application_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('fm_ensure_tracking_schema: ' . $conn->error);
    }
}

/**
 * Inventory of files in Attached + Visa groups + public-form uploads (read-only).
 *
 * @param array<string, mixed> $app
 * @return list<array{id:string,group:string,name:string,path:string}>
 */
function fm_tracking_file_inventory(mysqli $conn, array $app): array
{
    $appId = (int) ($app['id'] ?? 0);
    $out = [];

    foreach (fm_list_documents($conn, $appId) as $doc) {
        $path = trim((string) ($doc['stored_path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $out[] = [
            'id' => 'doc:' . (int) ($doc['id'] ?? 0),
            'group' => (string) ($doc['doc_group'] ?? 'attached'),
            'name' => (string) ($doc['original_name'] ?: basename($path)),
            'path' => $path,
        ];
    }

    $clientFields = [
        'cv_file' => ['group' => 'resume', 'label' => 'CV (client form)'],
        'french_cert_file' => ['group' => 'proof_of_meeting_requirements', 'label' => 'French certificate (client form)'],
        'english_cert_file' => ['group' => 'proof_of_meeting_requirements', 'label' => 'English certificate (client form)'],
    ];
    foreach ($clientFields as $field => $meta) {
        $path = trim((string) ($app[$field] ?? ''));
        if ($path === '') {
            continue;
        }
        $out[] = [
            'id' => 'client:' . $field,
            'group' => $meta['group'],
            'name' => $meta['label'],
            'path' => $path,
        ];
    }

    $academic = fm_parse_stored_files((string) ($app['academic_docs_file'] ?? ''));
    foreach ($academic as $i => $path) {
        $path = trim((string) $path);
        if ($path === '') {
            continue;
        }
        $out[] = [
            'id' => 'client:academic_docs_file:' . $i,
            'group' => 'proof_of_meeting_requirements',
            'name' => 'Academic document ' . ($i + 1) . ' (client form)',
            'path' => $path,
        ];
    }

    return $out;
}

function fm_tracking_fingerprint(array $inventory): string
{
    $parts = [];
    foreach ($inventory as $row) {
        $parts[] = ($row['id'] ?? '') . '|' . ($row['group'] ?? '') . '|' . ($row['name'] ?? '') . '|' . ($row['path'] ?? '');
    }
    sort($parts);
    return hash('sha256', implode("\n", $parts));
}

/**
 * Fast keyword / folder heuristics (used when Gemini unavailable or as baseline).
 *
 * @param list<array{id:string,group:string,name:string,path:string}> $inventory
 * @return array<string, array{id:string,group:string,name:string,path:string}|null>
 */
function fm_tracking_match_heuristic(array $inventory): array
{
    $checklist = fm_tracking_checklist();
    $matches = [];
    foreach (array_keys($checklist) as $key) {
        $matches[$key] = null;
    }

    $rules = [
        'questionnaire' => ['groups' => ['questionnaire'], 'names' => ['questionnaire', 'questionaire', 'qnaire']],
        'employment_letter' => ['groups' => ['employment_documents'], 'names' => ['employment', 'employer', 'work letter', 'lettre']],
        'police_certificate' => ['groups' => ['proof_of_meeting_requirements'], 'names' => ['police', 'casier', 'certificate of good', 'good conduct']],
        'passport' => ['groups' => ['passport'], 'names' => ['passport', 'passeport']],
        'photo' => ['groups' => ['photo'], 'names' => ['photo', 'picture', 'portrait', 'headshot']],
        'french_test' => ['groups' => ['proof_of_meeting_requirements'], 'names' => ['french', 'tef', 'tcf', 'delf', 'dalf', 'francais', 'français']],
        'diplomas' => ['groups' => ['proof_of_meeting_requirements', 'client_information'], 'names' => ['diploma', 'degree', 'academic', 'transcript', 'certificate']],
        'visa_resume' => ['groups' => ['resume'], 'names' => ['resume', 'cv', 'curriculum']],
        'form_1295' => ['groups' => ['imm1295_form'], 'names' => ['1295', 'imm1295', 'imm 1295']],
        'form_4645' => ['groups' => ['imm5645_family_information_form'], 'names' => ['4645', '5645', 'imm5645', 'imm 5645', 'family information']],
        'marriage_cert' => ['groups' => ['client_information', 'imm5645_family_information_form'], 'names' => ['marriage', 'mariage', 'wedding']],
        'child_birth_certs' => ['groups' => ['client_information', 'imm5645_family_information_form'], 'names' => ['birth', 'naissance', 'child']],
        'bank_deposit' => ['groups' => ['login_information', 'proof_of_meeting_requirements', 'client_information'], 'names' => ['bank', 'deposit', 'payment', 'receipt', 'wire']],
        'job_offer' => ['groups' => ['job_offer'], 'names' => ['job offer', 'offer of employment', 'lmia', 'offre']],
    ];

    foreach ($inventory as $file) {
        $group = strtolower((string) ($file['group'] ?? ''));
        $name = strtolower((string) ($file['name'] ?? '') . ' ' . basename((string) ($file['path'] ?? '')));
        foreach ($rules as $key => $rule) {
            if ($matches[$key] !== null) {
                continue;
            }
            $hit = in_array($group, $rule['groups'], true);
            if (!$hit) {
                foreach ($rule['names'] as $needle) {
                    if ($needle !== '' && str_contains($name, $needle)) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                $matches[$key] = $file;
            }
        }
    }

    return $matches;
}

/**
 * Ask Gemini to map folder files onto the Excel checklist.
 *
 * @param list<array{id:string,group:string,name:string,path:string}> $inventory
 * @return array{matches: array<string, array{id:string,group:string,name:string,path:string}|null>, source: string, error?: string}
 */
function fm_tracking_match_gemini(array $inventory): array
{
    $checklist = fm_tracking_checklist();
    $fallback = fm_tracking_match_heuristic($inventory);

    if ($inventory === []) {
        return ['matches' => $fallback, 'source' => 'heuristic'];
    }

    if (!pcvc_docvision_is_configured()) {
        return ['matches' => $fallback, 'source' => 'heuristic', 'error' => 'Gemini API key not configured'];
    }

    $keys = array_keys($checklist);
    $labels = [];
    foreach ($checklist as $k => $lab) {
        $labels[] = $k . ' = ' . $lab;
    }

    $fileLines = [];
    foreach ($inventory as $f) {
        $fileLines[] = sprintf(
            '- id=%s | folder=%s | filename=%s',
            $f['id'],
            $f['group'],
            $f['name']
        );
    }

    $system = 'You are classifying visa submission package files for Francophonie Mobility client tracking. '
        . 'Document groups behave like folders and may contain multiple files. '
        . 'Map each checklist item to at most one file id from the inventory when that file clearly belongs to the item. '
        . 'Excel uses "4645 form" for the family form that may be labeled IMM5645. '
        . 'Return JSON only: {"matches":{"checklist_key":"file_id_or_null",...}} using every checklist key. '
        . 'Use null when missing. Do not invent file ids.';

    $userText = "Checklist keys:\n" . implode("\n", $labels)
        . "\n\nFiles in folders:\n" . implode("\n", $fileLines)
        . "\n\nReturn JSON with matches for all keys: " . implode(', ', $keys);

    $result = pcvc_docvision_generate_json($system, [
        ['type' => 'input_text', 'text' => $userText],
    ], 2, 400, 0.0);

    if (!empty($result['error'])) {
        return [
            'matches' => $fallback,
            'source' => 'heuristic',
            'error' => (string) ($result['error']['message'] ?? 'Gemini failed'),
        ];
    }

    $json = $result['json'] ?? null;
    $rawMatches = [];
    if (is_array($json)) {
        $rawMatches = $json['matches'] ?? $json;
    }
    if (!is_array($rawMatches)) {
        return ['matches' => $fallback, 'source' => 'heuristic', 'error' => 'Invalid Gemini JSON'];
    }

    $byId = [];
    foreach ($inventory as $f) {
        $byId[$f['id']] = $f;
    }

    $matches = [];
    foreach ($keys as $key) {
        $val = $rawMatches[$key] ?? null;
        if (is_array($val)) {
            $val = $val['id'] ?? $val['file_id'] ?? null;
        }
        if (!is_string($val) || $val === '' || strtolower($val) === 'null') {
            $matches[$key] = $fallback[$key] ?? null;
            continue;
        }
        $matches[$key] = $byId[$val] ?? ($fallback[$key] ?? null);
    }

    return ['matches' => $matches, 'source' => 'gemini'];
}

/**
 * @return array<string, array{id:string,group:string,name:string,path:string}|null>|null
 */
function fm_tracking_load_cached(mysqli $conn, int $applicationId, string $fingerprint): ?array
{
    $st = $conn->prepare(
        'SELECT fingerprint, matches_json FROM francophonie_mobility_tracking_scan WHERE application_id = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $applicationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || (string) ($row['fingerprint'] ?? '') !== $fingerprint) {
        return null;
    }
    $decoded = json_decode((string) ($row['matches_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, array{id:string,group:string,name:string,path:string}|null> $matches
 */
function fm_tracking_save_cache(
    mysqli $conn,
    int $applicationId,
    string $fingerprint,
    array $matches,
    string $source
): void {
    $json = json_encode($matches, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $st = $conn->prepare(
        'INSERT INTO francophonie_mobility_tracking_scan (application_id, fingerprint, matches_json, source, scanned_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint), matches_json = VALUES(matches_json),
           source = VALUES(source), scanned_at = NOW()'
    );
    if (!$st) {
        return;
    }
    $st->bind_param('isss', $applicationId, $fingerprint, $json, $source);
    $st->execute();
    $st->close();
}

/**
 * Resolve matches for one application (cache → Gemini → heuristic).
 *
 * @param array<string, mixed> $app
 * @return array{matches: array<string, array{id:string,group:string,name:string,path:string}|null>, source: string, error?: string}
 */
function fm_tracking_resolve_for_app(mysqli $conn, array $app, bool $forceGemini = false): array
{
    $appId = (int) ($app['id'] ?? 0);
    $inventory = fm_tracking_file_inventory($conn, $app);
    $fp = fm_tracking_fingerprint($inventory);

    if (!$forceGemini) {
        $cached = fm_tracking_load_cached($conn, $appId, $fp);
        if ($cached !== null) {
            return ['matches' => $cached, 'source' => 'cache'];
        }
        // Fast path: folder/filename heuristics. Use "Rescan with Gemini" for AI classification.
        $result = ['matches' => fm_tracking_match_heuristic($inventory), 'source' => 'heuristic'];
        fm_tracking_save_cache($conn, $appId, $fp, $result['matches'], 'heuristic');
        return $result;
    }

    $result = fm_tracking_match_gemini($inventory);

    fm_tracking_save_cache(
        $conn,
        $appId,
        $fp,
        $result['matches'],
        (string) ($result['source'] ?? 'heuristic')
    );

    return $result;
}

function fm_tracking_cell_urls(?array $file): array
{
    if ($file === null || empty($file['path'])) {
        return ['view' => '', 'download' => ''];
    }
    $path = (string) $file['path'];
    $name = (string) ($file['name'] ?? 'document');
    return [
        'view' => pcvc_secure_file_url($path, ['inline' => true, 'name' => $name]),
        'download' => pcvc_secure_file_url($path, ['name' => $name]),
    ];
}

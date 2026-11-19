<?php
declare(strict_types=1);

/**
 * Daily and weekly job percentages, plus a short human note on what to do next.
 */

require_once __DIR__ . '/document_vision_gemini.php';
require_once __DIR__ . '/attendance_checkout.php';

/**
 * Monday–Saturday of the week that contains $date, stopping at $date.
 *
 * @return array{start:string,end:string,days:list<string>,label:string,day_count:int}
 */
function pcvc_perf_week(string $date): array
{
    $dt = new DateTime($date, new DateTimeZone('Africa/Kigali'));
    $monday = (clone $dt)->modify('-' . ((int) $dt->format('N') - 1) . ' days');
    $days = [];
    for ($i = 0; $i < 6; $i++) {
        $day = (clone $monday)->modify('+' . $i . ' days')->format('Y-m-d');
        if ($day > $date) {
            break;
        }
        $days[] = $day;
    }
    $end = $days === [] ? $date : $days[count($days) - 1];

    return [
        'start' => $monday->format('Y-m-d'),
        'end' => $end,
        'days' => $days,
        'day_count' => count($days),
        'label' => $monday->format('j M') . ' – ' . (new DateTime($end))->format('j M Y'),
    ];
}

function pcvc_perf_percent(int $done, int $target): int
{
    if ($target < 1) {
        return 0;
    }

    return (int) round(($done / $target) * 100);
}

/**
 * @return array{code:string,label:string,tone:string,text:string}
 */
function pcvc_perf_decision(int $percent, bool $linked): array
{
    if (!$linked) {
        return [
            'code' => 'unlinked',
            'label' => 'No note yet',
            'tone' => 'missing',
            'text' => 'I cannot see your work yet.',
        ];
    }
    if ($percent >= 100) {
        return [
            'code' => 'keep',
            'label' => 'Keep going',
            'tone' => 'good',
            'text' => 'Keep going.',
        ];
    }
    if ($percent >= 80) {
        return [
            'code' => 'watch',
            'label' => 'Finish what is left',
            'tone' => 'good',
            'text' => 'Finish what is left.',
        ];
    }
    if ($percent >= 50) {
        return [
            'code' => 'warning',
            'label' => 'Let us agree',
            'tone' => 'warn',
            'text' => 'Let us agree what you will finish next.',
        ];
    }
    if ($percent >= 25) {
        return [
            'code' => 'final',
            'label' => 'Let us plan this',
            'tone' => 'bad',
            'text' => 'Let us sit together and plan how you catch up.',
        ];
    }

    return [
        'code' => 'end',
        'label' => 'Tell me what happened',
        'tone' => 'bad',
        'text' => 'Tell me what got in the way.',
    ];
}

/**
 * @param list<int> $adminIds
 * @param list<string> $days
 * @return array<int, int>
 */
function pcvc_perf_completed_on_days(mysqli $conn, array $adminIds, array $days): array
{
    $adminIds = array_values(array_filter(array_map('intval', $adminIds), static fn (int $id): bool => $id > 0));
    $days = array_values(array_filter($days, static fn (string $day): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)));
    if ($adminIds === [] || $days === []) {
        return [];
    }
    $idSql = implode(',', $adminIds);
    $daySql = implode(',', array_map(static fn (string $day): string => "'" . $conn->real_escape_string($day) . "'", $days));
    $counts = [];
    $res = $conn->query(
        "SELECT admin_id, COUNT(*) AS c
         FROM job_list
         WHERE status = 'completed'
           AND completed_at IS NOT NULL
           AND DATE(DATE_ADD(completed_at, INTERVAL 2 HOUR)) IN ({$daySql})
           AND admin_id IN ({$idSql})
         GROUP BY admin_id"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $counts[(int) $row['admin_id']] = (int) $row['c'];
        }
        $res->free();
    }

    return $counts;
}

function pcvc_perf_comment(string $name, string $when, array $decision): string
{
    unset($name);
    $closing = (string) $decision['text'];
    if ($decision['code'] === 'unlinked') {
        return $closing;
    }
    $openings = [
        'keep' => [
            'today' => 'You finished today.',
            'week' => 'You kept up this week.',
        ],
        'watch' => [
            'today' => 'You are close today, with a little still undone.',
            'week' => 'You are close this week, but not quite there.',
        ],
        'warning' => [
            'today' => 'You left too much undone today.',
            'week' => 'You are well behind this week.',
        ],
        'final' => [
            'today' => 'You did less than half of today.',
            'week' => 'You did less than half of this week.',
        ],
        'end' => [
            'today' => 'You got very little done today.',
            'week' => 'You got very little done this week.',
        ],
    ];
    $opening = $openings[$decision['code']][$when] ?? 'Here is what I need from you.';

    return $opening . ' ' . $closing;
}

function pcvc_perf_comment_ok(string $comment, string $mustSay): bool
{
    $comment = trim($comment);
    if ($comment === '' || strlen($comment) > 240 || !str_contains($comment, $mustSay)) {
        return false;
    }
    if (preg_match('/\b(percent|percentage|threshold|performance|indicates|metric|target)\b/i', $comment)) {
        return false;
    }
    if (preg_match('/\b(them|they|their|he|she|him|her)\b/i', $comment)) {
        return false;
    }

    return !preg_match('/\b(warn|end the job|job ends|fired|dismiss|terminat)/i', $comment);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function pcvc_perf_attach_reviews(mysqli $conn, array $rows, string $date): array
{
    $week = pcvc_perf_week($date);
    $ids = [];
    foreach ($rows as $row) {
        if ((int) ($row['admin_id'] ?? 0) > 0) {
            $ids[] = (int) $row['admin_id'];
        }
    }
    $targets = pcvc_daily_job_targets_for_admins($conn, $ids);
    $weekDone = pcvc_perf_completed_on_days($conn, $ids, $week['days']);

    $prepared = [];
    foreach ($rows as $row) {
        $id = (int) ($row['admin_id'] ?? 0);
        $linked = $id > 0 && !empty($row['linked']);
        $doneToday = (int) ($row['completed_today'] ?? 0);
        $doneWeek = $weekDone[$id] ?? 0;
        $dailyTarget = $targets[$id] ?? PCVC_CHECKOUT_MIN_JOBS_TODAY;
        $weekTarget = $dailyTarget * max(1, $week['day_count']);
        $dailyPct = $linked ? pcvc_perf_percent($doneToday, $dailyTarget) : 0;
        $weeklyPct = $linked ? pcvc_perf_percent($doneWeek, $weekTarget) : 0;
        $dailyDecision = pcvc_perf_decision($dailyPct, $linked);
        $weeklyDecision = pcvc_perf_decision($weeklyPct, $linked);
        $row['daily_target'] = $dailyTarget;
        $row['daily_percent'] = $dailyPct;
        $row['weekly_percent'] = $weeklyPct;
        $row['week_done'] = $doneWeek;
        $row['week_target'] = $weekTarget;
        $row['week_label'] = $week['label'];
        $row['daily_decision'] = $dailyDecision;
        $row['weekly_decision'] = $weeklyDecision;
        $row['daily_comment'] = pcvc_perf_comment((string) $row['name'], 'today', $dailyDecision);
        $row['weekly_comment'] = pcvc_perf_comment((string) $row['name'], 'week', $weeklyDecision);
        $row['review_source'] = 'score';
        $prepared[] = $row;
    }

    $comments = pcvc_perf_gemini_comments($prepared, $date, $week['label']);
    foreach ($prepared as $i => $row) {
        $key = (string) ($row['admin_id'] ?? 0);
        if (!isset($comments[$key])) {
            continue;
        }
        $daily = trim((string) ($comments[$key]['daily_comment'] ?? ''));
        $weekly = trim((string) ($comments[$key]['weekly_comment'] ?? ''));
        $dailyOk = pcvc_perf_comment_ok($daily, (string) $row['daily_decision']['text']);
        $weeklyOk = pcvc_perf_comment_ok($weekly, (string) $row['weekly_decision']['text']);
        if ($dailyOk) {
            $prepared[$i]['daily_comment'] = $daily;
        }
        if ($weeklyOk) {
            $prepared[$i]['weekly_comment'] = $weekly;
        }
        if ($dailyOk && $weeklyOk) {
            $prepared[$i]['review_source'] = 'gemini';
        }
    }

    return $prepared;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, array{daily_comment:string,weekly_comment:string}>
 */
function pcvc_perf_gemini_comments(array $rows, string $date, string $weekLabel): array
{
    $payload = [];
    foreach ($rows as $row) {
        if (empty($row['linked'])) {
            continue;
        }
        $payload[] = [
            'id' => (int) $row['admin_id'],
            'name' => (string) $row['name'],
            'position' => (string) $row['position'],
            'finished_today' => (int) $row['completed_today'],
            'daily_target' => (int) ($row['daily_target'] ?? PCVC_CHECKOUT_MIN_JOBS_TODAY),
            'daily_percent' => (int) $row['daily_percent'],
            'daily_decision' => (string) $row['daily_decision']['text'],
            'finished_this_week' => (int) $row['week_done'],
            'weekly_target' => (int) ($row['week_target'] ?? 0),
            'weekly_percent' => (int) $row['weekly_percent'],
            'weekly_decision' => (string) $row['weekly_decision']['text'],
            'open_jobs' => (int) $row['open_jobs'],
        ];
    }
    if ($payload === [] || !pcvc_docvision_is_configured()) {
        return [];
    }

    $fingerprint = hash('sha256', 'human-v4|' . json_encode($payload));
    $cacheDir = dirname(__DIR__) . '/cache/staff_performance';
    $cacheFile = $cacheDir . '/human-' . $date . '-' . substr($fingerprint, 0, 16) . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return pcvc_perf_index_comments($cached);
        }
    }

    $system = 'You are speaking to one staff member, face to face. Use "you". Never use them, they, their, he, she, him, or her. '
        . 'One short sentence for daily_comment and one for weekly_comment. '
        . 'Say what you finished, in plain words, then the advice. '
        . 'End daily_comment with the exact daily_decision text. End weekly_comment with the exact weekly_decision text. '
        . 'Do not soften, upgrade, or add a second decision. '
        . 'Never say warn, end the job, fire, dismiss, or terminate. '
        . 'Do not use these words: percent, percentage, threshold, performance, indicates, metric, target. '
        . 'Do not mention clients or invent misconduct. '
        . 'Good: "You finished today. Keep going." '
        . 'Good: "You got very little done this week. Tell me what got in the way." '
        . 'Bad: "Talk with them." Bad: "Call them in." Bad: "End the job." '
        . 'Return JSON only: {"reviews":[{"id":1,"daily_comment":"...","weekly_comment":"..."}]}';
    $user = 'Date ' . $date . '. Work week ' . $weekLabel . ' (Monday to Saturday, up to this date). '
        . "Use each person's daily_target and weekly_target.\n"
        . json_encode(['staff' => $payload], JSON_UNESCAPED_UNICODE);

    $result = pcvc_perf_gemini_json($system, $user);
    $reviews = $result['reviews'] ?? $result['json']['reviews'] ?? null;
    if (!is_array($reviews)) {
        return [];
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($reviews, JSON_UNESCAPED_UNICODE));

    return pcvc_perf_index_comments($reviews);
}

/**
 * @param list<mixed> $reviews
 * @return array<string, array{daily_comment:string,weekly_comment:string}>
 */
function pcvc_perf_index_comments(array $reviews): array
{
    $out = [];
    foreach ($reviews as $review) {
        if (!is_array($review)) {
            continue;
        }
        $id = (string) ($review['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[$id] = [
            'daily_comment' => trim((string) ($review['daily_comment'] ?? '')),
            'weekly_comment' => trim((string) ($review['weekly_comment'] ?? '')),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function pcvc_perf_gemini_json(string $system, string $user): array
{
    $key = pcvc_docvision_api_key();
    if ($key === '') {
        return [];
    }
    $model = rawurlencode(pcvc_docvision_model());
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($key);
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => [
            'temperature' => 0.2,
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => 4096,
        ],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!is_string($response) || $response === '') {
        return [];
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [];
    }
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (!empty($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $decoded = pcvc_docvision_decode_json($text);

    return is_array($decoded) ? $decoded : [];
}

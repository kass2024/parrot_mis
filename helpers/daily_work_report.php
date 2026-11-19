<?php
declare(strict_types=1);

function daily_report_site_root(): string
{
    return dirname(__DIR__);
}

function daily_report_private_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/private/daily_work_reports';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('The report folder could not be created.');
    }
    return $dir;
}

function daily_report_ensure_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS daily_work_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_id INT NOT NULL,
        report_date DATE NOT NULL,
        body_html MEDIUMTEXT NOT NULL,
        signature_image MEDIUMTEXT NULL,
        pdf_file VARCHAR(160) NOT NULL,
        recipients_json TEXT NOT NULL,
        contract_line VARCHAR(500) NOT NULL DEFAULT '',
        mail_status VARCHAR(20) NOT NULL DEFAULT 'saved',
        mail_error VARCHAR(300) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY admin_date (admin_id, report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        throw new RuntimeException('The report table could not be prepared.');
    }
}

function daily_report_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function daily_report_staff(mysqli $conn, int $adminId): array
{
    $stmt = $conn->prepare('SELECT id, full_name, username, email, position, role FROM admins WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return ['id' => $adminId, 'name' => 'Staff', 'username' => '', 'email' => '', 'position' => '', 'role' => ''];
    }
    $name = trim((string) ($row['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['username'] ?? 'Staff'));
    }
    return [
        'id' => (int) $row['id'],
        'name' => $name,
        'username' => trim((string) ($row['username'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'position' => trim((string) ($row['position'] ?? '')),
        'role' => trim((string) ($row['role'] ?? '')),
    ];
}

function daily_report_jobs(mysqli $conn, int $adminId): array
{
    $sql = "SELECT j.id, j.title, j.applicant_name, j.applicant_email, j.job_type, j.status, j.comment,
            j.application_id, u.name AS university_name
        FROM job_list j
        LEFT JOIN universities u ON u.id = j.university_id
        WHERE j.admin_id = ? AND DATE(j.created_at) = CURDATE()
        ORDER BY j.status ASC, j.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $stmt = $conn->prepare("SELECT id, title, applicant_name, applicant_email, job_type, status, comment, application_id
            FROM job_list
            WHERE admin_id = ? AND DATE(created_at) = CURDATE()
            ORDER BY status ASC, id ASC");
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) ($row['title'] ?? '')),
            'applicant_name' => trim((string) ($row['applicant_name'] ?? '')),
            'applicant_email' => trim((string) ($row['applicant_email'] ?? '')),
            'job_type' => trim((string) ($row['job_type'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'comment' => trim((string) ($row['comment'] ?? '')),
            'application_id' => (int) ($row['application_id'] ?? 0),
            'college' => trim((string) ($row['university_name'] ?? '')),
            'program' => '',
        ];
    }
    $stmt->close();
    return daily_report_attach_study($conn, $jobs);
}

function daily_report_attach_study(mysqli $conn, array $jobs): array
{
    if ($jobs === []) {
        return $jobs;
    }
    $byApp = [];
    $appIds = [];
    foreach ($jobs as $job) {
        if ($job['application_id'] > 0) {
            $appIds[$job['application_id']] = $job['application_id'];
        }
    }
    if ($appIds !== []) {
        $in = implode(',', $appIds);
        $res = $conn->query("SELECT sc.application_id,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(u.name), '') ORDER BY u.name SEPARATOR ', ') AS colleges,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.program_name), '') ORDER BY p.program_name SEPARATOR ', ') AS programs
            FROM application_study_choices sc
            LEFT JOIN universities u ON u.id = sc.university_id
            LEFT JOIN programs p ON p.id = sc.program_id
            WHERE sc.application_id IN ($in)
            GROUP BY sc.application_id");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $byApp[(int) $row['application_id']] = $row;
            }
        }
    }
    $byEmail = [];
    $emails = [];
    foreach ($jobs as $job) {
        $email = strtolower($job['applicant_email']);
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }
    if ($emails !== []) {
        $in = implode(',', array_map(static fn (string $email): string => "'" . $conn->real_escape_string($email) . "'", $emails));
        $res = $conn->query("SELECT LOWER(sa.email) AS email,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(u.name), '') ORDER BY u.name SEPARATOR ', ') AS colleges,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.program_name), '') ORDER BY p.program_name SEPARATOR ', ') AS programs
            FROM student_applications sa
            LEFT JOIN application_study_choices sc ON sc.application_id = sa.id
            LEFT JOIN universities u ON u.id = sc.university_id
            LEFT JOIN programs p ON p.id = sc.program_id
            WHERE LOWER(sa.email) IN ($in)
            GROUP BY LOWER(sa.email)");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $byEmail[(string) $row['email']] = $row;
            }
        }
    }
    foreach ($jobs as $i => $job) {
        $study = $byApp[$job['application_id']] ?? $byEmail[strtolower($job['applicant_email'])] ?? null;
        if (is_array($study)) {
            if ($job['college'] === '' && trim((string) ($study['colleges'] ?? '')) !== '') {
                $jobs[$i]['college'] = trim((string) $study['colleges']);
            }
            if (trim((string) ($study['programs'] ?? '')) !== '') {
                $jobs[$i]['program'] = trim((string) $study['programs']);
            }
        }
    }
    return $jobs;
}

function daily_report_contract_line(int $adminId): string
{
    $root = daily_report_site_root();
    global $conn;
    $stmt = $conn->prepare("SELECT filled_docx_path, source_docx_path
        FROM employment_contracts
        WHERE admin_id = ? AND COALESCE(NULLIF(filled_docx_path, ''), NULLIF(source_docx_path, '')) IS NOT NULL
        ORDER BY id DESC");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $line = '';
    while ($row = $result->fetch_assoc()) {
        $rel = trim((string) ($row['filled_docx_path'] ?: $row['source_docx_path']));
        $path = $root . '/' . ltrim($rel, '/');
        $found = daily_report_reports_to_from_docx($path);
        if ($found !== '') {
            $line = $found;
            break;
        }
    }
    $stmt->close();
    return $line;
}

function daily_report_reports_to_from_docx(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml) || $xml === '') {
        return '';
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return '';
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    foreach ($xp->query('//w:tr') as $tr) {
        $cells = [];
        foreach ($xp->query('./w:tc', $tr) as $tc) {
            $cells[] = trim(preg_replace('/\s+/', ' ', (string) $tc->textContent));
        }
        if (count($cells) >= 2 && strcasecmp($cells[0], 'Reports to') === 0) {
            return trim($cells[1]);
        }
    }
    return '';
}

function daily_report_norm(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim($value);
}

function daily_report_words(string $value): array
{
    $parts = preg_split('/\s+/', daily_report_norm($value)) ?: [];
    $skip = ['and' => true, 'the' => true, 'of' => true, 'to' => true, 'for' => true, 'a' => true];
    $words = [];
    foreach ($parts as $part) {
        if ($part === '' || isset($skip[$part]) || strlen($part) < 3) {
            continue;
        }
        $words[] = $part;
    }
    return $words;
}

function daily_report_split_titles(string $line): array
{
    $line = preg_replace('/(Coordinator|Manager|Director|Officer)\s*Available\b/i', '$1', $line) ?? $line;
    $line = preg_replace('/financial reports also go directly to the\s+/i', '; ', $line) ?? $line;
    $chunks = preg_split('/\s*(?:;|&| together with | together )\s*/i', $line) ?: [];
    $titles = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk, " \t\n\r\0\x0B.,");
        $chunk = preg_replace('/^(and|with|the)\s+/i', '', $chunk) ?? $chunk;
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        if (preg_match('/^(.+?)\s+and\s+(.+)$/i', $chunk, $match)) {
            $left = trim($match[1]);
            $right = trim($match[2]);
            $titleWord = '/(manager|coordinator|director|owner|officer|specialist)/i';
            if (preg_match($titleWord, $left) && preg_match($titleWord, $right)) {
                $titles[] = $left;
                $titles[] = $right;
                continue;
            }
        }
        $titles[] = $chunk;
    }
    $unique = [];
    foreach ($titles as $title) {
        $key = daily_report_norm($title);
        if ($key !== '' && !isset($unique[$key])) {
            $unique[$key] = $title;
        }
    }
    return array_values($unique);
}

function daily_report_directory(mysqli $conn): array
{
    $result = $conn->query("SELECT id, full_name, username, email, position FROM admins WHERE email IS NOT NULL AND email <> ''");
    $people = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['username'] ?? ''));
            }
            $people[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'email' => trim((string) $row['email']),
                'position' => trim((string) ($row['position'] ?? '')),
            ];
        }
    }
    return $people;
}

function daily_report_is_director_title(string $title): bool
{
    $norm = daily_report_norm($title);
    if ($norm === '') {
        return false;
    }
    if (str_contains($norm, 'deputy')) {
        return false;
    }
    return $norm === 'owner'
        || str_contains($norm, 'managing director')
        || str_contains($norm, 'owner and managing director')
        || $norm === 'ceo';
}

function daily_report_match_title(string $title, array $directory, int $selfId): array
{
    if (daily_report_norm($title) === 'it coordinator') {
        $hits = [];
        foreach ($directory as $person) {
            if ($person['id'] === $selfId) {
                continue;
            }
            if (daily_report_norm($person['position']) === 'it coordinator') {
                $hits[] = $person;
            }
        }
        return $hits;
    }

    if (daily_report_is_director_title($title)) {
        $hits = [];
        foreach ($directory as $person) {
            if ($person['id'] === $selfId) {
                continue;
            }
            if (daily_report_norm($person['position']) === 'ceo') {
                $hits[] = $person;
            }
        }
        return $hits;
    }

    $fragment = daily_report_norm($title);
    $fragmentWords = daily_report_words($title);
    $hits = [];
    foreach ($directory as $person) {
        if ($person['id'] === $selfId || $person['position'] === '') {
            continue;
        }
        $position = daily_report_norm($person['position']);
        if ($position === '' || strlen($position) < 8 || $fragment === '') {
            continue;
        }
        $shorter = strlen($fragment) <= strlen($position) ? $fragment : $position;
        $longer = $shorter === $fragment ? $position : $fragment;
        $prefix = strlen($shorter) >= 18 && str_starts_with($longer, $shorter);
        $wordMatch = false;
        if (!$prefix) {
            $positionWords = daily_report_words($person['position']);
            if (count($positionWords) >= 3 && count($fragmentWords) >= 3) {
                $missing = array_diff($positionWords, $fragmentWords);
                $covered = count($positionWords) / max(1, count($fragmentWords));
                $wordMatch = $missing === [] && $covered >= 0.6 && $covered <= 1.35;
            }
        }
        if ($prefix || $wordMatch) {
            $hits[] = $person;
        }
    }
    $norm = daily_report_norm($title);
    if (str_contains($norm, 'finance') && str_contains($norm, 'overseas')) {
        $preferred = array_values(array_filter(
            $hits,
            static fn (array $person): bool => strcasecmp($person['email'], 'tuyizerei83@gmail.com') === 0
        ));
        if ($preferred !== []) {
            $hits = $preferred;
        } else {
            $hits = array_values(array_filter(
                $hits,
                static fn (array $person): bool => strcasecmp($person['email'], 'innocentmushimiyimanarw@gmail.com') !== 0
            ));
        }
    }
    if (count($hits) > 3) {
        return [];
    }
    return $hits;
}

function daily_report_recipients(mysqli $conn, int $adminId): array
{
    $line = daily_report_contract_line($adminId);
    $directory = daily_report_directory($conn);
    $people = [];
    $unmatched = [];
    $seen = [];
    if ($line === '') {
        return [
            'contract_found' => false,
            'contract_line' => '',
            'people' => [],
            'unmatched' => [],
        ];
    }
    $staff = daily_report_staff($conn, $adminId);
    $matchLine = $line;
    if (strcasecmp($staff['username'], 'Robert') === 0) {
        $matchLine = trim($line . ' & IT Coordinator');
    }
    foreach (daily_report_split_titles($matchLine) as $title) {
        $matches = daily_report_match_title($title, $directory, $adminId);
        if ($matches === []) {
            $unmatched[] = $title;
            continue;
        }
        foreach ($matches as $person) {
            if (!filter_var($person['email'], FILTER_VALIDATE_EMAIL) || isset($seen[$person['id']])) {
                continue;
            }
            $seen[$person['id']] = true;
            $person['matched_title'] = $title;
            $people[] = $person;
        }
    }
    return [
        'contract_found' => true,
        'contract_line' => $line,
        'people' => $people,
        'unmatched' => $unmatched,
    ];
}

function daily_report_job_label(array $job): string
{
    $title = $job['title'] !== '' ? $job['title'] : 'Untitled job';
    $bits = [$title];
    if ($job['applicant_name'] !== '') {
        $bits[] = $job['applicant_name'];
    }
    if ($job['job_type'] !== '') {
        $bits[] = $job['job_type'];
    }
    $line = implode(' — ', $bits);
    if ($job['comment'] !== '') {
        $line .= '. Note: ' . $job['comment'];
    }
    return $line;
}

function daily_report_default_action(array $job): string
{
    $status = $job['status'] === 'completed' ? 'Completed' : 'Pending follow-up';
    $parts = [$status];
    if ($job['job_type'] !== '') {
        $parts[] = $job['job_type'];
    }
    if ($job['comment'] !== '') {
        $parts[] = $job['comment'];
    } elseif ($job['title'] !== '' && strcasecmp($job['title'], $job['applicant_name']) !== 0) {
        $parts[] = $job['title'];
    }
    return implode('. ', $parts);
}

function daily_report_counts(array $jobs): array
{
    $submitted = 0;
    $offers = 0;
    $emails = 0;
    $pending = 0;
    $people = [];
    foreach ($jobs as $job) {
        $blob = strtolower($job['title'] . ' ' . $job['job_type'] . ' ' . $job['comment']);
        if ($job['status'] !== 'completed') {
            $pending++;
        }
        if ($job['status'] === 'completed' && preg_match('/submit/', $blob)) {
            $submitted++;
        }
        if (preg_match('/admit|offer received|admission offer/', $blob)) {
            $offers++;
        }
        if (preg_match('/\be-?mail\b|@/', $blob)) {
            $emails++;
        }
        $key = strtolower($job['applicant_email'] !== '' ? $job['applicant_email'] : ($job['applicant_name'] !== '' ? $job['applicant_name'] : (string) $job['id']));
        $people[$key] = true;
    }
    return [
        'submitted' => $submitted,
        'followed_up' => count($jobs),
        'offers' => $offers,
        'pending' => $pending,
        'contacted' => count($people),
        'emails_sent' => $emails,
    ];
}

function daily_report_document_html(array $staff, array $jobs, string $dateLabel, ?array $ai = null): string
{
    $counts = daily_report_counts($jobs);
    $actions = [];
    $remarks = $jobs === []
        ? 'No applicant was on today\'s job list.'
        : 'See the pending rows above. Add any blocker or the next step before you send this report.';
    if (is_array($ai)) {
        if (!empty($ai['actions']) && is_array($ai['actions'])) {
            $actions = $ai['actions'];
        }
        $aiRemarks = trim((string) ($ai['remarks'] ?? ''));
        if ($aiRemarks !== '') {
            $remarks = $aiRemarks;
        }
    }
    $position = trim((string) ($staff['position'] ?? ''));
    if ($position === '') {
        $position = (string) ($staff['name'] ?? '');
    }
    $html = '<h2>DAILY STUDENT APPLICATION FOLLOW-UP REPORT</h2>';
    $html .= '<p><strong>PARROT CANADA VISA CONSULTANT CO. LTD.</strong></p>';
    $html .= '<p><strong>Employee Position:</strong> ' . daily_report_h($position) . '</p>';
    $html .= '<p><strong>Reporting Date:</strong> ' . daily_report_h($dateLabel) . '</p>';
    $html .= '<h3>1. Applicants Followed Up</h3>';
    $html .= '<table><thead><tr>'
        . '<th>No.</th><th>Applicant\'s Full Name</th><th>Email Address</th><th>College</th><th>Program</th><th>Application Status / Action Taken</th>'
        . '</tr></thead><tbody>';
    if ($jobs === []) {
        $html .= '<tr><td>1.</td><td></td><td></td><td></td><td></td><td>No applicant was on today\'s job list.</td></tr>';
    } else {
        $number = 1;
        foreach ($jobs as $job) {
            $action = $actions[(string) $job['id']] ?? $actions[$job['id']] ?? daily_report_default_action($job);
            $name = $job['applicant_name'] !== '' ? $job['applicant_name'] : $job['title'];
            $html .= '<tr><td>' . $number . '.</td><td>' . daily_report_h($name) . '</td><td>'
                . daily_report_h($job['applicant_email']) . '</td><td>' . daily_report_h((string) ($job['college'] ?? ''))
                . '</td><td>' . daily_report_h((string) ($job['program'] ?? '')) . '</td><td>'
                . daily_report_h((string) $action) . '</td></tr>';
            $number++;
        }
    }
    $html .= '</tbody></table>';
    $html .= '<h3>2. Daily Work Summary</h3>';
    $lines = [
        'Applications submitted' => $counts['submitted'],
        'Applications followed up' => $counts['followed_up'],
        'Admission offers received' => $counts['offers'],
        'Pending applications' => $counts['pending'],
        'Students contacted' => $counts['contacted'],
        'Emails sent' => $counts['emails_sent'],
    ];
    foreach ($lines as $label => $value) {
        $html .= '<p><strong>' . $label . ':</strong> ' . (int) $value . '</p>';
    }
    $html .= '<h3>3. Pending Actions / Remarks</h3><p>' . nl2br(daily_report_h($remarks)) . '</p>';
    $html .= '<p><strong>Prepared by:</strong> ' . daily_report_h((string) $staff['name']) . ' &nbsp;&nbsp; <strong>Signature:</strong></p>';
    return $html;
}

function daily_report_local_html(array $staff, array $jobs, string $dateLabel): string
{
    return daily_report_document_html($staff, $jobs, $dateLabel, null);
}

function daily_report_clean_html(string $html): string
{
    $html = preg_replace('/```(?:html)?|```/i', '', $html) ?? $html;
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
    $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><h2><h3><table><thead><tbody><tr><th><td>');
    $html = preg_replace('/<(p|br|strong|b|em|i|ul|ol|li|h2|h3|table|thead|tbody|tr|th|td)\b[^>]*>/i', '<$1>', $html) ?? $html;
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (!preg_match('/<(p|ul|ol|h2|h3)\b/i', $html)) {
        $html = daily_report_plain_to_html($html);
    }
    return $html;
}

function daily_report_plain_to_html(string $text): string
{
    $text = trim(html_entity_decode(strip_tags($text)));
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $html = '';
    $inList = false;
    $close = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
    };
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $close();
            continue;
        }
        if (preg_match('/^[-*•]\s+(.+)$/', $line, $match)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . daily_report_h($match[1]) . '</li>';
            continue;
        }
        $close();
        if (preg_match('/^#{1,3}\s+(.+)$/', $line, $match)) {
            $html .= '<h3>' . daily_report_h($match[1]) . '</h3>';
            continue;
        }
        $html .= '<p>' . daily_report_h($line) . '</p>';
    }
    $close();
    return $html;
}

function daily_report_gemini_html(array $staff, array $jobs, string $dateLabel): string
{
    $key = weekly_advert_gemini_key();
    if ($key === '') {
        throw new RuntimeException('The form could not be filled. You can still edit this draft and submit it.');
    }
    $payloadJobs = [];
    foreach ($jobs as $job) {
        $payloadJobs[] = [
            'id' => $job['id'],
            'applicant' => $job['applicant_name'],
            'email' => $job['applicant_email'],
            'college' => $job['college'] ?? '',
            'program' => $job['program'] ?? '',
            'title' => $job['title'],
            'type' => $job['job_type'],
            'status' => $job['status'] === 'completed' ? 'completed' : 'open',
            'comment' => mb_substr($job['comment'], 0, 400),
        ];
    }
    $facts = json_encode([
        'staff' => $staff['name'],
        'position' => $staff['position'],
        'date' => $dateLabel,
        'jobs' => $payloadJobs,
    ], JSON_UNESCAPED_UNICODE);

    $prompt = "Fill the action column and the remarks of a Daily Student Application Follow-Up Report.\n"
        . "Return JSON only, with no markdown:\n"
        . "{\"actions\":{\"JOB_ID\":\"one short status or action sentence\"},\"remarks\":\"two or three sentences on what is still pending\"}\n"
        . "Use only the jobs in the JSON. Do not invent applicants, colleges, programs, emails, or results.\n"
        . "Write one action for every job id. If the job is open, say what is still pending. If it is completed, say what was done.\n"
        . "The remarks must only mention facts that are in the jobs.\n\n"
        . $facts;

    $body = [
        'systemInstruction' => [
            'parts' => [[
                'text' => 'You fill a staff follow-up report from a job list. Return JSON only. Never add a person, a college, or a result that is not in the list.',
            ]],
        ],
        'contents' => [[
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 4096,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ],
    ];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('The form could not be filled. You can still edit this draft and submit it.');
    }
    $data = json_decode($raw, true);
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException('The form could not be filled. You can still edit this draft and submit it.');
    }
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }
    $jsonText = trim($text);
    $jsonText = preg_replace('/^```(?:json)?|```$/i', '', $jsonText) ?? $jsonText;
    $start = strpos($jsonText, '{');
    $end = strrpos($jsonText, '}');
    $ai = null;
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($jsonText, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            $ai = $decoded;
        }
    }
    if ($ai === null) {
        throw new RuntimeException('The form could not be filled. You can still edit this draft and submit it.');
    }
    return daily_report_document_html($staff, $jobs, $dateLabel, $ai);
}

function daily_report_pdf(string $absolutePath, array $staff, string $dateLabel, string $bodyHtml, string $signature, array $recipients): void
{
    $people = '';
    if ($recipients === []) {
        $people = '<p>No matched supervisor was on the contract.</p>';
    } else {
        $people = '<ul>';
        foreach ($recipients as $person) {
            $people .= '<li><strong>' . daily_report_h($person['name']) . '</strong> · '
                . daily_report_h($person['email']);
            if (!empty($person['position'])) {
                $people .= ' · ' . daily_report_h($person['position']);
            }
            $people .= '</li>';
        }
        $people .= '</ul>';
    }
    $sign = '';
    if (str_starts_with($signature, 'data:image/png;base64,')) {
        $sign = '<img src="' . $signature . '" alt="Signature">';
    }
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1c1c1c; line-height: 1.45; }
        h2 { font-size: 13pt; margin: 0 0 4pt; text-align: center; color: #1b4332; }
        h3 { font-size: 12pt; margin: 12pt 0 4pt; color: #1b4332; }
        p { margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; }
        th, td { border: 1px solid #222; padding: 3pt 4pt; font-size: 8.5pt; vertical-align: top; }
        th { background: #e8f5e9; }
        .sign img { max-height: 70pt; max-width: 220pt; }
        .foot { margin-top: 10pt; font-size: 9pt; color: #555; }
    </style></head><body>
        ' . $bodyHtml . '
        <p class="sign">' . $sign . '</p>
        <p class="foot"><strong>Sent to</strong><br>' . $people . '</p>
    </body></html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();
    $bytes = $dompdf->output();
    if ($bytes === '' || file_put_contents($absolutePath, $bytes) === false) {
        throw new RuntimeException('The PDF could not be saved.');
    }
}

function daily_report_can_view_all(mysqli $conn, int $adminId): bool
{
    if (function_exists('pcvc_current_user_is_superadmin') && pcvc_current_user_is_superadmin($conn)) {
        return true;
    }
    $staff = daily_report_staff($conn, $adminId);
    $role = strtolower($staff['role'] ?? '');
    $position = strtolower($staff['position'] ?? '');
    return str_contains($role, 'superadmin') || str_contains($role, 'owner') || $position === 'ceo';
}

function daily_report_signed_today(mysqli $conn, int $adminId): ?array
{
    $today = date('Y-m-d');
    $stmt = $conn->prepare('SELECT id, report_date, mail_status, created_at
        FROM daily_work_reports WHERE admin_id = ? AND report_date = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('is', $adminId, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function daily_report_history(mysqli $conn, int $adminId, bool $all = false): array
{
    if ($all) {
        $result = $conn->query("SELECT r.id, r.admin_id, r.report_date, r.mail_status, r.created_at,
            COALESCE(NULLIF(TRIM(a.full_name), ''), a.username, 'Staff') AS staff_name
            FROM daily_work_reports r
            LEFT JOIN admins a ON a.id = r.admin_id
            ORDER BY r.id DESC
            LIMIT 200");
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
    $stmt = $conn->prepare("SELECT id, admin_id, report_date, mail_status, created_at, '' AS staff_name
        FROM daily_work_reports WHERE admin_id = ? ORDER BY id DESC LIMIT 30");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

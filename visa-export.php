<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/form17_application_status.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Kigali');

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if ($format !== 'pdf') {
    $format = 'csv';
}

$range = strtolower(trim((string) ($_GET['range'] ?? 'today')));
$today = date('Y-m-d');
if ($range === 'today') {
    $from = $today;
    $to = $today;
} else {
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $fromOk = DateTime::createFromFormat('Y-m-d', $from);
    $toOk = DateTime::createFromFormat('Y-m-d', $to);
    if (!$fromOk || $fromOk->format('Y-m-d') !== $from || !$toOk || $toOk->format('Y-m-d') !== $to) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p>Choose a start date and an end date, then export again.</p>';
        echo '<p><a href="visa-report.php">Back to visa applications</a></p>';
        exit;
    }
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
}

$searchTerm = trim((string) ($_GET['search'] ?? ''));
$processStatusFilter = trim((string) ($_GET['process_status'] ?? ''));
$statusActive = $processStatusFilter !== '' && xander_is_valid_visa_process_status($processStatusFilter);
$labels = xander_visa_application_process_statuses();

$whereParts = ['COALESCE(DATE(f.date), DATE(f.submitted_at)) BETWEEN ? AND ?'];
$params = [$from, $to];
$paramTypes = 'ss';

if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $whereParts[] = '(f.first_name LIKE ? OR f.last_name LIKE ? OR f.email LIKE ? OR f.passport_number LIKE ? OR f.user_id LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $paramTypes .= 'sssss';
}
if ($statusActive) {
    $whereParts[] = 'f.process_status = ?';
    $params[] = $processStatusFilter;
    $paramTypes .= 's';
}

$sql = 'SELECT f.user_id, f.date, f.submitted_at, f.prefix, f.first_name, f.middle_name, f.last_name,
        f.email, f.applicant_mobile, f.birthdate, f.gender, f.passport_number,
        f.country_applying_from, f.country_to_visit, f.visa_type, f.process_status
        FROM form_17_applications f
        WHERE ' . implode(' AND ', $whereParts) . '
        ORDER BY f.date DESC, f.last_name ASC, f.first_name ASC';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'Could not prepare the export.';
    exit;
}
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$periodLabel = $from === $to
    ? date('M j, Y', strtotime($from))
    : date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to));
$fileStem = 'visa-applications-' . ($from === $to ? $from : $from . '-to-' . $to);

$lines = [];
foreach ($rows as $row) {
    $name = trim(preg_replace('/\s+/', ' ', trim((string) ($row['prefix'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
    $applied = trim((string) ($row['date'] ?? ''));
    if ($applied === '' || $applied === '0000-00-00') {
        $applied = !empty($row['submitted_at']) ? date('Y-m-d', strtotime((string) $row['submitted_at'])) : '';
    }
    $statusKey = strtolower(trim((string) ($row['process_status'] ?? '')));
    $lines[] = [
        $name,
        (string) ($row['email'] ?? ''),
        (string) ($row['applicant_mobile'] ?? ''),
        (string) ($row['visa_type'] ?? ''),
        $labels[$statusKey] ?? ($statusKey !== '' ? $statusKey : 'Submitted'),
        $applied,
        (string) ($row['passport_number'] ?? ''),
        (string) ($row['country_applying_from'] ?? ''),
        (string) ($row['country_to_visit'] ?? ''),
        (string) ($row['gender'] ?? ''),
        (string) ($row['birthdate'] ?? ''),
    ];
}

$headers = ['Name', 'Email', 'Phone', 'Visa type', 'Status', 'Applied date', 'Passport', 'From', 'To', 'Gender', 'Birthdate'];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Visa applications', $periodLabel, count($lines) . ' applications']);
    fputcsv($out, $headers);
    foreach ($lines as $line) {
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$body = '';
if ($lines === []) {
    $body = '<tr><td colspan="8">No applications in this period.</td></tr>';
} else {
    foreach ($lines as $line) {
        $body .= '<tr>'
            . '<td>' . $esc($line[0]) . '</td>'
            . '<td>' . $esc($line[1]) . '</td>'
            . '<td>' . $esc($line[2]) . '</td>'
            . '<td>' . $esc($line[3]) . '</td>'
            . '<td>' . $esc($line[4]) . '</td>'
            . '<td>' . $esc($line[5]) . '</td>'
            . '<td>' . $esc($line[6]) . '</td>'
            . '<td>' . $esc($line[7]) . ' → ' . $esc($line[8]) . '</td>'
            . '</tr>';
    }
}

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #14532d; }
h1 { font-size: 16px; margin: 0 0 4px; }
p { margin: 0 0 10px; color: #334155; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
th { background: #166534; color: #fff; font-size: 10px; }
tr:nth-child(even) td { background: #f8fafc; }
</style></head><body>'
    . '<h1>Visa applications</h1>'
    . '<p>' . $esc($periodLabel) . ' · ' . count($lines) . ' application' . (count($lines) === 1 ? '' : 's') . '</p>'
    . '<table><thead><tr>'
    . '<th>Name</th><th>Email</th><th>Phone</th><th>Visa type</th><th>Status</th><th>Applied</th><th>Passport</th><th>Route</th>'
    . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream($fileStem . '.pdf', ['Attachment' => true]);
exit;

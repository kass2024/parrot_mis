<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/caq_applications.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

pcvc_caq_ensure_schema($conn);

$docs = pcvc_caq_missing_doc_options();
$search = mb_strtolower(trim((string) ($_GET['search'] ?? '')));
$kpi = trim((string) ($_GET['kpi'] ?? 'all'));
$allowedKpi = ['all', 'received', 'not_yet', 'missing', 'ircc_yes'];
if (!in_array($kpi, $allowedKpi, true)) {
    $kpi = 'all';
}

$res = $conn->query('SELECT * FROM caq_applications ORDER BY full_name ASC');
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$lines = [];
foreach ($rows as $row) {
    $keys = pcvc_caq_docs_to_keys((string) $row['missing_docs']);
    $received = strtolower((string) $row['caq_received']);
    $flags = ['all'];
    if (str_starts_with($received, 'received')) {
        $flags[] = 'received';
    } elseif ($received !== '') {
        $flags[] = 'not_yet';
    }
    if ($keys !== [] && $keys !== ['na']) {
        $flags[] = 'missing';
    }
    if (strcasecmp((string) $row['ircc_account'], 'Yes') === 0) {
        $flags[] = 'ircc_yes';
    }
    $labels = [];
    foreach ($keys as $key) {
        $labels[] = $docs[$key] ?? $key;
    }
    $blob = mb_strtolower(trim($row['full_name'] . ' ' . $row['comment'] . ' ' . $row['caq_received'] . ' ' . implode(' ', $labels)));
    if ($kpi !== 'all' && !in_array($kpi, $flags, true)) {
        continue;
    }
    if ($search !== '' && mb_strpos($blob, $search) === false) {
        continue;
    }
    $lines[] = [
        (string) $row['full_name'],
        (string) $row['caq_form'],
        (string) $row['signed_form'],
        (string) $row['submitted_status'],
        (string) $row['caq_received'],
        $labels === [] ? '' : implode(', ', $labels),
        (string) $row['comment'],
        (string) $row['ircc_account'],
    ];
}

$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$body = '';
if ($lines === []) {
    $body = '<tr><td colspan="8">No applications in this export.</td></tr>';
} else {
    foreach ($lines as $line) {
        $body .= '<tr>';
        foreach ($line as $cell) {
            $body .= '<td>' . $esc($cell) . '</td>';
        }
        $body .= '</tr>';
    }
}

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #14532d; }
h1 { font-size: 16px; margin: 0 0 4px; }
p { margin: 0 0 10px; color: #334155; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; vertical-align: top; }
th { background: #166534; color: #fff; font-size: 9px; }
tr:nth-child(even) td { background: #f8fafc; }
</style></head><body>'
    . '<h1>CAQ Applications</h1>'
    . '<p>' . count($lines) . ' application' . (count($lines) === 1 ? '' : 's') . '</p>'
    . '<table><thead><tr>'
    . '<th>Name</th><th>Form of CAQ</th><th>Signed form</th><th>Submitted</th><th>CAQ received</th><th>Missing documents</th><th>Comment</th><th>IRCC account</th>'
    . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('caq-applications.pdf', ['Attachment' => true]);
exit;

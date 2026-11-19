<?php
declare(strict_types=1);

/**
 * Match meeting emails to Parrot MIS staff and return Mobile Money numbers.
 * Called by the e-learning attendance report. Requires X-Staff-Lookup-Token.
 */
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers/env_load.php';
require_once dirname(__DIR__) . '/includes/momo_phone.php';

function staff_lookup_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim(xander_env_get('STAFF_MOMO_LOOKUP_TOKEN'));
$given = trim((string) ($_SERVER['HTTP_X_STAFF_LOOKUP_TOKEN'] ?? ''));
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    staff_lookup_respond(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
$emails = is_array($input['emails'] ?? null) ? $input['emails'] : [];
$clean = [];
foreach ($emails as $email) {
    $email = strtolower(trim((string) $email));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $clean[$email] = $email;
    }
    if (count($clean) >= 80) {
        break;
    }
}

if ($clean === []) {
    staff_lookup_respond(['ok' => true, 'staff' => new stdClass()]);
}

$placeholders = implode(',', array_fill(0, count($clean), '?'));
$sql = "SELECT email, full_name, first_name, last_name, phone_number
        FROM admins
        WHERE LOWER(TRIM(email)) IN ($placeholders)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    staff_lookup_respond(['ok' => false, 'error' => 'Database error.'], 500);
}

$values = array_values($clean);
$types = str_repeat('s', count($values));
$stmt->bind_param($types, ...$values);
$stmt->execute();
$result = $stmt->get_result();

$staff = [];
while ($row = $result->fetch_assoc()) {
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    if ($email === '') {
        continue;
    }
    $name = trim((string) ($row['full_name'] ?? ''));
    if ($name === '') {
        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
    }
    $phone = trim((string) ($row['phone_number'] ?? ''));
    $msisdn = pcvc_normalize_rw_momo_msisdn($phone);
    $staff[$email] = [
        'name' => $name,
        'momo' => $msisdn ?? ($phone !== '' ? $phone : null),
        'momo_display' => $msisdn !== null ? pcvc_format_rw_momo_display($msisdn) : ($phone !== '' ? $phone : null),
    ];
}
$stmt->close();

staff_lookup_respond(['ok' => true, 'staff' => $staff === [] ? new stdClass() : $staff]);

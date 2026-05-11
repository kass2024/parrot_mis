<?php
/**
 * Smart retrieval: verify user_id exists for a service card and return the URL to continue.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$card   = isset($_POST['card']) ? trim((string)$_POST['card']) : (isset($_GET['card']) ? trim((string)$_GET['card']) : '');
$userId = isset($_POST['user_id']) ? trim((string)$_POST['user_id']) : (isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '');

if ($userId === '' || strlen($userId) > 160) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter your application user ID.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $userId)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user ID format.']);
    exit;
}

$allowedCards = ['admissions', 'scholarships', 'i20', 'credit', 'visa', 'jobs', 'medical'];
if (!in_array($card, $allowedCards, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Unknown service.']);
    exit;
}

/**
 * @return array{0:bool,1:string} [found, summary line]
 */
function row_found(mysqli $conn, string $sql, string $types, string $userId): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [false, ''];
    }
    $stmt->bind_param($types, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return [false, ''];
    }
    return [true, trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))];
}

$redirect = '';
$summary  = '';

switch ($card) {
    case 'credit':
        [$ok, $name] = row_found(
            $conn,
            'SELECT first_name, last_name, email FROM credit_transfer_applications WHERE user_id = ? LIMIT 1',
            's',
            $userId
        );
        if (!$ok) {
            echo json_encode(['status' => 'error', 'message' => 'No credit transfer application found for this user ID. Use the format from your confirmation (e.g. credit-1749906159-3206).']);
            exit;
        }
        $redirect = 'credit_transfer.php?id=' . rawurlencode($userId);
        $summary  = $name !== '' ? $name : $userId;
        break;

    case 'scholarships':
        [$ok, $name] = row_found(
            $conn,
            'SELECT first_name, last_name, email FROM master_loan_applications WHERE user_id = ? LIMIT 1',
            's',
            $userId
        );
        if (!$ok) {
            echo json_encode(['status' => 'error', 'message' => 'No scholarship / loan application found for this user ID.']);
            exit;
        }
        $st = $conn->prepare('SELECT loan_provider_id FROM master_loan_applications WHERE user_id = ? LIMIT 1');
        $st->bind_param('s', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        $pid = isset($r['loan_provider_id']) ? trim((string)$r['loan_provider_id']) : '';
        $redirect = 'master-loan.php?id=' . rawurlencode($userId);
        if ($pid !== '') {
            $redirect .= '&provider_id=' . rawurlencode($pid);
        }
        $summary = $name !== '' ? $name : $userId;
        break;

    case 'i20':
        $st = $conn->prepare('SELECT first_name, last_name, university_id, region_id FROM form_20_applications WHERE user_id = ? LIMIT 1');
        $st->bind_param('s', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r) {
            echo json_encode(['status' => 'error', 'message' => 'No I-20 application found for this user ID.']);
            exit;
        }
        $uid = isset($r['university_id']) ? (int)$r['university_id'] : 0;
        $rid = isset($r['region_id']) ? (int)$r['region_id'] : 1;
        if ($uid > 0) {
            $redirect = 'form-20.php?id=' . rawurlencode($userId)
                . '&university_id=' . $uid
                . '&region_id=' . $rid;
        } else {
            $redirect = 'select-20.php?form=' . rawurlencode('form-20.php') . '&id=' . rawurlencode($userId);
        }
        $summary = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        break;

    case 'admissions':
        [$ok, $name] = row_found(
            $conn,
            'SELECT first_name, last_name, email FROM student_applications WHERE user_id = ? LIMIT 1',
            's',
            $userId
        );
        if (!$ok) {
            echo json_encode(['status' => 'error', 'message' => 'No study abroad application found for this user ID.']);
            exit;
        }
        $redirect = 'student-application.php?id=' . rawurlencode($userId);
        $summary  = $name !== '' ? $name : $userId;
        break;

    case 'visa':
        $st = $conn->prepare('SELECT first_name, last_name, region_id, country_id FROM form_17_applications WHERE user_id = ? LIMIT 1');
        $st->bind_param('s', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r) {
            echo json_encode(['status' => 'error', 'message' => 'No visa application found for this user ID.']);
            exit;
        }
        $cid = isset($r['country_id']) ? (int)$r['country_id'] : 0;
        $rid = isset($r['region_id']) ? (int)$r['region_id'] : 0;
        $redirect = 'visa.php?id=' . rawurlencode($userId)
            . '&country_id=' . $cid
            . '&region_id=' . $rid;
        $summary = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        break;

    case 'jobs':
        $chk = $conn->prepare('SELECT id FROM job_applications WHERE user_id = ? LIMIT 1');
        $chk->bind_param('s', $userId);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
        if ($exists) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'A job application with this user ID was already submitted. If you need changes, please contact the office.',
            ]);
            exit;
        }
        $redirect = 'job-application.php?id=' . rawurlencode($userId);
        $summary  = $userId;
        break;

    case 'medical':
        [$ok, $name] = row_found(
            $conn,
            'SELECT first_name, last_name, email FROM canada_medical_exams_requests WHERE user_id = ? LIMIT 1',
            's',
            $userId
        );
        if (!$ok) {
            echo json_encode(['status' => 'error', 'message' => 'No Canada medical exam request found for this user ID.']);
            exit;
        }
        $redirect = 'canada-medical-already-applied.php?id=' . rawurlencode($userId);
        $summary  = $name !== '' ? $name : $userId;
        break;
}

$conn->close();

echo json_encode([
    'status'        => 'success',
    'redirect_url' => $redirect,
    'summary'       => $summary,
]);

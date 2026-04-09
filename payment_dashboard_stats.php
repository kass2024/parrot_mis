<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id'], $_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

/* =====================================================
   HARD GUARD
===================================================== */
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Database connection not available'
    ]);
    exit;
}

/* =====================================================
   SAFE QUERY HELPER
===================================================== */
function run(mysqli $conn, string $sql, string $stage): mysqli_result {
    $res = $conn->query($sql);
    if (!$res) {
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'stage' => $stage,
            'mysqli_error' => $conn->error
        ], JSON_PRETTY_PRINT);
        exit;
    }
    return $res;
}

/* =====================================================
   UNIFIED STUDENTS SOURCE (SCHEMA-AWARE)
   — LOGIC SAFE —
===================================================== */
$studentsSource = "
(
    SELECT
        id,
        email,
        first_name,
        last_name
    FROM student_applications

    UNION ALL

    SELECT
        id,
        email,
        name    AS first_name,
        surname AS last_name
    FROM malta_applications

    UNION ALL

    SELECT
        id,
        email,
        first_name,
        last_name
    FROM turkey_applications
) sa
";

/* =====================================================
   KPI LIST MODE (MODAL)
   ?status=fully_paid | partial_paid | unpaid | outstanding
===================================================== */
$statusFilter = $_GET['status'] ?? null;

if ($statusFilter !== null) {

    $allowed = ['fully_paid', 'partial_paid', 'unpaid', 'outstanding'];
    if (!in_array($statusFilter, $allowed, true)) {
        echo json_encode([]);
        exit;
    }

    $sql = "
        SELECT
            ap.application_id,
            sa.email,
            CONCAT_WS(' ', sa.first_name, sa.last_name) AS student_name,

            COUNT(fi.id) AS total_items,

            SUM(
                CASE
                    WHEN COALESCE(pi.paid_amount,0) >= fi.amount
                    THEN 1 ELSE 0
                END
            ) AS fully_paid_items,

            SUM(
                CASE
                    WHEN COALESCE(pi.paid_amount,0) > 0
                    THEN 1 ELSE 0
                END
            ) AS paid_items,

            SUM(fi.amount) AS expected,
            COALESCE(SUM(pi.paid_amount),0) AS paid,
            MAX(pi.last_payment) AS last_payment

        FROM application_packages ap
        JOIN fee_items fi
            ON fi.package_id = ap.package_id

        LEFT JOIN (
            SELECT
                application_id,
                fee_item_id,
                SUM(amount_paid) AS paid_amount,
                MAX(paid_at) AS last_payment
            FROM application_payments
            WHERE status = 'PAID'
            GROUP BY application_id, fee_item_id
        ) pi
            ON pi.application_id = ap.application_id
           AND pi.fee_item_id = fi.id

        LEFT JOIN {$studentsSource}
            ON sa.id = ap.application_id

        GROUP BY ap.application_id
    ";

    $res = run($conn, $sql, 'kpi_list');
    $rows = [];

    while ($r = $res->fetch_assoc()) {

        if ($r['fully_paid_items'] == $r['total_items']) {
            $status = 'fully_paid';
        } elseif ($r['paid_items'] > 0) {
            $status = 'partial_paid';
        } else {
            $status = 'unpaid';
        }

        $match =
            ($statusFilter === 'fully_paid'   && $status === 'fully_paid') ||
            ($statusFilter === 'partial_paid' && $status === 'partial_paid') ||
            ($statusFilter === 'unpaid'       && $status === 'unpaid') ||
            ($statusFilter === 'outstanding'  && $r['paid'] < $r['expected']);

        if ($match) {
            $rows[] = [
                'application_id' => (int)$r['application_id'],
                'student_name'   => $r['student_name'] ?: 'Unknown Student',
                'email'          => $r['email'],
                'expected'       => (float)$r['expected'],
                'total_paid'     => (float)$r['paid'],
                'balance'        => max(0, (float)$r['expected'] - (float)$r['paid']),
                'status'         => $status,
                'last_payment'   => $r['last_payment']
            ];
        }
    }

    echo json_encode($rows, JSON_PRETTY_PRINT);
    exit;
}

/* =====================================================
   1. EXPECTED REVENUE
===================================================== */
$expectedSql = "
    SELECT COALESCE(SUM(fi.amount),0) AS expected
    FROM application_packages ap
    JOIN fee_items fi ON fi.package_id = ap.package_id
";
$expected = (float) run($conn, $expectedSql, 'expected')
    ->fetch_assoc()['expected'];

/* =====================================================
   2. TOTAL COLLECTED
===================================================== */
$collectedSql = "
    SELECT COALESCE(SUM(amount_paid),0) AS collected
    FROM application_payments
    WHERE status = 'PAID'
";
$collected = (float) run($conn, $collectedSql, 'collected')
    ->fetch_assoc()['collected'];

/* =====================================================
   3. STATUS COUNTS (ITEM-AWARE)
===================================================== */
$statusSql = "
    SELECT
        SUM(is_fully_paid)   AS fully_paid,
        SUM(is_partial_paid) AS partial_paid,
        SUM(is_unpaid)       AS unpaid
    FROM (
        SELECT
            ap.application_id,

            COUNT(fi.id) AS total_items,

            SUM(
                CASE
                    WHEN COALESCE(pi.paid_amount,0) >= fi.amount
                    THEN 1 ELSE 0
                END
            ) AS fully_paid_items,

            SUM(
                CASE
                    WHEN COALESCE(pi.paid_amount,0) > 0
                    THEN 1 ELSE 0
                END
            ) AS paid_items,

            CASE
                WHEN
                    SUM(CASE WHEN COALESCE(pi.paid_amount,0) >= fi.amount THEN 1 ELSE 0 END)
                    = COUNT(fi.id)
                THEN 1 ELSE 0
            END AS is_fully_paid,

            CASE
                WHEN
                    SUM(CASE WHEN COALESCE(pi.paid_amount,0) > 0 THEN 1 ELSE 0 END) > 0
                AND
                    SUM(CASE WHEN COALESCE(pi.paid_amount,0) >= fi.amount THEN 1 ELSE 0 END)
                    < COUNT(fi.id)
                THEN 1 ELSE 0
            END AS is_partial_paid,

            CASE
                WHEN SUM(CASE WHEN COALESCE(pi.paid_amount,0) > 0 THEN 1 ELSE 0 END) = 0
                THEN 1 ELSE 0
            END AS is_unpaid

        FROM application_packages ap
        JOIN fee_items fi
            ON fi.package_id = ap.package_id
        LEFT JOIN (
            SELECT
                application_id,
                fee_item_id,
                SUM(amount_paid) AS paid_amount
            FROM application_payments
            WHERE status = 'PAID'
            GROUP BY application_id, fee_item_id
        ) pi
            ON pi.application_id = ap.application_id
           AND pi.fee_item_id = fi.id
        GROUP BY ap.application_id
    ) x
";
$status = run($conn, $statusSql, 'status')->fetch_assoc();

/* =====================================================
   4. PAYMENT METHODS
===================================================== */
$methodsSql = "
    SELECT payment_method, SUM(amount_paid) AS total
    FROM application_payments
    WHERE status = 'PAID'
    GROUP BY payment_method
";
$methods = [];
$res = run($conn, $methodsSql, 'methods');
while ($row = $res->fetch_assoc()) {
    $methods[$row['payment_method']] = (float)$row['total'];
}

/* =====================================================
   5. RECENT PAYMENTS
===================================================== */
$recentSql = "
    SELECT
        CONCAT_WS(' ', sa.first_name, sa.last_name) AS student,
        SUM(p.amount_paid) AS amount_paid,
        p.payment_method,
        MAX(p.paid_at) AS paid_at
    FROM application_payments p
    LEFT JOIN application_packages ap
        ON ap.application_id = p.application_id
    LEFT JOIN {$studentsSource}
        ON sa.id = ap.application_id
    WHERE p.status = 'PAID'
    GROUP BY ap.application_id
    ORDER BY paid_at DESC
    LIMIT 10
";

$recent = run($conn, $recentSql, 'recent')->fetch_all(MYSQLI_ASSOC);

/* =====================================================
   FINAL RESPONSE
===================================================== */
echo json_encode([
    'error'       => false,
    'expected'    => $expected,
    'collected'   => $collected,
    'outstanding' => max(0, $expected - $collected),
    'status' => [
        'fully_paid'   => (int)$status['fully_paid'],
        'partial_paid' => (int)$status['partial_paid'],
        'unpaid'       => (int)$status['unpaid']
    ],
    'methods' => $methods,
    'recent'  => $recent
], JSON_PRETTY_PRINT);

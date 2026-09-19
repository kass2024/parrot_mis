<?php
/**
 * Seed new student-contract packages into fee_packages / fee_items
 * so they appear in Record Payment, invoices, and online payment.
 *
 * Packages:
 *   - p77ca        7.8  Study in Canada (With Your Own Admission Letter)
 *   - p77loa       7.9  Study in Canada having LOA (Without Lawyer Consultation)
 *   - p77loalawyer 7.10 Study in Canada having LOA (Lawyer Consultation)
 *   - p710promo    7.16 Visit Canada with Invitation on Promotion
 *
 * Also syncs renumbered titles for existing packages.
 *
 * Usage:
 *   php seed_new_contract_fee_packages.php
 *   http://localhost/parrot_mis/seed_new_contract_fee_packages.php
 *
 * Safe to re-run.
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/db.php';

function out(string $line): void
{
    echo $line . PHP_EOL;
}

/** @return array<string, string> code => title */
function fee_package_titles(): array
{
    return [
        'p71'         => '7.1 Study in the USA (Loan-Based)',
        'p72'         => '7.2 Study in the USA (Without Loan)',
        'p73'         => '7.3 Study in Europe (Without Loan)',
        'p74'         => '7.4 Study in Canada (Loan-Based)',
        'p75'         => '7.6 Study in Canada (Without Loan)',
        'p76'         => '7.7 Canada – High School Graduate (Loan-Based)',
        'p77ca'       => '7.8 Study in Canada (With Your Own Admission Letter)',
        'p77loa'      => '7.9 Study in Canada having LOA (Without Lawyer Consultation)',
        'p77loalawyer'=> '7.10 Study in Canada having LOA (Lawyer Consultation)',
        'p77'         => '7.11 Study in South Korea (Self-Sponsored)',
        'p78'         => '7.12 South Korea Visitor Visa',
        'p79'         => '7.13 Credit Transfer (Bachelor, Masters, PhD)',
        'p710'        => '7.14 Canada Visit Visa',
        'p710b'       => '7.15 Canada Visit Visa – With Invitation Letter',
        'p710promo'   => '7.16 Visit Canada with Invitation on Promotion',
        'p711'        => '7.17 USA Visit Visa',
        'p712'        => '7.18 Europe Visit Visa',
        'p713'        => '7.19 Asia Visit Visa',
        'p714'        => '7.20 SHORT COURSES-CANADA',
        'p715'        => '7.21 STUDY PhD IN CANADA-USA-EUROPE & ASIA',
        'p716'        => '7.22 WES EVALUATION – INTERNATIONAL EQUIVALENCE',
        'p717'        => '7.23 GUARANTEED EVALUATION SUPPORT!',
    ];
}

/**
 * @return array<string, array{currency:string,total:float,items:list<array{name:string,amount:float}>}>
 */
function packages_to_seed(): array
{
    return [
        'p77ca' => [
            'currency' => 'CAD',
            'total' => 1735.00,
            'items' => [
                ['name' => 'Document Handling, Visa Application & Biometric Fees', 'amount' => 735.00],
                ['name' => 'Service Fees (payable after visa approval)', 'amount' => 1000.00],
            ],
        ],
        'p77loa' => [
            'currency' => 'CAD',
            'total' => 2000.00,
            'items' => [
                ['name' => 'MIS Registration Fee', 'amount' => 225.00],
                ['name' => 'Documents Preparation Fees', 'amount' => 375.00],
                ['name' => 'Upfront Fees', 'amount' => 400.00],
                ['name' => 'After Visa Approval', 'amount' => 1000.00],
            ],
        ],
        'p77loalawyer' => [
            'currency' => 'CAD',
            'total' => 2500.00,
            'items' => [
                ['name' => 'MIS Registration Fee', 'amount' => 225.00],
                ['name' => 'Documents Preparation Fees', 'amount' => 375.00],
                ['name' => 'Lawyer Consultation Fees', 'amount' => 500.00],
                ['name' => 'Upfront Fees', 'amount' => 400.00],
                ['name' => 'After Visa Approval', 'amount' => 1000.00],
            ],
        ],
        'p710promo' => [
            'currency' => 'CAD',
            'total' => 2000.00,
            'items' => [
                ['name' => 'Invitation Fees', 'amount' => 360.00],
                ['name' => 'Documents Preparation Fees', 'amount' => 240.00],
                ['name' => 'Lawyer Consultation Fees', 'amount' => 400.00],
                ['name' => 'Service Fees After Visa Approval', 'amount' => 1000.00],
            ],
        ],
    ];
}

function get_package_id_by_code(mysqli $conn, string $code): ?int
{
    $stmt = $conn->prepare('SELECT id FROM fee_packages WHERE code = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}

function ensure_fee_package(
    mysqli $conn,
    string $code,
    string $title,
    string $currency,
    float $totalAmount
): array {
    $existingId = get_package_id_by_code($conn, $code);
    if ($existingId !== null) {
        $upd = $conn->prepare(
            'UPDATE fee_packages
             SET title = ?, currency = ?, total_amount = ?, total_expected = ?
             WHERE id = ?'
        );
        if ($upd) {
            $upd->bind_param('ssddi', $title, $currency, $totalAmount, $totalAmount, $existingId);
            $upd->execute();
            $upd->close();
        }

        return ['id' => $existingId, 'created' => false];
    }

    $stmt = $conn->prepare(
        'INSERT INTO fee_packages (code, title, currency, total_amount, total_expected)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('sssdd', $code, $title, $currency, $totalAmount, $totalAmount);
    if (!$stmt->execute()) {
        throw new RuntimeException('Insert fee_packages failed: ' . $stmt->error);
    }
    $packageId = (int) $conn->insert_id;
    $stmt->close();

    return ['id' => $packageId, 'created' => true];
}

function ensure_fee_item(
    mysqli $conn,
    int $packageId,
    string $name,
    float $amount,
    string $currency
): array {
    $stmt = $conn->prepare(
        'SELECT id, amount FROM fee_items WHERE package_id = ? AND name = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('is', $packageId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $itemId = (int) $row['id'];
        if ((float) $row['amount'] !== $amount) {
            $upd = $conn->prepare('UPDATE fee_items SET amount = ?, currency = ? WHERE id = ?');
            if ($upd) {
                $upd->bind_param('dsi', $amount, $currency, $itemId);
                $upd->execute();
                $upd->close();
            }
        }

        return ['id' => $itemId, 'created' => false];
    }

    $stmt = $conn->prepare(
        'INSERT INTO fee_items (package_id, name, amount, currency) VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('isds', $packageId, $name, $amount, $currency);
    if (!$stmt->execute()) {
        throw new RuntimeException('Insert fee_items failed: ' . $stmt->error);
    }
    $itemId = (int) $conn->insert_id;
    $stmt->close();

    return ['id' => $itemId, 'created' => true];
}

function update_package_title(mysqli $conn, string $code, string $title): string
{
    $id = get_package_id_by_code($conn, $code);
    if ($id === null) {
        return 'missing';
    }

    $stmt = $conn->prepare(
        'UPDATE fee_packages SET title = ? WHERE code = ? AND title <> ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('sss', $title, $code, $title);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected > 0 ? 'updated' : 'unchanged';
}

$titles = fee_package_titles();
$toSeed = packages_to_seed();
$lines = [
    'Seed new contract fee packages for Record Payment / Online Payment / Invoices',
    'Database: ' . ($conn->host_info ?? 'connected'),
    str_repeat('-', 72),
];

try {
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start transaction');
    }

    foreach ($toSeed as $code => $pkg) {
        $title = $titles[$code] ?? $code;
        $result = ensure_fee_package($conn, $code, $title, $pkg['currency'], $pkg['total']);
        $lines[] = sprintf(
            'Package %s: %s (id=%d) — %s %.2f',
            $code,
            $result['created'] ? 'INSERTED' : 'updated/exists',
            $result['id'],
            $pkg['currency'],
            $pkg['total']
        );

        foreach ($pkg['items'] as $item) {
            $itemResult = ensure_fee_item(
                $conn,
                $result['id'],
                $item['name'],
                $item['amount'],
                $pkg['currency']
            );
            $lines[] = sprintf(
                '  fee_item "%s": %s (id=%d, %.2f)',
                $item['name'],
                $itemResult['created'] ? 'INSERTED' : 'exists',
                $itemResult['id'],
                $item['amount']
            );
        }
        $lines[] = '';
    }

    $lines[] = 'Sync renumbered package titles:';
    foreach ($titles as $code => $title) {
        if (isset($toSeed[$code])) {
            continue;
        }
        $status = update_package_title($conn, $code, $title);
        $lines[] = sprintf('  %s => %s [%s]', $code, $title, $status);
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Commit failed');
    }

    $lines[] = '';
    $lines[] = 'Verify new packages:';
    $codes = array_keys($toSeed);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $sql = "SELECT fp.code, fp.id, fp.title, fp.total_amount, fp.currency, COUNT(fi.id) AS items
            FROM fee_packages fp
            LEFT JOIN fee_items fi ON fi.package_id = fp.id
            WHERE fp.code IN ($placeholders)
            GROUP BY fp.code, fp.id, fp.title, fp.total_amount, fp.currency
            ORDER BY fp.id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lines[] = sprintf(
            '  %s | id=%s | %s | total=%s %s | items=%s',
            $row['code'],
            $row['id'],
            $row['title'],
            $row['total_amount'],
            $row['currency'],
            $row['items']
        );
    }
    $stmt->close();

    $lines[] = '';
    $lines[] = 'Done. Packages now appear in Record Payment, Online Payment, and invoices.';
} catch (Throwable $e) {
    @$conn->rollback();
    $lines[] = '';
    $lines[] = 'ERROR: ' . $e->getMessage();
    out(implode(PHP_EOL, $lines));
    if (!$isCli) {
        http_response_code(500);
    }
    exit(1);
}

out(implode(PHP_EOL, $lines));

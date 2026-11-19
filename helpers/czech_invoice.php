<?php
declare(strict_types=1);

function cz_invoice_fee_items(): array
{
    return [
        [
            'code' => 'cz_inst_1',
            'name' => 'First installment',
            'amount' => 500.00,
            'when' => 'Due before processing begins. Refundable only under Article 6 if the agreed documents cannot be obtained.',
        ],
        [
            'code' => 'cz_inst_2',
            'name' => 'Second installment',
            'amount' => 1250.00,
            'when' => 'Due when the Work-Permit Documents are ready and made available. Non-refundable after that milestone.',
        ],
        [
            'code' => 'cz_inst_3',
            'name' => 'Third installment',
            'amount' => 750.00,
            'when' => 'Due only after official visa approval. Not payable if the visa is refused.',
        ],
    ];
}

function cz_invoice_program_total(): float
{
    $total = 0.0;
    foreach (cz_invoice_fee_items() as $item) {
        $total += (float) $item['amount'];
    }

    return round($total, 2);
}

function cz_invoice_money(float $amount): float
{
    return round($amount, 2);
}

function cz_invoice_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS czech_invoices (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          invoice_no VARCHAR(48) NOT NULL,
          contract_id INT UNSIGNED NOT NULL,
          payment_method VARCHAR(40) NOT NULL,
          comment VARCHAR(500) DEFAULT NULL,
          currency CHAR(3) NOT NULL DEFAULT 'EUR',
          total_amount DECIMAL(12,2) NOT NULL,
          recorded_by INT DEFAULT NULL,
          recorded_by_name VARCHAR(120) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY invoice_no (invoice_no),
          KEY contract_id (contract_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS czech_invoice_lines (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          invoice_id INT UNSIGNED NOT NULL,
          item_code VARCHAR(40) NOT NULL,
          item_name VARCHAR(255) NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          PRIMARY KEY (id),
          KEY invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** @return array<int, float> */
function cz_invoice_paid_totals_map(mysqli $conn): array
{
    cz_invoice_ensure_schema($conn);
    $map = [];
    $res = $conn->query('SELECT contract_id, SUM(total_amount) AS paid FROM czech_invoices GROUP BY contract_id');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int) $row['contract_id']] = cz_invoice_money((float) $row['paid']);
        }
        $res->free();
    }

    return $map;
}

/** @return array<string, float> */
function cz_invoice_paid_by_code(mysqli $conn, int $contractId): array
{
    $paid = [];
    foreach (cz_invoice_fee_items() as $item) {
        $paid[$item['code']] = 0.0;
    }
    if ($contractId <= 0) {
        return $paid;
    }
    $stmt = $conn->prepare(
        'SELECT l.item_code, COALESCE(SUM(l.amount), 0) AS paid
         FROM czech_invoice_lines l
         INNER JOIN czech_invoices i ON i.id = l.invoice_id
         WHERE i.contract_id = ?
         GROUP BY l.item_code'
    );
    if (!$stmt) {
        return $paid;
    }
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $code = (string) $row['item_code'];
        if (isset($paid[$code])) {
            $paid[$code] = cz_invoice_money((float) $row['paid']);
        }
    }
    $stmt->close();

    return $paid;
}

function cz_invoice_summary(mysqli $conn, int $contractId): array
{
    $paidByCode = cz_invoice_paid_by_code($conn, $contractId);
    $items = [];
    $paidTotal = 0.0;
    foreach (cz_invoice_fee_items() as $item) {
        $due = cz_invoice_money((float) $item['amount']);
        $paid = $paidByCode[$item['code']] ?? 0.0;
        $remaining = cz_invoice_money(max(0, $due - $paid));
        $paidTotal += $paid;
        $items[] = $item + [
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'due'),
        ];
    }
    $program = cz_invoice_program_total();

    return [
        'currency' => 'EUR',
        'program_total' => $program,
        'paid_total' => cz_invoice_money($paidTotal),
        'remaining_total' => cz_invoice_money(max(0, $program - $paidTotal)),
        'items' => $items,
    ];
}

/** @return list<array<string, mixed>> */
function cz_invoice_history(mysqli $conn, int $contractId): array
{
    $stmt = $conn->prepare(
        'SELECT id, invoice_no, payment_method, comment, total_amount, recorded_by_name, created_at
         FROM czech_invoices WHERE contract_id = ? ORDER BY id DESC LIMIT 30'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn ($row) => (int) $row['id'], $rows);
    $in = implode(',', $ids);
    $lines = [];
    $lineRes = $conn->query("SELECT invoice_id, item_name, amount FROM czech_invoice_lines WHERE invoice_id IN ($in) ORDER BY id ASC");
    if ($lineRes) {
        while ($line = $lineRes->fetch_assoc()) {
            $lines[(int) $line['invoice_id']][] = $line;
        }
        $lineRes->free();
    }
    foreach ($rows as &$row) {
        $row['lines'] = $lines[(int) $row['id']] ?? [];
    }
    unset($row);

    return $rows;
}

function cz_invoice_load(mysqli $conn, string $invoiceNo): ?array
{
    $invoiceNo = trim($invoiceNo);
    if ($invoiceNo === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT i.*, c.client_name, c.email, c.phone, c.reference_number, c.passport_number
         FROM czech_invoices i
         INNER JOIN czech_work_contracts c ON c.id = i.contract_id
         WHERE i.invoice_no = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $invoiceNo);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) {
        return null;
    }
    $id = (int) $invoice['id'];
    $lineStmt = $conn->prepare('SELECT item_code, item_name, amount FROM czech_invoice_lines WHERE invoice_id = ? ORDER BY id ASC');
    $invoice['lines'] = [];
    if ($lineStmt) {
        $lineStmt->bind_param('i', $id);
        $lineStmt->execute();
        $invoice['lines'] = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();
    }

    return $invoice;
}

/**
 * @param array<string, float|int|string> $amounts
 * @return array{invoice_no:string,total:float}
 */
function cz_invoice_record(mysqli $conn, int $contractId, array $amounts, string $method, string $comment): array
{
    cz_invoice_ensure_schema($conn);
    $allowedMethods = ['Cash', 'Bank Transfer', 'Mobile Money'];
    if (!in_array($method, $allowedMethods, true)) {
        throw new InvalidArgumentException('Choose a payment method');
    }
    $comment = function_exists('mb_substr') ? mb_substr(trim($comment), 0, 500) : substr(trim($comment), 0, 500);
    $catalog = [];
    foreach (cz_invoice_fee_items() as $item) {
        $catalog[$item['code']] = $item;
    }
    $lines = [];
    foreach ($catalog as $code => $item) {
        $amount = cz_invoice_money((float) ($amounts[$code] ?? 0));
        if ($amount <= 0) {
            continue;
        }
        $lines[] = [
            'code' => $code,
            'name' => $item['name'],
            'amount' => $amount,
            'due' => cz_invoice_money((float) $item['amount']),
        ];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Enter an amount for at least one installment');
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, client_name, reference_number FROM czech_work_contracts WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$lock) {
            throw new RuntimeException('Could not open the contract');
        }
        $lock->bind_param('i', $contractId);
        $lock->execute();
        $contract = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$contract) {
            throw new InvalidArgumentException('Contract not found');
        }
        $already = cz_invoice_paid_by_code($conn, $contractId);
        foreach ($lines as $line) {
            $paid = $already[$line['code']] ?? 0.0;
            if (($paid + $line['amount']) - $line['due'] > 0.009) {
                $left = cz_invoice_money(max(0, $line['due'] - $paid));
                throw new InvalidArgumentException($line['name'] . ' can take at most EUR ' . number_format($left, 2));
            }
        }
        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['amount'];
        }
        $total = cz_invoice_money($total);
        require_once __DIR__ . '/payment_receipt_recorded_by.php';
        $admin = pcvc_receipt_admin_from_session($conn);
        $recordedBy = $admin['id'] > 0 ? (int) $admin['id'] : 0;
        $recordedName = $admin['name'] !== '' ? $admin['name'] : '';
        $invoiceNo = '';
        $invoiceId = 0;
        for ($try = 0; $try < 5; $try++) {
            $invoiceNo = 'INV-CZ-' . date('Ymd-His') . '-' . random_int(100, 999);
            $ins = $conn->prepare(
                'INSERT INTO czech_invoices
                 (invoice_no, contract_id, payment_method, comment, currency, total_amount, recorded_by, recorded_by_name)
                 VALUES (?, ?, ?, ?, \'EUR\', ?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('Could not save the invoice');
            }
            $ins->bind_param('sissdis', $invoiceNo, $contractId, $method, $comment, $total, $recordedBy, $recordedName);
            if ($ins->execute()) {
                $invoiceId = (int) $ins->insert_id;
                $ins->close();
                break;
            }
            $ins->close();
            $invoiceNo = '';
        }
        if ($invoiceId <= 0) {
            throw new RuntimeException('Could not save the invoice');
        }
        $lineIns = $conn->prepare('INSERT INTO czech_invoice_lines (invoice_id, item_code, item_name, amount) VALUES (?, ?, ?, ?)');
        if (!$lineIns) {
            throw new RuntimeException('Could not save the invoice lines');
        }
        foreach ($lines as $line) {
            $lineIns->bind_param('issd', $invoiceId, $line['code'], $line['name'], $line['amount']);
            if (!$lineIns->execute()) {
                $lineIns->close();
                throw new RuntimeException('Could not save the invoice lines');
            }
        }
        $lineIns->close();
        $conn->commit();

        return ['invoice_no' => $invoiceNo, 'total' => $total];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

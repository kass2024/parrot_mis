<?php
declare(strict_types=1);

/**
 * Dubai work-abroad invoices.
 * Payable items are the two installments in the service agreement (USD 850).
 */

function dxb_invoice_fee_items(): array
{
    return [
        [
            'code' => 'dxb_inst_1',
            'name' => 'First installment',
            'amount' => 425.00,
            'when' => 'Paid upon signing. Refundable in full if the UAE employment visa is officially refused.',
            'includes' => [],
        ],
        [
            'code' => 'dxb_inst_2',
            'name' => 'Second installment',
            'amount' => 425.00,
            'when' => 'Paid only after official visa approval and verification, before final ticket arrangements, and only where lawful.',
            'note' => 'This installment is not due if the visa is refused.',
            'includes' => [],
        ],
    ];
}

function dxb_invoice_program_total(): float
{
    $total = 0.0;
    foreach (dxb_invoice_fee_items() as $item) {
        $total += (float) $item['amount'];
    }

    return round($total, 2);
}

function dxb_invoice_item(string $code): ?array
{
    foreach (dxb_invoice_fee_items() as $item) {
        if ($item['code'] === $code) {
            return $item;
        }
    }

    return null;
}

function dxb_invoice_money(float $amount): float
{
    return round($amount, 2);
}

function dxb_invoice_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS dubai_invoices (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          invoice_no VARCHAR(48) NOT NULL,
          contract_id INT UNSIGNED NOT NULL,
          payment_method VARCHAR(40) NOT NULL,
          comment VARCHAR(500) DEFAULT NULL,
          currency CHAR(3) NOT NULL DEFAULT 'USD',
          total_amount DECIMAL(12,2) NOT NULL,
          recorded_by INT DEFAULT NULL,
          recorded_by_name VARCHAR(120) DEFAULT NULL,
          pdf_path VARCHAR(255) DEFAULT NULL,
          emailed_at DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY invoice_no (invoice_no),
          KEY contract_id (contract_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS dubai_invoice_lines (
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
function dxb_invoice_paid_totals_map(mysqli $conn): array
{
    dxb_invoice_ensure_schema($conn);
    $map = [];
    $res = $conn->query('SELECT contract_id, SUM(total_amount) AS paid FROM dubai_invoices GROUP BY contract_id');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int) $row['contract_id']] = dxb_invoice_money((float) $row['paid']);
        }
        $res->free();
    }

    return $map;
}

/** @return array<string, float> */
function dxb_invoice_paid_by_code(mysqli $conn, int $contractId): array
{
    $paid = [];
    foreach (dxb_invoice_fee_items() as $item) {
        $paid[$item['code']] = 0.0;
    }
    if ($contractId <= 0) {
        return $paid;
    }
    $stmt = $conn->prepare(
        'SELECT l.item_code, COALESCE(SUM(l.amount), 0) AS paid
         FROM dubai_invoice_lines l
         INNER JOIN dubai_invoices i ON i.id = l.invoice_id
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
            $paid[$code] = dxb_invoice_money((float) $row['paid']);
        }
    }
    $stmt->close();

    return $paid;
}

function dxb_invoice_summary(mysqli $conn, int $contractId): array
{
    $paidByCode = dxb_invoice_paid_by_code($conn, $contractId);
    $items = [];
    $paidTotal = 0.0;
    foreach (dxb_invoice_fee_items() as $item) {
        $due = dxb_invoice_money((float) $item['amount']);
        $paid = $paidByCode[$item['code']] ?? 0.0;
        $remaining = dxb_invoice_money(max(0, $due - $paid));
        $paidTotal += $paid;
        $items[] = $item + [
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'due'),
        ];
    }
    $program = dxb_invoice_program_total();

    return [
        'currency' => 'USD',
        'program_total' => $program,
        'paid_total' => dxb_invoice_money($paidTotal),
        'remaining_total' => dxb_invoice_money(max(0, $program - $paidTotal)),
        'items' => $items,
    ];
}

/** @return list<array<string, mixed>> */
function dxb_invoice_history(mysqli $conn, int $contractId): array
{
    $stmt = $conn->prepare(
        'SELECT id, invoice_no, payment_method, comment, total_amount, recorded_by_name, emailed_at, created_at
         FROM dubai_invoices WHERE contract_id = ? ORDER BY id DESC LIMIT 30'
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
    $lineRes = $conn->query("SELECT invoice_id, item_name, amount FROM dubai_invoice_lines WHERE invoice_id IN ($in) ORDER BY id ASC");
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

function dxb_invoice_load(mysqli $conn, string $invoiceNo): ?array
{
    $invoiceNo = trim($invoiceNo);
    if ($invoiceNo === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT i.*, c.client_name, c.email, c.phone, c.reference_number, c.position, c.passport_number
         FROM dubai_invoices i
         INNER JOIN dubai_work_contracts c ON c.id = i.contract_id
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
    $lineStmt = $conn->prepare('SELECT item_code, item_name, amount FROM dubai_invoice_lines WHERE invoice_id = ? ORDER BY id ASC');
    $invoice['lines'] = [];
    if ($lineStmt) {
        $lineStmt->bind_param('i', $id);
        $lineStmt->execute();
        $invoice['lines'] = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();
    }

    return $invoice;
}

function dxb_invoice_receipt_data(array $invoice): array
{
    $name = trim((string) ($invoice['client_name'] ?? ''));
    $method = (string) ($invoice['payment_method'] ?? 'Cash');
    $comment = trim((string) ($invoice['comment'] ?? ''));
    if ($comment !== '') {
        $method .= ' — ' . $comment;
    }
    $items = [];
    foreach ($invoice['lines'] ?? [] as $line) {
        $catalog = dxb_invoice_item((string) ($line['item_code'] ?? ''));
        $items[] = [
            'name' => (string) ($line['item_name'] ?? ''),
            'amount' => (float) ($line['amount'] ?? 0),
            'comment' => (string) ($catalog['when'] ?? ''),
        ];
    }

    return [
        'receipt_no' => (string) $invoice['invoice_no'],
        'application_id' => (string) ($invoice['reference_number'] ?? $invoice['contract_id'] ?? ''),
        'customer_name' => $name !== '' ? $name : 'Customer',
        'package_title' => 'Dubai Work Abroad Agreement',
        'currency' => 'USD',
        'payment_method' => $method,
        'created_at' => (string) ($invoice['created_at'] ?? date('Y-m-d H:i:s')),
        'total_amount' => (float) ($invoice['total_amount'] ?? 0),
        'items' => $items,
    ];
}

function dxb_invoice_write_pdf(array $receiptData): string
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF library is not available');
    }
    require_once $autoload;
    require_once __DIR__ . '/receipt_render.php';
    $html = pcvc_render_receipt_html($receiptData);
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('chroot', dirname(__DIR__));
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dir = dirname(__DIR__) . '/receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . $receiptData['receipt_no'] . '.pdf';
    file_put_contents($file, $dompdf->output());

    return $file;
}

/**
 * @param array<string, float|int|string> $amounts
 * @return array{invoice_no:string,total:float,emailed:bool,email_error:string}
 */
function dxb_invoice_record(mysqli $conn, int $contractId, array $amounts, string $method, string $comment, bool $sendEmail): array
{
    dxb_invoice_ensure_schema($conn);
    $allowedMethods = ['Cash', 'Bank Transfer', 'Mobile Money'];
    if (!in_array($method, $allowedMethods, true)) {
        throw new InvalidArgumentException('Choose a payment method');
    }
    $comment = trim($comment);
    $comment = function_exists('mb_substr') ? mb_substr($comment, 0, 500) : substr($comment, 0, 500);

    $lines = [];
    foreach (dxb_invoice_fee_items() as $item) {
        $amount = dxb_invoice_money((float) ($amounts[$item['code']] ?? 0));
        if ($amount <= 0) {
            continue;
        }
        $lines[] = [
            'code' => $item['code'],
            'name' => $item['name'],
            'amount' => $amount,
            'due' => dxb_invoice_money((float) $item['amount']),
        ];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Enter an amount for at least one installment');
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, client_name, email, reference_number FROM dubai_work_contracts WHERE id = ? LIMIT 1 FOR UPDATE');
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

        $already = dxb_invoice_paid_by_code($conn, $contractId);
        foreach ($lines as $line) {
            $paid = $already[$line['code']] ?? 0.0;
            if (($paid + $line['amount']) - $line['due'] > 0.009) {
                $left = dxb_invoice_money(max(0, $line['due'] - $paid));
                throw new InvalidArgumentException($line['name'] . ' can take at most USD ' . number_format($left, 2));
            }
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['amount'];
        }
        $total = dxb_invoice_money($total);

        require_once __DIR__ . '/payment_receipt_recorded_by.php';
        $admin = pcvc_receipt_admin_from_session($conn);
        $recordedBy = $admin['id'] > 0 ? (int) $admin['id'] : 0;
        $recordedName = $admin['name'] !== '' ? $admin['name'] : '';

        $invoiceNo = '';
        $invoiceId = 0;
        for ($try = 0; $try < 5; $try++) {
            $invoiceNo = 'INV-DXB-' . date('Ymd-His') . '-' . random_int(100, 999);
            $ins = $conn->prepare(
                'INSERT INTO dubai_invoices
                 (invoice_no, contract_id, payment_method, comment, currency, total_amount, recorded_by, recorded_by_name)
                 VALUES (?, ?, ?, ?, \'USD\', ?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('Could not save the invoice');
            }
            $ins->bind_param('sissdis', $invoiceNo, $contractId, $method, $comment, $total, $recordedBy, $recordedName);
            $ok = $ins->execute();
            $err = $ins->errno;
            $invoiceId = (int) $ins->insert_id;
            $ins->close();
            if ($ok) {
                break;
            }
            if ($err !== 1062) {
                throw new RuntimeException('Could not save the invoice');
            }
            $invoiceNo = '';
        }
        if ($invoiceNo === '' || $invoiceId <= 0) {
            throw new RuntimeException('Could not assign an invoice number');
        }

        $lineIns = $conn->prepare('INSERT INTO dubai_invoice_lines (invoice_id, item_code, item_name, amount) VALUES (?, ?, ?, ?)');
        if (!$lineIns) {
            throw new RuntimeException('Could not save invoice items');
        }
        foreach ($lines as $line) {
            $lineIns->bind_param('issd', $invoiceId, $line['code'], $line['name'], $line['amount']);
            if (!$lineIns->execute()) {
                $lineIns->close();
                throw new RuntimeException('Could not save invoice items');
            }
        }
        $lineIns->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $saved = dxb_invoice_load($conn, $invoiceNo);
    $pdfPath = '';
    $emailed = false;
    $emailError = '';
    if ($saved) {
        try {
            $pdfPath = dxb_invoice_write_pdf(dxb_invoice_receipt_data($saved));
            $rel = 'receipts/' . basename($pdfPath);
            $upd = $conn->prepare('UPDATE dubai_invoices SET pdf_path = ? WHERE id = ?');
            if ($upd) {
                $id = (int) $saved['id'];
                $upd->bind_param('si', $rel, $id);
                $upd->execute();
                $upd->close();
            }
        } catch (Throwable $pdfErr) {
            error_log('dubai invoice pdf: ' . $pdfErr->getMessage());
            $emailError = 'Invoice saved. The PDF could not be created.';
        }

        $email = trim((string) ($saved['email'] ?? ''));
        if ($sendEmail && $pdfPath !== '' && is_file($pdfPath) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mailResult = dxb_invoice_email($saved, $pdfPath);
            $emailed = $mailResult['ok'];
            if ($emailed) {
                $mark = $conn->prepare('UPDATE dubai_invoices SET emailed_at = NOW() WHERE invoice_no = ?');
                if ($mark) {
                    $mark->bind_param('s', $invoiceNo);
                    $mark->execute();
                    $mark->close();
                }
                $emailError = '';
            } else {
                $emailError = 'Invoice saved. Email was not sent.';
            }
        } elseif ($sendEmail && $emailError === '') {
            $emailError = 'Invoice saved. Email was not sent.';
        }
    }

    return [
        'invoice_no' => $invoiceNo,
        'total' => $total,
        'emailed' => $emailed,
        'email_error' => $emailError,
    ];
}

/** @return array{ok:bool,error?:string} */
function dxb_invoice_email(array $invoice, string $pdfPath): array
{
    require_once __DIR__ . '/mail_smtp.php';
    $name = trim((string) ($invoice['client_name'] ?? ''));
    $no = (string) $invoice['invoice_no'];
    $total = number_format((float) $invoice['total_amount'], 2);
    $method = htmlspecialchars((string) $invoice['payment_method'], ENT_QUOTES, 'UTF-8');
    $nameEsc = htmlspecialchars($name !== '' ? $name : 'Client', ENT_QUOTES, 'UTF-8');
    $noEsc = htmlspecialchars($no, ENT_QUOTES, 'UTF-8');
    $lineRows = '';
    foreach ($invoice['lines'] ?? [] as $line) {
        $lineRows .= '<tr><td style="padding:6px 0;">' . htmlspecialchars((string) $line['item_name'], ENT_QUOTES, 'UTF-8')
            . '</td><td style="padding:6px 0;text-align:right;font-weight:700;">USD '
            . number_format((float) $line['amount'], 2) . '</td></tr>';
    }
    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2933;max-width:640px;margin:0 auto;">
      <div style="background:#1c1917;color:#f6e7c1;padding:18px 22px;border-radius:10px 10px 0 0;">
        <div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">Parrot Canada Visa Consultant</div>
        <div style="font-size:20px;font-weight:800;margin-top:6px;color:#fff;">Dubai work abroad receipt</div>
      </div>
      <div style="border:1px solid #e5e7eb;border-top:0;padding:22px;border-radius:0 0 10px 10px;background:#fff;">
        <p>Dear <strong>' . $nameEsc . '</strong>,</p>
        <p>Thank you for your payment toward the Dubai work abroad agreement. Your official receipt is attached.</p>
        <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;">
          <tr><td style="padding:8px 0;color:#6b7280;width:140px;"><strong>Receipt No.</strong></td><td>' . $noEsc . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Program</strong></td><td>Dubai Work Abroad Agreement</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Amount paid</strong></td><td style="font-weight:700;">USD ' . $total . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Method</strong></td><td>' . $method . '</td></tr>
        </table>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">' . $lineRows . '</table>
        <p style="font-size:12px;color:#6b7280;">Please keep this receipt for your records.</p>
      </div>
    </div>';

    return sendSMTPMailDetailed(
        (string) $invoice['email'],
        'Dubai work abroad receipt – ' . $no,
        $html,
        [['path' => $pdfPath, 'name' => $no . '.pdf']]
    );
}

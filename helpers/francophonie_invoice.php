<?php
declare(strict_types=1);

/**
 * Francophone Mobility invoices.
 * Payable items are the three installments in the service agreement (CAD 8,000).
 */

function fm_invoice_fee_items(): array
{
    return [
        [
            'code' => 'fm_inst_1',
            'name' => 'First Installment',
            'amount' => 850.00,
            'when' => 'Paid before commencement of agreed, legally chargeable client services.',
            'includes' => [],
        ],
        [
            'code' => 'fm_inst_2',
            'name' => 'Second Installment',
            'amount' => 3150.00,
            'when' => 'Paid only when Canada requests a medical check-up.',
            'note' => 'This installment does not become payable if the work permit is refused before this milestone.',
            'includes' => [],
        ],
        [
            'code' => 'fm_inst_3',
            'name' => 'Third Installment',
            'amount' => 4000.00,
            'when' => 'Paid only after Canadian work permit approval.',
            'includes' => [],
        ],
    ];
}

function fm_invoice_program_total(): float
{
    $total = 0.0;
    foreach (fm_invoice_fee_items() as $item) {
        $total += (float) $item['amount'];
    }
    return round($total, 2);
}

function fm_invoice_item(string $code): ?array
{
    foreach (fm_invoice_fee_items() as $item) {
        if ($item['code'] === $code) {
            return $item;
        }
    }
    return null;
}

function fm_invoice_money(float $amount): float
{
    return round($amount, 2);
}

function fm_invoice_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `fm_invoices` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `invoice_no` varchar(48) NOT NULL,
          `application_id` int(11) NOT NULL,
          `payment_method` varchar(40) NOT NULL,
          `comment` varchar(500) DEFAULT NULL,
          `currency` char(3) NOT NULL DEFAULT 'CAD',
          `total_amount` decimal(12,2) NOT NULL,
          `recorded_by` int(11) DEFAULT NULL,
          `recorded_by_name` varchar(120) DEFAULT NULL,
          `pdf_path` varchar(255) DEFAULT NULL,
          `emailed_at` datetime DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `invoice_no` (`invoice_no`),
          KEY `application_id` (`application_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `fm_invoice_lines` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `invoice_id` int(11) NOT NULL,
          `item_code` varchar(40) NOT NULL,
          `item_name` varchar(255) NOT NULL,
          `amount` decimal(12,2) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `invoice_id` (`invoice_id`),
          KEY `item_code` (`item_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** @return array<int, float> application id => total paid */
function fm_invoice_paid_totals_map(mysqli $conn): array
{
    fm_invoice_ensure_schema($conn);
    $map = [];
    $res = $conn->query('SELECT application_id, SUM(total_amount) AS paid FROM fm_invoices GROUP BY application_id');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int) $row['application_id']] = fm_invoice_money((float) $row['paid']);
        }
        $res->free();
    }
    return $map;
}

/** @return array<string, float> item code => amount already paid */
function fm_invoice_paid_by_code(mysqli $conn, int $applicationId): array
{
    $paid = [];
    foreach (fm_invoice_fee_items() as $item) {
        $paid[$item['code']] = 0.0;
    }
    if ($applicationId <= 0) {
        return $paid;
    }

    $stmt = $conn->prepare(
        'SELECT l.item_code, COALESCE(SUM(l.amount), 0) AS paid
         FROM fm_invoice_lines l
         INNER JOIN fm_invoices i ON i.id = l.invoice_id
         WHERE i.application_id = ?
         GROUP BY l.item_code'
    );
    if (!$stmt) {
        return $paid;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $code = (string) $row['item_code'];
        if (isset($paid[$code])) {
            $paid[$code] = fm_invoice_money((float) $row['paid']);
        }
    }
    $stmt->close();
    return $paid;
}

function fm_invoice_summary(mysqli $conn, int $applicationId): array
{
    $paidByCode = fm_invoice_paid_by_code($conn, $applicationId);
    $items = [];
    $paidTotal = 0.0;
    foreach (fm_invoice_fee_items() as $item) {
        $due = fm_invoice_money((float) $item['amount']);
        $paid = $paidByCode[$item['code']] ?? 0.0;
        $remaining = fm_invoice_money(max(0, $due - $paid));
        $paidTotal += $paid;
        $items[] = $item + [
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'due'),
        ];
    }
    $program = fm_invoice_program_total();
    return [
        'currency' => 'CAD',
        'program_total' => $program,
        'paid_total' => fm_invoice_money($paidTotal),
        'remaining_total' => fm_invoice_money(max(0, $program - $paidTotal)),
        'items' => $items,
    ];
}

/** @return list<array<string, mixed>> */
function fm_invoice_history(mysqli $conn, int $applicationId): array
{
    $stmt = $conn->prepare(
        'SELECT id, invoice_no, payment_method, comment, total_amount, recorded_by_name, emailed_at, created_at
         FROM fm_invoices
         WHERE application_id = ?
         ORDER BY id DESC
         LIMIT 30'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return [];
    }

    $ids = array_map(static fn($row) => (int) $row['id'], $rows);
    $in = implode(',', $ids);
    $lines = [];
    $lineRes = $conn->query(
        "SELECT invoice_id, item_name, amount FROM fm_invoice_lines WHERE invoice_id IN ($in) ORDER BY id ASC"
    );
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

function fm_invoice_load(mysqli $conn, string $invoiceNo): ?array
{
    $invoiceNo = trim($invoiceNo);
    if ($invoiceNo === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT i.*, a.first_name, a.last_name, a.email, a.reference_id, a.phone_number, a.profession
         FROM fm_invoices i
         INNER JOIN francophonie_mobility_applications a ON a.id = i.application_id
         WHERE i.invoice_no = ?
         LIMIT 1'
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
    $lineStmt = $conn->prepare('SELECT item_code, item_name, amount FROM fm_invoice_lines WHERE invoice_id = ? ORDER BY id ASC');
    $invoice['lines'] = [];
    if ($lineStmt) {
        $lineStmt->bind_param('i', $id);
        $lineStmt->execute();
        $invoice['lines'] = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();
    }
    return $invoice;
}

function fm_invoice_receipt_data(array $invoice): array
{
    $name = trim((string) ($invoice['first_name'] ?? '') . ' ' . (string) ($invoice['last_name'] ?? ''));
    $method = (string) ($invoice['payment_method'] ?? 'Cash');
    $comment = trim((string) ($invoice['comment'] ?? ''));
    if ($comment !== '') {
        $method .= ' — ' . $comment;
    }

    $items = [];
    foreach ($invoice['lines'] ?? [] as $line) {
        $catalog = fm_invoice_item((string) ($line['item_code'] ?? ''));
        $items[] = [
            'name' => (string) ($line['item_name'] ?? ''),
            'amount' => (float) ($line['amount'] ?? 0),
            'comment' => (string) ($catalog['when'] ?? ''),
        ];
    }

    return [
        'receipt_no' => (string) $invoice['invoice_no'],
        'application_id' => (string) ($invoice['reference_id'] ?? $invoice['application_id'] ?? ''),
        'customer_name' => $name !== '' ? $name : 'Customer',
        'package_title' => 'Francophone Mobility Program',
        'currency' => 'CAD',
        'payment_method' => $method,
        'created_at' => (string) ($invoice['created_at'] ?? date('Y-m-d H:i:s')),
        'total_amount' => (float) ($invoice['total_amount'] ?? 0),
        'items' => $items,
    ];
}

function fm_invoice_write_pdf(array $receiptData): string
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
 * @param array<string, float|int|string> $amounts item code => amount to record
 * @return array{invoice_no:string,total:float,emailed:bool,email_error:string}
 */
function fm_invoice_record(
    mysqli $conn,
    int $applicationId,
    array $amounts,
    string $method,
    string $comment,
    bool $sendEmail
): array {
    fm_invoice_ensure_schema($conn);

    $allowedMethods = ['Cash', 'Bank Transfer', 'Mobile Money'];
    if (!in_array($method, $allowedMethods, true)) {
        throw new InvalidArgumentException('Choose a payment method');
    }
    $comment = trim($comment);
    $comment = function_exists('mb_substr') ? mb_substr($comment, 0, 500) : substr($comment, 0, 500);

    $lines = [];
    foreach (fm_invoice_fee_items() as $item) {
        $amount = fm_invoice_money((float) ($amounts[$item['code']] ?? 0));
        if ($amount <= 0) {
            continue;
        }
        $lines[] = [
            'code' => $item['code'],
            'name' => $item['name'],
            'amount' => $amount,
            'due' => fm_invoice_money((float) $item['amount']),
        ];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Enter an amount for at least one installment');
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, first_name, last_name, email, reference_id FROM francophonie_mobility_applications WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$lock) {
            throw new RuntimeException('Could not open the application');
        }
        $lock->bind_param('i', $applicationId);
        $lock->execute();
        $applicant = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$applicant) {
            throw new InvalidArgumentException('Application not found');
        }

        $already = fm_invoice_paid_by_code($conn, $applicationId);
        foreach ($lines as $line) {
            $paid = $already[$line['code']] ?? 0.0;
            if (($paid + $line['amount']) - $line['due'] > 0.009) {
                $left = fm_invoice_money(max(0, $line['due'] - $paid));
                throw new InvalidArgumentException($line['name'] . ' can take at most CAD ' . number_format($left, 2));
            }
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['amount'];
        }
        $total = fm_invoice_money($total);

        require_once __DIR__ . '/payment_receipt_recorded_by.php';
        $admin = pcvc_receipt_admin_from_session($conn);
        $recordedBy = $admin['id'] > 0 ? (int) $admin['id'] : 0;
        $recordedName = $admin['name'] !== '' ? $admin['name'] : '';

        $invoiceNo = '';
        $invoiceId = 0;
        for ($try = 0; $try < 5; $try++) {
            $invoiceNo = 'INV-FM-' . date('Ymd-His') . '-' . random_int(100, 999);
            $ins = $conn->prepare(
                'INSERT INTO fm_invoices
                 (invoice_no, application_id, payment_method, comment, currency, total_amount, recorded_by, recorded_by_name)
                 VALUES (?, ?, ?, ?, \'CAD\', ?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('Could not save the invoice');
            }
            $ins->bind_param('sissdis', $invoiceNo, $applicationId, $method, $comment, $total, $recordedBy, $recordedName);
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

        $lineIns = $conn->prepare(
            'INSERT INTO fm_invoice_lines (invoice_id, item_code, item_name, amount) VALUES (?, ?, ?, ?)'
        );
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

    $saved = fm_invoice_load($conn, $invoiceNo);
    $pdfPath = '';
    $emailed = false;
    $emailError = '';
    if ($saved) {
        try {
            $pdfPath = fm_invoice_write_pdf(fm_invoice_receipt_data($saved));
            $rel = 'receipts/' . basename($pdfPath);
            $upd = $conn->prepare('UPDATE fm_invoices SET pdf_path = ? WHERE id = ?');
            if ($upd) {
                $id = (int) $saved['id'];
                $upd->bind_param('si', $rel, $id);
                $upd->execute();
                $upd->close();
            }
        } catch (Throwable $pdfErr) {
            error_log('fm invoice pdf: ' . $pdfErr->getMessage());
            $emailError = 'Invoice saved. The PDF could not be created.';
        }

        $email = trim((string) ($saved['email'] ?? ''));
        if ($sendEmail && $pdfPath !== '' && is_file($pdfPath) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mailResult = fm_invoice_email($saved, $pdfPath);
            $emailed = $mailResult['ok'];
            if ($emailed) {
                $mark = $conn->prepare('UPDATE fm_invoices SET emailed_at = NOW() WHERE invoice_no = ?');
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
function fm_invoice_email(array $invoice, string $pdfPath): array
{
    require_once __DIR__ . '/mail_smtp.php';
    $name = trim((string) ($invoice['first_name'] ?? '') . ' ' . (string) ($invoice['last_name'] ?? ''));
    $no = (string) $invoice['invoice_no'];
    $total = number_format((float) $invoice['total_amount'], 2);
    $method = htmlspecialchars((string) $invoice['payment_method'], ENT_QUOTES, 'UTF-8');
    $nameEsc = htmlspecialchars($name !== '' ? $name : 'Client', ENT_QUOTES, 'UTF-8');
    $noEsc = htmlspecialchars($no, ENT_QUOTES, 'UTF-8');

    $lineRows = '';
    foreach ($invoice['lines'] ?? [] as $line) {
        $lineRows .= '<tr><td style="padding:6px 0;">' . htmlspecialchars((string) $line['item_name'], ENT_QUOTES, 'UTF-8')
            . '</td><td style="padding:6px 0;text-align:right;font-weight:700;">CAD '
            . number_format((float) $line['amount'], 2) . '</td></tr>';
    }

    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2933;max-width:640px;margin:0 auto;">
      <div style="background:linear-gradient(135deg,#1e4d2b,#3661B9);color:#fff;padding:18px 22px;border-radius:10px 10px 0 0;">
        <div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;opacity:.85;">Parrot Canada Visa Consultant</div>
        <div style="font-size:20px;font-weight:800;margin-top:6px;">Francophone Mobility receipt</div>
      </div>
      <div style="border:1px solid #e5e7eb;border-top:0;padding:22px;border-radius:0 0 10px 10px;background:#fff;">
        <p>Dear <strong>' . $nameEsc . '</strong>,</p>
        <p>Thank you for your payment toward the Francophone Mobility program. Your official receipt is attached.</p>
        <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;">
          <tr><td style="padding:8px 0;color:#6b7280;width:140px;"><strong>Receipt No.</strong></td><td>' . $noEsc . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Program</strong></td><td>Francophone Mobility Program</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Amount paid</strong></td><td style="font-weight:700;color:#1e4d2b;">CAD ' . $total . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;"><strong>Method</strong></td><td>' . $method . '</td></tr>
        </table>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">' . $lineRows . '</table>
        <p style="font-size:12px;color:#6b7280;">Please keep this receipt for your records.</p>
      </div>
    </div>';

    return sendSMTPMailDetailed(
        (string) $invoice['email'],
        'Francophone Mobility receipt – ' . $no,
        $html,
        [['path' => $pdfPath, 'name' => $no . '.pdf']]
    );
}

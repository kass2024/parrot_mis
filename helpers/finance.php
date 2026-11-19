<?php
declare(strict_types=1);

function finance_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function finance_money(float $amount, string $currency = 'CAD'): string
{
    return $currency . ' ' . number_format($amount, 2, '.', ',');
}

function finance_period(?DateTime $now = null): array
{
    $now = $now ?? new DateTime('now', new DateTimeZone('America/Toronto'));
    $start = $now->format('Y-m-01');
    $end = $now->format('Y-m-t');

    return [
        'year' => (int) $now->format('Y'),
        'month' => (int) $now->format('n'),
        'start' => $start,
        'end' => $end,
        'label' => $now->format('F Y'),
    ];
}

function finance_ensure_schema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS finance_budgets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_year SMALLINT NOT NULL,
            period_month TINYINT NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            currency CHAR(3) NOT NULL DEFAULT 'CAD',
            uploaded_by INT DEFAULT NULL,
            uploaded_at DATETIME DEFAULT NULL,
            closed_by INT DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_finance_period (period_year, period_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS finance_budget_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            budget_id INT UNSIGNED NOT NULL,
            line_code VARCHAR(40) NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_finance_line (budget_id, line_code),
            KEY budget_id (budget_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS finance_budget_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            budget_id INT UNSIGNED NOT NULL,
            line_id INT UNSIGNED NOT NULL,
            requested_by INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            purpose VARCHAR(500) NOT NULL,
            status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
            decision_note VARCHAR(255) DEFAULT NULL,
            decided_by INT DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY budget_id (budget_id),
            KEY line_id (line_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function finance_table_exists(mysqli $conn, string $table): bool
{
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    if ($safe === '') {
        return false;
    }
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($safe) . "'");

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function finance_close_elapsed(mysqli $conn, array $period): void
{
    finance_ensure_schema($conn);
    $year = (int) $period['year'];
    $month = (int) $period['month'];
    $stmt = $conn->prepare(
        "UPDATE finance_budgets
         SET status = 'closed', closed_at = NOW()
         WHERE status = 'open'
           AND (period_year < ? OR (period_year = ? AND period_month < ?))"
    );
    $stmt->bind_param('iii', $year, $year, $month);
    $stmt->execute();
    $stmt->close();
}

function finance_budget(mysqli $conn, int $year, int $month): ?array
{
    finance_ensure_schema($conn);
    finance_close_elapsed($conn, finance_period());
    $stmt = $conn->prepare('SELECT * FROM finance_budgets WHERE period_year = ? AND period_month = ? LIMIT 1');
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function finance_lines(mysqli $conn, int $budgetId): array
{
    $stmt = $conn->prepare('SELECT * FROM finance_budget_lines WHERE budget_id = ? ORDER BY sort_order, line_code');
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    $reserved = finance_reserved_by_line($conn, $budgetId);
    foreach ($rows as &$row) {
        $used = $reserved[(int) $row['id']] ?? 0.0;
        $row['reserved'] = $used;
        $row['remaining'] = round((float) $row['amount'] - $used, 2);
    }
    unset($row);

    return $rows;
}

function finance_reserved_by_line(mysqli $conn, int $budgetId): array
{
    $stmt = $conn->prepare(
        "SELECT line_id, COALESCE(SUM(amount), 0) AS reserved
         FROM finance_budget_requests
         WHERE budget_id = ? AND status IN ('pending','approved','paid')
         GROUP BY line_id"
    );
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $map = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $map[(int) $row['line_id']] = round((float) $row['reserved'], 2);
    }
    $stmt->close();

    return $map;
}

function finance_budget_open_for_use(array $budget, array $period): bool
{
    if (($budget['status'] ?? '') !== 'open') {
        return false;
    }

    return (int) $budget['period_year'] === (int) $period['year']
        && (int) $budget['period_month'] === (int) $period['month'];
}

/** @return list<array{source:string,ref:string,paid_at:string,currency:string,amount:float}> */
function finance_income_rows(mysqli $conn, string $from, string $to, bool $limitDates): array
{
    $rows = [];
    $push = static function (array &$rows, string $source, string $ref, string $paidAt, string $currency, float $amount): void {
        if ($amount <= 0) {
            return;
        }
        $rows[] = [
            'source' => $source,
            'ref' => $ref,
            'paid_at' => $paidAt,
            'currency' => strtoupper($currency !== '' ? $currency : 'CAD'),
            'amount' => round($amount, 2),
        ];
    };

    if (finance_table_exists($conn, 'payment_receipts')) {
        $sql = "SELECT pr.receipt_no AS ref, pr.total_amount AS amount, pr.created_at AS paid_at,
                       COALESCE(NULLIF(fp.currency, ''), 'CAD') AS currency
                FROM payment_receipts pr
                LEFT JOIN fee_packages fp ON fp.id = pr.package_id
                WHERE COALESCE(pr.status, 'ACTIVE') <> 'CANCELED'";
        if ($limitDates) {
            $sql .= " AND DATE(pr.created_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $push($rows, 'Package receipt', (string) $row['ref'], (string) $row['paid_at'], (string) $row['currency'], (float) $row['amount']);
            }
        }
    }
    if (finance_table_exists($conn, 'fm_invoices')) {
        $sql = "SELECT invoice_no AS ref, total_amount AS amount, created_at AS paid_at, currency FROM fm_invoices";
        if ($limitDates) {
            $sql .= " WHERE DATE(created_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $push($rows, 'Francophone invoice', (string) $row['ref'], (string) $row['paid_at'], (string) $row['currency'], (float) $row['amount']);
            }
        }
    }
    if (finance_table_exists($conn, 'dubai_invoices')) {
        $sql = "SELECT invoice_no AS ref, total_amount AS amount, created_at AS paid_at, currency FROM dubai_invoices";
        if ($limitDates) {
            $sql .= " WHERE DATE(created_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $push($rows, 'Dubai invoice', (string) $row['ref'], (string) $row['paid_at'], (string) $row['currency'], (float) $row['amount']);
            }
        }
    }
    if (finance_table_exists($conn, 'czech_invoices')) {
        $sql = "SELECT invoice_no AS ref, total_amount AS amount, created_at AS paid_at, currency FROM czech_invoices";
        if ($limitDates) {
            $sql .= " WHERE DATE(created_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $push($rows, 'Czech invoice', (string) $row['ref'], (string) $row['paid_at'], (string) $row['currency'], (float) $row['amount']);
            }
        }
    }
    if (finance_table_exists($conn, 'canada_partner_payments')) {
        $sql = "SELECT id, amount, paid_on FROM canada_partner_payments";
        if ($limitDates) {
            $sql .= " WHERE paid_on BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $push($rows, 'Canada partner fee', 'PAR-' . (int) $row['id'], (string) $row['paid_on'], 'CAD', (float) $row['amount']);
            }
        }
    }

    usort($rows, static fn (array $a, array $b): int => strcmp($b['paid_at'], $a['paid_at']));

    return $rows;
}

/** @return array<string, float> */
function finance_income_totals(array $rows): array
{
    $totals = [];
    foreach ($rows as $row) {
        $currency = (string) $row['currency'];
        $totals[$currency] = round(($totals[$currency] ?? 0) + (float) $row['amount'], 2);
    }
    ksort($totals);

    return $totals;
}

/** @return list<array{line_code:string,category:string,description:string,amount:float}> */
function finance_parse_budget_file(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $rows = $ext === 'csv' ? finance_parse_csv($path) : finance_parse_xlsx($path);
    if ($rows === []) {
        throw new InvalidArgumentException('The file has no budget lines.');
    }
    $map = [];
    $headerAt = null;
    foreach ($rows as $rowIndex => $header) {
        $labels = array_map(static fn ($cell): string => strtolower(trim((string) $cell)), $header);
        $try = [];
        foreach ($labels as $index => $label) {
            if (str_contains($label, 'code')) {
                $try['code'] = $index;
            } elseif (str_contains($label, 'categor')) {
                $try['category'] = $index;
            } elseif (str_contains($label, 'desc')) {
                $try['description'] = $index;
            } elseif (str_contains($label, 'amount')) {
                $try['amount'] = $index;
            }
        }
        if (isset($try['code'], $try['amount'])) {
            $map = $try;
            $headerAt = $rowIndex;
            break;
        }
    }
    if ($headerAt === null) {
        throw new InvalidArgumentException('Use the template columns: Line code, Category, Description, Amount (RWF).');
    }
    $lines = [];
    $seen = [];
    foreach (array_slice($rows, $headerAt + 1) as $row) {
        $code = strtoupper(trim((string) ($row[$map['code']] ?? '')));
        if ($code === '') {
            continue;
        }
        if (isset($seen[$code])) {
            throw new InvalidArgumentException('Line code ' . $code . ' is repeated.');
        }
        $amount = (float) str_replace([',', ' '], '', (string) ($row[$map['amount']] ?? '0'));
        if ($amount <= 0) {
            throw new InvalidArgumentException('Line ' . $code . ' needs an amount greater than zero.');
        }
        $seen[$code] = true;
        $lines[] = [
            'line_code' => substr($code, 0, 40),
            'category' => substr(trim((string) ($row[$map['category']] ?? 'General')) ?: 'General', 0, 80),
            'description' => substr(trim((string) ($row[$map['description']] ?? '')), 0, 255),
            'amount' => round($amount, 2),
        ];
    }
    if ($lines === []) {
        throw new InvalidArgumentException('The file has no budget lines.');
    }
    if (count($lines) > 200) {
        throw new InvalidArgumentException('Keep the monthly budget to 200 lines or fewer.');
    }

    return $lines;
}

function finance_parse_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('The file could not be read.');
    }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function finance_parse_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('Upload the Excel template or a CSV saved from Excel.');
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml)) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml) {
            foreach ($xml->si as $item) {
                $text = '';
                if (isset($item->t)) {
                    $text = (string) $item->t;
                } else {
                    foreach ($item->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!is_string($sheet)) {
        throw new InvalidArgumentException('The workbook has no first sheet.');
    }
    $sheet = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $sheet) ?? $sheet;
    $xml = @simplexml_load_string($sheet);
    if (!$xml) {
        throw new InvalidArgumentException('The workbook could not be read.');
    }
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $col = preg_replace('/\d+/', '', $ref) ?: 'A';
            $index = 0;
            $length = strlen($col);
            for ($i = 0; $i < $length; $i++) {
                $index = $index * 26 + (ord($col[$i]) - 64);
            }
            $index--;
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') {
                $value = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cell->is->t;
            } else {
                $value = (string) $cell->v;
            }
            $cells[$index] = $value;
        }
        if ($cells === []) {
            continue;
        }
        ksort($cells);
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }

    return $rows;
}

function finance_budget_currency(): string
{
    return 'RWF';
}

function finance_template_xlsx(): string
{
    $samples = [
        ['OFF-01', 'Office', 'Rent, utilities, and supplies', 500000],
        ['MKT-01', 'Marketing', 'Ads and partner promotion', 300000],
        ['TRV-01', 'Travel', 'Local transport and trips', 200000],
        ['PRO-01', 'Professional services', 'Legal, accounting, and consultants', 400000],
        ['STF-01', 'Staff support', 'Allowances and extra duty', 350000],
    ];
    $cell = static function (string $ref, string $value, int $style, bool $number = false): string {
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
        if ($number) {
            return '<c r="' . $ref . '"' . $styleAttr . '><v>' . htmlspecialchars($value, ENT_XML1) . '</v></c>';
        }

        return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
    };
    $sheetRows = '<row r="1" ht="28" customHeight="1">' . $cell('A1', 'PARROT monthly budget', 1) . '</row>';
    $sheetRows .= '<row r="2" ht="22" customHeight="1">' . $cell('A2', 'Amounts are in Rwandan francs (RWF). Keep the header row and put one budget line on each row below it.', 2) . '</row>';
    $sheetRows .= '<row r="3" ht="8" customHeight="1"></row>';
    $headers = ['Line code', 'Category', 'Description', 'Amount (RWF)'];
    $headerCells = '';
    foreach ($headers as $c => $label) {
        $headerCells .= $cell(chr(65 + $c) . '4', $label, 3 + $c);
    }
    $sheetRows .= '<row r="4" ht="24" customHeight="1">' . $headerCells . '</row>';
    foreach ($samples as $i => $sample) {
        $rowNumber = 5 + $i;
        $textStyle = $i % 2 === 0 ? 7 : 8;
        $amountStyle = $i % 2 === 0 ? 9 : 10;
        $sheetRows .= '<row r="' . $rowNumber . '" ht="20" customHeight="1">'
            . $cell('A' . $rowNumber, $sample[0], $textStyle)
            . $cell('B' . $rowNumber, $sample[1], $textStyle)
            . $cell('C' . $rowNumber, $sample[2], $textStyle)
            . $cell('D' . $rowNumber, (string) $sample[3], $amountStyle, true)
            . '</row>';
    }
    $lastRow = 4 + count($samples);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><tabColor rgb="FF15803D"/></sheetPr>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A5" sqref="A5"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="18" customWidth="1"/>'
        . '<col min="2" max="2" width="28" customWidth="1"/>'
        . '<col min="3" max="3" width="48" customWidth="1"/>'
        . '<col min="4" max="4" width="20" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A4:D' . $lastRow . '"/>'
        . '<mergeCells count="2"><mergeCell ref="A1:D1"/><mergeCell ref="A2:D2"/></mergeCells>'
        . '</worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FF14532D"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF3F6212"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="8">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF14532D"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF166534"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF15803D"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFB45309"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD1FAE5"/></left><right style="thin"><color rgb="FFD1FAE5"/></right>'
        . '<top style="thin"><color rgb="FFD1FAE5"/></top><bottom style="thin"><color rgb="FFD1FAE5"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="11">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs></styleSheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Monthly budget" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'fin');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $binary = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $binary;
}

function finance_save_upload(mysqli $conn, array $lines, int $adminId, array $period): int
{
    finance_ensure_schema($conn);
    $budget = finance_budget($conn, (int) $period['year'], (int) $period['month']);
    if ($budget && ($budget['status'] ?? '') === 'closed') {
        throw new InvalidArgumentException($period['label'] . ' is already closed.');
    }
    if ($budget) {
        $count = finance_request_count($conn, (int) $budget['id']);
        if ($count > 0) {
            throw new InvalidArgumentException('This month already has requests, so the file cannot be replaced.');
        }
    }
    $conn->begin_transaction();
    try {
        if (!$budget) {
            $year = (int) $period['year'];
            $month = (int) $period['month'];
            $stmt = $conn->prepare(
                'INSERT INTO finance_budgets (period_year, period_month, status, currency, uploaded_by, uploaded_at)
                 VALUES (?, ?, \'open\', \'RWF\', ?, NOW())'
            );
            $stmt->bind_param('iii', $year, $month, $adminId);
            $stmt->execute();
            $budgetId = (int) $stmt->insert_id;
            $stmt->close();
        } else {
            $budgetId = (int) $budget['id'];
            $stmt = $conn->prepare('UPDATE finance_budgets SET currency = \'RWF\', uploaded_by = ?, uploaded_at = NOW() WHERE id = ?');
            $stmt->bind_param('ii', $adminId, $budgetId);
            $stmt->execute();
            $stmt->close();
            $conn->query('DELETE FROM finance_budget_lines WHERE budget_id = ' . $budgetId);
        }
        $insert = $conn->prepare(
            'INSERT INTO finance_budget_lines (budget_id, line_code, category, description, amount, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $index => $line) {
            $code = $line['line_code'];
            $category = $line['category'];
            $description = $line['description'];
            $amount = $line['amount'];
            $sort = $index + 1;
            $insert->bind_param('isssdi', $budgetId, $code, $category, $description, $amount, $sort);
            $insert->execute();
        }
        $insert->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return $budgetId;
}

function finance_request_count(mysqli $conn, int $budgetId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM finance_budget_requests WHERE budget_id = ?');
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

function finance_close_budget(mysqli $conn, int $budgetId, int $adminId): void
{
    $stmt = $conn->prepare("UPDATE finance_budgets SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ? AND status = 'open'");
    $stmt->bind_param('ii', $adminId, $budgetId);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new InvalidArgumentException('This budget is already closed.');
    }
    $stmt->close();
}

function finance_submit_request(mysqli $conn, int $budgetId, int $lineId, int $adminId, float $amount, string $purpose): void
{
    finance_submit_requests($conn, $budgetId, $adminId, [[
        'line_id' => $lineId,
        'amount' => $amount,
        'purpose' => $purpose,
    ]]);
}

/** @param list<array{line_id:int,amount:float,purpose:string}> $items */
function finance_submit_requests(mysqli $conn, int $budgetId, int $adminId, array $items): void
{
    $clean = [];
    foreach ($items as $item) {
        $lineId = (int) ($item['line_id'] ?? 0);
        $purpose = trim((string) ($item['purpose'] ?? ''));
        $amount = round((float) ($item['amount'] ?? 0), 2);
        if ($lineId <= 0 && $purpose === '' && $amount <= 0) {
            continue;
        }
        if ($lineId <= 0) {
            throw new InvalidArgumentException('Choose a budget line for every item.');
        }
        if ($purpose === '') {
            throw new InvalidArgumentException('Say what each item is for.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Enter an amount greater than zero for every item.');
        }
        $clean[] = [
            'line_id' => $lineId,
            'purpose' => substr($purpose, 0, 500),
            'amount' => $amount,
        ];
    }
    if ($clean === []) {
        throw new InvalidArgumentException('Add at least one item.');
    }
    if (count($clean) > 30) {
        throw new InvalidArgumentException('Submit up to 30 items at a time.');
    }

    $conn->begin_transaction();
    try {
        $lineIds = array_values(array_unique(array_map(static fn (array $row): int => $row['line_id'], $clean)));
        sort($lineIds);
        $lines = [];
        $lock = $conn->prepare('SELECT id, line_code, amount FROM finance_budget_lines WHERE id = ? AND budget_id = ? FOR UPDATE');
        foreach ($lineIds as $lineId) {
            $lock->bind_param('ii', $lineId, $budgetId);
            $lock->execute();
            $line = $lock->get_result()->fetch_assoc();
            if (!$line) {
                throw new InvalidArgumentException('Choose a line from this month\'s budget.');
            }
            $lines[$lineId] = $line;
        }
        $lock->close();

        $usedStmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS reserved
             FROM finance_budget_requests
             WHERE line_id = ? AND status IN ('pending','approved','paid')"
        );
        $reserved = [];
        foreach ($lineIds as $lineId) {
            $usedStmt->bind_param('i', $lineId);
            $usedStmt->execute();
            $reserved[$lineId] = (float) ($usedStmt->get_result()->fetch_assoc()['reserved'] ?? 0);
        }
        $usedStmt->close();

        $batch = [];
        foreach ($clean as $item) {
            $lineId = $item['line_id'];
            $batch[$lineId] = round(($batch[$lineId] ?? 0) + $item['amount'], 2);
            $remaining = round((float) $lines[$lineId]['amount'] - $reserved[$lineId], 2);
            if ($batch[$lineId] - $remaining > 0.001) {
                throw new InvalidArgumentException($lines[$lineId]['line_code'] . ' has ' . finance_money($remaining, finance_budget_currency()) . ' left.');
            }
        }

        $insert = $conn->prepare(
            'INSERT INTO finance_budget_requests (budget_id, line_id, requested_by, amount, purpose, status)
             VALUES (?, ?, ?, ?, ?, \'pending\')'
        );
        foreach ($clean as $item) {
            $lineId = $item['line_id'];
            $amount = $item['amount'];
            $purpose = $item['purpose'];
            $insert->bind_param('iiids', $budgetId, $lineId, $adminId, $amount, $purpose);
            $insert->execute();
        }
        $insert->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function finance_set_request_status(mysqli $conn, int $requestId, string $status, int $adminId): void
{
    if (!in_array($status, ['approved', 'rejected', 'paid'], true)) {
        throw new InvalidArgumentException('Choose approve, reject, or paid.');
    }
    $stmt = $conn->prepare('SELECT status FROM finance_budget_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new InvalidArgumentException('That request was not found.');
    }
    $current = (string) $row['status'];
    $allowed = ($status === 'paid' && $current === 'approved')
        || (in_array($status, ['approved', 'rejected'], true) && $current === 'pending');
    if (!$allowed) {
        throw new InvalidArgumentException('That request can no longer be changed.');
    }
    $update = $conn->prepare('UPDATE finance_budget_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?');
    $update->bind_param('sii', $status, $adminId, $requestId);
    $update->execute();
    $update->close();
}

function finance_requests(mysqli $conn, int $budgetId): array
{
    $stmt = $conn->prepare(
        'SELECT r.*, l.line_code, l.category, l.description,
                TRIM(CONCAT(COALESCE(a.first_name, \'\'), \' \', COALESCE(a.last_name, \'\'))) AS requester
         FROM finance_budget_requests r
         INNER JOIN finance_budget_lines l ON l.id = r.line_id
         LEFT JOIN admins a ON a.id = r.requested_by
         WHERE r.budget_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->bind_param('i', $budgetId);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function finance_css(): string
{
    return <<<'CSS'
@import url("https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap");
body { margin: 0; background: #f3f6f4; color: #142033; font-family: Manrope, "Segoe UI", system-ui, sans-serif; }
.wrap { max-width: 1100px; margin: 0 auto; padding: 22px 16px 48px; }
h1 { margin: 0; font-size: 28px; letter-spacing: -0.04em; }
.sub { margin: 4px 0 0; color: #64748b; font-weight: 600; }
.top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.card { background: #fff; border: 1px solid #e7eeea; border-radius: 16px; padding: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
.card b { display: block; font-size: 22px; letter-spacing: -0.03em; }
.card span, .muted { color: #64748b; font-weight: 700; font-size: 13px; }
.hot { background: #e9f9ef; border-color: #b7ebc9; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #eef3f0; font-size: 14px; vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; color: #64748b; }
.btn, button, select.period { border: 0; border-radius: 12px; padding: 10px 14px; font: 800 14px Manrope, sans-serif; cursor: pointer; text-decoration: none; display: inline-block; }
.btn, button { background: #16a34a; color: #fff; }
.ghost { background: #fff; color: #334155; border: 1px solid #e5eee8; }
button.warn { background: #b91c1c; }
.row-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
label { display: block; font-size: 13px; font-weight: 800; margin: 10px 0 6px; }
input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d7e3dc; border-radius: 12px; padding: 11px 12px; font: 600 15px Manrope, sans-serif; }
.grid { display: grid; grid-template-columns: 1fr 180px; gap: 12px; }
.err { background: #fef2f2; color: #991b1b; border-radius: 12px; padding: 10px 12px; font-weight: 700; }
.ok { background: #ecfdf3; color: #166534; border-radius: 12px; padding: 10px 12px; font-weight: 700; }
.pill { border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 800; background: #e2e8f0; }
.pill.pending { background: #fef3c7; color: #92400e; }
.pill.approved, .pill.paid, .pill.open { background: #dcfce7; color: #166534; }
.pill.rejected, .pill.closed { background: #fee2e2; color: #991b1b; }
@media (max-width: 800px) { .grid, .top { grid-template-columns: 1fr; display: grid; } }
CSS;
}

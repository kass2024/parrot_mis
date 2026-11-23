<?php
declare(strict_types=1);

/**
 * Francophonie Mobility payment records (Sultan fees spreadsheet).
 * Separate from record invoice.
 */

require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/francophonie_mobility_notify.php';
require_once __DIR__ . '/fm_client_tracking.php';
require_once __DIR__ . '/fm_manager_portal.php';

function fm_payment_report_recipient(): string
{
    return 'ujeanmethode@gmail.com';
}

function fm_ensure_payment_records_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $sql = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_payment_records` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `full_name` varchar(200) NOT NULL DEFAULT '',
      `job_position` varchar(120) NOT NULL DEFAULT '',
      `total_fees` decimal(12,2) NOT NULL DEFAULT 0.00,
      `upfront_fees_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
      `upfront_fees_paid_2` decimal(12,2) NOT NULL DEFAULT 0.00,
      `paid_after_medical` decimal(12,2) NOT NULL DEFAULT 0.00,
      `balance_to_sultan` decimal(12,2) NOT NULL DEFAULT 0.00,
      `visa_status` varchar(120) NOT NULL DEFAULT '',
      `comment` text NULL,
      `highlighted` tinyint(1) NOT NULL DEFAULT 0,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('fm_ensure_payment_records_schema: ' . $conn->error);
        return;
    }

    $cnt = $conn->query('SELECT COUNT(*) AS c FROM francophonie_mobility_payment_records');
    $n = 0;
    if ($cnt) {
        $n = (int) (($cnt->fetch_assoc()['c'] ?? 0));
        $cnt->free();
    }
    if ($n === 0) {
        fm_payment_records_seed_defaults($conn);
    }
}

/**
 * Seed from CANADA FRANCOPHONIE MOBILITY.xlsx (Clients sheet).
 */
function fm_payment_records_seed_defaults(mysqli $conn): void
{
    $rows = [
        [1, 'CHRISTIAN NIBISHAKA', 'Store keeper', 5000, 1500, 500, 3000, 0, 'Approved', '', 0],
        [2, 'JEAN PAUL BIMENYIMANA', 'Construction Helper', 5000, 500, 0, 0, 4500, '', 'all docs available wait for job offer number', 0],
        [3, 'Pontien RUTAYISIRE', 'Construction Helper', 5000, 0, 0, 0, 5000, '', 'Docs are ready Wait for Criminal record', 1],
        [4, 'UWABERA Marie Vivine', 'Restaurant Staff', 5000, 0, 0, 0, 5000, '', '', 0],
        [5, 'MUHIRE Emmanuel /of Roger', 'Construction Helper', 5000, 0, 0, 0, 5000, '', '', 0],
        [6, 'HATEGEKIMANA JAMES', 'Construction Helper', 5000, 500, 0, 0, 4500, '', 'Replaced Pontien', 0],
        [7, 'UMUHOZA MARIE CHANTAL', 'Restaurant Staff', 5000, 0, 0, 0, 5000, '', '', 0],
        [8, 'NYAKABEMBE VIVIEN', 'Construction Helper', 5000, 500, 0, 0, 4500, '', '', 0],
        [9, 'MUDAHERANWA Faustin', 'Construction Helper', 5000, 0, 0, 0, 5000, 'Tell us how to pay 500CAD', '', 0],
        [10, 'DUSHIMIYIMANA Noel', 'Construction Helper', 5000, 0, 0, 0, 5000, '', '', 0],
    ];
    $st = $conn->prepare(
        'INSERT INTO francophonie_mobility_payment_records
        (sort_order, full_name, job_position, total_fees, upfront_fees_paid, upfront_fees_paid_2,
         paid_after_medical, balance_to_sultan, visa_status, comment, highlighted)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return;
    }
    foreach ($rows as $r) {
        [$sn, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi] = $r;
        $st->bind_param(
            'issdddddssi',
            $sn, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi
        );
        $st->execute();
    }
    $st->close();
}

/**
 * @return list<array<string, mixed>>
 */
function fm_payment_records_list(mysqli $conn): array
{
    fm_ensure_payment_records_schema($conn);
    $res = $conn->query(
        'SELECT * FROM francophonie_mobility_payment_records ORDER BY sort_order ASC, id ASC'
    );
    if (!$res) {
        return [];
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC) ?: [];
    $res->free();
    return $rows;
}

function fm_payment_record_recalc_balance(float $total, float $up1, float $up2, float $med): float
{
    return round($total - $up1 - $up2 - $med, 2);
}

/**
 * @param array<string, mixed> $data
 */
function fm_payment_record_save(mysqli $conn, int $id, array $data): bool
{
    fm_ensure_payment_records_schema($conn);
    $name = trim((string) ($data['full_name'] ?? ''));
    $job = trim((string) ($data['job_position'] ?? ''));
    $total = (float) ($data['total_fees'] ?? 0);
    $up1 = (float) ($data['upfront_fees_paid'] ?? 0);
    $up2 = (float) ($data['upfront_fees_paid_2'] ?? 0);
    $med = (float) ($data['paid_after_medical'] ?? 0);
    $bal = fm_payment_record_recalc_balance($total, $up1, $up2, $med);
    if (isset($data['balance_to_sultan']) && $data['balance_to_sultan'] !== '') {
        $bal = (float) $data['balance_to_sultan'];
    }
    $visa = trim((string) ($data['visa_status'] ?? ''));
    $comment = trim((string) ($data['comment'] ?? ''));
    $hi = !empty($data['highlighted']) ? 1 : 0;
    $sort = (int) ($data['sort_order'] ?? 0);

    if ($id > 0) {
        $st = $conn->prepare(
            'UPDATE francophonie_mobility_payment_records SET
             sort_order=?, full_name=?, job_position=?, total_fees=?, upfront_fees_paid=?,
             upfront_fees_paid_2=?, paid_after_medical=?, balance_to_sultan=?, visa_status=?,
             comment=?, highlighted=? WHERE id=? LIMIT 1'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param(
            'issdddddssii',
            $sort, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi, $id
        );
        $ok = $st->execute();
        $st->close();
        return $ok;
    }

    $st = $conn->prepare(
        'INSERT INTO francophonie_mobility_payment_records
        (sort_order, full_name, job_position, total_fees, upfront_fees_paid, upfront_fees_paid_2,
         paid_after_medical, balance_to_sultan, visa_status, comment, highlighted)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param(
        'issdddddssi',
        $sort, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi
    );
    $ok = $st->execute();
    $st->close();
    return $ok;
}

function fm_payment_record_delete(mysqli $conn, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $st = $conn->prepare('DELETE FROM francophonie_mobility_payment_records WHERE id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $id);
    $ok = $st->execute();
    $st->close();
    return $ok;
}

function fm_xml_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Build SpreadsheetML workbook (Client tracking + Payment records).
 */
function fm_build_manager_report_xls(mysqli $conn): string
{
    fm_ensure_payment_records_schema($conn);
    fm_ensure_tracking_schema($conn);

    $checklist = fm_tracking_checklist();
    $apps = [];
    $res = $conn->query(
        "SELECT id, reference_id, first_name, last_name, email, status,
                cv_file, french_cert_file, english_cert_file, academic_docs_file
         FROM francophonie_mobility_applications
         ORDER BY updated_at DESC, id DESC LIMIT 500"
    );
    if ($res) {
        $apps = $res->fetch_all(MYSQLI_ASSOC) ?: [];
        $res->free();
    }

    $trackRows = '';
    $headers = array_merge(['Client name', 'Reference', 'Status', 'Done'], array_values($checklist));
    $trackRows .= '<Row>';
    foreach ($headers as $h) {
        $trackRows .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . fm_xml_escape($h) . '</Data></Cell>';
    }
    $trackRows .= '</Row>';

    foreach ($apps as $app) {
        $resolved = fm_tracking_resolve_for_app($conn, $app, false);
        $matches = $resolved['matches'] ?? [];
        $present = 0;
        $cells = [];
        foreach ($checklist as $key => $_lab) {
            $file = $matches[$key] ?? null;
            $ok = is_array($file) && !empty($file['path']);
            if ($ok) {
                $present++;
            }
            $cells[] = $ok ? 'Available' : 'Missing';
        }
        $name = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
        $values = [
            $name !== '' ? $name : '—',
            (string) ($app['reference_id'] ?? ''),
            fm_application_status_label((string) ($app['status'] ?? '')),
            $present . '/' . count($checklist),
        ];
        $values = array_merge($values, $cells);
        $trackRows .= '<Row>';
        foreach ($values as $i => $v) {
            $style = 'Text';
            if ($i >= 4) {
                $style = $v === 'Available' ? 'Ok' : 'Miss';
            }
            $trackRows .= '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . fm_xml_escape((string) $v) . '</Data></Cell>';
        }
        $trackRows .= '</Row>';
    }

    $payHeaders = [
        'S/N', 'FULL NAMES', 'JOB POSITION', 'TOTAL FEES TO BE PAID-SULTAN',
        'UPFRONT FEES PAID-SULTAN', 'UPFRONT FEES PAID-SULTAN (2)',
        'PAID FEES AFTER MEDICAL-SULTAN', 'BALANCE TO SULTAN', 'VISA STATUS', 'COMMENT',
    ];
    $payRows = '<Row>';
    foreach ($payHeaders as $h) {
        $payRows .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . fm_xml_escape($h) . '</Data></Cell>';
    }
    $payRows .= '</Row>';
    foreach (fm_payment_records_list($conn) as $p) {
        $vals = [
            (string) ($p['sort_order'] ?? ''),
            (string) ($p['full_name'] ?? ''),
            (string) ($p['job_position'] ?? ''),
            (string) ($p['total_fees'] ?? '0'),
            (string) ($p['upfront_fees_paid'] ?? '0'),
            (string) ($p['upfront_fees_paid_2'] ?? '0'),
            (string) ($p['paid_after_medical'] ?? '0'),
            (string) ($p['balance_to_sultan'] ?? '0'),
            (string) ($p['visa_status'] ?? ''),
            (string) ($p['comment'] ?? ''),
        ];
        $styleRow = !empty($p['highlighted']) ? 'Highlight' : 'Text';
        $payRows .= '<Row>';
        foreach ($vals as $v) {
            $payRows .= '<Cell ss:StyleID="' . $styleRow . '"><Data ss:Type="String">' . fm_xml_escape($v) . '</Data></Cell>';
        }
        $payRows .= '</Row>';
    }

    return '<?xml version="1.0"?>' . "\n"
        . '<?mso-application progid="Excel.Sheet"?>' . "\n"
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Styles>'
        . '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E4D2B" ss:Pattern="Solid"/></Style>'
        . '<Style ss:ID="Text"/>'
        . '<Style ss:ID="Ok"><Font ss:Color="#166534"/><Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/></Style>'
        . '<Style ss:ID="Miss"><Font ss:Color="#991B1B"/><Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/></Style>'
        . '<Style ss:ID="Highlight"><Interior ss:Color="#FEF08A" ss:Pattern="Solid"/></Style>'
        . '</Styles>'
        . '<Worksheet ss:Name="Client tracking"><Table>' . $trackRows . '</Table></Worksheet>'
        . '<Worksheet ss:Name="Payment records"><Table>' . $payRows . '</Table></Worksheet>'
        . '</Workbook>';
}

/**
 * @return array{ok:bool,message:string}
 */
function fm_send_manager_full_report(mysqli $conn, string $privateLink): array
{
    require_once __DIR__ . '/francophonie_mobility_schema.php';
    fm_ensure_schema($conn);

    $xml = fm_build_manager_report_xls($conn);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm_manager_report_' . date('Ymd_His') . '.xls';
    if (file_put_contents($tmp, $xml) === false) {
        return ['ok' => false, 'message' => 'Could not create the Excel report file.'];
    }

    $to = fm_payment_report_recipient();
    $subject = 'Francophonie Mobility — full report ' . date('Y-m-d');
    $body = '<p>Please find the attached Excel report (Client tracking + Payment records).</p>'
        . '<p><strong>For more details</strong> (Smart Add, application list, overall report, and payment records), '
        . 'open your private manager link:</p>'
        . '<p><a href="' . htmlspecialchars($privateLink, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($privateLink, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>No username or password is required — the private link alone grants access.</p>';

    $ok = sendSMTPMail(
        $to,
        $subject,
        fm_email_wrap('Full report', $body),
        [['path' => $tmp, 'name' => 'Francophonie-Mobility-Full-Report.xls']]
    );
    @unlink($tmp);

    if ($ok) {
        return ['ok' => true, 'message' => 'Report sent to ' . $to . ' with the Excel attachment.'];
    }
    return ['ok' => false, 'message' => 'Could not send the report email. Please try again.'];
}

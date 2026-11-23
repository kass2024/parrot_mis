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

/** @deprecated No recipient is mandatory; kept for older call sites. */
function fm_payment_report_required_recipient(): string
{
    return '';
}

/**
 * Selectable report recipients. All three may be checked or unchecked freely.
 *
 * @return array<string, array{label:string,required:bool}>
 */
function fm_payment_report_recipients(): array
{
    return [
        'info@francocana.com' => ['label' => 'info@francocana.com', 'required' => false],
        'ujeanmethode@gmail.com' => ['label' => 'ujeanmethode@gmail.com', 'required' => false],
        'infos@visaconsultantcanada.com' => ['label' => 'infos@visaconsultantcanada.com', 'required' => false],
    ];
}

/** @deprecated Prefer fm_payment_report_recipients() */
function fm_payment_report_recipient(): string
{
    return 'info@francocana.com';
}

/**
 * @param list<string>|null $selected
 * @return list<string>
 */
function fm_normalize_report_recipients(?array $selected): array
{
    $allowed = array_keys(fm_payment_report_recipients());
    $out = [];
    foreach ($selected ?? [] as $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !in_array($email, $allowed, true)) {
            continue;
        }
        if (!in_array($email, $out, true)) {
            $out[] = $email;
        }
    }
    return $out;
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
      `application_id` int(11) DEFAULT NULL,
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
      KEY `sort_order` (`sort_order`),
      KEY `application_id` (`application_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('fm_ensure_payment_records_schema: ' . $conn->error);
        return;
    }

    $appCol = $conn->query("SHOW COLUMNS FROM francophonie_mobility_payment_records LIKE 'application_id'");
    if ($appCol && $appCol->num_rows === 0) {
        $conn->query(
            'ALTER TABLE francophonie_mobility_payment_records
             ADD COLUMN `application_id` int(11) DEFAULT NULL AFTER `id`,
             ADD KEY `application_id` (`application_id`)'
        );
    }
    if ($appCol) {
        $appCol->free();
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
    fm_payment_records_link_existing_clients($conn);
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
        'SELECT p.*,
                a.reference_id AS app_reference,
                a.email AS app_email,
                a.status AS app_status,
                TRIM(CONCAT(COALESCE(a.first_name,\'\'), \' \', COALESCE(a.last_name,\'\'))) AS app_name
         FROM francophonie_mobility_payment_records p
         LEFT JOIN francophonie_mobility_applications a ON a.id = p.application_id
         ORDER BY p.sort_order ASC, p.id ASC'
    );
    if (!$res) {
        return [];
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC) ?: [];
    $res->free();
    return $rows;
}

/**
 * FM applications available to attach to a payment row.
 *
 * @return list<array{id:int,name:string,reference_id:string,email:string,status:string,profession:string}>
 */
function fm_payment_clients_for_picker(mysqli $conn, ?int $keepApplicationId = null): array
{
    fm_ensure_payment_records_schema($conn);
    $used = [];
    $res = $conn->query(
        'SELECT application_id FROM francophonie_mobility_payment_records
         WHERE application_id IS NOT NULL AND application_id > 0'
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $aid = (int) ($row['application_id'] ?? 0);
            if ($aid > 0 && $aid !== (int) $keepApplicationId) {
                $used[$aid] = true;
            }
        }
        $res->free();
    }

    $out = [];
    $apps = $conn->query(
        "SELECT id, first_name, last_name, email, reference_id, status, profession, job_offer
         FROM francophonie_mobility_applications
         ORDER BY first_name ASC, last_name ASC, id DESC
         LIMIT 800"
    );
    if (!$apps) {
        return [];
    }
    while ($a = $apps->fetch_assoc()) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0 || isset($used[$id])) {
            continue;
        }
        $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $out[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : ('Client #' . $id),
            'reference_id' => (string) ($a['reference_id'] ?? ''),
            'email' => (string) ($a['email'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'profession' => (string) ($a['profession'] ?? ''),
            'job_offer' => (string) ($a['job_offer'] ?? ''),
        ];
    }
    $apps->free();
    return $out;
}

function fm_payment_records_link_existing_clients(mysqli $conn): void
{
    $rows = $conn->query(
        'SELECT id, full_name FROM francophonie_mobility_payment_records
         WHERE (application_id IS NULL OR application_id = 0) AND full_name <> \'\''
    );
    if (!$rows) {
        return;
    }
    $upd = $conn->prepare(
        'UPDATE francophonie_mobility_payment_records SET application_id = ? WHERE id = ? LIMIT 1'
    );
    while ($p = $rows->fetch_assoc()) {
        $pid = (int) ($p['id'] ?? 0);
        $name = trim((string) ($p['full_name'] ?? ''));
        if ($pid <= 0 || $name === '') {
            continue;
        }
        $appId = fm_payment_find_application_id_by_name($conn, $name);
        if ($appId > 0 && $upd) {
            $upd->bind_param('ii', $appId, $pid);
            $upd->execute();
        }
    }
    $rows->free();
    if ($upd) {
        $upd->close();
    }
}

function fm_payment_find_application_id_by_name(mysqli $conn, string $fullName): int
{
    $fullName = preg_replace('/\s+/u', ' ', trim($fullName)) ?? trim($fullName);
    if ($fullName === '') {
        return 0;
    }
    $st = $conn->prepare(
        "SELECT id FROM francophonie_mobility_applications
         WHERE LOWER(TRIM(CONCAT(TRIM(first_name), ' ', TRIM(last_name)))) = LOWER(?)
         LIMIT 1"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $fullName);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int) ($row['id'] ?? 0);
}

function fm_payment_guess_job_offer_slug(string $jobPosition): string
{
    require_once __DIR__ . '/francophonie_mobility_schema.php';
    $j = strtolower($jobPosition);
    if (str_contains($j, 'store') || str_contains($j, 'customer')) {
        return 'customer_service_representative';
    }
    if (str_contains($j, 'restaurant') || str_contains($j, 'cook') || str_contains($j, 'kitchen')) {
        return 'restaurant_staff';
    }
    if (str_contains($j, 'mechanical')) {
        return 'mechanical_helper';
    }
    if (isset(fm_job_offer_choices()['construction_helper'])) {
        return 'construction_helper';
    }
    $keys = array_keys(fm_job_offer_choices());
    return $keys[0] ?? 'construction_helper';
}

/**
 * Create a minimal FM client in applications DB (no payment fields — payments stay in payment_records only).
 */
function fm_payment_create_stub_application(mysqli $conn, string $fullName, string $jobPosition = ''): int
{
    require_once __DIR__ . '/francophonie_mobility_schema.php';
    fm_ensure_schema($conn);

    $fullName = preg_replace('/\s+/u', ' ', trim($fullName)) ?? trim($fullName);
    if ($fullName === '') {
        throw new RuntimeException('Enter a full name.');
    }
    $existing = fm_payment_find_application_id_by_name($conn, $fullName);
    if ($existing > 0) {
        return $existing;
    }

    $parts = preg_split('/\s+/u', $fullName, 2) ?: [$fullName];
    $first = (string) ($parts[0] ?? $fullName);
    $last = (string) ($parts[1] ?? '');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $first . $last) ?: 'client');
    $email = 'fm.pay.' . $slug . '.' . substr(bin2hex(random_bytes(3)), 0, 6) . '@placeholder.local';
    $userId = 'fm_pay_' . bin2hex(random_bytes(6)) . '_' . time();
    $reference = 'FM' . date('Y') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $jobOffer = fm_payment_guess_job_offer_slug($jobPosition);
    $profession = $jobPosition !== '' ? $jobPosition : 'N/A';
    $phoneArea = '';
    $phoneNum = '0';
    $dobBind = '';
    $passport = 'PENDING';
    $address = 'N/A';
    $age = 0;
    $nationality = 'N/A';
    $residence = 'N/A';
    $zero = '0';
    $na = 'N/A';
    $year = '0000';
    $empty = '';
    $beginner = 'beginner';
    $no = 'no';
    $zeroInt = 0;
    $notes = 'Added from Payment records (stub client — no payment data on application).';
    $status = 'pending';
    $source = 'staff';

    $sql = 'INSERT INTO francophonie_mobility_applications (
            user_id, reference_id, first_name, last_name, email,
            phone_area_code, phone_number, date_of_birth, passport_number, address,
            age, nationality, country_of_residence,
            profession, years_experience, highest_degree, field_of_study, university_name,
            country_of_study, graduation_year, other_certifications,
            french_level, french_tef, french_tcf, french_professional,
            english_level, english_toefl, english_ielts, english_professional,
            has_wes, job_offer, cv_file, french_cert_file, english_cert_file, academic_docs_file,
            admin_notes, status, source, created_at
        ) VALUES (
            ?,?,?,?,?,
            ?,?,NULLIF(?,\'\'),?,?,
            ?,?,?,
            ?,?,?,?,?,
            ?,?,?,
            ?,?,?,?,
            ?,?,?,?,
            ?,?,\'\',\'\',\'\',\'\',
            ?,?,?, NOW()
        )';
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new RuntimeException('Could not create client: ' . $conn->error);
    }
    $type = 'ssssssssssisssssssssssiissiissssss';
    $st->bind_param(
        $type,
        $userId, $reference, $first, $last, $email,
        $phoneArea, $phoneNum, $dobBind, $passport, $address,
        $age, $nationality, $residence,
        $profession, $zero, $na, $na, $na,
        $na, $year, $empty,
        $beginner, $zeroInt, $zeroInt, $no,
        $beginner, $zeroInt, $zeroInt, $no,
        $no, $jobOffer, $notes, $status, $source
    );
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new RuntimeException('Could not create client: ' . $err);
    }
    $newId = (int) $st->insert_id;
    $st->close();
    return $newId;
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
    $appId = (int) ($data['application_id'] ?? 0);
    $name = trim((string) ($data['full_name'] ?? ''));
    $job = trim((string) ($data['job_position'] ?? ''));

    if ($appId > 0) {
        $stApp = $conn->prepare(
            'SELECT first_name, last_name, profession, job_offer FROM francophonie_mobility_applications WHERE id = ? LIMIT 1'
        );
        if ($stApp) {
            $stApp->bind_param('i', $appId);
            $stApp->execute();
            $app = $stApp->get_result()->fetch_assoc();
            $stApp->close();
            if ($app) {
                $appName = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
                if ($appName !== '') {
                    $name = $appName;
                }
                if ($job === '') {
                    $job = trim((string) ($app['profession'] ?? ''));
                    if ($job === '' || strtoupper($job) === 'N/A') {
                        require_once __DIR__ . '/francophonie_mobility_schema.php';
                        $job = fm_job_offer_label((string) ($app['job_offer'] ?? ''));
                    }
                }
            }
        }
    }

    $total = (float) ($data['total_fees'] ?? 0);
    $up1 = (float) ($data['upfront_fees_paid'] ?? 0);
    $up2 = (float) ($data['upfront_fees_paid_2'] ?? 0);
    $med = (float) ($data['paid_after_medical'] ?? 0);
    $bal = fm_payment_record_recalc_balance($total, $up1, $up2, $med);
    if (array_key_exists('balance_to_sultan', $data) && $data['balance_to_sultan'] !== '' && $data['balance_to_sultan'] !== null) {
        $bal = (float) $data['balance_to_sultan'];
    }
    $visa = trim((string) ($data['visa_status'] ?? ''));
    $comment = trim((string) ($data['comment'] ?? ''));
    $hi = !empty($data['highlighted']) ? 1 : 0;
    $sort = (int) ($data['sort_order'] ?? 0);
    $appIdBind = $appId > 0 ? $appId : 0;

    if ($id > 0) {
        $st = $conn->prepare(
            'UPDATE francophonie_mobility_payment_records SET
             application_id=NULLIF(?,0), sort_order=?, full_name=?, job_position=?, total_fees=?, upfront_fees_paid=?,
             upfront_fees_paid_2=?, paid_after_medical=?, balance_to_sultan=?, visa_status=?,
             comment=?, highlighted=? WHERE id=? LIMIT 1'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param(
            'iissdddddssii',
            $appIdBind, $sort, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi, $id
        );
        $ok = $st->execute();
        $st->close();
        return $ok;
    }

    $st = $conn->prepare(
        'INSERT INTO francophonie_mobility_payment_records
        (application_id, sort_order, full_name, job_position, total_fees, upfront_fees_paid, upfront_fees_paid_2,
         paid_after_medical, balance_to_sultan, visa_status, comment, highlighted)
         VALUES (NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param(
        'iissdddddssi',
        $appIdBind, $sort, $name, $job, $total, $up1, $up2, $med, $bal, $visa, $comment, $hi
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
 * @param list<string>|null $selectedRecipients
 * @return array{ok:bool,message:string}
 */
function fm_send_manager_full_report(mysqli $conn, string $privateLink, ?array $selectedRecipients = null): array
{
    require_once __DIR__ . '/francophonie_mobility_schema.php';
    fm_ensure_schema($conn);

    $recipients = fm_normalize_report_recipients($selectedRecipients);
    if ($recipients === []) {
        return ['ok' => false, 'message' => 'Select at least one email address.'];
    }
    $to = $recipients[0];
    $cc = array_slice($recipients, 1);

    $xml = fm_build_manager_report_xls($conn);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm_manager_report_' . date('Ymd_His') . '.xls';
    if (file_put_contents($tmp, $xml) === false) {
        return ['ok' => false, 'message' => 'Could not create the Excel report file.'];
    }

    $subject = 'Francophonie Mobility — full report ' . date('Y-m-d');
    $body = '<p>Please find the attached Excel report (Client tracking + Payment records).</p>'
        . '<p><strong>For more details</strong> (Smart Add, application list, overall report, and payment records), '
        . 'open your private manager link:</p>'
        . '<p><a href="' . htmlspecialchars($privateLink, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($privateLink, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>No username or password is required — the private link alone grants access.</p>';

    $send = sendSMTPMailDetailed(
        $to,
        $subject,
        fm_email_wrap('Full report', $body),
        [['path' => $tmp, 'name' => 'Francophonie-Mobility-Full-Report.xls']],
        [],
        null,
        $cc
    );
    @unlink($tmp);

    if (!empty($send['ok'])) {
        return [
            'ok' => true,
            'message' => 'Report sent to ' . implode(', ', $recipients) . ' with the Excel attachment.',
        ];
    }
    $err = trim((string) ($send['error'] ?? ''));
    return [
        'ok' => false,
        'message' => $err !== ''
            ? ('Could not send the report email: ' . $err)
            : 'Could not send the report email. Please try again.',
    ];
}

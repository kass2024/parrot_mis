<?php
declare(strict_types=1);

/**
 * Canada consultant partnerships.
 * Service progress and client payments are stored separately.
 * A fixed partner pays a set amount per accepted client.
 * A percent partner pays that share of the client's service fee.
 */

function canada_partner_ensure_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS canada_partners (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(160) NOT NULL,
            service_name VARCHAR(160) NOT NULL,
            email VARCHAR(160) NOT NULL DEFAULT '',
            phone VARCHAR(60) NOT NULL DEFAULT '',
            city VARCHAR(120) NOT NULL DEFAULT '',
            commission_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            commission_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS canada_partner_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_id INT UNSIGNED NOT NULL,
            client_code VARCHAR(32) NOT NULL,
            full_name VARCHAR(180) NOT NULL,
            phone VARCHAR(60) NOT NULL DEFAULT '',
            email VARCHAR(160) NOT NULL DEFAULT '',
            nationality VARCHAR(80) NOT NULL DEFAULT '',
            passport_number VARCHAR(40) NOT NULL DEFAULT '',
            progress_status VARCHAR(20) NOT NULL DEFAULT 'discussion',
            referral_date DATE NULL,
            discussion_date DATE NULL,
            partner_remarks TEXT NULL,
            accepted_date DATE NULL,
            service_start_date DATE NULL,
            service_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            decision_date DATE NULL,
            decision_outcome VARCHAR(255) NOT NULL DEFAULT '',
            decision_document VARCHAR(255) NOT NULL DEFAULT '',
            next_followup DATE NULL,
            responsible_admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_canada_partner_client_code (client_code),
            KEY idx_canada_partner_client_partner (partner_id),
            KEY idx_canada_partner_client_status (progress_status),
            KEY idx_canada_partner_client_followup (next_followup)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS canada_partner_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            paid_on DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(40) NOT NULL DEFAULT '',
            receipt_path VARCHAR(255) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            recorded_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_canada_partner_payment_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS canada_partner_commission_receipts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            paid_on DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(40) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            recorded_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_canada_partner_commission_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $count = $conn->query('SELECT COUNT(*) AS n FROM canada_partners');
    $existing = $count ? (int) ($count->fetch_assoc()['n'] ?? 0) : 0;
    if ($existing === 0) {
        $seed = $conn->prepare(
            'INSERT INTO canada_partners (name, service_name, city, commission_type, commission_value, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $rows = [
            ['TCF Canada consultant', 'TCF Canada Training', 'Canada', 'fixed', 50.00, 'Pays Parrot CAD 50 for each client who accepts the service. Replace this name with the consultant\'s name.'],
            ['PR and Express Entry consultant', 'Permanent Residence / Express Entry', 'Canada', 'percent', 25.00, 'Pays Parrot 25% of the client service fee once the client accepts the service. Replace this name with the consultant\'s name.'],
        ];
        foreach ($rows as $row) {
            [$seedName, $seedService, $seedCity, $seedType, $seedValue, $seedNotes] = $row;
            $seed->bind_param('ssssds', $seedName, $seedService, $seedCity, $seedType, $seedValue, $seedNotes);
            $seed->execute();
        }
        $seed->close();
    }
}

/** @return array<string, array{n:int,label:string,detail:string}> */
function canada_partner_statuses(): array
{
    return [
        'discussion' => ['n' => 1, 'label' => 'In Discussion', 'detail' => 'Client is communicating with the partner about services, fees, eligibility, and requirements.'],
        'accepted' => ['n' => 2, 'label' => 'Accepted to Start', 'detail' => 'Client has agreed to proceed with the service.'],
        'started' => ['n' => 3, 'label' => 'Service Started', 'detail' => 'Client has officially started TCF training or the PR/Express Entry process.'],
        'paid' => ['n' => 4, 'label' => 'Fully Paid', 'detail' => 'Client has completed payment of all agreed service fees.'],
        'decision' => ['n' => 5, 'label' => 'Decision / Certificate Received', 'detail' => 'Client has received their TCF certificate or results, or an immigration decision.'],
    ];
}

function canada_partner_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function canada_partner_cad(float $amount): string
{
    return 'CAD ' . number_format($amount, 2, '.', ',');
}

function canada_partner_date_or_null(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        throw new InvalidArgumentException('Enter a valid date.');
    }

    return $value;
}

function canada_partner_earned(string $type, float $value, string $status, float $fee): float
{
    if (!in_array($status, ['accepted', 'started', 'paid', 'decision'], true)) {
        return 0.0;
    }
    if ($type === 'percent') {
        return round($fee * $value / 100, 2);
    }

    return round($value, 2);
}

function canada_partner_pay_class(string $state): string
{
    if ($state === 'Paid') {
        return 'paid';
    }
    if ($state === 'Partial') {
        return 'partial';
    }
    if ($state === 'Unpaid') {
        return 'unpaid';
    }

    return '';
}

function canada_partner_payment_state(float $fee, float $paid): string
{
    if ($fee <= 0) {
        return 'No fee set';
    }
    if ($paid + 0.001 >= $fee) {
        return 'Paid';
    }
    if ($paid > 0) {
        return 'Partial';
    }

    return 'Unpaid';
}

/** @param array<string, mixed> $row */
function canada_partner_enrich(array $row): array
{
    $fee = round((float) ($row['service_fee'] ?? 0), 2);
    $paid = round((float) ($row['amount_paid'] ?? 0), 2);
    $earned = canada_partner_earned(
        (string) ($row['commission_type'] ?? 'fixed'),
        (float) ($row['commission_value'] ?? 0),
        (string) ($row['progress_status'] ?? 'discussion'),
        $fee
    );
    $received = round((float) ($row['commission_received'] ?? 0), 2);
    $row['service_fee'] = $fee;
    $row['amount_paid'] = $paid;
    $row['balance'] = round($fee - $paid, 2);
    $row['payment_state'] = canada_partner_payment_state($fee, $paid);
    $row['commission_earned'] = $earned;
    $row['commission_received'] = $received;
    $row['commission_outstanding'] = round($earned - $received, 2);
    $statuses = canada_partner_statuses();
    $status = (string) ($row['progress_status'] ?? 'discussion');
    $row['status_label'] = $statuses[$status]['label'] ?? $status;
    $row['status_number'] = $statuses[$status]['n'] ?? 0;

    return $row;
}

function canada_partner_client_select(): string
{
    return 'SELECT c.*, p.name AS partner_name, p.service_name, p.commission_type, p.commission_value, p.active AS partner_active,
            COALESCE(pay.paid, 0) AS amount_paid, COALESCE(com.received, 0) AS commission_received,
            TRIM(CONCAT(COALESCE(a.first_name, \'\'), \' \', COALESCE(a.last_name, \'\'))) AS staff_name,
            a.full_name AS staff_full_name, a.username AS staff_username
        FROM canada_partner_clients c
        INNER JOIN canada_partners p ON p.id = c.partner_id
        LEFT JOIN (
            SELECT client_id, SUM(amount) AS paid FROM canada_partner_payments GROUP BY client_id
        ) pay ON pay.client_id = c.id
        LEFT JOIN (
            SELECT client_id, SUM(amount) AS received FROM canada_partner_commission_receipts GROUP BY client_id
        ) com ON com.client_id = c.id
        LEFT JOIN admins a ON a.id = c.responsible_admin_id';
}

/** @return list<array<string, mixed>> */
function canada_partner_clients(mysqli $conn, int $partnerId = 0, string $status = '', string $search = ''): array
{
    $sql = canada_partner_client_select() . ' WHERE 1=1';
    $types = '';
    $params = [];
    if ($partnerId > 0) {
        $sql .= ' AND c.partner_id = ?';
        $types .= 'i';
        $params[] = $partnerId;
    }
    if ($status !== '' && isset(canada_partner_statuses()[$status])) {
        $sql .= ' AND c.progress_status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (c.full_name LIKE ? OR c.client_code LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.passport_number LIKE ? OR p.name LIKE ? OR p.service_name LIKE ?)';
        $types .= 'sssssss';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    }
    $sql .= ' ORDER BY c.id DESC';
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        $stmt->bind_param($types, ...$refs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = canada_partner_enrich($row);
    }
    $stmt->close();

    return $rows;
}

/** @return array<string, mixed>|null */
function canada_partner_client(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(canada_partner_client_select() . ' WHERE c.id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? canada_partner_enrich($row) : null;
}

/** @return list<array<string, mixed>> */
function canada_partner_partners(mysqli $conn): array
{
    $res = $conn->query('SELECT * FROM canada_partners ORDER BY active DESC, service_name, name');
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/** @return array<string, mixed>|null */
function canada_partner_get(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM canada_partners WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function canada_partner_save(mysqli $conn, array $input, int $id = 0): int
{
    $name = trim((string) ($input['name'] ?? ''));
    $service = trim((string) ($input['service_name'] ?? ''));
    if ($name === '' || $service === '') {
        throw new InvalidArgumentException('Enter the partner name and the service.');
    }
    $type = (string) ($input['commission_type'] ?? '');
    if (!in_array($type, ['fixed', 'percent'], true)) {
        throw new InvalidArgumentException('Choose a fixed amount or a percentage.');
    }
    $value = round((float) ($input['commission_value'] ?? 0), 2);
    if ($value < 0 || ($type === 'percent' && $value > 100)) {
        throw new InvalidArgumentException('Enter a commission of 0 to 100 percent, or a fixed amount of CAD 0 or more.');
    }
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $active = (string) ($input['active'] ?? '1') === '1' ? 1 : 0;
    if ($id > 0) {
        $stmt = $conn->prepare(
            'UPDATE canada_partners
             SET name = ?, service_name = ?, email = ?, phone = ?, city = ?, commission_type = ?, commission_value = ?, notes = ?, active = ?
             WHERE id = ?'
        );
        $stmt->bind_param('ssssssdsii', $name, $service, $email, $phone, $city, $type, $value, $notes, $active, $id);
        $stmt->execute();
        $stmt->close();

        return $id;
    }
    $stmt = $conn->prepare(
        'INSERT INTO canada_partners (name, service_name, email, phone, city, commission_type, commission_value, notes, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssssdsi', $name, $service, $email, $phone, $city, $type, $value, $notes, $active);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    return $newId;
}

function canada_partner_next_code(mysqli $conn): string
{
    $year = date('Y');
    $prefix = 'PAR-CAN-' . $year . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare('SELECT client_code FROM canada_partner_clients WHERE client_code LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if (is_array($row)) {
        $next = (int) substr((string) $row['client_code'], -4) + 1;
    }

    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function canada_partner_store_file(array $file, string $folder): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file could not be uploaded.');
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('The file must be 8 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'pdf', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];
    if (!isset($allowed[$ext])) {
        throw new RuntimeException('Upload a PDF or an image.');
    }
    $dir = dirname(__DIR__) . '/uploads/canada-partners/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The upload folder is not available.');
    }
    $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$ext];
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('The file could not be saved.');
    }

    return 'uploads/canada-partners/' . $folder . '/' . $name;
}

function canada_partner_save_client(mysqli $conn, array $input, int $id, string $documentPath): int
{
    $partnerId = (int) ($input['partner_id'] ?? 0);
    if (canada_partner_get($conn, $partnerId) === null) {
        throw new InvalidArgumentException('Choose a partner.');
    }
    $name = trim((string) ($input['full_name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Enter the client name.');
    }
    $status = (string) ($input['progress_status'] ?? 'discussion');
    if (!isset(canada_partner_statuses()[$status])) {
        throw new InvalidArgumentException('Choose a client progress status.');
    }
    $fee = round((float) ($input['service_fee'] ?? 0), 2);
    if ($fee < 0) {
        throw new InvalidArgumentException('The service fee cannot be negative.');
    }
    $phone = trim((string) ($input['phone'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $nationality = trim((string) ($input['nationality'] ?? ''));
    $passport = trim((string) ($input['passport_number'] ?? ''));
    $remarks = trim((string) ($input['partner_remarks'] ?? ''));
    $outcome = trim((string) ($input['decision_outcome'] ?? ''));
    $referral = canada_partner_date_or_null((string) ($input['referral_date'] ?? '')) ?? '';
    $discussion = canada_partner_date_or_null((string) ($input['discussion_date'] ?? '')) ?? '';
    $accepted = canada_partner_date_or_null((string) ($input['accepted_date'] ?? '')) ?? '';
    $started = canada_partner_date_or_null((string) ($input['service_start_date'] ?? '')) ?? '';
    $decision = canada_partner_date_or_null((string) ($input['decision_date'] ?? '')) ?? '';
    $followup = canada_partner_date_or_null((string) ($input['next_followup'] ?? '')) ?? '';
    $staffId = (int) ($input['responsible_admin_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare(
            'UPDATE canada_partner_clients SET
                partner_id = ?, full_name = ?, phone = ?, email = ?, nationality = ?, passport_number = ?,
                progress_status = ?, referral_date = NULLIF(?, \'\'), discussion_date = NULLIF(?, \'\'),
                partner_remarks = ?, accepted_date = NULLIF(?, \'\'), service_start_date = NULLIF(?, \'\'),
                service_fee = ?, decision_date = NULLIF(?, \'\'), decision_outcome = ?, decision_document = ?,
                next_followup = NULLIF(?, \'\'), responsible_admin_id = NULLIF(?, 0), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param(
            'isssssssssssdssssii',
            $partnerId,
            $name,
            $phone,
            $email,
            $nationality,
            $passport,
            $status,
            $referral,
            $discussion,
            $remarks,
            $accepted,
            $started,
            $fee,
            $decision,
            $outcome,
            $documentPath,
            $followup,
            $staffId,
            $id
        );
        $stmt->execute();
        $stmt->close();

        return $id;
    }
    $code = canada_partner_next_code($conn);
    $stmt = $conn->prepare(
        'INSERT INTO canada_partner_clients (
            partner_id, client_code, full_name, phone, email, nationality, passport_number, progress_status,
            referral_date, discussion_date, partner_remarks, accepted_date, service_start_date, service_fee,
            decision_date, decision_outcome, decision_document, next_followup, responsible_admin_id, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?,
            NULLIF(?, \'\'), ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NOW(), NOW()
        )'
    );
    $stmt->bind_param(
        'issssssssssssdssssi',
        $partnerId,
        $code,
        $name,
        $phone,
        $email,
        $nationality,
        $passport,
        $status,
        $referral,
        $discussion,
        $remarks,
        $accepted,
        $started,
        $fee,
        $decision,
        $outcome,
        $documentPath,
        $followup,
        $staffId
    );
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    return $newId;
}

function canada_partner_add_money(mysqli $conn, string $table, int $clientId, string $date, float $amount, string $method, string $note, string $receipt, int $adminId): void
{
    if (!in_array($table, ['canada_partner_payments', 'canada_partner_commission_receipts'], true)) {
        throw new InvalidArgumentException('This payment cannot be recorded.');
    }
    if (canada_partner_client($conn, $clientId) === null) {
        throw new InvalidArgumentException('This client was not found.');
    }
    $paidOn = canada_partner_date_or_null($date);
    if ($paidOn === null) {
        throw new InvalidArgumentException('Enter the payment date.');
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Enter an amount greater than zero.');
    }
    $method = trim($method);
    $note = trim($note);
    if ($table === 'canada_partner_payments') {
        $stmt = $conn->prepare(
            'INSERT INTO canada_partner_payments (client_id, paid_on, amount, method, receipt_path, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0))'
        );
        $stmt->bind_param('isdsssi', $clientId, $paidOn, $amount, $method, $receipt, $note, $adminId);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO canada_partner_commission_receipts (client_id, paid_on, amount, method, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, 0))'
        );
        $stmt->bind_param('isdssi', $clientId, $paidOn, $amount, $method, $note, $adminId);
    }
    $stmt->execute();
    $stmt->close();
}

function canada_partner_remove_money(mysqli $conn, string $table, int $id, int $clientId): void
{
    if (!in_array($table, ['canada_partner_payments', 'canada_partner_commission_receipts'], true)) {
        return;
    }
    $stmt = $conn->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND client_id = ?');
    $stmt->bind_param('ii', $id, $clientId);
    $stmt->execute();
    $stmt->close();
}

/** @return list<array<string, mixed>> */
function canada_partner_money_rows(mysqli $conn, string $table, int $clientId): array
{
    if (!in_array($table, ['canada_partner_payments', 'canada_partner_commission_receipts'], true)) {
        return [];
    }
    $stmt = $conn->prepare('SELECT * FROM ' . $table . ' WHERE client_id = ? ORDER BY paid_on DESC, id DESC');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/** @return list<array{id:int,name:string}> */
function canada_partner_staff(mysqli $conn): array
{
    $res = $conn->query('SELECT id, first_name, last_name, full_name, username FROM admins ORDER BY first_name, last_name, username');
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['full_name'] ?? ''));
            }
            if ($name === '') {
                $name = (string) ($row['username'] ?? 'Staff');
            }
            $rows[] = ['id' => (int) $row['id'], 'name' => $name];
        }
    }

    return $rows;
}

function canada_partner_staff_label(array $row): string
{
    $name = trim((string) ($row['staff_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['staff_full_name'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($row['staff_username'] ?? ''));
    }

    return $name;
}

function canada_partner_rule_label(string $type, float $value): string
{
    if ($type === 'percent') {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% of the service fee';
    }

    return canada_partner_cad($value) . ' per accepted client';
}

/** @param list<array<string, mixed>> $clients @return array<string, float|int> */
function canada_partner_totals(array $clients): array
{
    $totals = [
        'clients' => count($clients),
        'earned' => 0.0,
        'received' => 0.0,
        'outstanding' => 0.0,
        'fees' => 0.0,
        'paid' => 0.0,
        'balance' => 0.0,
    ];
    foreach (canada_partner_statuses() as $key => $meta) {
        $totals[$key] = 0;
    }
    foreach ($clients as $client) {
        $status = (string) ($client['progress_status'] ?? '');
        if (isset($totals[$status])) {
            $totals[$status]++;
        }
        $totals['earned'] += (float) $client['commission_earned'];
        $totals['received'] += (float) $client['commission_received'];
        $totals['outstanding'] += (float) $client['commission_outstanding'];
        $totals['fees'] += (float) $client['service_fee'];
        $totals['paid'] += (float) $client['amount_paid'];
        $totals['balance'] += (float) $client['balance'];
    }
    foreach (['earned', 'received', 'outstanding', 'fees', 'paid', 'balance'] as $key) {
        $totals[$key] = round((float) $totals[$key], 2);
    }

    return $totals;
}

function canada_partner_css(): string
{
    return <<<'CSS'
@import url("https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap");
body { margin: 0; background: #f4f7fb; color: #0f172a; font-family: Manrope, "Segoe UI", system-ui, sans-serif; font-size: 15px; line-height: 1.45; }
.wrap { max-width: 1180px; margin: 0 auto; padding: 28px 18px 56px; }
h1 { margin: 0 0 20px; font-size: 2rem; font-weight: 800; letter-spacing: -0.035em; line-height: 1.15; }
h2 { margin: 0 0 12px; font-size: 1.25rem; font-weight: 800; letter-spacing: -0.025em; }
.kpis, .steps, .split { display: grid; gap: 12px; }
.kpis { grid-template-columns: repeat(auto-fit, minmax(158px, 1fr)); margin-bottom: 18px; }
.kpi, .card, .step { background: #fff; border: 1px solid #e6edf5; border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.kpi { padding: 16px 16px 14px; }
.kpi b { display: block; font-size: 1.45rem; font-weight: 800; letter-spacing: -0.03em; line-height: 1.1; }
.kpi span, .muted { color: #64748b; font-size: 13px; font-weight: 600; }
.card { padding: 18px; margin-bottom: 14px; }
.card > strong, .section-title { display: block; margin-bottom: 10px; font-size: 1.05rem; font-weight: 800; letter-spacing: -0.02em; }
.partner { display: grid; grid-template-columns: 1.4fr .8fr auto; gap: 14px; align-items: center; }
.partner > div > strong { font-size: 1.08rem; font-weight: 800; letter-spacing: -0.02em; }
.counts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.count { background: #f8fafc; border-radius: 999px; padding: 5px 10px; font-size: 12.5px; font-weight: 650; }
.steps { grid-template-columns: repeat(5, 1fr); margin: 8px 0 4px; }
.step { padding: 12px; font-size: 14px; font-weight: 700; }
.step input { margin-right: 6px; }
.step.current { border-color: #16a34a; background: #f0fdf4; }
.step strong { display: block; font-size: 14px; }
.split { grid-template-columns: 1.15fr .85fr; }
label { display: block; font-size: 12px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; margin: 10px 0 5px; }
input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d7e0ec; border-radius: 10px; padding: 10px 12px; font: 600 15px Manrope, "Segoe UI", system-ui, sans-serif; color: #0f172a; background: #fff; }
textarea { min-height: 88px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.money { border-left: 4px solid #d97706; }
.commission { border-left: 4px solid #15803d; }
.commission-amount { margin: 6px 0 0; font-size: 1.7rem; font-weight: 800; letter-spacing: -0.03em; }
button, .btn { display: inline-block; background: #16a34a; color: #fff; border: 0; border-radius: 10px; padding: 10px 16px; font: 700 14px Manrope, "Segoe UI", system-ui, sans-serif; text-decoration: none; cursor: pointer; }
button.ghost, .btn.ghost { background: #f1f5f9; color: #1e293b; }
button.warn { background: #b91c1c; }
.row-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e8eef6; vertical-align: top; font-size: 14.5px; }
th { font-size: 12px; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; }
.filters { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 14px; }
.filters a { text-decoration: none; background: #fff; border: 1px solid #dbe3ef; border-radius: 999px; padding: 7px 12px; color: #1e293b; font-size: 13px; font-weight: 700; }
.filters a.on { background: #16a34a; color: #fff; border-color: #16a34a; }
.err { background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 10px 12px; }
.ok { background: #ecfdf3; color: #166534; border-radius: 10px; padding: 10px 12px; }
.badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 800; background: #e2e8f0; }
.badge.paid { background: #dcfce7; color: #166534; }
.badge.partial { background: #ffedd5; color: #9a3412; }
.badge.unpaid { background: #fee2e2; color: #991b1b; }
@media (max-width: 860px) {
  .partner, .steps, .split, .grid { grid-template-columns: 1fr; }
  h1 { font-size: 1.6rem; }
}
CSS;
}

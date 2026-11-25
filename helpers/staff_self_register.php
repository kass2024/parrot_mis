<?php

/**
 * Public staff self-registration.
 * New accounts sign in with the email address. No password is collected on the form.
 */

require_once __DIR__ . '/mail_smtp.php';

function pcvc_ensure_staff_account_columns(mysqli $conn): void
{
    $columns = [
        'account_type' => "VARCHAR(20) NOT NULL DEFAULT 'personal'",
        'company_name' => 'VARCHAR(190) NULL DEFAULT NULL',
        'company_registration' => 'VARCHAR(80) NULL DEFAULT NULL',
        'company_address' => 'VARCHAR(255) NULL DEFAULT NULL',
        'company_phone' => 'VARCHAR(40) NULL DEFAULT NULL',
        'company_website_url' => 'VARCHAR(190) NULL DEFAULT NULL',
        'account_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
    ];
    foreach ($columns as $name => $definition) {
        $esc = $conn->real_escape_string($name);
        $found = @$conn->query("SHOW COLUMNS FROM `admins` LIKE '{$esc}'");
        if ($found && $found->num_rows > 0) {
            $found->free();
            continue;
        }
        if ($found) {
            $found->free();
        }
        @$conn->query('ALTER TABLE `admins` ADD COLUMN `' . $name . '` ' . $definition);
    }
}

function pcvc_admin_has_column(mysqli $conn, string $column): bool
{
    $esc = $conn->real_escape_string($column);
    $found = @$conn->query("SHOW COLUMNS FROM `admins` LIKE '{$esc}'");
    $yes = $found && $found->num_rows > 0;
    if ($found) {
        $found->free();
    }

    return $yes;
}

function pcvc_admin_column_limit(mysqli $conn, string $column): int
{
    $allowed = ['username', 'email', 'first_name', 'last_name', 'phone_number', 'full_name', 'role'];
    if (!in_array($column, $allowed, true)) {
        return 190;
    }
    $res = $conn->query("SHOW COLUMNS FROM admins LIKE '" . $column . "'");
    if (!$res) {
        return 190;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if (!$row || !preg_match('/\((\d+)\)/', (string) ($row['Type'] ?? ''), $match)) {
        return 190;
    }

    return max(1, (int) $match[1]);
}

function pcvc_generate_staff_password(): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '@#$%';
    $pick = static function (string $set): string {
        return $set[random_int(0, strlen($set) - 1)];
    };
    $chars = [
        $pick($upper),
        $pick($upper),
        $pick($lower),
        $pick($lower),
        $pick($lower),
        $pick($lower),
        $pick($digits),
        $pick($digits),
        $pick($digits),
        $pick($symbols),
    ];
    $pool = $upper . $lower . $digits;
    for ($n = 0; $n < 4; $n++) {
        $chars[] = $pick($pool);
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function pcvc_staff_public_base_url(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if (!in_array($host, ['mis.visaconsultantcanada.com', 'localhost', '127.0.0.1'], true)) {
        $host = 'mis.visaconsultantcanada.com';
    }
    $https = $host === 'mis.visaconsultantcanada.com'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/register.php')));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }

    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function pcvc_staff_login_url(string $email): string
{
    return pcvc_staff_public_base_url() . '/admin-login.php?email=' . rawurlencode($email);
}

/** Public signup link that opens the company-only registration form. */
function pcvc_company_registration_url(): string
{
    return pcvc_staff_public_base_url() . '/register.php?type=company';
}

function pcvc_admin_is_company_account(array $row): bool
{
    return strtolower(trim((string) ($row['account_type'] ?? ''))) === 'company'
        || trim((string) ($row['company_name'] ?? '')) !== '';
}

/**
 * @return array{pending:int,active:int,inactive:int,total:int}
 */
function pcvc_company_account_kpis(mysqli $conn): array
{
    pcvc_ensure_staff_account_columns($conn);
    $kpi = ['pending' => 0, 'active' => 0, 'inactive' => 0, 'total' => 0];
    $res = $conn->query(
        "SELECT
            COUNT(*) AS total,
            SUM(LOWER(TRIM(COALESCE(account_status,''))) = 'pending') AS pending,
            SUM(LOWER(TRIM(COALESCE(account_status,''))) = 'inactive') AS inactive,
            SUM(LOWER(TRIM(COALESCE(account_status,''))) NOT IN ('pending','inactive')) AS active
         FROM admins
         WHERE LOWER(TRIM(COALESCE(account_type,''))) = 'company'
            OR TRIM(COALESCE(company_name,'')) <> ''"
    );
    if ($res && ($row = $res->fetch_assoc())) {
        foreach ($kpi as $k => $_) {
            $kpi[$k] = (int) ($row[$k] ?? 0);
        }
    }

    return $kpi;
}

/**
 * @return list<array<string,mixed>>
 */
function pcvc_list_company_accounts(mysqli $conn, string $filter = 'all'): array
{
    pcvc_ensure_staff_account_columns($conn);
    $where = [
        "(LOWER(TRIM(COALESCE(account_type,''))) = 'company' OR TRIM(COALESCE(company_name,'')) <> '')",
    ];
    $filter = strtolower(trim($filter));
    if ($filter === 'pending') {
        $where[] = "LOWER(TRIM(COALESCE(account_status,''))) = 'pending'";
    } elseif ($filter === 'inactive') {
        $where[] = "LOWER(TRIM(COALESCE(account_status,''))) = 'inactive'";
    } elseif ($filter === 'active') {
        $where[] = "LOWER(TRIM(COALESCE(account_status,''))) NOT IN ('pending','inactive')";
    }
    $sql = 'SELECT * FROM admins WHERE ' . implode(' AND ', $where)
        . " ORDER BY
            CASE LOWER(TRIM(COALESCE(account_status,'')))
                WHEN 'pending' THEN 0
                WHEN 'active' THEN 1
                WHEN '' THEN 1
                WHEN 'inactive' THEN 2
                ELSE 3
            END,
            company_name ASC, full_name ASC, id DESC";
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function pcvc_staff_username_for_email(mysqli $conn, string $email): string
{
    $limit = pcvc_admin_column_limit($conn, 'username');
    if (strlen($email) <= $limit) {
        return $email;
    }
    $local = strstr($email, '@', true);
    $local = strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', (string) $local));
    if ($local === '') {
        $local = 'staff';
    }
    $suffix = '_' . bin2hex(random_bytes(3));
    $room = max(1, $limit - strlen($suffix));

    return substr($local, 0, $room) . $suffix;
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool, error?:string, email?:string, old?:array<string,string>, rotate_csrf?:bool}
 */
function pcvc_self_register_staff(mysqli $conn, array $post, string $csrf): array
{
    $accountType = strtolower(trim((string) ($post['account_type'] ?? 'personal')));
    if ($accountType !== 'company') {
        $accountType = 'personal';
    }
    $website = trim((string) ($post['company_website_url'] ?? ''));
    if ($website !== '' && !preg_match('#^https?://#i', $website)) {
        $website = 'https://' . $website;
    }
    $old = [
        'account_type' => $accountType,
        'first_name' => trim((string) ($post['first_name'] ?? '')),
        'last_name' => trim((string) ($post['last_name'] ?? '')),
        'phone_number' => trim((string) ($post['phone_number'] ?? '')),
        'email' => strtolower(trim((string) ($post['email'] ?? ''))),
        'company_name' => trim((string) ($post['company_name'] ?? '')),
        'company_registration' => trim((string) ($post['company_registration'] ?? '')),
        'company_address' => trim((string) ($post['company_address'] ?? '')),
        'company_phone' => trim((string) ($post['company_phone'] ?? '')),
        'company_website_url' => $website,
    ];

    $postedToken = (string) ($post['csrf'] ?? '');
    if ($csrf === '' || $postedToken === '' || !hash_equals($csrf, $postedToken)) {
        return [
            'ok' => false,
            'error' => 'This page expired. Please submit the form again.',
            'old' => $old,
            'rotate_csrf' => true,
        ];
    }

    if (trim((string) ($post['leave_blank'] ?? '')) !== '') {
        return [
            'ok' => false,
            'error' => 'Please complete the form and try again.',
            'old' => $old,
        ];
    }

    $fieldErrors = pcvc_register_field_errors($old);
    if ($fieldErrors !== []) {
        return [
            'ok' => false,
            'error' => 'Fill in the highlighted fields.',
            'fields' => $fieldErrors,
            'old' => $old,
        ];
    }
    if ($old['account_type'] !== 'company') {
        $old['company_name'] = '';
        $old['company_registration'] = '';
        $old['company_address'] = '';
        $old['company_phone'] = '';
        $old['company_website_url'] = '';
    }

    pcvc_ensure_staff_account_columns($conn);
    if ($old['account_type'] === 'company' && !pcvc_admin_has_column($conn, 'company_name')) {
        return [
            'ok' => false,
            'error' => 'Company registration is unavailable right now. Please try again.',
            'old' => $old,
        ];
    }

    $dup = $conn->prepare('SELECT id FROM admins WHERE email = ? OR username = ? LIMIT 1');
    if (!$dup) {
        return ['ok' => false, 'error' => 'Registration is unavailable right now. Please try again.', 'old' => $old];
    }
    $dup->bind_param('ss', $old['email'], $old['email']);
    $dup->execute();
    $dup->store_result();
    $taken = $dup->num_rows > 0;
    $dup->close();
    if ($taken) {
        return [
            'ok' => false,
            'error' => 'An account already uses this email. Sign in with it, or use Forgot password.',
            'old' => $old,
        ];
    }

    $username = pcvc_staff_username_for_email($conn, $old['email']);
    $password = pcvc_generate_staff_password();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        return ['ok' => false, 'error' => 'Registration is unavailable right now. Please try again.', 'old' => $old];
    }

    $fullName = $old['first_name'] . ' ' . $old['last_name'];
    $createdAt = date('Y-m-d H:i:s');
    $role = 'agent';
    $companyName = $old['account_type'] === 'company' ? $old['company_name'] : '';
    $companyRegistration = $old['account_type'] === 'company' ? $old['company_registration'] : '';
    $companyAddress = $old['account_type'] === 'company' ? $old['company_address'] : '';
    $companyPhone = $old['account_type'] === 'company' ? $old['company_phone'] : '';
    $companyWebsite = $old['account_type'] === 'company' ? $old['company_website_url'] : '';

    $columns = [
        'username' => $username,
        'first_name' => $old['first_name'],
        'last_name' => $old['last_name'],
        'email' => $old['email'],
        'phone_number' => $old['phone_number'],
        'password_hash' => $passwordHash,
        'full_name' => $fullName,
        'created_at' => $createdAt,
        'role' => $role,
        'account_status' => 'pending',
        'account_type' => $old['account_type'],
        'company_name' => $companyName,
        'company_registration' => $companyRegistration,
        'company_address' => $companyAddress,
        'company_phone' => $companyPhone,
        'company_website_url' => $companyWebsite,
    ];
    $fields = [];
    $holders = [];
    $values = [];
    foreach ($columns as $column => $value) {
        if (!pcvc_admin_has_column($conn, $column)) {
            continue;
        }
        $fields[] = '`' . $column . '`';
        $holders[] = '?';
        $values[] = $value;
    }
    $sql = 'INSERT INTO admins (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Registration is unavailable right now. Please try again.', 'old' => $old];
    }
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);
    $saved = $stmt->execute();
    $newId = $saved ? (int) $conn->insert_id : 0;
    $stmt->close();
    if ($saved && $newId > 0 && pcvc_admin_has_column($conn, 'role')) {
        $roleFix = $conn->prepare('UPDATE admins SET role = ? WHERE id = ? LIMIT 1');
        if ($roleFix) {
            $agentRole = 'agent';
            $roleFix->bind_param('si', $agentRole, $newId);
            $roleFix->execute();
            $roleFix->close();
        }
    }
    if (!$saved || $newId < 1) {
        return [
            'ok' => false,
            'error' => 'An account already uses this email. Sign in with it, or use Forgot password.',
            'old' => $old,
        ];
    }

    return [
        'ok' => true,
        'email' => $old['email'],
        'pending' => true,
        'rotate_csrf' => true,
    ];
}

/**
 * @return array{ok:bool, error?:string}
 */
/**
 * @param array<string, string> $old
 * @return array<string, string>
 */
function pcvc_register_field_errors(array $old): array
{
    $fields = [];
    $nameOk = static function (string $value): bool {
        return (bool) preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’\\-]{0,79}$/u", $value);
    };
    if ($old['first_name'] === '') {
        $fields['first_name'] = 'This field is required.';
    } elseif (!$nameOk($old['first_name'])) {
        $fields['first_name'] = 'Use letters only.';
    }
    if ($old['last_name'] === '') {
        $fields['last_name'] = 'This field is required.';
    } elseif (!$nameOk($old['last_name'])) {
        $fields['last_name'] = 'Use letters only.';
    }
    if ($old['phone_number'] === '') {
        $fields['phone_number'] = 'This field is required.';
    } elseif (!preg_match('/^[0-9+\s().-]{7,24}$/', $old['phone_number'])) {
        $fields['phone_number'] = 'Enter a phone number with the country code.';
    }
    if ($old['email'] === '') {
        $fields['email'] = 'This field is required.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 190) {
        $fields['email'] = 'Enter a valid email address.';
    }
    if ($old['account_type'] === 'company') {
        $fields = array_merge($fields, pcvc_company_field_errors($old));
    }

    return $fields;
}

/**
 * @param array<string, string> $old
 * @return array<string, string>
 */
function pcvc_company_field_errors(array $old): array
{
    $fields = [];
    if ($old['company_name'] === '') {
        $fields['company_name'] = 'This field is required.';
    } elseif (!preg_match("/^[\\p{L}\\p{M}0-9][\\p{L}\\p{M}0-9 .,&'’()\\-]{1,188}$/u", $old['company_name'])) {
        $fields['company_name'] = 'Enter the company name.';
    }
    if ($old['company_registration'] === '') {
        $fields['company_registration'] = 'This field is required.';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{1,78}$/', $old['company_registration'])) {
        $fields['company_registration'] = 'Enter the registration number.';
    }
    $address = $old['company_address'];
    if ($address === '') {
        $fields['company_address'] = 'This field is required.';
    } elseif (strlen($address) < 5 || strlen($address) > 255 || preg_match('/[\\x00-\\x1F]/', $address)) {
        $fields['company_address'] = 'Enter the company address.';
    }
    if ($old['company_phone'] === '') {
        $fields['company_phone'] = 'This field is required.';
    } elseif (!preg_match('/^[0-9+\\s().-]{7,24}$/', $old['company_phone'])) {
        $fields['company_phone'] = 'Enter a phone number with the country code.';
    }
    if ($old['company_website_url'] !== '' && (
        strlen($old['company_website_url']) > 190
        || !filter_var($old['company_website_url'], FILTER_VALIDATE_URL)
    )) {
        $fields['company_website_url'] = 'Enter a valid website, or leave it blank.';
    }

    return $fields;
}

function pcvc_approve_staff_account(mysqli $conn, int $adminId): array
{
    if ($adminId < 1) {
        return ['ok' => false, 'error' => 'Choose an account to approve.'];
    }
    pcvc_ensure_staff_account_columns($conn);
    $st = $conn->prepare(
        'SELECT id, email, first_name, last_name, full_name, account_type, company_name, account_status
         FROM admins WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Approval is unavailable right now.'];
    }
    $st->bind_param('i', $adminId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return ['ok' => false, 'error' => 'That account was not found.'];
    }
    $status = strtolower(trim((string) ($row['account_status'] ?? 'active')));
    if ($status !== 'pending') {
        return ['ok' => false, 'error' => 'This account is already approved.'];
    }
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'This account has no valid email, so the sign-in details cannot be sent.'];
    }
    $fullName = trim((string) ($row['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    }
    $companyName = trim((string) ($row['company_name'] ?? ''));
    $isCompany = strtolower(trim((string) ($row['account_type'] ?? ''))) === 'company' || $companyName !== '';
    $password = pcvc_generate_staff_password();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        return ['ok' => false, 'error' => 'Approval is unavailable right now.'];
    }
    $loginUrl = pcvc_staff_login_url($email);
    $sent = pcvc_send_staff_welcome_email($email, $fullName !== '' ? $fullName : $email, $password, $loginUrl, $companyName, $isCompany);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => 'The approval email could not be sent, so the account is still waiting.'];
    }
    $active = 'active';
    $upd = $conn->prepare('UPDATE admins SET password_hash = ?, account_status = ? WHERE id = ? AND account_status = ? LIMIT 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'The email was sent, but the account could not be marked approved. Approve it again only if they still cannot sign in.'];
    }
    $pending = 'pending';
    $upd->bind_param('ssis', $passwordHash, $active, $adminId, $pending);
    $upd->execute();
    $changed = $upd->affected_rows > 0;
    $upd->close();
    if (!$changed) {
        return ['ok' => false, 'error' => 'The email was sent, but the account status did not change. Check the account before approving again.'];
    }

    return ['ok' => true, 'email' => $email, 'name' => $fullName !== '' ? $fullName : $email];
}

function pcvc_send_staff_welcome_email(string $email, string $fullName, string $password, string $loginUrl, string $registeredCompany = '', bool $askCompanyDocuments = false): array
{
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $company = defined('PCVC_COMPANY_DISPLAY_NAME') ? PCVC_COMPANY_DISPLAY_NAME : 'Parrot Canada Visa Consultant';
    $safeCompany = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
    $companyLine = '';
    if (trim($registeredCompany) !== '') {
        $companyLine = '<div style="color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em">Company</div>'
            . '<div style="font-weight:700;margin-bottom:12px">' . htmlspecialchars($registeredCompany, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $html = '<div style="margin:0;padding:24px 12px;background:#f4f7f4;font-family:Arial,Helvetica,sans-serif;color:#1e293b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="background:#427431;padding:28px 28px 22px;color:#ffffff">'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.04em;text-transform:uppercase;opacity:.9">' . $safeCompany . '</p>'
        . '<h1 style="margin:0;font-size:22px;line-height:1.3">Your account is approved</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:28px">'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6">Hello <strong>' . $safeName . '</strong>,</p>'
        . '<p style="margin:0 0 18px;font-size:15px;line-height:1.6">A superadmin approved your account. Use the email address below to sign in. Your password was created for you.</p>'
        . '<p style="margin:0 0 22px"><a href="' . $safeUrl . '" style="display:inline-block;background:#427431;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px">Sign in</a></p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8faf8;border:1px solid #d9e6d4;border-radius:12px">'
        . '<tr><td style="padding:16px 18px;font-size:14px;line-height:1.7">'
        . $companyLine
        . '<div style="color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em">Email</div>'
        . '<div style="font-weight:700;margin-bottom:12px">' . $safeEmail . '</div>'
        . '<div style="color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em">Password</div>'
        . '<div style="font-weight:700;font-family:Consolas,Monaco,monospace">' . $safePassword . '</div>'
        . '</td></tr></table>'
        . ($askCompanyDocuments
            ? '<p style="margin:18px 0 0;padding:14px 16px;background:#f8faf8;border:1px solid #d9e6d4;border-radius:12px;font-size:14px;line-height:1.6">Please send your company registration certificate and a copy of the valid passport or national ID of the company owner or authorized representative to <a href="mailto:partnership@visaconsultantcanada.com" style="color:#3661B9">partnership@visaconsultantcanada.com</a></p>'
            : '')
        . '<p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#64748b">If the button does not open, copy this link:<br><a href="' . $safeUrl . '" style="color:#3661B9">' . $safeUrl . '</a></p>'
        . '<p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#64748b">After you sign in, you can change this password from Forgot password on the sign-in page.</p>'
        . '</td></tr></table></div>';

    return sendSMTPMailDetailed(
        $email,
        'Your ' . $company . ' account is approved',
        $html
    );
}

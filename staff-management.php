<?php
// staff-management.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/company_branding.php';
require_once __DIR__ . '/helpers/secure_file.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/helpers/staff_self_register.php';
require_once __DIR__ . '/helpers/staff_contract_schema.php';
require_once __DIR__ . '/helpers/company_view_as.php';
pcvc_ensure_staff_account_columns($conn);
pcvc_ensure_director_role($conn);
$companyBrandName = PCVC_COMPANY_DISPLAY_NAME;
/* ============================================================
   SECURITY CHECK 
============================================================ */
if (!isset($_SESSION['id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$isSuperAdmin = pcvc_is_superadmin_role($_SESSION['role'] ?? '');
$currentUserId = $_SESSION['id'];
if (empty($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(16));
}

/* ============================================================
   DELETE STAFF (SUPERADMIN ONLY)
============================================================ */
if (
    $isSuperAdmin
    && (
        (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] !== 'POST')
        || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account']))
    )
) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = (string) ($_POST['csrf_token'] ?? '');
        if ($postedToken === '' || !hash_equals((string) $_SESSION['staff_csrf'], $postedToken)) {
            $_SESSION['error'] = 'This request expired. Refresh the page and try again.';
            header('Location: staff-management.php');
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
    } else {
        $id = (int) ($_GET['delete'] ?? 0);
    }
    if ($id < 1 || $id === (int) $currentUserId) {
        $_SESSION['error'] = 'You cannot delete your own account.';
    } else {
        $deleted = pcvc_delete_admin_account($conn, $id);
        if (!empty($deleted['ok'])) {
            $_SESSION['success'] = 'Account deleted.';
        } else {
            $_SESSION['error'] = (string) ($deleted['error'] ?? 'That account could not be deleted.');
        }
    }
    header('Location: staff-management.php');
    exit;
}

/* ============================================================
   UPDATE STAFF (SUPERADMIN ONLY)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_staff']) && $isSuperAdmin) {

    $id        = intval($_POST['id']);
    $fullName  = trim((string) ($_POST['full_name'] ?? ''));
    $emailIn   = trim((string) ($_POST['email'] ?? ''));
    $phoneIn   = trim((string) ($_POST['phone_number'] ?? ''));
    $usernameIn = trim((string) ($_POST['username'] ?? ''));

    $salary    = floatval($_POST['salary_per_minute']);
    $monthly   = ($_POST['monthly_salary'] !== '' ? floatval($_POST['monthly_salary']) : null);
    $currency  = $_POST['salary_currency'] ?? 'RWF';
    $break     = intval($_POST['allowed_break_minutes']);
    $days      = intval($_POST['work_days_per_week']);
    $sheet     = trim($_POST['sheet_id'] ?? '');
    $link      = trim($_POST['sheet_link'] ?? '');
    $officeId  = ($_POST['office_id'] !== '' ? intval($_POST['office_id']) : null);

    // Role update (superadmin only can change roles)
    $role = trim((string) ($_POST['role'] ?? 'staff'));
    if (!isset(pcvc_admin_role_options()[$role])) {
        $role = 'staff';
    }

    // HR / CONTRACT FIELDS
    $position     = trim($_POST['position'] ?? '');
    $empType      = pcvc_staff_employment_type_store(trim((string) ($_POST['employment_type'] ?? '')));
    $startDate    = $_POST['employment_start_date'] ?: null;
    $nid          = trim($_POST['national_id'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $dob          = $_POST['date_of_birth'] ?: null;
    $marital      = $_POST['marital_status'] ?? null;
    $nationality  = trim($_POST['nationality'] ?? '');
    $birthPlace   = trim($_POST['place_of_birth'] ?? '');

    if ($fullName === '' || $emailIn === '' || $usernameIn === '') {
        $_SESSION['error'] = 'Full name, email, and username are required.';
        header('Location: staff-management.php');
        exit;
    }
    if (!filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Please enter a valid email address.';
        header('Location: staff-management.php');
        exit;
    }

    $nameParts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts[0] ?? '';
    $lastName = isset($nameParts[1]) ? trim((string) $nameParts[1]) : '';

    $chkUser = $conn->prepare('SELECT id FROM admins WHERE username = ? AND id != ? LIMIT 1');
    if ($chkUser) {
        $chkUser->bind_param('si', $usernameIn, $id);
        $chkUser->execute();
        $dupUser = $chkUser->get_result()->fetch_assoc();
        $chkUser->close();
        if ($dupUser) {
            $_SESSION['error'] = 'That username is already taken by another account.';
            header('Location: staff-management.php');
            exit;
        }
    }

    $chkMail = $conn->prepare('SELECT id FROM admins WHERE email = ? AND id != ? LIMIT 1');
    if ($chkMail) {
        $chkMail->bind_param('si', $emailIn, $id);
        $chkMail->execute();
        $dupMail = $chkMail->get_result()->fetch_assoc();
        $chkMail->close();
        if ($dupMail) {
            $_SESSION['error'] = 'That email is already used by another account.';
            header('Location: staff-management.php');
            exit;
        }
    }

    $stmt = $conn->prepare("
        UPDATE admins SET
            username = ?,
            first_name = ?,
            last_name = ?,
            full_name = ?,
            email = ?,
            phone_number = ?,
            role = ?,
            position = ?,
            employment_type = ?,
            employment_start_date = ?,
            national_id = ?,
            date_of_birth = ?,
            marital_status = ?,
            nationality = ?,
            place_of_birth = ?,
            address = ?,
            salary_per_minute = ?,
            monthly_salary = ?,
            salary_currency = ?,
            allowed_break_minutes = ?,
            work_days_per_week = ?,
            sheet_id = ?,
            sheet_link = ?,
            office_id = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        'ssssssssssssssssddsiissii',
        $usernameIn,
        $firstName,
        $lastName,
        $fullName,
        $emailIn,
        $phoneIn,
        $role,
        $position,
        $empType,
        $startDate,
        $nid,
        $dob,
        $marital,
        $nationality,
        $birthPlace,
        $address,
        $salary,
        $monthly,
        $currency,
        $break,
        $days,
        $sheet,
        $link,
        $officeId,
        $id
    );

    if ($stmt->execute()) {
        if (isset($_POST['account_type']) && function_exists('pcvc_admin_has_column') && pcvc_admin_has_column($conn, 'company_name')) {
            $cType = strtolower(trim((string) ($_POST['account_type'] ?? 'personal')));
            if ($cType !== 'company') {
                $cType = 'personal';
            }
            $cName = trim((string) ($_POST['company_name'] ?? ''));
            $cReg = trim((string) ($_POST['company_registration'] ?? ''));
            $cAddr = trim((string) ($_POST['company_address'] ?? ''));
            $cPhone = trim((string) ($_POST['company_phone'] ?? ''));
            $cWeb = trim((string) ($_POST['company_website_url'] ?? ''));
            $cUp = $conn->prepare('UPDATE admins SET account_type = ?, company_name = ?, company_registration = ?, company_address = ?, company_phone = ?, company_website_url = ? WHERE id = ? LIMIT 1');
            if ($cUp) {
                $cUp->bind_param('ssssssi', $cType, $cName, $cReg, $cAddr, $cPhone, $cWeb, $id);
                $cUp->execute();
                $cUp->close();
            }
        }
        $_SESSION['success'] = 'Account updated.';
        require_once __DIR__ . '/helpers/staff_contract_witness.php';
        try {
            pcvc_staff_contract_sync_employment_status($conn, (int) $id);
        } catch (Throwable $e) {
            // The account save already succeeded. A missing contract file must not block it.
        }

        if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed, true) && $_FILES['profile_photo']['size'] <= 2 * 1024 * 1024) {
                $oldStmt = $conn->prepare('SELECT profile_photo FROM admins WHERE id = ? LIMIT 1');
                if ($oldStmt) {
                    $oldStmt->bind_param('i', $id);
                    $oldStmt->execute();
                    $oldRow = $oldStmt->get_result()->fetch_assoc();
                    $oldStmt->close();
                    $photoName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $photoName)) {
                        $photoUpd = $conn->prepare('UPDATE admins SET profile_photo = ? WHERE id = ?');
                        if ($photoUpd) {
                            $photoUpd->bind_param('si', $photoName, $id);
                            $photoUpd->execute();
                            $photoUpd->close();
                            $oldPhoto = trim((string) ($oldRow['profile_photo'] ?? ''));
                            if ($oldPhoto !== '' && $oldPhoto !== 'default_avatar.png') {
                                $oldPath = $uploadDir . basename($oldPhoto);
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                        }
                    }
                }
            }
        }
    } else {
        $_SESSION['error'] = 'Update failed: ' . $conn->error;
    }
    $stmt->close();

    header('Location: staff-management.php');
    exit;
}

/* ============================================================
   ADD STAFF (SUPERADMIN ONLY) — all fields optional
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff']) && $isSuperAdmin) {
    $fullName   = trim((string) ($_POST['full_name'] ?? ''));
    $emailIn    = trim((string) ($_POST['email'] ?? ''));
    $phoneIn    = trim((string) ($_POST['phone_number'] ?? ''));
    $usernameIn = trim((string) ($_POST['username'] ?? ''));
    $passwordIn = (string) ($_POST['password'] ?? '');
    $role       = trim((string) ($_POST['role'] ?? 'staff'));
    if (!isset(pcvc_admin_role_options()[$role])) {
        $role = 'staff';
    }

    $position    = trim((string) ($_POST['position'] ?? ''));
    $empType     = pcvc_staff_employment_type_store(trim((string) ($_POST['employment_type'] ?? '')));
    $startDate   = trim((string) ($_POST['employment_start_date'] ?? '')) ?: null;
    $nid         = trim((string) ($_POST['national_id'] ?? ''));
    $address     = trim((string) ($_POST['address'] ?? ''));
    $dob         = trim((string) ($_POST['date_of_birth'] ?? '')) ?: null;
    $marital     = trim((string) ($_POST['marital_status'] ?? '')) ?: null;
    $nationality = trim((string) ($_POST['nationality'] ?? ''));
    $birthPlace  = trim((string) ($_POST['place_of_birth'] ?? ''));

    $salary   = ($_POST['salary_per_minute'] !== '' ? floatval($_POST['salary_per_minute']) : 0.0);
    $monthly  = ($_POST['monthly_salary'] !== '' ? floatval($_POST['monthly_salary']) : null);
    $currency = trim((string) ($_POST['salary_currency'] ?? 'RWF'));
    if (!in_array($currency, ['RWF', 'KES', 'USD'], true)) {
        $currency = 'RWF';
    }
    $break    = ($_POST['allowed_break_minutes'] !== '' ? intval($_POST['allowed_break_minutes']) : 60);
    $days     = ($_POST['work_days_per_week'] !== '' ? intval($_POST['work_days_per_week']) : 5);
    $sheet    = trim((string) ($_POST['sheet_id'] ?? ''));
    $link     = trim((string) ($_POST['sheet_link'] ?? ''));
    $officeId = (isset($_POST['office_id']) && $_POST['office_id'] !== '' ? intval($_POST['office_id']) : null);

    if ($empType !== '' && pcvc_staff_employment_type_normalize($empType) === '' && strcasecmp($empType, 'Contract') !== 0) {
        $empType = '';
    }
    if ($marital !== null && !in_array($marital, ['Single', 'Married', 'Divorced', 'Widowed'], true)) {
        $marital = null;
    }

    if ($emailIn !== '' && !filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Please enter a valid email address, or leave email blank.';
        header('Location: staff-management.php');
        exit;
    }

    // DB requires username + password_hash — auto-fill only when left blank
    if ($usernameIn === '') {
        $usernameIn = 'staff_' . strtolower(bin2hex(random_bytes(4)));
    }
    if ($passwordIn === '') {
        $passwordIn = 'Parrot@ChangeMe2026';
    }
    $passwordHash = password_hash($passwordIn, PASSWORD_DEFAULT);

    if ($fullName === '') {
        $fullName = $usernameIn;
    }
    $nameParts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts[0] ?? '';
    $lastName  = isset($nameParts[1]) ? trim((string) $nameParts[1]) : '';

    $chkUser = $conn->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    if ($chkUser) {
        $chkUser->bind_param('s', $usernameIn);
        $chkUser->execute();
        $dupUser = $chkUser->get_result()->fetch_assoc();
        $chkUser->close();
        if ($dupUser) {
            $_SESSION['error'] = 'That username is already taken. Choose another or leave blank to auto-generate.';
            header('Location: staff-management.php');
            exit;
        }
    }

    if ($emailIn !== '') {
        $chkMail = $conn->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
        if ($chkMail) {
            $chkMail->bind_param('s', $emailIn);
            $chkMail->execute();
            $dupMail = $chkMail->get_result()->fetch_assoc();
            $chkMail->close();
            if ($dupMail) {
                $_SESSION['error'] = 'That email is already used by another account.';
                header('Location: staff-management.php');
                exit;
            }
        }
    }

    $empTypeDb = $empType !== '' ? $empType : null;

    $stmt = $conn->prepare("
        INSERT INTO admins (
            username, first_name, last_name, full_name, email, phone_number, password_hash, role,
            position, employment_type, employment_start_date, national_id, date_of_birth,
            marital_status, nationality, place_of_birth, address,
            salary_per_minute, monthly_salary, salary_currency, allowed_break_minutes, work_days_per_week,
            sheet_id, sheet_link, office_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Could not prepare insert: ' . $conn->error;
        header('Location: staff-management.php');
        exit;
    }

    $stmt->bind_param(
        'sssssssssssssssssddsiissi',
        $usernameIn,
        $firstName,
        $lastName,
        $fullName,
        $emailIn,
        $phoneIn,
        $passwordHash,
        $role,
        $position,
        $empTypeDb,
        $startDate,
        $nid,
        $dob,
        $marital,
        $nationality,
        $birthPlace,
        $address,
        $salary,
        $monthly,
        $currency,
        $break,
        $days,
        $sheet,
        $link,
        $officeId
    );

    if ($stmt->execute()) {
        $newId = (int) $conn->insert_id;
        $msg = 'Staff member added successfully';
        if (trim((string) ($_POST['username'] ?? '')) === '') {
            $msg .= ' (username: ' . $usernameIn . ')';
        }
        if (trim((string) ($_POST['password'] ?? '')) === '') {
            $msg .= '. Temporary password: Parrot@ChangeMe2026 — ask them to change it after login.';
        }
        $_SESSION['success'] = $msg;

        // Company dashboard: keep new staff inside this company only.
        $companyCtxForLink = pcvc_effective_company_context($conn);
        if ($newId > 0 && $companyCtxForLink) {
            pcvc_link_admin_to_company($conn, $newId, (int) ($companyCtxForLink['id'] ?? 0));
        }

        if ($newId > 0 && !empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed, true) && $_FILES['profile_photo']['size'] <= 2 * 1024 * 1024) {
                $photoName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $photoName)) {
                    $photoUpd = $conn->prepare('UPDATE admins SET profile_photo = ? WHERE id = ?');
                    if ($photoUpd) {
                        $photoUpd->bind_param('si', $photoName, $newId);
                        $photoUpd->execute();
                        $photoUpd->close();
                    }
                }
            }
        }
    } else {
        $_SESSION['error'] = 'Could not add staff: ' . $stmt->error;
    }
    $stmt->close();

    header('Location: staff-management.php');
    exit;
}

/* ============================================================
   RESET STAFF PASSWORD (SUPERADMIN ONLY)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_staff_password']) && $isSuperAdmin) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals((string) $_SESSION['staff_csrf'], $postedToken)) {
        $_SESSION['error'] = 'The reset request expired. Refresh the page and try again.';
        header('Location: staff-management.php');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    if ($id < 1 || strlen($newPassword) < 8) {
        $_SESSION['error'] = 'Enter a new password of at least 8 characters.';
        header('Location: staff-management.php');
        exit;
    }

    $lookup = $conn->prepare('SELECT id, username, full_name, first_name, last_name FROM admins WHERE id = ? LIMIT 1');
    if (!$lookup) {
        $_SESSION['error'] = 'Could not load that staff account.';
        header('Location: staff-management.php');
        exit;
    }
    $lookup->bind_param('i', $id);
    $lookup->execute();
    $staff = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$staff || trim((string) ($staff['username'] ?? '')) === '') {
        $_SESSION['error'] = 'That staff account has no username.';
        header('Location: staff-management.php');
        exit;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE admins SET password_hash = ? WHERE id = ? LIMIT 1');
    if (!$update) {
        $_SESSION['error'] = 'Could not reset the password.';
        header('Location: staff-management.php');
        exit;
    }
    $update->bind_param('si', $passwordHash, $id);
    $saved = $update->execute();
    $update->close();
    if (!$saved) {
        $_SESSION['error'] = 'Could not reset the password.';
        header('Location: staff-management.php');
        exit;
    }

    $loginUrl = rtrim(pcvc_public_base_url(), '/') . '/admin-login.php';
    $display = trim((string) ($staff['full_name'] ?? ''));
    if ($display === '') {
        $display = trim((string) (($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? '')));
    }
    $_SESSION['reset_credentials'] = [
        'name' => $display !== '' ? $display : (string) $staff['username'],
        'username' => (string) $staff['username'],
        'password' => $newPassword,
        'login' => $loginUrl,
    ];
    $_SESSION['success'] = 'Password reset for ' . $staff['username'] . '. Copy the credentials below before you leave this page.';
    header('Location: staff-management.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_account']) && $isSuperAdmin) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals((string) $_SESSION['staff_csrf'], $postedToken)) {
        $_SESSION['error'] = 'This approval request expired. Refresh the page and try again.';
        header('Location: staff-management.php');
        exit;
    }
    $result = pcvc_approve_staff_account($conn, (int) ($_POST['id'] ?? 0));
    if (!empty($result['ok'])) {
        $_SESSION['success'] = 'Approved ' . (string) ($result['name'] ?? 'the account') . '. The sign-in email was sent to ' . (string) ($result['email'] ?? 'the new agent') . '.';
    } else {
        $_SESSION['error'] = (string) ($result['error'] ?? 'Approval failed.');
    }
    header('Location: staff-management.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account']) && $isSuperAdmin) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals((string) $_SESSION['staff_csrf'], $postedToken)) {
        $_SESSION['error'] = 'This request expired. Refresh the page and try again.';
        header('Location: staff-management.php');
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1 || $id === (int) $currentUserId) {
        $_SESSION['error'] = 'You cannot deactivate your own account.';
        header('Location: staff-management.php');
        exit;
    }
    $inactive = 'inactive';
    $st = $conn->prepare('UPDATE admins SET account_status = ? WHERE id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('si', $inactive, $id);
        $st->execute();
        $st->close();
        $_SESSION['success'] = 'Account deactivated. They can no longer sign in.';
    } else {
        $_SESSION['error'] = 'Could not deactivate that account.';
    }
    header('Location: staff-management.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_account']) && $isSuperAdmin) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals((string) $_SESSION['staff_csrf'], $postedToken)) {
        $_SESSION['error'] = 'This request expired. Refresh the page and try again.';
        header('Location: staff-management.php');
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $active = 'active';
    $onlyInactive = 'inactive';
    $st = $conn->prepare('UPDATE admins SET account_status = ? WHERE id = ? AND account_status = ? LIMIT 1');
    if ($st && $id > 0) {
        $st->bind_param('sis', $active, $id, $onlyInactive);
        $st->execute();
        $changed = $st->affected_rows > 0;
        $st->close();
        $_SESSION['success'] = $changed ? 'Account reactivated. They can sign in again.' : 'That account was not inactive.';
    } else {
        $_SESSION['error'] = 'Could not reactivate that account.';
    }
    header('Location: staff-management.php');
    exit;
}

/* ============================================================
   FETCH ADMINS + OFFICES
============================================================ */
// Company dashboard view: only this company + its linked staff/agents (no Parrot HQ mix).
$actingCompanyCtx = pcvc_effective_company_context($conn);
$companyScopeIds = [];
if ($actingCompanyCtx) {
    $companyScopeIds = pcvc_company_member_admin_ids($conn, (int) ($actingCompanyCtx['id'] ?? 0));
}
$companyScopeSql = $companyScopeIds !== []
    ? ('id IN (' . implode(',', array_map('intval', $companyScopeIds)) . ')')
    : '';

if ($companyScopeSql !== '') {
    $admins = $conn->query(
        "SELECT * FROM admins WHERE {$companyScopeSql}
         ORDER BY created_at DESC"
    );
} else {
    // Staff / agents only — company partners are managed on companies-management.php
    $admins = $conn->query("SELECT * FROM admins
        WHERE LOWER(TRIM(COALESCE(account_type,''))) <> 'company'
          AND TRIM(COALESCE(company_name,'')) = ''
        ORDER BY 
        CASE 
            WHEN role IN ('superadmin', 'director', 'secretary') THEN 1
            WHEN role = 'admin' THEN 2
            ELSE 3
        END, created_at DESC");
}
    
$offices = $conn->query("SELECT id, office_name FROM offices ORDER BY office_name ASC");
$pendingAccounts = [];
if ($companyScopeSql === '') {
    $pendingRes = $conn->query(
        "SELECT id, full_name, first_name, last_name, email, phone_number, account_type, company_name,
                company_registration, company_address, company_phone, company_website_url, created_at
         FROM admins
         WHERE account_status = 'pending'
           AND LOWER(TRIM(COALESCE(account_type,''))) <> 'company'
           AND TRIM(COALESCE(company_name,'')) = ''
         ORDER BY created_at ASC"
    );
} else {
    $pendingRes = false;
}
if ($pendingRes) {
    while ($pendingRow = $pendingRes->fetch_assoc()) {
        $pendingAccounts[] = $pendingRow;
    }
    $pendingRes->free();
}

// Get success/error messages
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
$resetCredentials = $_SESSION['reset_credentials'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['reset_credentials']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parrot Canada Visa — Accounts management</title>
    
    <!-- Brand color variables -->
    <style>
        :root {
            --deep-navy: #427431;
            --secondary-blue: #3661B9;
            --dark-blue: #2f5a26;
            --gold: #E21D1E;
            --white: #FFFFFF;
            --light-bg: #f8fafc;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --success: #2e7d32;
            --danger: #c62828;
            --warning: #ed6c02;
            --info: #0288d1;
            --border-light: #e2e8f0;
        }
    </style>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(180deg, var(--white) 0%, #f0f4f8 100%);
            color: var(--text-dark);
            min-height: 100vh;
            overflow: hidden;
        }

        /* ===== Brand header ===== */
        .brand-payroll-header {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            padding: 12px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 3px solid var(--gold);
        }

        .header-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 0 24px;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-main {
            font-size: 2rem;
            font-weight: 800;
            color: var(--white);
            letter-spacing: 1px;
            position: relative;
            display: inline-block;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .logo-main::after {
            content: '🎓';
            position: absolute;
            top: -8px;
            right: -35px;
            font-size: 1.8rem;
            filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.3));
        }

        .logo-subtitle {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 1px;
            border-left: 3px solid var(--gold);
            padding-left: 20px;
            text-transform: uppercase;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        /* ===== MAIN CONTAINER - FULL HEIGHT ===== */
        .main-container {
            height: calc(100vh - 80px);
            padding: 20px 24px 0 24px;
            display: flex;
            flex-direction: column;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--deep-navy);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .page-title i {
            color: var(--gold);
            font-size: 28px;
        }

        .stats-container {
            display: flex;
            gap: 15px;
        }

        .stat-card {
            background: var(--white);
            padding: 10px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(1, 47, 107, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border-light);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 20px;
        }

        .stat-info h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--deep-navy);
            line-height: 1.2;
        }

        .stat-info p {
            margin: 0;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* ===== ALERTS ===== */
        .alert {
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            border: none;
            font-weight: 500;
            flex-shrink: 0;
        }

        /* ===== SEARCH & FILTERS ===== */
        .filters-section {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(1, 47, 107, 0.08);
            border: 1px solid var(--border-light);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            flex-shrink: 0;
        }

        .search-wrapper {
            flex: 1;
            min-width: 300px;
            position: relative;
        }

        .search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        #searchBox {
            width: 100%;
            padding: 10px 16px 10px 42px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }

        #searchBox:focus {
            outline: none;
            border-color: var(--gold);
        }

        .filter-badge {
            background: var(--light-bg);
            padding: 6px 12px;
            border-radius: 6px;
            color: var(--text-muted);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .filter-badge:hover {
            background: var(--border-light);
        }

        .filter-badge.active {
            background: var(--deep-navy);
            color: var(--white);
        }

        .btn-add-staff {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--deep-navy) 0%, #2f5a26 100%);
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(66, 116, 49, 0.25);
            white-space: nowrap;
        }

        .btn-add-staff:hover {
            color: #fff;
            filter: brightness(1.05);
        }

        #addStaffModal .modal-header {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            color: #fff;
            border-bottom: 3px solid var(--gold);
        }

        #addStaffModal .modal-title {
            font-weight: 700;
        }

        #addStaffModal .form-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        #addStaffModal .optional-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* ===== TABLE CARD - FULL HEIGHT ===== */
        .table-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(1, 47, 107, 0.1);
            border: 1px solid var(--border-light);
            overflow: hidden;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* ===== TABLE RESPONSIVE - FIXED HEADER, SCROLLING BODY ===== */
        .table-responsive {
            flex: 1;
            overflow: auto;
            position: relative;
        }

        /* Improved table headers - LIGHTER BACKGROUND for better visibility */
        th {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f6 100%);
            color: var(--deep-navy);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 8px;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            border-bottom: 2px solid var(--gold);
            border-right: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
        }

        th:last-child {
            border-right: none;
        }

        th i {
            margin-left: 4px;
            color: var(--gold);
        }

        /* Table cells - NO WRAP, FULL WIDTH */
        td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid #f1f3f5;
            vertical-align: middle;
            background: var(--white);
            white-space: nowrap;
            min-width: 100px;
        }

        td:last-child {
            border-right: none;
        }

        /* Make all form elements fit in cells */
        .form-control-sm, .form-select-sm {
            padding: 6px 8px;
            font-size: 12px;
            height: 32px;
            width: 100%;
            min-width: 100px;
        }

        /* Profile image - fixed size */
        .profile-img-container {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--gold);
            margin: 0 auto;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--deep-navy) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin: 0 auto;
        }

        .profile-upload-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin: 0 auto;
        }

        .profile-upload-wrap .profile-img-container,
        .profile-upload-wrap .profile-placeholder {
            width: 36px;
            height: 36px;
        }

        .profile-upload-btn {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: rgba(30, 41, 59, 0.55);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.15s ease;
            font-size: 14px;
        }

        .profile-upload-wrap:hover .profile-upload-btn,
        .profile-upload-wrap:focus-within .profile-upload-btn {
            opacity: 1;
        }

        .profile-upload-wrap.is-uploading .profile-upload-btn {
            opacity: 1;
            pointer-events: none;
        }

        .profile-upload-wrap.is-uploading .profile-upload-btn i {
            animation: spin 0.8s linear infinite;
        }

        /* Role badges - compact */
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            width: 100%;
            text-align: center;
        }

        .role-superadmin {
            background: linear-gradient(135deg, var(--gold) 0%, #e6953e 100%);
            color: var(--deep-navy);
        }

        .role-admin {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
            color: var(--white);
        }

        .role-staff {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: var(--white);
        }

        .role-agent {
            background: linear-gradient(135deg, var(--info) 0%, #026aa7 100%);
            color: var(--white);
        }

        /* Action buttons - compact */
        .action-group {
            display: flex;
            gap: 4px;
            justify-content: center;
            min-width: 90px;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #1b5e20 100%);
            border: none;
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #8b1e1e 100%);
            border: none;
            color: white;
        }

        /* Table container - full width scroll */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        /* Empty state */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-light);
            border-radius: 50%;
            border-top-color: var(--deep-navy);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Fix for number inputs */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            opacity: 0.3;
            height: 20px;
        }

        /* Make all cells fill available space */
        td:not(:last-child) {
            width: auto;
        }

        .staff-cell-input {
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 13px;
            width: 100%;
            min-width: 0;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            background: #fff;
        }

        .staff-cell-input:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(54, 97, 185, 0.18);
        }

        .staff-cell-input:read-only {
            background: #f1f5f9;
            color: var(--text-muted);
        }

        tbody tr[data-role] {
            transition: background 0.15s ease;
        }

        tbody tr[data-role]:hover {
            background: #f8fafc !important;
        }

        tbody tr[data-role] td {
            background: transparent;
        }

        .pending-board {
            margin: 0 0 18px;
            background: #fff;
            border: 1px solid #d9e6d4;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(47, 90, 38, 0.06);
        }
        .pending-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 16px 18px;
            background: #f4faf3;
            border-bottom: 1px solid #d9e6d4;
        }
        .pending-head h2 { margin: 0; font-size: 1.05rem; }
        .pending-head p { margin: 4px 0 0; color: var(--text-muted); font-size: .85rem; }
        .pending-count {
            min-width: 36px;
            height: 36px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: #427431;
            color: #fff;
            font-weight: 700;
        }
        .pending-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 14px;
        }
        @media (min-width: 800px) {
            .pending-grid { grid-template-columns: 1fr 1fr; }
        }
        .pending-card {
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 14px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .pending-card strong { font-size: 1rem; }
        .pending-meta { color: var(--text-muted); font-size: .84rem; line-height: 1.45; }
        .kind-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
            background: #eef6ea;
            color: #2f5a26;
        }
        .kind-pill.is-company { background: #e8f0fe; color: #3661B9; }
        .approve-btn {
            margin-top: 6px;
            border: 0;
            border-radius: 10px;
            background: #427431;
            color: #fff;
            font-weight: 700;
            padding: 10px 12px;
        }
        .approve-btn:hover { background: #356a2a; }
        .status-wait {
            display: inline-block;
            margin-left: 6px;
            border-radius: 999px;
            background: #fff7ed;
            color: #c2410c;
            font-size: .68rem;
            font-weight: 700;
            padding: 2px 7px;
            vertical-align: middle;
        }
        .account-list {
            flex: 1;
            min-height: 0;
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-bottom: 18px;
        }
        .account-fold {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        }
        .account-fold.is-inactive { background: #f8fafc; }
        .account-fold summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
        }
        .account-fold summary::-webkit-details-marker { display: none; }
        .fold-main { min-width: 0; flex: 1; }
        .fold-name { font-weight: 700; font-size: 1rem; }
        .fold-sub { color: var(--text-muted); font-size: .84rem; margin-top: 2px; }
        .fold-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .fold-tag {
            border-radius: 999px;
            background: #eef6ea;
            color: #2f5a26;
            font-size: .72rem;
            font-weight: 700;
            padding: 3px 8px;
        }
        .fold-tag.is-company { background: #e8f0fe; color: #1d4ed8; }
        .fold-tag.is-wait { background: #fff7ed; color: #c2410c; }
        .fold-tag.is-off { background: #f1f5f9; color: #64748b; }
        .fold-chevron { color: #94a3b8; transition: transform .15s ease; }
        .account-fold[open] .fold-chevron { transform: rotate(180deg); }
        .summary-actions { display: flex; gap: 8px; flex: 0 0 auto; }
        .deactivate-btn, .reactivate-btn {
            border: 0;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 700;
            font-size: .82rem;
        }
        .deactivate-btn { background: #fff1f2; color: #be123c; }
        .reactivate-btn { background: #ecfdf3; color: #166534; }
        .fold-body { padding: 0 16px 16px; }
        .company-panel {
            background: #f8fbff;
            border: 1px solid #dbe7ff;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }
        .company-panel h3 { margin: 0 0 10px; font-size: 1rem; color: #1e3a8a; }
        .fold-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        @media (min-width: 800px) {
            .fold-grid { grid-template-columns: 1fr 1fr; }
            .fold-grid .span-2 { grid-column: 1 / -1; }
        }
        .fold-grid label { font-size: .75rem; font-weight: 700; color: #475569; }
        .fold-grid input, .fold-grid select {
            margin-top: 4px;
            width: 100%;
            border: 1px solid #d5e0d8;
            border-radius: 10px;
            padding: 8px 10px;
            font: inherit;
            font-size: .92rem;
        }
        .fold-section { margin-top: 14px; }
        .fold-section h3 { margin: 0 0 8px; font-size: .95rem; color: #2f5a26; }
        .fold-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .account-fold summary { flex-wrap: wrap; }
        .account-fold summary::marker { content: ''; }
        .account-fold .profile-upload-wrap,
        .account-fold .profile-upload-wrap .profile-img-container,
        .account-fold .profile-upload-wrap .profile-placeholder {
            width: 48px;
            height: 48px;
            margin: 0;
        }
        .account-fold .profile-placeholder { font-size: 18px; }
        .fold-photo-btn {
            display: inline-flex;
            align-items: center;
            border-radius: 10px;
            background: #eef6ea;
            color: #2f5a26;
            font-weight: 700;
            font-size: .82rem;
            padding: 8px 12px;
            cursor: pointer;
        }
        .company-kicker {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #3661B9;
        }
        .company-facts {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px 16px;
            margin: 12px 0 0;
        }
        @media (min-width: 700px) {
            .company-facts { grid-template-columns: 1fr 1fr; }
            .company-facts .wide { grid-column: 1 / -1; }
        }
        .company-facts dt {
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .company-facts dd {
            margin: 2px 0 0;
            color: #0f172a;
            font-weight: 600;
            word-break: break-word;
        }
        .company-facts a { color: #1d4ed8; }
        .delete-btn {
            border: 0;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 700;
            font-size: .82rem;
            background: #fee2e2;
            color: #991b1b;
        }
        @media (max-width: 760px) {
            body { overflow: auto; }
            .brand-payroll-header { display: none; }
            .main-container {
                height: auto;
                min-height: 100%;
                padding: 12px 12px 24px;
            }
            .page-header, .stats-container, .summary-actions {
                flex-wrap: wrap;
                width: 100%;
            }
            .page-header { align-items: flex-start; gap: 10px; }
            .page-title { font-size: 1.25rem; }
            .search-wrapper { min-width: 0; flex: 1 1 100%; }
            .filters-section { gap: 8px; padding: 12px; }
            .filter-badge { padding: 6px 8px; }
            .account-fold summary { align-items: flex-start; }
            .fold-sub { overflow-wrap: anywhere; }
            .fold-actions .btn { flex: 1 1 auto; }
            .modal-dialog { margin: 8px; max-width: calc(100% - 16px); }
        }
    </style>
</head>

<body>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<!-- Company header -->
<div class="brand-payroll-header">
    <div class="header-container">
        <div class="logo-container">
            <div class="logo-main"><?= htmlspecialchars($companyBrandName) ?></div>
            <div class="logo-subtitle">Accounts management</div>
        </div>
    </div>
</div>

<div class="main-container">
    
    <!-- Page Header with Stats - Simplified -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="bi bi-people-fill"></i>
            Accounts management
        </h1>
        
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-people"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $admins->num_rows ?></h3>
                    <p>Total</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-star-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?= count($pendingAccounts) ?></h3>
                    <p>Waiting</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Alert Messages -->
    <?php if ($success): ?>
    <div class="alert alert-success" id="successAlert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" id="errorAlert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (is_array($resetCredentials)): ?>
    <?php
        $credentialText = "Login link: " . $resetCredentials['login'] . "\n"
            . "Username: " . $resetCredentials['username'] . "\n"
            . "Password: " . $resetCredentials['password'];
    ?>
    <div class="alert alert-warning" id="credentialBox">
        <strong>New login for <?= htmlspecialchars((string) $resetCredentials['name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <div style="margin-top:8px;font-size:14px;">Username: <code><?= htmlspecialchars((string) $resetCredentials['username'], ENT_QUOTES, 'UTF-8') ?></code></div>
        <div style="font-size:14px;">Password: <code><?= htmlspecialchars((string) $resetCredentials['password'], ENT_QUOTES, 'UTF-8') ?></code></div>
        <div style="font-size:14px;word-break:break-all;">Login link: <code><?= htmlspecialchars((string) $resetCredentials['login'], ENT_QUOTES, 'UTF-8') ?></code></div>
        <textarea id="credentialText" readonly style="position:absolute;left:-9999px;"><?= htmlspecialchars($credentialText, ENT_QUOTES, 'UTF-8') ?></textarea>
        <button type="button" class="btn btn-dark btn-sm" style="margin-top:10px;" onclick="copyStaffCredentials()">
            <i class="bi bi-clipboard"></i> Copy credentials
        </button>
    </div>
    <?php endif; ?>
    
    <!-- Waiting for approval -->
    <?php if ($isSuperAdmin): ?>
    <section class="pending-board">
        <div class="pending-head">
            <div>
                <h2>Waiting for approval</h2>
                <p>Approve personal agent/staff signups here. Company partners are approved under <strong>Companies Management</strong>.</p>
            </div>
            <div class="pending-count"><?= count($pendingAccounts) ?></div>
        </div>
        <?php if ($pendingAccounts === []): ?>
            <p class="pending-meta" style="padding:14px 18px;">No new accounts are waiting.</p>
        <?php else: ?>
        <div class="pending-grid">
            <?php foreach ($pendingAccounts as $pending):
                $pendingName = trim((string) ($pending['full_name'] ?? ''));
                if ($pendingName === '') {
                    $pendingName = trim((string) (($pending['first_name'] ?? '') . ' ' . ($pending['last_name'] ?? '')));
                }
                $isCompanyAccount = strtolower((string) ($pending['account_type'] ?? '')) === 'company'
                    || trim((string) ($pending['company_name'] ?? '')) !== '';
            ?>
            <article class="pending-card">
                <div>
                    <span class="kind-pill<?= $isCompanyAccount ? ' is-company' : '' ?>"><?= $isCompanyAccount ? 'Company' : 'Personal' ?></span>
                </div>
                <strong><?= htmlspecialchars($pendingName !== '' ? $pendingName : 'New account', ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="pending-meta">
                    <?= htmlspecialchars((string) ($pending['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    <?php if (trim((string) ($pending['phone_number'] ?? '')) !== ''): ?>
                        <br><?= htmlspecialchars((string) $pending['phone_number'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if ($isCompanyAccount): ?>
                        <br><strong><?= htmlspecialchars((string) ($pending['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (trim((string) ($pending['company_registration'] ?? '')) !== ''): ?>
                            · <?= htmlspecialchars((string) $pending['company_registration'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                        <?php if (trim((string) ($pending['company_address'] ?? '')) !== ''): ?>
                            <br><?= htmlspecialchars((string) $pending['company_address'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['staff_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="approve_account" value="1">
                    <input type="hidden" name="id" value="<?= (int) $pending['id'] ?>">
                    <button type="submit" class="approve-btn">Approve and email sign-in</button>
                </form>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Search and Filters - Compact -->
    <div class="filters-section">
        <div class="search-wrapper">
            <i class="bi bi-search"></i>
            <input type="text" id="searchBox" class="form-control" placeholder="Search...">
        </div>
        
        <div class="filter-badge active" onclick="filterByRole('all', this)">
            <i class="bi bi-people"></i> All
        </div>
        <div class="filter-badge" onclick="filterByRole('superadmin', this)">
            <i class="bi bi-star-fill"></i> Super
        </div>
        <div class="filter-badge" onclick="filterByRole('director', this)">
            <i class="bi bi-star"></i> Director
        </div>
        <div class="filter-badge" onclick="filterByRole('secretary', this)">
            <i class="bi bi-star"></i> Secretary
        </div>
        <div class="filter-badge" onclick="filterByRole('admin', this)">
            <i class="bi bi-shield"></i> Admin
        </div>
        <div class="filter-badge" onclick="filterByRole('staff', this)">
            <i class="bi bi-person-badge"></i> Staff
        </div>
        <div class="filter-badge" onclick="filterByRole('accountant', this)">
            <i class="bi bi-calculator"></i> Accountant
        </div>
        <div class="filter-badge" onclick="filterByRole('pending', this)">
            <i class="bi bi-hourglass-split"></i> Waiting
        </div>
        <div class="filter-badge" onclick="filterByRole('inactive', this)">
            <i class="bi bi-person-x"></i> Inactive
        </div>
        <div class="filter-badge" onclick="filterByRole('agent', this)">
            <i class="bi bi-person-workspace"></i> Agent
        </div>

        <?php if ($isSuperAdmin): ?>
        <a href="companies-management.php" class="btn-add-staff" style="text-decoration:none;">
            <i class="bi bi-building"></i> Companies
        </a>
        <button type="button" class="btn-add-staff" data-bs-toggle="modal" data-bs-target="#addStaffModal">
            <i class="bi bi-person-plus-fill"></i> Add Staff
        </button>
        <?php endif; ?>
    </div>
    
    <div class="account-list" id="accountList">
        <?php
        $admins->data_seek(0);
        $foldCount = 0;
        while ($row = $admins->fetch_assoc()):
            $foldCount++;
            $isOwnProfile = ((int) $row['id'] === (int) $currentUserId);
            $displayName = trim((string) ($row['full_name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            }
            if ($displayName === '') {
                $displayName = (string) ($row['username'] ?? '');
            }
            $accountStatus = strtolower(trim((string) ($row['account_status'] ?? 'active')));
            if ($accountStatus === '') {
                $accountStatus = 'active';
            }
            $isCompanyAccount = strtolower((string) ($row['account_type'] ?? '')) === 'company'
                || trim((string) ($row['company_name'] ?? '')) !== '';
            $companyTitle = trim((string) ($row['company_name'] ?? ''));
            $searchBlob = implode(' ', array_filter([
                $displayName,
                $row['first_name'] ?? '',
                $row['last_name'] ?? '',
                $row['email'] ?? '',
                $row['phone_number'] ?? '',
                $row['username'] ?? '',
                $row['role'] ?? '',
                $row['position'] ?? '',
                $companyTitle,
                $row['company_registration'] ?? '',
                $row['company_address'] ?? '',
                $row['company_phone'] ?? '',
                $row['company_website_url'] ?? '',
                $row['nationality'] ?? '',
                $row['address'] ?? '',
            ], static fn($v) => trim((string) $v) !== ''));
            $esc = static function ($value): string {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            };
            $ro = $isSuperAdmin ? '' : 'readonly';
            $dis = $isSuperAdmin ? '' : 'disabled';
            $dateField = static function ($value): string {
                $value = trim((string) $value);
                if ($value === '' || strncmp($value, '0000', 4) === 0) {
                    return '';
                }
                return substr($value, 0, 10);
            };
        ?>
        <details class="account-fold<?= $accountStatus === 'inactive' ? ' is-inactive' : '' ?>"
                 data-role="<?= $esc($row['role'] ?? 'staff') ?>"
                 data-status="<?= $esc($accountStatus) ?>"
                 data-kind="<?= $isCompanyAccount ? 'company' : 'personal' ?>"
                 data-search="<?= $esc($searchBlob) ?>">
            <summary>
                <div class="profile-upload-wrap" data-staff-id="<?= (int) $row['id'] ?>">
                    <?php if (!empty($row['profile_photo'])): ?>
                    <div class="profile-img-container">
                        <img src="<?= $esc(pcvc_profile_photo_url($row['profile_photo'] ?? '')) ?>" alt="" class="profile-img profile-preview">
                    </div>
                    <?php else: ?>
                    <div class="profile-placeholder profile-preview"><?= $esc(strtoupper(substr($displayName !== '' ? $displayName : 'U', 0, 1))) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fold-main">
                    <div class="fold-name"><?= $esc($isCompanyAccount && $companyTitle !== '' ? $companyTitle : $displayName) ?></div>
                    <div class="fold-sub">
                        <?= $esc($displayName) ?>
                        <?php if (trim((string) ($row['email'] ?? '')) !== ''): ?> · <?= $esc($row['email']) ?><?php endif; ?>
                        <?php if (trim((string) ($row['phone_number'] ?? '')) !== ''): ?> · <?= $esc($row['phone_number']) ?><?php endif; ?>
                    </div>
                    <div class="fold-tags">
                        <span class="fold-tag<?= $isCompanyAccount ? ' is-company' : '' ?>"><?= $isCompanyAccount ? 'Company' : 'Personal' ?></span>
                        <span class="fold-tag"><?= $esc(ucfirst((string) ($row['role'] ?? 'staff'))) ?></span>
                        <?php if ($accountStatus === 'pending'): ?><span class="fold-tag is-wait">Waiting</span><?php endif; ?>
                        <?php if ($accountStatus === 'inactive'): ?><span class="fold-tag is-off">Inactive</span><?php endif; ?>
                    </div>
                </div>
                <span class="summary-actions" onclick="event.stopPropagation();">
                    <?php if ($isSuperAdmin && !$isOwnProfile && $accountStatus !== 'inactive'): ?>
                    <button type="submit" class="deactivate-btn" form="deact-<?= (int) $row['id'] ?>" onclick="return confirm('Deactivate this account? They will not be able to sign in.');">Deactivate</button>
                    <?php elseif ($isSuperAdmin && $accountStatus === 'inactive'): ?>
                    <button type="submit" class="reactivate-btn" form="react-<?= (int) $row['id'] ?>">Reactivate</button>
                    <?php endif; ?>
                    <?php if ($isSuperAdmin && !$isOwnProfile): ?>
                    <button type="submit" class="delete-btn" form="del-<?= (int) $row['id'] ?>" onclick="return confirm('Delete this account permanently?');">Delete</button>
                    <?php endif; ?>
                </span>
                <i class="bi bi-chevron-down fold-chevron"></i>
            </summary>
            <div class="fold-body">
                <?php if ($isCompanyAccount): ?>
                <section class="company-panel">
                    <div class="company-kicker">Company</div>
                    <h3><?= $esc($companyTitle !== '' ? $companyTitle : 'Company account') ?></h3>
                    <?php
                    $companyWeb = trim((string) ($row['company_website_url'] ?? ''));
                    $companyWebHref = $companyWeb;
                    if ($companyWebHref !== '' && !preg_match('#^https?://#i', $companyWebHref)) {
                        $companyWebHref = 'https://' . $companyWebHref;
                    }
                    $fact = static function (string $value) use ($esc): string {
                        $value = trim($value);
                        return $value === '' ? '<span class="fold-sub">Not provided</span>' : $esc($value);
                    };
                    ?>
                    <dl class="company-facts">
                        <div>
                            <dt>Registration</dt>
                            <dd><?= $fact((string) ($row['company_registration'] ?? '')) ?></dd>
                        </div>
                        <div>
                            <dt>Company phone</dt>
                            <dd><?= $fact((string) ($row['company_phone'] ?? '')) ?></dd>
                        </div>
                        <div class="wide">
                            <dt>Address</dt>
                            <dd><?= $fact((string) ($row['company_address'] ?? '')) ?></dd>
                        </div>
                        <div class="wide">
                            <dt>Website</dt>
                            <dd><?php if ($companyWeb === ''): ?><span class="fold-sub">Not provided</span><?php else: ?><a href="<?= $esc($companyWebHref) ?>" target="_blank" rel="noopener"><?= $esc($companyWeb) ?></a><?php endif; ?></dd>
                        </div>
                        <div>
                            <dt>Person who signs in</dt>
                            <dd><?= $esc($displayName) ?></dd>
                        </div>
                        <div>
                            <dt>Sign-in email</dt>
                            <dd><?= $fact((string) ($row['email'] ?? '')) ?></dd>
                        </div>
                        <div>
                            <dt>Personal phone</dt>
                            <dd><?= $fact((string) ($row['phone_number'] ?? '')) ?></dd>
                        </div>
                        <div>
                            <dt>Username</dt>
                            <dd><?= $fact((string) ($row['username'] ?? '')) ?></dd>
                        </div>
                    </dl>
                </section>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="staff-form" data-id="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="account_type" value="<?= $isCompanyAccount ? 'company' : 'personal' ?>">

                    <?php if ($isCompanyAccount): ?>
                    <div class="fold-section">
                        <h3>Company details</h3>
                        <div class="fold-grid">
                            <label class="span-2">Company name<input name="company_name" value="<?= $esc($row['company_name'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Registration number<input name="company_registration" value="<?= $esc($row['company_registration'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Company phone<input name="company_phone" value="<?= $esc($row['company_phone'] ?? '') ?>" <?= $ro ?>></label>
                            <label class="span-2">Company address<input name="company_address" value="<?= $esc($row['company_address'] ?? '') ?>" <?= $ro ?>></label>
                            <label class="span-2">Website<input name="company_website_url" value="<?= $esc($row['company_website_url'] ?? '') ?>" <?= $ro ?>></label>
                        </div>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="company_name" value="">
                    <input type="hidden" name="company_registration" value="">
                    <input type="hidden" name="company_phone" value="">
                    <input type="hidden" name="company_address" value="">
                    <input type="hidden" name="company_website_url" value="">
                    <?php endif; ?>

                    <div class="fold-section">
                        <h3><?= $isCompanyAccount ? 'Person who signs in' : 'Account' ?></h3>
                        <div class="fold-grid">
                            <label>Full name<input name="full_name" required value="<?= $esc($displayName) ?>" <?= $ro ?>></label>
                            <label>Email<input type="email" name="email" required value="<?= $esc($row['email'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Phone<input name="phone_number" value="<?= $esc($row['phone_number'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Username<input name="username" required value="<?= $esc($row['username'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Role
                                <select name="role" <?= $dis ?>>
                                    <?php foreach (pcvc_admin_role_options() as $roleValue => $roleLabel): ?>
                                    <option value="<?= $esc($roleValue) ?>" <?= ($row['role'] ?? '') === $roleValue ? 'selected' : '' ?>><?= $esc($roleLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Position<input name="position" value="<?= $esc($row['position'] ?? '') ?>" <?= $ro ?>></label>
                        </div>
                    </div>

                    <div class="fold-section">
                        <h3>Work and personal</h3>
                        <div class="fold-grid">
                            <label>Employment
                                <select name="employment_type" <?= $dis ?>>
                                    <option value="">--</option>
                                    <?php
                                    $employmentCurrent = (string) ($row['employment_type'] ?? '');
                                    $employmentSelected = pcvc_staff_employment_type_normalize($employmentCurrent);
                                    if ($employmentSelected === '') {
                                        $employmentSelected = $employmentCurrent;
                                    }
                                    foreach (pcvc_staff_employment_type_choices($employmentCurrent) as $emp):
                                    ?>
                                    <option value="<?= $esc($emp) ?>" <?= $employmentSelected === $emp ? 'selected' : '' ?>><?= $esc($emp) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Start date<input type="date" name="employment_start_date" value="<?= $esc($dateField($row['employment_start_date'] ?? '')) ?>" <?= $ro ?>></label>
                            <label>Date of birth<input type="date" name="date_of_birth" value="<?= $esc($dateField($row['date_of_birth'] ?? '')) ?>" <?= $ro ?>></label>
                            <label>Marital status
                                <select name="marital_status" <?= $dis ?>>
                                    <option value="">--</option>
                                    <?php foreach (['Single', 'Married', 'Divorced', 'Widowed'] as $maritalOpt): ?>
                                    <option value="<?= $esc($maritalOpt) ?>" <?= ($row['marital_status'] ?? '') === $maritalOpt ? 'selected' : '' ?>><?= $esc($maritalOpt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Nationality<input name="nationality" value="<?= $esc($row['nationality'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Place of birth<input name="place_of_birth" value="<?= $esc($row['place_of_birth'] ?? '') ?>" <?= $ro ?>></label>
                            <label>National ID<input name="national_id" value="<?= $esc($row['national_id'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Address<input name="address" value="<?= $esc($row['address'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Office
                                <select name="office_id" <?= $dis ?>>
                                    <option value="">--</option>
                                    <?php if ($offices): $offices->data_seek(0); while ($office = $offices->fetch_assoc()): ?>
                                    <option value="<?= (int) $office['id'] ?>" <?= (string) ($row['office_id'] ?? '') === (string) $office['id'] ? 'selected' : '' ?>><?= $esc($office['office_name'] ?? '') ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </label>
                        </div>
                    </div>

                    <div class="fold-section">
                        <h3>Pay</h3>
                        <div class="fold-grid">
                            <label>Salary per minute<input type="number" step="0.01" name="salary_per_minute" value="<?= $esc($row['salary_per_minute'] ?? 8.33) ?>" <?= $ro ?>></label>
                            <label>Monthly salary<input type="number" step="0.01" name="monthly_salary" value="<?= $esc($row['monthly_salary'] ?? '') ?>" <?= $ro ?>></label>
                            <label>Currency
                                <select name="salary_currency" <?= $dis ?>>
                                    <?php
                                    $payCurrency = strtoupper(trim((string) ($row['salary_currency'] ?? '')));
                                    if (!in_array($payCurrency, ['RWF', 'KES', 'USD'], true)) {
                                        $payCurrency = 'RWF';
                                    }
                                    foreach (['RWF' => 'RWF — Rwandan francs', 'KES' => 'KES', 'USD' => 'USD'] as $cur => $curLabel):
                                    ?>
                                    <option value="<?= $cur ?>" <?= $payCurrency === $cur ? 'selected' : '' ?>><?= $esc($curLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Break minutes<input type="number" name="allowed_break_minutes" value="<?= $esc($row['allowed_break_minutes'] ?? 60) ?>" <?= $ro ?>></label>
                            <label>Work days / week<input type="number" name="work_days_per_week" value="<?= $esc($row['work_days_per_week'] ?? 6) ?>" <?= $ro ?>></label>
                            <label>Sheet ID<input name="sheet_id" value="<?= $esc($row['sheet_id'] ?? '') ?>" <?= $ro ?>></label>
                            <label class="span-2">Sheet link<input name="sheet_link" value="<?= $esc($row['sheet_link'] ?? '') ?>" <?= $ro ?>></label>
                        </div>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                    <div class="fold-actions">
                        <label class="fold-photo-btn" title="Change photo">
                            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" class="profile-photo-input" data-staff-id="<?= (int) $row['id'] ?>" hidden>
                            Change photo
                        </label>
                        <button type="submit" name="update_staff" class="btn btn-success btn-sm" onclick="return confirmUpdate()">Save</button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="openResetPassword(<?= (int) $row['id'] ?>, <?= htmlspecialchars(json_encode((string) ($row['username'] ?? '')), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($displayName), ENT_QUOTES, 'UTF-8') ?>)">Reset password</button>
                        <?php if (!$isOwnProfile): ?>
                        <button type="submit" class="delete-btn" form="del-<?= (int) $row['id'] ?>" onclick="return confirm('Delete this account permanently?');">Delete</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>

                <?php if ($isSuperAdmin && !$isOwnProfile): ?>
                <form method="post" id="del-<?= (int) $row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['staff_csrf']) ?>">
                    <input type="hidden" name="delete_account" value="1">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                </form>
                <?php endif; ?>
                <?php if ($isSuperAdmin && !$isOwnProfile && $accountStatus !== 'inactive'): ?>
                <form method="post" id="deact-<?= (int) $row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['staff_csrf']) ?>">
                    <input type="hidden" name="deactivate_account" value="1">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                </form>
                <?php elseif ($isSuperAdmin && $accountStatus === 'inactive'): ?>
                <form method="post" id="react-<?= (int) $row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['staff_csrf']) ?>">
                    <input type="hidden" name="reactivate_account" value="1">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                </form>
                <?php endif; ?>
            </div>
        </details>
        <?php endwhile; ?>
        <?php if ($foldCount === 0): ?>
        <div class="empty-state"><div class="empty-title">No accounts found</div></div>
        <?php endif; ?>
    </div>
    <div class="empty-state" id="filterEmpty" style="display:none"><div class="empty-title">No accounts match this view</div></div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- Add Staff Modal — all fields optional -->
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="addStaffForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addStaffModalLabel">
                        <i class="bi bi-person-plus-fill me-2"></i>Add Staff Member
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="optional-hint mb-3">
                        All fields are optional. If username or password is left blank, a username and temporary password
                        (<code>Parrot@ChangeMe2026</code> will be created automatically.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Full name</label>
                            <input type="text" name="full_name" class="form-control" placeholder="Full name" autocomplete="name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="email@…" autocomplete="email">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone_number" class="form-control" placeholder="Phone / MoMo" inputmode="tel" autocomplete="tel">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Auto if blank" autocomplete="username">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <input type="text" name="password" class="form-control" placeholder="Temp password if blank" autocomplete="new-password">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <?php foreach (pcvc_admin_role_options() as $roleValue => $roleLabel): ?>
                                <option value="<?= htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8') ?>" <?= $roleValue === 'staff' ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Profile photo</label>
                            <input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control" placeholder="Position / title">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employment type</label>
                            <select name="employment_type" class="form-select">
                                <option value="">--</option>
                                <?php foreach (pcvc_staff_employment_type_options() as $emp): ?>
                                <option value="<?= $esc($emp) ?>"><?= $esc($emp) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start date</label>
                            <input type="date" name="employment_start_date" class="form-control">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Date of birth</label>
                            <input type="date" name="date_of_birth" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Marital status</label>
                            <select name="marital_status" class="form-select">
                                <option value="">--</option>
                                <option>Single</option>
                                <option>Married</option>
                                <option>Divorced</option>
                                <option>Widowed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Nationality</label>
                            <input type="text" name="nationality" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Place of birth</label>
                            <input type="text" name="place_of_birth" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" class="form-control">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Office</label>
                            <select name="office_id" class="form-select">
                                <option value="">--</option>
                                <?php
                                if ($offices) {
                                    $offices->data_seek(0);
                                    while ($o = $offices->fetch_assoc()):
                                ?>
                                <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['office_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php
                                    endwhile;
                                    $offices->data_seek(0);
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Salary / min</label>
                            <input type="number" step="0.01" name="salary_per_minute" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Monthly</label>
                            <input type="number" step="0.01" name="monthly_salary" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Currency</label>
                            <select name="salary_currency" class="form-select">
                                <option value="RWF" selected>RWF — Rwandan francs</option>
                                <option value="KES">KES</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Break</label>
                            <input type="number" name="allowed_break_minutes" class="form-control" placeholder="60">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Days</label>
                            <input type="number" name="work_days_per_week" class="form-control" placeholder="5">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Sheet ID</label>
                            <input type="text" name="sheet_id" class="form-control">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Sheet link</label>
                            <input type="text" name="sheet_link" class="form-control" placeholder="https://…">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_staff" value="1" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Save staff
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['staff_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="resetStaffId" value="">
                <p class="mb-3">Reset the password for <strong id="resetStaffName"></strong>.</p>
                <label class="form-label" for="resetStaffUsername">Current username</label>
                <input type="text" id="resetStaffUsername" class="form-control mb-3" readonly>
                <label class="form-label" for="resetStaffPassword">New password</label>
                <div class="input-group">
                    <input type="text" name="new_password" id="resetStaffPassword" class="form-control" minlength="8" required autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('resetStaffPassword').value = suggestStaffPassword()">New password</button>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size:13px;">The new password is saved hashed. It is shown once, in plain text, so you can copy it.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="reset_staff_password" class="btn btn-warning">Reset password</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function() {
    // Auto-hide alerts after 3 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 3000);
    
    // Show loading on form submit
    $('.staff-form').on('submit', function() {
        $('#loadingOverlay').fadeIn();
        return true;
    });

    // Instant profile photo upload (superadmin)
    $(document).on('change', '.profile-photo-input', function() {
        const input = this;
        const file = input.files && input.files[0];
        if (!file) return;

        const staffId = input.dataset.staffId;
        const wrap = input.closest('.profile-upload-wrap') || document.querySelector('.profile-upload-wrap[data-staff-id="' + staffId + '"]');
        const preview = wrap ? wrap.querySelector('.profile-preview') : null;

        const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) {
            alert('Please choose a JPG, PNG, GIF, or WebP image.');
            input.value = '';
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            alert('Image must be 2MB or smaller.');
            input.value = '';
            return;
        }

        const fd = new FormData();
        fd.append('staff_id', staffId);
        fd.append('profile_photo', file);

        if (wrap) wrap.classList.add('is-uploading');

        fetch('api/staff-profile-photo.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                alert(data.error || 'Upload failed.');
                return;
            }
            const photoSrc = data.photo_url + (data.photo_url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
            if (preview && preview.tagName === 'IMG') {
                preview.src = photoSrc;
            } else if (preview && wrap) {
                const img = document.createElement('img');
                img.src = photoSrc;
                img.alt = 'Profile';
                img.className = 'profile-img profile-preview';
                preview.replaceWith(img);
            }
        })
        .catch(function() {
            alert('Upload failed. Please try again.');
        })
        .finally(function() {
            if (wrap) wrap.classList.remove('is-uploading');
            input.value = '';
        });
    });
});

// Smart live search + role filter (combined)
let currentRoleFilter = 'all';
let smartSearchTimer = null;

function pcvcNormalizeSearchStr(str) {
    if (!str) return '';
    try {
        return str
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '');
    } catch (e) {
        return str.toLowerCase();
    }
}

function applyStaffFilters() {
    const query = (document.getElementById('searchBox')?.value || '').trim();
    const norm = pcvcNormalizeSearchStr(query);
    const tokens = norm.split(/[^a-z0-9]+/i).filter(function(t) { return t.length > 0; });
    const rows = document.querySelectorAll('.account-fold');

    rows.forEach(function(row) {
        const rowRole = row.getAttribute('data-role') || '';
        const rowStatus = row.getAttribute('data-status') || 'active';
        const rowKind = row.getAttribute('data-kind') || 'personal';
        const roleOk = currentRoleFilter === 'all'
            || (currentRoleFilter === 'pending' ? rowStatus === 'pending'
            : currentRoleFilter === 'inactive' ? rowStatus === 'inactive'
            : currentRoleFilter === 'company' ? rowKind === 'company'
            : rowRole === currentRoleFilter);

        let searchOk = true;
        if (tokens.length > 0) {
            let hay = row.getAttribute('data-search') || '';
            if (!hay) {
                hay = pcvcNormalizeSearchStr(row.textContent || '');
            } else {
                hay = pcvcNormalizeSearchStr(hay);
            }
            searchOk = tokens.every(function(t) { return hay.indexOf(t) !== -1; });
        }

        row.style.display = (roleOk && searchOk) ? '' : 'none';
    });
    const empty = document.getElementById('filterEmpty');
    if (empty) {
        const any = Array.prototype.some.call(rows, function(row) { return row.style.display !== 'none'; });
        empty.style.display = any ? 'none' : '';
    }
}

const searchBox = document.getElementById('searchBox');
if (searchBox) {
    searchBox.addEventListener('keyup', function() {
        clearTimeout(smartSearchTimer);
        smartSearchTimer = setTimeout(applyStaffFilters, 120);
    });
    searchBox.addEventListener('input', function() {
        clearTimeout(smartSearchTimer);
        smartSearchTimer = setTimeout(applyStaffFilters, 120);
    });
}

// Filter by role
function filterByRole(role, el) {
    currentRoleFilter = role;
    document.querySelectorAll('.filter-badge').forEach(function(badge) {
        badge.classList.remove('active');
    });
    if (el) {
        el.classList.add('active');
    }
    applyStaffFilters();
}

applyStaffFilters();

document.querySelectorAll('.account-fold').forEach(function(fold) {
    fold.addEventListener('toggle', function() {
        if (!fold.open) return;
        document.querySelectorAll('.account-fold[open]').forEach(function(other) {
            if (other !== fold) other.removeAttribute('open');
        });
    });
});

// Sort table by column
function sortTable(columnIndex) {
    let table = document.getElementById('staffTable');
    let tbody = table.querySelector('tbody');
    let rows = Array.from(tbody.querySelectorAll('tr.staff-data-row'));
    
    // Toggle sort order
    if (!table.sortDirection) {
        table.sortDirection = {};
    }
    table.sortDirection[columnIndex] = !table.sortDirection[columnIndex];
    
    rows.sort((a, b) => {
        let aText = a.cells[columnIndex].textContent.trim().toLowerCase();
        let bText = b.cells[columnIndex].textContent.trim().toLowerCase();
        
        if (table.sortDirection[columnIndex]) {
            return aText.localeCompare(bText);
        } else {
            return bText.localeCompare(aText);
        }
    });
    
    // Reorder rows
    rows.forEach(row => tbody.appendChild(row));
    applyStaffFilters();
}

// Confirm delete
function confirmDelete() {
    return confirm('⚠️ Delete this staff member?');
}

// Confirm update
function confirmUpdate() {
    return confirm('Save changes?');
}

function suggestStaffPassword() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    let password = 'Parrot-';
    for (let i = 0; i < 8; i++) {
        password += alphabet[Math.floor(Math.random() * alphabet.length)];
    }
    return password;
}

function openResetPassword(id, username, name) {
    document.getElementById('resetStaffId').value = id;
    document.getElementById('resetStaffName').textContent = name || username;
    document.getElementById('resetStaffUsername').value = username;
    document.getElementById('resetStaffPassword').value = suggestStaffPassword();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resetPasswordModal')).show();
}

function copyStaffCredentials() {
    const box = document.getElementById('credentialText');
    const text = box ? box.value : '';
    const done = function () {
        const button = document.querySelector('#credentialBox button');
        if (!button) return;
        const original = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check"></i> Copied';
        setTimeout(function () { button.innerHTML = original; }, 1600);
    };
    if (navigator.clipboard && text) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
            box.focus();
            box.select();
            document.execCommand('copy');
            done();
        });
        return;
    }
    if (box) {
        box.focus();
        box.select();
        document.execCommand('copy');
        done();
    }
}

// Keyboard shortcuts
$(document).on('keydown', function(e) {
    // Ctrl+F - Focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.getElementById('searchBox').focus();
    }
});
</script>

</body>
</html>
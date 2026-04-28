<?php
declare(strict_types=1);

require_once __DIR__ . '/student_portal_schema.php';

/**
 * Default password requested by USER for auto-created accounts.
 * You can later move this to .env if you prefer.
 */
const PCVC_STUDENT_DEFAULT_PASSWORD = 'Parrot@2026';

function pcvc_student_email_norm(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Ensure a portal account exists for a student application (based on application email).
 * - Creates account if missing (default password).
 * - If account exists, links it to the latest application id.
 */
function pcvc_student_portal_ensure_account_for_application(mysqli $conn, int $applicationId, string $defaultPassword = PCVC_STUDENT_DEFAULT_PASSWORD): void
{
    if ($applicationId <= 0) {
        return;
    }
    pcvc_student_portal_ensure_schema($conn);

    $stmt = $conn->prepare("SELECT id, email FROM student_applications WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $email = isset($row['email']) ? pcvc_student_email_norm((string) $row['email']) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $stmt2 = $conn->prepare("SELECT id, student_application_id FROM student_portal_accounts WHERE email = ? LIMIT 1");
    if (!$stmt2) {
        return;
    }
    $stmt2->bind_param('s', $email);
    $stmt2->execute();
    $acc = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if ($acc) {
        $aid = (int) $acc['id'];
        $stmtU = $conn->prepare("UPDATE student_portal_accounts SET student_application_id = ? WHERE id = ?");
        if ($stmtU) {
            $stmtU->bind_param('ii', $applicationId, $aid);
            $stmtU->execute();
            $stmtU->close();
        }
        return;
    }

    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $stmtI = $conn->prepare("INSERT INTO student_portal_accounts (student_application_id, email, password_hash) VALUES (?, ?, ?)");
    if (!$stmtI) {
        return;
    }
    $stmtI->bind_param('iss', $applicationId, $email, $hash);
    $stmtI->execute();
    $stmtI->close();
}

/**
 * Ensure a portal account exists for an email (may or may not have a student_applications record yet).
 * - If student_applications exists for the email, link to latest application id.
 * - Otherwise, student_application_id remains NULL.
 */
function pcvc_student_portal_ensure_account_for_email(mysqli $conn, string $email, string $defaultPassword = PCVC_STUDENT_DEFAULT_PASSWORD): void
{
    pcvc_student_portal_ensure_schema($conn);

    $email = pcvc_student_email_norm($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $applicationId = null;
    $st = $conn->prepare("
        SELECT id
        FROM student_applications
        WHERE LOWER(TRIM(email)) = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($st) {
        $st->bind_param('s', $email);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) $applicationId = (int)$r['id'];
    }

    $stmt2 = $conn->prepare("SELECT id FROM student_portal_accounts WHERE email = ? LIMIT 1");
    $existingId = 0;
    if ($stmt2) {
        $stmt2->bind_param('s', $email);
        $stmt2->execute();
        $acc = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        if ($acc) $existingId = (int)$acc['id'];
    }

    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    if ($existingId > 0) {
        if ($applicationId !== null) {
            $stU = $conn->prepare("UPDATE student_portal_accounts SET student_application_id = ?, password_hash = ?, status='active' WHERE id = ?");
            if ($stU) {
                $aid = $existingId;
                $app = (int)$applicationId;
                $stU->bind_param('isi', $app, $hash, $aid);
                $stU->execute();
                $stU->close();
            }
        } else {
            $stU = $conn->prepare("UPDATE student_portal_accounts SET password_hash = ?, status='active' WHERE id = ?");
            if ($stU) {
                $aid = $existingId;
                $stU->bind_param('si', $hash, $aid);
                $stU->execute();
                $stU->close();
            }
        }
        return;
    }

    if ($applicationId !== null) {
        $app = (int)$applicationId;
        $stmtI = $conn->prepare("INSERT INTO student_portal_accounts (student_application_id, email, password_hash) VALUES (?, ?, ?)");
        if ($stmtI) {
            $stmtI->bind_param('iss', $app, $email, $hash);
            $stmtI->execute();
            $stmtI->close();
        }
        return;
    }

    $stmtI = $conn->prepare("INSERT INTO student_portal_accounts (student_application_id, email, password_hash) VALUES (NULL, ?, ?)");
    if ($stmtI) {
        $stmtI->bind_param('ss', $email, $hash);
        $stmtI->execute();
        $stmtI->close();
    }
}

/**
 * Backfill accounts for existing students. Creates one account per distinct email (latest application wins).
 * Returns counts: [created, linked, skipped_invalid]
 */
function pcvc_student_portal_backfill_accounts(mysqli $conn, string $defaultPassword = PCVC_STUDENT_DEFAULT_PASSWORD): array
{
    pcvc_student_portal_ensure_schema($conn);

    $created = 0;
    $linked = 0;
    $skipped = 0;

    // Emails from all relevant sources (latest student_applications wins for linking).
    $sql = "
        SELECT DISTINCT LOWER(TRIM(email)) AS email_norm
        FROM student_applications
        WHERE email IS NOT NULL AND TRIM(email) <> ''
        UNION
        SELECT DISTINCT LOWER(TRIM(email)) AS email_norm
        FROM credit_transfer_applications
        WHERE email IS NOT NULL AND TRIM(email) <> ''
        UNION
        SELECT DISTINCT LOWER(TRIM(email)) AS email_norm
        FROM master_loan_applications
        WHERE email IS NOT NULL AND TRIM(email) <> ''
    ";
    $res = $conn->query($sql);
    if (!$res) {
        return [$created, $linked, $skipped];
    }

    while ($r = $res->fetch_assoc()) {
        $email = pcvc_student_email_norm((string) ($r['email_norm'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }

        // Ensure account exists; link if student_applications exists.
        $before = $conn->prepare("SELECT id FROM student_portal_accounts WHERE email = ? LIMIT 1");
        $had = false;
        if ($before) {
            $before->bind_param('s', $email);
            $before->execute();
            $had = (bool)$before->get_result()->fetch_assoc();
            $before->close();
        }

        pcvc_student_portal_ensure_account_for_email($conn, $email, $defaultPassword);

        if ($had) $linked++;
        else $created++;
    }
    $res->free();

    return [$created, $linked, $skipped];
}


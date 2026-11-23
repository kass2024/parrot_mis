<?php
declare(strict_types=1);

/**
 * Private Francophonie Mobility manager portal.
 * Shareable link → username/password → Smart Add, View list, Overall report.
 */

require_once __DIR__ . '/env_bootstrap.php';

/** Default portal credentials (seeded once as a password hash). */
function fm_manager_portal_default_username(): string
{
    return 'Fmobility';
}

function fm_manager_portal_default_password(): string
{
    return 'mobility@2026';
}

/**
 * Scripts a logged-in portal manager may open.
 *
 * @return list<string>
 */
function fm_manager_portal_allowed_scripts(): array
{
    return [
        'fm-manager.php',
        'francophonie-mobility-applications.php',
        'francophonie-mobility-staff-add.php',
        'francophonie-mobility-client-tracking.php',
        'francophonie-mobility-payment-records.php',
        'francophonie_mobility_application_details.php',
        'francophonie_mobility_admin_action.php',
        'update_francophonie_mobility_status.php',
        'send_francophonie_mobility_email.php',
        'download.php',
    ];
}

function fm_manager_portal_current_script(): string
{
    return basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
}

function fm_manager_portal_script_allowed(?string $script = null): bool
{
    $script = $script ?? fm_manager_portal_current_script();
    return in_array($script, fm_manager_portal_allowed_scripts(), true);
}

function fm_ensure_manager_portal_schema(mysqli $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $sql = "CREATE TABLE IF NOT EXISTS `francophonie_mobility_manager_portal` (
      `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
      `access_token` varchar(64) NOT NULL,
      `username` varchar(64) NOT NULL,
      `password_hash` varchar(255) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('fm_ensure_manager_portal_schema: ' . $conn->error);
        return;
    }

    $row = $conn->query('SELECT id FROM francophonie_mobility_manager_portal WHERE id = 1 LIMIT 1');
    if ($row && $row->num_rows === 0) {
        $token = bin2hex(random_bytes(24));
        $user = fm_manager_portal_default_username();
        $hash = password_hash(fm_manager_portal_default_password(), PASSWORD_DEFAULT);
        $st = $conn->prepare(
            'INSERT INTO francophonie_mobility_manager_portal (id, access_token, username, password_hash) VALUES (1, ?, ?, ?)'
        );
        if ($st) {
            $st->bind_param('sss', $token, $user, $hash);
            $st->execute();
            $st->close();
        }
    }
    if ($row) {
        $row->free();
    }
}

/**
 * @return array{access_token:string,username:string,password_hash:string}|null
 */
function fm_manager_portal_settings(mysqli $conn): ?array
{
    fm_ensure_manager_portal_schema($conn);
    $res = $conn->query(
        'SELECT access_token, username, password_hash FROM francophonie_mobility_manager_portal WHERE id = 1 LIMIT 1'
    );
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: null;
}

function fm_manager_portal_public_base_url(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com'));
    if (!in_array($host, ['mis.visaconsultantcanada.com', 'localhost', '127.0.0.1'], true)
        && !str_ends_with($host, '.visaconsultantcanada.com')) {
        $host = 'mis.visaconsultantcanada.com';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || $host === 'mis.visaconsultantcanada.com';
    return ($https ? 'https://' : 'http://') . $host;
}

function fm_manager_portal_private_url(mysqli $conn): string
{
    $settings = fm_manager_portal_settings($conn);
    $token = (string) ($settings['access_token'] ?? '');
    return rtrim(fm_manager_portal_public_base_url(), '/') . '/fm-manager.php?k=' . rawurlencode($token);
}

function fm_manager_portal_rotate_token(mysqli $conn): string
{
    fm_ensure_manager_portal_schema($conn);
    $token = bin2hex(random_bytes(24));
    $st = $conn->prepare('UPDATE francophonie_mobility_manager_portal SET access_token = ? WHERE id = 1');
    if ($st) {
        $st->bind_param('s', $token);
        $st->execute();
        $st->close();
    }
    return $token;
}

function fm_manager_portal_reset_credentials(mysqli $conn): void
{
    fm_ensure_manager_portal_schema($conn);
    $user = fm_manager_portal_default_username();
    $hash = password_hash(fm_manager_portal_default_password(), PASSWORD_DEFAULT);
    $st = $conn->prepare(
        'UPDATE francophonie_mobility_manager_portal SET username = ?, password_hash = ? WHERE id = 1'
    );
    if ($st) {
        $st->bind_param('ss', $user, $hash);
        $st->execute();
        $st->close();
    }
}

function fm_manager_portal_token_valid(mysqli $conn, string $token): bool
{
    $settings = fm_manager_portal_settings($conn);
    if (!$settings) {
        return false;
    }
    $expected = (string) ($settings['access_token'] ?? '');
    return $expected !== '' && hash_equals($expected, $token);
}

function fm_manager_portal_logged_in(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['fm_manager_portal']['ok']);
}

function fm_manager_portal_mark_invite_ok(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['fm_manager_portal_invite'] = true;
}

function fm_manager_portal_invite_ok(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['fm_manager_portal_invite']) || fm_manager_portal_logged_in();
}

/** Grant portal access from a valid private link (no username/password). */
function fm_manager_portal_grant_access(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['fm_manager_portal']['ok'])) {
        session_regenerate_id(true);
    }
    $_SESSION['fm_manager_portal'] = [
        'ok' => true,
        'username' => 'manager',
        'at' => time(),
    ];
    $_SESSION['fm_manager_portal_invite'] = true;
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/** @deprecated Password login removed — private link alone is enough. */
function fm_manager_portal_login(mysqli $conn, string $username, string $password): bool
{
    unset($conn, $username, $password);
    fm_manager_portal_grant_access();
    return true;
}

function fm_manager_portal_logout(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['fm_manager_portal'], $_SESSION['fm_manager_portal_invite']);
}

/**
 * Staff Francophonie access OR logged-in portal manager on an allowed page.
 */
function fm_require_staff_or_manager_portal(mysqli $conn, bool $json = false): void
{
    require_once __DIR__ . '/role.php';
    fm_ensure_manager_portal_schema($conn);

    if (pcvc_can_access_francophonie_mobility($conn)) {
        return;
    }

    if (fm_manager_portal_logged_in()) {
        if (fm_manager_portal_script_allowed()) {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return;
        }
        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        header('Location: fm-manager.php');
        exit;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    if (fm_manager_portal_invite_ok()) {
        header('Location: fm-manager.php');
        exit;
    }

    header('Location: admin-login.php');
    exit;
}

function fm_manager_portal_session_or_admin_id(): bool
{
    return !empty($_SESSION['id']) || !empty($_SESSION['admin_id']) || fm_manager_portal_logged_in();
}

/** Compact nav for portal managers on tool pages. */
function fm_manager_portal_nav_html(string $active = ''): string
{
    if (!fm_manager_portal_logged_in()) {
        return '';
    }
    $items = [
        'hub' => ['fm-manager.php', 'Home', 'fa-home'],
        'list' => ['francophonie-mobility-applications.php', 'View list', 'fa-list'],
        'add' => ['francophonie-mobility-staff-add.php', 'Smart Add', 'fa-user-plus'],
        'report' => ['francophonie-mobility-client-tracking.php', 'Overall report', 'fa-clipboard-check'],
        'payments' => ['francophonie-mobility-payment-records.php', 'Payment records', 'fa-money-bill-wave'],
    ];
    $html = '<div class="fm-portal-nav">'
        . '<div class="fm-portal-nav-inner">'
        . '<a class="fm-portal-brand" href="fm-manager.php"><i class="fas fa-map me-1"></i> Francophonie Mobility</a>'
        . '<div class="fm-portal-links">';
    foreach ($items as $key => $item) {
        $cls = $active === $key ? ' active' : '';
        $html .= '<a class="fm-portal-link' . $cls . '" href="' . htmlspecialchars($item[0]) . '">'
            . '<i class="fas ' . $item[2] . ' me-1"></i>' . htmlspecialchars($item[1]) . '</a>';
    }
    $html .= '<a class="fm-portal-link" href="fm-manager.php?logout=1"><i class="fas fa-sign-out-alt me-1"></i>Leave</a>'
        . '</div></div></div>'
        . '<style>
.fm-portal-nav{background:#163d22;color:#fff;margin:0 0 1rem;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.fm-portal-nav-inner{max-width:1100px;margin:0 auto;padding:.65rem 1rem;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between}
.fm-portal-brand{color:#fff;font-weight:700;text-decoration:none}
.fm-portal-links{display:flex;flex-wrap:wrap;gap:.35rem}
.fm-portal-link{color:#dcefe2;text-decoration:none;font-size:.88rem;padding:.35rem .7rem;border-radius:999px}
.fm-portal-link:hover,.fm-portal-link.active{background:rgba(255,255,255,.12);color:#fff}
</style>';
    return $html;
}

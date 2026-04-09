<?php
declare(strict_types=1);

/**
 * Commission status email + WhatsApp — same delivery model as job applications:
 * @see helpers/application_status_notify.php (xander_whatsapp_send_template_or_session)
 * WhatsApp: tries Meta template when configured; otherwise free-form text inside the 24h session window.
 */

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php';
require_once __DIR__ . '/../includes/commission_mail_helper.php';
require_once __DIR__ . '/../includes/company_branding.php';

function pcvc_commission_notify_email_html(
    string $name,
    int $requestId,
    string $statusLabel,
    string $recruitedName,
    string $extraMessage
): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeRec = htmlspecialchars($recruitedName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $co = htmlspecialchars(PCVC_COMPANY_DISPLAY_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $msg = trim($extraMessage);
    $msgBlock = '';
    if ($msg !== '') {
        $safeM = nl2br(htmlspecialchars($msg, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $msgBlock = '<div style="margin:16px 0;padding:14px 16px;background:#f0fdf4;border-left:4px solid #427431;border-radius:8px;">'
            . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#166534;">Message from our team</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.55;color:#1e293b;">' . $safeM . '</p></div>';
    }

    return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1e293b;">'
        . '<p style="margin:0 0 12px;font-size:16px;">Dear ' . $safeName . ',</p>'
        . '<p style="margin:0 0 8px;">Your <strong>commission request #' . (int) $requestId . '</strong> status is now:</p>'
        . '<p style="margin:0 0 16px;font-size:18px;font-weight:700;color:#427431;">' . $safeStatus . '</p>'
        . ($safeRec !== '' ? '<p style="margin:0 0 12px;font-size:14px;">Student: <strong>' . $safeRec . '</strong></p>' : '')
        . $msgBlock
        . '<p style="margin:16px 0 0;font-size:14px;color:#475569;">Questions? Reply to this email.</p>'
        . '<p style="margin:12px 0 0;font-size:13px;color:#94a3b8;">' . $co . '</p></div>';
}

function pcvc_commission_notify_whatsapp_body(
    string $name,
    int $requestId,
    string $statusLabel,
    string $recruitedName,
    string $extraMessage
): string {
    $n = xander_whatsapp_sanitize_user_text($name !== '' ? $name : 'Agent');
    $s = xander_whatsapp_sanitize_user_text($statusLabel);
    $parts = [
        '*Parrot Canada Visa Consultant*',
        '*Commission request — status update*',
        '',
        'Hello ' . $n . ',',
        '',
        'Request #' . (int) $requestId . ' is now:',
        '*' . $s . '*',
    ];
    $rec = trim($recruitedName);
    if ($rec !== '') {
        $parts[] = '';
        $parts[] = 'Student: ' . xander_whatsapp_sanitize_user_text($rec);
    }
    $msg = trim($extraMessage);
    if ($msg !== '') {
        $parts[] = '';
        $parts[] = '*Message from our team*';
        $parts[] = xander_whatsapp_sanitize_user_text($msg);
    }
    $parts[] = '';
    $parts[] = '— Parrot Canada Visa Consultant';

    return xander_notify_text_clip(implode("\n", $parts), 4096);
}

/**
 * @return array{sent:bool,method:string,error:string,detail:string}
 */
function pcvc_send_commission_status_whatsapp(
    string $phoneRaw,
    string $name,
    int $requestId,
    string $statusLabel,
    string $recruitedName,
    string $extraMessage
): array {
    $empty = ['sent' => false, 'method' => '', 'error' => '', 'detail' => ''];
    $token = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    $phoneId = xander_env_get('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneId === '') {
        $empty['error'] = 'WhatsApp is not configured (missing token or phone number ID).';

        return $empty;
    }
    $defaultCc = xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE');
    $defaultCcOrNull = $defaultCc !== '' ? $defaultCc : null;
    $to = xander_format_phone_for_whatsapp_e164($phoneRaw, $defaultCcOrNull);
    if ($to === null) {
        $empty['error'] = 'Invalid phone number for WhatsApp.';

        return $empty;
    }
    if (!function_exists('curl_init')) {
        $empty['error'] = 'Server has no cURL.';

        return $empty;
    }

    $version = xander_env_get('META_GRAPH_VERSION') ?: 'v19.0';
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode((string) $phoneId) . '/messages';
    $body = pcvc_commission_notify_whatsapp_body($name, $requestId, $statusLabel, $recruitedName, $extraMessage);

    return xander_whatsapp_send_template_or_session(
        $to,
        $url,
        $token,
        '',
        'en_US',
        0,
        [],
        $body
    );
}

/**
 * @return array{email: array<string, mixed>, whatsapp: array<string, mixed>}|null
 */
function pcvc_notify_commission_request_change(
    mysqli $conn,
    int $id,
    string $newStatus,
    string $statusLabel,
    bool $sendEmail,
    bool $sendWhatsapp,
    string $notifyMessage = ''
): ?array {
    if (!$sendEmail && !$sendWhatsapp) {
        return null;
    }

    xander_load_env_file();

    $emailOut = ['requested' => $sendEmail, 'sent' => null, 'error' => ''];
    $waOut = ['requested' => $sendWhatsapp, 'sent' => null, 'method' => '', 'error' => ''];

    $stmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, phone, recruited_name FROM commission_requests WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['email' => $emailOut, 'whatsapp' => $waOut];
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        if ($sendEmail) {
            $emailOut['sent'] = false;
            $emailOut['error'] = 'Request not found.';
        }
        if ($sendWhatsapp) {
            $waOut['sent'] = false;
            $waOut['error'] = 'Request not found.';
        }

        return ['email' => $emailOut, 'whatsapp' => $waOut];
    }

    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    $recruited = trim((string) ($row['recruited_name'] ?? ''));

    if ($sendEmail) {
        $to = trim((string) ($row['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $emailOut['sent'] = false;
            $emailOut['error'] = 'No valid email on file.';
        } else {
            $html = pcvc_commission_notify_email_html($name, $id, $statusLabel, $recruited, $notifyMessage);
            $subj = 'Commission request #' . $id . ' — ' . $statusLabel;
            $ok = pcvc_send_commission_html_mail([$to], $subj, $html);
            $emailOut['sent'] = $ok;
            $emailOut['error'] = $ok ? '' : 'Email could not be sent.';
        }
    }

    if ($sendWhatsapp) {
        $phone = trim((string) ($row['phone'] ?? ''));
        $r = pcvc_send_commission_status_whatsapp($phone, $name, $id, $statusLabel, $recruited, $notifyMessage);
        $waOut['sent'] = $r['sent'];
        $waOut['method'] = $r['method'];
        $waOut['error'] = $r['error'];
    }

    return ['email' => $emailOut, 'whatsapp' => $waOut];
}

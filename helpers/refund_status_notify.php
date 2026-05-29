<?php
declare(strict_types=1);

/**
 * Refund request notifications (email + WhatsApp template).
 *
 * WhatsApp template (Utility) — register in Meta Business Manager:
 *   WHATSAPP_REFUND_TEMPLATE_NAME=pcvc_refund_update
 *   WHATSAPP_REFUND_TEMPLATE_LANG=en
 *   WHATSAPP_REFUND_TEMPLATE_PARAMS=7
 *
 * Body placeholders (exact order, 7 variables):
 *   {{1}} Student name
 *   {{2}} Reference ID (e.g. REF2026ABC12345)
 *   {{3}} Status label (e.g. Under review, Approved)
 *   {{4}} Service paid for
 *   {{5}} Amount with currency (e.g. 250.00 USD)
 *   {{6}} Admin comment — part 1 (primary message, up to ~900 chars)
 *   {{7}} Admin comment — part 2 (continuation, or "—" if not needed)
 *
 * Copy-paste Meta template body:
 * ---
 * Hello {{1}},
 *
 * Update on your refund request *{{2}}*.
 *
 * Status: *{{3}}*
 * Service: {{4}}
 * Amount: {{5}}
 *
 * *Message from our team:*
 * {{6}}
 *
 * {{7}}
 *
 * — Parrot Canada Visa Consultant
 * ---
 *
 * Legacy 4-param template (WHATSAPP_REFUND_TEMPLATE_PARAMS=4) is still supported:
 *   {{1}} name, {{2}} reference, {{3}} status, {{4}} full comment (single block, clipped)
 */

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php';
require_once __DIR__ . '/../includes/commission_mail_helper.php';
require_once __DIR__ . '/../includes/company_branding.php';

/** Max chars per WhatsApp template body variable (Meta limit 1024; leave headroom). */
const PCVC_REFUND_WA_VAR_MAX = 900;

function pcvc_refund_whatsapp_template_config(): array
{
    xander_load_env_file();
    $name = trim(xander_env_get('WHATSAPP_REFUND_TEMPLATE_NAME'));
    if ($name === '') {
        $name = 'pcvc_refund_update';
    }
    $lang = trim(xander_env_get('WHATSAPP_REFUND_TEMPLATE_LANG'));
    if ($lang === '') {
        $lang = 'en';
    }
    $params = (int) trim(xander_env_get('WHATSAPP_REFUND_TEMPLATE_PARAMS'));
    if ($params < 1) {
        $params = 7;
    }

    return ['name' => $name, 'lang' => $lang, 'params' => $params];
}

/**
 * Split a long admin comment across WhatsApp template variables.
 *
 * @return list<string> Always returns $partCount entries; empty slots are "—"
 */
function pcvc_refund_comment_parts_for_whatsapp(string $comment, int $partCount = 2, int $maxLen = PCVC_REFUND_WA_VAR_MAX): array
{
    $partCount = max(1, min(3, $partCount));
    $text = trim(preg_replace("/\r\n|\r/", "\n", $comment) ?? '');
    $out = [];

    if ($text === '') {
        for ($i = 0; $i < $partCount; $i++) {
            $out[] = '—';
        }

        return $out;
    }

    $remaining = $text;
    for ($i = 0; $i < $partCount; $i++) {
        if ($remaining === '') {
            $out[] = '—';
            continue;
        }
        if (strlen($remaining) <= $maxLen) {
            $out[] = $remaining;
            $remaining = '';
            continue;
        }
        $chunk = substr($remaining, 0, $maxLen);
        $breakAt = strrpos($chunk, "\n");
        if ($breakAt === false || $breakAt < (int) ($maxLen * 0.5)) {
            $breakAt = strrpos($chunk, ' ');
        }
        if ($breakAt !== false && $breakAt > (int) ($maxLen * 0.4)) {
            $piece = substr($remaining, 0, $breakAt);
            $remaining = ltrim(substr($remaining, $breakAt));
        } else {
            $piece = $chunk;
            $remaining = ltrim(substr($remaining, $maxLen));
        }
        $out[] = $piece;
    }

    if ($remaining !== '' && $partCount > 0) {
        $last = count($out) - 1;
        $merged = trim($out[$last] . ' ' . $remaining);
        $out[$last] = xander_notify_text_clip($merged, $maxLen);
    }

    while (count($out) < $partCount) {
        $out[] = '—';
    }

    return array_slice($out, 0, $partCount);
}

/**
 * @return list<string>
 */
function pcvc_refund_whatsapp_template_body_texts(
    string $name,
    string $referenceId,
    string $statusLabel,
    string $servicePaid,
    string $amountLine,
    string $adminComment,
    int $paramCount
): array {
    $safeName = xander_whatsapp_sanitize_user_text($name !== '' ? $name : 'Student');
    $safeRef = xander_whatsapp_sanitize_user_text($referenceId);
    $safeStatus = xander_whatsapp_sanitize_user_text($statusLabel);
    $safeService = xander_whatsapp_sanitize_user_text($servicePaid !== '' ? $servicePaid : '—');
    $safeAmount = xander_whatsapp_sanitize_user_text($amountLine !== '' ? $amountLine : '—');
    $comment = trim($adminComment);

    if ($paramCount <= 4) {
        $legacyComment = $comment !== '' ? $comment : '—';

        return [
            $safeName,
            $safeRef,
            $safeStatus,
            xander_whatsapp_sanitize_user_text(xander_notify_text_clip($legacyComment, PCVC_REFUND_WA_VAR_MAX)),
        ];
    }

    if ($paramCount === 5) {
        $legacyComment = $comment !== '' ? $comment : '—';

        return [
            $safeName,
            $safeRef,
            $safeStatus,
            $safeService,
            xander_whatsapp_sanitize_user_text(xander_notify_text_clip($legacyComment, PCVC_REFUND_WA_VAR_MAX)),
        ];
    }

    if ($paramCount === 6) {
        $parts = pcvc_refund_comment_parts_for_whatsapp($comment, 1);

        return [
            $safeName,
            $safeRef,
            $safeStatus,
            $safeService,
            $safeAmount,
            xander_whatsapp_sanitize_user_text($parts[0]),
        ];
    }

    // Default: 7+ params — two dedicated comment slots
    $commentParts = pcvc_refund_comment_parts_for_whatsapp($comment, 2);
    $texts = [
        $safeName,
        $safeRef,
        $safeStatus,
        $safeService,
        $safeAmount,
        xander_whatsapp_sanitize_user_text($commentParts[0]),
        xander_whatsapp_sanitize_user_text($commentParts[1]),
    ];

    if ($paramCount > 7) {
        $extraParts = pcvc_refund_comment_parts_for_whatsapp($comment, 3);
        $texts[] = xander_whatsapp_sanitize_user_text($extraParts[2]);
    }

    return array_slice($texts, 0, $paramCount);
}

function pcvc_refund_notify_email_html(
    string $name,
    string $referenceId,
    string $statusLabel,
    string $servicePaid,
    string $amountLine,
    string $adminComment
): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeRef = htmlspecialchars($referenceId, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeService = htmlspecialchars($servicePaid, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeAmount = htmlspecialchars($amountLine, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $co = htmlspecialchars(PCVC_COMPANY_DISPLAY_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $commentBlock = '';
    $msg = trim($adminComment);
    if ($msg !== '') {
        $safeM = nl2br(htmlspecialchars($msg, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $commentBlock = '<div style="margin:16px 0;padding:14px 16px;background:#f0fdf4;border-left:4px solid #427431;border-radius:8px;">'
            . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#166534;">Message from our team</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.55;color:#1e293b;">' . $safeM . '</p></div>';
    }

    return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1e293b;">'
        . '<div style="background:linear-gradient(135deg,#427431,#2f5a26);color:#fff;padding:24px;border-radius:12px 12px 0 0;text-align:center;">'
        . '<h1 style="margin:0;font-size:22px;">Refund Request Update</h1>'
        . '<p style="margin:8px 0 0;opacity:0.9;font-size:14px;">' . $co . '</p></div>'
        . '<div style="background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:0 0 12px 12px;">'
        . '<p style="margin:0 0 12px;font-size:16px;">Dear ' . $safeName . ',</p>'
        . '<p style="margin:0 0 8px;">Your refund request <strong>' . $safeRef . '</strong> has been updated:</p>'
        . '<p style="margin:0 0 16px;font-size:18px;font-weight:700;color:#427431;">' . $safeStatus . '</p>'
        . '<p style="margin:0 0 6px;font-size:14px;color:#64748b;">Service: <strong style="color:#1e293b;">' . $safeService . '</strong></p>'
        . '<p style="margin:0 0 16px;font-size:14px;color:#64748b;">Amount: <strong style="color:#1e293b;">' . $safeAmount . '</strong></p>'
        . $commentBlock
        . '<p style="margin:16px 0 0;font-size:14px;color:#475569;">Questions? Reply to this email.</p>'
        . '<p style="margin:12px 0 0;font-size:13px;color:#94a3b8;">' . $co . '</p></div></div>';
}

function pcvc_refund_notify_whatsapp_fallback(
    string $name,
    string $referenceId,
    string $statusLabel,
    string $servicePaid,
    string $amountLine,
    string $adminComment
): string {
    $parts = [
        '*Parrot Canada Visa Consultant*',
        '*Refund request update*',
        '',
        'Hello ' . xander_whatsapp_sanitize_user_text($name !== '' ? $name : 'Student') . ',',
        '',
        'Reference: ' . xander_whatsapp_sanitize_user_text($referenceId),
        'Status: *' . xander_whatsapp_sanitize_user_text($statusLabel) . '*',
    ];
    if (trim($servicePaid) !== '') {
        $parts[] = 'Service: ' . xander_whatsapp_sanitize_user_text($servicePaid);
    }
    if (trim($amountLine) !== '') {
        $parts[] = 'Amount: ' . xander_whatsapp_sanitize_user_text($amountLine);
    }
    $msg = trim($adminComment);
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
function pcvc_send_refund_status_whatsapp(
    string $phoneRaw,
    string $name,
    string $referenceId,
    string $statusLabel,
    string $servicePaid,
    string $amountLine,
    string $adminComment
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

    $tpl = pcvc_refund_whatsapp_template_config();
    $templateBodyTexts = pcvc_refund_whatsapp_template_body_texts(
        $name,
        $referenceId,
        $statusLabel,
        $servicePaid,
        $amountLine,
        $adminComment,
        $tpl['params']
    );

    $version = xander_env_get('META_GRAPH_VERSION') ?: 'v19.0';
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode((string) $phoneId) . '/messages';
    $fallback = pcvc_refund_notify_whatsapp_fallback(
        $name,
        $referenceId,
        $statusLabel,
        $servicePaid,
        $amountLine,
        $adminComment
    );

    return xander_whatsapp_send_template_or_session(
        $to,
        $url,
        $token,
        $tpl['name'],
        $tpl['lang'],
        $tpl['params'],
        $templateBodyTexts,
        $fallback
    );
}

/**
 * @return array{email: array<string, mixed>, whatsapp: array<string, mixed>}|null
 */
function pcvc_notify_refund_request_change(
    mysqli $conn,
    int $id,
    string $statusLabel,
    bool $sendEmail,
    bool $sendWhatsapp,
    string $adminComment = ''
): ?array {
    if (!$sendEmail && !$sendWhatsapp) {
        return null;
    }

    xander_load_env_file();

    $emailOut = ['requested' => $sendEmail, 'sent' => null, 'error' => ''];
    $waOut = ['requested' => $sendWhatsapp, 'sent' => null, 'method' => '', 'error' => ''];

    $stmt = $conn->prepare(
        'SELECT id, reference_id, first_name, last_name, email, phone, service_paid_for, amount, currency
         FROM refund_requests WHERE id = ? LIMIT 1'
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
    $ref = trim((string) ($row['reference_id'] ?? ''));
    $service = trim((string) ($row['service_paid_for'] ?? ''));
    $cur = strtoupper(trim((string) ($row['currency'] ?? 'USD'))) ?: 'USD';
    $amountLine = number_format((float) ($row['amount'] ?? 0), 2) . ' ' . $cur;

    if ($sendEmail) {
        $to = trim((string) ($row['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $emailOut['sent'] = false;
            $emailOut['error'] = 'No valid email on file.';
        } else {
            $html = pcvc_refund_notify_email_html($name, $ref, $statusLabel, $service, $amountLine, $adminComment);
            $subj = 'Refund request ' . $ref . ' — ' . $statusLabel;
            $ok = pcvc_send_commission_html_mail([$to], $subj, $html);
            $emailOut['sent'] = $ok;
            $emailOut['error'] = $ok ? '' : 'Email could not be sent.';
        }
    }

    if ($sendWhatsapp) {
        $phone = trim((string) ($row['phone'] ?? ''));
        $r = pcvc_send_refund_status_whatsapp(
            $phone,
            $name,
            $ref,
            $statusLabel,
            $service,
            $amountLine,
            $adminComment
        );
        $waOut['sent'] = $r['sent'];
        $waOut['method'] = $r['method'];
        $waOut['error'] = $r['error'];
    }

    return ['email' => $emailOut, 'whatsapp' => $waOut];
}

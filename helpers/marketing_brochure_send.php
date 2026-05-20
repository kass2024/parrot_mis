<?php
declare(strict_types=1);

/**
 * Smart Brochure Sharing — send + search helpers.
 *
 *  - pcvc_brochure_search_applicants : unified search across all application tables
 *  - pcvc_brochure_send_whatsapp     : WhatsApp Cloud API (from .env)
 *  - pcvc_brochure_send_email        : PHPMailer SMTP using helpers/mailer.php
 *  - pcvc_brochure_save_contact      : persist a new lead into `contacts`
 *  - pcvc_brochure_default_message   : default sharing copy
 */

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php';

/**
 * Returns column metadata for a table (cached per request).
 *
 * @return array{cols:array<string,true>,pk:?string}
 */
function pcvc_brochure_table_meta(mysqli $conn, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $cols  = [];
    $pk    = null;
    $first = null;
    $safe  = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW COLUMNS FROM `$safe`");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $name = (string) $row['Field'];
            $cols[strtolower($name)] = true;
            if ($first === null) {
                $first = $name;
            }
            if (($row['Key'] ?? '') === 'PRI' && $pk === null) {
                $pk = $name;
            }
        }
        $r->free();
    }
    if ($pk === null) {
        $pk = $first;
    }
    $cache[$table] = ['cols' => $cols, 'pk' => $pk];
    return $cache[$table];
}

/**
 * Backward-compat shortcut used in places that only need the column set.
 *
 * @return array<string,true>
 */
function pcvc_brochure_table_columns(mysqli $conn, string $table): array
{
    return pcvc_brochure_table_meta($conn, $table)['cols'] ?? [];
}

/**
 * Lookup recent applicants across every application table.
 * Only adds a source for a column that actually exists in that table.
 *
 * @param  string|null $likePhone "%digits%" pattern for phone columns, or null
 * @param  string      $likeText  "%term%" pattern for name/email columns
 * @return array<int,array{table:string,row_id:int,name:string,phone:string,email:string}>
 */
function pcvc_brochure_search_applicants(mysqli $conn, ?string $likePhone, string $likeText, bool $phoneMode, string $rawQuery): array
{
    // Each source declares candidate columns; only those that exist are used.
    $sources = [
        [
            'table'      => 'student_applications',
            'phone_cols' => ['phone_number', 'emergency_phone_number'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'malta_applications',
            'phone_cols' => ['contact_number'],
            'name_cols'  => ['name', 'surname'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'turkey_applications',
            'phone_cols' => ['mobile', 'father_mobile'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'budapest_applications',
            'phone_cols' => ['phone'],
            'name_cols'  => ['full_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'credit_transfer_applications',
            'phone_cols' => ['phone_number'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'master_loan_applications',
            'phone_cols' => ['phone_number', 'ref_phone'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'georgia_applications',
            'phone_cols' => ['contact_number'],
            'name_cols'  => ['name', 'surname'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'form_17_applications',
            'phone_cols' => ['applicant_mobile'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'form_20_applications',
            'phone_cols' => ['phone_number', 'emergency_phone_number'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'canada_medical_exams_requests',
            'phone_cols' => ['phone_number', 'emergency_phone_number'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'job_applications',
            'phone_cols' => ['phone_number'],
            'name_cols'  => ['first_name', 'last_name'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'dphu',
            'phone_cols' => ['telephone', 'orgTel'],
            'name_cols'  => ['name', 'orgName'],
            'email_col'  => 'email',
        ],
        [
            'table'      => 'contacts',
            'phone_cols' => ['phone'],
            'name_cols'  => ['name'],
            'email_col'  => '',
        ],
    ];

    $matches = [];
    $seen    = [];

    foreach ($sources as $src) {
        $meta      = pcvc_brochure_table_meta($conn, $src['table']);
        $cols      = $meta['cols'];
        $pk        = $meta['pk'] ?: 'id';
        $phoneCols = array_values(array_filter($src['phone_cols'], static fn($c) => isset($cols[strtolower($c)])));
        $nameCols  = array_values(array_filter($src['name_cols'],  static fn($c) => isset($cols[strtolower($c)])));
        $hasEmail  = $src['email_col'] !== '' && isset($cols[strtolower($src['email_col'])]);

        if (!$phoneCols && !$nameCols && !$hasEmail) {
            continue;
        }

        $nameExpr  = $nameCols
            ? 'TRIM(CONCAT(' . implode(",' ',", array_map(static fn($c) => "IFNULL($c,'')", $nameCols)) . '))'
            : "''";
        $phoneExpr = $phoneCols
            ? 'COALESCE(' . implode(',', array_map(static fn($c) => "NULLIF($c,'')", $phoneCols)) . ')'
            : 'NULL';
        $emailExpr = $hasEmail ? $src['email_col'] : 'NULL';

        // Build WHERE clause with the right number of binds.
        $cond  = [];
        $binds = [];

        if ($phoneMode && $likePhone !== null && $phoneCols) {
            foreach ($phoneCols as $c) {
                $cond[]  = "REPLACE(REPLACE(REPLACE(REPLACE(IFNULL($c,''),' ',''),'-',''),'(',''),')','') LIKE ?";
                $binds[] = $likePhone;
            }
        } else {
            // Text search across name + email + phone (free-text mode)
            foreach ($nameCols as $c) {
                $cond[]  = "$c LIKE ?";
                $binds[] = $likeText;
            }
            if ($hasEmail) {
                $cond[]  = $src['email_col'] . ' LIKE ?';
                $binds[] = $likeText;
            }
            if ($likePhone !== null && $phoneCols) {
                foreach ($phoneCols as $c) {
                    $cond[]  = "REPLACE(REPLACE(REPLACE(REPLACE(IFNULL($c,''),' ',''),'-',''),'(',''),')','') LIKE ?";
                    $binds[] = $likePhone;
                }
            }
        }

        if (!$cond) {
            continue;
        }

        $sql = "SELECT `$pk` AS rid,
                       $nameExpr  AS full_name,
                       $phoneExpr AS phone_match,
                       $emailExpr AS email_match
                FROM `" . $src['table'] . "`
                WHERE " . implode(' OR ', $cond) . "
                ORDER BY `$pk` DESC
                LIMIT 8";
        $stmt = @$conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param(str_repeat('s', count($binds)), ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            continue;
        }
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $name  = trim((string) ($row['full_name']  ?? ''));
            $phone = trim((string) ($row['phone_match'] ?? ''));
            $email = trim((string) ($row['email_match'] ?? ''));
            $key   = strtolower($name) . '|' . preg_replace('/\D+/', '', $phone) . '|' . strtolower($email);
            if (isset($seen[$key]) || ($name === '' && $phone === '' && $email === '')) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = [
                'table'  => (string) $src['table'],
                'row_id' => (int) ($row['rid'] ?? 0),
                'name'   => $name,
                'phone'  => $phone,
                'email'  => $email,
            ];
            if (count($matches) >= 25) {
                break 2;
            }
        }
        $stmt->close();
    }

    return $matches;
}

/**
 * Default text body used when admin doesn't override it.
 */
function pcvc_brochure_default_message(string $name, string $title, string $url): string
{
    $hi = $name !== '' ? ('Hello ' . $name . ',') : 'Hello,';
    $msg  = $hi . "\n\n";
    $msg .= "Please find our brochure: " . $title . "\n";
    $msg .= $url . "\n\n";
    $msg .= "Open the link to read the full document and download the PDF.\n";
    $msg .= "Reach out any time if you have questions.\n\n";
    $msg .= "— Parrot Canada Visa Consultant";
    return $msg;
}

/**
 * Persist (or update) a contact record from the share modal.
 */
function pcvc_brochure_save_contact(mysqli $conn, string $name, string $phone): void
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return;
    }
    $check = $conn->prepare('SELECT id FROM contacts WHERE phone = ? LIMIT 1');
    $check->bind_param('s', $digits);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        return;
    }
    $segment   = 'customer';
    $cleanName = $name !== '' ? $name : 'Brochure Contact';
    $ins = $conn->prepare('INSERT INTO contacts (name, phone, segment, opted_in, status) VALUES (?, ?, ?, 1, "active")');
    $ins->bind_param('sss', $cleanName, $digits, $segment);
    @$ins->execute();
    $ins->close();
}

/**
 * Send a brochure via WhatsApp Cloud API.
 * Uses the official Meta number → message is delivered under the company's WhatsApp Business name.
 *
 * @return array{sent:bool,method:string,error:string,detail:string,to:?string}
 */
function pcvc_brochure_send_whatsapp(string $phoneRaw, string $message): array
{
    $out = ['sent' => false, 'method' => '', 'error' => '', 'detail' => '', 'to' => null];

    $token   = trim(xander_env_get('WHATSAPP_ACCESS_TOKEN'));
    if ($token === '') {
        $token = trim(xander_env_get('WHATSAPP_TOKEN'));
    }
    $phoneId = trim(xander_env_get('WHATSAPP_PHONE_NUMBER_ID'));
    if ($token === '' || $phoneId === '') {
        $out['error']  = 'WhatsApp is not configured. Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env.';
        return $out;
    }

    $dcc       = trim(xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE'));
    $defaultCc = $dcc !== '' ? $dcc : null;
    $to        = xander_format_phone_for_whatsapp_e164($phoneRaw, $defaultCc);
    if ($to === null || $to === '') {
        $out['error']  = 'Could not normalize the phone number to international format. Set WHATSAPP_DEFAULT_COUNTRY_CODE in .env for national numbers.';
        $out['detail'] = 'input=' . $phoneRaw;
        return $out;
    }
    $out['to'] = $to;

    if (!function_exists('curl_init')) {
        $out['error'] = 'Server has no cURL extension (enable php-curl).';
        return $out;
    }

    $version = trim(xander_env_get('META_GRAPH_VERSION'));
    if ($version === '') {
        $version = 'v19.0';
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneId) . '/messages';

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'text',
        'text'              => [
            'preview_url' => true,
            'body'        => mb_substr($message, 0, 4096),
        ],
    ];

    $res = xander_whatsapp_graph_post($url, $token, $payload);
    if (($res['http'] ?? 0) >= 200 && ($res['http'] ?? 0) < 300 && xander_whatsapp_response_has_message_id($res['json'] ?? null)) {
        $out['sent']   = true;
        $out['method'] = 'cloud-api-text';
        return $out;
    }

    $err = xander_whatsapp_extract_error($res['json'] ?? null);
    $msg = $err ? xander_whatsapp_user_hint($err) : ('HTTP ' . ($res['http'] ?? 0));
    $out['error']  = $msg;
    $out['detail'] = (string) ($res['body'] ?? '');
    $out['method'] = 'cloud-api-text';
    return $out;
}

/**
 * Send a brochure email via SMTP using the project's existing app_mailer().
 *
 * @param array{to_email:string,to_name:string,title:string,description:string,html_content:string,share_url:string,pdf_path:string,pdf_filename:string} $opts
 * @return array{sent:bool,error:string}
 */
function pcvc_brochure_send_email(array $opts): array
{
    $out = ['sent' => false, 'error' => ''];

    require_once __DIR__ . '/mailer.php';
    try {
        $mail = app_mailer();
        $mail->addAddress($opts['to_email'], $opts['to_name'] ?: $opts['to_email']);
        $mail->Subject = $opts['title'] !== '' ? $opts['title'] : 'Brochure from Parrot Canada Visa Consultant';

        $html = pcvc_brochure_build_email_html($opts);
        $mail->Body    = $html;
        $alt = strip_tags($opts['html_content'] !== '' ? $opts['html_content'] : ($opts['description'] ?: $opts['title']));
        $mail->AltBody = trim($alt) . "\n\n" . $opts['share_url'];

        if ($opts['pdf_path'] !== '' && is_file($opts['pdf_path'])) {
            $mail->addAttachment($opts['pdf_path'], $opts['pdf_filename'] ?: basename($opts['pdf_path']));
        }
        $mail->send();
        $out['sent'] = true;
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/**
 * Build a clean responsive HTML email body that mirrors the public brochure page.
 */
function pcvc_brochure_build_email_html(array $opts): string
{
    $title       = htmlspecialchars($opts['title'], ENT_QUOTES, 'UTF-8');
    $shareUrl    = htmlspecialchars($opts['share_url'], ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($opts['description'], ENT_QUOTES, 'UTF-8');
    $name        = htmlspecialchars($opts['to_name'] !== '' ? $opts['to_name'] : 'there', ENT_QUOTES, 'UTF-8');
    $articleHtml = $opts['html_content']; // already sanitized by extractor
    $y           = (int) date('Y');

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb">
    <tr><td align="center" style="padding:24px 12px">
      <table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,0.12);max-width:640px;width:100%">
        <tr>
          <td style="background:linear-gradient(135deg,#427431 0%,#2f5a26 100%);padding:24px 28px;color:#fff;border-bottom:3px solid #E21D1E">
            <div style="font-size:14px;text-transform:uppercase;letter-spacing:1.5px;opacity:.85">Parrot Canada Visa Consultant</div>
            <div style="font-size:22px;font-weight:800;margin-top:6px">{$title}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:28px">
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6">Hello {$name},</p>
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6">Please find our latest brochure attached and summarized below for your convenience.</p>
            <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6">{$description}</p>
            <p style="margin:0 0 24px">
              <a href="{$shareUrl}" style="display:inline-block;background:#427431;color:#fff;padding:13px 22px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px">Open the full brochure online</a>
            </p>
            <div style="border-top:1px solid #e2e8f0;margin:24px 0 18px"></div>
            <div style="font-size:14px;line-height:1.75">{$articleHtml}</div>
            <div style="border-top:1px solid #e2e8f0;margin:24px 0 14px"></div>
            <p style="margin:0;font-size:13px;color:#64748b">Need help? Reply to this email or chat with us on WhatsApp — we're here for you.</p>
          </td>
        </tr>
        <tr>
          <td style="background:#0f172a;color:#cbd5e1;padding:16px 28px;font-size:12px">
            <strong style="color:#fff">Parrot Canada Visa Consultant</strong> · admission@visaconsultantcanada.com<br>
            © {$y} All rights reserved.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML
;
}

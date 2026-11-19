<?php
declare(strict_types=1);

/**
 * Employee notice lines, signature-block identity, and witness e-sign
 * for custom employment agreements that do not use ${placeholders}.
 */

require_once __DIR__ . '/staff_contract_schema.php';
require_once __DIR__ . '/staff_contract_word.php';

/**
 * @param callable(string):string $editor
 */
function pcvc_staff_contract_edit_document_xml(string $docxAbs, callable $editor): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) {
        throw new RuntimeException('Could not open contract document.');
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    if ($xml === '') {
        $zip->close();
        throw new RuntimeException('Contract document body is missing.');
    }
    $updated = $editor($xml);
    if (!is_string($updated) || $updated === '') {
        $zip->close();
        throw new RuntimeException('Contract document update failed.');
    }
    if ($updated !== $xml) {
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $updated);
    }
    $zip->close();
}

function pcvc_staff_contract_xml_text(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Replace the underscore-only cell that follows a label, the Nth time that label appears.
 */
function pcvc_staff_contract_fill_blank_after_label(string $xml, string $label, string $value, int $occurrence = 1): string
{
    $value = trim($value);
    if ($value === '' || $occurrence < 1) {
        return $xml;
    }
    $safe = pcvc_staff_contract_xml_text($value);
    $pattern = '/(<w:t(?:\s[^>]*)?>' . preg_quote($label, '/') . '<\/w:t>)((?:(?!<w:t[\s>]).)*?)(<w:t(?:\s[^>]*)?>)(_+)(<\/w:t>)/s';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen, $occurrence, $safe): string {
            $seen++;
            if ($seen !== $occurrence) {
                return $match[0];
            }

            return $match[1] . $match[2] . $match[3] . $safe . $match[5];
        },
        $xml
    ) ?? $xml;
}

/**
 * Replace a line stored as one run, such as "Address: ________".
 */
function pcvc_staff_contract_fill_underscore_line(string $xml, string $prefix, string $value, int $occurrence = 1): string
{
    $value = trim($value);
    if ($value === '' || $occurrence < 1) {
        return $xml;
    }
    $safe = pcvc_staff_contract_xml_text($prefix . $value);
    $pattern = '/(<w:t(?:\s[^>]*)?>)' . preg_quote($prefix, '/') . '_+(<\/w:t>)/';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen, $occurrence, $safe): string {
            $seen++;
            if ($seen !== $occurrence) {
                return $match[0];
            }

            return $match[1] . $safe . $match[2];
        },
        $xml
    ) ?? $xml;
}

function pcvc_staff_contract_replace_exact_text(string $xml, string $old, string $new, int $occurrence = 1): string
{
    $new = trim($new);
    if ($old === '' || $new === '' || $occurrence < 1) {
        return $xml;
    }
    $safe = pcvc_staff_contract_xml_text($new);
    $pattern = '/(<w:t(?:\s[^>]*)?>)' . preg_quote($old, '/') . '(<\/w:t>)/';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen, $occurrence, $safe): string {
            $seen++;
            if ($seen !== $occurrence) {
                return $match[0];
            }

            return $match[1] . $safe . $match[2];
        },
        $xml
    ) ?? $xml;
}

/**
 * Replace the text node that follows a label, whether it is still blank or already filled.
 */
function pcvc_staff_contract_set_following_text(string $xml, string $label, string $value, int $occurrence = 1): string
{
    $value = trim($value);
    if ($value === '' || $occurrence < 1) {
        return $xml;
    }
    $safe = pcvc_staff_contract_xml_text($value);
    $pattern = '/(<w:t(?:\s[^>]*)?>' . preg_quote($label, '/') . '<\/w:t>)((?:(?!<w:t[\s>]).)*?)(<w:t(?:\s[^>]*)?>)([^<]*)(<\/w:t>)/s';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen, $occurrence, $safe): string {
            $seen++;
            if ($seen !== $occurrence) {
                return $match[0];
            }

            return $match[1] . $match[2] . $match[3] . $safe . $match[5];
        },
        $xml
    ) ?? $xml;
}

function pcvc_staff_contract_mark_employment_status(string $xml, string $label): string
{
    $label = pcvc_staff_employment_type_normalize($label);
    $empty = "\u{2610}";
    $checked = "\u{2611}";
    $boxes = $empty . $checked . "\u{2612}";
    foreach (pcvc_staff_employment_type_options() as $name) {
        $pattern = '/(<w:t(?:\s[^>]*)?>)[' . $boxes . '](<\/w:t>)((?:(?!<w:t[\s>]).)*?)(<w:t(?:\s[^>]*)?>)(\s*'
            . preg_quote($name, '/') . ')/su';
        $mark = ($label !== '' && $name === $label) ? $checked : $empty;
        $xml = preg_replace($pattern, '$1' . $mark . '$2$3$4$5', $xml, 1) ?? $xml;
    }

    return $xml;
}

/**
 * Tick the employment-status box on the working copies from the staff profile.
 */
function pcvc_staff_contract_sync_employment_status(mysqli $conn, int $adminId): void
{
    if ($adminId <= 0) {
        return;
    }
    $contract = pcvc_staff_contract_for_admin($conn, $adminId);
    $admin = pcvc_staff_contract_admin_row($conn, $adminId);
    if (!$contract || !$admin) {
        return;
    }
    $type = (string) ($admin['employment_type'] ?? '');
    $paths = [
        trim((string) ($contract['source_docx_path'] ?? '')),
        trim((string) ($contract['filled_docx_path'] ?? '')),
        trim((string) ($contract['signed_docx_path'] ?? '')),
    ];
    foreach ($paths as $rel) {
        if ($rel === '') {
            continue;
        }
        $abs = pcvc_staff_contract_abs_path($rel);
        if (!is_file($abs)) {
            continue;
        }
        try {
            pcvc_staff_contract_edit_document_xml($abs, static function (string $xml) use ($type): string {
                return pcvc_staff_contract_mark_employment_status($xml, $type);
            });
        } catch (Throwable $e) {
            continue;
        }
    }
}

/**
 * The second signature line belongs to the employee. A later refill must not
 * copy the company signature and stamp onto that line.
 */
function pcvc_staff_contract_clear_company_images_from_employee_signature(string $xml): string
{
    $first = strpos($xml, '>Signature:</w:t>');
    if ($first === false) {
        return $xml;
    }
    $second = strpos($xml, '>Signature:</w:t>', $first + 12);
    if ($second === false) {
        return $xml;
    }
    $paraEnd = strpos($xml, '</w:p>', $second);
    $afterLabel = strpos($xml, '</w:r>', $second);
    if ($paraEnd === false || $afterLabel === false || $afterLabel > $paraEnd) {
        return $xml;
    }
    $afterLabel += strlen('</w:r>');
    $middle = substr($xml, $afterLabel, $paraEnd - $afterLabel);
    if (strpos($middle, 'Employer signature') === false && strpos($middle, 'Company stamp') === false) {
        return $xml;
    }

    return substr($xml, 0, $afterLabel)
        . '<w:r><w:br/><w:t>________________________________</w:t></w:r>'
        . substr($xml, $paraEnd);
}

/**
 * Company column only: managing-director signature, then the company stamp.
 */
function pcvc_staff_contract_mark_company_signature_tokens(string $xml): string
{
    if (strpos($xml, '${employer_signature}') !== false || strpos($xml, 'name="Employer signature"') !== false) {
        return $xml;
    }
    $pattern = '/(<w:t(?:\s[^>]*)?>Signature:<\/w:t>)((?:(?!<w:t[\s>]).)*?)(<w:t(?:\s[^>]*)?>)(_+)(<\/w:t><\/w:r>)/s';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen): string {
            $seen++;
            if ($seen !== 1) {
                return $match[0];
            }

            return $match[1] . $match[2]
                . '<w:t>${employer_signature}</w:t></w:r>'
                . '<w:r><w:br/><w:t>${company_stamp}</w:t></w:r>';
        },
        $xml,
        1
    ) ?? $xml;
}

function pcvc_staff_contract_embed_company_signature_and_stamp(string $docxAbs): void
{
    $signature = pcvc_staff_contract_manager_signature_bytes();
    if ($signature !== null) {
        pcvc_staff_contract_embed_image_at_placeholder(
            $docxAbs,
            'employer_signature',
            'employer_signature.png',
            $signature,
            1645920,
            448560
        );
    }
    $stamp = pcvc_staff_contract_company_stamp_bytes();
    if ($stamp !== null) {
        pcvc_staff_contract_embed_image_at_placeholder(
            $docxAbs,
            'company_stamp',
            'company_stamp.png',
            $stamp,
            1143000,
            1122300
        );
    }
}

/**
 * Fill a notice line whether the underscores sit in the label run or the next run.
 */
function pcvc_staff_contract_fill_notice_line(string $xml, string $label, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return $xml;
    }
    $updated = pcvc_staff_contract_fill_underscore_line($xml, $label . ' ', $value, 1);
    if ($updated !== $xml) {
        return $updated;
    }
    $updated = pcvc_staff_contract_fill_underscore_line($xml, $label, $value, 1);
    if ($updated !== $xml) {
        return $updated;
    }
    $updated = pcvc_staff_contract_fill_blank_after_label($xml, $label, $value, 1);
    if ($updated !== $xml) {
        return $updated;
    }

    return pcvc_staff_contract_fill_blank_after_label($xml, rtrim($label), $value, 1);
}

function pcvc_staff_contract_fill_split_email_line(string $xml, string $email): string
{
    $email = trim($email);
    if ($email === '') {
        return $xml;
    }
    $safe = pcvc_staff_contract_xml_text('Email: ' . $email);
    $pattern = '/(<w:t(?:\s[^>]*)?>)Email: _+(<\/w:t>)(.*?)(<w:t(?:\s[^>]*)?>)_+(<\/w:t>)/s';
    $done = false;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$done, $safe): string {
            if ($done) {
                return $match[0];
            }
            $done = true;

            return $match[1] . $safe . $match[2] . $match[3] . $match[4] . $match[5];
        },
        $xml,
        1
    ) ?? $xml;
}

/**
 * @param array<string, mixed> $admin
 */
function pcvc_staff_contract_fill_notice_and_identity(string $docxAbs, array $admin): void
{
    $employer = pcvc_staff_contract_employer_defaults();
    $name = trim((string) ($admin['full_name'] ?? ''));
    $email = trim((string) ($admin['email'] ?? ''));
    $phone = trim((string) ($admin['phone_number'] ?? ''));
    $address = trim((string) ($admin['address'] ?? ''));
    $nationalId = trim((string) ($admin['national_id'] ?? ''));
    $startRaw = trim((string) ($admin['employment_start_date'] ?? ''));
    $start = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw) && $startRaw !== '0000-00-00') {
        $start = date('F j, Y', strtotime($startRaw));
    }
    $phoneEmail = trim($phone . ($phone !== '' && $email !== '' ? ' / ' : '') . $email);
    $signedAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Kigali')))->format('F j, Y g:i A');
    $signingPlace = 'GISOZI, KIGALI RWANDA';
    $employmentType = (string) ($admin['employment_type'] ?? '');

    pcvc_staff_contract_edit_document_xml($docxAbs, static function (string $xml) use (
        $employer,
        $name,
        $email,
        $phone,
        $address,
        $nationalId,
        $start,
        $phoneEmail,
        $signedAt,
        $signingPlace,
        $employmentType
    ): string {
        if (strpos($xml, 'Employee notice details') === false && strpos($xml, 'Witnesses') === false) {
            return $xml;
        }
        $xml = pcvc_staff_contract_remove_witness_block($xml);
        $xml = preg_replace('/(?<!Dr\. )TWAJAMAHORO JEAN PIERRE/', 'Dr. TWAJAMAHORO JEAN PIERRE', $xml) ?? $xml;
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Company registration or TIN', '122190556', 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Registered office', 'GISOZI, KIGALI RWANDA', 1);
        $xml = pcvc_staff_contract_replace_exact_text($xml, 'Kigali-Rwanda', 'GISOZI, KIGALI RWANDA', 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Employee full legal name', $name, 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'National ID or passport number', $nationalId, 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Residential address', $address, 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Telephone and email', $phoneEmail, 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Start date', $start, 1);
        $xml = pcvc_staff_contract_fill_notice_line($xml, 'Address:', $address);
        $xml = pcvc_staff_contract_fill_split_email_line($xml, $email);
        $xml = pcvc_staff_contract_fill_notice_line($xml, 'Email:', $email);
        $xml = pcvc_staff_contract_fill_notice_line($xml, 'Telephone:', $phone);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Authorized representative full name:', (string) $employer['name'], 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Title or capacity:', (string) $employer['position'], 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Employee full legal name:', $name, 1);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'National ID or passport number:', $nationalId, 1);
        $xml = pcvc_staff_contract_mark_employment_status($xml, $employmentType);
        $blankLine = '________________________________';
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Date and time:', $signedAt, 1);
        $xml = pcvc_staff_contract_set_following_text($xml, 'Date and time:', $blankLine, 2);
        $xml = pcvc_staff_contract_fill_blank_after_label($xml, 'Place of signing:', $signingPlace, 1);
        $xml = pcvc_staff_contract_set_following_text($xml, 'Place of signing:', $blankLine, 2);
        $xml = pcvc_staff_contract_clear_company_images_from_employee_signature($xml);
        $xml = pcvc_staff_contract_mark_company_signature_tokens($xml);

        return $xml;
    });
    pcvc_staff_contract_embed_company_signature_and_stamp($docxAbs);
}

function pcvc_staff_contract_remove_witness_block(string $xml): string
{
    $marker = '>Witnesses</w:t>';
    $pos = strpos($xml, $marker);
    if ($pos === false) {
        return $xml;
    }
    $start = false;
    $scan = $pos;
    while ($scan > 0) {
        $found = strrpos(substr($xml, 0, $scan), '<w:p');
        if ($found === false) {
            break;
        }
        $next = $xml[$found + 4] ?? '';
        if ($next === ' ' || $next === '>') {
            $start = $found;
            break;
        }
        $scan = $found;
    }
    $tableEnd = strpos($xml, '</w:tbl>', $pos);
    if ($start === false || $tableEnd === false) {
        return $xml;
    }

    return substr($xml, 0, $start) . substr($xml, $tableEnd + strlen('</w:tbl>'));
}

/**
 * @param array<string, mixed> $admin
 */
function pcvc_staff_contract_apply_employee_signature(
    string $docxAbs,
    array $admin,
    string $signatureDataUrl,
    ?string $signingDate = null,
    ?string $signingPlace = null
): void {
    if (!function_exists('contract_signature_to_display_png')) {
        require_once __DIR__ . '/contract_signature_image.php';
    }
    $png = contract_signature_to_display_png($signatureDataUrl) ?? contract_signature_raw_bytes($signatureDataUrl);
    if ($png === null || $png === '') {
        throw new RuntimeException('Signature image is empty.');
    }

    $dateRaw = trim((string) $signingDate);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
        $dateText = date('F j, Y', strtotime($dateRaw));
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $dateRaw)) {
        $stamp = strtotime($dateRaw);
        $dateText = $stamp !== false ? date('F j, Y g:i A', $stamp) : $dateRaw;
    } else {
        $dateText = $dateRaw;
    }
    $place = trim((string) $signingPlace);

    pcvc_staff_contract_edit_document_xml($docxAbs, static function (string $xml) use ($dateText, $place): string {
        $marker = '>Signature:</w:t>';
        $first = strpos($xml, $marker);
        $second = $first === false ? false : strpos($xml, $marker, $first + strlen($marker));
        if ($second !== false) {
            $head = substr($xml, 0, $second);
            $tail = substr($xml, $second);
            $tail = preg_replace(
                '/(<w:t(?:\s[^>]*)?>)(_+)(<\/w:t>)/',
                '$1${employee_signature}$3',
                $tail,
                1
            ) ?? $tail;
            $xml = $head . $tail;
        }
        $xml = pcvc_staff_contract_set_following_text($xml, 'Date and time:', $dateText, 2);
        if ($place !== '') {
            $xml = pcvc_staff_contract_set_following_text($xml, 'Place of signing:', $place, 2);
        }

        return $xml;
    });

    pcvc_staff_contract_embed_image_at_placeholder(
        $docxAbs,
        'employee_signature',
        'employee_signature.png',
        $png,
        1371600,
        457200
    );
}

function pcvc_staff_contract_mark_signature_token(string $xml, int $occurrence): string
{
    $pattern = '/(<w:t(?:\s[^>]*)?>)Signature: _+(<\/w:t>)/';
    $seen = 0;

    return preg_replace_callback(
        $pattern,
        static function (array $match) use (&$seen, $occurrence): string {
            $seen++;
            if ($seen !== $occurrence) {
                return $match[0];
            }

            return $match[1] . '${witness_signature}' . $match[2];
        },
        $xml
    ) ?? $xml;
}

function pcvc_staff_contract_apply_witness_block(
    string $docxAbs,
    string $name,
    string $nationalId,
    string $contact,
    string $place,
    string $signatureDataUrl
): void {
    if (!function_exists('contract_signature_to_display_png')) {
        require_once __DIR__ . '/contract_signature_image.php';
    }
    $png = contract_signature_to_display_png($signatureDataUrl) ?? contract_signature_raw_bytes($signatureDataUrl);
    if ($png === null || $png === '') {
        throw new RuntimeException('Witness signature image is empty.');
    }

    $when = date('F j, Y g:i A');
    $datePlace = $place !== '' ? $when . ', ' . $place : $when;

    pcvc_staff_contract_edit_document_xml($docxAbs, static function (string $xml) use (
        $name,
        $nationalId,
        $contact,
        $datePlace
    ): string {
        $xml = pcvc_staff_contract_fill_underscore_line($xml, 'Full name: ', $name, 2);
        $xml = pcvc_staff_contract_fill_underscore_line($xml, 'National ID or passport: ', $nationalId, 2);
        $xml = pcvc_staff_contract_fill_underscore_line($xml, 'Telephone or email: ', $contact, 2);
        $xml = pcvc_staff_contract_mark_signature_token($xml, 2);
        $xml = pcvc_staff_contract_fill_underscore_line($xml, 'Date and place: ', $datePlace, 2);

        return $xml;
    });

    pcvc_staff_contract_embed_image_at_placeholder(
        $docxAbs,
        'witness_signature',
        'witness_signature.png',
        $png,
        1371600,
        457200
    );
}

/**
 * @param array<string, mixed> $contract
 */
function pcvc_staff_contract_apply_witness_to_saved_files(
    array $contract,
    string $name,
    string $nationalId,
    string $contact,
    string $place,
    string $signatureDataUrl
): void {
    $paths = [
        trim((string) ($contract['source_docx_path'] ?? '')),
        trim((string) ($contract['filled_docx_path'] ?? '')),
        trim((string) ($contract['signed_docx_path'] ?? '')),
    ];
    $done = [];
    foreach ($paths as $rel) {
        if ($rel === '' || isset($done[$rel])) {
            continue;
        }
        $done[$rel] = true;
        $abs = pcvc_staff_contract_abs_path($rel);
        if (!is_file($abs)) {
            continue;
        }
        pcvc_staff_contract_apply_witness_block($abs, $name, $nationalId, $contact, $place, $signatureDataUrl);
    }
}

function pcvc_staff_contract_witness_by_token(mysqli $conn, string $token): ?array
{
    pcvc_staff_contract_ensure_schema($conn);
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT c.*, a.full_name, a.position, a.email AS staff_email
         FROM employment_contracts c
         INNER JOIN admins a ON a.id = c.admin_id
         WHERE c.witness_token = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * @return array{ok:bool, error?:string, email?:string}
 */
function pcvc_staff_contract_send_witness_email(mysqli $conn, int $staffId, string $witnessEmail): array
{
    pcvc_staff_contract_ensure_schema($conn);
    $witnessEmail = trim($witnessEmail);
    if ($witnessEmail === '' || !filter_var($witnessEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid witness email address'];
    }

    $stmt = $conn->prepare(
        'SELECT a.full_name, a.position, c.id, c.contract_title, c.witness_token, c.witness_signed_at, c.source_docx_path
         FROM admins a
         INNER JOIN employment_contracts c ON c.admin_id = a.id
         WHERE a.id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Database error'];
    }
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || trim((string) ($row['source_docx_path'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'This staff member has no contract to witness'];
    }
    if (trim((string) ($row['witness_signed_at'] ?? '')) !== '') {
        return ['ok' => false, 'error' => 'The witness has already signed this contract'];
    }

    $token = trim((string) ($row['witness_token'] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
    }
    $contractId = (int) $row['id'];
    $save = $conn->prepare('UPDATE employment_contracts SET witness_email = ?, witness_token = ? WHERE id = ? AND admin_id = ?');
    if (!$save) {
        return ['ok' => false, 'error' => 'Database error'];
    }
    $save->bind_param('ssii', $witnessEmail, $token, $contractId, $staffId);
    $save->execute();
    $save->close();

    require_once __DIR__ . '/staff_contract_notify.php';
    $staffName = trim((string) ($row['full_name'] ?? '')) ?: 'the employee';
    $position = trim((string) ($row['position'] ?? ''));
    $title = trim((string) ($row['contract_title'] ?? '')) ?: 'Employment contract';
    $link = rtrim(pcvc_public_base_url(), '/') . '/staff-contract-witness.php?token=' . rawurlencode($token);

    $safeName = htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8');
    $safePosition = htmlspecialchars($position, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $roleLine = $position !== '' ? ' (' . $safePosition . ')' : '';

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px;color:#1e293b;line-height:1.5;">'
        . '<p>Hello,</p>'
        . '<p>You are asked to witness the employment contract of <strong>' . $safeName . '</strong>'
        . $roleLine . '.</p>'
        . '<p>Open the secure page, fill your name, ID, telephone or email, and place of signing, then draw your signature. '
        . 'Nothing is signed until you submit it yourself.</p>'
        . '<p style="margin:24px 0;"><a href="' . $safeLink . '" style="display:inline-block;background:#3661B9;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;">Review and sign as witness</a></p>'
        . '<p style="font-size:14px;color:#475569;">Contract: ' . $safeTitle . '<br>Link: ' . $safeLink . '</p>'
        . '<p style="margin-top:28px;font-size:13px;color:#64748b;">Parrot Canada Visa Consultant</p></div>';

    $text = "You are asked to witness the employment contract of {$staffName}"
        . ($position !== '' ? " ({$position})" : '') . ".\n\n"
        . "Fill your details and draw your signature here:\n{$link}\n";

    try {
        $mail = pcvc_staff_contract_smtp_mailer();
        $mail->addAddress($witnessEmail);
        $mail->Subject = 'Witness signature requested — ' . $staffName;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Witness email could not be sent. Check the address and try again.'];
    }

    return ['ok' => true, 'email' => $witnessEmail];
}

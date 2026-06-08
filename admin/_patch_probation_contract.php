<?php
declare(strict_types=1);

/**
 * Apply standard probation contract placeholders (same as MUTWARE template).
 * Usage: php _patch_probation_contract.php "CONTRACT.docx"
 */

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php _patch_probation_contract.php <path-to-docx>\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    fwrite(STDERR, "Cannot open: $file\n");
    exit(1);
}

$xml = $zip->getFromName('word/document.xml');
if ($xml === false) {
    fwrite(STDERR, "No document.xml in: $file\n");
    exit(1);
}

$original = $xml;

// --- Header / agreement ---
$xml = str_replace(' PCVC-PROB-________', ' PCVC-PROB-${national_id}', $xml);
$xml = str_replace(' __________________________', ' ${signing_date}', $xml);

// Employee details (top section) — order matters for shared underscore lengths.
$xml = preg_replace(
    '/(>Name:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) ______________________________________(<\/w:t>)/',
    '$1 ${full_name}$2',
    $xml,
    1
) ?? $xml;

$xml = preg_replace(
    '/(>Email:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) ______________________________________(<\/w:t>)/',
    '$1 ${email}$2',
    $xml,
    1
) ?? $xml;

$xml = str_replace(' ______________________________', ' ${phone_number}', $xml);
$xml = str_replace('Country Coordinator Assistant', '${position}', $xml);
$xml = str_replace(' ___________________', ' ${probation_end_date}', $xml);
$xml = str_replace(' __________________', ' ${employment_start_date}', $xml);

// --- Employer block (ACCEPTANCE section only) ---
$acceptPos = strpos($xml, 'ACCEPTANCE');
if ($acceptPos !== false) {
    $head = substr($xml, 0, $acceptPos);
    $tail = substr($xml, $acceptPos);

    $tail = str_replace('Name: ____________________________________', 'Name: ${employer_name}', $tail);
    $tail = str_replace('Position: __________________________________', 'Position: ${employer_position}', $tail);

    $employerSigOld = '<w:t>Signature: ________________________________</w:t></w:r></w:p><w:p w:rsidR="00FF4A46" w:rsidRPr="00807A5F" w:rsidRDefault="00FF4A46" w:rsidP="00FF4A46"><w:pPr><w:spacing w:before="100" w:beforeAutospacing="1" w:after="100" w:afterAutospacing="1" w:line="240" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:pPr><w:r w:rsidRPr="00807A5F"><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr><w:t>Date: ____________________________________</w:t>';

    $employerSigNew = '<w:t>Signature:</w:t></w:r></w:p><w:p w:rsidR="00FF4A46" w:rsidRPr="00807A5F" w:rsidRDefault="00FF4A46" w:rsidP="00FF4A46"><w:pPr><w:spacing w:before="100" w:beforeAutospacing="1" w:after="100" w:afterAutospacing="1" w:line="240" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:pPr><w:r w:rsidRPr="00807A5F"><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr><w:t>${employer_signature}</w:t></w:r></w:p><w:p w:rsidR="00FF4A46" w:rsidRPr="00807A5F" w:rsidRDefault="00FF4A46" w:rsidP="00FF4A46"><w:pPr><w:spacing w:before="100" w:beforeAutospacing="1" w:after="100" w:afterAutospacing="1" w:line="240" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:pPr><w:r w:rsidRPr="00807A5F"><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr><w:t>Date: ${employer_date}</w:t>';

    // Replace only first employer signature block (before final EMPLOYEE heading).
    $empPos = strpos($tail, '>EMPLOYEE</w:t>');
    if ($empPos !== false) {
        $beforeEmp = substr($tail, 0, $empPos);
        $afterEmp = substr($tail, $empPos);
        if (str_contains($beforeEmp, $employerSigOld)) {
            $beforeEmp = str_replace($employerSigOld, $employerSigNew, $beforeEmp);
        } else {
            $beforeEmp = preg_replace(
                '/<w:t>Signature: _{20,}<\/w:t>(?=.*?<w:t>Date: _{20,}<\/w:t>)/s',
                '<w:t>Signature:</w:t></w:r></w:p><w:p><w:r><w:t>${employer_signature}</w:t>',
                $beforeEmp,
                1
            ) ?? $beforeEmp;
        }
        $tail = $beforeEmp . $afterEmp;
    }

    $xml = $head . $tail;
}

// --- Employee signature block (last EMPLOYEE section) ---
$employeeStart = strrpos($xml, '>EMPLOYEE</w:t>');
if ($employeeStart !== false) {
    $head = substr($xml, 0, $employeeStart);
    $tail = substr($xml, $employeeStart);

    $tail = str_replace('Employee Name: ___________________________', 'Employee Name: ${full_name}', $tail);

    if (str_contains($tail, 'Signature: ${employee_signature}')) {
        // already patched
    } elseif (str_contains($tail, '<w:t>Signature: ________________________________</w:t>')) {
        $tail = str_replace(
            '<w:t>Signature: ________________________________</w:t>',
            '<w:t>Signature:</w:t></w:r></w:p><w:p w:rsidR="00FF4A46" w:rsidRPr="00807A5F" w:rsidRDefault="00FF4A46" w:rsidP="00FF4A46"><w:pPr><w:spacing w:before="100" w:beforeAutospacing="1" w:after="100" w:afterAutospacing="1" w:line="240" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:pPr><w:r w:rsidRPr="00807A5F"><w:rPr><w:rFonts w:ascii="Times New Roman" w:eastAsia="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr><w:t>${employee_signature}</w:t>',
            $tail
        );
    }

    $tail = str_replace('Date: ____________________________________', 'Date: ${signing_date}', $tail);
    $xml = $head . $tail;
}

// --- NOTARY: ensure blank (no employer placeholders) ---
$notaryPos = strpos($xml, '>NOTARY </w:t>');
if ($notaryPos !== false) {
    $head = substr($xml, 0, $notaryPos);
    $tail = substr($xml, $notaryPos);
    $tail = str_replace('Name: ${employer_name}______', 'Name: __________________________________________', $tail);
    $tail = str_replace('Name: ${employer_name}________', 'Name: __________________________________________', $tail);
    $tail = str_replace('Name: ${employer_name}', 'Name: __________________________________________', $tail);
    $xml = $head . $tail;
}

if ($xml === $original) {
    fwrite(STDERR, "Warning: no changes applied to $file\n");
    exit(2);
}

$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $xml);
$zip->close();

echo "Patched: " . basename($file) . "\n";

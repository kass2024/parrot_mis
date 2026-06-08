<?php
declare(strict_types=1);

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php _apply_probation_placeholders.php <docx>\n");
    exit(1);
}

$employerSnippet = file_get_contents(__DIR__ . '/_mutware_employer_snippet.txt');
$employeeSigSnippet = file_get_contents(__DIR__ . '/_mutware_employee_sig_snippet.txt');
if ($employerSnippet === false || $employeeSigSnippet === false) {
    fwrite(STDERR, "Missing snippet files\n");
    exit(1);
}

$zip = new ZipArchive();
$zip->open($file);
$xml = $zip->getFromName('word/document.xml');

// Agreement reference + header date.
$xml = str_replace(' PCVC-PROB-________', ' PCVC-PROB-${national_id}', $xml);
if (!str_contains($xml, '${national_id}')) {
    $xml = str_replace('PCVC-PROB-________', 'PCVC-PROB-${national_id}', $xml);
}

// Header date line (only first Date: blank after reference).
$xml = preg_replace(
    '/(Agreement Reference:<\/w:t>.*?<w:t[^>]*>Date: )_{10,}(<\/w:t>)/s',
    '$1${signing_date}$2',
    $xml,
    1
) ?? $xml;
$xml = preg_replace(
    '/(PCVC-PROB-\$\{national_id\}<\/w:t>.*?<w:t[^>]*>Date: )_{10,}(<\/w:t>)/s',
    '$1${signing_date}$2',
    $xml,
    1
) ?? $xml;

// Employee profile header (first EMPLOYEE section).
$firstEmployee = strpos($xml, '>EMPLOYEE</w:t>');
if ($firstEmployee !== false) {
    $head = substr($xml, 0, $firstEmployee);
    $tail = substr($xml, $firstEmployee);

    $replacements = [
        '/(>Name:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) \$\{signing_date\}_+(<\/w:t>)/' => '$1 ${full_name}$2',
        '/(>Name:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) _+(<\/w:t>)/' => '$1 ${full_name}$2',
        '/(>Email:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) \$\{signing_date\}_+(<\/w:t>)/' => '$1 ${email}$2',
        '/(>Email:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) _+(<\/w:t>)/' => '$1 ${email}$2',
        '/(>Phone Number:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) \$\{signing_date\}_+(<\/w:t>)/' => '$1 ${phone_number}$2',
        '/(>Phone Number:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) _+(<\/w:t>)/' => '$1 ${phone_number}$2',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $tail = preg_replace($pattern, $replacement, $tail, 1) ?? $tail;
    }

    $tail = str_replace('Country Coordinator Assistant', '${position}', $tail);
    if (!str_contains($tail, '${employment_start_date}')) {
        $tail = preg_replace('/(>Start Date:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) _+(<\/w:t>)/', '$1 ${employment_start_date}$2', $tail, 1) ?? $tail;
    }
    if (!str_contains($tail, '${probation_end_date}')) {
        $tail = preg_replace('/(>End Date:<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>) _+(<\/w:t>)/', '$1 ${probation_end_date}$2', $tail, 1) ?? $tail;
    }

    $xml = $head . $tail;
}

// Employer block in ACCEPTANCE section.
$acceptPos = strpos($xml, 'ACCEPTANCE');
$lastEmployee = strrpos($xml, '>EMPLOYEE</w:t>');
if ($acceptPos !== false && $lastEmployee !== false && $acceptPos < $lastEmployee) {
    $head = substr($xml, 0, $acceptPos);
    $mid = substr($xml, $acceptPos, $lastEmployee - $acceptPos);
    $end = substr($xml, $lastEmployee);

    $mid = preg_replace(
        '/<w:t>Name: (?:\$\{signing_date\}_+|_{10,})<\/w:t>.*?<w:t>Date: (?:\$\{signing_date\}_+|_{10,})<\/w:t>/s',
        $employerSnippet,
        $mid,
        1
    ) ?? $mid;

    $xml = $head . $mid . $end;
}

// Employee signature block (last EMPLOYEE section).
$lastEmployee = strrpos($xml, '>EMPLOYEE</w:t>');
if ($lastEmployee !== false) {
    $head = substr($xml, 0, $lastEmployee);
    $tail = substr($xml, $lastEmployee);

    $tail = preg_replace(
        '/<w:t>Employee Name: (?:\$\{signing_date\}_+|_{5,}|\$\{full_name\})<\/w:t>.*?<w:t>Date: (?:\$\{signing_date\}_+|_{5,}|\$\{signing_date\})<\/w:t>/s',
        $employeeSigSnippet,
        $tail,
        1
    ) ?? $tail;

    if (!str_contains($tail, '${employee_signature}')) {
        $tail = preg_replace(
            '/<w:t>Employee Name: _+<\/w:t>.*?<w:t>Date: _+<\/w:t>/s',
            $employeeSigSnippet,
            $tail,
            1
        ) ?? $tail;
    }

    $xml = $head . $tail;
}

// NOTARY stays blank.
$notaryPos = strpos($xml, '>NOTARY </w:t>');
if ($notaryPos !== false) {
    $head = substr($xml, 0, $notaryPos);
    $tail = substr($xml, $notaryPos);
    $tail = str_replace('Name: ${employer_name}______', 'Name: __________________________________________', $tail);
    $tail = str_replace('Name: ${employer_name}________', 'Name: __________________________________________', $tail);
    $tail = str_replace('Name: ${employer_name}', 'Name: __________________________________________', $tail);
    $tail = str_replace('Name: ${signing_date}________________', 'Name: __________________________________________', $tail);
    $xml = $head . $tail;
}

$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $xml);
$zip->close();

echo 'Applied placeholders: ' . basename($file) . PHP_EOL;

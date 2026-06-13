<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$imageKeys = ['employer_signature', 'employee_signature'];

foreach ($values as $key => $value) {
    if (in_array($key, $imageKeys, true)) continue;
    $xml = str_replace('${' . $key . '}', htmlspecialchars((string)$value, ENT_XML1|ENT_QUOTES, 'UTF-8'), $xml);
}
foreach (pcvc_staff_contract_placeholder_keys() as $key) {
    if ($key === 'full_name') break;
    if (in_array($key, $imageKeys, true) || !isset($values[$key])) continue;
    $safe = (string) $values[$key];
    $prev = '';
    while ($xml !== $prev) {
        $prev = $xml;
        $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
    }
}

// find second full_name in acceptance
$keyWt = '/<w:t(?:\s[^>]*)?>' . preg_quote('full_name', '/') . '<\/w:t>/';
$offset = strpos($xml, 'ACCEPTANCE');
preg_match($keyWt, $xml, $m, PREG_OFFSET_CAPTURE, $offset);
$keyPos = (int) $m[0][1];
$windowStart = max(0, $keyPos - 2500);
$windowEnd = min(strlen($xml), $keyPos + 800);
$window = substr($xml, $windowStart, $windowEnd - $windowStart);
$pattern = pcvc_staff_contract_fragmented_placeholder_pattern('full_name');

preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE);
echo 'matches in window: ' . count($matches[0]) . "\n";
foreach ($matches[0] as $i => $match) {
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $match[0], $t);
    echo "match $i texts: " . implode(' | ', $t[1]) . "\n";
}

$xml2 = pcvc_staff_contract_replace_fragmented_placeholder($xml, 'full_name', 'Parrott Canada');
echo "\nAfter full_name fragmented once more:\n";
echo (strpos($xml2, 'Date:Parrott') !== false ? 'BAD' : 'ok') . "\n";
echo (strpos($xml2, 'Employee Name') !== false ? 'has Employee Name label' : 'no Employee Name') . "\n";

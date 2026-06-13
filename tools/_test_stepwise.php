<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$imageKeys = ['employer_signature', 'employee_signature'];

// Step 1: str_replace all text values
foreach ($values as $key => $value) {
    if (in_array($key, $imageKeys, true)) continue;
    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = str_replace('${' . $key . '}', $safe, $xml);
}

// Step 2: only full_name fragmented passes
$key = 'full_name';
$safe = (string) $values[$key];
$prev = '';
while ($xml !== $prev) {
    $prev = $xml;
    $safeXml = htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = str_replace('${' . $key . '}', $safeXml, $xml);
    $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
}

$p = strpos($xml, 'Dr.');
echo "After full_name only:\n";
echo pcvc_staff_contract_xml_fragment_text(substr($xml, strpos($xml, 'ACCEPTANCE'), 8000)) . "\n";
echo 'employer_name tag=' . (strpos($xml, 'employer_name') !== false ? 'yes' : 'no') . "\n";

// Step 3: employer_name passes
$key = 'employer_name';
$safe = (string) $values[$key];
$prev = '';
while ($xml !== $prev) {
    $prev = $xml;
    $safeXml = htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = str_replace('${' . $key . '}', $safeXml, $xml);
    $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
}

echo "\nAfter employer_name:\n";
echo pcvc_staff_contract_xml_fragment_text(substr($xml, strpos($xml, 'ACCEPTANCE'), 8000)) . "\n";

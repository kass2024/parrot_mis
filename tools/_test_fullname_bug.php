<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$imageKeys = ['employer_signature', 'employee_signature'];

foreach ($values as $key => $value) {
    if (in_array($key, $imageKeys, true)) continue;
    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = str_replace('${' . $key . '}', $safe, $xml);
}

$key = 'full_name';
$safe = (string) $values[$key];
$prev = '';
while ($xml !== $prev) {
    $prev = $xml;
    $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
    $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
}

$p = strpos($xml, 'Dr.');
echo substr($xml, max(0, $p - 300), 2000) . "\n";

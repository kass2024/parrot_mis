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
    if ($key === 'employer_date') break;
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

$before = $xml;
$safe = (string) $values['employer_date'];
echo 'employer_date value=[' . $safe . "]\n";
$prev = '';
while ($xml !== $prev) {
    $prev = $xml;
    $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, 'employer_date', $safe);
    $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, 'employer_date', $safe);
    $xml = pcvc_staff_contract_replace_split_placeholder($xml, 'employer_date', $safe);
}
echo 'changed=' . ($xml !== $before ? 'yes' : 'no') . "\n";
$p = strpos($xml, 'ACCEPTANCE');
echo (strpos(substr($xml,$p,12000), 'Parrott Canada') !== false ? 'has Parrott' : 'no') . "\n";
echo (strpos(substr($xml,$p,12000), 'June 13') !== false ? 'has June' : 'no') . "\n";

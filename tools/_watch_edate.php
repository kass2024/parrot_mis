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
    if (in_array($key, $imageKeys, true) || !isset($values[$key])) continue;
    $safe = (string) $values[$key];
    $prev = '';
    while ($xml !== $prev) {
        $prev = $xml;
        $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
    }
    if ($key === 'employer_date') {
        $p = strpos($xml, 'Date:');
        while ($p !== false) {
            if ($p > strpos($xml, 'ACCEPTANCE') && $p < strpos($xml, 'NOTARY')) {
                echo substr($xml, $p, 80) . "\n";
            }
            $p = strpos($xml, 'Date:', $p + 1);
        }
    }
}

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

$stopBefore = 'employer_date';
foreach (pcvc_staff_contract_placeholder_keys() as $key) {
    if ($key === $stopBefore) break;
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

echo "Before employer_date:\n";
$p = strpos($xml, 'employer_date');
echo substr($xml, max(0, $p - 200), 600) . "\n\n";

$safe = (string) $values['employer_date'];
$xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, 'employer_date', $safe);
$p = strpos($xml, 'Date:');
while ($p !== false && $p < strpos($xml, 'NOTARY')) {
    if ($p > strpos($xml, 'ACCEPTANCE')) {
        echo "After employer_date fragmented: " . substr($xml, $p, 120) . "\n";
        break;
    }
    $p = strpos($xml, 'Date:', $p + 1);
}

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($xml, 'Dr.');
if ($p !== false) {
    echo substr($xml, $p - 200, 1200) . "\n";
}

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$fixed = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);

$p2 = strpos($fixed, 'Dr.');
if ($p2 !== false) {
    echo "\n--- AFTER ---\n";
    echo substr($fixed, $p2 - 200, 1200) . "\n";
}

echo "\nTWAJAMAHORO=" . (strpos($fixed, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";
echo "employer_=" . (strpos($fixed, 'employer_') !== false ? 'yes' : 'no') . "\n";
echo "name}=" . (strpos($fixed, 'name}') !== false ? 'yes' : 'no') . "\n";

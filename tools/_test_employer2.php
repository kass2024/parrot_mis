<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$pos = 0;
$n = 0;
while (($p = strpos($xml, 'employer_name', $pos)) !== false) {
    echo '--- occ ' . (++$n) . " at $p ---\n";
    echo substr($xml, max(0, $p - 500), $p - max(0, $p - 500) + 200) . "\n";
    $pos = $p + 1;
}

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
echo 'employer_name value=[' . $values['employer_name'] . "]\n";

$fixed = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);
$pos = 0;
while (($p = strpos($fixed, 'employer_name', $pos)) !== false) {
    echo "AFTER still employer_name at $p\n";
    echo substr($fixed, max(0, $p - 300), 600) . "\n";
    $pos = $p + 1;
}
echo (strpos($fixed, 'TWAJAMAHORO') !== false ? 'HAS TWAJAMAHORO' : 'NO TWAJAMAHORO') . "\n";
echo (strpos($fixed, '${employer_name}') !== false ? 'HAS literal placeholder' : 'no literal placeholder') . "\n";

$p = strpos($fixed, 'ACCEPTANCE');
if ($p !== false) {
    echo "\nACCEPTANCE section:\n";
    echo substr($fixed, $p, 2000) . "\n";
}

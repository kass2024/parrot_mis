<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_final.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
foreach (['June 13, 2026', 'employer_date', 'signing_date', 'Parrott Canada', 'Employee Name'] as $n) {
    echo "$n: " . (strpos($x, $n) !== false ? 'yes' : 'no') . "\n";
}
$p = strpos($x, 'EMPLOYEE');
echo "\n" . pcvc_staff_contract_xml_fragment_text(substr($x, $p, 5000)) . "\n";

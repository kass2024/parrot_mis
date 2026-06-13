<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_test2.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
foreach (['ACCEPTANCE', 'Dr.', 'TWAJAMAHORO', 'Managing director', 'employer_date', 'June 13'] as $needle) {
    $p = strpos($x, $needle);
    echo "$needle at " . ($p === false ? 'MISSING' : $p) . "\n";
}
$p = strpos($x, 'ACCEPTANCE');
if ($p !== false) {
    echo "\n" . pcvc_staff_contract_xml_fragment_text(substr($x, $p, 15000)) . "\n";
}

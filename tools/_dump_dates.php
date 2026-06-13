<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_final.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$pos = 0;
while (($p = strpos($x, 'Date:', $pos)) !== false) {
    if ($p > strpos($x, 'ACCEPTANCE')) {
        echo substr($x, $p, 500) . "\n---\n";
    }
    $pos = $p + 1;
}

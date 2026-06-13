<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_final.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($x, 'ACCEPTANCE');
$chunk = substr($x, $p, 15000);
if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $chunk, $m)) {
    foreach ($m[1] as $t) {
        $t = trim($t);
        if ($t !== '') {
            echo "[$t]\n";
        }
    }
}

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_textonly.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, 'ACCEPTANCE');
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', substr($x, $p, 20000), $m);
foreach ($m[1] as $t) {
    $t = trim($t);
    if ($t !== '') echo "[$t]\n";
}

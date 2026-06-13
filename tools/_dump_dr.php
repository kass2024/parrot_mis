<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_test2.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, 'Dr.');
echo substr($x, max(0, $p - 500), 4000);

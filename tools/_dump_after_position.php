<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/_trace_full.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, 'Managing director');
echo substr($x, $p, 3500);

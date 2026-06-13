<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($xml, 'employer_name');
echo substr($xml, max(0, $p - 800), 1600) . "\n\n";

// Count w:p tags before employer_name
$before = substr($xml, 0, $p);
echo 'w:p opens before: ' . substr_count($before, '<w:p') . "\n";
echo 'w:p closes before: ' . substr_count($before, '</w:p>') . "\n";

// Is employer_name inside unclosed paragraph?
$diff = substr_count($before, '<w:p') - substr_count($before, '</w:p>');
echo 'unclosed p depth: ' . $diff . "\n";

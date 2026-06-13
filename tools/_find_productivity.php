<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $i => $p) {
    $text = pcvc_staff_contract_paragraph_text($p);
    if (stripos($text, 'Productivity') !== false) {
        echo "$i: $text\n";
    }
}

<?php
$ref = dirname(__DIR__) . '/uploads/staff_contracts/generated/mutware_test.docx';
$z = new ZipArchive();
$z->open($ref);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $i => $p) {
    if (strpos($p, 'lastRenderedPageBreak') === false) {
        continue;
    }
    $text = '';
    if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $p, $t)) {
        $text = trim(html_entity_decode(implode('', $t[1])));
    }
    $inList = strpos($p, '<w:numPr>') !== false ? 'LIST' : 'body';
    echo "para $i [$inList]: $text\n";
}

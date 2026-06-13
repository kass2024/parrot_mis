<?php
$ref = dirname(__DIR__) . '/uploads/staff_contracts/generated/mutware_test.docx';
$z = new ZipArchive();
$z->open($ref);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $i => $p) {
    $text = '';
    if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $p, $t)) {
        $text = trim(html_entity_decode(implode('', $t[1])));
    }
    if ($i >= 95 && $i <= 145) {
        $mark = '';
        if (strpos($p, 'lastRenderedPageBreak') !== false) {
            $mark = ' [BREAK]';
        }
        echo "$i$mark: " . mb_substr($text, 0, 70) . "\n";
    }
}

<?php
$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $i => $p) {
    $text = '';
    if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $p, $t)) {
        $text = trim(html_entity_decode(implode('', $t[1])));
    }
    if ($text === '') {
        continue;
    }
    $style = '';
    if (preg_match('/w:val="([^"]+)"/', $p, $s) && strpos($p, 'pStyle') !== false) {
        $style = ' [' . $s[1] . ']';
    }
    $bold = strpos($p, '<w:b/>') !== false || strpos($p, '<w:b w:val="true"/>') !== false ? '*' : ' ';
    echo sprintf("%3d%s %s\n", $i, $bold, mb_substr($text, 0, 90));
}

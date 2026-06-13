<?php
$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

echo 'xml_len=' . strlen($xml) . PHP_EOL;
echo 'paras=' . substr_count($xml, '<w:p ') . PHP_EOL;
echo 'lastRenderedPageBreak=' . substr_count($xml, 'lastRenderedPageBreak') . PHP_EOL;
echo 'hard_page=' . substr_count($xml, 'w:type="page"') . PHP_EOL;
echo 'sectPr=' . substr_count($xml, 'w:sectPr') . PHP_EOL;

// Page size from first sectPr
if (preg_match('/<w:pgSz[^>]+w:w="(\d+)"[^>]+w:h="(\d+)"/', $xml, $m)) {
    echo 'page_twips=' . $m[1] . 'x' . $m[2] . PHP_EOL;
}
if (preg_match('/<w:pgMar[^>]+w:top="(\d+)"[^>]+w:right="(\d+)"[^>]+w:bottom="(\d+)"[^>]+w:left="(\d+)"/', $xml, $m)) {
    echo 'margins=' . implode(',', $m) . PHP_EOL;
}

// Positions of page breaks
$offset = 0;
$i = 0;
while (($p = strpos($xml, 'lastRenderedPageBreak', $offset)) !== false) {
    $snippet = substr($xml, max(0, $p - 80), 200);
    $text = '';
    if (preg_match('/<w:t[^>]*>([^<]*)<\/w:t>/', substr($xml, $p, 300), $t)) {
        $text = $t[1];
    }
    echo "break#$i at $p near: " . trim(preg_replace('/<[^>]+>/', '', $snippet)) . " -> " . $text . PHP_EOL;
    $offset = $p + 1;
    $i++;
}

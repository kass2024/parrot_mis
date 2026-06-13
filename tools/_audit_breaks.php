<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $i => $p) {
    if (strpos($p, 'w:type="page"') === false) {
        continue;
    }
    $text = pcvc_staff_contract_paragraph_text($p);
    echo "break para $i: [$text]\n";
    // show next para text
    if (isset($m[0][$i + 1])) {
        echo '  next: ' . mb_substr(pcvc_staff_contract_paragraph_text($m[0][$i + 1]), 0, 80) . "\n";
    }
}

// find duplicate anchor matches
foreach (pcvc_staff_contract_page_break_anchor_texts() as $anchor) {
    $count = 0;
    foreach ($m[0] as $p) {
        $text = pcvc_staff_contract_paragraph_text($p);
        if ($text !== '' && strpos($text, $anchor) !== false) {
            $count++;
        }
    }
    if ($count !== 1) {
        echo "ANCHOR '$anchor' matches $count times\n";
    }
}

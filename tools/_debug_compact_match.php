<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = pcvc_staff_contract_apply_page_break_layout((string) $z->getFromName('word/document.xml'));
$z->close();

$pattern = '#</w:p>\s*<w:p\b[^>]*>(?:\s*<w:pPr\b[^>]*>.*?</w:pPr>)?\s*<w:r[^>]*>\s*(?:<w:lastRenderedPageBreak\s*/>\s*)?<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>#s';

if (preg_match($pattern, $xml, $m, PREG_OFFSET_CAPTURE)) {
    echo 'match len=' . strlen($m[0][0]) . ' at=' . $m[0][1] . "\n";
    echo substr($m[0][0], 0, 200) . "\n...\n";
    echo substr($m[0][0], -200) . "\n";
}

$pattern2 = '#</w:p>\s*<w:p\b[^>]*>\s*<w:r[^>]*>\s*<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>#s';
if (preg_match($pattern2, $xml, $m2, PREG_OFFSET_CAPTURE)) {
    echo "\nsimple match len=" . strlen($m2[0][0]) . ' at=' . $m2[0][1] . "\n";
    echo $m2[0][0] . "\n";
}

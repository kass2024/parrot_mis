<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = pcvc_staff_contract_apply_page_break_layout((string) $z->getFromName('word/document.xml'));
$z->close();

$pattern = '#</w:p>\s*<w:p\b[^>]*>(?:\s*<w:pPr\b[^>]*>.*?</w:pPr>)?\s*<w:r[^>]*>\s*(?:<w:lastRenderedPageBreak\s*/>\s*)?<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>#s';

$count = preg_match_all($pattern, $xml, $matches);
echo "matches=$count\n";
foreach ($matches[0] as $idx => $m) {
    echo "match $idx len=" . strlen($m) . "\n";
}

$out = preg_replace($pattern, '<w:r><w:br w:type="page"/></w:r></w:p>', $xml) ?? $xml;
echo 'out len=' . strlen($out) . ' hard=' . substr_count($out, 'w:type="page"') . "\n";
libxml_use_internal_errors(true);
echo simplexml_load_string($out) ? "valid\n" : "INVALID\n";

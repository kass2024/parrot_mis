<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$zip = new ZipArchive();
$zip->open($tpl);
$xml = (string) $zip->getFromName('word/document.xml');
$zip->close();

// Strip all existing hard/soft page breaks, then inject canonical set once.
$xml = preg_replace('/<w:p><w:r><w:br w:type="page"\/><\/w:r><\/w:p>/', '', $xml) ?? $xml;
$xml = preg_replace('/<w:lastRenderedPageBreak\s*\/>/', '', $xml) ?? $xml;
$xml = pcvc_staff_contract_apply_page_break_layout($xml);

$zip = new ZipArchive();
$zip->open($tpl);
$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $xml);
$zip->close();

echo 'pages=' . pcvc_staff_contract_expected_page_count_from_xml($xml) . PHP_EOL;
echo 'hard=' . substr_count($xml, 'w:type="page"') . PHP_EOL;

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$zip = new ZipArchive();
if ($zip->open($tpl) !== true) {
    fwrite(STDERR, "Cannot open template\n");
    exit(1);
}
$xml = (string) $zip->getFromName('word/document.xml');
$before = substr_count($xml, 'lastRenderedPageBreak');
$fixed = pcvc_staff_contract_clean_docx_layout_in_xml($xml);
$after = substr_count($fixed, 'lastRenderedPageBreak');
$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $fixed);
$zip->close();
echo "Template cleaned: lastRenderedPageBreak $before -> $after\n";

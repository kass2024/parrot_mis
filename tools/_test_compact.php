<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

echo 'before hard=' . substr_count($xml, 'w:type="page"') . ' len=' . strlen($xml) . "\n";

$step1 = pcvc_staff_contract_apply_page_break_layout($xml);
echo 'after layout hard=' . substr_count($step1, 'w:type="page"') . ' len=' . strlen($step1) . "\n";

$step2 = pcvc_staff_contract_compact_page_break_paragraphs($step1);
echo 'after compact hard=' . substr_count($step2, 'w:type="page"') . ' len=' . strlen($step2) . "\n";
libxml_use_internal_errors(true);
echo simplexml_load_string($step2) ? "valid\n" : "INVALID\n";

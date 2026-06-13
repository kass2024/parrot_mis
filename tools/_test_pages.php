<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

echo 'hard=' . substr_count($xml, 'w:type="page"') . ' expected=' . pcvc_staff_contract_expected_page_count_from_xml($xml) . PHP_EOL;

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_pagecheck2.docx';
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99', 'employment_start_date' => '2024-06-01'];
pcvc_staff_contract_fill_docx($tpl, $out, $admin, '2026-06-13', null);
echo 'filled pages=' . pcvc_staff_contract_expected_page_count($out) . PHP_EOL;

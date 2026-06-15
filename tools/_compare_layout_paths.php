<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-15', 'data:image/png;base64,abc');
$imageKeys = ['employer_signature', 'employee_signature'];

$merged = pcvc_staff_contract_apply_placeholder_values($xml, $values, $imageKeys);
echo 'after merge only: breaks=' . substr_count($merged, 'w:type="page"') . ' lrb=' . substr_count($merged, 'lastRenderedPageBreak') . "\n";

$cleanOnly = pcvc_staff_contract_clean_docx_layout_in_xml($merged);
echo 'after clean only: breaks=' . substr_count($cleanOnly, 'w:type="page"') . ' lrb=' . substr_count($cleanOnly, 'lastRenderedPageBreak') . "\n";

$full = pcvc_staff_contract_apply_page_break_layout($merged);
echo 'after full layout: breaks=' . substr_count($full, 'w:type="page"') . ' lrb=' . substr_count($full, 'lastRenderedPageBreak') . "\n";

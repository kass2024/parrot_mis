<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$imageKeys = ['employer_signature', 'employee_signature'];

$merged = pcvc_staff_contract_apply_placeholder_values($xml, $values, $imageKeys);
$p = strpos($merged, 'ACCEPTANCE');
echo "After merge only:\n";
echo (strpos(substr($merged,$p,12000), 'Date:Parrott') !== false ? 'BAD' : 'ok') . "\n";
echo (strpos(substr($merged,$p,12000), 'June 13') !== false ? 'June ok' : 'no June') . "\n";

$layout = pcvc_staff_contract_apply_page_break_layout($merged);
$p2 = strpos($layout, 'ACCEPTANCE');
echo "After page layout:\n";
echo (strpos(substr($layout,$p2,12000), 'Date:Parrott') !== false ? 'BAD' : 'ok') . "\n";
echo (strpos(substr($layout,$p2,12000), 'June 13') !== false ? 'June ok' : 'no June') . "\n";

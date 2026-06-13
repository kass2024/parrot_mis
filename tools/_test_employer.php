<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($xml, 'employer_name');
echo substr($xml, max(0, $p - 400), 900) . "\n\n";
echo 'has_literal=' . (strpos($xml, '${employer_name}') !== false ? 'yes' : 'no') . "\n";
echo 'hard_breaks=' . substr_count($xml, 'w:type="page"') . "\n";

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$fixed = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);
echo 'after_merge_literal=' . (strpos($fixed, '${employer_name}') !== false ? 'FAIL' : 'ok') . "\n";
echo 'employer_in=' . (strpos($fixed, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_emp_test2.docx';
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);
$z2 = new ZipArchive();
$z2->open($out);
$x2 = (string) $z2->getFromName('word/document.xml');
$z2->close();
echo 'filled_breaks=' . substr_count($x2, 'w:type="page"') . ' pages=' . pcvc_staff_contract_expected_page_count_from_xml($x2) . "\n";
$p2 = strpos($x2, 'employer_name');
if ($p2 !== false) echo "STILL HAS employer_name placeholder\n";
else echo "employer_name replaced\n";
$p3 = strpos($x2, 'TWAJAMAHORO');
echo 'name_present=' . ($p3 !== false ? 'yes' : 'no') . "\n";

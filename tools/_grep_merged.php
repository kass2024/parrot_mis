<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$merged = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);

echo 'whole doc Date:Parrott=' . (strpos($merged, 'Date:Parrott') !== false ? 'yes' : 'no') . "\n";
echo 'whole doc June 13=' . (strpos($merged, 'June 13, 2026') !== false ? 'yes' : 'no') . "\n";
echo 'whole doc employer_date tag=' . (strpos($merged, 'employer_date') !== false ? 'yes' : 'no') . "\n";
echo 'whole doc signing_date tag=' . (strpos($merged, 'signing_date') !== false ? 'yes' : 'no') . "\n";
echo 'whole doc Employee Name=' . (strpos($merged, 'Employee Name') !== false ? 'yes' : 'no') . "\n";

$p = strpos($merged, 'Date:Parrott');
if ($p !== false) echo "context: " . substr($merged, max(0,$p-100), 300) . "\n";

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_test2.docx';
@unlink($out);
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

$z = new ZipArchive();
$z->open($out);
$filled = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($filled, 'ACCEPTANCE');
$chunk = $p !== false ? substr($filled, $p, 12000) : '';
$text = pcvc_staff_contract_xml_fragment_text($chunk);
echo $text . "\n\n";

$bad = [];
foreach (['${', 'employer_name', 'employer_position', 'employer_date', 'employer_signature', 'employee_signature', 'full_name', 'signing_date', 'name}'] as $n) {
    if (strpos($filled, $n) !== false) {
        $bad[] = $n;
    }
}
echo $bad ? 'FAIL leftover: ' . implode(', ', $bad) : "ALL PLACEHOLDERS CLEARED\n";
echo 'TWAJAMAHORO=' . (strpos($filled, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";
echo 'Managing director=' . (strpos($filled, 'Managing director') !== false ? 'yes' : 'no') . "\n";
echo 'Parrott Canada=' . (strpos($filled, 'Parrott Canada') !== false ? 'yes' : 'no') . "\n";
echo 'pages=' . pcvc_staff_contract_expected_page_count($out) . "\n";
echo 'has employer image=' . (strpos($filled, 'employer_signature.png') !== false || strpos($filled, 'wp:inline') !== false ? 'yes' : 'no') . "\n";

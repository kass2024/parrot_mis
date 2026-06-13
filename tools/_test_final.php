<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_final.docx';
@unlink($out);
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

$z = new ZipArchive();
$z->open($out);
$filled = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($filled, 'ACCEPTANCE');
echo pcvc_staff_contract_xml_fragment_text(substr($filled, $p, 15000)) . "\n\n";

$bad = [];
foreach (['${', 'employer_name', 'employer_position', 'employer_date', 'employer_signature', 'employee_signature', 'full_name', 'signing_date'] as $n) {
    if (strpos($filled, $n) !== false) {
        $bad[] = $n;
    }
}
echo $bad ? 'FAIL: ' . implode(', ', $bad) : "PASS all cleared\n";
echo 'TWAJAMAHORO=' . (strpos($filled, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";
echo 'pages=' . pcvc_staff_contract_expected_page_count($out) . "\n";

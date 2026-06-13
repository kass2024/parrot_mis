<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$merged = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);
$p = strpos($merged, 'ACCEPTANCE');
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', substr($merged, $p, 20000), $m);
echo "apply_placeholder_values:\n";
foreach ($m[1] as $t) {
    $t = trim($t);
    if ($t !== '') echo "[$t]\n";
}

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_textonly2.docx';
@unlink($out);
copy(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out);
pcvc_staff_contract_fill_docx_text($out, $values);

$z2 = new ZipArchive();
$z2->open($out);
$x2 = (string) $z2->getFromName('word/document.xml');
$z2->close();
$p2 = strpos($x2, 'ACCEPTANCE');
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', substr($x2, $p2, 20000), $m2);
echo "\nfill_docx_text:\n";
foreach ($m2[1] as $t) {
    $t = trim($t);
    if ($t !== '') echo "[$t]\n";
}

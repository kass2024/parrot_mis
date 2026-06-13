<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_step.docx';
@unlink($out);
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

// Also test text-only without signatures
$out2 = dirname(__DIR__) . '/uploads/staff_contracts/generated/_textonly.docx';
@unlink($out2);
copy(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out2);
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
pcvc_staff_contract_fill_docx_text($out2, $values);

foreach (['_textonly.docx' => 'text only', '_step.docx' => 'full fill'] as $file => $label) {
    $z = new ZipArchive();
    $z->open(dirname(__DIR__) . '/uploads/staff_contracts/generated/' . $file);
    $x = (string) $z->getFromName('word/document.xml');
    $z->close();
    $p = strpos($x, 'ACCEPTANCE');
    $slice = substr($x, $p, 12000);
    echo "$label: ";
    echo (strpos($slice, 'Date:Parrott') !== false ? 'BAD Date:Parrott ' : '');
    echo (strpos($slice, 'June 13') !== false ? 'June OK ' : 'no June ');
    echo (strpos($slice, 'TWAJAMAHORO') !== false ? 'employer OK ' : 'no employer ');
    echo "\n";
}

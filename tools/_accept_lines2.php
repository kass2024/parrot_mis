<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_trace_full.docx';
@unlink($out);
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

$z = new ZipArchive();
$z->open($out);
$x = (string) $z->getFromName('word/document.xml');
$z->close();

$p = strpos($x, 'ACCEPTANCE');
$slice = substr($x, $p, 20000);
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $slice, $m);
foreach ($m[1] as $t) {
    $t = trim($t);
    if ($t !== '') echo "[$t]\n";
}

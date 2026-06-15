<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_signed_date_check.docx';
@unlink($out);
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$dataUrl = 'data:image/png;base64,' . base64_encode($png);
pcvc_staff_contract_fill_docx(
    dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx',
    $out,
    $admin,
    '2026-06-13',
    $dataUrl
);

$z = new ZipArchive();
$z->open($out);
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, '15. ACCEPTANCE');
if ($p === false) {
    $p = strpos($x, 'ACCEPTANCE');
}
$s = substr($x, $p, 25000);
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $s, $m);
foreach ($m[1] as $t) {
    $t = trim($t);
    if ($t !== '') {
        echo "[$t]\n";
    }
}

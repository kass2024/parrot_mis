<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_signed_test.docx';
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

libxml_use_internal_errors(true);
$ok = simplexml_load_string($x) !== false;
echo 'signed with both sigs: ' . ($ok ? 'valid' : 'INVALID') . "\n";
echo 'pages=' . pcvc_staff_contract_expected_page_count($out) . "\n";

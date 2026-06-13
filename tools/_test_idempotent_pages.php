<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_idem_test.docx';
@unlink($out);

pcvc_staff_contract_fill_docx(
    dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx',
    $out,
    $admin,
    '2026-06-13',
    null
);

$z = new ZipArchive();
$z->open($out);
$xml1 = (string) $z->getFromName('word/document.xml');
$z->close();
$c1 = substr_count($xml1, 'w:type="page"');
$p1 = pcvc_staff_contract_expected_page_count_from_xml($xml1);

// Simulate re-merge on already-filled docx (regenerate path)
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$xml2 = pcvc_staff_contract_apply_placeholder_values($xml1, $values, ['employer_signature', 'employee_signature']);
$xml2 = pcvc_staff_contract_apply_page_break_layout($xml2);
$c2 = substr_count($xml2, 'w:type="page"');
$p2 = pcvc_staff_contract_expected_page_count_from_xml($xml2);

echo "pass1 breaks=$c1 pages=$p1\n";
echo "pass2 breaks=$c2 pages=$p2\n";
echo "employer=" . (strpos($xml2, 'TWAJAMAHORO') !== false ? 'ok' : 'FAIL') . "\n";
echo ($c1 === 8 && $c2 === 8 && $p1 === 9 && $p2 === 9) ? "IDEMPOTENT OK\n" : "IDEMPOTENT FAIL\n";

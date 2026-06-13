<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function check_xml(string $xml, string $label): void {
    libxml_use_internal_errors(true);
    $ok = simplexml_load_string($xml) !== false;
    echo "$label: " . ($ok ? 'valid' : 'INVALID') . "\n";
    if (!$ok) {
        foreach (array_slice(libxml_get_errors(), 0, 3) as $e) {
            echo "  " . trim($e->message) . " col {$e->column}\n";
        }
    }
}

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_sig_test.docx';
@unlink($out);
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

$z = new ZipArchive();
$z->open($out);
$x = (string) $z->getFromName('word/document.xml');
$z->close();
check_xml($x, 'full fill with signatures');

// employer sig only
$out2 = dirname(__DIR__) . '/uploads/staff_contracts/generated/_sig_emp.docx';
copy($out, $out2);
// rebuild text only then add employer sig
$z3 = new ZipArchive();
$z3->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z3->getFromName('word/document.xml');
$z3->close();
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$out3 = dirname(__DIR__) . '/uploads/staff_contracts/generated/_text_then_sig.docx';
copy(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out3);
pcvc_staff_contract_fill_docx_text($out3, $values);
pcvc_staff_contract_apply_signature_images($out3, null);
$z4 = new ZipArchive();
$z4->open($out3);
$x4 = (string) $z4->getFromName('word/document.xml');
$z4->close();
check_xml($x4, 'text + employer sig only');

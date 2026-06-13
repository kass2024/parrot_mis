<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_xml_validate.docx';
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
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

libxml_use_internal_errors(true);
$doc = simplexml_load_string($xml);
if ($doc === false) {
    echo "INVALID XML\n";
    foreach (libxml_get_errors() as $err) {
        echo trim($err->message) . " (line {$err->line}, col {$err->column})\n";
    }
} else {
    echo "XML valid\n";
}

// count tag balance
foreach (['w:p', 'w:r', 'w:t'] as $tag) {
    $open = substr_count($xml, "<$tag") + substr_count($xml, "<$tag ");
    $close = substr_count($xml, "</$tag>");
    echo "$tag open~$open close=$close\n";
}

// find mismatch near column 101228 if exists
$col = 101228;
if (strlen($xml) > $col) {
    echo "\nContext at col $col:\n" . substr($xml, max(0, $col - 200), 400) . "\n";
}

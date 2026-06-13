<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function check_xml(string $xml, string $label): void {
    libxml_use_internal_errors(true);
    $ok = simplexml_load_string($xml) !== false;
    echo "$label: " . ($ok ? 'valid' : 'INVALID') . "\n";
    if (!$ok) {
        $e = libxml_get_errors()[0] ?? null;
        if ($e) echo "  {$e->message} line {$e->line} col {$e->column}\n";
    }
    echo "  w:p " . substr_count($xml, '<w:p') . '/' . substr_count($xml, '</w:p>') . "\n";
    echo "  w:r " . substr_count($xml, '<w:r') . '/' . substr_count($xml, '</w:r>') . "\n";
}

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
check_xml($xml, 'template');

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$merged = pcvc_staff_contract_apply_placeholder_values($xml, $values, ['employer_signature', 'employee_signature']);
check_xml($merged, 'after text merge');

$layout = pcvc_staff_contract_apply_page_break_layout($merged);
check_xml($layout, 'after page layout');

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_textonly3.docx';
@unlink($out);
copy(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out);
pcvc_staff_contract_fill_docx_text($out, $values);
$z2 = new ZipArchive();
$z2->open($out);
$x2 = (string) $z2->getFromName('word/document.xml');
$z2->close();
check_xml($x2, 'fill_docx_text');

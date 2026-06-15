<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_bench.docx';
@unlink($out);

$admin = [
    'id' => 114,
    'full_name' => 'Parrott Canada',
    'position' => 'CEO',
    'national_id' => '99',
    'email' => 'test@example.com',
];

function bench(string $label, callable $fn): void
{
    $t0 = microtime(true);
    $fn();
    $ms = round((microtime(true) - $t0) * 1000);
    echo "$label: {$ms}ms\n";
}

bench('full fill_docx', static function () use ($tpl, $out, $admin) {
    pcvc_staff_contract_fill_docx($tpl, $out, $admin, null, null);
});

$z = new ZipArchive();
$z->open($tpl);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$values = pcvc_staff_contract_merge_values($admin, null, null);
$imageKeys = ['employer_signature', 'employee_signature'];

bench('apply_placeholder_values', static function () use ($xml, $values, $imageKeys) {
    pcvc_staff_contract_apply_placeholder_values($xml, $values, $imageKeys);
});

bench('apply_page_break_layout', static function () use ($xml) {
    pcvc_staff_contract_apply_page_break_layout($xml);
});

bench('clean_docx_layout_in_xml', static function () use ($xml) {
    pcvc_staff_contract_clean_docx_layout_in_xml($xml);
});

bench('inject_page_breaks_in_xml', static function () use ($xml) {
    pcvc_staff_contract_inject_page_breaks_in_xml($xml);
});

foreach (['position', 'email', 'role', 'address', 'full_name'] as $key) {
    $n = preg_match_all('/<w:t(?:\s[^>]*)?>' . preg_quote($key, '/') . '<\/w:t>/', $xml, $m);
    echo "w:t>$key< occurrences: $n\n";
}

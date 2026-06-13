<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
$imageKeys = ['employer_signature', 'employee_signature'];

function show_accept(string $xml, string $label): void {
    $p = strpos($xml, 'ACCEPTANCE');
    $slice = substr($xml, $p, 20000);
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $slice, $m);
    $lines = array_values(array_filter(array_map('trim', $m[1])));
    echo "$label: " . implode(' | ', array_slice($lines, 0, 15)) . "\n";
}

$xml = pcvc_staff_contract_apply_placeholder_values($xml, $values, $imageKeys);
show_accept($xml, 'full apply_placeholder_values');

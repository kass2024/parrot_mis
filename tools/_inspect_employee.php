<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function show_employee_section(string $path, string $label): void
{
    $z = new ZipArchive();
    $z->open($path);
    $x = (string) $z->getFromName('word/document.xml');
    $z->close();
    $p = strpos($x, 'EMPLOYEE');
    $s = substr($x, $p, 12000);
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $s, $m);
    echo "=== $label ===\n";
    foreach ($m[1] as $t) {
        $t = trim($t);
        if ($t !== '') {
            echo "[$t]\n";
        }
    }
    echo "employee_signature in xml: " . (strpos($x, 'employee_signature') !== false ? 'yes' : 'no') . "\n";
    echo "drawing after EMPLOYEE: " . (strpos($s, 'w:drawing') !== false ? 'yes' : 'no') . "\n\n";
}

$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_inspect.docx';
@unlink($out);
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
pcvc_staff_contract_fill_docx(
    dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx',
    $out,
    $admin,
    null,
    null
);

show_employee_section(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', 'template');
show_employee_section($out, 'filled preview');

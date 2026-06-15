<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function acceptance_lines(string $path): void
{
    $z = new ZipArchive();
    $z->open($path);
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
    echo "--- drawing: " . (strpos($s, 'w:drawing') !== false ? 'yes' : 'no') . " ---\n\n";
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

echo "TEMPLATE acceptance:\n";
acceptance_lines(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
echo "FILLED acceptance:\n";
acceptance_lines($out);

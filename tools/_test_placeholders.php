<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$anchors = ['employer_name', 'employer_position', 'employer_date', 'employer_signature', 'full_name', 'employee_signature', 'signing_date'];
foreach ($anchors as $key) {
    echo "\n=== $key ===\n";
    $pos = 0;
    $n = 0;
    while (($p = strpos($xml, $key, $pos)) !== false && $n < 3) {
        echo substr($xml, max(0, $p - 350), 700) . "\n---\n";
        $pos = $p + 1;
        $n++;
    }
}

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_ph_test.docx';
@unlink($out);
pcvc_staff_contract_fill_docx(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx', $out, $admin, '2026-06-13', null);

$z2 = new ZipArchive();
$z2->open($out);
$filled = (string) $z2->getFromName('word/document.xml');
$z2->close();

echo "\n\n=== FILLED acceptance area ===\n";
$p = strpos($filled, 'ACCEPTANCE');
if ($p !== false) {
    $chunk = substr($filled, $p, 8000);
    if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $chunk, $m)) {
        foreach ($m[1] as $t) {
            $t = trim($t);
            if ($t !== '') {
                echo "[$t]\n";
            }
        }
    }
}

echo "\nLeftover placeholders:\n";
foreach (['${', 'employer_', 'name}', 'employer_date', 'employer_position', 'employer_signature', 'employee_signature', 'full_name', 'signing_date'] as $needle) {
    if (strpos($filled, $needle) !== false) {
        echo "  STILL HAS: $needle\n";
    }
}

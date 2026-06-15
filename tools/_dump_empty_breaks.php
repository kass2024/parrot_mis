<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$path = $argv[1] ?? (dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$z = new ZipArchive();
$z->open($path);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
foreach ($m[0] as $idx => $p) {
    if (strpos($p, 'w:type="page"') === false) {
        continue;
    }
    if (pcvc_staff_contract_paragraph_text($p) !== '') {
        continue;
    }
    echo "=== empty break para @{$idx} (len=" . strlen($p) . ") ===\n";
    echo $p . "\n\n";
}

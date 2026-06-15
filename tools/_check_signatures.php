<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$path = $argv[1] ?? (dirname(__DIR__) . '/uploads/staff_contracts/generated/_pages_audit.docx');
$z = new ZipArchive();
$z->open($path);
$xml = (string) $z->getFromName('word/document.xml');
$media = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $name = (string) $z->getNameIndex($i);
    if (strpos($name, 'word/media/') === 0) {
        $media[] = $name;
    }
}
$z->close();

echo 'media: ' . implode(', ', $media) . "\n";
echo 'employee_signature.png: ' . (in_array('word/media/employee_signature.png', $media, true) ? 'yes' : 'no') . "\n";
echo 'employer_signature.png: ' . (in_array('word/media/employer_signature.png', $media, true) ? 'yes' : 'no') . "\n";
echo 'drawing in acceptance: ' . (strpos($xml, 'Employee signature') !== false || strpos($xml, 'employee_signature') !== false ? 'yes' : 'no') . "\n";

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);

$safe = 'Parrott Canada';
$orig = $xml;

$xml = pcvc_staff_contract_replace_fragmented_placeholder($orig, 'full_name', $safe);
echo 'fragmented changed=' . ($xml !== $orig ? 'yes' : 'no') . ' employer_name=' . (strpos($xml, 'employer_name') !== false ? 'yes' : 'no') . "\n";

$orig2 = $xml;
$xml = pcvc_staff_contract_replace_key_split_placeholder($xml, 'full_name', $safe);
echo 'key_split changed=' . ($xml !== $orig2 ? 'yes' : 'no') . ' employer_name=' . (strpos($xml, 'employer_name') !== false ? 'yes' : 'no') . "\n";

$orig3 = $xml;
$xml = pcvc_staff_contract_replace_split_placeholder($xml, 'full_name', $safe);
echo 'split changed=' . ($xml !== $orig3 ? 'yes' : 'no') . ' employer_name=' . (strpos($xml, 'employer_name') !== false ? 'yes' : 'no') . "\n";

$p = strpos($xml, 'Dr.');
if ($p) {
    echo substr($xml, $p, 200) . "\n";
}

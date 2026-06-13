<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);

$pattern = pcvc_staff_contract_fragmented_placeholder_pattern('full_name');
$n = 0;
$tmp = $xml;
while (preg_match($pattern, $tmp, $m, PREG_OFFSET_CAPTURE)) {
    $n++;
    $match = $m[0][0];
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $match, $texts);
    echo "Match #$n texts: " . implode(' | ', $texts[1]) . "\n";
    echo "Has employer_name in match: " . (strpos($match, 'employer_name') !== false ? 'yes' : 'no') . "\n";
    echo "Has full_name in match: " . (strpos($match, 'full_name') !== false ? 'yes' : 'no') . "\n\n";
    $tmp = substr_replace($tmp, 'REPLACED', $m[0][1], strlen($match));
}

<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);

$p = strpos($xml, 'employer_name');
$chunk = substr($xml, max(0, $p - 400), 1200);
echo "CHUNK:\n$chunk\n\n";

$pattern = pcvc_staff_contract_fragmented_placeholder_pattern('full_name');
echo 'full_name pattern matches employer chunk: ' . (preg_match($pattern, $chunk) ? 'YES BUG' : 'no') . "\n";
$pattern2 = pcvc_staff_contract_fragmented_placeholder_pattern('employer_name');
echo 'employer_name pattern matches: ' . (preg_match($pattern2, $chunk) ? 'yes' : 'no') . "\n";

// Count all full_name fragmented matches in full doc
$n = 0;
$tmp = $xml;
while (preg_match($pattern, $tmp, $m, PREG_OFFSET_CAPTURE)) {
    $n++;
    $pos = $m[0][1];
    echo "\nMatch #$n at $pos:\n" . substr($tmp, $pos, min(500, strlen($m[0][0]) + 100)) . "\n";
    $tmp = substr_replace($tmp, 'REPLACED', $pos, strlen($m[0][0]));
}

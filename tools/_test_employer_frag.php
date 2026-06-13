<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);

$p = strpos($xml, 'employer_name');
$paraStart = strrpos(substr($xml, 0, $p), '<w:p');
$paraEnd = strpos($xml, '</w:p>', $p) + 6;
$para = substr($xml, $paraStart, $paraEnd - $paraStart);

$pattern = pcvc_staff_contract_fragmented_placeholder_pattern('employer_name');
echo 'Pattern matches employer para: ' . (preg_match($pattern, $para) ? 'yes' : 'no') . "\n";

$fixed = pcvc_staff_contract_replace_fragmented_placeholder($xml, 'employer_name', 'TWAJAMAHORO JEAN PIERRE');
echo 'TWAJAMAHORO after fragmented only: ' . (strpos($fixed, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";
echo 'employer_name left: ' . (strpos($fixed, 'employer_name') !== false ? 'yes' : 'no') . "\n";

$p2 = strpos($fixed, 'Dr.');
echo substr($fixed, $p2, 400) . "\n";

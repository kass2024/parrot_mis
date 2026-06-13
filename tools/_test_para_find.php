<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();
$xml = pcvc_staff_contract_strip_proof_err($xml);

$n = 0;
preg_replace_callback(
    '/<w:p\b[^>]*>.*?<\/w:p>/s',
    static function (array $m) use (&$n): string {
        if (strpos($m[0], 'employer_name') !== false) {
            $n++;
            $pattern = pcvc_staff_contract_fragmented_placeholder_pattern('employer_name');
            echo "Para #$n len=" . strlen($m[0]) . ' pattern=' . (preg_match($pattern, $m[0]) ? 'yes' : 'no') . "\n";
        }
        return $m[0];
    },
    $xml
);
echo "Total paras with employer_name: $n\n";

$fixed = pcvc_staff_contract_replace_fragmented_placeholder($xml, 'employer_name', 'TWAJAMAHORO JEAN PIERRE');
echo 'result TWAJAMAHORO=' . (strpos($fixed, 'TWAJAMAHORO') !== false ? 'yes' : 'no') . "\n";

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
$replaced = preg_replace_callback(
    $pattern,
    static function (array $m): string {
        echo "MATCHED prefix=[" . $m[1] . "]\n";
        return '<w:t xml:space="preserve"> TWAJAMAHORO JEAN PIERRE</w:t></w:r>';
    },
    $para,
    1
);
echo $replaced === null ? "preg failed\n" : ($replaced === $para ? "no change\n" : "changed\n");
echo strpos($replaced, 'TWAJAMAHORO') !== false ? "has name\n" : "no name\n";

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$path = $argv[1] ?? (dirname(__DIR__) . '/uploads/staff_contracts/generated/_pages_audit.docx');
$z = new ZipArchive();
$z->open($path);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m);
$target = (int) ($argv[2] ?? 196);
for ($i = max(0, $target - 2); $i <= min(count($m[0]) - 1, $target + 2); $i++) {
    $text = pcvc_staff_contract_paragraph_text($m[0][$i]);
    echo "=== para @{$i} text=[" . mb_substr($text, 0, 80) . "] ===\n";
    if ($i === $target) {
        echo $m[0][$i] . "\n";
    }
}

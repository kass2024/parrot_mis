<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$path = $argc > 1 ? $argv[1] : dirname(__DIR__) . '/uploads/staff_contracts/generated/_pages_audit.docx';
$z = new ZipArchive();
$z->open($path);
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m, PREG_OFFSET_CAPTURE);
for ($idx = 68; $idx <= 85; $idx++) {
    if (!isset($m[0][$idx])) continue;
    $p = $m[0][$idx][0];
    $text = pcvc_staff_contract_paragraph_text($p);
    $hasNum = strpos($p, '<w:numPr>') !== false ? 'LIST' : 'para';
    $hasBreak = strpos($p, 'w:type="page"') !== false ? ' PAGE-BREAK' : '';
    $hasLrb = strpos($p, 'lastRenderedPageBreak') !== false ? ' LRB' : '';
    $hasPbb = strpos($p, 'pageBreakBefore') !== false ? ' PBB' : '';
    echo "$idx [$hasNum$hasBreak$hasLrb$hasPbb] $text\n";
}

echo "\nTotal hard breaks: " . substr_count($xml, 'w:type="page"') . "\n";

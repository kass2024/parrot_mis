<?php
$path = $argv[1] ?? (dirname(__DIR__) . '/uploads/staff_contracts/generated/_pages_audit.docx');
$z = new ZipArchive();
$z->open($path);
$x = (string) $z->getFromName('word/document.xml');
$z->close();
echo 'xml len=' . strlen($x) . "\n";
echo 'hard=' . substr_count($x, 'w:type="page"') . "\n";
libxml_use_internal_errors(true);
echo simplexml_load_string($x) ? "valid\n" : "INVALID\n";

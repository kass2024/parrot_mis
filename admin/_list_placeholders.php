<?php
$file = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($file);
$x = $z->getFromName('word/document.xml');
$z->close();
preg_match_all('/\$\{[a-z_]+\}/', $x, $m);
echo basename($file) . ":\n" . implode(', ', array_unique($m[0])) . "\n";

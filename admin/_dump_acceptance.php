<?php
$file = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($file);
$x = $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, 'ACCEPTANCE');
echo substr($x, $p, 4000);

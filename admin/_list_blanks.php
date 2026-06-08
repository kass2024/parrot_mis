<?php
$file = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($file);
$x = $z->getFromName('word/document.xml');
$z->close();
preg_match_all('/<w:t[^>]*>([^<]*_{3,}[^<]*)<\/w:t>/', $x, $m);
echo basename($file) . ":\n";
foreach (array_unique($m[1]) as $s) {
    echo '  ' . json_encode($s) . "\n";
}

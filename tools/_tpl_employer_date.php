<?php
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$x = (string) $z->getFromName('word/document.xml');
$z->close();
$p = strpos($x, 'employer_date');
echo substr($x, max(0, $p - 600), 2000);

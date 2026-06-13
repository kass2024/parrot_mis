<?php
$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
for ($i = 0; $i < $z->numFiles; $i++) {
    $n = (string) $z->getNameIndex($i);
    if (!preg_match('/\.xml$/', $n)) {
        continue;
    }
    $c = (string) $z->getFromIndex($i);
    if (stripos($c, 'employer') === false && stripos($c, 'Dr.') === false && strpos($c, '${') === false) {
        continue;
    }
    echo "=== $n ===\n";
    if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $c, $m)) {
        foreach ($m[1] as $t) {
            if (stripos($t, 'employer') !== false || strpos($t, '${') !== false || stripos($t, 'Dr.') !== false || stripos($t, 'Name') !== false) {
                echo "  [$t]\n";
            }
        }
    }
}
$z->close();

// Also check uploaded source templates if any
$genDir = dirname(__DIR__) . '/uploads/staff_contracts';
if (is_dir($genDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($genDir));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'docx') {
            continue;
        }
        $path = $file->getPathname();
        $zz = new ZipArchive();
        if ($zz->open($path) !== true) {
            continue;
        }
        $xml = (string) $zz->getFromName('word/document.xml');
        $zz->close();
        if (strpos($xml, 'employer_name') !== false || strpos($xml, '${employer') !== false) {
            echo "\nFOUND employer_name in $path\n";
        }
    }
}

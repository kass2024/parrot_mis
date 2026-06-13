<?php
foreach (glob(__DIR__ . '/_tpl_*.docx') as $tpl) {
    $z = new ZipArchive();
    if ($z->open($tpl) !== true) {
        echo basename($tpl) . " FAIL open\n";
        continue;
    }
    $xml = (string) $z->getFromName('word/document.xml');
    $z->close();
    echo basename($tpl) . ' size=' . filesize($tpl) . ' breaks=' . substr_count($xml, 'lastRenderedPageBreak') . ' paras=' . substr_count($xml, '<w:p ') . "\n";
}

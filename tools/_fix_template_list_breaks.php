<?php
/**
 * Remove w:lastRenderedPageBreak inside numbered list paragraphs (fixes empty bullet ghosts).
 */
function strip_list_page_breaks(string $xml): string
{
    return preg_replace_callback(
        '/<w:p\b[^>]*>.*?<\/w:p>/s',
        static function (array $m): string {
            $p = $m[0];
            if (strpos($p, '<w:numPr>') === false) {
                return $p;
            }
            $p = preg_replace('/<w:lastRenderedPageBreak\s*\/>/', '', $p) ?? $p;
            $p = preg_replace('/<w:br\s+w:type="page"\s*\/>/', '', $p) ?? $p;
            return $p;
        },
        $xml
    );
}

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$zip = new ZipArchive();
if ($zip->open($tpl) !== true) {
    fwrite(STDERR, "Cannot open template\n");
    exit(1);
}

$xml = (string) $zip->getFromName('word/document.xml');
$before = substr_count($xml, 'lastRenderedPageBreak');
$fixed = strip_list_page_breaks($xml);
$after = substr_count($fixed, 'lastRenderedPageBreak');

$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $fixed);
$zip->close();

echo "Template fixed. lastRenderedPageBreak count: $before -> $after\n";

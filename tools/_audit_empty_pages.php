<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function analyze(string $label, string $path): void
{
    $z = new ZipArchive();
    $z->open($path);
    $xml = (string) $z->getFromName('word/document.xml');
    $z->close();

    $hard = substr_count($xml, 'w:type="page"');
    $lrb = substr_count($xml, 'lastRenderedPageBreak');
    $pbb = substr_count($xml, 'w:pageBreakBefore');
    $pages = pcvc_staff_contract_expected_page_count_from_xml($xml);

    echo "=== $label ===\n";
    echo "hard page breaks: $hard | lastRendered: $lrb | pageBreakBefore: $pbb | pages=$pages\n";

    preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $m, PREG_OFFSET_CAPTURE);
    $emptyBreakParas = 0;
    foreach ($m[0] as $idx => $para) {
        $p = $para[0];
        $text = pcvc_staff_contract_paragraph_text($p);
        $hasBreak = strpos($p, 'w:type="page"') !== false;
        if ($hasBreak) {
            echo "break@$idx text=[$text]\n";
            if ($text === '') {
                $emptyBreakParas++;
            }
            if (isset($m[0][$idx + 1])) {
                $next = pcvc_staff_contract_paragraph_text($m[0][$idx + 1][0]);
                echo "  next: " . mb_substr($next, 0, 90) . "\n";
            }
        }
        if (strpos($text, 'Marketing & Outreach Support') !== false) {
            echo "marketing para@$idx\n";
            if (isset($m[0][$idx + 1])) {
                echo "  next: " . mb_substr(pcvc_staff_contract_paragraph_text($m[0][$idx + 1][0]), 0, 90) . "\n";
            }
            if (isset($m[0][$idx + 2])) {
                echo "  next+1: " . mb_substr(pcvc_staff_contract_paragraph_text($m[0][$idx + 2][0]), 0, 90) . "\n";
            }
        }
    }
    echo "empty break-only paras: $emptyBreakParas\n\n";
}

$tpl = dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx';
$out = dirname(__DIR__) . '/uploads/staff_contracts/generated/_pages_audit.docx';
@unlink($out);
$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$dataUrl = 'data:image/png;base64,' . base64_encode($png);
pcvc_staff_contract_fill_docx($tpl, $out, $admin, '2026-06-15', $dataUrl);

analyze('template', $tpl);
analyze('signed filled', $out);

pcvc_staff_contract_patch_docx_layout($out);
analyze('after patch_docx_layout', $out);

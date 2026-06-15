<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/staff_contract_word.php';

$z = new ZipArchive();
$z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
$xml = (string) $z->getFromName('word/document.xml');
$z->close();

$admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99', 'email' => 't@e.com'];
$values = pcvc_staff_contract_merge_values($admin, null, null);
$imageKeys = ['employer_signature', 'employee_signature'];

$work = pcvc_staff_contract_strip_proof_err($xml);
foreach ($values as $key => $value) {
    if (in_array($key, $imageKeys, true)) continue;
    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $work = str_replace('${' . $key . '}', $safe, $work);
}

if (strpos($work, '${') !== false) {
    foreach (pcvc_staff_contract_placeholder_keys() as $key) {
        if (!isset($values[$key]) || in_array($key, $imageKeys, true)) continue;
        $safe = (string) $values[$key];
        $t0 = microtime(true);
        $iter = 0;
        $prev = '';
        $cur = $work;
        while ($cur !== $prev) {
            $prev = $cur;
            $iter++;
            $safeXml = htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cur = str_replace('${' . $key . '}', $safeXml, $cur);
            $cur = pcvc_staff_contract_replace_fragmented_placeholder($cur, $key, $safe);
            $cur = pcvc_staff_contract_replace_key_split_placeholder($cur, $key, $safe);
            $cur = pcvc_staff_contract_replace_split_placeholder($cur, $key, $safe);
            if ($iter > 50) {
                echo "KEY $key: >50 iterations ABORT\n";
                break;
            }
        }
        $ms = round((microtime(true) - $t0) * 1000);
        if ($ms > 50) {
            echo "KEY $key: {$ms}ms iterations=$iter\n";
        }
        $work = $cur;
    }
}

echo "remaining placeholders: " . (strpos($work, '${') !== false ? 'yes' : 'no') . "\n";

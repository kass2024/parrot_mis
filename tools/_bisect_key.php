<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

function run_until(string $stopKey): string {
    $z = new ZipArchive();
    $z->open(dirname(__DIR__) . '/admin/Parrot Contract for Mutware.docx');
    $xml = (string) $z->getFromName('word/document.xml');
    $z->close();
    $xml = pcvc_staff_contract_strip_proof_err($xml);
    $admin = ['id' => 114, 'full_name' => 'Parrott Canada', 'position' => 'CEO', 'national_id' => '99'];
    $values = pcvc_staff_contract_merge_values($admin, '2026-06-13', null);
    $imageKeys = ['employer_signature', 'employee_signature'];
    foreach ($values as $key => $value) {
        if (in_array($key, $imageKeys, true)) continue;
        $xml = str_replace('${' . $key . '}', htmlspecialchars((string)$value, ENT_XML1|ENT_QUOTES, 'UTF-8'), $xml);
    }
    foreach (pcvc_staff_contract_placeholder_keys() as $key) {
        if ($key === $stopKey) break;
        if (in_array($key, $imageKeys, true) || !isset($values[$key])) continue;
        $safe = (string) $values[$key];
        $prev = '';
        while ($xml !== $prev) {
            $prev = $xml;
            $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
            $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
            $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
        }
    }
    return $xml;
}

$keys = pcvc_staff_contract_placeholder_keys();
$idx = array_search('employer_date', $keys, true);
for ($i = max(0, $idx - 3); $i < $idx; $i++) {
    $k = $keys[$i];
    $xml = run_until($keys[$i + 1] ?? 'employer_date');
    $has = strpos($xml, 'employer_date') !== false ? 'employer_date=yes' : 'employer_date=GONE';
    $p = strpos($xml, 'ACCEPTANCE');
    $slice = $p ? substr($xml, $p, 12000) : '';
    $bad = strpos($slice, 'Date:Parrott Canada') !== false ? 'BAD Date:Parrott' : 'ok';
    echo "after $k then stop: $has $bad\n";
}

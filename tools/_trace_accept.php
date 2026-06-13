<?php
require dirname(__DIR__) . '/helpers/staff_contract_word.php';

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

$watch = ['employer_name', 'employer_position', 'employer_date', 'signing_date', 'full_name', 'employee_signature'];

foreach (pcvc_staff_contract_placeholder_keys() as $key) {
    if (in_array($key, $imageKeys, true) || !isset($values[$key])) continue;
    $safe = (string) $values[$key];
    $prev = '';
    while ($xml !== $prev) {
        $prev = $xml;
        $safeXml = htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = str_replace('${' . $key . '}', $safeXml, $xml);
        $xml = pcvc_staff_contract_replace_fragmented_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_key_split_placeholder($xml, $key, $safe);
        $xml = pcvc_staff_contract_replace_split_placeholder($xml, $key, $safe);
    }
    if (!in_array($key, $watch, true)) continue;
    $p = strpos($xml, 'ACCEPTANCE');
    $slice = substr($xml, $p, 12000);
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $slice, $m);
    $texts = array_values(array_filter(array_map('trim', $m[1])));
    $accept = implode(' | ', array_slice($texts, 0, 20));
    echo "after $key: $accept\n\n";
}

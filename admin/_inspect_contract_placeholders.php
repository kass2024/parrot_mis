<?php
$file = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($file);
$x = $z->getFromName('word/document.xml');
$z->close();

$keys = [
    'national_id', 'signing_date', 'full_name', 'email', 'phone_number', 'position',
    'employment_start_date', 'probation_end_date', 'employer_name', 'employer_position',
    'employer_signature', 'employer_date', 'employee_signature',
];
foreach ($keys as $k) {
    echo (str_contains($x, '${' . $k . '}') ? 'OK' : 'MISS') . " \${$k}\n";
}

$notaryPos = strpos($x, '>NOTARY </w:t>');
if ($notaryPos !== false) {
    $chunk = substr($x, $notaryPos, 1200);
    echo (str_contains($chunk, 'employer_name') ? 'BAD notary has employer' : 'OK notary blank') . "\n";
}

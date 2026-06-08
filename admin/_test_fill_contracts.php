<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../helpers/staff_contract_word.php';

$files = [
    'DELPHINE PROBATION CONTRACT-PARROT CANADA VISA CONSULTANT CO.docx',
    'SONIA  PROBATION CONTRACT-PARROT CANADA VISA CONSULTANT CO.docx',
];

$admin = [
    'full_name' => 'Test Employee', 'first_name' => 'Test', 'last_name' => 'Employee',
    'email' => 'emp@test.com', 'phone_number' => '+250 788 111 222', 'username' => 'emp',
    'role' => 'staff', 'position' => 'Country Coordinator Assistant',
    'employment_type' => 'Probation', 'employment_start_date' => '2026-04-01',
    'national_id' => '9988776655', 'date_of_birth' => '1992-05-10',
    'marital_status' => 'Single', 'nationality' => 'Rwandan', 'place_of_birth' => 'Kigali',
    'address' => 'Kigali', 'monthly_salary' => 120000, 'salary_currency' => 'RWF',
];

foreach ($files as $f) {
    $src = __DIR__ . '/' . $f;
    $slug = preg_replace('/[^a-z0-9]+/i', '_', pathinfo($f, PATHINFO_FILENAME));
    $out = __DIR__ . '/../uploads/staff_contracts/generated/test_' . $slug . '.docx';
    pcvc_staff_contract_fill_docx($src, $out, $admin, date('Y-m-d'), null);
    $ox = file_get_contents('zip://' . str_replace('\\', '/', $out) . '#word/document.xml');
    $plain = html_entity_decode(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $ox)));
    $ok = str_contains($plain, 'Test Employee')
        && str_contains($plain, 'TWAJAMAHORO JEAN PIERRE')
        && str_contains($plain, 'Managing director')
        && !str_contains(substr($plain, (int) strrpos($plain, 'NOTARY')), 'TWAJAMAHORO');
    echo ($ok ? 'OK' : 'FAIL') . ' ' . basename($f) . "\n";
}

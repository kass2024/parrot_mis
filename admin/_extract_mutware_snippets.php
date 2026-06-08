<?php
$z = new ZipArchive();
$z->open(__DIR__ . '/MUTWARE  PROBATION CONTRACT-PARROT CANADA VISA CONSULTANT CO.docx');
$x = $z->getFromName('word/document.xml');
$z->close();
$acc = strpos($x, 'ACCEPTANCE');
$lastEmp = strrpos($x, '>EMPLOYEE</w:t>');
$mid = substr($x, $acc, $lastEmp - $acc);
// extract from Name: ${employer_name} through Date: ${employer_date}
if (preg_match('/(<w:t>Name: \$\{employer_name\}<\/w:t>.*?<w:t>Date: \$\{employer_date\}<\/w:t>)/s', $mid, $m)) {
    file_put_contents(__DIR__ . '/_mutware_employer_snippet.txt', $m[1]);
    echo "employer snippet saved\n";
}
$end = substr($x, $lastEmp);
if (preg_match('/(<w:t>Employee Name: \$\{full_name\}<\/w:t>.*?<w:t>Date: \$\{signing_date\}<\/w:t>)/s', $end, $m)) {
    file_put_contents(__DIR__ . '/_mutware_employee_sig_snippet.txt', $m[1]);
    echo "employee sig snippet saved\n";
}

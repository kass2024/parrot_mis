<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php_version' => PHP_VERSION,
    'zip_extension' => class_exists('ZipArchive'),
    'vendor_autoload' => is_file(__DIR__ . '/../vendor/autoload.php'),
    'word_helper' => is_file(__DIR__ . '/../helpers/staff_contract_word.php'),
    'canonical_template' => is_file(__DIR__ . '/Parrot Contract for Mutware.docx'),
    'uploads_writable' => is_writable(__DIR__ . '/../uploads') || @mkdir(__DIR__ . '/../uploads/staff_contracts/source', 0775, true),
];

$errors = [];
if (!$checks['zip_extension']) {
    $errors[] = 'Install PHP ext-zip';
}
if (!$checks['vendor_autoload']) {
    $errors[] = 'Run composer install (vendor/autoload.php missing)';
}
if (!$checks['word_helper']) {
    $errors[] = 'Deploy helpers/staff_contract_word.php';
}

echo json_encode([
    'ok' => $errors === [],
    'checks' => $checks,
    'errors' => $errors,
]);

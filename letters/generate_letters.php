<?php
declare(strict_types=1);

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

/* ============================
   1. READ JSON INPUT
============================ */
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

if (empty($input['data']) || empty($input['gender'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

$data   = $input['data'];
$gender = strtolower(trim($input['gender']));

/* ============================
   2. GENDER MAP
============================ */
$genderMap = [
    'male'   => ['Mr.', 'he', 'his'],
    'female' => ['Ms.', 'she', 'her']
];

if (!isset($genderMap[$gender])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid gender']);
    exit;
}

[$title, $pronoun, $possessive] = $genderMap[$gender];

/* ============================
   3. TEMPLATE DATA
============================ */
$fullName = trim(($data['name'] ?? '') . ' ' . ($data['surname'] ?? ''));

$templateData = [
    'name'              => (string)($data['name'] ?? ''),
    'surname'           => (string)($data['surname'] ?? ''),
    'full_name'         => $fullName,
    'program'           => (string)($data['program'] ?? ''),
    'academic_year'     => (string)($data['academic_year_start'] ?? ''),
    'class'             => (string)($data['class'] ?? ''),
    'gender_title'      => $title,
    'gender_pronoun'    => $pronoun,
    'gender_possessive' => $possessive
];

/* ============================
   4. PATHS
============================ */
$templateDir = __DIR__ . '/../templates';
$outputDir   = __DIR__ . '/../generated';

if (!is_dir($templateDir)) {
    echo json_encode(['status' => 'error', 'message' => 'Templates folder missing']);
    exit;
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

/* ============================
   5. TEMPLATE DEFINITIONS
============================ */
$templates = [
    [
        'file'   => 'template-1.docx',
        'author' => 'M. Adama DOUAMBA'
    ],
    [
        'file'   => 'template-2.docx',
        'author' => 'Pr. Amade BADINI'
    ],
    [
        'file'   => 'template-3.docx',
        'author' => 'PROF NAON BETABOALE'
    ]
];

/* ============================
   6. GENERATE LETTERS
============================ */
$files = [];
$counter = 1;

// Clean name for filename use
$safeFullName = preg_replace('/[^A-Za-z0-9 _-]/', '', $fullName);
$safeFullName = strtoupper(trim($safeFullName));

foreach ($templates as $tpl) {

    $tplPath = $templateDir . '/' . $tpl['file'];

    if (!file_exists($tplPath)) {
        error_log("Missing template: $tplPath");
        continue;
    }

    try {
        $processor = new TemplateProcessor($tplPath);

        foreach ($templateData as $key => $value) {
            $processor->setValue($key, $value);
        }

        // Internal filename (server)
        $internalFile = 'letter_' . $counter . '.docx';
        $processor->saveAs($outputDir . '/' . $internalFile);

        // Download filename (user)
        $downloadName =
            $safeFullName .
            ' RECOMMENDATION LETTER FROM ' .
            strtoupper($tpl['author']) .
            '.docx';

        $files[] = [
            'name' => $downloadName,                  // 👈 download name
            'path' => '../generated/' . $internalFile // 👈 actual file
        ];

        $counter++;

    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

/* ============================
   7. RESPONSE
============================ */
if (!$files) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Templates loaded but generation failed'
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'files'  => $files
]);
exit;

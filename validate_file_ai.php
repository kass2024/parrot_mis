<?php
require_once __DIR__ . '/helpers/env_bootstrap.php';
require_once 'vendor/autoload.php'; // if using composer
use Orhanerday\OpenAi\OpenAi; // for example if using openai-php sdk

$open_ai = new OpenAi(pcvc_env('OPENAI_API_KEY'));

if (!isset($_FILES['file']) || !isset($_POST['expected_type'])) {
    echo json_encode(["status" => "error", "message" => "Missing file or expected type"]);
    exit;
}

$file = $_FILES['file'];
$tmpPath = $file['tmp_name'];
$expected = strtolower(trim($_POST['expected_type']));

// Step 1: Extract text (basic OCR if PDF/Image)
$extractedText = '';
if (mime_content_type($tmpPath) === 'application/pdf') {
    $extractedText = shell_exec("pdftotext " . escapeshellarg($tmpPath) . " -"); // requires poppler-utils
} elseif (str_contains(mime_content_type($tmpPath), 'image')) {
    // use Tesseract for OCR
    $extractedText = shell_exec("tesseract " . escapeshellarg($tmpPath) . " stdout");
}

// Step 2: Ask AI to verify file content
$prompt = "You are a file validation assistant. Analyze this text and decide if it matches a {$expected}. 
Respond only with one word: 'valid' or 'invalid'.
Text content:\n" . substr($extractedText, 0, 1500);

$response = $open_ai->completion([
    'model' => 'gpt-3.5-turbo-instruct',
    'prompt' => $prompt,
    'max_tokens' => 5,
]);

$result = strtolower(trim(json_decode($response, true)['choices'][0]['text'] ?? ''));

if (strpos($result, 'valid') !== false) {
    echo json_encode(["status" => "ok", "message" => "File validated successfully"]);
} else {
    echo json_encode(["status" => "reject", "message" => "File content does not match expected type"]);
}
?>

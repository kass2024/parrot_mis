<?php
/**
 * validate_payment_proof_ai.php
 * AI-powered payment proof validation using existing system pattern
 * Based on upload_file.php AI validation logic
 */

declare(strict_types=1);
ob_start();
session_start();
header('Content-Type: application/json');

// =========================================
// SESSION VALIDATION
// =========================================
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit;
}

// =========================================
// CONFIG
// =========================================
require_once __DIR__ . '/helpers/env_bootstrap.php';
$API_KEY  = pcvc_env('OPENAI_API_KEY');
$LOG_FILE = __DIR__ . '/upload_debug.log';
$TEMP_DIR = __DIR__ . '/temp/';
$UPLOAD_DIR = __DIR__ . '/uploads/';

foreach ([$TEMP_DIR, $UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// =========================================
// INPUT VALIDATION
// =========================================
if (empty($_POST['file_path'])) {
    echo json_encode(['success'=>false,'message'=>'File path is required']);
    exit;
}

if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
    exit;
}

$file_path = trim($_POST['file_path']);

// Security: Validate file path
$base_dir = __DIR__ . '/uploads/';
$full_path = realpath($base_dir . basename($file_path));

if (!$full_path || !file_exists($full_path) || strpos($full_path, realpath($base_dir)) !== 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid file path']);
    exit;
}

// =========================================
// FILE PROCESSING
// =========================================
$fileName = basename($full_path);
$mime = mime_content_type($full_path);
$isPdf = ($mime === 'application/pdf');
$isImage = in_array($mime, ['image/jpeg', 'image/jpg', 'image/png']);

if (!$isPdf && !$isImage) {
    echo json_encode(['success'=>false,'message'=>'Invalid file type. Only PDF and images allowed']);
    exit;
}

$tmpPath = $full_path; // Use existing file

// =========================================
// AI VALIDATION FOR PAYMENT PROOF
// =========================================

if ($isImage) {
    // Convert image to base64 for AI
    $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($tmpPath));
    $content = [
        ["type" => "input_text", "text" => getPaymentProofPrompt()],
        ["type" => "input_image", "image_url" => $dataUrl]
    ];
} else {
    // Upload PDF to OpenAI
    $ch = curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $API_KEY"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['purpose'=>'assistants','file'=>new CURLFile($tmpPath,$mime,$fileName)]
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        echo json_encode(['success'=>false,'message'=>"File upload error: $err"]);
        exit;
    }
    
    $data = json_decode($resp, true);
    if (empty($data['id'])) {
        echo json_encode(['success'=>false,'message'=>'File upload failed']);
        exit;
    }
    
    $fileId = $data['id'];
    $content = [
        ["type" => "input_text", "text" => getPaymentProofPrompt()],
        ["type" => "input_file", "file_id" => $fileId]
    ];
}

// =========================================
// AI API CALL
// =========================================
$payload = [
    "model" => "gpt-4o",
    "input" => $content,
    "text" => ["format" => ["type" => "json_schema", "schema" => getPaymentProofSchema()]],
    "temperature" => 0.1,
    "max_output_tokens" => 1000
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$API_KEY}",
        "Content-Type: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60
]);

$resp = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    echo json_encode(['success'=>false,'message'=>"API error: $err"]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['success'=>false,'message'=>"API returned HTTP $httpCode"]);
    exit;
}

$result = json_decode($resp, true);
if (empty($result['output'])) {
    echo json_encode(['success'=>false,'message'=>'No response from AI']);
    exit;
}

try {
    $output = json_decode($result['output'][0]['text'], true);
    
    if ($output === null) {
        throw new Exception('Invalid JSON response from AI');
    }
    
    // Log the validation
    file_put_contents($LOG_FILE, "\nPayment Proof AI Validation: " . json_encode($output) . "\n", FILE_APPEND);
    
    echo json_encode([
        'success' => true,
        'contains_payment_info' => $output['contains_payment_info'] ?? false,
        'confidence' => $output['confidence'] ?? 0,
        'details' => $output['details'] ?? [],
        'message' => $output['message'] ?? 'Validation completed',
        'detected_amount' => $output['detected_amount'] ?? null,
        'detected_transaction_id' => $output['detected_transaction_id'] ?? null
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Validation failed: ' . $e->getMessage()]);
}

// =========================================
// HELPER FUNCTIONS
// =========================================

function getPaymentProofPrompt(): string {
    return <<<PROMPT
You are a payment validation AI. Your task is to analyze the provided document and determine if it contains valid payment information.

Look for the following indicators:
- Payment amount or total
- Transaction ID or receipt number
- Payment method (cash, credit card, bank transfer, etc.)
- Payment date and time
- Merchant or service provider name
- Authorization or confirmation status
- Bank or payment processor details

Return ONLY JSON in this format:
{
  "contains_payment_info": true or false,
  "confidence": 0.0-1.0,
  "detected_amount": "string or null",
  "detected_transaction_id": "string or null",
  "details": ["list", "of", "payment", "details", "found"],
  "message": "Brief summary of findings"
}

If no clear payment information is found, set contains_payment_info=false and explain why.
PROMPT;
}

function getPaymentProofSchema(): string {
    return <<<SCHEMA
{
  "type": "object",
  "properties": {
    "contains_payment_info": {"type": "boolean"},
    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    "detected_amount": {"type": ["string", "null"]},
    "detected_transaction_id": {"type": ["string", "null"]},
    "details": {"type": "array", "items": {"type": "string"}},
    "message": {"type": "string"}
  },
  "required": ["contains_payment_info", "confidence", "details", "message"]
}
SCHEMA;
}
?>

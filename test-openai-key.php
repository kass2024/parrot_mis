<?php
header('Content-Type: application/json');
require_once __DIR__ . '/helpers/env_bootstrap.php';

$apiKey = pcvc_env('OPENAI_API_KEY');
if ($apiKey === '') {
    echo json_encode(['error' => 'Set OPENAI_API_KEY in .env first.'], JSON_PRETTY_PRINT);
    exit;
}

// Simple test request (small + cheap)
$payload = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "user", "content" => "Say OK if this key works"]
    ],
    "max_tokens" => 10
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// HARD DEBUG OUTPUT
echo json_encode([
    "http_code" => $httpCode,
    "curl_error" => $error,
    "raw_response" => json_decode($response, true)
], JSON_PRETTY_PRINT);

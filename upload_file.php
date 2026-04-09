<?php
/**
 * upload_file.php
 * UNIVERSAL FINAL VERSION (2025-10-22)
 * Supports PDF, images, DOCX → PDF.
 * Accepts English certificates confirming study in English.
 * Adds AI-based applicant name verification.
 * Works reliably on shared cPanel hosting.
 */

declare(strict_types=1);
ob_start();
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/helpers/env_bootstrap.php';
require_once __DIR__ . '/db.php';
// =========================================
// SESSION VALIDATION (MUST BE FIRST)
// =========================================
if (empty($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'User not authenticated']);
    exit;
}
// =========================================
// ATTACH TO EXISTING DRAFT APPLICATION ONLY
// =========================================
$sessionId = session_id();

$stmt = $conn->prepare(
    "SELECT id FROM student_applications
     WHERE session_id = ?
       AND submitted = 0
     LIMIT 1"
);
if (!$stmt) {
    echo json_encode(['status'=>'error','message'=>'DB error']);
    exit;
}

$stmt->bind_param('s', $sessionId);
$stmt->execute();
$stmt->bind_result($appId);
$stmt->fetch();
$stmt->close();

if (!$appId) {
    // 🚫 Uploads must NOT create applications
    echo json_encode([
        'status'  => 'error',
        'message' => 'No active application draft found. Please start or continue your application first.'
    ]);
    exit;
}


// =========================================
// CONFIG
// =========================================
$API_KEY  = pcvc_env('OPENAI_API_KEY');
$LOG_FILE = __DIR__ . '/upload_debug.log';
$TEMP_DIR = __DIR__ . '/temp/';
$UPLOAD_DIR = __DIR__ . '/uploads/';
foreach ([$TEMP_DIR, $UPLOAD_DIR] as $dir)
    if (!is_dir($dir)) mkdir($dir, 0755, true);

// =========================================
// BASIC VALIDATION
// =========================================
if (empty($_SESSION['user_id']))
    exit(json_encode(['status'=>'error','message'=>'User not authenticated']));
if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
    exit(json_encode(['status'=>'error','message'=>'File upload failed or missing']));

$field = $_POST['field'] ?? 'unknown';
$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$fullName  = trim("$firstName $lastName");

// =========================================
// EXPECTED DOCUMENT TYPE
// =========================================
$expectedTypes = [
    'degree_transcripts'     => 'university degree or transcript',
    'high_school_degree'     => 'high school diploma or certificate',
    'valid_passport'         => 'passport or ID page',
    'personal_statement'     => 'personal statement or motivation letter',
    'cv_resume'              => 'curriculum vitae or resume',
    'english_certificate'    => 'English proficiency certificate (accept if document confirms instruction in English)',
    'birth_certificate'      => 'birth certificate or national ID',
    'recommendation_letters' => 'recommendation or reference letter',
    'payment_proof'          => 'payment receipt or transaction proof'
];
$expectedType = $expectedTypes[$field] ?? 'academic or identification document';

// =========================================
// SAVE TEMP FILE LOCALLY
// =========================================
$fileName = time().'_'.preg_replace('/[^A-Za-z0-9.\-_]/','_',$_FILES['file']['name']);
$tmpPath  = $TEMP_DIR.$fileName;
if (!move_uploaded_file($_FILES['file']['tmp_name'],$tmpPath))
    exit(json_encode(['status'=>'error','message'=>'Cannot save uploaded file']));

$mime = mime_content_type($tmpPath) ?: 'application/octet-stream';
$ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
// Detect scanned PDF (no text layer)
$isPdf = ($mime === 'application/pdf');
$isScannedPdf = false;

if ($isPdf) {
    $sample = file_get_contents($tmpPath, false, null, 0, 5000);
    if (!preg_match('/[A-Za-z]{4,}/', $sample)) {
        $isScannedPdf = true;
    }
}

function scannedPdfToImages(string $pdfPath, string $outDir): array {
    if (!class_exists('Imagick')) {
        exit(json_encode([
            'status'=>'error',
            'message'=>'Scanned PDF detected but Imagick is not available on server'
        ]));
    }

    $images = [];
    $im = new Imagick();
    $im->setResolution(200, 200);
    $im->readImage($pdfPath);

    foreach ($im as $i => $page) {
        $page->setImageFormat('jpeg');
        $page->setImageCompressionQuality(90);
        $out = $outDir . 'page_' . ($i+1) . '.jpg';
        $page->writeImage($out);
        $images[] = $out;
    }

    $im->clear();
    return $images;
}

// =========================================
// STEP 1️⃣  PREPARE BASED ON TYPE
// =========================================
$fileId = null;
$content = [];
$isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tiff']);
$isScannedPdfImages = [];


if ($isImage) {
    // ---------- IMAGE HANDLING ----------
    $info = @getimagesize($tmpPath);
    if (!$info) exit(json_encode(['status'=>'error','message'=>'Unreadable or unsupported image file']));

    [$w, $h] = $info;
    $src = match($ext) {
        'png'   => imagecreatefrompng($tmpPath),
        'webp'  => imagecreatefromwebp($tmpPath),
        'bmp'   => imagecreatefrombmp($tmpPath),
        'tiff'  => @imagecreatefromstring(file_get_contents($tmpPath)),
        default => imagecreatefromjpeg($tmpPath),
    };
    if (!$src) exit(json_encode(['status'=>'error','message'=>'Failed to read image data']));

    // Resize if large (improves OCR)
    $maxW = 1200;
    if ($w > $maxW) {
        $ratio = $maxW / $w;
        $newW = $maxW;
        $newH = (int)($h * $ratio);
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagejpeg($dst, $tmpPath, 90);
        imagedestroy($dst);
    }
    imagedestroy($src);

    // No API upload needed for OCR — encode base64 inline
    $imageData = base64_encode(file_get_contents($tmpPath));
    $dataUrl = 'data:' . $mime . ';base64,' . $imageData;

    file_put_contents($LOG_FILE, "\n✅ Image ready (base64 embedded): $fileName\n", FILE_APPEND);
}

elseif ($ext === 'docx') {
    // ---------- DOCX → PDF ----------
    $zip = new ZipArchive;
    if ($zip->open($tmpPath) === TRUE) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) exit(json_encode(['status'=>'error','message'=>'Empty DOCX']));
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($xml)));

        $pdfPath = $TEMP_DIR . pathinfo($fileName, PATHINFO_FILENAME) . '.pdf';
        $escaped = str_replace(['(', ')'], '', $text);
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
             . "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n"
             . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R>>endobj\n"
             . "4 0 obj<</Length " . strlen($escaped) . ">>stream\nBT /F1 12 Tf 72 720 Td ($escaped) Tj ET\nendstream\nendobj\n"
             . "xref\n0 5\n0000000000 65535 f \ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n0\n%%EOF";
        file_put_contents($pdfPath, $pdf);

        $tmpPath = $pdfPath;
        $fileName = basename($pdfPath);
        $mime = 'application/pdf';
    } else {
        exit(json_encode(['status'=>'error','message'=>'Failed to read DOCX']));
    }

    // Upload the PDF
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
    if ($err) exit(json_encode(['status'=>'error','message'=>$err]));
    $data = json_decode($resp,true);
    if (empty($data['id'])) exit(json_encode(['status'=>'error','message'=>'File upload failed']));
    $fileId = $data['id'];
    file_put_contents($LOG_FILE, "\n✅ DOCX converted & uploaded: $fileId ($fileName)\n", FILE_APPEND);
}

elseif ($isPdf && $isScannedPdf) {
    // ---------- SCANNED PDF (PREPARE FOR OCR IN STEP 2) ----------

    // Convert PDF pages to images
    $isScannedPdfImages = scannedPdfToImages($tmpPath, $TEMP_DIR);

    // Mark as handled; content will be built later
    $fileId = null;

    file_put_contents(
        $LOG_FILE,
        "\n🧠 Scanned PDF prepared for OCR (" . count($isScannedPdfImages) . " pages): $fileName\n",
        FILE_APPEND
    );
}
 else {
    // ---------- NORMAL TEXT PDF ----------

    $ch = curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $API_KEY"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'purpose' => 'assistants',
            'file'    => new CURLFile($tmpPath, $mime, $fileName)
        ]
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        exit(json_encode([
            'status'  => 'error',
            'message' => "File upload error: $err"
        ]));
    }

    $data = json_decode($resp, true);
    if (empty($data['id'])) {
        exit(json_encode([
            'status'  => 'error',
            'message' => 'File upload failed'
        ]));
    }

    $fileId = $data['id'];

    file_put_contents(
        $LOG_FILE,
        "\n✅ Text PDF uploaded: $fileId ($fileName)\n",
        FILE_APPEND
    );
}


// =========================================
// STEP 2️⃣ PROMPTS (with Name Check)
// =========================================
$systemPrompt = <<<PROMPT
You are a document validation AI for university admissions screening.

Your task is to determine whether a document appears to be a valid and plausible official document
based on its internal consistency, structure, formatting, stamps, signatures, and content.

Do NOT require online verification, QR codes, cryptographic security features, or external databases.
A document may be considered valid even if its authenticity cannot be externally verified,
as long as it appears officially formatted and internally consistent for the stated institution and country.

If you receive an image, perform OCR-style text extraction.
If you receive a PDF, analyze its text content or visual structure.

Return ONLY JSON in this format:
{
  "valid": true or false,
  "detected_type": "string",
  "confidence": 0.0-1.0,
  "summary": "1–3 short sentences summarizing the document",
  "name_detected": "string",
  "name_match": true or false
}
PROMPT;


if ($fileId || $isImage || $isScannedPdf) {

    $nameInstruction = $fullName
        ? " Detect the main full name appearing in the document. Compare it to '{$fullName}'. "
          . "If they refer to the same person, set name_match=true; otherwise false. "
        : "";

    // -------------------------------
    // FIELD-SPECIFIC PROMPTS
    // -------------------------------
    if ($field === 'english_certificate') {

        $userPrompt =
            "Determine whether this document can serve as valid English proficiency proof. "
          . "Accept both official test certificates and academic certificates or letters "
          . "explicitly confirming that the language of instruction was English. "
          . $nameInstruction;

    } elseif ($field === 'cv_resume') {

        $userPrompt =
            "Determine whether this document is a genuine Curriculum Vitae (CV) or Resume. "
          . "ACCEPT ONLY documents that clearly list employment history, job titles, "
          . "professional experience, internships, skills, or work responsibilities "
          . "in a standard CV or resume format. "
          . "REJECT English proficiency certificates, academic confirmation letters, "
          . "transcripts, diplomas, recommendation letters, passports, or personal statements. "
          . "If the document does not clearly look like a CV or resume, set valid=false. "
          . $nameInstruction;

    } else {

        $userPrompt =
            "Verify whether this document is a valid {$expectedType}. "
          . "Analyze content, structure, formatting, consistency, stamps, and signatures. "
          . "Do NOT require online or external verification. "
          . $nameInstruction;
    }


 // ✅ Correct payload for image, scanned PDF, or text PDF
if ($isImage) {

    $content = [
        ["type" => "input_text", "text" => $userPrompt],
        ["type" => "input_image", "image_url" => $dataUrl]
    ];

} elseif ($isScannedPdf) {

    $content = [
        ["type" => "input_text", "text" => $userPrompt]
    ];

    foreach ($isScannedPdfImages as $img) {
        $b64 = base64_encode(file_get_contents($img));
        $content[] = [
            "type" => "input_image",
            "image_url" => "data:image/jpeg;base64,$b64"
        ];
    }

} elseif ($fileId) {

    $content = [
        ["type" => "input_text", "text" => $userPrompt],
        ["type" => "input_file", "file_id" => $fileId]
    ];
}

}

// =========================================
// STEP 3️⃣ API CALL
// =========================================
function callResponsesApi(array $payload, string $key, int $max=3, int $delay=800): array {
    for ($i=0; $i<$max; $i++) {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$key}",
                "Content-Type: application/json"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $r = curl_exec($ch);
        $e = curl_error($ch);
        curl_close($ch);
        if ($e) return ['error'=>['message'=>$e]];
        $d = json_decode($r,true);
        if (!isset($d['error'])) return $d;
        if (str_contains(strtolower($d['error']['message']), 'ownership') && $i < $max-1) {
            usleep($delay*1000); continue;
        }
        return $d;
    }
    return ['error'=>['message'=>'Validation failed after retries']];
}

$payload = [
  "model" => "gpt-4.1-mini",
  "input" => [
    ["role" => "system", "content" => [["type" => "input_text", "text" => $systemPrompt]]],
    ["role" => "user", "content" => $content]
  ],
  "text" => ["format" => ["type" => "json_object"]]
];
$data = callResponsesApi($payload, $API_KEY);

// =========================================
// STEP 4️⃣ LOG & PARSE
// =========================================
file_put_contents(
  $LOG_FILE,
  "\n=== ".date('Y-m-d H:i:s')." ===\nField:$field\nApplicant:$fullName\nFile:$fileName\nResponse:\n".json_encode($data,JSON_PRETTY_PRINT)."\n",
  FILE_APPEND
);
if (isset($data['error'])) exit(json_encode(['status'=>'error','message'=>$data['error']['message']]));

$aiText = $data['output'][0]['content'][0]['text'] ?? '';
$ai = json_decode($aiText, true);
if (!$ai || !isset($ai['valid']))
    exit(json_encode(['status'=>'error','message'=>'Invalid AI response','debug'=>substr($aiText ?: json_encode($data),0,400)]));

// =========================================
// STEP 5️⃣ FINAL DECISION + SAVE (SAFE)
// =========================================

// Whitelisted document fields only
$allowedFileFields = [
    'degree_transcripts',
    'high_school_degree',
    'valid_passport',
    'recommendation_letters',
    'personal_statement',
    'cv_resume',
    'english_certificate',
    'birth_certificate',
    'payment_proof'
];

// Fields that allow MULTIPLE files
$multiFileFields = [
    'degree_transcripts',
    'recommendation_letters'
];

// -----------------------------------------
// 1️⃣ Decide if document is allowed to save
// -----------------------------------------
$shouldSave = false;

// 🚀 Payment proof always allowed
if ($field === 'payment_proof') {
    $shouldSave = true;
}

// 🔐 All other documents must pass AI validation
elseif ($ai['valid'] === true) {

    // Name mismatch → reject
    if (isset($ai['name_match']) && $ai['name_match'] === false) {

        unlink($tmpPath);

        echo json_encode([
            'status'  => 'error',
            'message' =>
                "⚠️ Name mismatch: found '{$ai['name_detected']}', expected '{$fullName}'."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $shouldSave = true;
}

// -----------------------------------------
// 2️⃣ Save ONLY if allowed
// -----------------------------------------
if ($shouldSave && $appId && in_array($field, $allowedFileFields, true)) {

    // Move file from temp → uploads
    if (!rename($tmpPath, $UPLOAD_DIR . $fileName)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Failed to finalize uploaded file'
        ]);
        exit;
    }

    $filePath = 'uploads/' . $fileName;

    // ---------- MULTI-FILE FIELD ----------
    if (in_array($field, $multiFileFields, true)) {

        $stmt = $conn->prepare(
            "SELECT {$field} FROM student_applications WHERE id = ?"
        );
        $stmt->bind_param('i', $appId);
        $stmt->execute();
        $stmt->bind_result($existing);
        $stmt->fetch();
        $stmt->close();

        $files = [];
        if (!empty($existing)) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $files = $decoded;
            }
        }

        $files[] = $filePath;
        $json = json_encode($files, JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare(
            "UPDATE student_applications SET {$field} = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $json, $appId);
        $stmt->execute();
        $stmt->close();

    }
    // ---------- SINGLE-FILE FIELD ----------
    else {

        $stmt = $conn->prepare(
            "UPDATE student_applications SET {$field} = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $filePath, $appId);
        $stmt->execute();
        $stmt->close();
    }

    // ✅ SUCCESS RESPONSE
    echo json_encode([
        'status'        => 'success',
        'file_path'     => $filePath,
        'confidence'    => $ai['confidence'] ?? null,
        'summary'       => $ai['summary'] ?? '',
        'name_detected' => $ai['name_detected'] ?? '',
        'name_match'    => $ai['name_match'] ?? null,
        'message'       =>
            "✅ Verified as {$ai['detected_type']} "
          . "(expected {$expectedType}). "
          . "Name confirmed."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// -----------------------------------------
// 3️⃣ Invalid document → cleanup & exit
// -----------------------------------------
unlink($tmpPath);

echo json_encode([
    'status'  => 'error',
    'message' => "❌ Not a valid {$expectedType}"
], JSON_UNESCAPED_UNICODE);
exit;


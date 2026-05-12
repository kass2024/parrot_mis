<?php
declare(strict_types=1);

ob_start();
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/load_env.php';
pcvc_load_dotenv(__DIR__);

$ENV_PATH = __DIR__ . '/.env';
$MODEL = 'gpt-4.1-mini';
$LOG_FILE = __DIR__ . '/upload_debug.log';
$TEMP_ROOT = __DIR__ . '/temp/autofill/';

function json_exit(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temp directory.');
    }
}

function add_stage(array &$debug, string $stage, string $detail): void
{
    $debug['stages'][] = [
        'stage' => $stage,
        'detail' => $detail,
        'time' => date('H:i:s')
    ];
}

function normalize_text(?string $value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string)$value);
}

function normalize_date(?string $value): string
{
    $value = normalize_text($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    $date = date('Y-m-d', $timestamp);
    if ($date < '1900-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
        return '';
    }

    return $date;
}

function normalize_gender(?string $value, string $lang): string
{
    $value = strtolower(normalize_text($value));
    if ($value === '') {
        return '';
    }

    if (in_array($value, ['male', 'man', 'm', 'homme', 'masculin'], true)) {
        return $lang === 'fr' ? 'Homme' : 'Male';
    }

    if (in_array($value, ['female', 'woman', 'f', 'femme', 'feminin'], true)) {
        return $lang === 'fr' ? 'Femme' : 'Female';
    }

    return '';
}

function normalize_language(?string $value, string $lang): string
{
    $value = strtolower(normalize_text($value));
    if ($value === '') {
        return '';
    }

    if (in_array($value, ['english', 'anglais'], true)) {
        return $lang === 'fr' ? 'Anglais' : 'English';
    }

    if (in_array($value, ['french', 'francais', 'français'], true)) {
        return $lang === 'fr' ? 'Français' : 'French';
    }

    if (in_array($value, ['other', 'autre'], true)) {
        return $lang === 'fr' ? 'Autre' : 'Other';
    }

    return '';
}

function normalize_email(?string $value): string
{
    $value = strtolower(normalize_text($value));
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    [$local] = explode('@', $value, 2);
    $genericLocals = [
        'info', 'contact', 'admin', 'office', 'admission', 'admissions',
        'apply', 'application', 'support', 'help', 'registrar', 'enquiry',
        'enquiries', 'inquiry', 'hello'
    ];
    if (in_array($local, $genericLocals, true)) {
        return '';
    }

    return $value;
}

function normalize_country_name(?string $value): string
{
    $value = strtolower(normalize_text($value));
    if ($value === '') {
        return '';
    }

    $value = str_replace(
        ['é', 'è', 'ê', 'ë', 'à', 'â', 'ä', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç'],
        ['e', 'e', 'e', 'e', 'a', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c'],
        $value
    );
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

function lookup_country_id(mysqli $conn, ?string $name): string
{
    $name = normalize_text($name);
    if ($name === '') {
        return '';
    }

    if (ctype_digit($name)) {
        return $name;
    }

    $stmt = $conn->prepare('SELECT id FROM countries WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->bind_result($id);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            return (string)$id;
        }
    }

    $like = '%' . $name . '%';
    $stmt = $conn->prepare('SELECT id FROM countries WHERE LOWER(name) LIKE LOWER(?) ORDER BY CHAR_LENGTH(name) ASC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt->bind_result($id);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            return (string)$id;
        }
    }

    return '';
}

function country_dial_code_from_name(?string $country): string
{
    $country = normalize_country_name($country);
    if ($country === '') {
        return '';
    }

    $codes = [
        'rwanda' => '250',
        'kenya' => '254',
        'uganda' => '256',
        'tanzania' => '255',
        'burundi' => '257',
        'democratic republic of congo' => '243',
        'dr congo' => '243',
        'congo kinshasa' => '243',
        'ethiopia' => '251',
        'eritrea' => '291',
        'djibouti' => '253',
        'somalia' => '252',
        'south sudan' => '211',
        'sudan' => '249',
        'zambia' => '260',
        'zimbabwe' => '263',
        'malawi' => '265',
        'mozambique' => '258',
        'namibia' => '264',
        'botswana' => '267',
        'south africa' => '27',
        'nigeria' => '234',
        'ghana' => '233',
        'cameroon' => '237',
        'senegal' => '221',
        'cote d ivoire' => '225',
        'ivory coast' => '225',
        'benin' => '229',
        'togo' => '228',
        'morocco' => '212',
        'algeria' => '213',
        'tunisia' => '216',
        'egypt' => '20',
        'india' => '91',
        'pakistan' => '92',
        'bangladesh' => '880',
        'nepal' => '977',
        'china' => '86',
        'canada' => '1',
        'united states' => '1',
        'usa' => '1',
        'united kingdom' => '44',
        'uk' => '44',
        'france' => '33',
        'germany' => '49',
        'belgium' => '32',
        'netherlands' => '31',
        'turkey' => '90',
        'united arab emirates' => '971',
        'uae' => '971',
        'saudi arabia' => '966',
        'qatar' => '974',
        'oman' => '968',
    ];

    return $codes[$country] ?? '';
}

function normalize_phone_pair(?string $value, array $countryHints = []): array
{
    $value = normalize_text($value);
    if ($value === '') {
        return ['area_code' => '', 'phone_number' => ''];
    }

    $hasPlus = str_starts_with($value, '+');
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null || $digits === '') {
        return ['area_code' => '', 'phone_number' => ''];
    }

    if ($hasPlus && preg_match('/^\+(\d{1,4})/', $value, $match)) {
        $areaDigits = $match[1];
        $phoneDigits = substr($digits, strlen($areaDigits));
        if ($phoneDigits !== false && $phoneDigits !== '') {
            return [
                'area_code' => '+' . $areaDigits,
                'phone_number' => $phoneDigits
            ];
        }
    }

    foreach ($countryHints as $hint) {
        $dialCode = country_dial_code_from_name($hint);
        if ($dialCode === '') {
            continue;
        }

        $phoneDigits = $digits;
        if (str_starts_with($phoneDigits, $dialCode)) {
            $phoneDigits = substr($phoneDigits, strlen($dialCode));
        } elseif (str_starts_with($phoneDigits, '0')) {
            $phoneDigits = ltrim($phoneDigits, '0');
        }

        if ($phoneDigits !== '' && preg_match('/^\d{6,15}$/', $phoneDigits)) {
            return [
                'area_code' => '+' . $dialCode,
                'phone_number' => $phoneDigits
            ];
        }
    }

    return ['area_code' => '', 'phone_number' => ''];
}

function normalize_fields(array $fields, string $lang, mysqli $conn): array
{
    $normalized = [];
    $stringFields = [
        'first_name',
        'last_name',
        'email',
        'passport_number',
        'student_national_id',
        'country_of_birth',
        'city_of_birth',
        'nationality',
        'second_nationality',
        'address_line1',
        'address_line2',
        'city',
        'state_province',
        'postal_code',
        'previous_institution_name',
        'previous_institution_city',
        'previous_institution_province',
        'previous_institution_country',
        'previous_institution_post_code',
        'father_first_name',
        'father_last_name',
        'mother_first_name',
        'mother_last_name'
    ];

    foreach ($stringFields as $field) {
        $value = normalize_text($fields[$field] ?? '');
        if ($value !== '') {
            $normalized[$field] = $value;
        }
    }

    if (!empty($normalized['email'])) {
        $normalized['email'] = normalize_email($normalized['email']);
        if ($normalized['email'] === '') {
            unset($normalized['email']);
        }
    }

    foreach (['dob', 'previous_study_start', 'previous_study_graduation'] as $dateField) {
        $date = normalize_date($fields[$dateField] ?? '');
        if ($date !== '') {
            $normalized[$dateField] = $date;
        }
    }

    $gender = normalize_gender($fields['gender'] ?? '', $lang);
    if ($gender !== '') {
        $normalized['gender'] = $gender;
    }

    $language = normalize_language($fields['language_of_instruction'] ?? '', $lang);
    if ($language !== '') {
        $normalized['language_of_instruction'] = $language;
    }

    if (!empty($normalized['passport_number'])) {
        $normalized['passport_number'] = strtoupper(preg_replace('/\s+/', '', $normalized['passport_number']));
    }

    if (!empty($normalized['student_national_id'])) {
        $normalized['student_national_id'] = strtoupper($normalized['student_national_id']);
    }

    foreach (['country_of_birth', 'nationality', 'second_nationality', 'previous_institution_country'] as $countryField) {
        $countryId = lookup_country_id($conn, $fields[$countryField] ?? '');
        if ($countryId !== '') {
            $normalized[$countryField] = $countryId;
        }
    }

    $phone = normalize_phone_pair(
        $fields['phone_international'] ?? '',
        [
            $fields['nationality'] ?? '',
            $fields['country_of_birth'] ?? '',
            $fields['previous_institution_country'] ?? '',
            $fields['address_line1'] ?? '',
            $fields['city'] ?? ''
        ]
    );
    if ($phone['area_code'] !== '' && $phone['phone_number'] !== '') {
        $normalized['area_code'] = $phone['area_code'];
        $normalized['phone_number'] = $phone['phone_number'];
    }

    return $normalized;
}

function flatten_uploaded_files(string $key): array
{
    if (empty($_FILES[$key])) {
        return [];
    }

    $files = $_FILES[$key];
    if (!is_array($files['name'])) {
        return [[
            'name' => $files['name'],
            'tmp_name' => $files['tmp_name'] ?? '',
            'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'] ?? 0,
            'client_index' => 0
        ]];
    }

    $out = [];
    foreach ($files['name'] as $index => $name) {
        $out[] = [
            'name' => $name,
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
            'client_index' => $index
        ];
    }

    return $out;
}

function scanned_pdf_to_images(string $pdfPath, string $outDir): array
{
    if (!class_exists('Imagick')) {
        throw new RuntimeException('Scanned PDF support requires Imagick on the server.');
    }

    $images = [];
    $im = new Imagick();
    $im->setResolution(200, 200);
    $im->readImage($pdfPath);

    foreach ($im as $i => $page) {
        $page->setImageFormat('jpeg');
        $page->setImageCompressionQuality(90);
        $out = $outDir . 'page_' . ($i + 1) . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $page->writeImage($out);
        $images[] = $out;
    }

    $im->clear();
    $im->destroy();

    return $images;
}

function is_scanned_pdf(string $pdfPath): bool
{
    $sample = @file_get_contents($pdfPath, false, null, 0, 5000);
    if ($sample === false || $sample === '') {
        return true;
    }

    return !preg_match('/[A-Za-z]{4,}/', $sample);
}

function upload_openai_file(string $filePath, string $mime, string $fileName, string $apiKey): string
{
    $ch = curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'purpose' => 'assistants',
            'file' => new CURLFile($filePath, $mime, $fileName)
        ]
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('OpenAI file upload failed: ' . $error);
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['id'])) {
        throw new RuntimeException('OpenAI file upload did not return a file id.');
    }

    return (string)$data['id'];
}

function call_responses_api(array $payload, string $apiKey, int $maxRetries = 3, int $delayMs = 800): array
{
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => ['message' => $error]];
        }

        $data = json_decode((string)$response, true);
        if (!isset($data['error'])) {
            return is_array($data) ? $data : ['error' => ['message' => 'Invalid API response']];
        }

        $message = strtolower((string)($data['error']['message'] ?? ''));
        if (str_contains($message, 'ownership') && $attempt < ($maxRetries - 1)) {
            usleep($delayMs * 1000);
            continue;
        }

        return $data;
    }

    return ['error' => ['message' => 'AI extraction failed after retries.']];
}

function response_text(array $data): string
{
    if (!empty($data['output_text']) && is_string($data['output_text'])) {
        return $data['output_text'];
    }

    if (!empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $entry) {
            if (empty($entry['content']) || !is_array($entry['content'])) {
                continue;
            }

            foreach ($entry['content'] as $content) {
                if (!empty($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
    }

    return '';
}

function decode_json_response(string $text): array
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return [];
    }

    $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed);
    $decoded = json_decode((string)$trimmed, true);
    return is_array($decoded) ? $decoded : [];
}

function build_document_content(string $tmpPath, string $originalName, string $apiKey, array &$cleanup): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = mime_content_type($tmpPath) ?: 'application/octet-stream';
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff'], true);

    if ($isImage) {
        $imageData = @file_get_contents($tmpPath);
        if ($imageData === false) {
            throw new RuntimeException('Unable to read image data.');
        }

        return [[
            'type' => 'input_image',
            'image_url' => 'data:' . $mime . ';base64,' . base64_encode($imageData)
        ]];
    }

    if ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new RuntimeException('Unable to read DOCX document.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) {
            throw new RuntimeException('DOCX document is empty.');
        }

        $text = normalize_text(strip_tags($xml));
        if ($text === '') {
            throw new RuntimeException('DOCX text could not be extracted.');
        }

        return [[
            'type' => 'input_text',
            'text' => "Document text:\n" . mb_substr($text, 0, 18000, 'UTF-8')
        ]];
    }

    if ($ext === 'pdf') {
        if (is_scanned_pdf($tmpPath)) {
            $scanDir = dirname($tmpPath) . '/scan_' . bin2hex(random_bytes(4)) . '/';
            ensure_dir($scanDir);
            $cleanup[] = rtrim($scanDir, '/');

            $images = scanned_pdf_to_images($tmpPath, $scanDir);
            $content = [];

            foreach ($images as $imagePath) {
                $cleanup[] = $imagePath;
                $imageData = @file_get_contents($imagePath);
                if ($imageData === false) {
                    continue;
                }
                $content[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:image/jpeg;base64,' . base64_encode($imageData)
                ];
            }

            if (!$content) {
                throw new RuntimeException('Scanned PDF pages could not be prepared for OCR.');
            }

            return $content;
        }

        $fileId = upload_openai_file($tmpPath, $mime, basename($originalName), $apiKey);
        return [[
            'type' => 'input_file',
            'file_id' => $fileId
        ]];
    }

    throw new RuntimeException('Unsupported file type. Please use PDF, DOCX, JPG, JPEG, PNG, or WEBP.');
}

function cleanup_paths(array $paths): void
{
    rsort($paths);
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }

        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            @rmdir($path);
        }
    }
}

function field_priority(string $field, string $source): int
{
    $preferences = [
        'first_name' => ['valid_passport', 'birth_certificate', 'cv_resume', 'degree_transcripts', 'high_school_degree'],
        'last_name' => ['valid_passport', 'birth_certificate', 'cv_resume', 'degree_transcripts', 'high_school_degree'],
        'dob' => ['valid_passport', 'birth_certificate', 'degree_transcripts', 'high_school_degree'],
        'gender' => ['valid_passport', 'birth_certificate'],
        'passport_number' => ['valid_passport'],
        'student_national_id' => ['valid_passport', 'birth_certificate'],
        'country_of_birth' => ['valid_passport', 'birth_certificate'],
        'city_of_birth' => ['valid_passport', 'birth_certificate'],
        'nationality' => ['valid_passport', 'birth_certificate', 'cv_resume'],
        'second_nationality' => ['valid_passport', 'birth_certificate'],
        'email' => ['cv_resume', 'personal_statement', 'recommendation_letters', 'payment_proof'],
        'area_code' => ['cv_resume', 'personal_statement'],
        'phone_number' => ['cv_resume', 'personal_statement'],
        'address_line1' => ['cv_resume', 'valid_passport', 'personal_statement'],
        'address_line2' => ['cv_resume', 'valid_passport', 'personal_statement'],
        'city' => ['cv_resume', 'valid_passport', 'personal_statement'],
        'state_province' => ['cv_resume', 'valid_passport', 'personal_statement'],
        'postal_code' => ['cv_resume', 'valid_passport', 'personal_statement'],
        'previous_institution_name' => ['degree_transcripts', 'high_school_degree', 'english_certificate'],
        'previous_institution_city' => ['degree_transcripts', 'high_school_degree'],
        'previous_institution_province' => ['degree_transcripts', 'high_school_degree'],
        'previous_institution_country' => ['degree_transcripts', 'high_school_degree'],
        'previous_institution_post_code' => ['degree_transcripts', 'high_school_degree'],
        'previous_study_start' => ['degree_transcripts', 'high_school_degree'],
        'previous_study_graduation' => ['degree_transcripts', 'high_school_degree'],
        'language_of_instruction' => ['english_certificate', 'degree_transcripts', 'high_school_degree'],
        'father_first_name' => ['birth_certificate'],
        'father_last_name' => ['birth_certificate'],
        'mother_first_name' => ['birth_certificate'],
        'mother_last_name' => ['birth_certificate']
    ];

    $list = $preferences[$field] ?? ['valid_passport', 'cv_resume', 'degree_transcripts', 'high_school_degree', 'birth_certificate'];
    $index = array_search($source, $list, true);
    return $index === false ? 0 : (count($list) - $index);
}

function merge_candidate_fields(array &$merged, array &$scores, array $candidate, string $source, float $confidence): void
{
    foreach ($candidate as $field => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        $score = (field_priority($field, $source) * 100) + (int)round($confidence * 100);
        if (!isset($scores[$field]) || $score > $scores[$field]) {
            $merged[$field] = $value;
            $scores[$field] = $score;
        }
    }
}

$debug = [
    'api_key_status' => 'unknown',
    'env_path' => $ENV_PATH,
    'log_file' => $LOG_FILE,
    'model' => $MODEL,
    'documents_received' => 0,
    'stages' => []
];

if (empty($_SESSION['user_id'])) {
    $debug['api_key_status'] = 'not_checked';
    add_stage($debug, 'prepare', 'Session user id missing.');
    json_exit([
        'status' => 'error',
        'message' => 'User session is missing. Please reload the application form.',
        'debug' => $debug
    ], 401);
}

$apiKey = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
if ($apiKey === '') {
    $debug['api_key_status'] = 'missing';
    add_stage($debug, 'prepare', 'OPENAI_API_KEY not found in .env.');
    json_exit([
        'status' => 'error',
        'message' => 'AI document autofill is not configured. Set OPENAI_API_KEY in .env on the server.',
        'debug' => $debug
    ], 500);
}

$debug['api_key_status'] = 'configured';
$lang = (($_POST['lang'] ?? 'en') === 'fr') ? 'fr' : 'en';
$uploadedFiles = flatten_uploaded_files('documents');
$debug['documents_received'] = count($uploadedFiles);
add_stage($debug, 'prepare', 'Batch upload accepted.');

if (!$uploadedFiles) {
    json_exit([
        'status' => 'error',
        'message' => 'Please choose at least one document.',
        'debug' => $debug
    ], 400);
}

ensure_dir($TEMP_ROOT);

$fieldLabels = [
    'degree_transcripts' => $lang === 'fr' ? 'Diplomes / Releves de Notes' : 'Degree / Academic Transcripts',
    'high_school_degree' => $lang === 'fr' ? 'Certificat de Lycee' : 'High School Certificate',
    'valid_passport' => $lang === 'fr' ? 'Passeport Valide' : 'Valid Passport',
    'recommendation_letters' => $lang === 'fr' ? 'Lettres de Recommandation' : 'Recommendation Letter(s)',
    'personal_statement' => $lang === 'fr' ? 'Lettre de Motivation' : 'Personal Statement / Motivation Letter',
    'cv_resume' => $lang === 'fr' ? 'CV / Curriculum Vitae' : 'CV / Resume',
    'english_certificate' => $lang === 'fr' ? 'Certificat d Anglais' : 'English Proficiency Certificate',
    'birth_certificate' => $lang === 'fr' ? 'Certificat de Naissance' : 'Birth Certificate',
    'payment_proof' => $lang === 'fr' ? 'Preuve de Paiement' : 'Application / Payment Proof'
];

$systemPrompt = <<<PROMPT
You are an admissions document classification and extraction assistant.

Classify each uploaded document into exactly one of:
- valid_passport
- degree_transcripts
- high_school_degree
- cv_resume
- recommendation_letters
- personal_statement
- english_certificate
- birth_certificate
- payment_proof
- unknown

Rules:
1. Extract only applicant facts explicitly visible in the document.
2. Never invent data.
3. If the document mostly refers to someone other than the applicant, keep fields empty.
4. Recommendation letters may mention other people; only extract student data if it is clearly about the applicant.
5. Return country names, not codes.
6. When the document is a CV or resume, prioritize extracting the main contact block first: email, phone, address, city, nationality, and education institution details.
7. For CV/resume documents, if the phone is written locally but the country is explicit elsewhere in the same document, convert it to a full international number in phone_international.
8. Return the strongest real applicant email address visible in the document, not a school or company address unless it is clearly the applicant contact.
9. Ignore sample, placeholder, dummy, or template contact details.
10. Return JSON only.

JSON schema:
{
  "document_type": "valid_passport|degree_transcripts|high_school_degree|cv_resume|recommendation_letters|personal_statement|english_certificate|birth_certificate|payment_proof|unknown",
  "confidence": 0.0,
  "summary": "short summary",
  "fields": {
    "first_name": "",
    "last_name": "",
    "email": "",
    "phone_international": "",
    "dob": "",
    "gender": "",
    "passport_number": "",
    "student_national_id": "",
    "country_of_birth": "",
    "city_of_birth": "",
    "nationality": "",
    "second_nationality": "",
    "address_line1": "",
    "address_line2": "",
    "city": "",
    "state_province": "",
    "postal_code": "",
    "previous_institution_name": "",
    "previous_institution_city": "",
    "previous_institution_province": "",
    "previous_institution_country": "",
    "previous_institution_post_code": "",
    "previous_study_start": "",
    "previous_study_graduation": "",
    "language_of_instruction": "",
    "father_first_name": "",
    "father_last_name": "",
    "mother_first_name": "",
    "mother_last_name": ""
  }
}
PROMPT;

$mergedFields = [];
$fieldScores = [];
$documents = [];
$warnings = [];

foreach ($uploadedFiles as $file) {
    $originalName = basename((string)($file['name'] ?? 'document'));
    $clientIndex = (int)($file['client_index'] ?? 0);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        $warnings[] = $originalName . ': upload failed before analysis.';
        continue;
    }

    if ((int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
        $warnings[] = $originalName . ': file is too large (max 15MB).';
        continue;
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'docx', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        $warnings[] = $originalName . ': unsupported file type.';
        continue;
    }

    $tempName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $originalName);
    $tempPath = $TEMP_ROOT . $tempName;
    $cleanup = [$tempPath];

    if (!move_uploaded_file((string)$file['tmp_name'], $tempPath)) {
        $warnings[] = $originalName . ': failed to prepare the file for AI extraction.';
        continue;
    }

    try {
        add_stage($debug, 'extract', 'Preparing ' . $originalName . ' for AI.');
        $fileNameHint = strtolower($originalName);
        $docInstruction = 'Classify this document and extract applicant fields.';
        if (str_contains($fileNameHint, 'cv') || str_contains($fileNameHint, 'resume')) {
            $docInstruction .= ' This file is likely a CV/resume, so prioritize extracting applicant email, phone, address, nationality, and education history.';
        } elseif (str_contains($fileNameHint, 'passport')) {
            $docInstruction .= ' This file may be a passport, so prioritize legal identity fields like first name, last name, date of birth, nationality, and passport number.';
        } elseif (str_contains($fileNameHint, 'transcript') || str_contains($fileNameHint, 'degree')) {
            $docInstruction .= ' This file may be an academic record, so prioritize previous institution, study dates, and language of instruction.';
        }
        $content = [
            [
                'type' => 'input_text',
                'text' => 'File name: ' . $originalName . "\n" . $docInstruction
            ]
        ];
        $content = array_merge($content, build_document_content($tempPath, $originalName, $apiKey, $cleanup));

        $payload = [
            'model' => $MODEL,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $systemPrompt
                    ]]
                ],
                [
                    'role' => 'user',
                    'content' => $content
                ]
            ],
            'text' => ['format' => ['type' => 'json_object']]
        ];

        add_stage($debug, 'ai', 'Sending ' . $originalName . ' to OpenAI.');
        $response = call_responses_api($payload, $apiKey);
        if (isset($response['error'])) {
            throw new RuntimeException((string)($response['error']['message'] ?? 'AI extraction failed.'));
        }

        $ai = decode_json_response(response_text($response));
        if (!$ai || empty($ai['document_type'])) {
            throw new RuntimeException('AI returned an invalid extraction result.');
        }

        $documentType = (string)$ai['document_type'];
        $confidence = max(0.0, min(1.0, (float)($ai['confidence'] ?? 0)));
        $summary = normalize_text((string)($ai['summary'] ?? ''));

        if (!array_key_exists($documentType, $fieldLabels) || $confidence < 0.45) {
            $documents[] = [
                'client_index' => $clientIndex,
                'original_name' => $originalName,
                'field' => '',
                'field_label' => '',
                'confidence' => $confidence,
                'summary' => $summary
            ];
            $warnings[] = $originalName . ': the document could not be matched confidently to a supported attachment field.';
            cleanup_paths($cleanup);
            continue;
        }

        $normalized = normalize_fields((array)($ai['fields'] ?? []), $lang, $conn);
        merge_candidate_fields($mergedFields, $fieldScores, $normalized, $documentType, $confidence);

        $documents[] = [
            'client_index' => $clientIndex,
            'original_name' => $originalName,
            'field' => $documentType,
            'field_label' => $fieldLabels[$documentType],
            'confidence' => $confidence,
            'summary' => $summary
        ];
        add_stage($debug, 'parse', 'Parsed ' . $originalName . ' as ' . $documentType . '.');
    } catch (Throwable $e) {
        $warnings[] = $originalName . ': ' . $e->getMessage();
    }

    cleanup_paths($cleanup);
}

file_put_contents(
    $LOG_FILE,
    "\n=== " . date('Y-m-d H:i:s') . " ===\nBATCH AUTOFILL DEBUG\n" . json_encode([
        'documents' => $documents,
        'warnings' => $warnings,
        'fields' => $mergedFields,
        'debug' => $debug
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND
);

if (!$documents && !$mergedFields) {
    add_stage($debug, 'parse', 'No usable documents were analyzed successfully.');
    json_exit([
        'status' => 'error',
        'message' => 'No document could be analyzed successfully. Please try clearer passport, CV, or academic files.',
        'warnings' => $warnings,
        'debug' => $debug
    ], 422);
}

add_stage($debug, 'save', 'Batch analysis completed successfully.');
$uploadToken = bin2hex(random_bytes(16));
$_SESSION['smart_autofill_batch_upload_token'] = $uploadToken;
$_SESSION['smart_autofill_batch_upload_token_expires'] = time() + 900;
json_exit([
    'status' => 'success',
    'message' => 'Documents analyzed successfully.',
    'fields' => $mergedFields,
    'documents' => $documents,
    'warnings' => $warnings,
    'upload_token' => $uploadToken,
    'debug' => $debug
]);

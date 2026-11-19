<?php
declare(strict_types=1);

/**
 * Canada general-workers recruitment — live file upload.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request');
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $map = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted — please try again',
            UPLOAD_ERR_NO_FILE => 'No file received',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write file',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension',
        ];
        throw new RuntimeException($map[$code] ?? ('Upload failed (code ' . $code . ')'));
    }

    $field = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['field'] ?? '')));
    $rules = [
        'passport' => ['ext' => ['pdf', 'jpg', 'jpeg', 'png'], 'max' => 10 * 1024 * 1024, 'label' => 'PDF, JPG, JPEG, or PNG'],
        'cv' => ['ext' => ['pdf', 'doc', 'docx'], 'max' => 10 * 1024 * 1024, 'label' => 'PDF, DOC, or DOCX'],
        'photo' => ['ext' => ['jpg', 'jpeg', 'png'], 'max' => 5 * 1024 * 1024, 'label' => 'JPG, JPEG, or PNG'],
    ];
    if (!isset($rules[$field])) {
        throw new RuntimeException('Unknown upload field');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $rules[$field]['ext'], true)) {
        throw new RuntimeException('Accepted formats: ' . $rules[$field]['label']);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Empty file');
    }
    if ($size > $rules[$field]['max']) {
        $mb = (int) ($rules[$field]['max'] / (1024 * 1024));
        throw new RuntimeException('File too large (max ' . $mb . 'MB)');
    }

    if ($field === 'photo') {
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('Please upload a real JPG or PNG photograph');
        }
    }

    $permDir = __DIR__ . '/uploads/canada_workers';
    if (!is_dir($permDir) && !mkdir($permDir, 0755, true) && !is_dir($permDir)) {
        throw new RuntimeException('Cannot create upload directory');
    }

    $filename = 'cuw_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '_' . $field . '.' . $ext;
    $dest = $permDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save file');
    }

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded',
        'file_path' => 'uploads/canada_workers/' . $filename,
        'original_name' => $file['name'],
        'size' => $size,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

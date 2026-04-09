<?php
// download_file.php
require_once __DIR__ . '/db.php';
if (isset($_GET['id'])) {
    $fileId = intval($_GET['id']);
    
    $query = "SELECT * FROM upafa_registration_files WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($file = $result->fetch_assoc()) {
        $filePath = $file['storage_path'];
        $fileName = $file['original_name'];
        
        // Check if file exists
        if (file_exists($filePath)) {
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            
            // Clear output buffer
            ob_clean();
            flush();
            
            // Read file
            readfile($filePath);
            exit;
        } else {
            die('File not found on server.');
        }
    } else {
        die('File not found in database.');
    }
} else {
    die('No file specified.');
}
?>
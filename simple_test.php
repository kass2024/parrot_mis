<?php
// Simple test to check if file upload works
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>POST Data Received:</h2>";
    echo "<pre>";
    echo "FILES:\n";
    print_r($_FILES);
    echo "\nPOST:\n";
    print_r($_POST);
    echo "\nSESSION:\n";
    print_r($_SESSION);
    echo "</pre>";
    
    if (!empty($_FILES['test_file']['name'])) {
        $uploadDir = 'uploads/tmp/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'test_' . time() . '_' . $_FILES['test_file']['name'];
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['test_file']['tmp_name'], $targetPath)) {
            echo "<h3 style='color: green;'>File uploaded successfully to: $targetPath</h3>";
        } else {
            echo "<h3 style='color: red;'>Failed to move uploaded file</h3>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Simple Upload Test</title>
</head>
<body>
    <h2>Simple File Upload Test</h2>
    
    <form method="POST" enctype="multipart/form-data">
        <p>
            <label>File:</label>
            <input type="file" name="test_file" required>
        </p>
        <p>
            <label>Field:</label>
            <input type="text" name="field" value="test">
        </p>
        <p>
            <button type="submit">Upload</button>
        </p>
    </form>
    
    <hr>
    
    <h3>Current Session Info:</h3>
    <pre>
    Session ID: <?php echo session_id(); ?>
    Session Name: <?php echo session_name(); ?>
    User ID: <?php echo $_SESSION['user_id'] ?? 'Not set'; ?>
    </pre>
</body>
</html>

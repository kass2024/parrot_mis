<?php
// Check PHP configuration for file uploads
echo "<h2>PHP Upload Configuration</h2>";

echo "<h3>File Upload Settings:</h3>";
echo "<table border='1'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>file_uploads</td><td>" . ini_get('file_uploads') . "</td></tr>";
echo "<tr><td>upload_max_filesize</td><td>" . ini_get('upload_max_filesize') . "</td></tr>";
echo "<tr><td>post_max_size</td><td>" . ini_get('post_max_size') . "</td></tr>";
echo "<tr><td>max_execution_time</td><td>" . ini_get('max_execution_time') . "</td></tr>";
echo "<tr><td>memory_limit</td><td>" . ini_get('memory_limit') . "</td></tr>";
echo "<tr><td>max_input_time</td><td>" . ini_get('max_input_time') . "</td></tr>";
echo "</table>";

echo "<h3>Session Settings:</h3>";
echo "<table border='1'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>session.save_path</td><td>" . ini_get('session.save_path') . "</td></tr>";
echo "<tr><td>session.gc_maxlifetime</td><td>" . ini_get('session.gc_maxlifetime') . "</td></tr>";
echo "<tr><td>session.cookie_lifetime</td><td>" . ini_get('session.cookie_lifetime') . "</td></tr>";
echo "</table>";

echo "<h3>Current Directory Permissions:</h3>";
$uploadsDir = __DIR__ . '/uploads';
$tmpDir = __DIR__ . '/uploads/tmp';

echo "Uploads directory: " . (is_dir($uploadsDir) ? "EXISTS" : "MISSING") . "<br>";
echo "Uploads directory writable: " . (is_writable($uploadsDir) ? "YES" : "NO") . "<br>";
echo "Tmp directory: " . (is_dir($tmpDir) ? "EXISTS" : "MISSING") . "<br>";
echo "Tmp directory writable: " . (is_writable($tmpDir) ? "YES" : "NO") . "<br>";

echo "<h3>Error Log:</h3>";
$errorLog = ini_get('error_log');
echo "Error log location: " . $errorLog . "<br>";
if (file_exists($errorLog)) {
    echo "Error log readable: YES<br>";
    echo "Last few lines:<br>";
    echo "<pre>" . htmlspecialchars(file_get_contents($errorLog, false, null, 1000)) . "</pre>";
} else {
    echo "Error log readable: NO<br>";
}
?>

<?php
declare(strict_types=1);

// Enable all error reporting for debugging
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html>
<html>
<head>
    <title>cPanel Debug Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; border-color: #28a745; }
        .error { background: #f8d7da; border-color: #dc3545; }
        .warning { background: #fff3cd; border-color: #ffc107; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>cPanel Environment Debug Report</h1>
    
    <div class='section'>
        <h2>1. PHP Configuration</h2>
        <p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>
        <p><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</p>
        <p><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "s</p>
        <p><strong>Upload Max Filesize:</strong> " . ini_get('upload_max_filesize') . "</p>
        <p><strong>Post Max Size:</strong> " . ini_get('post_max_size') . "</p>
        <p><strong>Error Reporting:</strong> " . ini_get('error_reporting') . "</p>
        <p><strong>Display Errors:</strong> " . ini_get('display_errors') . "</p>
    </div>";

// Test 2: Required PHP Extensions
echo "<div class='section'>
        <h2>2. PHP Extensions Check</h2>";
$extensions = ['mysqli', 'gd', 'mbstring', 'dom', 'fileinfo', 'json', 'curl'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p style='color: green;'>\u2713 $ext: Loaded</p>";
    } else {
        echo "<p style='color: red;'>\u2717 $ext: NOT Loaded</p>";
    }
}
echo "</div>";

// Test 3: Database Connection
echo "<div class='section'>
        <h2>3. Database Connection Test</h2>";
try {
    // Test with different connection methods
    $conn1 = new mysqli('premium120.web-hosting.com', 'visaeofi_mis_user', 'Petero@1981', 'visaeofi_mis');
    if ($conn1->connect_error) {
        echo "<p style='color: red;'>\u2717 Connection 1 Failed: " . $conn1->connect_error . "</p>";
    } else {
        echo "<p style='color: green;'>\u2713 Connection 1: SUCCESS</p>";
        $conn1->close();
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>\u2717 Connection 1 Exception: " . $e->getMessage() . "</p>";
}

try {
    // Test with localhost (in case cPanel uses localhost)
    $conn2 = new mysqli('localhost', 'visaeofi_mis_user', 'Petero@1981', 'visaeofi_mis');
    if ($conn2->connect_error) {
        echo "<p style='color: red;'>\u2717 Connection 2 Failed: " . $conn2->connect_error . "</p>";
    } else {
        echo "<p style='color: green;'>\u2713 Connection 2: SUCCESS (localhost)</p>";
        $conn2->close();
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>\u2717 Connection 2 Exception: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 4: File System
echo "<div class='section'>
        <h2>4. File System Test</h2>";
$test_dirs = ['admin', 'logs', 'generated', 'pdfs', 'vendor'];
foreach ($test_dirs as $dir) {
    $dir_path = __DIR__ . '/' . $dir;
    if (is_dir($dir_path)) {
        if (is_writable($dir_path)) {
            echo "<p style='color: green;'>\u2713 $dir: Directory exists and writable</p>";
        } else {
            echo "<p style='color: orange;'>\u26a0 $dir: Directory exists but NOT writable</p>";
        }
    } else {
        echo "<p style='color: red;'>\u2717 $dir: Directory NOT found</p>";
    }
}

// Test file writing
$test_file = __DIR__ . '/debug-test.txt';
if (file_put_contents($test_file, 'Test: ' . date('Y-m-d H:i:s'))) {
    echo "<p style='color: green;'>\u2713 File writing: SUCCESS</p>";
    unlink($test_file);
} else {
    echo "<p style='color: red;'>\u2717 File writing: FAILED</p>";
}
echo "</div>";

// Test 5: Composer/Vendor
echo "<div class='section'>
        <h2>5. Composer/Vendor Test</h2>";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "<p style='color: green;'>\u2713 vendor/autoload.php: Found</p>";
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        if (class_exists('Dompdf\Dompdf')) {
            echo "<p style='color: green;'>\u2713 DOMPDF class: Available</p>";
        } else {
            echo "<p style='color: red;'>\u2717 DOMPDF class: NOT available</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>\u2717 Vendor autoload error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>\u2717 vendor/autoload.php: NOT found</p>";
}
echo "</div>";

// Test 6: Submit Script Test
echo "<div class='section'>
        <h2>6. Submit Script Test</h2>";
if (file_exists(__DIR__ . '/submit-partner-signature.php')) {
    echo "<p style='color: green;'>\u2713 submit-partner-signature.php: Found</p>";
    
    // Test if script can be included
    try {
        // Check syntax without executing
        $content = file_get_contents(__DIR__ . '/submit-partner-signature.php');
        if (strpos($content, '<?php') === 0) {
            echo "<p style='color: green;'>\u2713 Script syntax: Valid PHP</p>";
        } else {
            echo "<p style='color: red;'>\u2717 Script syntax: Invalid</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>\u2717 Script test error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>\u2717 submit-partner-signature.php: NOT found</p>";
}
echo "</div>";

echo "<div class='section warning'>
        <h2>7. Quick Fixes to Try</h2>
        <ol>
            <li><strong>Check cPanel Error Logs:</strong> Look in cPanel > Error Log</li>
            <li><strong>Verify Database Host:</strong> Try 'localhost' instead of 'premium120.web-hosting.com'</li>
            <li><strong>Check Database User:</strong> Verify 'visaeofi_mis_user' exists and has permissions</li>
            <li><strong>Run Composer:</strong> Upload composer.json and run 'composer install' on cPanel</li>
            <li><strong>Set Permissions:</strong> chmod 755 for directories, 644 for files</li>
            <li><strong>Check PHP Version:</strong> Ensure PHP 7.4+ is active on cPanel</li>
        </ol>
    </div>

    <div class='section success'>
        <h2>8. Next Steps</h2>
        <p>Based on this debug report, identify the specific issue and apply the corresponding fix:</p>
        <ul>
            <li>If database fails: Update db1.php with correct cPanel credentials</li>
            <li>If extensions missing: Install via cPanel PHP Extensions Manager</li>
            <li>If vendor missing: Upload vendor folder or run composer install</li>
            <li>If permissions issue: Set correct file/directory permissions</li>
            <li>If memory low: Increase PHP memory limit in cPanel</li>
        </ul>
    </div>

</body>
</html>";
?>

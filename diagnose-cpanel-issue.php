<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>cPanel Issue Diagnosis</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .issue { background: #fff3cd; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .solution { background: #d4edda; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #28a745; }
        .warning { background: #f8d7da; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .code { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; font-family: 'Courier New', monospace; }
        .test-result { background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #2196f3; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>cPanel Issue Diagnosis</h1>
        <p><strong>Problem:</strong> Contract submission works locally but fails on cPanel with \"Submission failed\" error</p>
        
        <div class='issue'>
            <h3>Identified Issues</h3>
            <ul>
                <li><strong>Database Connection:</strong> Using hardcoded cPanel host (premium120.web-hosting.com)</li>
                <li><strong>File Path Issues:</strong> Possible absolute path problems on cPanel</li>
                <li><strong>PHP Extensions:</strong> Missing required extensions on cPanel server</li>
                <li><strong>Memory Limits:</strong> PHP memory limits affecting large PDF generation</li>
                <li><strong>Timeout Issues:</strong> Script execution timeouts on cPanel</li>
                <li><strong>Permission Issues:</strong> File/directory permission problems</li>
            </ul>
        </div>
        
        <div class='solution'>
            <h3>Recommended Solutions</h3>
            <ul>
                <li><strong>Test Database Connection:</strong> Verify cPanel database connectivity</li>
                <li><strong>Check Error Logs:</strong> Review cPanel error logs for specific issues</li>
                <li><strong>Verify PHP Extensions:</strong> Ensure GD, DOMPDF, and required extensions are installed</li>
                <li><strong>Increase Memory:</strong> Set higher memory limits for PDF generation</li>
                <li><strong>Check File Paths:</strong> Use absolute paths for cPanel environment</li>
                <li><strong>Verify Permissions:</strong> Ensure proper file/directory permissions</li>
                <li><strong>Test Individual Components:</strong> Isolate and test each part of the process</li>
            </ul>
        </div>
        
        <div class='code'>
            <h3>Diagnostic Tests</h3>
            <p>Running diagnostic checks...</p>";
        
        // Test 1: Database Connection
        echo "<div class='test-result'><h4>Test 1: Database Connection</h4>";
        try {
            require_once 'db1.php';
            $conn = new mysqli('premium120.web-hosting.com', 'visaeofi_mis_user', 'Petero@1981', 'visaeofi_mis');
            if ($conn->connect_error) {
                echo "<span style='color: red;'>❌ FAILED: " . $conn->connect_error . "</span>";
            } else {
                echo "<span style='color: green;'>✅ SUCCESS: Connected to cPanel database</span>";
                
                // Test 2: Check if partner_contracts table exists
                $result = $conn->query("SHOW TABLES LIKE 'partner_contracts'");
                if ($result && $result->num_rows > 0) {
                    echo "<br><span style='color: green;'>✅ Table 'partner_contracts' exists</span>";
                } else {
                    echo "<br><span style='color: red;'>❌ Table 'partner_contracts' NOT FOUND</span>";
                }
                
                // Test 3: Check partner_signatures table
                $result2 = $conn->query("SHOW TABLES LIKE 'partner_signatures'");
                if ($result2 && $result2->num_rows > 0) {
                    echo "<br><span style='color: green;'>✅ Table 'partner_signatures' exists</span>";
                } else {
                    echo "<br><span style='color: red;'>❌ Table 'partner_signatures' NOT FOUND</span>";
                }
                
                $conn->close();
            }
        } catch (Exception $e) {
            echo "<span style='color: red;'>❌ EXCEPTION: " . $e->getMessage() . "</span>";
        }
        echo "</div>";
        
        // Test 4: PHP Extensions
        echo "<div class='test-result'><h4>Test 2: PHP Extensions</h4>";
        $required_extensions = ['gd', 'mysqli', 'mbstring', 'dom', 'fileinfo'];
        foreach ($required_extensions as $ext) {
            if (extension_loaded($ext)) {
                echo "<br><span style='color: green;'>✅ $ext: Loaded</span>";
            } else {
                echo "<br><span style='color: red;'>❌ $ext: NOT LOADED</span>";
            }
        }
        echo "</div>";
        
        // Test 5: DOMPDF Configuration
        echo "<div class='test-result'><h4>Test 3: DOMPDF Configuration</h4>";
        if (class_exists('Dompdf\Dompdf')) {
            echo "<br><span style='color: green;'>✅ DOMPDF class exists</span>";
            
            // Test DOMPDF functionality
            try {
                $dompdf = new Dompdf();
                $html = '<html><body>Test PDF Generation</body></html>';
                $dompdf->loadHtml($html);
                $dompdf->render();
                echo "<br><span style='color: green;'>✅ DOMPDF functionality: Working</span>";
            } catch (Exception $e) {
                echo "<br><span style='color: red;'>❌ DOMPDF functionality: FAILED - " . $e->getMessage() . "</span>";
            }
        } else {
            echo "<br><span style='color: red;'>❌ DOMPDF class NOT FOUND</span>";
        }
        echo "</div>";
        
        // Test 6: File Permissions
        echo "<div class='test-result'><h4>Test 4: File System Permissions</h4>";
        $test_dirs = ['admin', 'logs', 'generated', 'pdfs'];
        foreach ($test_dirs as $dir) {
            $dir_path = __DIR__ . '/' . $dir;
            if (is_dir($dir_path)) {
                if (is_writable($dir_path)) {
                    echo "<br><span style='color: green;'>✅ $dir: Writable</span>";
                } else {
                    echo "<br><span style='color: red;'>❌ $dir: NOT WRITABLE</span>";
                }
            } else {
                echo "<br><span style='color: orange;'>⚠️ $dir: Directory not found</span>";
            }
        }
        echo "</div>";
        
        // Test 7: Memory and Time Limits
        echo "<div class='test-result'><h4>Test 5: PHP Configuration</h4>";
        echo "<br><span style='color: blue;'>Memory Limit: " . ini_get('memory_limit') . "</span>";
        echo "<br><span style='color: blue;'>Max Execution Time: " . ini_get('max_execution_time') . "s</span>";
        echo "<br><span style='color: blue;'>Upload Max Filesize: " . ini_get('upload_max_filesize') . "</span>";
        echo "<br><span style='color: blue;'>Post Max Size: " . ini_get('post_max_size') . "</span>";
        echo "</div>";
        
        echo "
        <div class='warning'>
            <h3>Immediate Actions Required</h3>
            <ol>
                <li><strong>Check cPanel Error Logs:</strong> Look for specific error messages in cPanel error logs</li>
                <li><strong>Verify Database Credentials:</strong> Ensure database credentials are correct for cPanel</li>
                <li><strong>Test Submission API:</strong> Manually test the submit-partner-signature.php endpoint</li>
                <li><strong>Check File Paths:</strong> Verify all file paths work in cPanel environment</li>
                <li><strong>Increase PHP Memory:</strong> Set memory_limit to 512M or higher in cPanel</li>
                <li><strong>Enable Error Display:</strong> Temporarily enable display_errors for debugging</li>
            </ol>
        </div>
        
        <div class='solution'>
            <h3>Code Fixes for cPanel Compatibility</h3>
            <p>Add these lines to submit-partner-signature.php for better error handling:</p>
            <pre style='background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto;'>
// Add at the beginning after require_once statements
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Add better error handling in the try-catch block
try {
    // Existing code here
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    fail("Database error: " . $e->getMessage(), 500, [
        "sql_error" => $e->getMessage(),
        "sql_code" => $e->getCode()
    ]);
} catch (Exception $e) {
    $conn->rollback();
    fail("System error: " . $e->getMessage(), 500, [
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "trace" => $e->getTraceAsString()
    ]);
}
            </pre>
        </div>
    </div>
</body>
</html>";
?>

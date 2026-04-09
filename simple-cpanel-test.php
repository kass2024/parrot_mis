<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>cPanel Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .result { margin: 10px 0; padding: 15px; border-radius: 4px; }
        .success { background: #d4edda; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>cPanel Connection & Configuration Test</h1>
        
        <div class='result success'>
            <h3>✅ Database Connection Test</h3>
            <p>Testing connection to cPanel database...</p>";
        
        try {
            require_once 'db1.php';
            $conn = new mysqli('premium120.web-hosting.com', 'visaeofi_mis_user', 'Petero@1981', 'visaeofi_mis');
            
            if ($conn->connect_error) {
                echo "<p style='color: red;'>❌ Connection failed: " . $conn->connect_error . "</p>";
            } else {
                echo "<p style='color: green;'>✅ Connected successfully to cPanel database</p>";
                
                // Test basic query
                $result = $conn->query("SELECT COUNT(*) as count FROM partner_contracts");
                if ($result) {
                    $row = $result->fetch_assoc();
                    echo "<p style='color: green;'>✅ Database query working: Found " . $row['count'] . " contracts</p>";
                } else {
                    echo "<p style='color: red;'>❌ Database query failed</p>";
                }
                
                // Test file writing
                $test_file = __DIR__ . '/test-cpanel-write.txt';
                if (file_put_contents($test_file, 'cPanel write test: ' . date('Y-m-d H:i:s'))) {
                    echo "<p style='color: green;'>✅ File writing working</p>";
                } else {
                    echo "<p style='color: red;'>❌ File writing failed</p>";
                }
                
                $conn->close();
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Exception: " . $e->getMessage() . "</p>";
        }
        
        echo "</div>";
        
        <div class='result warning'>
            <h3>⚠️ Common cPanel Issues & Solutions</h3>
            <ul>
                <li><strong>PHP Memory Limit:</strong> cPanel may have lower memory than localhost</li>
                <li><strong>Execution Time Limit:</strong> Scripts may timeout faster on cPanel</li>
                <li><strong>File Path Issues:</strong> Absolute paths may differ between environments</li>
                <li><strong>Extension Differences:</strong> cPanel may lack certain PHP extensions</li>
                <li><strong>Permission Issues:</strong> File/directory permissions may be restrictive</li>
            </ul>
        </div>
        
        <div class='result'>
            <h3>🔧 Recommended Fixes for submit-partner-signature.php</h3>
            <pre style='background: #f8f9fa; padding: 10px; border-radius: 4px;'>
// Add these lines at the top after require_once statements
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Add error handling improvements
try {
    // Your existing submission code
} catch (mysqli_sql_exception \$e) {
    \$conn->rollback();
    fail(\"Database error: \" . \$e->getMessage(), 500);
} catch (Exception \$e) {
    \$conn->rollback();
    fail(\"System error: \" . \$e->getMessage(), 500);
}

// Add memory and time limit increases
ini_set('memory_limit', '512M');
set_time_limit(300); // 5 minutes
            </pre>
        </div>
        
        <div class='result success'>
            <h3>📋 Quick Test Steps</h3>
            <ol>
                <li><strong>Upload this file to cPanel:</strong> simple-cpanel-test.php</li>
                <li><strong>Access via browser:</strong> yourdomain.com/simple-cpanel-test.php</li>
                <li><strong>Check results:</strong> Review database connection and file writing tests</li>
                <li><strong>If tests pass:</strong> The issue is likely in submission process logic</li>
                <li><strong>If tests fail:</strong> The issue is cPanel configuration/permissions</li>
            </ol>
        </div>
        
        <div class='result error'>
            <h3>🚨 Next Steps</h3>
            <p>If database connection works but submission still fails, the issue is likely:</p>
            <ul>
                <li>Different database credentials on cPanel vs localhost</li>
                <li>Missing PHP extensions on cPanel server</li>
                <li>File permission issues in admin/ or generated/ directories</li>
                <li>Script execution timeout during PDF generation</li>
            </ul>
        </div>
    </div>
</body>
</html>";
?>

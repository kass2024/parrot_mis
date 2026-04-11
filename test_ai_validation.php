<?php
/**
 * Test AI Validation
 */

session_start();

// Generate session data for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'TEST_' . uniqid() . '_' . time();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if OpenAI API key is available
require_once __DIR__ . '/helpers/env_bootstrap.php';
$api_key = pcvc_env('OPENAI_API_KEY');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test AI Payment Validation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2>AI Payment Validation Test</h2>
        
        <div class="alert alert-info">
            <strong>OpenAI API Key:</strong> <?php echo $api_key ? 'Available' : 'NOT SET - Please set OPENAI_API_KEY in .env file'; ?>
        </div>
        
        <form id="testForm">
            <div class="mb-3">
                <label for="testFile" class="form-label">Select Payment Proof File:</label>
                <input type="file" class="form-control" id="testFile" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
            
            <button type="button" class="btn btn-primary" onclick="testValidation()">Test AI Validation</button>
        </form>
        
        <div id="result" class="mt-4"></div>
    </div>
    
    <script>
    function testValidation() {
        const fileInput = document.getElementById('testFile');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file');
            return;
        }
        
        // First upload the file
        const formData = new FormData();
        formData.append('file', file);
        formData.append('field', 'payment_proof');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        
        document.getElementById('result').innerHTML = '<div class="spinner-border" role="status"></div> Uploading file...';
        
        fetch('basic_upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(uploadData => {
            if (uploadData.success) {
                document.getElementById('result').innerHTML = '<div class="spinner-border" role="status"></div> Validating with AI...';
                
                // Now validate with AI
                const validateData = new FormData();
                validateData.append('file_path', uploadData.file_path);
                validateData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                
                return fetch('validate_payment_proof_ai.php', {
                    method: 'POST',
                    body: validateData
                });
            } else {
                throw new Error(uploadData.message || 'Upload failed');
            }
        })
        .then(response => response.json())
        .then(validationData => {
            console.log('Validation result:', validationData);
            
            let html = '<div class="card">';
            html += '<div class="card-header"><h5>AI Validation Result</h5></div>';
            html += '<div class="card-body">';
            
            if (validationData.success) {
                const isValid = validationData.contains_payment_info;
                const alertClass = isValid ? 'alert-success' : 'alert-warning';
                const icon = isValid ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
                
                html += `<div class="alert ${alertClass}">`;
                html += `<i class="${icon} me-2"></i>`;
                html += `<strong>Status:</strong> ${isValid ? 'Payment Info Detected' : 'No Payment Info Detected'}<br>`;
                html += `<strong>Confidence:</strong> ${Math.round((validationData.confidence || 0) * 100)}%<br>`;
                html += `<strong>Message:</strong> ${validationData.message}`;
                html += '</div>';
                
                if (validationData.detected_amount) {
                    html += `<p><strong>Detected Amount:</strong> ${validationData.detected_amount}</p>`;
                }
                
                if (validationData.detected_transaction_id) {
                    html += `<p><strong>Transaction ID:</strong> ${validationData.detected_transaction_id}</p>`;
                }
                
                if (validationData.details && validationData.details.length > 0) {
                    html += '<h6>Details:</h6><ul>';
                    validationData.details.forEach(detail => {
                        html += `<li>${detail}</li>`;
                    });
                    html += '</ul>';
                }
            } else {
                html += `<div class="alert alert-danger">`;
                html += `<i class="fas fa-exclamation-triangle me-2"></i>`;
                html += `<strong>Error:</strong> ${validationData.message}`;
                html += `</div>`;
            }
            
            html += '</div>';
            html += '</div>';
            
            document.getElementById('result').innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('result').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> ${error.message}
                </div>
            `;
        });
    }
    </script>
</body>
</html>

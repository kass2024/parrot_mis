<!DOCTYPE html>
<html>
<head>
    <title>Test Upload</title>
</head>
<body>
    <h2>Test File Upload</h2>
    
    <form id="testForm">
        <input type="file" id="fileInput" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
        <button type="button" onclick="testUpload()">Upload File</button>
    </form>
    
    <div id="result"></div>
    
    <script>
    function testUpload() {
        const fileInput = document.getElementById('fileInput');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file');
            return;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('field', 'test');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        
        fetch('upload_medical_file.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            document.getElementById('result').innerHTML = JSON.stringify(data, null, 2);
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('result').innerHTML = 'Error: ' + error.message;
        });
    }
    </script>
    
    <?php
    session_name('XGS_MEDICAL_FORM');
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 'TEST_' . uniqid() . '_' . time();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    echo "<p>User ID: " . $_SESSION['user_id'] . "</p>";
    echo "<p>CSRF Token: " . $_SESSION['csrf_token'] . "</p>";
    ?>
</body>
</html>

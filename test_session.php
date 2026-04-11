<?php
session_name('XGS_MEDICAL_FORM');
session_start();

echo "<h2>Session Debug</h2>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session Name: " . session_name() . "</p>";
echo "<pre>";
echo "Session Data:\n";
print_r($_SESSION);
echo "</pre>";

// Test setting a session variable
$_SESSION['test'] = 'Hello World';
echo "<p>Set test variable: " . $_SESSION['test'] . "</p>";

// Test CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
echo "<p>CSRF Token: " . $_SESSION['csrf_token'] . "</p>";
?>

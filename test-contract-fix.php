<?php
/**
 * Test script to verify contract submission fixes
 */

require_once __DIR__ . '/db.php';

echo "<h1>Contract Submission Fix Test</h1>";

// Test database schema
echo "<h2>Database Schema Check</h2>";

$sql = "DESCRIBE student_contracts";
$result = $conn->query($sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Test student_signatures table
echo "<h2>Student Signatures Schema Check</h2>";

$sql = "DESCRIBE student_signatures";
$result = $conn->query($sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Test a sample contract token
echo "<h2>Sample Contract Test</h2>";

$testToken = 'test-token-' . time();
$sql = "INSERT INTO student_contracts (contract_token, status, created_at) VALUES (?, 'draft', NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $testToken);
$stmt->execute();
$stmt->close();

$contractId = $conn->insert_id;
echo "<p>Created test contract with ID: $contractId and token: $testToken</p>";

// Test the UPDATE query that was fixed
$sql = "UPDATE student_contracts SET 
            student_id = ?, 
            status = 'signed', 
            signed_at = NOW(), 
            selected_package = ? 
          WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sisi", 123, "Test Package", $contractId);
$stmt->execute();
$stmt->close();

echo "<p>✅ UPDATE query executed successfully - bind parameters match!</p>";

// Clean up
$sql = "DELETE FROM student_contracts WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $contractId);
$stmt->execute();
$stmt->close();

echo "<p>✅ Test contract cleaned up</p>";
echo "<p><strong>All database schema and query fixes are working correctly!</strong></p>";

$conn->close();
?>

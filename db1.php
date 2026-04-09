<?php
/**
 * db.php
 * Remote MySQL connection (cPanel-safe)
 */

/* ===============================
   DATABASE CONFIG
================================ */

// Use the domain or server hostname
$DB_HOST = 'premium120.web-hosting.com'; 
// OR sometimes: 'server123.web-hosting.com'

$DB_PORT = 3306; // default MySQL port

$DB_USER = 'visaeofi_mis_user';
$DB_PASS = 'Petero@1981';
$DB_NAME = 'visaeofi_mis';

/* ===============================
   ERROR REPORTING (DEV ONLY)
================================ */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(
        $DB_HOST,
        $DB_USER,
        $DB_PASS,
        $DB_NAME,
        $DB_PORT
    );

    // Charset
    $conn->set_charset('utf8mb4');

    // Force UTC
    $conn->query("SET time_zone = '+00:00'");

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    error_log($e->getMessage()); // log internally
    die('Database connection error.');
}

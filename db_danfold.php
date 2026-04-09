<?php
// db.php
declare(strict_types=1);

/**
 * Simple PDO connector (MySQL/MariaDB).
 * -------------------------------------------------
 * 1) Create a DB and user with proper privileges.
 * 2) Update the credentials below.
 * 3) require_once __DIR__.'/db.php'; in your scripts.
 */

const DB_HOST = '127.0.0.1';
const DB_NAME = 'parrot';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
  http_response_code(500);
  exit('Database connection failed.');
}

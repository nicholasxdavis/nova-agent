<?php
// File: db_connect.php
// Path: /api/db_connect.php

// Set headers for CORS and content type
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// --- Database Credentials ---
$host = 'localhost'; // Corrected: Use localhost for a local database
$dbname = 'nova_ai'; 
$user = 'root';
$pass = 'HrSHtMgVj5hitcHW5piRfOQMg2KboFZHGmYhHME7eJJEUfAmnRxfQOsx57686pyq';
$charset = 'utf8mb4';

// --- PDO Connection Options ---
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// --- Establish Database Connection ---
try {
     $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $user, $pass, $options);
} catch (\PDOException $e) {
     // If connection fails, return a JSON error message
     http_response_code(500);
     echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
     exit();
}

// --- SQL to Create Users Table ---
// This ensures the table exists before any operation is attempted.
$users_table_sql = "
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($users_table_sql);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create users table: ' . $e->getMessage()]);
    exit();
}

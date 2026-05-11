<?php
// config/db_connect.php
$host = 'localhost';
$username = 'root';  // Your DB username
$password = '';      // Your DB password
$database = 'mdrrmo_db';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]));
}

// Set charset
$conn->set_charset("utf8mb4");
?>
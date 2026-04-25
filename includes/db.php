<?php
// db.php - Database connection file

$host = 'localhost';
$db   = 'pureflow';        // 👈 change this if your DB name is different
$user = 'root';            // 👈 use your actual DB username
$pass = '';                // 👈 use your actual DB password

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset
$conn->set_charset("utf8");
?>

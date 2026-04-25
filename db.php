<?php
// db.php

$host = '127.0.0.1';
$db = 'u549992181_DB_TuyPureFlow';
$user = 'u549992181_Arciee';
$pass = 'TuyPureFlow_Capstone_2025'; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => true, // Convert BLOBs to strings
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('Database Connection Failed: ' . $e->getMessage());
}
?>

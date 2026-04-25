<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$host = '127.0.0.1';
$db   = 'u549992181_DB_TuyPureFlow';
$user = 'u549992181_Arciee';
$pass = 'TuyPureFlow_Capstone_2025';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

$comments = [
    "Great service!",
    "Fast delivery!",
    "Highly recommended!",
    "Smooth transaction.",
    "Good experience."
];

// Chunk settings
$chunkSize = 10000;
$start = isset($_GET['start']) ? intval($_GET['start']) : 42465;
$end = min($start + $chunkSize - 1, 315470);

$selectOrder = $pdo->prepare("SELECT consumer_id, shop_id FROM orders WHERE order_id = ?");
$insertRating = $pdo->prepare("INSERT INTO shop_ratings (order_id, consumer_id, shop_id, rating, comment) VALUES (?, ?, ?, ?, ?)");

$inserted = 0;
for ($order_id = $start; $order_id <= $end; $order_id++) {
    $selectOrder->execute([$order_id]);
    $order = $selectOrder->fetch();
    if ($order && $order['consumer_id'] && $order['shop_id']) {
        $rating = rand(3, 5);
        $comment = $comments[array_rand($comments)];
        $insertRating->execute([$order_id, $order['consumer_id'], $order['shop_id'], $rating, $comment]);
        $inserted++;
    }
}

echo "Inserted $inserted ratings for order_id $start to $end.<br>";

if ($end < 315470) {
    $next = $end + 1;
    echo "<a href='generate_rating.php?start=$next'>Continue with next 10,000</a>";
} else {
    echo "DONE: All ratings inserted.";
}
?>
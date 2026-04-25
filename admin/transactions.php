<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
try {
  $stmt = $conn->prepare("SELECT o.order_id, o.consumer_id, o.shop_id, o.status, COALESCE(o.total_price, o.amount, 0) AS amount FROM orders o ORDER BY o.order_id DESC LIMIT 200");
  $stmt->execute();
  $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  // Transactions Monitoring page removed

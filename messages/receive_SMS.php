<?php
/**
 * TuyPureFlow — Receive SMS Orders
 * File: /messages/receive_sms.php
 */

header('Content-Type: application/json');
require_once '../db.php';

// Get JSON payload
$input = json_decode(file_get_contents('php://input'), true);
$number = $input['from'] ?? '';
$message = strtoupper(trim($input['message'] ?? ''));
$timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');

if (empty($number) || empty($message)) {
    echo json_encode(["status" => "error", "message" => "Missing number or message."]);
    exit;
}

// Example message: ORDER 3GALLONS 1
// (where 1 = container_id or shop_id, depending on setup)
if (preg_match('/^ORDER\s+(\d+)GALLONS\s+(\d+)/i', $message, $matches)) {
    $quantity = intval($matches[1]);
    $container_id = intval($matches[2]);

    try {
        // Find or create consumer
        $stmt = $conn->prepare("SELECT consumer_id FROM consumer WHERE phone_number = ?");
        $stmt->execute([$number]);
        $consumer = $stmt->fetch();

        if (!$consumer) {
            $insert = $conn->prepare("INSERT INTO consumer (name, phone_number, created_at) VALUES ('SMS Customer', ?, NOW())");
            $insert->execute([$number]);
            $consumer_id = $conn->lastInsertId();
        } else {
            $consumer_id = $consumer['consumer_id'];
        }

        // Default shop (can be assigned dynamically later)
        $shop_id = 1;

        // Log to sms_orders
        $insertSMS = $conn->prepare("
            INSERT INTO sms_orders (consumer_id, shop_id, container_id, sender_phone_number, message, status)
            VALUES (?, ?, ?, ?, ?, 'received')
        ");
        $insertSMS->execute([$consumer_id, $shop_id, $container_id, $number, $message]);
        $sms_order_id = $conn->lastInsertId();

        // Send confirmation SMS
        $confirmMessage = "TuyPureFlow: Your order has been received and is being processed.";
        $url = "https://yourdomain.com/messages/send_sms.php";
        $postData = http_build_query(['number' => $number, 'message' => $confirmMessage]);
        @file_get_contents($url . '?' . $postData);

        echo json_encode([
            "status" => "success",
            "message" => "SMS order recorded.",
            "order_id" => $sms_order_id
        ]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid SMS format. Use: ORDER <quantity>GALLONS <container_id>"
    ]);
}
?>

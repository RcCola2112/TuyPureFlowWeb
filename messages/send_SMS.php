<?php
/**
 * TuyPureFlow — Send SMS
 * File: /messages/send_sms.php
 */

header('Content-Type: application/json');
require_once '../db.php';

// SMS-Gate credentials
$deviceId = "000000005ae43a1b00000199cb23fbbb";
$username = "TuyPureFlow";
$password = "Admin_01_TuyPureFlow";

// Input
$number = $_POST['number'] ?? '';
$message = $_POST['message'] ?? '';
$status = $_POST['status'] ?? 'sent';
$sms_order_id = $_POST['sms_order_id'] ?? null;

if (empty($number) || empty($message)) {
    echo json_encode(["status" => "error", "message" => "Missing number or message."]);
    exit;
}

// Send SMS via SMS-Gate API
$url = "https://api.sms-gate.app:443/send";
$data = [
    "deviceId" => $deviceId,
    "number" => $number,
    "message" => $message
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => "$username:$password",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(["status" => "error", "message" => "cURL Error: " . $error]);
    exit;
}

// Decode response
$result = json_decode($response, true);

// Update sms_orders status if linked
if ($sms_order_id) {
    $stmt = $conn->prepare("UPDATE sms_orders SET status = ?, updated_at = NOW() WHERE sms_order_id = ?");
    $stmt->execute([$status, $sms_order_id]);
}

// Log message
$log = $conn->prepare("
    INSERT INTO sms_messages (direction, phone_number, message, status)
    VALUES ('outgoing', ?, ?, ?)
");
$log->execute([$number, $message, $result['success'] ? 'Sent' : 'Failed']);

echo json_encode([
    "status" => $result['success'] ? 'success' : 'failed',
    "message" => $result['success'] ? 'SMS sent successfully.' : 'Failed to send SMS.',
    "response" => $result
]);
?>
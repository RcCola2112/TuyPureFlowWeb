<?php
// webhook.php - DEBUG VERSION (SIGNATURE CHECK DISABLED)

// --- DEBUG LOGGING SETUP ---
$logFile = 'sms_log.txt';
function log_message($content) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $content\n", FILE_APPEND);
}
// ----------------------------

log_message("--- Webhook request received ---");

// 1. Define your Signing Key (Set this in App Settings -> Webhooks -> Signing Key)
$signingKey = "ZvWyC544"; 

// 2. Receive the raw POST data
$payload = file_get_contents('php://input');
$headers = getallheaders();

// 3. Extract Headers for Security
$signature = isset($headers['X-Signature']) ? $headers['X-Signature'] : '';
$timestamp = isset($headers['X-Timestamp']) ? $headers['X-Timestamp'] : 0;

// 4. Verify the Signature (CRITICAL SECURITY STEP)
// We are TEMPORARILY commenting out the check to see if the script is running.
/*
$messageToSign = $payload . $timestamp;
$calculatedSignature = hash_hmac('sha256', $messageToSign, $signingKey);

// Use hash_equals to prevent timing attacks
if (!hash_equals($calculatedSignature, $signature)) {
    http_response_code(403); // Forbidden
    log_message("SECURITY FAILURE: Invalid Signature. Calculated: $calculatedSignature | Sent: $signature");
    die("Invalid Signature");
}
log_message("Signature check PASSED.");
*/

// 5. Decode the JSON Data
$data = json_decode($payload, true);

// 6. Handle the Event
if (isset($data['event']) && $data['event'] === 'sms:received') {
    
    // Extract SMS details
    $sender = $data['payload']['phoneNumber'] ?? 'N/A';
    $message = $data['payload']['message'] ?? 'N/A';
    $receivedAt = $data['payload']['receivedAt'] ?? 'N/A';

    $logEntry = "SUCCESS: SMS Received! From: $sender | Msg: $message";
    log_message($logEntry); 
} else {
    // Log the entire payload if the expected event is not found
    log_message("EVENT RECEIVED: " . ($data['event'] ?? 'NO_EVENT_FIELD') . ". Payload: " . $payload);
}

// 7. Respond with 200 OK immediately (Required within 30s)
http_response_code(200);
echo json_encode(["status" => "success"]);
?>
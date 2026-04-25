<?php
// send_message.php
// Handles messages from both consumers and distributors
include '../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a consumer sending to distributor
    $consumer_id = intval($_POST['consumer_id'] ?? 0);
    $distributor_id = intval($_POST['distributor_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $sender_type = $_POST['sender_type'] ?? 'consumer';
    
    // Consumer to Distributor flow
    if ($consumer_id && $distributor_id && $message) {
        $stmt = $conn->prepare("INSERT INTO messages (consumer_id, distributor_id, message, sender_type, sent_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$consumer_id, $distributor_id, $message, $sender_type]);
        echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);
        exit;
    }
    
    // Distributor to Consumer/Admin flow (original logic)
    $to_username = trim($_POST['to_username'] ?? '');
    if ($to_username && $distributor_id && $message) {
        $sender_type = $_POST['sender_type'] ?? 'distributor';
        $consumer_id = 0;
        $admin_id = 0;
        
        // Try to find consumer by username
        $stmt = $conn->prepare("SELECT consumer_id FROM consumer WHERE username = ? LIMIT 1");
        $stmt->execute([$to_username]);
        $row = $stmt->fetch();
        if ($row) {
            $consumer_id = intval($row['consumer_id']);
        } else {
            // Try to find admin by username
            $stmt = $conn->prepare("SELECT admin_id FROM admin WHERE username = ? LIMIT 1");
            $stmt->execute([$to_username]);
            $row = $stmt->fetch();
            if ($row) {
                $admin_id = intval($row['admin_id']);
            }
        }
        
        if ($consumer_id) {
            $stmt = $conn->prepare("INSERT INTO messages (consumer_id, distributor_id, message, sender_type, sent_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$consumer_id, $distributor_id, $message, $sender_type]);
            echo json_encode(['success' => true, 'message' => 'Message sent to consumer.']);
        } elseif ($admin_id) {
            $stmt = $conn->prepare("INSERT INTO messages (admin_id, distributor_id, message, sender_type, sent_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$admin_id, $distributor_id, $message, $sender_type]);
            echo json_encode(['success' => true, 'message' => 'Message sent to admin.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Username not found.']);
        }
        exit;
    }
    
    // If we get here, required fields are missing
    echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request.']);

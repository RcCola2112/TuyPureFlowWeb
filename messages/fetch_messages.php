<?php
// fetch_messages.php
include '../db.php';

$consumer_id = intval($_GET['consumer_id'] ?? 0);
$distributor_id = intval($_GET['distributor_id'] ?? 0);

if ($consumer_id && $distributor_id) {
    // Fetch messages for a specific chat between consumer and distributor
    $stmt = $conn->prepare("SELECT m.message, m.sent_at, m.sender_type, m.consumer_id, m.distributor_id, c.username AS consumer_username FROM messages m LEFT JOIN consumer c ON m.consumer_id = c.consumer_id WHERE m.consumer_id = ? AND m.distributor_id = ? ORDER BY m.sent_at ASC");
    $stmt->execute([$consumer_id, $distributor_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
} elseif ($distributor_id) {
    // Fetch all messages for this distributor
    // The issue: when this distributor sends a message, m.distributor_id stores the sender's distributor_id
    // But we need to show the correct shop name based on who the message is to/from
    
    // Get this distributor's shop name
    $stmt_own_shop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
    $stmt_own_shop->execute([$distributor_id]);
    $own_shop_name = $stmt_own_shop->fetchColumn() ?: '';
    
    // Fetch messages where this distributor is involved
    // We need to get messages where:
    // 1. This distributor received messages (m.distributor_id = current distributor_id)
    // 2. This distributor sent messages (need to check sender_type and find recipient shop)
    
    // First, get messages where this distributor is the recipient
    $stmt = $conn->prepare("
        SELECT 
            m.message, 
            m.sent_at, 
            m.sender_type, 
            m.consumer_id, 
            m.distributor_id,
            c.username AS consumer_username,
            ? AS shop_name
        FROM messages m 
        LEFT JOIN consumer c ON m.consumer_id = c.consumer_id 
        WHERE m.distributor_id = ? AND m.sender_type != 'distributor'
        ORDER BY m.sent_at ASC
    ");
    $stmt->execute([$own_shop_name, $distributor_id]);
    $received_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Then, get messages where this distributor is the sender
    // When distributor sends to a consumer, show the shop that consumer most recently ordered from
    // This helps identify which shop the consumer is associated with
    $stmt2 = $conn->prepare("
        SELECT 
            m.message, 
            m.sent_at, 
            m.sender_type, 
            m.consumer_id, 
            m.distributor_id,
            c.username AS consumer_username,
            COALESCE(
                (SELECT s.name FROM shop s 
                 JOIN orders o ON s.shop_id = o.shop_id 
                 WHERE o.consumer_id = m.consumer_id 
                 ORDER BY o.order_date DESC LIMIT 1),
                ?
            ) AS shop_name
        FROM messages m 
        LEFT JOIN consumer c ON m.consumer_id = c.consumer_id 
        WHERE m.distributor_id = ? AND m.sender_type = 'distributor'
        ORDER BY m.sent_at ASC
    ");
    $stmt2->execute([$own_shop_name, $distributor_id]);
    $sent_messages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine and sort by sent_at
    $messages = array_merge($received_messages, $sent_messages);
    usort($messages, function($a, $b) {
        return strtotime($a['sent_at']) - strtotime($b['sent_at']);
    });
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);

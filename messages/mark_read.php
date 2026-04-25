<?php
// mark_read.php
// POST: user_id, user_type, from_id, from_type
include '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $user_type = $_POST['user_type'] ?? '';
    $from_id = intval($_POST['from_id'] ?? 0);
    $from_type = $_POST['from_type'] ?? '';
    if ($user_id && $user_type && $from_id && $from_type) {
        $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND receiver_type = ? AND sender_id = ? AND sender_type = ?");
        $stmt->execute([$user_id, $user_type, $from_id, $from_type]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    }
    exit;
}
echo json_encode(['success' => false, 'error' => 'Invalid request.']);


<?php
// send_notification.php
// Reusable notification sender for TuyPureFlow

/**
 * Send a notification to any recipient type.
 *
 * @param PDO $pdo PDO database connection
 * @param int $recipient_id Recipient's user ID
 * @param string $recipient_type Recipient type ('Consumer', 'Didstributor', 'rider', 'Admin') - Note: matches database enum values
 * @param string $message Notification message
 * @param string $type Notification type (optional, for logging purposes only)
 * @return bool True on success, false on failure
 */
function sendNotification($pdo, $recipient_id, $recipient_type, $message, $type = '') {
    // Sanitize inputs
    $recipient_id = filter_var($recipient_id, FILTER_VALIDATE_INT);
    $recipient_type = trim($recipient_type);
    $message = trim($message);

    // Validate recipient_id
    if (!$recipient_id || $recipient_id <= 0) {
        return false;
    }

    // Validate and normalize recipient_type to match database enum values
    // Database enum: 'Consumer', 'Didstributor', 'rider', 'Admin'
    $valid_types = ['Consumer', 'Didstributor', 'rider', 'Admin'];
    $normalized_type = null;
    
    // Normalize common variations to match database enum
    if (strcasecmp($recipient_type, 'Distributor') == 0 || strcasecmp($recipient_type, 'Didstributor') == 0) {
        $normalized_type = 'Didstributor'; // Match database typo
    } elseif (strcasecmp($recipient_type, 'Rider') == 0 || strcasecmp($recipient_type, 'rider') == 0) {
        $normalized_type = 'rider'; // Lowercase as in database
    } elseif (strcasecmp($recipient_type, 'Consumer') == 0) {
        $normalized_type = 'Consumer';
    } elseif (strcasecmp($recipient_type, 'Admin') == 0) {
        $normalized_type = 'Admin';
    }
    
    if (!$normalized_type || !in_array($normalized_type, $valid_types)) {
        return false;
    }

    // Validate message
    if (empty($message)) {
        return false;
    }

    // Sanitize message for database
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    try {
        $sql = "INSERT INTO notifications (recipient_id, recipient_type, message, status) VALUES (?, ?, ?, 'Unread')";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$recipient_id, $normalized_type, $message]);
    } catch (Exception $e) {
        // Log error if needed, but don't expose to user
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}

// Example usage:
// sendNotification($conn, $rider_id, 'rider', 'Your application has been approved.', 'Rider Application');
// sendNotification($conn, $consumer_id, 'Consumer', 'Your order has been placed.', 'Order Placed');
// sendNotification($conn, $distributor_id, 'Didstributor', 'New order received.', 'New Order');

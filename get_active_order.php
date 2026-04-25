<?php

// Start output buffering to catch any unexpected output
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clear any potential caching
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit(0);
}

// Get consumer_id from query string
$consumer_id = isset($_GET['consumer_id']) ? intval($_GET['consumer_id']) : 0;

if (!$consumer_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Missing consumer_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

// DB connection
try {
    require_once 'db.php';
    // Clear any unexpected output from db.php
    $dbOutput = ob_get_clean();
    if (!empty(trim($dbOutput))) {
        error_log("Unexpected output from db.php in get_active_order: " . substr($dbOutput, 0, 100));
    }
    ob_start(); // Restart buffer for our response
    // Use $conn instead of $pdo
    $pdo = $conn;
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
// Check if tables exist
    $tables_check = $pdo->query("SHOW TABLES LIKE 'orders'");
    if ($tables_check->rowCount() == 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Orders table does not exist.'], JSON_UNESCAPED_UNICODE);
    exit;
}

    $tables_check = $pdo->query("SHOW TABLES LIKE 'shop'");
    if ($tables_check->rowCount() == 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Shop table does not exist.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// First, let's check what orders exist for this consumer
    $debug_stmt = $pdo->prepare('SELECT * FROM orders WHERE consumer_id = ? ORDER BY order_date DESC LIMIT 5');
    $debug_stmt->execute([$consumer_id]);
    $debug_orders = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active order for the consumer
    // IMPORTANT: Get delivery address ONLY from order's address_id (o.address_id)
    // DO NOT use consumer's default address - only use the address associated with this specific order
    $stmt = $pdo->prepare('
        SELECT 
            o.*, 
            s.name as business_name, 
            s.location, 
            s.contact_number, 
            s.latitude as shop_latitude, 
            s.longitude as shop_longitude,
            a.latitude as delivery_latitude,
            a.longitude as delivery_longitude,
            COALESCE(
                NULLIF(
                    TRIM(
                        CONCAT_WS(
                            \', \',
                            NULLIF(a.street, \'\'),
                            NULLIF(a.barangay, \'\'),
                            NULLIF(a.city, \'\'),
                            NULLIF(a.region, \'\'),
                            NULLIF(a.zip_code, \'\')
                        )
                    ),
                    \'\'
                ),
                \'No address on file\'
            ) AS delivery_address,
            CASE 
                WHEN a.latitude IS NOT NULL AND a.latitude != \'\' AND a.longitude IS NOT NULL AND a.longitude != \'\' 
                THEN CONCAT(a.latitude, \', \', a.longitude)
                ELSE NULL
            END AS delivery_map
    FROM orders o 
    JOIN shop s ON o.shop_id = s.shop_id 
        LEFT JOIN address a ON a.address_id = o.address_id
    WHERE o.consumer_id = ? 
    AND o.status IN ("Processing", "Out for Delivery", "Pending") 
    ORDER BY o.order_date DESC 
    LIMIT 1
');

    $stmt->execute([$consumer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        // Calculate delivery number (how many completed orders before this one)
        $deliveryCountStmt = $pdo->prepare('
            SELECT COUNT(*) as completed_count 
            FROM orders 
            WHERE consumer_id = ? 
            AND status = "Completed" 
            AND (order_date < ? OR (order_date = ? AND order_id < ?))
        ');
        $deliveryCountStmt->execute([
            $consumer_id,
            $order['order_date'],
            $order['order_date'],
            $order['order_id']
        ]);
        $deliveryCountResult = $deliveryCountStmt->fetch(PDO::FETCH_ASSOC);
        $completedCount = intval($deliveryCountResult['completed_count'] ?? 0);
        $deliveryNumber = $completedCount + 1; // Current order is the next delivery
        
        // Check if rider tables exist before trying to fetch rider info
        $rider_table_check = $pdo->query("SHOW TABLES LIKE 'rider'");
        $rider_location_table_check = $pdo->query("SHOW TABLES LIKE 'rider_locations'");
    
        if ($rider_table_check->rowCount() > 0 && $rider_location_table_check->rowCount() > 0 && 
        ($order['status'] === 'Out for Delivery' || $order['status'] === 'Processing') && 
        isset($order['rider_id']) && $order['rider_id']) {
        
            $riderStmt = $pdo->prepare('
            SELECT r.rider_id, r.name as rider_name, r.phone as rider_phone, rl.latitude, rl.longitude, rl.updated_at
            FROM rider r 
            LEFT JOIN (
              SELECT rl1.rider_id, rl1.latitude, rl1.longitude, rl1.updated_at
              FROM rider_locations rl1
              JOIN (
                SELECT rider_id, MAX(updated_at) AS max_updated
                FROM rider_locations
                GROUP BY rider_id
              ) m ON m.rider_id = rl1.rider_id AND m.max_updated = rl1.updated_at
            ) rl ON rl.rider_id = r.rider_id
            WHERE r.rider_id = ?
        ');
        
            $riderStmt->execute([$order['rider_id']]);
            $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rider) {
                $order['rider'] = $rider;
            }
        }
        
        // Ensure delivery coordinates are properly formatted
        if (isset($order['delivery_latitude']) && isset($order['delivery_longitude'])) {
            $order['delivery_latitude'] = $order['delivery_latitude'] !== null ? (float)$order['delivery_latitude'] : null;
            $order['delivery_longitude'] = $order['delivery_longitude'] !== null ? (float)$order['delivery_longitude'] : null;
    }
    
        // Add delivery number to order object
        $order['delivery_number'] = $deliveryNumber;
        
        ob_end_clean();
    echo json_encode([
        'success' => true, 
        'order' => $order,
        'debug' => [
            'consumer_id' => $consumer_id,
            'total_orders' => count($debug_orders),
            'recent_orders' => $debug_orders
        ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
} else {
        ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'No orders found for this consumer.',
        'debug' => [
            'consumer_id' => $consumer_id,
            'total_orders' => count($debug_orders),
            'recent_orders' => $debug_orders
        ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch(PDOException $e) {
    error_log("Get active order error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    error_log("Get active order general error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}


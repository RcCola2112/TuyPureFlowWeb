<?php

// Start output buffering to catch any unexpected output
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CORS and JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit();
}

// Include database connection
try {
    require_once 'db.php';
    // Clear any unexpected output from db.php
    $dbOutput = ob_get_clean();
    if (!empty(trim($dbOutput))) {
        error_log("Unexpected output from db.php in get_rider_location: " . substr($dbOutput, 0, 100));
    }
    ob_start(); // Restart buffer for our response
    // Use $conn instead of $pdo
    $pdo = $conn;
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Get order_id from query parameters
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
    $rider_id = isset($_GET['rider_id']) ? intval($_GET['rider_id']) : null;

    if (!$order_id && !$rider_id) {
        throw new Exception('Either order_id or rider_id is required');
    }

    // Get the latest location for the rider
    // First, get the latest location from rider_locations
    if ($order_id) {
        // If order_id is provided, get rider_id from the order first
        $orderStmt = $pdo->prepare("SELECT rider_id FROM orders WHERE order_id = ? LIMIT 1");
        $orderStmt->execute([$order_id]);
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$orderData) {
            throw new Exception('Order not found');
        }
        
        // If no rider assigned, return success with null location
        if (!$orderData['rider_id']) {
            // Get order info with delivery address
            $orderStmt2 = $pdo->prepare("
                SELECT 
                    o.order_id,
                    o.status as order_status,
                    COALESCE(
                        NULLIF(
                            TRIM(
                                CONCAT_WS(
                                    ', ',
                                    NULLIF(a.street, ''),
                                    NULLIF(a.barangay, ''),
                                    NULLIF(a.city, ''),
                                    NULLIF(a.region, ''),
                                    NULLIF(a.zip_code, '')
                                )
                            ),
                            ''
                        ),
                        'No address on file'
                    ) AS delivery_address,
                    CASE 
                        WHEN a.latitude IS NOT NULL AND a.latitude != '' THEN CAST(a.latitude AS DECIMAL(10,8))
                        ELSE NULL
                    END AS delivery_latitude,
                    CASE 
                        WHEN a.longitude IS NOT NULL AND a.longitude != '' THEN CAST(a.longitude AS DECIMAL(11,8))
                        ELSE NULL
                    END AS delivery_longitude
                FROM orders o
                LEFT JOIN address a ON a.address_id = o.address_id
                WHERE o.order_id = ?
            ");
            $orderStmt2->execute([$order_id]);
            $orderData2 = $orderStmt2->fetch(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'message' => 'No rider assigned to this order yet',
                'data' => [
                    'rider' => null,
                    'location' => null,
                    'order' => $orderData2 ? [
                        'order_id' => (int)$orderData2['order_id'],
                        'status' => $orderData2['order_status'] ?? null,
                        'delivery_address' => $orderData2['delivery_address'] ?? null,
                        'delivery_latitude' => isset($orderData2['delivery_latitude']) ? (float)$orderData2['delivery_latitude'] : null,
                        'delivery_longitude' => isset($orderData2['delivery_longitude']) ? (float)$orderData2['delivery_longitude'] : null
                    ] : null,
                    'delivery' => ($orderData2 && isset($orderData2['delivery_latitude']) && isset($orderData2['delivery_longitude']) && 
                                  $orderData2['delivery_latitude'] !== null && $orderData2['delivery_longitude'] !== null) ? [
                        'latitude' => (float)$orderData2['delivery_latitude'],
                        'longitude' => (float)$orderData2['delivery_longitude']
                    ] : null
                ]
            ];
            
            ob_end_clean();
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        $rider_id = (int)$orderData['rider_id'];
    }
    
    // Get the latest location for the rider
    $locationStmt = $pdo->prepare("
        SELECT 
            rider_id,
            latitude,
            longitude,
            updated_at as last_location_update,
            TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_since_update
        FROM rider_locations 
        WHERE rider_id = ? 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $locationStmt->execute([$rider_id]);
    $locationData = $locationStmt->fetch(PDO::FETCH_ASSOC);
    
    // If no location found, return success response with null location
    if (!$locationData || $locationData['latitude'] === null || $locationData['longitude'] === null) {
        // Still try to get rider info and order info if available
        $riderInfo = null;
        $riderStmt = $pdo->prepare("
            SELECT 
                rider_id,
                name as rider_name,
                phone as rider_phone,
                vehicle_type,
                plate_number AS vehicle_number
            FROM rider 
            WHERE rider_id = ?
        ");
        $riderStmt->execute([$rider_id]);
        $riderInfo = $riderStmt->fetch(PDO::FETCH_ASSOC);
        
        $orderData = null;
        if ($order_id) {
            $orderStmt = $pdo->prepare("
                SELECT 
                    o.order_id,
                    o.status as order_status,
                    COALESCE(
                        NULLIF(
                            TRIM(
                                CONCAT_WS(
                                    ', ',
                                    NULLIF(a.street, ''),
                                    NULLIF(a.barangay, ''),
                                    NULLIF(a.city, ''),
                                    NULLIF(a.region, ''),
                                    NULLIF(a.zip_code, '')
                                )
                            ),
                            ''
                        ),
                        'No address on file'
                    ) AS delivery_address,
                    CASE 
                        WHEN a.latitude IS NOT NULL AND a.latitude != '' THEN CAST(a.latitude AS DECIMAL(10,8))
                        ELSE NULL
                    END AS delivery_latitude,
                    CASE 
                        WHEN a.longitude IS NOT NULL AND a.longitude != '' THEN CAST(a.longitude AS DECIMAL(11,8))
                        ELSE NULL
                    END AS delivery_longitude
                FROM orders o
                LEFT JOIN address a ON a.address_id = o.address_id
                WHERE o.order_id = ?
            ");
            $orderStmt->execute([$order_id]);
            $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $response = [
            'success' => true,
            'message' => 'Rider location not available yet',
            'data' => [
                'rider' => $riderInfo ? [
                    'rider_id' => (int)$riderInfo['rider_id'],
                    'name' => $riderInfo['rider_name'] ?? '',
                    'phone' => $riderInfo['rider_phone'] ?? '',
                    'vehicle_type' => $riderInfo['vehicle_type'] ?? null,
                    'vehicle_number' => $riderInfo['vehicle_number'] ?? null
                ] : null,
                'location' => null,
                'order' => ($order_id && $orderData) ? [
                    'order_id' => (int)$orderData['order_id'],
                    'status' => $orderData['order_status'] ?? null,
                    'delivery_address' => $orderData['delivery_address'] ?? null,
                    'delivery_latitude' => isset($orderData['delivery_latitude']) ? (float)$orderData['delivery_latitude'] : null,
                    'delivery_longitude' => isset($orderData['delivery_longitude']) ? (float)$orderData['delivery_longitude'] : null
                ] : null,
                'delivery' => ($orderData && isset($orderData['delivery_latitude']) && isset($orderData['delivery_longitude']) && 
                              $orderData['delivery_latitude'] !== null && $orderData['delivery_longitude'] !== null) ? [
                    'latitude' => (float)$orderData['delivery_latitude'],
                    'longitude' => (float)$orderData['delivery_longitude']
                ] : null
            ]
        ];
        
        ob_end_clean();
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Get rider information
    $riderStmt = $pdo->prepare("
        SELECT 
            rider_id,
            name as rider_name,
            phone as rider_phone,
            vehicle_type,
            plate_number AS vehicle_number
        FROM rider 
        WHERE rider_id = ?
    ");
    $riderStmt->execute([$rider_id]);
    $riderInfo = $riderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$riderInfo) {
        throw new Exception('Rider not found');
    }
    
    // Get order information if order_id is provided
    $orderData = null;
    if ($order_id) {
        $orderStmt = $pdo->prepare("
            SELECT 
                o.order_id,
                o.status as order_status,
                COALESCE(
                    NULLIF(
                        TRIM(
                            CONCAT_WS(
                                ', ',
                                NULLIF(a.street, ''),
                                NULLIF(a.barangay, ''),
                                NULLIF(a.city, ''),
                                NULLIF(a.region, ''),
                                NULLIF(a.zip_code, '')
                            )
                        ),
                        ''
                    ),
                    'No address on file'
                ) AS delivery_address,
                CASE 
                    WHEN a.latitude IS NOT NULL AND a.latitude != '' THEN CAST(a.latitude AS DECIMAL(10,8))
                    ELSE NULL
                END AS delivery_latitude,
                CASE 
                    WHEN a.longitude IS NOT NULL AND a.longitude != '' THEN CAST(a.longitude AS DECIMAL(11,8))
                    ELSE NULL
                END AS delivery_longitude
            FROM orders o
            LEFT JOIN address a ON a.address_id = o.address_id
            WHERE o.order_id = ?
        ");
        $orderStmt->execute([$order_id]);
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Combine all data
    $rider_data = array_merge($riderInfo, $locationData);
    if ($orderData) {
        $rider_data = array_merge($rider_data, $orderData);
    }
    
    // Check if location is recent (within last 5 minutes)
    $is_location_recent = isset($rider_data['minutes_since_update']) && $rider_data['minutes_since_update'] <= 5;
    
    // Calculate distance to delivery address if available
    $distance_to_destination = null;
    if (
        isset($rider_data['delivery_latitude'], $rider_data['delivery_longitude']) &&
        $rider_data['delivery_latitude'] !== null &&
        $rider_data['delivery_longitude'] !== null &&
        isset($rider_data['latitude'], $rider_data['longitude']) &&
        $rider_data['latitude'] !== null &&
        $rider_data['longitude'] !== null
    ) {
        $distance_to_destination = calculateDistance(
            (float)$rider_data['latitude'],
            (float)$rider_data['longitude'],
            (float)$rider_data['delivery_latitude'],
            (float)$rider_data['delivery_longitude']
        );
    }
    
    // Format response - ensure delivery coordinates are easily accessible
    $response = [
        'success' => true,
        'data' => [
            'rider' => [
                'rider_id' => (int)$rider_data['rider_id'],
                'name' => $rider_data['rider_name'] ?? '',
                'phone' => $rider_data['rider_phone'] ?? '',
                'vehicle_type' => $rider_data['vehicle_type'] ?? null,
                'vehicle_number' => $rider_data['vehicle_number'] ?? null
            ],
            'location' => [
                'latitude' => isset($rider_data['latitude']) ? (float)$rider_data['latitude'] : null,
                'longitude' => isset($rider_data['longitude']) ? (float)$rider_data['longitude'] : null,
                'last_updated' => $rider_data['last_location_update'] ?? null,
                'is_recent' => $is_location_recent,
                'minutes_since_update' => isset($rider_data['minutes_since_update']) ? (int)$rider_data['minutes_since_update'] : null
            ],
            'order' => ($order_id && $orderData) ? [
                'order_id' => (int)$orderData['order_id'],
                'status' => $orderData['order_status'] ?? null,
                'delivery_address' => $orderData['delivery_address'] ?? null,
                'delivery_latitude' => isset($orderData['delivery_latitude']) ? (float)$orderData['delivery_latitude'] : null,
                'delivery_longitude' => isset($orderData['delivery_longitude']) ? (float)$orderData['delivery_longitude'] : null,
                'distance_to_destination_km' => $distance_to_destination ? round($distance_to_destination, 2) : null
            ] : null,
            // Add delivery coordinates at top level for easier access
            'delivery' => ($orderData && isset($orderData['delivery_latitude']) && isset($orderData['delivery_longitude']) && 
                          $orderData['delivery_latitude'] !== null && $orderData['delivery_longitude'] !== null) ? [
                'latitude' => (float)$orderData['delivery_latitude'],
                'longitude' => (float)$orderData['delivery_longitude']
            ] : null
        ]
    ];
    
    ob_end_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (PDOException $e) {
    error_log("Get rider location PDO error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    error_log("Get rider location error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Function to calculate distance between two points (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Earth's radius in kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c;
}


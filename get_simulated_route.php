<?php
// Start output buffering to catch any unexpected output
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit(0);
}

try {
    require_once 'db.php';
    // Clear any unexpected output from db.php
    $dbOutput = ob_get_clean();
    if (!empty(trim($dbOutput))) {
        error_log("Unexpected output from db.php in get_simulated_route: " . substr($dbOutput, 0, 100));
    }
    ob_start(); // Restart buffer for our response
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get parameters
$origin = isset($_GET['origin']) ? trim($_GET['origin']) : '';
$destination = isset($_GET['destination']) ? trim($_GET['destination']) : '';

if (empty($origin) || empty($destination)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing origin or destination parameters'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Parse coordinates
$originParts = explode(',', $origin);
$destParts = explode(',', $destination);

if (count($originParts) !== 2 || count($destParts) !== 2) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid coordinate format. Expected: "latitude,longitude"'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$startLat = floatval(trim($originParts[0]));
$startLng = floatval(trim($originParts[1]));
$endLat = floatval(trim($destParts[0]));
$endLng = floatval(trim($destParts[1]));

// Validate coordinates
if ($startLat < -90 || $startLat > 90 || $endLat < -90 || $endLat > 90 ||
    $startLng < -180 || $startLng > 180 || $endLng < -180 || $endLng > 180) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid coordinate values. Latitude must be between -90 and 90, longitude between -180 and 180.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Generate realistic route coordinates
    $coordinates = generateRealisticRoute($startLat, $startLng, $endLat, $endLng);
    
    // Calculate fixed distance that never changes
    $directDistance = calculateDistance($startLat, $startLng, $endLat, $endLng);
    $distance = $directDistance * 1.15; // Route is 15% longer than direct (realistic for roads)
    $estimatedTime = round($distance * 2.5); // 2.5 minutes per km
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'route' => [
            'coordinates' => $coordinates,
            'distance' => round($distance, 1) . ' km',
            'duration' => $estimatedTime . ' min',
            'distance_value' => $distance * 1000, // in meters
            'duration_value' => $estimatedTime * 60, // in seconds
            'start_address' => 'Rider Location',
            'end_address' => 'Delivery Address'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Exception $e) {
    error_log("Get simulated route error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error generating route: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function generateRealisticRoute($startLat, $startLng, $endLat, $endLng) {
    // Create a fixed, deterministic route that never changes
    // This simulates a real navigation route with waypoints
    
    $coordinates = [];
    
    // Always use the same waypoints for consistency
    $waypoints = [
        ['latitude' => $startLat, 'longitude' => $startLng], // Start
        ['latitude' => $startLat + ($endLat - $startLat) * 0.25, 'longitude' => $startLng + ($endLng - $startLng) * 0.2], // 25% point
        ['latitude' => $startLat + ($endLat - $startLat) * 0.5, 'longitude' => $startLng + ($endLng - $startLng) * 0.45], // 50% point
        ['latitude' => $startLat + ($endLat - $startLat) * 0.75, 'longitude' => $startLng + ($endLng - $startLng) * 0.7], // 75% point
        ['latitude' => $endLat, 'longitude' => $endLng] // End
    ];
    
    // Generate consistent path between waypoints
    for ($i = 0; $i < count($waypoints) - 1; $i++) {
        $current = $waypoints[$i];
        $next = $waypoints[$i + 1];
        
        // Add 3 points between each waypoint for smooth path
        for ($j = 0; $j <= 3; $j++) {
            $ratio = $j / 3;
            $coordinates[] = [
                'latitude' => $current['latitude'] + ($next['latitude'] - $current['latitude']) * $ratio,
                'longitude' => $current['longitude'] + ($next['longitude'] - $current['longitude']) * $ratio,
            ];
        }
    }
    
    return $coordinates;
}

function calculateRouteDistance($coordinates) {
    $totalDistance = 0;
    for ($i = 1; $i < count($coordinates); $i++) {
        $totalDistance += calculateDistance(
            $coordinates[$i - 1]['latitude'], 
            $coordinates[$i - 1]['longitude'],
            $coordinates[$i]['latitude'], 
            $coordinates[$i]['longitude']
        );
    }
    return $totalDistance;
}

function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // Earth's radius in kilometers
    $dLat = ($lat2 - $lat1) * M_PI / 180;
    $dLng = ($lng2 - $lng1) * M_PI / 180;
    $a = sin($dLat/2) * sin($dLat/2) + cos($lat1 * M_PI / 180) * cos($lat2 * M_PI / 180) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}
?>


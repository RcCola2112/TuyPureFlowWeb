<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include '../db.php';

// --- SMS API CONFIGURATION (Confirmed Working Credentials) ---
$sms_api_url = 'https://api.sms-gate.app/3rdparty/v1/messages';
$sms_api_device_id = 'JmVe0esL_o156_NG3p9D2'; // Confirmed Device ID
$sms_api_auth_user = 'LH0OE9';
$sms_api_auth_pass = 'ArcieeCola2129'; // Confirmed Working Password

// --- DEBUG LOGGING SETUP ---
$logFile = 'sms_inbox_log.txt';
$timestamp = date('Y-m-d H:i:s');

// ===========================================================
// 📨 1. RECEIVE SMS HANDLER (Webhook from SMS Gate)
// ===========================================================
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

// Check if this is a JSON payload and not a form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $data && !isset($_POST['send_sms'])) {
    
    // Optional logging (for debugging)
    file_put_contents($logFile, $timestamp . " [WEBHOOK HIT] " . $rawData . PHP_EOL, FILE_APPEND);

    $sender = null; // The number that sent the text (from webhook payload)
    $message = null; // The content of the text

    // Correctly extract data from the nested 'payload' structure
    if (isset($data['payload']['message']) && isset($data['payload']['phoneNumber'])) {
        $sender = trim($data['payload']['phoneNumber']);
        $message = trim($data['payload']['message']);
    }

    if ($sender && $message) {
        $status = 'Pending';
        $target_distributor_phone = $sender; // Default to sender's phone for routing
        $order_message = $message;
        $container_id = null;
        $quantity = null;
        $db_shop_id = 1; // Default fallback shop ID
        $distributor_match_found = false;

        // -----------------------------------------------------------------
        // STEP A: PARSE MESSAGE FOR CONTAINER ORDER AND EXTRACT ROUTING NUMBER
        // -----------------------------------------------------------------
        
        // 1. Try to find a phone number on the first line of the message body (e.g., 09454566364)
        if (preg_match('/^\+?(\d{10,12})\s*[\r\n]/', $message, $phone_matches)) {
            // Use the number found in the message body for routing
            $target_distributor_phone = $phone_matches[1];
            // Remove the phone number line from the message for cleaner parsing of the order
            $message = preg_replace('/^\+?(\d{10,12})\s*/', '', $message, 1);
        }

        // 2. Parse Order Details using the updated multiline structure and colon for Quantity
        // Pattern: (Container Type: X) ... (Quantity: Y)
        $pattern = '/container\s*type:\s*(\d+).*?quantity:\s*(\d+)/si';
        if (preg_match($pattern, $message, $matches)) {
            $container_id = intval($matches[1]);
            $quantity = intval($matches[2]);
            $status = 'New Order';
            $order_message = "Container ID: {$container_id}, Quantity: {$quantity}";
        }
        
        // -----------------------------------------------------------------
        // STEP B: FIND DISTRIBUTOR/SHOP BASED ON THE TARGET PHONE NUMBER
        // -----------------------------------------------------------------
        // Normalizes to 10-digit format (e.g., 9454566364) for lookup
        $normalized_target = preg_replace('/^\+?63|^0/', '', $target_distributor_phone);
        
        try {
            // Attempt to find the distributor using the normalized phone number
            // The DB phone is also stripped of non-digit characters (+, space, -) for maximal matching flexibility.
            $stmt = $conn->prepare("SELECT d.distributor_id, s.shop_id FROM distributor d JOIN shop s ON d.distributor_id = s.distributor_id WHERE REPLACE(REPLACE(REPLACE(d.phone, '+', ''), ' ', ''), '-', '') LIKE ? LIMIT 1");
            $stmt->execute(['%' . $normalized_target]); 
            $distributor_data = $stmt->fetch();
            
            if ($distributor_data) {
                // MATCH FOUND: Use the distributor's actual shop ID
                $db_shop_id = $distributor_data['shop_id'];
                $distributor_match_found = true;
            }
        } catch (PDOException $e) {
            // Database lookup failed, proceed with default shop_id
            file_put_contents($logFile, $timestamp . " [DB LOOKUP ERROR] " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }

        // -----------------------------------------------------------------
        // STEP C: SAVE ORDER
        // -----------------------------------------------------------------
        $routing_status = $distributor_match_found ? "Routed to Shop ID {$db_shop_id}" : "FAILED to route (Shop ID 1)";
        
        try {
            // *** FIX: Updated INSERT statement to match required schema columns ***
            // Columns: consumer_id, shop_id, container_type_id, sender_phone_number, message, quantity, total_price, status
            $stmt = $conn->prepare("INSERT INTO sms_orders (consumer_id, shop_id, container_type_id, sender_phone_number, message, quantity, total_price, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            // Note: total_price is mandatory (NOT NULL) so we set it to 0.
            // Note: consumer_id is nullable, set to NULL.
            $stmt->execute([
                NULL, // consumer_id
                $db_shop_id, 
                $container_id, // Maps to container_type_id
                $sender, // Consumer's actual number
                $order_message, 
                $quantity, 
                0, // total_price (MUST be set, NOT NULL)
                $status 
            ]);

            // Final logging output
            file_put_contents($logFile, $timestamp . " [DB INSERT] Success: {$routing_status}. Target Phone used for routing: {$target_distributor_phone}. Final Shop ID: {$db_shop_id}." . PHP_EOL, FILE_APPEND);

            http_response_code(200);
            echo json_encode(['success' => true]);
            exit; 
        } catch (PDOException $e) {
            file_put_contents($logFile, $timestamp . " [DB ERROR - INSERT FAIL] " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            http_response_code(500);
            echo json_encode(['error' => 'Database error (Failed to insert order)']);
            exit;
        }
    } else {
        file_put_contents($logFile, $timestamp . " [INVALID PAYLOAD] Missing message/phoneNumber keys in payload." . PHP_EOL, FILE_APPEND);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }
}

// ===========================================================
// 📨 2. SEND SMS SECTION (OUTGOING TEXT)
// ===========================================================
$sms_send_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    $manual_phone = isset($_POST['manual_phone']) ? trim($_POST['manual_phone']) : '';
    $manual_message = isset($_POST['manual_message']) ? trim($_POST['manual_message']) : '';

    if ($manual_phone && $manual_message) {
        $phone = $manual_phone;
        $message = $manual_message;
    } elseif (!empty($_POST['sms_order_id'])) {
        $sms_order_id = intval($_POST['sms_order_id']);
        $stmt = $conn->prepare("SELECT * FROM sms_orders WHERE sms_order_id = ? LIMIT 1");
        $stmt->execute([$sms_order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $sms_send_result = "Order not found.";
            $phone = '';
            $message = '';
        } else {
            $phone = $order['sender_phone_number'] ?? '';
            $message = 'Your order is being processed.'; 
        }
    } else {
        $phone = '';
        $message = '';
    }

    if ($phone && $message) {
        // --- FIX 1: Normalize Phone Number to +63 format ---
        if ($phone) {
            $phone = preg_replace('/^\+/', '', $phone);
            if (substr($phone, 0, 1) === '0') {
                $phone = '63' . substr($phone, 1);
            }
            if (substr($phone, 0, 2) === '63') {
                $phone = '+' . $phone;
            }
        }
        // --- END FIX 1 ---

        // Build payload. Device ID is mandatory for reliable sending.
        $payload = [
            'textMessage' => ['text' => $message],
            'phoneNumbers' => [$phone],
            'simNumber' => 1, // TESTING SIM SLOT 1
            'ttl' => 3600,
            'priority' => 100,
            'deviceId' => $sms_api_device_id
        ];
        
        $params = http_build_query([
            'skipPhoneValidation' => 'true',
            'deviceActiveWithin' => 12
        ]);

        $ch = curl_init();
        
        // --- Error Handling Check ---
        if ($ch === false) {
            $sms_send_result = "cURL initialization failed. Check your hosting environment.";
        } else {
            // Original code execution block
            curl_setopt($ch, CURLOPT_URL, $sms_api_url . '?' . $params);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_USERPWD, $sms_api_auth_user . ':' . $sms_api_auth_pass);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $sms_send_result = "cURL error: " . $curlErr;
            } else {
                $respData = json_decode($response, true);
                if ($httpCode >= 200 && $httpCode < 300) {
                    $sms_send_result = "SMS sent successfully (HTTP {$httpCode}).";
                } else {
                    $apiError = $respData ? ($respData['message'] ?? json_encode($respData)) : $response;
                    $sms_send_result = "SMS API returned HTTP {$httpCode}. Error: " . $apiError;
                }
            }
        }
    } else {
        $sms_send_result = "Phone number and message required.";
    }
}

// ===========================================================
// 👤 3. DISTRIBUTOR AND SHOP FETCH (DASHBOARD VIEW)
// ===========================================================
$distributor_id = $_SESSION['distributor_id'] ?? null;
if (!$distributor_id) {
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$stmtShop = $conn->prepare("SELECT shop_id, name FROM shop WHERE distributor_id = ? LIMIT 1");
$stmtShop->execute([$distributor_id]);
$shop = $stmtShop->fetch();
$shop_id = $shop['shop_id'] ?? null;
$shopname = $shop['name'] ?? '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

$sms_orders = [];
if ($shop_id) {
    // Fetches saved orders from the database
    // Orders are now filtered by the shop_id found during authentication/distributor lookup.
    $stmt = $conn->prepare("SELECT sms_order_id, sender_phone_number, message, status, created_at, updated_at FROM sms_orders WHERE shop_id = ? ORDER BY created_at ASC");
    $stmt->execute([$shop_id]);
    $sms_orders = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Orders by Text | Tuy PureFlow Distributor</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .gradient-text {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }
  </style>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex bg-gray-100">
  <?php include 'sidebar.php'; ?>
  <div class="ml-64 flex flex-col flex-1">
    <?php include 'header.php'; ?>
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="max-w-6xl w-full mx-auto">
        <div class="mb-8">
          <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
            <i class="fas fa-sms text-blue-600"></i>
            Orders by Text
          </h1>
          <p class="text-gray-600">Manage SMS-based orders</p>
        </div>


      <div class="bg-white p-6 rounded-lg shadow-md mb-8">
        <h2 class="text-xl font-semibold mb-4 text-gray-800">Send SMS to Any Number</h2>
        <div class="mb-4 text-gray-700 text-sm">
          Format: <span class="font-mono bg-gray-100 px-2 py-1 rounded">+639XXXXXXXXX</span>
        </div>
        <form method="POST" id="sendSmsForm" class="flex flex-col gap-4 md:flex-row md:items-end">
          <input type="text" name="manual_phone" id="manual_phone" placeholder="e.g. +639123456789" class="border border-gray-300 rounded-md px-4 py-2 w-full md:w-64" required>
          <input type="text" name="manual_message" id="manual_message" placeholder="Enter message" class="border border-gray-300 rounded-md px-4 py-2 w-full md:flex-1" required>
          <button type="submit" name="send_sms" class="bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2 rounded-md transition duration-200">Send SMS</button>
        </form>
      </div>

      <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-semibold mb-4 text-gray-800">Received Orders</h2>
        <?php if ($sms_orders): ?>
          <div class="overflow-x-auto">
            <table class="min-w-full table-auto border-collapse">
              <thead>
                <tr class="bg-gray-100 border-b border-gray-200 text-xs uppercase text-gray-600">
                  <th class="px-3 py-3 text-left">Order ID</th>
                  <th class="px-3 py-3 text-left">Sender</th>
                  <th class="px-3 py-3 text-left">Message</th>
                  <th class="px-3 py-3 text-left">Status</th>
                  <th class="px-3 py-3 text-left">Created At</th>
                  <th class="px-3 py-3 text-left">Updated At</th>
                  <th class="px-3 py-3 text-left">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sms_orders as $order): ?>
                  <tr class="hover:bg-gray-50 border-b border-gray-100">
                    <td class="px-3 py-3 text-sm text-gray-500"><?= htmlspecialchars($order['sms_order_id']) ?></td>
                    <td class="px-3 py-3 text-sm font-medium text-gray-800"><?= htmlspecialchars($order['sender_phone_number']) ?></td>
                    <td class="px-3 py-3 text-sm text-gray-600 truncate max-w-xs" title="<?= htmlspecialchars($order['message']) ?>"><?= htmlspecialchars($order['message']) ?></td>
                    <td class="px-3 py-3 text-sm">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $order['status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800' ?>">
                            <?= htmlspecialchars($order['status']) ?>
                        </span>
                    </td>
                    <td class="px-3 py-3 text-sm text-gray-500"><?= htmlspecialchars($order['created_at']) ?></td>
                    <td class="px-3 py-3 text-sm text-gray-500"><?= htmlspecialchars($order['updated_at']) ?></td>
                    <td class="px-3 py-3">
                      <button type="button" onclick="openReplyModal('<?= htmlspecialchars(addslashes($order['sender_phone_number'])) ?>')" class="text-blue-600 hover:text-blue-800 text-sm font-medium hover:underline">Reply</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-gray-500 text-center py-4">No SMS orders found for your shop.</div>
        <?php endif; ?>
      </div>
      </div>
    </main>
  </div>

  <!-- Reply Modal -->
  <div id="replyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 transform transition-all">
      <div class="p-6">
        <!-- Modal Header -->
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-semibold text-gray-800">Reply to Customer</h3>
          <button onclick="closeReplyModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <!-- Modal Body -->
        <form method="POST" id="replyModalForm" class="flex flex-col gap-4">
          <input type="hidden" name="send_sms" value="1">
          
          <!-- Mobile Number Display -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Mobile Number</label>
            <div class="px-4 py-2 bg-gray-50 border border-gray-300 rounded-md text-gray-800 font-medium" id="modalPhoneNumber">
              <!-- Phone number will be inserted here -->
            </div>
            <input type="hidden" name="manual_phone" id="modalPhoneInput">
          </div>
          
          <!-- Message Composition -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Compose Message</label>
            <textarea 
              name="manual_message" 
              id="modalMessageInput" 
              rows="5" 
              placeholder="Type your message here..." 
              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
              required
            ></textarea>
          </div>
          
          <!-- Modal Footer -->
          <div class="flex justify-end gap-3 mt-2">
            <button 
              type="button" 
              onclick="closeReplyModal()" 
              class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md font-medium transition-colors"
            >
              Cancel
            </button>
            <button 
              type="submit" 
              class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md font-medium transition-colors"
            >
              Send SMS
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    function openReplyModal(phoneNumber) {
      const modal = document.getElementById('replyModal');
      const phoneDisplay = document.getElementById('modalPhoneNumber');
      const phoneInput = document.getElementById('modalPhoneInput');
      const messageInput = document.getElementById('modalMessageInput');
      
      // Set the phone number
      phoneDisplay.textContent = phoneNumber;
      phoneInput.value = phoneNumber;
      
      // Clear previous message
      messageInput.value = '';
      
      // Show modal
      modal.classList.remove('hidden');
      
      // Focus on message input
      setTimeout(() => {
        messageInput.focus();
      }, 100);
    }

    function closeReplyModal() {
      const modal = document.getElementById('replyModal');
      modal.classList.add('hidden');
      
      // Clear form
      document.getElementById('modalMessageInput').value = '';
    }

    // Close modal when clicking outside
    document.getElementById('replyModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeReplyModal();
      }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeReplyModal();
      }
    });

    // Handle form submission - close modal after successful submission
    document.getElementById('replyModalForm').addEventListener('submit', function(e) {
      // The form will submit normally via POST
      // Modal will be closed on page reload/redirect
    });
  </script>
</body>
</html>
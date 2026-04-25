<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

// Handle AJAX add address BEFORE any redirect logic
if (isset($_POST['ajax_add_address'])) {
  if (!isset($_SESSION['consumer_id'])) {
    echo 'error:not_logged_in';
    exit;
  }
  $user_id = $_SESSION['consumer_id'];
  $name = trim($_POST['name'] ?? '');
  $contact = trim($_POST['contact_number'] ?? '');
  $street = $_POST['street'] ?? '';
  $barangay = $_POST['barangay'] ?? '';
  $city = $_POST['city'] ?? '';
  $region = $_POST['region'] ?? '';
  $zip = $_POST['zip_code'] ?? '';
  $latitude = $_POST['latitude'] ?? '';
  $longitude = $_POST['longitude'] ?? '';
  if (!$name) {
    echo 'error:missing_name';
    exit;
  }
  if (!$contact) {
    echo 'error:missing_contact';
    exit;
  }
  $stmt = $conn->prepare("INSERT INTO address (consumer_id, name, contact_number, street, barangay, city, region, zip_code, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->execute([$user_id, $name, $contact, $street, $barangay, $city, $region, $zip, $latitude, $longitude]);
  echo 'success';
  exit;
}

if (!isset($_SESSION['consumer_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}

$user_id = $_SESSION['consumer_id'];

// Check if this is a direct purchase (Buy Now from shop page)
$is_direct_purchase = isset($_POST['direct_purchase']) && $_POST['direct_purchase'] == '1';

if ($is_direct_purchase) {
  // Handle direct purchase - don't fetch from cart
  $container_id = intval($_POST['container_id'] ?? 0);
  $shop_id = intval($_POST['shop_id'] ?? 0);
  $qty = intval($_POST['qty'] ?? 1);
  $option = $_POST['option'] ?? 'with-container';
  $name = trim($_POST['name'] ?? '');
  $price_with_container = floatval($_POST['price_with_container'] ?? 0);
  $price_refill = floatval($_POST['price_refill'] ?? 0);
  
  // Determine price based on option
  $price = ($option === 'with-container') ? $price_with_container : $price_refill;
  
  if ($container_id <= 0 || $shop_id <= 0 || $price <= 0) {
    header('Location: cart.php');
    exit;
  }
  
  // Fetch container and shop info
  $stmt = $conn->prepare("
    SELECT cont.*, ct.type, s.shop_id, s.name AS shop_name, s.contact_number AS shop_contact, s.location AS shop_location
    FROM container cont
    LEFT JOIN container_type ct ON cont.container_type_id = ct.container_type_id
    LEFT JOIN shop s ON cont.shop_id = s.shop_id
    WHERE cont.container_id = ? AND s.shop_id = ?
  ");
  $stmt->execute([$container_id, $shop_id]);
  $product_data = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$product_data) {
    header('Location: cart.php');
    exit;
  }
  
  // Create virtual cart item array (not from actual cart)
  $cart_items = [[
    'cart_id' => 0, // No cart ID since it's not in cart
    'container_id' => $container_id,
    'product_name' => $name ?: $product_data['type'],
    'price' => $price,
    'qty' => $qty,
    'type' => $option, // Store the option (with-container or refill-only) for purchase_type determination
    'container_image' => $product_data['container_image'] ?? null,
    'shop_id' => $shop_id,
    'shop_name' => $product_data['shop_name'],
    'shop_contact' => $product_data['shop_contact'],
    'shop_location' => $product_data['shop_location']
  ]];
  
} else {
  // Handle regular cart checkout
  $selected_ids = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];

  if (empty($selected_ids)) {
    header('Location: cart.php');
    exit;
  }

  // Prepare placeholders for IN clause
  $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

  // Fetch selected cart items with container images and shop info
  $stmt = $conn->prepare("
    SELECT c.*, cont.container_image, cont.container_id, ct.type, s.shop_id, s.name AS shop_name, s.contact_number AS shop_contact, s.location AS shop_location
    FROM cart c
    LEFT JOIN container cont ON c.container_id = cont.container_id
    LEFT JOIN container_type ct ON cont.container_type_id = ct.container_type_id
    LEFT JOIN shop s ON c.shop_id = s.shop_id
    WHERE c.consumer_id = ? AND c.cart_id IN ($placeholders)
  ");
  $stmt->execute(array_merge([$user_id], $selected_ids));
  $cart_items = $stmt->fetchAll();
}

// Process container images
foreach ($cart_items as &$item) {
  if (!empty($item['container_image'])) {
    $imgData = $item['container_image'];
    // Detect image type (PNG/JPEG/WEBP)
    $imgType = 'png';
    if (is_string($imgData) && strlen($imgData) > 0) {
      if (substr($imgData, 0, 2) === "\xFF\xD8") {
        $imgType = 'jpeg';
      } elseif (substr($imgData, 0, 4) === "\x89PNG") {
        $imgType = 'png';
      } elseif (substr($imgData, 0, 4) === "RIFF" && substr($imgData, 8, 4) === "WEBP") {
        $imgType = 'webp';
      }
    }
    $item['container_image'] = 'data:image/' . $imgType . ';base64,' . base64_encode($imgData);
  } else {
    $item['container_image'] = '../images/watercontainer.png';
  }
}
unset($item);

// Get shop info from first item
$shop_info = null;
if (!empty($cart_items)) {
  $shop_info = [
    'shop_id' => $cart_items[0]['shop_id'],
    'shop_name' => $cart_items[0]['shop_name'],
    'shop_contact' => $cart_items[0]['shop_contact'],
    'shop_location' => $cart_items[0]['shop_location']
  ];
}

// Fetch default address for the logged-in user
$stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ? AND is_default = 1 LIMIT 1");
$stmt->execute([$user_id]);
$default_address = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$default_address) {
    // fallback: get any address
    $stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $default_address = $stmt->fetch(PDO::FETCH_ASSOC);
}

$subtotal = 0;
foreach ($cart_items as $item) {
  $subtotal += $item['qty'] * $item['price'];
}
$total = $subtotal;

// Fetch all addresses for the user
$stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ?");
$stmt->execute([$user_id]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find the selected address details
$selected_address = null;
if (!empty($default_address)) {
  $selected_address = $default_address;
} elseif (!empty($addresses)) {
  $selected_address = $addresses[0]; // fallback to the first address if no default
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
  // Get selected address_id from POST, fallback to default if not set
  $address_id = isset($_POST['address_id']) ? intval($_POST['address_id']) : ($selected_address['address_id'] ?? null);
  if (!$address_id) {
    echo '<script>alert("No address found. Please add a shipping address first."); window.location="address.php";</script>';
    exit;
  }

  // Get shop_id - from direct purchase POST or from cart items
  $order_shop_id = 0;
  if ($is_direct_purchase) {
    $order_shop_id = intval($_POST['shop_id'] ?? 0);
  } elseif (!empty($cart_items)) {
    // Get shop_id from first cart item
    $order_shop_id = $cart_items[0]['shop_id'] ?? 0;
  }

  // Use quantities from cart (already calculated)
  // Total is already calculated from cart items

  // Insert order
  $stmt = $conn->prepare("INSERT INTO orders (consumer_id, shop_id, address_id, total_amount, status, order_date) VALUES (?, ?, ?, ?, 'pending', NOW())");
  $stmt->execute([$user_id, $order_shop_id, $address_id, $total]);
  $order_id = $conn->lastInsertId();

  // Insert order items with updated quantities
  $stmt = $conn->prepare("INSERT INTO order_items (order_id, container_id, quantity, price, purchase_type) VALUES (?, ?, ?, ?, ?)");
  foreach ($cart_items as $item) {
    $purchase_type = ($item['type'] === 'refill-only' || (isset($_POST['option']) && $_POST['option'] === 'refill-only')) ? 'refill-only' : 'with-container';
    $stmt->execute([$order_id, $item['container_id'], $item['qty'], $item['price'], $purchase_type]);
  }

  // Remove items from cart only if it's NOT a direct purchase
  if (!$is_direct_purchase) {
    $cart_ids = array_column($cart_items, 'cart_id');
    $cart_ids = array_filter($cart_ids, function($id) { return $id > 0; }); // Filter out 0 (virtual cart items)
    if (!empty($cart_ids)) {
      $in = str_repeat('?,', count($cart_ids) - 1) . '?';
      $stmt = $conn->prepare("DELETE FROM cart WHERE cart_id IN ($in) AND consumer_id = ?");
      $stmt->execute(array_merge($cart_ids, [$user_id]));
    }
  }

  // Redirect to confirmation page
  header('Location: confirm_order.php?order_id=' . $order_id);
  exit;
}

// Fetch all addresses for the user
$stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ?");
$stmt->execute([$user_id]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find the selected address details
$selected_address = null;
if (!empty($default_address)) {
    $selected_address = $default_address;
} elseif (!empty($addresses)) {
    $selected_address = $addresses[0]; // fallback to the first address if no default
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Checkout - Tuy PureFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
  <!-- Leaflet OpenStreetMap -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body class="bg-gray-50 font-sans">
  <header class="header-gradient sticky top-0 z-50">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <a href="landing_page.php" class="brand-link flex items-center gap-2 text-xl">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
        <span>Tuy PureFlow</span>
      </a>
      <div class="flex items-center gap-4">
        <?php if (isset($_SESSION['consumer_id'])): ?>
          <?php include 'notification_icon.php'; ?>
          <?php
            // Fetch consumer profile picture
            $consumer_profile = null;
            $profile_stmt = $conn->prepare("SELECT profile_pic FROM consumer WHERE consumer_id = ? LIMIT 1");
            $profile_stmt->execute([$_SESSION['consumer_id']]);
            $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile_data && isset($profile_data['profile_pic'])) {
                $profile_blob = $profile_data['profile_pic'];
                
                // Check if it's NULL or empty
                if ($profile_blob === null || $profile_blob === '') {
                    $consumer_profile = '../images/default-profile.jpg';
                } else {
                    // Handle both string and binary data
                    if (is_resource($profile_blob)) {
                        $profile_blob = stream_get_contents($profile_blob);
                    }
                    
                    // Ensure it's a string and has content
                    if (is_string($profile_blob) && strlen($profile_blob) > 10) {
                        // Detect image type from magic bytes
                        $imgType = 'png'; // default
                        $firstBytes = substr($profile_blob, 0, 12);
                        
                        if (substr($firstBytes, 0, 2) === "\xFF\xD8") {
                            $imgType = 'jpeg';
                        } elseif (substr($firstBytes, 0, 4) === "\x89PNG") {
                            $imgType = 'png';
                        } elseif (substr($firstBytes, 0, 4) === "RIFF" && substr($firstBytes, 8, 4) === "WEBP") {
                            $imgType = 'webp';
                        }
                        
                        $consumer_profile = 'data:image/' . $imgType . ';base64,' . base64_encode($profile_blob);
                    } else {
                        $consumer_profile = '../images/default-profile.jpg';
                    }
                }
            } else {
                $consumer_profile = '../images/default-profile.jpg';
            }
          ?>
          <a href="account.php" class="flex items-center gap-2 text-white font-semibold hover:underline px-3 py-2 rounded-lg hover:bg-white hover:bg-opacity-20 transition-all">
            <img src="<?= htmlspecialchars($consumer_profile) ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover border-2 border-white border-opacity-30">
            <span><?= htmlspecialchars($_SESSION['consumer_name']) ?></span>
          </a>
          <a href="logout.php" class="text-white hover:bg-white hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all font-medium">Logout</a>
        <?php else: ?>
          <div class="relative group">
            <button class="px-5 py-2.5 bg-white bg-opacity-25 backdrop-blur-sm text-white rounded-lg hover:bg-opacity-35 font-semibold flex items-center gap-2 focus:outline-none transition-all border border-white border-opacity-30">
              Account
              <svg class="w-4 h-4 transform transition-transform group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
              </svg>
            </button>
            <div class="absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-10 overflow-hidden">
              <a href="signup.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 font-medium transition-colors">Sign Up</a>
              <a href="login.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 font-medium transition-colors border-t border-gray-100">Sign In</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="container mx-auto px-4 py-8 max-w-7xl">
    <div class="mb-6">
      <h1 class="text-3xl font-bold mb-2 text-gradient">Checkout</h1>
      <p class="text-gray-600">Review your order and complete your purchase</p>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
      <!-- Left Column: Order Details -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Shop Information Card -->
        <?php if ($shop_info): ?>
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-store text-white text-lg"></i>
            </div>
            <div>
              <h2 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($shop_info['shop_name'] ?? 'Shop') ?></h2>
              <p class="text-sm text-gray-500 flex items-center gap-1">
                <i class="fas fa-map-marker-alt text-cyan-600"></i>
                <?= htmlspecialchars($shop_info['shop_location'] ?? 'Location not available') ?>
              </p>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Shipping Information Card -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
          <div class="flex items-center gap-2 mb-4">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-truck text-white"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800">Delivery Address</h2>
          </div>
          <div id="selectedAddress" class="bg-gray-50 rounded-lg p-4 border border-gray-200">
            <?php if ($selected_address): ?>
              <div class="space-y-2">
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                      <i class="fas fa-user text-cyan-600"></i>
                      <span class="font-semibold text-gray-800"><?= htmlspecialchars($selected_address['full_name'] ?? $selected_address['name'] ?? '') ?></span>
                    </div>
                    <div class="flex items-center gap-2 mb-2 text-sm text-gray-600">
                      <i class="fas fa-phone text-cyan-600"></i>
                      <span><?= htmlspecialchars($selected_address['contact_number'] ?? '') ?></span>
                    </div>
                    <div class="flex items-start gap-2 text-sm text-gray-600">
                      <i class="fas fa-map-marker-alt text-cyan-600 mt-1"></i>
                      <div>
                        <?= htmlspecialchars($selected_address['street'] ?? '') ?>,
                        <?= htmlspecialchars($selected_address['barangay'] ?? '') ?>,
                        <?= htmlspecialchars($selected_address['city'] ?? '') ?>,
                        <?= htmlspecialchars($selected_address['region'] ?? '') ?>
                        <?= htmlspecialchars($selected_address['zip_code'] ?? '') ?>
                      </div>
                    </div>
                  </div>
                </div>
                <button type="button" onclick="openAddressModal()" class="mt-3 text-cyan-600 hover:text-blue-700 font-medium text-sm flex items-center gap-1 transition-colors">
                  <i class="fas fa-edit"></i> Change Address
                </button>
              </div>
            <?php else: ?>
              <div class="text-center py-4">
                <i class="fas fa-exclamation-circle text-red-500 text-2xl mb-2"></i>
                <p class="text-red-600 mb-3">No address found. Please add your shipping address first.</p>
                <button type="button" onclick="openAddressModal()" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white px-4 py-2 rounded-lg font-medium transition-all shadow-md">
                  <i class="fas fa-plus mr-2"></i>Add Address
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Order Items Card -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
          <div class="flex items-center gap-2 mb-4">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-shopping-bag text-white"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800">Order Items</h2>
          </div>
          <div class="space-y-4">
            <?php foreach ($cart_items as $item): ?>
            <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200 hover:shadow-md transition-all">
              <img src="<?= htmlspecialchars($item['container_image'] ?? '../images/watercontainer.png') ?>" 
                   alt="<?= htmlspecialchars($item['product_name']) ?>" 
                   class="w-20 h-20 object-contain rounded-lg border border-gray-200 bg-white">
              <div class="flex-1">
                <h3 class="font-semibold text-gray-800 mb-1"><?= htmlspecialchars($item['product_name']) ?></h3>
                <div class="flex items-center gap-2 mb-2">
                  <span class="inline-flex items-center px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                    <?= htmlspecialchars($item['type'] ?? 'Standard') ?>
                  </span>
                  <span class="text-sm text-gray-500">× <?= $item['qty'] ?></span>
                </div>
                <p class="text-lg font-bold text-gray-800">₱<?= number_format($item['qty'] * $item['price'], 2) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Delivery Instructions (Optional) -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
          <div class="flex items-center gap-2 mb-4">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-sticky-note text-white"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800">Delivery Instructions</h2>
          </div>
          <textarea name="delivery_notes" id="delivery_notes" rows="3" 
                    class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" 
                    placeholder="Add any special delivery instructions (optional)"></textarea>
        </div>
      </div>

      <!-- Right Column: Order Summary -->
      <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 sticky top-24">
          <div class="flex items-center gap-2 mb-6">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-receipt text-white"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800">Order Summary</h2>
          </div>
          
          <div class="space-y-3 mb-6">
            <div class="flex justify-between text-sm text-gray-600">
              <span>Items (<?= count($cart_items) ?>)</span>
              <span>₱<?= number_format($subtotal, 2) ?></span>
            </div>
            <div class="flex justify-between text-sm text-gray-600">
              <span>Delivery Fee</span>
              <span class="text-gray-400">Free</span>
            </div>
            <div class="border-t border-gray-200 pt-3 mt-3">
              <div class="flex justify-between items-center">
                <span class="text-lg font-bold text-gray-800">Total</span>
                <span class="text-2xl font-bold text-gradient">₱<?= number_format($total, 2) ?></span>
              </div>
            </div>
          </div>

          <form method="POST" action="checkout.php" id="orderForm">
            <?php if ($is_direct_purchase): ?>
              <!-- Direct purchase parameters -->
              <input type="hidden" name="direct_purchase" value="1">
              <input type="hidden" name="container_id" value="<?= htmlspecialchars($_POST['container_id'] ?? '') ?>">
              <input type="hidden" name="shop_id" value="<?= htmlspecialchars($_POST['shop_id'] ?? '') ?>">
              <input type="hidden" name="qty" value="<?= htmlspecialchars($_POST['qty'] ?? '1') ?>">
              <input type="hidden" name="option" value="<?= htmlspecialchars($_POST['option'] ?? 'with-container') ?>">
              <input type="hidden" name="price_with_container" value="<?= htmlspecialchars($_POST['price_with_container'] ?? '0') ?>">
              <input type="hidden" name="price_refill" value="<?= htmlspecialchars($_POST['price_refill'] ?? '0') ?>">
              <input type="hidden" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            <?php else: ?>
              <!-- Cart checkout parameters -->
              <?php foreach ($selected_ids as $id): ?>
                <input type="hidden" name="selected_items[]" value="<?= $id ?>">
              <?php endforeach; ?>
            <?php endif; ?>
            <input type="hidden" name="address_id" id="selected_address_id" value="<?= $selected_address['address_id'] ?? '' ?>">
            <button type="submit" name="place_order" 
                    class="w-full bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-3 rounded-lg font-semibold text-lg shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2">
              <i class="fas fa-check-circle"></i>
              Place Order
            </button>
          </form>

          <div class="mt-4 text-center">
            <a href="cart.php" class="text-sm text-gray-500 hover:text-cyan-600 transition-colors">
              <i class="fas fa-arrow-left mr-1"></i> Back to Cart
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Address Modal -->
    <div id="addressModal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm flex items-center justify-center z-50 hidden" onclick="if(event.target===this)closeAddressModal()">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 relative max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between mb-6">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-map-marker-alt text-white"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">My Addresses</h2>
          </div>
          <button onclick="closeAddressModal()" class="text-gray-400 hover:text-red-600 text-2xl font-bold transition-colors w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <form id="addressForm">
          <!-- Always render add address form, hide by default if addresses exist -->
          <div id="addAddressFields" style="<?php echo empty($addresses) ? '' : 'display:none;'; ?>">
            <input type="text" name="name" id="new_name" class="border rounded p-2 w-full mb-2" placeholder="Full Name">
            <input type="text" name="contact_number" id="new_contact" class="border rounded p-2 w-full mb-2" placeholder="Phone Number">
            <input type="text" name="street" id="new_street" class="border rounded p-2 w-full mb-2" placeholder="Street">
            <input type="text" name="barangay" id="new_barangay" class="border rounded p-2 w-full mb-2" placeholder="Barangay">
            <input type="text" name="city" id="new_city" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="City" readonly>
            <input type="text" name="region" id="new_region" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="Region" readonly>
            <input type="text" name="zip_code" id="new_zip" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="Postal Code" readonly>
            <input type="hidden" name="latitude" id="new_latitude" value="">
            <input type="hidden" name="longitude" id="new_longitude" value="">
            <label class="block text-sm font-medium mb-1">Set location on map</label>
            <div id="leafletMap" class="w-full h-56 rounded border mt-3 mb-2"></div>
            <div id="leafletLocationInfo" class="text-xs text-gray-500 mt-2">Click the map or drag the marker to select your address.</div>
            <button type="button" onclick="openMapModal()" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded mb-2">Pick Location on Map</button>
            <div class="mt-2 flex justify-end">
              <button type="button" class="bg-green-600 text-white px-4 py-2 rounded" onclick="saveNewAddress()">Save Address</button>
            </div>
          </div>
          <?php if (!empty($addresses)): ?>
            <div class="space-y-3 mb-4">
              <?php foreach ($addresses as $address): ?>
                <label class="flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer hover:border-cyan-500 hover:bg-cyan-50 transition-all <?= $address['address_id'] == $selected_address['address_id'] ? 'border-cyan-500 bg-cyan-50' : 'border-gray-200' ?>">
                  <input type="radio" name="address_id" value="<?= $address['address_id'] ?>" <?= $address['address_id'] == $selected_address['address_id'] ? 'checked' : '' ?> class="mt-1">
                  <div class="flex-1">
                    <div class="flex items-center gap-2 mb-1">
                      <i class="fas fa-user text-cyan-600"></i>
                      <span class="font-bold text-gray-800"><?= htmlspecialchars($address['name']) ?></span>
                    </div>
                    <div class="flex items-center gap-2 mb-1 text-sm text-gray-600">
                      <i class="fas fa-phone text-cyan-600"></i>
                      <span><?= htmlspecialchars($address['contact_number']) ?></span>
                    </div>
                    <div class="flex items-start gap-2 text-sm text-gray-600">
                      <i class="fas fa-map-marker-alt text-cyan-600 mt-0.5"></i>
                      <span>
                        <?= htmlspecialchars($address['street']) ?>, 
                        <?= htmlspecialchars($address['barangay']) ?>, 
                        <?= htmlspecialchars($address['city']) ?>, 
                        <?= htmlspecialchars($address['region']) ?>, 
                        <?= htmlspecialchars($address['zip_code']) ?>
                      </span>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="flex justify-between items-center pt-4 border-t border-gray-200">
              <button type="button" class="bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white px-6 py-2.5 rounded-lg font-medium transition-all shadow-md flex items-center gap-2" onclick="showAddAddressForm();">
                <i class="fas fa-plus"></i> Add New Address
              </button>
              <button type="button" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white px-6 py-2.5 rounded-lg font-medium transition-all shadow-md flex items-center gap-2" onclick="confirmAddress()">
                <i class="fas fa-check"></i> Confirm Selection
              </button>
            </div>
          <?php endif; ?>
        </form>
        <!-- Edit Address Form (hidden by default) -->
        <form id="editAddressForm" class="mt-4 hidden">
          <input type="hidden" name="latitude" id="latitude" value="">
          <input type="hidden" name="longitude" id="longitude" value="">
          <input type="text" name="name" id="edit_name" class="border rounded p-2 w-full mb-2" placeholder="Full Name">
          <input type="text" name="contact_number" id="edit_contact" class="border rounded p-2 w-full mb-2" placeholder="Phone Number">
          <input type="text" name="street" id="edit_street" class="border rounded p-2 w-full mb-2" placeholder="Street">
          <input type="text" name="barangay" id="edit_barangay" class="border rounded p-2 w-full mb-2" placeholder="Barangay">
          <input type="text" name="city" id="edit_city" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="City" readonly>
          <input type="text" name="region" id="edit_region" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="Region" readonly>
          <input type="text" name="zip_code" id="edit_zip" class="border rounded p-2 w-full mb-2 bg-gray-100" placeholder="Postal Code" readonly>
          <div class="mb-2">
            <button type="button" onclick="openMapModal()" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Pick Location on Map</button>
          </div>
          <div class="mt-2 flex justify-end">
            <button type="button" class="bg-green-600 text-white px-4 py-2 rounded" onclick="saveAddress()">Submit</button>
          </div>
        </form>
        <!-- Map Modal for Picking Location -->
        <div id="mapModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden" onclick="if(event.target===this)closeMapModal()">
          <div class="bg-white rounded shadow-lg p-4 w-full max-w-xl relative" onclick="event.stopPropagation()">
            <button onclick="closeMapModal()" class="absolute top-2 right-2 text-gray-600 hover:text-red-600 text-xl font-bold">&times;</button>
            <h2 class="text-lg font-bold mb-2">Select Address Location</h2>
            <div id="map" style="height: 350px; width: 100%;" class="rounded"></div>
            <p class="mt-2 text-sm text-gray-600">Drag the marker or click on the map to set your address location.</p>
            <button onclick="closeMapModal()" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded">Done</button>
          </div>
        </div>
      </div>
    </div>

  </main>

  <!-- Footer -->
  <footer class="mt-16 bg-gray-900 text-white py-12">
    <div class="container mx-auto px-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
        <div class="md:col-span-2">
          <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
            <i class="fas fa-tint text-cyan-400"></i>
            Tuy PureFlow
          </h3>
          <p class="text-gray-400 text-sm mb-4">Your trusted source for clean, pure water. Delivered fresh to your doorstep.</p>
          <div class="flex gap-4">
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-facebook-f"></i>
            </a>
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-instagram"></i>
            </a>
          </div>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Quick Links</h4>
          <ul class="space-y-2 text-sm text-gray-400">
            <li><a href="landing_page.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Browse Shops</a></li>
            <li><a href="account.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Account</a></li>
            <li><a href="my_purchases.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Orders</a></li>
            <li><a href="cart.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Shopping Cart</a></li>
          </ul>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Contact Us</h4>
          <ul class="space-y-3 text-sm text-gray-400">
            <li class="flex items-center gap-2">
              <i class="fas fa-envelope text-cyan-400"></i>
              <span>support@tuypureflow.com</span>
            </li>
            <li class="flex items-center gap-2">
              <i class="fas fa-phone text-cyan-400"></i>
              <span>+63 XXX XXX XXXX</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fas fa-map-marker-alt text-cyan-400 mt-1"></i>
              <span>Tuy, Batangas, Philippines</span>
            </li>
          </ul>
        </div>
      </div>
      <div class="border-t border-gray-800 pt-8">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
          <p class="text-sm text-gray-400">&copy; <?= date('Y') ?> Tuy PureFlow. All rights reserved.</p>
          <div class="flex gap-6 text-sm text-gray-400">
            <a href="#" class="hover:text-cyan-400 transition-colors">Privacy Policy</a>
            <a href="#" class="hover:text-cyan-400 transition-colors">Terms of Service</a>
            <a href="#" class="hover:text-cyan-400 transition-colors">About Us</a>
          </div>
        </div>
      </div>
    </div>
  </footer>

<script>
function showAddAddressForm() {
  var fields = [
    'new_name', 'new_contact', 'new_street', 'new_barangay', 'new_city', 'new_region', 'new_zip', 'new_latitude', 'new_longitude'
  ];
  fields.forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
      if (el.type === 'hidden' || el.type === 'text') el.value = '';
    }
  });
  document.getElementById('addAddressFields').style.display = 'block';
  // Reinitialize map for new address
  if (window.leafletMap && window.leafletMarker) {
    leafletMap.setView([13.9441, 120.7336], 14);
    leafletMarker.setLatLng([13.9441, 120.7336]);
  }
  // Scroll modal to top
  var modal = document.getElementById('addressModal');
  if (modal) modal.scrollTop = 0;
}
function openAddressModal() {
  document.getElementById('addressModal').classList.remove('hidden');
}
function closeAddressModal() {
  document.getElementById('addressModal').classList.add('hidden');
}
function confirmAddress() {
  // Get selected radio button value
  var radios = document.querySelectorAll('#addressForm input[type="radio"][name="address_id"]');
  var selected = null;
  radios.forEach(function(radio) {
    if (radio.checked) selected = radio.value;
  });
  if (selected) {
    document.getElementById('selected_address_id').value = selected;
    // Update displayed address info
    var addressLabels = document.querySelectorAll('#addressForm label');
    var addressInfo = '';
    addressLabels.forEach(function(label) {
      var radio = label.querySelector('input[type="radio"]');
      if (radio && radio.value === selected) {
        var name = label.querySelector('.font-bold').innerText;
        var contactDiv = label.querySelectorAll('.text-sm.text-gray-600')[0];
        var addressDiv = label.querySelectorAll('.text-sm.text-gray-600')[1];
        var contact = contactDiv ? contactDiv.innerText : '';
        var address = addressDiv ? addressDiv.innerText : '';
        addressInfo = '<div class="space-y-2">' +
          '<div class="flex items-center gap-2 mb-2">' +
          '<i class="fas fa-user text-cyan-600"></i>' +
          '<span class="font-semibold text-gray-800">' + name + '</span>' +
          '</div>' +
          '<div class="flex items-center gap-2 mb-2 text-sm text-gray-600">' +
          '<i class="fas fa-phone text-cyan-600"></i>' +
          '<span>' + contact + '</span>' +
          '</div>' +
          '<div class="flex items-start gap-2 text-sm text-gray-600">' +
          '<i class="fas fa-map-marker-alt text-cyan-600 mt-1"></i>' +
          '<div>' + address + '</div>' +
          '</div>' +
          '</div>' +
          '<button type="button" onclick="openAddressModal()" class="mt-3 text-cyan-600 hover:text-blue-700 font-medium text-sm flex items-center gap-1 transition-colors">' +
          '<i class="fas fa-edit"></i> Change Address' +
          '</button>';
      }
    });
    document.getElementById('selectedAddress').innerHTML = addressInfo;
  }
  closeAddressModal();
}
function editAddress(addressId) {
  document.getElementById('editAddressForm').classList.remove('hidden');
  // Optionally populate fields with address data using JS
}
function saveAddress() {
  document.getElementById('editAddressForm').classList.add('hidden');
  // Optionally refresh address list
}
let mapInstance = null;
let markerInstance = null;
function openMapModal() {
  document.getElementById('mapModal').classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
  setTimeout(() => {
    if (!mapInstance) {
      const lat = parseFloat(document.getElementById('latitude').value) || 13.9441;
      const lng = parseFloat(document.getElementById('longitude').value) || 120.7336;
      mapInstance = L.map('map').setView([lat, lng], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: 'Map data © <a href="https://openstreetmap.org">OpenStreetMap</a> contributors'
      }).addTo(mapInstance);
      markerInstance = L.marker([lat, lng], { draggable: true }).addTo(mapInstance);

      function updateAddressFields(lat, lng) {
        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;
        // Use reverse geocoding to fill address fields
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
          .then(response => response.json())
          .then(data => {
            document.getElementById('edit_street').value = data.address.road || data.address.house_number || '';
            document.getElementById('edit_barangay').value = data.address.suburb || data.address.village || data.address.hamlet || '';
            document.getElementById('edit_city').value = data.address.city || data.address.town || data.address.village || data.address.municipality || '';
            document.getElementById('edit_region').value = data.address.state || data.address.region || data.address.province || '';
            document.getElementById('edit_zip').value = data.address.postcode || '';
          });
      }

      markerInstance.on('dragend', function (e) {
        var latlng = markerInstance.getLatLng();
        updateAddressFields(latlng.lat, latlng.lng);
      });
      mapInstance.on('click', function (e) {
        markerInstance.setLatLng(e.latlng);
        updateAddressFields(e.latlng.lat, e.latlng.lng);
      });
    } else {
      // Reset view and marker position to current values
      const lat = parseFloat(document.getElementById('latitude').value) || 13.9441;
      const lng = parseFloat(document.getElementById('longitude').value) || 120.7336;
      mapInstance.setView([lat, lng], 15);
      markerInstance.setLatLng([lat, lng]);
      markerInstance.dragging.enable();
    }
    // Always fix map tile rendering after modal is shown
    if (mapInstance) {
      setTimeout(() => { mapInstance.invalidateSize(); }, 200);
    }
  }, 100);
}

function closeMapModal() {
  document.getElementById('mapModal').classList.add('hidden');
  document.body.classList.remove('overflow-hidden');
}
function saveNewAddress() {
  // Collect values
  var name = document.getElementById('new_name').value;
  var contact = document.getElementById('new_contact').value;
  var street = document.getElementById('new_street').value;
  var barangay = document.getElementById('new_barangay').value;
  var city = document.getElementById('new_city').value;
  var region = document.getElementById('new_region').value;
  var zip = document.getElementById('new_zip').value;
  var latitude = document.getElementById('new_latitude').value;
  var longitude = document.getElementById('new_longitude').value;

  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'checkout.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function() {
    if (xhr.status === 200) {
      if (xhr.responseText.trim() === 'success') {
        location.reload();
      } else if (xhr.responseText.indexOf('error:') === 0) {
        var errorMsg = xhr.responseText.replace('error:', '').replace('_', ' ');
        alert('Failed to save address: ' + errorMsg);
      } else {
        alert('Unexpected response: ' + xhr.responseText);
      }
    } else {
      alert('Failed to save address.');
    }
  };
  var params = 'ajax_add_address=1'
    + '&name=' + encodeURIComponent(name)
    + '&contact_number=' + encodeURIComponent(contact)
    + '&street=' + encodeURIComponent(street)
    + '&barangay=' + encodeURIComponent(barangay)
    + '&city=' + encodeURIComponent(city)
    + '&region=' + encodeURIComponent(region)
    + '&zip_code=' + encodeURIComponent(zip)
    + '&latitude=' + encodeURIComponent(latitude)
    + '&longitude=' + encodeURIComponent(longitude);
  xhr.send(params);
}

// Leaflet Map for Add Address Modal
let leafletMap, leafletMarker, leafletMapInitialized = false;
function initLeafletMap() {
  if (leafletMapInitialized) return;
  leafletMapInitialized = true;
  const defaultLat = 13.9441;
  const defaultLng = 120.7336;
  leafletMap = L.map('leafletMap').setView([defaultLat, defaultLng], 14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(leafletMap);
  leafletMarker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(leafletMap);
  leafletMarker.on('dragend', function () {
    const latlng = leafletMarker.getLatLng();
    updateLeafletAddressFields(latlng.lat, latlng.lng);
  });
  leafletMap.on('click', function (e) {
    leafletMarker.setLatLng(e.latlng);
    updateLeafletAddressFields(e.latlng.lat, e.latlng.lng);
  });
}
function updateLeafletAddressFields(lat, lng) {
  document.getElementById('new_latitude').value = lat;
  document.getElementById('new_longitude').value = lng;
  // Use reverse geocoding to fill address fields (distributor/signup.php logic)
  fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
    .then(response => response.json())
    .then(data => {
      document.getElementById('new_street').value = data.address.road || data.address.house_number || '';
      document.getElementById('new_barangay').value = data.address.suburb || data.address.village || data.address.hamlet || '';
      document.getElementById('new_city').value = data.address.city || data.address.town || data.address.village || data.address.municipality || '';
      document.getElementById('new_region').value = data.address.state || data.address.region || data.address.province || '';
      document.getElementById('new_zip').value = data.address.postcode || '';
    });
}
document.addEventListener('DOMContentLoaded', function() {
  if (document.getElementById('leafletMap')) {
    initLeafletMap();
  }
});
</script>
</body>
</html>

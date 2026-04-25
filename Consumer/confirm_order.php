<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

if (!isset($_SESSION['consumer_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}

$user_id = $_SESSION['consumer_id'];

// Fetch order details if order_id is provided
$order_details = null;
$order_items = [];
if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);
    
    // Fetch order with address and shop info
    $stmt = $conn->prepare("
        SELECT o.*, 
               a.name as address_name,
               a.contact_number,
               a.street,
               a.barangay,
               a.city,
               a.region,
               a.zip_code,
               s.name as shop_name
        FROM orders o
        LEFT JOIN address a ON o.address_id = a.address_id
        LEFT JOIN shop s ON o.shop_id = s.shop_id
        WHERE o.order_id = ? AND o.consumer_id = ?
    ");
    $stmt->execute([$order_id, $user_id]);
    $order_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order_details) {
        // Fetch order items with purchase type
        $stmt = $conn->prepare("
            SELECT oi.*, 
                   ct.type as container_type,
                   oi.purchase_type
            FROM order_items oi
            LEFT JOIN container c ON oi.container_id = c.container_id
            LEFT JOIN container_type ct ON c.container_type_id = ct.container_type_id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Initialize variables
$selected_ids = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
$selected_items = [];
$subtotal = 0;
$total = 0;

// Only process cart items if we're not viewing a completed order
if (!$order_details) {
    // Accept direct product info for Buy Now

    if (!empty($selected_ids)) {
        // Try to fetch from cart first
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $stmt = $conn->prepare("SELECT * FROM cart WHERE consumer_id = ? AND cart_id IN ($placeholders)");
        $stmt->execute(array_merge([$user_id], $selected_ids));
        $selected_items = $stmt->fetchAll();
        // If not found in cart, try to fetch direct product info
        if (count($selected_items) === 0 && isset($_POST['qty'], $_POST['container_id'])) {
            $container_id = intval($_POST['container_id']);
            $qty = intval($_POST['qty']);
            $option = isset($_POST['option']) ? $_POST['option'] : 'with-container';
            // Fetch container info
            $stmt = $conn->prepare("SELECT * FROM container WHERE container_id = ?");
            $stmt->execute([$container_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($container) {
                // Determine price
                $price = ($option === 'refill-only' && isset($container['price_refill']) && $container['price_refill'] > 0)
                  ? $container['price_refill']
                  : (isset($container['price_with_container']) && $container['price_with_container'] > 0 ? $container['price_with_container'] : 0);
                $selected_items[] = [
                    'product_name' => $container['type'],
                    'type' => $option,
                    'qty' => $qty,
                    'price' => $price
                ];
            }
        }
    }

    if (empty($selected_items)) {
        header('Location: cart.php');
        exit;
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($selected_items as $item) {
        $subtotal += $item['qty'] * $item['price'];
    }
    $total = $subtotal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order'])) {
    // Forward selected_items to checkout.php via POST
    ?>
    <form id="forwardForm" method="POST" action="checkout.php">
      <?php foreach ($selected_ids as $id): ?>
        <input type="hidden" name="selected_items[]" value="<?= $id ?>">
      <?php endforeach; ?>
    </form>
    <script>document.getElementById('forwardForm').submit();</script>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Confirm Order - Tuy PureFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 font-sans">
  <?php if ($order_details && !empty($order_items)): ?>
    <!-- Order Confirmation Modal -->
    <div id="orderConfirmationModal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm flex items-center justify-center z-50 p-4" style="display: flex;">
      <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg mx-auto overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Header with Success Icon -->
        <div class="bg-gradient-to-br from-cyan-500 via-blue-500 to-cyan-600 px-6 pt-8 pb-6 text-center">
          <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mb-4 mx-auto shadow-lg">
            <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
            </svg>
          </div>
          <h2 class="text-2xl font-bold text-white mb-2">Order Placed Successfully!</h2>
          <p class="text-sm text-cyan-50">
            Thank you for choosing Tuy PureFlow
          </p>
        </div>

        <!-- Scrollable Content -->
        <div class="overflow-y-auto flex-1 px-6 py-5">
          <!-- Shop Name (if available) -->
          <?php if (!empty($order_details['shop_name'])): ?>
          <div class="mb-4 pb-4 border-b border-gray-200">
            <div class="flex items-center gap-2 text-gray-600 mb-1">
              <i class="fas fa-store text-cyan-600"></i>
              <span class="text-xs font-medium uppercase tracking-wide">Shop</span>
            </div>
            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($order_details['shop_name']) ?></p>
          </div>
          <?php endif; ?>

          <!-- Order Information Card -->
          <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-4 mb-4 border border-gray-200">
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <div class="text-xs text-gray-500 mb-1 flex items-center gap-1">
                  <i class="fas fa-hashtag text-cyan-600"></i>
                  <span>Order Number</span>
                </div>
                <p class="font-bold text-gray-800">#<?= htmlspecialchars($order_details['order_id']) ?></p>
              </div>
              <div>
                <div class="text-xs text-gray-500 mb-1 flex items-center gap-1">
                  <i class="fas fa-calendar text-cyan-600"></i>
                  <span>Order Date</span>
                </div>
                <p class="font-bold text-gray-800"><?= date('M j, Y', strtotime($order_details['order_date'])) ?></p>
              </div>
              <div class="col-span-2 mt-2 pt-2 border-t border-gray-300">
                <div class="text-xs text-gray-500 mb-1 flex items-center gap-1">
                  <i class="fas fa-credit-card text-cyan-600"></i>
                  <span>Payment Method</span>
                </div>
                <p class="font-bold text-gray-800">Cash on Delivery</p>
              </div>
            </div>
          </div>

          <!-- Items Ordered Section -->
          <div class="mb-4">
            <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-6 bg-gradient-to-b from-cyan-500 to-blue-500 rounded-full"></div>
              <h3 class="text-lg font-bold text-gray-800">Items Ordered</h3>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
              <?php foreach ($order_items as $index => $item): ?>
                <div class="px-4 py-3 <?= $index < count($order_items) - 1 ? 'border-b border-gray-100' : '' ?>">
                  <div class="flex justify-between items-start">
                    <div class="flex-1">
                      <p class="font-semibold text-gray-800 mb-1"><?= htmlspecialchars($item['container_type'] ?? 'Item') ?></p>
                      <?php if (!empty($item['purchase_type'])): ?>
                        <span class="inline-flex items-center px-2 py-0.5 bg-cyan-100 text-cyan-700 rounded-full text-xs font-medium">
                          <?= htmlspecialchars(ucfirst(str_replace('-', ' ', $item['purchase_type']))) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="text-right ml-4">
                      <p class="text-sm text-gray-600 mb-1">₱<?= number_format($item['price'], 2) ?> × <?= $item['quantity'] ?></p>
                      <p class="font-bold text-gray-800">₱<?= number_format($item['price'] * $item['quantity'], 2) ?></p>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <div class="px-4 py-4 bg-gradient-to-r from-cyan-50 to-blue-50 border-t-2 border-cyan-200">
                <div class="flex justify-between items-center">
                  <span class="text-base font-bold text-gray-700">Total Amount</span>
                  <span class="text-2xl font-bold text-gradient bg-gradient-to-r from-cyan-600 to-blue-600 bg-clip-text text-transparent">₱<?= number_format($order_details['total_amount'], 2) ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Shipping Address -->
          <div class="mb-4">
            <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-6 bg-gradient-to-b from-cyan-500 to-blue-500 rounded-full"></div>
              <h3 class="text-lg font-bold text-gray-800">Delivery Address</h3>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
              <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-cyan-100 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-map-marker-alt text-cyan-600"></i>
                </div>
                <div class="flex-1">
                  <p class="text-sm text-gray-800 leading-relaxed">
                    <?php
                      $address_parts = array_filter([
                        $order_details['street'] ?? '',
                        $order_details['barangay'] ?? '',
                        $order_details['city'] ?? '',
                        $order_details['region'] ?? '',
                        $order_details['zip_code'] ?? ''
                      ]);
                      echo htmlspecialchars(implode(', ', $address_parts));
                    ?>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Shipping Details -->
          <div class="bg-blue-50 rounded-lg border border-blue-200 p-4">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-truck text-blue-600"></i>
              </div>
              <div class="flex-1">
                <p class="text-sm font-semibold text-gray-800 mb-1">PureFlow Delivery</p>
                <p class="text-xs text-gray-600">Estimated delivery within 24 hours</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="border-t border-gray-200 bg-gray-50 px-6 py-5 space-y-3">
          <a href="landing_page.php" class="block w-full bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white text-center py-3.5 rounded-xl font-semibold transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
            <i class="fas fa-shopping-bag"></i>
            Continue Shopping
          </a>
          <a href="my_purchases.php" class="block w-full bg-white border-2 border-cyan-500 text-cyan-600 text-center py-3.5 rounded-xl font-semibold hover:bg-cyan-50 transition-all flex items-center justify-center gap-2">
            <i class="fas fa-list"></i>
            View My Orders
          </a>
        </div>
      </div>
    </div>
  <?php else: ?>
  <header class="header-gradient sticky top-0 z-50">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <a href="landing_page.php" class="brand-link flex items-center gap-2 text-xl">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
        <span>Tuy PureFlow</span>
      </a>
      <div class="flex items-center gap-4">
        <?php if (isset($_SESSION['consumer_id'])): ?>
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
            <button class="ml-2 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium flex items-center gap-1 focus:outline-none">
              Account
              <span class="transform transition-transform group-hover:rotate-180">▼</span>
            </button>
            <div class="absolute right-0 mt-2 w-36 bg-white shadow-lg rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
              <a href="signup.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Sign Up</a>
              <a href="login.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Login</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">Confirm Your Order</h1>
    <div class="bg-white rounded-lg shadow p-6 mb-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b">
            <th class="text-left py-2">Product</th>
            <th class="text-center py-2">Type</th>
            <th class="text-center py-2">Qty</th>
            <th class="text-center py-2">Price</th>
            <th class="text-center py-2">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($selected_items as $item): ?>
            <tr class="border-b">
              <td class="py-3"><?= htmlspecialchars($item['product_name']) ?></td>
              <td class="text-center"><?= htmlspecialchars($item['type']) ?></td>
              <td class="text-center"><?= $item['qty'] ?></td>
              <td class="text-center">₱<?= number_format($item['price'], 2) ?></td>
              <td class="text-center">₱<?= number_format($item['qty'] * $item['price'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Summary and Confirm -->
    <div class="w-full max-w-md mx-auto bg-gray-50 p-4 rounded-lg border">
      <h2 class="text-lg font-semibold mb-3">Order Summary</h2>
      <div class="flex justify-between text-sm mb-2">
        <span>Subtotal</span>
        <span>₱<?= number_format($subtotal, 2) ?></span>
      </div>
      <div class="flex justify-between text-base font-bold border-t pt-2">
        <span>Total</span>
        <span>₱<?= number_format($total, 2) ?></span>
      </div>

      <form action="confirm_order.php" method="post" class="mt-4">
        <?php if (!empty($selected_ids)) {
          foreach ($selected_ids as $id): ?>
            <input type="hidden" name="selected_items[]" value="<?= $id ?>">
          <?php endforeach;
        } else if (isset($_POST['container_id'], $_POST['qty'], $_POST['option'])) { ?>
            <input type="hidden" name="container_id" value="<?= htmlspecialchars($_POST['container_id']) ?>">
            <input type="hidden" name="qty" value="<?= htmlspecialchars($_POST['qty']) ?>">
            <input type="hidden" name="option" value="<?= htmlspecialchars($_POST['option']) ?>">
        <?php } ?>
        <button type="submit" name="confirm_order" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg">Confirm</button>
      </form>
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
  <?php endif; ?>
</body>
</html>

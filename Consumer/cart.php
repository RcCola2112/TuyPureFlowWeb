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
$consumer_id = $_SESSION['consumer_id'];

// Handle add to cart or buy now
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_to_cart']) || isset($_POST['buy_now']))) {
  $container_id = intval($_POST['container_id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $price = floatval($_POST['price'] ?? 0);
  $qty = intval($_POST['qty'] ?? 1);
  $option = $_POST['option'] ?? '';
  $shop_id = intval($_POST['shop_id'] ?? 0);

  // Check if the item already exists in the cart
  $check_stmt = $conn->prepare("SELECT cart_id, qty FROM cart WHERE consumer_id = ? AND shop_id = ? AND container_id = ? AND type = ?");
  $check_stmt->execute([$consumer_id, $shop_id, $container_id, $option]);
  $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
  if ($existing) {
    // Update quantity
    $new_qty = $existing['qty'] + $qty;
    $update_stmt = $conn->prepare("UPDATE cart SET qty = ? WHERE cart_id = ?");
    $update_stmt->execute([$new_qty, $existing['cart_id']]);
    $new_cart_id = $existing['cart_id'];
  } else {
    // Insert new item
    $stmt = $conn->prepare("INSERT INTO cart (consumer_id, shop_id, container_id, product_name, price, qty, type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$consumer_id, $shop_id, $container_id, $name, $price, $qty, $option]);
    $new_cart_id = $conn->lastInsertId();
  }

  if (isset($_POST['buy_now'])) {
    header('Location: cart.php?selected_cart_id=' . $new_cart_id);
    exit;
  } else {
    header('Location: cart.php');
    exit;
  }
}
// Handle remove single cart item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_cart_id'])) {
  $cart_id = intval($_POST['remove_cart_id']);
  $stmt = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND consumer_id = ?");
  $stmt->execute([$cart_id, $consumer_id]);
  header('Location: cart.php');
  exit;
}

// Add AJAX endpoint for removing cart item
if (isset($_POST['ajax_remove_cart_id']) && isset($_SESSION['consumer_id'])) {
  $cart_id = intval($_POST['ajax_remove_cart_id']);
  $stmt = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND consumer_id = ?");
  $stmt->execute([$cart_id, $_SESSION['consumer_id']]);
  echo json_encode(['success' => true]);
  exit;
}

// Handle delete selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
  $selected = $_POST['selected_items'] ?? [];
  if (!empty($selected)) {
    $in = str_repeat('?,', count($selected) - 1) . '?';
    $stmt = $conn->prepare("DELETE FROM cart WHERE cart_id IN ($in) AND consumer_id = ?");
    $stmt->execute(array_merge($selected, [$consumer_id]));
  }
  header('Location: cart.php');
  exit;
}

// Fetch cart items grouped by shop with container images
$stmt = $conn->prepare(
  "SELECT c.*, s.name AS shop_name, s.shop_id, cont.container_image, cont.container_id
   FROM cart c
   LEFT JOIN shop s ON c.shop_id = s.shop_id
   LEFT JOIN container cont ON c.container_id = cont.container_id
   WHERE c.consumer_id = ?
   ORDER BY s.shop_id, c.cart_id"
);
$stmt->execute([$consumer_id]);
$cart_items = $stmt->fetchAll();

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

// Group items by shop_id
$grouped_cart = [];
foreach ($cart_items as $item) {
  $shop_id = isset($item['shop_id']) ? $item['shop_id'] : 0;
  $shop_name = !empty($item['shop_name']) ? $item['shop_name'] : 'No Shop';
  $grouped_cart[$shop_id]['shop_name'] = $shop_name;
  $grouped_cart[$shop_id]['items'][] = $item;
}

// Cart count endpoint for AJAX
if (isset($_GET['action']) && $_GET['action'] === 'cart_count' && isset($_SESSION['consumer_id'])) {
  $stmt = $conn->prepare("SELECT SUM(qty) FROM cart WHERE consumer_id = ?");
  $stmt->execute([$_SESSION['consumer_id']]);
  $count = $stmt->fetchColumn();
  header('Content-Type: application/json');
  echo json_encode(['count' => intval($count)]);
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Cart - Tuy PureFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 font-sans">
  <!-- Header -->
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

  <!-- Cart Content -->
  <main class="container mx-auto px-4 py-6">
    <div class="mb-6">
      <h1 class="text-2xl md:text-3xl font-bold mb-2 text-gradient">My Cart</h1>
      <p class="text-gray-600 text-sm">Review your items before checkout</p>
    </div>

    <?php
      $auto_selected_cart_id = isset($_GET['selected_cart_id']) ? intval($_GET['selected_cart_id']) : 0;
      if (empty($grouped_cart)):
    ?>
      <div class="card p-8 text-center">
        <div class="mx-auto w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4">
          <i class="fas fa-shopping-cart text-3xl text-gray-400"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">Your cart is empty</h3>
        <p class="text-gray-500 mb-4">Start adding items to your cart to see them here.</p>
        <a href="landing_page.php" class="inline-flex items-center gap-2 btn-primary px-6 py-3">
          <i class="fas fa-arrow-left"></i>
          Continue Shopping
        </a>
      </div>
    <?php else: ?>
    <form method="POST" action="checkout.php" id="cartForm">
      <?php foreach ($grouped_cart as $shop_id => $shop): ?>
      <div class="card p-4 mb-4">
        <div class="flex items-center gap-2 mb-4 pb-3 border-b">
          <i class="fas fa-store text-cyan-600"></i>
          <h2 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($shop['shop_name']) ?></h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm border-collapse">
            <thead>
              <tr class="border-b bg-gray-50">
                <th class="py-2 px-2 text-center w-10">
                  <input type="checkbox" id="selectAll-<?= $shop_id ?>" class="select-all-shop" data-shop="<?= $shop_id ?>" />
                </th>
                <th class="text-left py-2 px-3">Product</th>
                <th class="text-center py-2 px-2">Type</th>
                <th class="text-center py-2 px-2">Qty</th>
                <th class="text-center py-2 px-2">Price</th>
                <th class="text-center py-2 px-2">Total</th>
                <th class="text-center py-2 px-2">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($shop['items'] as $item): ?>
              <tr class="border-b hover:bg-gray-50 transition-colors">
                <td class="text-center py-3 px-2 align-middle">
                  <input type="checkbox" name="selected_items[]" value="<?= $item['cart_id'] ?>" class="item-checkbox shop-checkbox-<?= $shop_id ?>" data-shop="<?= $shop_id ?>" <?php if ($auto_selected_cart_id === intval($item['cart_id'])) echo 'checked'; ?> />
                </td>
                <td class="py-3 px-3 align-middle">
                  <div class="flex items-center gap-2">
                    <img src="<?= htmlspecialchars($item['container_image'] ?? '../images/watercontainer.png') ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="w-12 h-12 object-contain rounded border border-gray-200 bg-gray-50 flex-shrink-0">
                    <span class="font-medium text-gray-800"><?= htmlspecialchars($item['product_name']) ?></span>
                  </div>
                </td>
                <td class="text-center py-3 px-2 align-middle">
                  <span class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                    <?= htmlspecialchars($item['type']) ?>
                  </span>
                </td>
                <td class="text-center py-3 px-2 align-middle">
                  <input type="number" 
                         name="qty[<?= $item['cart_id'] ?>]" 
                         value="<?= $item['qty'] ?>" 
                         min="1" 
                         class="w-14 text-center border border-gray-300 rounded px-1 py-1 text-xs focus:border-cyan-500 focus:outline-none transition-all cart-qty-input"
                         data-cart-id="<?= $item['cart_id'] ?>"
                         data-price="<?= $item['price'] ?>"
                         onchange="updateCartItemTotal(<?= $item['cart_id'] ?>, this)"
                         oninput="updateCartItemTotal(<?= $item['cart_id'] ?>, this)">
                </td>
                <td class="text-center py-3 px-2 align-middle font-medium text-gray-700">₱<?= number_format($item['price'], 2) ?></td>
                <td class="text-center py-3 px-2 align-middle font-bold text-gray-800 item-row-total" data-cart-id="<?= $item['cart_id'] ?>">₱<?= number_format($item['qty'] * $item['price'], 2) ?></td>
                <td class="text-center py-3 px-2 align-middle">
                  <button type="button" class="text-red-500 hover:text-red-700 hover:underline text-xs font-medium remove-item-btn transition-colors" data-cart-id="<?= $item['cart_id'] ?>" onclick="removeCartItemAjax(<?= $item['cart_id'] ?>, this)">
                    Remove
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>

        <!-- Summary Section -->
        <div class="mt-4 flex justify-end hidden" id="summarySection">
          <div class="w-full max-w-sm bg-white p-5 rounded-xl shadow-lg border border-gray-200">
            <h2 class="text-lg font-bold mb-4 text-gray-800 flex items-center gap-2">
              <i class="fas fa-receipt text-cyan-600"></i>
              Order Summary
            </h2>
            <div class="space-y-2 mb-4 pb-4 border-b">
              <div class="flex justify-between text-sm">
                <span class="text-gray-600">Subtotal</span>
                <span id="subtotal" class="font-semibold text-gray-800">₱0.00</span>
              </div>
            </div>
            <div class="space-y-2">
              <button type="submit" class="w-full btn-primary text-white py-3 rounded-lg font-semibold flex items-center justify-center gap-2"
                onclick="return !window.deleteClicked;">
                <i class="fas fa-arrow-right"></i>
                Proceed to Checkout
              </button>
              <button type="submit" name="delete_selected" formaction="cart.php" class="w-full bg-red-500 hover:bg-red-600 text-white py-2.5 rounded-lg font-medium transition-all flex items-center justify-center gap-2"
                onclick="return confirm('Are you sure you want to delete these items from your cart?');">
                <i class="fas fa-trash-alt"></i>
                Delete Selected
              </button>
            </div>
          </div>
        </div>
    </form>
    <?php endif; ?>
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
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const summary = document.getElementById('summarySection');
    const subtotalEl = document.getElementById('subtotal');
    const totalEl = document.getElementById('total');
    // Update cartData for JS summary calculations
    const cartData = <?= json_encode($cart_items) ?>;

    function updateSummary() {
      let subtotal = 0;
      let selected = 0;
      checkboxes.forEach((box, index) => {
        if (box.checked) {
          const id = box.value;
          const qtyInput = document.querySelector(`input[name="qty[${id}]"]`);
          const qty = parseInt(qtyInput.value) || 0;
          const item = cartData.find(i => i.cart_id == id);
          if (item) {
            subtotal += item.price * qty;
            selected++;
          }
        }
      });
      if (selected > 0) {
        summary.classList.remove('hidden');
        subtotalEl.innerText = `₱${subtotal.toFixed(2)}`;
        if (totalEl) {
          totalEl.innerText = `₱${(subtotal + 15).toFixed(2)}`;
        }
      } else {
        summary.classList.add('hidden');
      }
    }
    
    // Update individual item total in cart
    function updateCartItemTotal(cartId, input) {
      const qty = parseInt(input.value) || 1;
      if (qty < 1) {
        input.value = 1;
        return;
      }
      const price = parseFloat(input.getAttribute('data-price')) || 0;
      const itemTotal = qty * price;
      const totalEl = document.querySelector(`.item-row-total[data-cart-id="${cartId}"]`);
      if (totalEl) {
        totalEl.textContent = '₱' + itemTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      }
      // Also update summary
      updateSummary();
    }

    function updateSelectAllCheckboxes() {
      document.querySelectorAll('.select-all-shop').forEach(selectAllShop => {
        const shopId = selectAllShop.getAttribute('data-shop');
        const shopCheckboxes = document.querySelectorAll('.shop-checkbox-' + shopId);
        const allChecked = shopCheckboxes.length > 0 && Array.from(shopCheckboxes).every(cb => cb.checked);
        const anyChecked = shopCheckboxes.length > 0 && Array.from(shopCheckboxes).some(cb => cb.checked);
        selectAllShop.checked = allChecked;
        selectAllShop.indeterminate = !allChecked && anyChecked;
      });
    }

    function setupCheckboxSync() {
      checkboxes.forEach(box => {
        box.addEventListener('change', function() {
          updateSummary();
          updateSelectAllCheckboxes();
        });
      });
      document.querySelectorAll('.select-all-shop').forEach(selectAllShop => {
        selectAllShop.addEventListener('change', function() {
          const shopId = this.getAttribute('data-shop');
          const shopCheckboxes = document.querySelectorAll('.shop-checkbox-' + shopId);
          shopCheckboxes.forEach(cb => {
            cb.checked = this.checked;
          });
          updateSummary();
          updateSelectAllCheckboxes();
        });
      });
      // Initial sync
      updateSelectAllCheckboxes();
    }

    // Use MutationObserver to catch DOM changes and always sync select all state
    const cartTable = document.querySelector('table');
    if (cartTable) {
      const observer = new MutationObserver(() => {
        updateSelectAllCheckboxes();
      });
      observer.observe(cartTable, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setupCheckboxSync);
    } else {
      setupCheckboxSync();
    }

    document.querySelectorAll('input[type="number"]').forEach(input => {
      input.addEventListener('input', updateSummary);
    });

    // Shop-specific select all logic
    document.querySelectorAll('.select-all-shop').forEach(selectAll => {
      selectAll.addEventListener('change', function () {
        const shopId = this.getAttribute('data-shop');
        document.querySelectorAll('.shop-checkbox-' + shopId).forEach(cb => {
          cb.checked = this.checked;
        });
        updateSummary();
      });
    });

    // AJAX removal of cart item
    document.querySelectorAll('.remove-item-btn').forEach(button => {
      button.addEventListener('click', function(e) {
        e.preventDefault();
        const cartId = this.getAttribute('data-cart-id');
        if (confirm('Are you sure you want to remove this item from your cart?')) {
          fetch('cart.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax_remove_cart_id=${cartId}`
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              // Remove the item row from the UI
              const form = this.closest('.remove-item-form');
              if (form) {
                form.closest('tr').remove();
              }
              updateSummary();
            } else {
              alert('Failed to remove item. Please try again.');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while removing the item. Please try again.');
          });
        }
      });
    });
    function removeCartItemAjax(cartId, btn) {
      if (!confirm('Are you sure you want to remove this item from your cart?')) return;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'cart.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        if (xhr.status === 200) {
          // Remove the row from the table
          var row = btn.closest('tr');
          if (row) row.remove();
          // Optionally update cart badge
          fetch('cart.php?action=cart_count')
            .then(response => response.json())
            .then(data => {
              var badge = document.querySelector('.cart-badge');
              if (badge) {
                badge.textContent = data.count > 0 ? data.count : '';
                badge.style.display = data.count > 0 ? 'flex' : 'none';
              }
            });
          updateSummary();
        } else {
          alert('Failed to remove item. Please try again.');
        }
      };
      xhr.send('ajax_remove_cart_id=' + encodeURIComponent(cartId));
    }
  </script>
</body>
</html>

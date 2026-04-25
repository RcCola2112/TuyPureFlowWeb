<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// my_purchases.php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

if (!isset($_SESSION['consumer_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}

$consumer_id = $_SESSION['consumer_id'];

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['shop_id'], $_POST['rating'])) {
  $order_id = $_POST['order_id'];
  $shop_id = $_POST['shop_id'];
  $rating = $_POST['rating'];
  $comment = isset($_POST['comment']) && $_POST['comment'] !== '' ? $_POST['comment'] : null;
  $photo = null;
  if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $photo = file_get_contents($_FILES['photo']['tmp_name']);
  }
  $stmt = $conn->prepare("INSERT INTO shop_ratings (consumer_id, shop_id, order_id, rating, comment, photo, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
  $stmt->execute([$consumer_id, $shop_id, $order_id, $rating, $comment, $photo]);
  header("Location: my_purchases.php?status=" . ($_GET['status'] ?? 'All'));
  exit;
}

// Get status filter from URL, default to 'All'
// Pagination setup for all tabs
$status = isset($_GET['status']) ? $_GET['status'] : 'All';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$total = 0;
if ($status === 'All') {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE consumer_id = ?");
  $count_stmt->execute([$consumer_id]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT * FROM orders WHERE consumer_id = ? ORDER BY order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id]);
} elseif ($status === 'Scheduled Delivery') {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE consumer_id = ? AND is_scheduled = 1");
  $count_stmt->execute([$consumer_id]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT * FROM orders WHERE consumer_id = ? AND is_scheduled = 1 ORDER BY scheduled_date DESC, order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id]);
} elseif ($status === 'To Rate') {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN shop_ratings r ON o.order_id = r.order_id AND r.consumer_id = ? WHERE o.consumer_id = ? AND o.status = 'Completed' AND r.rating_id IS NULL");
  $count_stmt->execute([$consumer_id, $consumer_id]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT o.* FROM orders o LEFT JOIN shop_ratings r ON o.order_id = r.order_id AND r.consumer_id = ? WHERE o.consumer_id = ? AND o.status = 'Completed' AND r.rating_id IS NULL ORDER BY o.order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id, $consumer_id]);
} elseif ($status === 'Rated') {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders o INNER JOIN shop_ratings r ON o.order_id = r.order_id AND r.consumer_id = ? WHERE o.consumer_id = ? AND o.status = 'Completed'");
  $count_stmt->execute([$consumer_id, $consumer_id]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT o.*, r.rating, r.comment, r.photo FROM orders o INNER JOIN shop_ratings r ON o.order_id = r.order_id AND r.consumer_id = ? WHERE o.consumer_id = ? AND o.status = 'Completed' ORDER BY o.order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id, $consumer_id]);
} elseif ($status === 'Unpaid') {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders o INNER JOIN delivery_record d ON o.order_id = d.order_id WHERE o.consumer_id = ? AND d.payment_status = 'unpaid'");
  $count_stmt->execute([$consumer_id]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT o.*, d.payment_status FROM orders o INNER JOIN delivery_record d ON o.order_id = d.order_id WHERE o.consumer_id = ? AND d.payment_status = 'unpaid' ORDER BY o.order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id]);
} else {
  $count_stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE consumer_id = ? AND status = ?");
  $count_stmt->execute([$consumer_id, $status]);
  $total = $count_stmt->fetchColumn();
  $stmt = $conn->prepare("SELECT * FROM orders WHERE consumer_id = ? AND status = ? ORDER BY order_date DESC LIMIT $perPage OFFSET $offset");
  $stmt->execute([$consumer_id, $status]);
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX endpoint for cancelling order
if (isset($_POST['ajax_cancel_order_id'])) {
  $cancel_order_id = intval($_POST['ajax_cancel_order_id']);
  $check_stmt = $conn->prepare("SELECT status FROM orders WHERE order_id = ? AND consumer_id = ?");
  $check_stmt->execute([$cancel_order_id, $consumer_id]);
  $order = $check_stmt->fetch(PDO::FETCH_ASSOC);
  if ($order && $order['status'] === 'Pending') {
    $cancel_stmt = $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE order_id = ?");
    $cancel_stmt->execute([$cancel_order_id]);
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Purchases - Tuy PureFlow</title>
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
        <?php include 'notification_icon.php'; ?>
        <?php
          // Fetch consumer profile picture
          $consumer_profile = null;
          $profile_stmt = $conn->prepare("SELECT profile_pic FROM consumer WHERE consumer_id = ? LIMIT 1");
          $profile_stmt->execute([$consumer_id]);
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
      </div>
    </div>
  </header>
  <main class="container mx-auto px-4 py-8">
    <div class="mb-6">
      <h1 class="text-3xl font-bold mb-2 text-gradient">My Purchases</h1>
      <p class="text-gray-600">View and manage your order history</p>
    </div>
    <div class="grid md:grid-cols-4 gap-6">
      <!-- Sidebar -->
      <aside class="card md:col-span-1">
        <nav class="space-y-2">
          <a href="account.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
            <i class="fas fa-user-circle mr-2"></i>Account Info
          </a>
          <a href="my_purchases.php" class="block px-4 py-3 rounded-lg bg-gradient-to-r from-cyan-400 to-blue-600 text-white font-semibold">
            <i class="fas fa-shopping-bag mr-2"></i>My Purchases
          </a>
          <a href="notification.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
            <i class="fas fa-bell mr-2"></i>Notifications
          </a>
          <a href="logout.php" class="block px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 transition-colors">
            <i class="fas fa-sign-out-alt mr-2"></i>Logout
          </a>
        </nav>
      </aside>
      <!-- Content -->
      <section class="md:col-span-3">
        <!-- Filter Tabs -->
        <div class="mb-6 flex flex-wrap gap-2 bg-white p-4 rounded-xl shadow-lg border border-gray-100">
          <a href="my_purchases.php?status=All" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'All' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-list mr-1"></i>All
          </a>
          <a href="my_purchases.php?status=Pending" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Pending' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-clock mr-1"></i>Pending
          </a>
          <a href="my_purchases.php?status=Processing" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Processing' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-cog mr-1"></i>Processing
          </a>
          <a href="my_purchases.php?status=Out for Delivery" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Out for Delivery' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-truck mr-1"></i>Out for Delivery
          </a>
          <a href="my_purchases.php?status=Completed" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Completed' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-check-circle mr-1"></i>Completed
          </a>
          <a href="my_purchases.php?status=Unpaid" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Unpaid' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-money-bill-wave mr-1"></i>Unpaid
          </a>
          <a href="my_purchases.php?status=Cancelled" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Cancelled' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-times-circle mr-1"></i>Cancelled
          </a>
          <a href="my_purchases.php?status=Scheduled Delivery" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Scheduled Delivery' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-calendar-alt mr-1"></i>Scheduled
          </a>
          <a href="my_purchases.php?status=To Rate" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'To Rate' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-star mr-1"></i>To Rate
          </a>
          <a href="my_purchases.php?status=Rated" class="px-4 py-2 rounded-lg text-sm font-medium transition-all <?= $status === 'Rated' ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-star-half-alt mr-1"></i>Rated
          </a>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-100 overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead class="bg-gradient-to-r from-cyan-500 to-blue-500 text-white">
              <tr>
                <th class="py-3 px-4 text-left font-semibold">Shop</th>
                <th class="py-3 px-4 text-left font-semibold">Items/Containers</th>
                <th class="py-3 px-4 text-left font-semibold">Delivery Address</th>
                <th class="py-3 px-4 text-right font-semibold">Total</th>
                <th class="py-3 px-4 text-center font-semibold">Status</th>
                <?php if ($status === 'Pending'): ?>
                  <th class="py-3 px-4 text-center font-semibold">Actions</th>
                <?php endif; ?>
                <th class="py-3 px-4 text-left font-semibold">Date</th>
                <?php if ($status === 'To Rate' || $status === 'Rated'): ?>
                  <th class="py-3 px-4 text-center font-semibold">Rating</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (count($orders) === 0): ?>
                <tr>
                  <td colspan="<?= ($status === 'To Rate' || $status === 'Rated') ? 7 : 6 ?>" class="py-12 text-center">
                    <div class="flex flex-col items-center justify-center">
                      <i class="fas fa-shopping-bag text-5xl text-gray-300 mb-4"></i>
                      <p class="text-gray-500 font-medium text-lg">No orders found</p>
                      <p class="text-gray-400 text-sm mt-2">Your orders will appear here</p>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($orders as $order): ?>
                  <?php
                    // Fetch shop name
                    $shop_name = '';
                    if (!empty($order['shop_id'])) {
                      $shop_stmt = $conn->prepare("SELECT name FROM shop WHERE shop_id = ?");
                      $shop_stmt->execute([$order['shop_id']]);
                      $shop_name = $shop_stmt->fetchColumn();
                    }

                    // Fetch order items/containers
                    $items_html = '';
                    $item_stmt = $conn->prepare("SELECT ct.type, c.price_with_container, c.price_refill, oi.quantity, oi.price FROM order_items oi JOIN container c ON oi.container_id = c.container_id JOIN container_type ct ON c.container_type_id = ct.container_type_id WHERE oi.order_id = ?");
                    $item_stmt->execute([$order['order_id']]);
                    $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
                    if ($items) {
                      foreach ($items as $item) {
                        $container_type = htmlspecialchars($item['type']);
                        $quantity = intval($item['quantity']);
                        $price = number_format((float)$item['price'], 2);
                        $items_html .= '<div class="mb-1">';
                        $items_html .= '<div class="font-medium text-gray-900">' . $container_type . ' x ' . $quantity . '</div>';
                        $items_html .= '<div class="text-xs text-gray-600">(₱' . $price . ')</div>';
                        $items_html .= '</div>';
                      }
                    } else {
                      $items_html = '<span class="text-gray-500">N/A</span>';
                    }

                    // Fetch delivery address
                    $address_text = '';
                    $addr_stmt = $conn->prepare("SELECT street, barangay, city, region, zip_code FROM address WHERE address_id = ?");
                    $addr_stmt->execute([$order['address_id'] ?? 0]);
                    $addr = $addr_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($addr) {
                        $address_text = htmlspecialchars($addr['street']) . ', ' . htmlspecialchars($addr['barangay']) . ', ' . htmlspecialchars($addr['city']) . ', ' . htmlspecialchars($addr['region']) . ' ' . htmlspecialchars($addr['zip_code']);
                    } else {
                      $address_text = 'N/A';
                    }
                  ?>
                  <tr>
                    <td class="py-4 px-4 border-b border-gray-200">
                      <div class="flex items-center gap-2">
                        <i class="fas fa-store text-cyan-600"></i>
                        <span class="font-medium text-gray-800"><?= htmlspecialchars($shop_name) ?></span>
                      </div>
                    </td>
                    <td class="py-4 px-4 border-b border-gray-200">
                      <?php if (!empty($items_html)): ?>
                        <?= $items_html ?>
                      <?php else: ?>
                        <span class="text-gray-500">No items</span>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 px-4 border-b border-gray-200">
                      <div class="flex items-start gap-2">
                        <i class="fas fa-map-marker-alt text-cyan-600 mt-1"></i>
                        <span class="text-sm text-gray-700"><?= $address_text ?></span>
                      </div>
                    </td>
                    <td class="py-4 px-4 border-b border-gray-200 text-right">
                      <span class="font-bold text-gray-800">₱<?= number_format($order['total_amount'], 2) ?></span>
                    </td>
                    <td class="py-4 px-4 border-b border-gray-200 text-center">
                      <?php 
                        $status_text = $status === 'Unpaid' ? $order['payment_status'] : $order['status'];
                        $status_colors = [
                          'Pending' => 'bg-yellow-100 text-yellow-800',
                          'Processing' => 'bg-blue-100 text-blue-800',
                          'Out for Delivery' => 'bg-purple-100 text-purple-800',
                          'Completed' => 'bg-green-100 text-green-800',
                          'Cancelled' => 'bg-red-100 text-red-800',
                          'Unpaid' => 'bg-orange-100 text-orange-800'
                        ];
                        $status_class = $status_colors[$status_text] ?? 'bg-gray-100 text-gray-800';
                      ?>
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $status_class ?>">
                        <?= htmlspecialchars($status_text) ?>
                      </span>
                    </td>
                    <?php if ($status === 'Pending'): ?>
                      <td class="py-4 px-4 border-b border-gray-200 text-center">
                        <?php if ($order['status'] === 'Pending'): ?>
                          <button type="button" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-sm hover:shadow cancel-btn" data-order-id="<?= $order['order_id'] ?>">
                            <i class="fas fa-times mr-1"></i>Cancel
                          </button>
                        <?php else: ?>
                          <span class="text-gray-400">-</span>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
                    <td class="py-4 px-4 border-b border-gray-200">
                      <div class="space-y-1">
                        <!-- Order Date -->
                        <div class="flex items-center gap-2 text-sm">
                          <i class="fas fa-calendar text-cyan-600 text-xs"></i>
                          <span class="font-medium text-gray-900">
                            <?= isset($order['order_date']) ? date('M j, Y', strtotime($order['order_date'])) : '-' ?>
                          </span>
                        </div>
                        
                        <!-- Scheduled Delivery Info (if scheduled) -->
                        <?php if (!empty($order['is_scheduled']) && $order['is_scheduled'] == 1): ?>
                          <?php if (!empty($order['scheduled_date'])): ?>
                            <div class="flex items-center gap-2 text-xs text-blue-700 mt-1">
                              <i class="fas fa-clock text-blue-500"></i>
                              <span>
                                Scheduled: <?= date('M j, Y', strtotime($order['scheduled_date'])) ?>
                                <?php if (!empty($order['scheduled_time'])): ?>
                                  at <?= date('g:i A', strtotime($order['scheduled_time'])) ?>
                                <?php endif; ?>
                              </span>
                            </div>
                          <?php endif; ?>
                          
                          <!-- Recurrence Info -->
                          <?php if (!empty($order['recurrence_type']) && $order['recurrence_type'] !== 'none'): ?>
                            <div class="flex items-center gap-2 text-xs text-purple-700 mt-1">
                              <i class="fas fa-sync-alt text-purple-500"></i>
                              <span class="font-medium"><?= ucfirst($order['recurrence_type']) ?></span>
                              <?php if (!empty($order['next_scheduled_date'])): ?>
                                <span class="text-gray-600">→ <?= date('M j, Y', strtotime($order['next_scheduled_date'])) ?></span>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <?php if ($status === 'To Rate'): ?>
                      <td class="py-4 px-4 border-b border-gray-200">
                        <form method="post" enctype="multipart/form-data" class="flex flex-col gap-3 rating-form bg-gray-50 p-4 rounded-lg border border-gray-200">
                          <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                          <input type="hidden" name="shop_id" value="<?= $order['shop_id'] ?>">
                          <input type="hidden" name="rating" value="1" class="rating-value">
                          <div class="flex gap-1 items-center rating-stars justify-center">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                              <svg data-star="<?= $i ?>" xmlns="http://www.w3.org/2000/svg" fill="<?= $i === 1 ? 'currentColor' : 'none' ?>" viewBox="0 0 24 24" stroke="currentColor" class="w-7 h-7 cursor-pointer star-svg transition-transform hover:scale-110" style="color: #fbbf24;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
                              </svg>
                            <?php endfor; ?>
                          </div>
                          <textarea name="comment" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" rows="2" placeholder="Add a comment (optional)"></textarea>
                          <label class="text-xs text-gray-600 flex items-center gap-2">
                            <i class="fas fa-image text-cyan-600"></i>
                            <span>Upload photo (optional)</span>
                          </label>
                          <input type="file" name="photo" accept="image/*" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all">
                          <button type="submit" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-all shadow-sm hover:shadow">
                            <i class="fas fa-paper-plane mr-1"></i>Submit Rating
                          </button>
                        </form>
                      </td>
                    <?php elseif ($status === 'Rated'): ?>
                      <td class="py-4 px-4 border-b border-gray-200">
                        <div class="bg-yellow-50 p-3 rounded-lg border border-yellow-200">
                          <div class="flex items-center gap-1 mb-2">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                              <i class="fas fa-star <?= $i <= $order['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                            <?php endfor; ?>
                          </div>
                          <?php if (!empty($order['comment'])): ?>
                            <div class="text-sm text-gray-700 mb-2">
                              <i class="fas fa-comment text-cyan-600 mr-1"></i>
                              <span class="font-medium">Comment:</span> <?= htmlspecialchars($order['comment']) ?>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($order['photo'])): ?>
                            <div class="mt-2">
                              <img src="data:image/jpeg;base64,<?= base64_encode($order['photo']) ?>" alt="Rating Photo" class="rounded-lg border border-gray-200 max-w-full h-32 object-cover">
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              <tr>
                <td colspan="<?= ($status === 'To Rate' || $status === 'Rated') ? 7 : 6 ?>" class="py-2 px-4 text-center">
                  <?php
                  $totalPages = !empty($total) ? ceil($total / $perPage) : 1;
                  if ($totalPages > 1) {
                    $prevPage = $page > 1 ? $page - 1 : 1;
                    $nextPage = $page < $totalPages ? $page + 1 : $totalPages;
                    $queryString = '?status=' . urlencode($status) . '&page=';
                    if ($page > 1) {
                      echo '<a href="' . $queryString . $prevPage . '" class="px-3 py-1 bg-gray-200 rounded mr-2">Previous</a>';
                    }
                    echo 'Page ' . $page . ' of ' . $totalPages;
                    if ($page < $totalPages) {
                      echo '<a href="' . $queryString . $nextPage . '" class="px-3 py-1 bg-gray-200 rounded ml-2">Next</a>';
                    }
                  }
                  ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
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
  // Interactive star rating logic
  document.querySelectorAll('.rating-form').forEach(function(form) {
    const stars = form.querySelectorAll('.star-svg');
    const ratingInput = form.querySelector('.rating-value');
    let currentRating = 1;
    function setStars(rating) {
      stars.forEach((star, idx) => {
        star.setAttribute('fill', idx < rating ? 'currentColor' : 'none');
      });
    }
    setStars(currentRating);
    stars.forEach((star, idx) => {
      star.addEventListener('mouseenter', function() {
        setStars(idx + 1);
      });
      star.addEventListener('mouseleave', function() {
        setStars(currentRating);
      });
      star.addEventListener('click', function() {
        currentRating = idx + 1;
        ratingInput.value = currentRating;
        setStars(currentRating);
      });
    });
  });

  // AJAX Cancel logic
  document.querySelectorAll('.cancel-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!confirm('Cancel this order?')) return;
      var orderId = btn.getAttribute('data-order-id');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'my_purchases.php?status=<?= addslashes($status) ?>');
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        if (xhr.status === 200) {
          var res = JSON.parse(xhr.responseText);
          if (res.success) {
            // Update status cell and remove cancel button
            var row = btn.closest('tr');
            var statusCell = row.querySelector('td:nth-child(6)');
            statusCell.textContent = 'Cancelled';
            btn.parentNode.innerHTML = '&nbsp;';
          } else {
            alert('Unable to cancel order.');
          }
        }
      };
      xhr.send('ajax_cancel_order_id=' + encodeURIComponent(orderId));
    });
  });
  </script>
</body>
</html>
  <?php
  // shop_page.php
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
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'not_logged_in']);
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
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    
    if (!$name) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'missing_name']);
      exit;
    }
    if (!$contact) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'missing_contact']);
      exit;
    }
    
    try {
      $stmt = $conn->prepare("INSERT INTO address (consumer_id, name, contact_number, street, barangay, city, region, zip_code, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$user_id, $name, $contact, $street, $barangay, $city, $region, $zip, $latitude, $longitude]);
      $new_address_id = $conn->lastInsertId();
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'address_id' => $new_address_id]);
      exit;
    } catch (PDOException $e) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'database_error', 'message' => $e->getMessage()]);
      exit;
    }
  }

  // Get shop_id from URL
  // Handle scheduled delivery submission
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivery_date']) && isset($_POST['container_id']) && isset($_POST['shop_id'])) {
    $consumer_id = $_SESSION['consumer_id'] ?? 0;
    $shop_id = intval($_POST['shop_id']);
    $container_id = intval($_POST['container_id']);
    $delivery_date = trim($_POST['delivery_date']);
    $delivery_time = !empty($_POST['delivery_time']) ? trim($_POST['delivery_time']) : null;
    $repeat_order = $_POST['repeat_order'] ?? 'one-time';
    $address_id = !empty($_POST['address_id']) ? intval($_POST['address_id']) : null;
    $is_scheduled = 1;
    $recurrence_type = $repeat_order === 'one-time' ? 'none' : $repeat_order;
    
    $validation_errors = [];
    
    // Validate consumer is logged in
    if (empty($consumer_id) || $consumer_id == 0) {
      $validation_errors[] = "You must be logged in to schedule a delivery.";
    }
    
    // Validate container_id
    if (empty($container_id) || $container_id <= 0) {
      $validation_errors[] = "Invalid product selected.";
    }
    
    // Validate shop_id
    if (empty($shop_id) || $shop_id <= 0) {
      $validation_errors[] = "Invalid shop selected.";
    }
    
    // Validate delivery_date is provided
    if (empty($delivery_date)) {
      $validation_errors[] = "Delivery date is required.";
    } else {
      // Validate date format
      $date_parts = explode('-', $delivery_date);
      if (count($date_parts) !== 3 || !checkdate(intval($date_parts[1]), intval($date_parts[2]), intval($date_parts[0]))) {
        $validation_errors[] = "Invalid delivery date format.";
      } else {
        // Validate date is not in the past
        $delivery_timestamp = strtotime($delivery_date);
        $today_timestamp = strtotime(date('Y-m-d'));
        
        if ($delivery_timestamp < $today_timestamp) {
          $validation_errors[] = "Delivery date cannot be in the past. Please select today or a future date.";
        }
        
        // Validate date is not too far in the future (e.g., max 1 year)
        $max_future_timestamp = strtotime('+1 year');
        if ($delivery_timestamp > $max_future_timestamp) {
          $validation_errors[] = "Delivery date cannot be more than 1 year in the future.";
        }
      }
    }
    
    // Validate delivery_time if provided
    if (!empty($delivery_time)) {
      // Validate time format (HH:MM)
      if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $delivery_time)) {
        $validation_errors[] = "Invalid time format. Please use 24-hour format (HH:MM).";
      } else {
        // If date is today, validate time is not in the past
        if ($delivery_date === date('Y-m-d')) {
          $current_time = date('H:i');
          if ($delivery_time < $current_time) {
            $validation_errors[] = "Delivery time cannot be in the past. Please select a future time.";
          }
        }
      }
    }
    
    // Validate address_id
    if (empty($address_id) || $address_id <= 0) {
      $validation_errors[] = "Please select a delivery address.";
    } else {
      // Verify address belongs to consumer
      $addr_check = $conn->prepare("SELECT address_id FROM address WHERE address_id = ? AND consumer_id = ?");
      $addr_check->execute([$address_id, $consumer_id]);
      if (!$addr_check->fetch()) {
        $validation_errors[] = "Invalid delivery address selected.";
      }
    }
    
    // Validate quantity
    $qty = intval($_POST['qty'] ?? 1);
    if ($qty <= 0 || $qty > 100) {
      $validation_errors[] = "Quantity must be between 1 and 100.";
    }
    
    // Validate option
    $option = $_POST['option'] ?? 'with-container';
    if (!in_array($option, ['with-container', 'refill-only'])) {
      $validation_errors[] = "Invalid purchase option selected.";
    }
    
    // Validate recurrence_type
    $valid_recurrence_types = ['none', 'weekly', 'bi-weekly', 'biweekly', 'monthly'];
    if (!in_array($recurrence_type, $valid_recurrence_types)) {
      $validation_errors[] = "Invalid recurrence type selected.";
    }
    
    // If there are validation errors, show them and stop
    if (!empty($validation_errors)) {
      $_SESSION['schedule_validation_errors'] = $validation_errors;
      $_SESSION['schedule_form_data'] = $_POST; // Preserve form data
      header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
      exit;
    }

    // Calculate total_amount (fetch price from container)
    $stmt = $conn->prepare("SELECT price_with_container, price_refill FROM container WHERE container_id = ?");
    $stmt->execute([$container_id]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$container) {
      $_SESSION['schedule_validation_errors'] = ["Product not found or no longer available."];
      header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
      exit;
    }
    
    $price = ($option === 'with-container') ? floatval($container['price_with_container']) : floatval($container['price_refill']);
    
    if ($price <= 0) {
      $_SESSION['schedule_validation_errors'] = ["Product price is invalid. Please contact the shop."];
      header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
      exit;
    }
    
    $total_amount = $price * $qty;

    // Calculate next_scheduled_date for recurring orders
    $next_scheduled_date = null;
    if ($recurrence_type === 'weekly' && $delivery_date) {
      $next_scheduled_date = date('Y-m-d', strtotime($delivery_date . ' +7 days'));
    } elseif (($recurrence_type === 'bi-weekly' || $recurrence_type === 'biweekly') && $delivery_date) {
      $next_scheduled_date = date('Y-m-d', strtotime($delivery_date . ' +14 days'));
    } elseif ($recurrence_type === 'monthly' && $delivery_date) {
      $next_scheduled_date = date('Y-m-d', strtotime($delivery_date . ' +1 month'));
    }

    try {
      $order_stmt = $conn->prepare("INSERT INTO `orders` (consumer_id, shop_id, address_id, rider_id, total_amount, status, order_date, is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id) VALUES (?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?, ?, ?, ?, ?)");
      $result = $order_stmt->execute([
        $consumer_id,
        $shop_id,
        $address_id,
        null, // rider_id (can be assigned later)
        $total_amount,
        $is_scheduled,
        $delivery_date,
        $delivery_time,
        $recurrence_type,
        $next_scheduled_date,
        null // parent_order_id
      ]);
      if (!$result) {
        $errorInfo = $order_stmt->errorInfo();
        $_SESSION['schedule_validation_errors'] = ["Failed to create scheduled order: " . ($errorInfo[2] ?? 'Unknown error')];
        header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
        exit;
        } else {
          // Insert into order_items table
          $order_id = $conn->lastInsertId();
          $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, container_id, quantity, price, purchase_type) VALUES (?, ?, ?, ?, ?)");
          $item_result = $item_stmt->execute([
            $order_id,
            $container_id,
            $qty,
            $price,
            $option
          ]);
          if (!$item_result) {
            $itemError = $item_stmt->errorInfo();
            $_SESSION['schedule_validation_errors'] = ["Failed to add order items: " . ($itemError[2] ?? 'Unknown error')];
            header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
            exit;
          } else {
            // Send notification to distributor about new scheduled delivery
            include_once '../includes/send_notification.php';
            
            // Get distributor_id from shop_id
            $distStmt = $conn->prepare("SELECT distributor_id FROM shop WHERE shop_id = ?");
            $distStmt->execute([$shop_id]);
            $distributor_id = $distStmt->fetchColumn();
            
            if ($distributor_id) {
              // Format delivery date and time for message
              $delivery_date_formatted = date('F j, Y', strtotime($delivery_date));
              $delivery_time_formatted = !empty($delivery_time) ? date('g:i A', strtotime($delivery_time)) : 'TBD';
              
              // Get container type for better message
              $containerStmt = $conn->prepare("SELECT ct.type FROM container c JOIN container_type ct ON c.container_type_id = ct.container_type_id WHERE c.container_id = ?");
              $containerStmt->execute([$container_id]);
              $container_type = $containerStmt->fetchColumn() ?: 'Container';
              
              $purchase_type_text = ($option === 'with-container') ? 'with container' : 'refill only';
              $recurrence_text = '';
              if ($recurrence_type !== 'none') {
                $recurrence_text = ' (Recurring: ' . ucfirst($recurrence_type) . ')';
              }
              
              $notification_msg = "New scheduled delivery order #{$order_id} received: {$container_type} ({$purchase_type_text}), Quantity: {$qty}, Scheduled for {$delivery_date_formatted} at {$delivery_time_formatted}{$recurrence_text}.";
              
              sendNotification($conn, $distributor_id, 'Didstributor', $notification_msg, 'New Scheduled Delivery');
            }
            
            // Clear any previous validation errors
            unset($_SESSION['schedule_validation_errors']);
            unset($_SESSION['schedule_form_data']);
            header('Location: shop_page.php?shop_id=' . $shop_id . '&scheduled=1');
            exit;
          }
        }
    } catch (PDOException $e) {
      $_SESSION['schedule_validation_errors'] = ["Database error: " . htmlspecialchars($e->getMessage())];
      header('Location: shop_page.php?shop_id=' . $shop_id . '&schedule_error=1');
      exit;
    }
  }
  $shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : 0;

  // Fetch shop info
  $stmt = $conn->prepare("SELECT * FROM shop WHERE shop_id = ?");
  $stmt->execute([$shop_id]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  // If shop not found, show error and exit
  if (!$station) {
      die('Shop not found.');
  }

  // Fetch containers for this shop, join container_type for type
  $stmt = $conn->prepare("SELECT c.*, ct.type FROM container c JOIN container_type ct ON c.container_type_id = ct.container_type_id WHERE c.shop_id = ? ORDER BY ct.type, c.container_id");
  $stmt->execute([$shop_id]);
  $products = $stmt->fetchAll();

  // Process all containers (show all, not just unique types)
  $uniqueProducts = [];
  foreach ($products as $product) {
    // Show container image as base64 (auto-detect PNG/JPEG/WEBP) if blob exists, else fallback
    if (!empty($product['container_image'])) {
      $imgData = $product['container_image'];
      // Try to detect image type (PNG/JPEG/WEBP)
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
      $product['container_image'] = 'data:image/' . $imgType . ';base64,' . base64_encode($imgData);
    } else {
      $product['container_image'] = '../images/watercontainer.png';
    }
    // Use container_id as key to show all containers, not just unique types
    $uniqueProducts[$product['container_id']] = $product;
  }

  // Fetch average rating for the shop (join orders for new schema)
  $stmt = $conn->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_ratings FROM shop_ratings WHERE shop_id = ?");
  $stmt->execute([$shop_id]);
  $ratingData = $stmt->fetch(PDO::FETCH_ASSOC);

  $avg_rating = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 2) : 0;
  $total_ratings = $ratingData['total_ratings'];

  // Fetch all addresses for the logged-in consumer
  $consumer_id = $_SESSION['consumer_id'] ?? 0;
  $addresses = [];
  if ($consumer_id) {
      $stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ?");
      $stmt->execute([$consumer_id]);
      $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($station['name'] ?? 'Shop') ?> - Tuy PureFlow</title>
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
          <?php
            // Show cart icon and pending count for logged in users
            $pending_count = 0;
            $consumer_profile = null;
            if (isset($_SESSION['consumer_id'])) {
                $cart_stmt = $conn->prepare("SELECT COUNT(*) FROM cart WHERE consumer_id = ?");
                $cart_stmt->execute([$_SESSION['consumer_id']]);
                $pending_count = $cart_stmt->fetchColumn();
                
                // Fetch consumer profile picture
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
            }
          ?>
          <?php if (isset($_SESSION['consumer_id'])): ?>
            <?php include 'notification_icon.php'; ?>
          <?php endif; ?>
          <a href="cart.php" class="relative p-2 rounded-full hover:bg-white hover:bg-opacity-20 transition-all">
            <i class="fas fa-shopping-bag text-white text-xl"></i>
            <?php if ($pending_count > 0): ?>
              <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full font-bold shadow-lg"><?= $pending_count ?></span>
            <?php endif; ?>
          </a>
          <?php if (isset($_SESSION['consumer_id'])): ?>
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

    <!-- Shop Banner -->
    <section class="bg-gradient-to-br from-cyan-50 via-blue-50 to-cyan-100 py-4 mb-6 shadow-md">
      <div class="container mx-auto px-4">
        <div class="bg-white rounded-xl shadow-lg p-4 md:p-5">
          <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
            <?php
              // Use the shop's logo_image (longblob) if available, otherwise fallback to default image
              $logoSrc = !empty($station['logo_image'])
                ? 'data:image/png;base64,' . base64_encode($station['logo_image'])
                : '../images/logo.png';
            ?>
            <div class="flex-shrink-0">
              <img src="<?= htmlspecialchars($logoSrc) ?>" class="w-20 h-20 md:w-24 md:h-24 object-cover rounded-xl border-2 border-white shadow-md" alt="Shop Logo">
            </div>
            <div class="flex-1 w-full">
              <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mb-1.5"><?= htmlspecialchars($station['name'] ?? 'Shop Not Found') ?></h1>
              <div class="flex items-center gap-2 text-gray-600 mb-2">
                <i class="fas fa-map-marker-alt text-cyan-600 text-xs"></i>
                <span class="text-xs md:text-sm"><?= htmlspecialchars($station['location'] ?? '') ?></span>
              </div>
              <div class="mb-2">
                <div class="flex items-center gap-2 mb-1.5">
                  <div class="flex items-center gap-0.5">
                    <?php
                      $fullStars = floor($avg_rating);
                      $hasHalfStar = ($avg_rating - $fullStars) >= 0.5;
                      for ($i = 0; $i < 5; $i++):
                    ?>
                      <?php if ($i < $fullStars): ?>
                        <svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                      <?php elseif ($i === $fullStars && $hasHalfStar): ?>
                        <svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545L10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0z"/></svg>
                      <?php else: ?>
                        <svg class="w-4 h-4 text-gray-300 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545L10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0z"/></svg>
                      <?php endif; ?>
                    <?php endfor; ?>
                  </div>
                  <span class="text-sm font-semibold text-gray-700">
                    <?php if ($total_ratings > 0): ?>
                      <?= $avg_rating ?>/5
                    <?php else: ?>
                      No ratings
                    <?php endif; ?>
                  </span>
                  <?php if ($total_ratings > 0): ?>
                    <span class="text-xs text-gray-500">(<?= $total_ratings ?> <?= $total_ratings === 1 ? 'rating' : 'ratings' ?>)</span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 text-gray-600">
                  <i class="fas fa-phone text-cyan-600 text-xs"></i>
                  <span class="text-xs"><span class="font-medium">Contact:</span> <?= htmlspecialchars($station['contact_number'] ?? 'N/A') ?></span>
                </div>
              </div>
              <div class="mb-2">
                <div class="flex items-center gap-2 text-gray-600">
                  <i class="fas fa-clock text-cyan-600 text-xs"></i>
                  <div>
                    <span class="text-xs text-gray-500">Operating Hours</span>
                    <p class="text-xs font-medium">
                      <?php
                        $open = isset($station['open_time']) && $station['open_time'] ? date('h:i A', strtotime($station['open_time'])) : null;
                        $close = isset($station['close_time']) && $station['close_time'] ? date('h:i A', strtotime($station['close_time'])) : null;
                        $hoursText = ($open && $close) ? ($open . ' - ' . $close) : '08:00 AM - 05:00 PM';
                        echo htmlspecialchars($hoursText);
                      ?>
                    </p>
                  </div>
                </div>
              </div>
              <div class="mt-2">
                <button type="button"
                  class="btn-primary text-white px-4 py-2 rounded-lg font-medium text-sm flex items-center gap-2"
                  onclick="toggleMessages();window.scrollTo({top:0,left:0,behavior:'smooth'});">
                  <i class="fas fa-comments text-xs"></i>
                  Message Seller
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['scheduled']) && $_GET['scheduled'] == '1'): ?>
      <div class="container mx-auto px-4 py-4">
        <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-lg shadow-md mb-4 fade-in">
          <div class="flex items-center gap-3">
            <i class="fas fa-check-circle text-green-600 text-xl"></i>
            <div class="flex-1">
              <h3 class="font-semibold text-green-800 mb-1">Order Scheduled Successfully!</h3>
              <p class="text-green-700 text-sm">Your scheduled delivery order has been created. You will receive a notification when it's ready for delivery.</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['schedule_error']) && $_GET['schedule_error'] == '1' && isset($_SESSION['schedule_validation_errors'])): ?>
      <div class="container mx-auto px-4 py-4">
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-md mb-4 fade-in">
          <div class="flex items-start gap-3">
            <i class="fas fa-exclamation-triangle text-red-600 text-xl mt-0.5"></i>
            <div class="flex-1">
              <h3 class="font-semibold text-red-800 mb-2">Validation Errors</h3>
              <ul class="list-disc list-inside space-y-1 text-red-700 text-sm">
                <?php foreach ($_SESSION['schedule_validation_errors'] as $error): ?>
                  <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
              </ul>
              <p class="text-red-600 text-xs mt-2 font-medium">Please correct the errors and try again.</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>
      </div>
      <?php 
        // Clear errors after displaying
        unset($_SESSION['schedule_validation_errors']);
      ?>
    <?php endif; ?>
    
    <!-- Success Modal for Scheduled Delivery -->
    <?php if (isset($_GET['scheduled']) && $_GET['scheduled'] == '1'): ?>
      <div id="scheduleSuccessModal" class="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl px-8 py-8 text-center max-w-md mx-4 fade-in">
          <div class="mb-4">
            <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
              <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
            </div>
          </div>
          <h2 class="text-2xl font-bold text-gray-800 mb-2">Order Successfully Scheduled!</h2>
          <p class="text-gray-600 mb-6">Your delivery has been scheduled and the distributor has been notified.</p>
          <div class="flex gap-3 justify-center">
            <button onclick="closeScheduleSuccessModal()" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white rounded-lg font-semibold transition-all shadow-md hover:shadow-lg">
              <i class="fas fa-check mr-2"></i>OK
            </button>
            <a href="my_purchases.php?status=Scheduled Delivery" class="px-6 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-semibold transition-all">
              <i class="fas fa-list mr-2"></i>View Orders
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Product List -->
    <main class="container mx-auto px-4 pb-12">
      <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl md:text-3xl font-bold text-gradient">Available Containers</h2>
        <span class="text-sm text-gray-500"><?= count($uniqueProducts) ?> <?= count($uniqueProducts) === 1 ? 'container' : 'containers' ?> available</span>
      </div>
      <?php if (count($addresses) === 0): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-5 mb-6 rounded-lg shadow-sm">
          <div class="flex items-start gap-3">
            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mt-0.5"></i>
            <div class="flex-1">
              <strong class="block mb-1">No delivery address found</strong>
              <p class="text-sm mb-3">Please add your delivery address to place an order.</p>
              <a href="address.php?redirect=shop_page.php?shop_id=<?= $shop_id ?>" class="inline-flex items-center gap-2 btn-primary text-sm px-4 py-2">
                <i class="fas fa-plus"></i>
                Add Address
              </a>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (count($uniqueProducts) > 0): ?>
          <?php foreach ($uniqueProducts as $product): ?>
          <div class="card hover:shadow-xl transition-all duration-300 group" style="padding: 1rem !important;">
            <form class="buy-now-form" method="POST" action="checkout.php">
              <input type="hidden" name="direct_purchase" value="1">
              <input type="hidden" name="shop_id" value="<?= $shop_id ?>">
              <input type="hidden" name="container_id" value="<?= $product['container_id'] ?>">
              <input type="hidden" name="name" value="<?= htmlspecialchars($product['type'] ?? 'Container') ?>">
              <input type="hidden" name="price_with_container" value="<?= isset($product['price_with_container']) && $product['price_with_container'] > 0 ? $product['price_with_container'] : 0 ?>">
              <input type="hidden" name="price_refill" value="<?= isset($product['price_refill']) && $product['price_refill'] > 0 ? $product['price_refill'] : 0 ?>">
              <input type="hidden" name="price" id="price<?= $product['container_id'] ?>" value="<?= isset($product['price_with_container']) && $product['price_with_container'] > 0 ? $product['price_with_container'] : 0 ?>">
              <?php $prodImg = !empty($product['container_image']) ? $product['container_image'] : '../images/watercontainer.png'; ?>
              <div class="mb-3 flex items-center justify-center h-40 overflow-hidden">
                <img src="<?= htmlspecialchars($prodImg) ?>" alt="<?= htmlspecialchars($product['type'] ?? 'Container') ?>" class="max-w-full max-h-full object-contain">
              </div>
              <h3 class="text-lg font-bold text-gray-800 mb-1.5"><?= htmlspecialchars($product['type'] ?? 'Container') ?></h3>
              <div class="flex items-baseline gap-2 mb-2">
                <span class="text-xl font-bold text-gradient" id="displayPrice<?= $product['container_id'] ?>">
                  <?php
                    $show_price = isset($product['price_with_container']) && $product['price_with_container'] > 0 ? number_format($product['price_with_container'], 2) : (isset($product['price_refill']) && $product['price_refill'] > 0 ? number_format($product['price_refill'], 2) : 'N/A');
                    echo '₱' . $show_price;
                  ?>
                </span>
                <?php if (isset($product['price_with_container']) && $product['price_with_container'] > 0 && isset($product['price_refill']) && $product['price_refill'] > 0): ?>
                  <span class="text-xs text-gray-500">(With Container)</span>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 mb-3">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                  <i class="fas fa-check-circle text-xs"></i>
                  Stock: <?= isset($product['stock_quantity']) ? htmlspecialchars($product['stock_quantity']) : (isset($product['stock']) ? htmlspecialchars($product['stock']) : 'N/A') ?>
                </span>
              </div>
              <div class="space-y-1.5 mb-3">
                <div class="flex items-center gap-2">
                  <label for="qty<?= $product['container_id'] ?>" class="text-xs text-gray-600 whitespace-nowrap w-16">
                    Quantity:
                  </label>
                  <?php
                    $max_stock = (isset($product['stock_quantity']) && $product['stock_quantity'] > 0) ? intval($product['stock_quantity']) : ((isset($product['stock']) && $product['stock'] > 0) ? intval($product['stock']) : 99);
                  ?>
                  <input id="qty<?= $product['container_id'] ?>" name="qty" type="number" min="1" max="<?= $max_stock ?>" value="1" class="text-center border border-gray-300 rounded px-1.5 py-0 text-xs focus:border-cyan-500 focus:outline-none transition-all" style="width: 5rem !important; max-width: 5rem !important; min-width: 5rem !important; height: 1.5rem !important;">
                </div>
                <div class="flex items-center gap-2">
                  <label for="option<?= $product['container_id'] ?>" class="text-xs text-gray-600 whitespace-nowrap w-16">
                    Option:
                  </label>
                  <select id="option<?= $product['container_id'] ?>" name="option" class="w-28 border border-gray-300 rounded px-1.5 py-0.5 text-xs focus:border-cyan-500 focus:outline-none transition-all" onchange="updatePrice(<?= $product['container_id'] ?>)">
                    <option value="with-container">With Container</option>
                    <option value="refill-only">Refill Only</option>
                  </select>
                </div>
              </div>
              <div class="flex flex-col gap-1.5 mt-3">
                <button type="button" onclick="addToCartAjax(this.form);" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-800 py-2 rounded-lg font-medium text-sm transition-all flex items-center justify-center gap-2">
                  <i class="fas fa-cart-plus text-xs"></i>
                  Add to Cart
                </button>
                <button type="submit" name="buy_now" class="w-full btn-primary text-white py-2 rounded-lg font-medium text-sm flex items-center justify-center gap-2" <?= count($addresses) === 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                  <i class="fas fa-bolt text-xs"></i>
                  Buy Now
                </button>
                <button type="button" onclick="openScheduleModal(<?= $product['container_id'] ?>);" class="w-full bg-green-500 hover:bg-green-600 text-white py-2 rounded-lg font-medium text-sm transition-all flex items-center justify-center gap-2 shadow-md hover:shadow-lg">
                  <i class="fas fa-calendar-alt text-xs"></i>
                  Schedule Delivery
                </button>
              </div>
            </form>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-span-3 text-center py-16">
            <div class="mx-auto w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
              <i class="fas fa-box-open text-4xl text-gray-400"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">No containers available</h3>
            <p class="text-gray-500">Please check back later or contact the shop for more information.</p>
          </div>
        <?php endif; ?>
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

    <?php if (isset($_SESSION['consumer_id'])): ?>
      <?php include 'consumer_messages_widget.php'; ?>
    <?php endif; ?>

    <!-- Schedule Delivery Modal -->
    <div id="scheduleModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
      <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md relative overflow-y-auto max-h-screen">
        <button onclick="closeScheduleModal();" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700 text-xl">&times;</button>
        <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
          <span class="inline-block w-6 h-6 bg-cyan-400 rounded mr-2 text-white text-center">&#10003;</span>
          Schedule for later delivery
        </h2>
        <form id="scheduleForm" method="POST" action="">
          <input type="hidden" name="container_id" id="scheduleContainerId" value="">
          <input type="hidden" name="shop_id" value="<?= htmlspecialchars($shop_id) ?>">
          <input type="hidden" name="address_id" id="scheduleAddressId" value="<?= count($addresses) > 0 ? $addresses[0]['address_id'] : '' ?>">
          <input type="hidden" name="qty" id="scheduleQty" value="1">
          <input type="hidden" name="option" id="scheduleOption" value="with-container">
          <label for="deliveryDate" class="block mb-2 font-semibold">Delivery Date *</label>
          <input type="date" id="deliveryDate" name="delivery_date" class="border rounded px-3 py-2 w-full mb-4" required placeholder="YYYY-MM-DD (e.g., 2024-12-25)" min="<?= date('Y-m-d') ?>">
          <div id="deliveryDateError" class="text-red-600 text-xs mb-2 hidden"></div>
          <label for="deliveryTime" class="block mb-2 font-semibold">Preferred Time (Optional)</label>
          <input type="time" id="deliveryTime" name="delivery_time" class="border rounded px-3 py-2 w-full mb-4" placeholder="HH:MM (e.g., 14:30)">
          <div id="deliveryTimeError" class="text-red-600 text-xs mb-2 hidden"></div>
          <label class="block mb-2 font-semibold">Repeat Order</label>
                  <div class="mb-2 font-semibold">Quantity: <span id="scheduleQtyDisplay">1</span></div>
                  <div class="mb-2 font-semibold">Option: <span id="scheduleOptionDisplay">With Container</span></div>
                  <div class="mb-2 font-semibold">Total Price: <span id="scheduleTotalDisplay">₱0.00</span></div>
          <div class="flex flex-wrap gap-2 mb-4">
            <button type="button" class="repeat-btn bg-cyan-400 text-white px-4 py-2 rounded-full" data-value="one-time" onclick="selectRepeat(this)">One-time</button>
            <button type="button" class="repeat-btn bg-gray-200 text-gray-700 px-4 py-2 rounded-full" data-value="weekly" onclick="selectRepeat(this)">Weekly</button>
            <button type="button" class="repeat-btn bg-gray-200 text-gray-700 px-4 py-2 rounded-full" data-value="bi-weekly" onclick="selectRepeat(this)">Bi-weekly</button>
            <button type="button" class="repeat-btn bg-gray-200 text-gray-700 px-4 py-2 rounded-full" data-value="monthly" onclick="selectRepeat(this)">Monthly</button>
          </div>
          <input type="hidden" name="repeat_order" id="repeatOrderInput" value="one-time">
          <div class="mt-4 mb-2 font-semibold">Delivery Address</div>
          <div id="addressSummary" class="mb-4">
            <textarea id="addressDisplay" class="w-full border rounded px-3 py-2 bg-gray-50" rows="3" readonly></textarea>
            <button type="button" onclick="showAddressSelect();" class="border border-cyan-400 text-cyan-400 px-4 py-2 rounded-full w-full mt-2">Change Address</button>
          </div>
          <div id="addressSelect" class="mb-4 hidden">
            <label for="addressDropdown" class="block mb-2 font-semibold">Select Address</label>
            <select id="addressDropdown" class="w-full border rounded px-3 py-2">
              <?php foreach ($addresses as $addr): ?>
                <option value="<?= $addr['address_id'] ?>">
                  <?= htmlspecialchars($addr['name']) ?>, <?= htmlspecialchars($addr['contact_number']) ?>, <?= htmlspecialchars($addr['street']) ?>, <?= htmlspecialchars($addr['barangay']) ?>, <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['region']) ?> <?= htmlspecialchars($addr['zip_code']) ?>
                </option>
              <?php endforeach; ?>
              <option value="add_new">+ Add New Address</option>
            </select>
            <div id="addNewAddressForm" class="mt-4 hidden">
              <h3 class="text-lg font-semibold mb-2">My Address <button type="button" onclick="closeAddNewAddressForm();" class="float-right text-xl">&times;</button></h3>
              <input type="text" id="newName" class="w-full border rounded px-3 py-2 mb-2" placeholder="Full Name">
              <input type="text" id="newContact" class="w-full border rounded px-3 py-2 mb-2" placeholder="Phone Number">
              <input type="text" id="newStreet" class="w-full border rounded px-3 py-2 mb-2" placeholder="Street">
              <input type="text" id="newBarangay" class="w-full border rounded px-3 py-2 mb-2" placeholder="Barangay">
              <input type="text" id="newCity" class="w-full border rounded px-3 py-2 mb-2" placeholder="City">
              <input type="text" id="newRegion" class="w-full border rounded px-3 py-2 mb-2" placeholder="Region">
              <input type="text" id="newZip" class="w-full border rounded px-3 py-2 mb-2" placeholder="Postal Code">
              <div class="mb-2 font-semibold">Set location on map</div>
              <div id="newAddressMap" style="height: 200px;" class="mb-2"></div>
              <button type="button" onclick="pickLocationOnMap();" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded mb-2">Pick Location on Map</button>
              <button type="button" onclick="saveNewAddress();" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded w-full">Save Address</button>
            </div>
            <div class="flex gap-4 mt-4">
              <button type="button" onclick="hideAddressSelect();" class="flex-1 border border-cyan-400 text-cyan-400 px-4 py-2 rounded-full">Cancel</button>
              <button type="button" onclick="saveSelectedAddress();" class="flex-1 bg-cyan-400 hover:bg-cyan-500 text-white px-4 py-2 rounded-full">Use This Address</button>
            </div>
          </div>
          <div id="formErrors" class="mb-4 hidden"></div>
          <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg mt-4" onclick="return validateScheduleForm(event);">Submit Scheduled Order</button>
        </form>
      </div>
    </div>

    <script>
      // Update address_id hidden field when address is selected
      function updateScheduleAddressId(val) {
        document.getElementById('scheduleAddressId').value = val;
      }
    function addToCartAjax(form) {
      // Ensure add_to_cart field is present
      if (!form.querySelector('[name="add_to_cart"]')) {
        var addToCartInput = document.createElement('input');
        addToCartInput.type = 'hidden';
        addToCartInput.name = 'add_to_cart';
        addToCartInput.value = '1';
        form.appendChild(addToCartInput);
      }
      var formData = new FormData(form);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'cart.php', true);
      xhr.onload = function() {
        if (xhr.status === 200) {
          showCartSuccess();
        } else {
          alert('Failed to add to cart. Please try again.');
        }
      };
      xhr.send(formData);
      return false;
    }

    function showCartSuccess() {
      var msg = document.createElement('div');
      msg.className = 'fixed top-4 right-4 bg-green-600 text-white px-6 py-3 rounded shadow-lg z-50';
      msg.textContent = 'Added to cart!';
      document.body.appendChild(msg);
      setTimeout(function() {
        msg.remove();
      }, 1500);
      // Update cart icon badge
      fetch('cart.php?action=cart_count')
        .then(response => response.json())
        .then(data => {
          var badge = document.querySelector('.cart-badge');
          if (badge) {
            badge.textContent = data.count > 0 ? data.count : '';
            badge.style.display = data.count > 0 ? 'flex' : 'none';
          }
        });
    }
      // Ensure toggleMessages is available for the Message Seller button
      function toggleMessages() {
        const widget = document.getElementById('messageWidget');
        widget.classList.toggle('translate-x-full');
      }
      // Shop Documents Modal logic
      function openDocumentsModal() {
        document.getElementById('documentsModal').classList.remove('hidden');
      }
      function closeDocumentsModal() {
        document.getElementById('documentsModal').classList.add('hidden');
      }
      // Update price based on option
      function updatePrice(containerId) {
        var option = document.getElementById('option' + containerId).value;
        var form = document.getElementById('option' + containerId).form;
        var priceNewVal = parseFloat(form.querySelector('input[name="price_with_container"]').value) || 0;
        var priceRefillVal = parseFloat(form.querySelector('input[name="price_refill"]').value) || 0;
        var priceInput = document.getElementById('price' + containerId);
        var displayPrice = document.getElementById('displayPrice' + containerId);
        if (option === 'with-container') {
          priceInput.value = priceNewVal;
          displayPrice.textContent = priceNewVal > 0 ? '₱' + priceNewVal.toFixed(2) : '₱N/A';
        } else {
          priceInput.value = priceRefillVal;
          displayPrice.textContent = priceRefillVal > 0 ? '₱' + priceRefillVal.toFixed(2) : '₱N/A';
        }
      }
      // Initialize all prices on page load
      document.addEventListener('DOMContentLoaded', function() {
        <?php foreach ($uniqueProducts as $product): ?>
          updatePrice(<?= $product['container_id'] ?>);
        <?php endforeach; ?>
        fillAddressSummary();
        // Close schedule modal when clicking outside container
        document.getElementById('scheduleModal').addEventListener('mousedown', function(e) {
          if (e.target === this) {
            closeScheduleModal();
          }
        });
      });
      // Schedule Delivery Modal logic
      function openScheduleModal(containerId) {
        document.getElementById('scheduleModal').classList.remove('hidden');
        document.getElementById('scheduleContainerId').value = containerId;
        // Get qty and option from outside modal
        var qtyInput = document.getElementById('qty' + containerId);
        var optionInput = document.getElementById('option' + containerId);
        var priceInput = document.getElementById('price' + containerId);
        var qty = qtyInput ? qtyInput.value : 1;
        var option = optionInput ? optionInput.value : 'with-container';
        var price = priceInput ? priceInput.value : 0;
        document.getElementById('scheduleQty').value = qty;
        document.getElementById('scheduleOption').value = option;
        document.getElementById('scheduleQtyDisplay').textContent = qty;
        document.getElementById('scheduleOptionDisplay').textContent = (option === 'with-container') ? 'With Container' : 'Refill Only';
        document.getElementById('scheduleTotalDisplay').textContent = '₱' + (parseFloat(price) * parseInt(qty)).toFixed(2);
        // Default repeat order selection
        selectRepeat(document.querySelector('.repeat-btn[data-value="one-time"]'));
        
        // Set minimum date to today
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('deliveryDate').setAttribute('min', today);
        
        // Clear previous errors
        clearScheduleFormErrors();
        
        // Initialize address display
        if (addresses.length > 0 && !currentAddress) {
          currentAddress = addresses[0];
        }
        fillAddressSummary();
        
        // Set initial address_id if we have a current address
        if (currentAddress && currentAddress.address_id) {
          document.getElementById('scheduleAddressId').value = currentAddress.address_id;
        }
        
        // Restore form data if there was an error
        <?php if (isset($_SESSION['schedule_form_data'])): ?>
          var formData = <?= json_encode($_SESSION['schedule_form_data']) ?>;
          if (formData.delivery_date) {
            document.getElementById('deliveryDate').value = formData.delivery_date;
          }
          if (formData.delivery_time) {
            document.getElementById('deliveryTime').value = formData.delivery_time;
          }
          if (formData.repeat_order) {
            var repeatBtn = document.querySelector('.repeat-btn[data-value="' + formData.repeat_order + '"]');
            if (repeatBtn) {
              selectRepeat(repeatBtn);
            }
          }
          if (formData.address_id) {
            document.getElementById('scheduleAddressId').value = formData.address_id;
            // Find and set current address
            var savedAddr = addresses.find(function(addr) {
              return String(addr.address_id) === String(formData.address_id);
            });
            if (savedAddr) {
              currentAddress = savedAddr;
              fillAddressSummary();
            }
          }
          <?php unset($_SESSION['schedule_form_data']); ?>
        <?php endif; ?>
      }
      function closeScheduleModal() {
        document.getElementById('scheduleModal').classList.add('hidden');
        document.getElementById('scheduleForm').reset();
        selectRepeat(document.querySelector('.repeat-btn[data-value="one-time"]'));
        clearScheduleFormErrors();
      }
      
      function clearScheduleFormErrors() {
        document.getElementById('deliveryDateError').classList.add('hidden');
        document.getElementById('deliveryTimeError').classList.add('hidden');
        document.getElementById('formErrors').classList.add('hidden');
        document.getElementById('formErrors').innerHTML = '';
      }
      
      function validateScheduleForm(event) {
        if (event) {
          event.preventDefault();
        }
        
        clearScheduleFormErrors();
        
        var errors = [];
        var deliveryDate = document.getElementById('deliveryDate').value;
        var deliveryTime = document.getElementById('deliveryTime').value;
        var addressId = document.getElementById('scheduleAddressId').value;
        var containerId = document.getElementById('scheduleContainerId').value;
        var qty = parseInt(document.getElementById('scheduleQty').value) || 0;
        
        // Validate delivery date
        if (!deliveryDate) {
          errors.push('Delivery date is required.');
          document.getElementById('deliveryDateError').textContent = 'Delivery date is required.';
          document.getElementById('deliveryDateError').classList.remove('hidden');
        } else {
          var selectedDate = new Date(deliveryDate);
          var today = new Date();
          today.setHours(0, 0, 0, 0);
          selectedDate.setHours(0, 0, 0, 0);
          
          if (selectedDate < today) {
            errors.push('Delivery date cannot be in the past.');
            document.getElementById('deliveryDateError').textContent = 'Delivery date cannot be in the past.';
            document.getElementById('deliveryDateError').classList.remove('hidden');
          }
          
          // Check if date is more than 1 year in the future
          var maxDate = new Date();
          maxDate.setFullYear(maxDate.getFullYear() + 1);
          if (selectedDate > maxDate) {
            errors.push('Delivery date cannot be more than 1 year in the future.');
            document.getElementById('deliveryDateError').textContent = 'Delivery date cannot be more than 1 year in the future.';
            document.getElementById('deliveryDateError').classList.remove('hidden');
          }
        }
        
        // Validate delivery time if provided
        if (deliveryTime) {
          // Validate time format
          if (!/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(deliveryTime)) {
            errors.push('Invalid time format. Please use 24-hour format (HH:MM).');
            document.getElementById('deliveryTimeError').textContent = 'Invalid time format.';
            document.getElementById('deliveryTimeError').classList.remove('hidden');
          } else if (deliveryDate === new Date().toISOString().split('T')[0]) {
            // If date is today, check if time is in the past
            var now = new Date();
            var selectedDateTime = new Date(deliveryDate + 'T' + deliveryTime);
            if (selectedDateTime < now) {
              errors.push('Delivery time cannot be in the past.');
              document.getElementById('deliveryTimeError').textContent = 'Delivery time cannot be in the past.';
              document.getElementById('deliveryTimeError').classList.remove('hidden');
            }
          }
        }
        
        // Validate address
        if (!addressId || addressId <= 0) {
          errors.push('Please select a delivery address.');
        }
        
        // Validate container
        if (!containerId || containerId <= 0) {
          errors.push('Invalid product selected.');
        }
        
        // Validate quantity
        if (qty <= 0 || qty > 100) {
          errors.push('Quantity must be between 1 and 100.');
        }
        
        // Display errors
        if (errors.length > 0) {
          var errorHtml = '<div class="bg-red-50 border-l-4 border-red-500 p-3 rounded">' +
            '<div class="flex items-start gap-2">' +
            '<i class="fas fa-exclamation-triangle text-red-600 mt-0.5"></i>' +
            '<div class="flex-1">' +
            '<h4 class="font-semibold text-red-800 mb-1">Please fix the following errors:</h4>' +
            '<ul class="list-disc list-inside text-red-700 text-sm space-y-1">';
          errors.forEach(function(error) {
            errorHtml += '<li>' + error + '</li>';
          });
          errorHtml += '</ul></div></div></div>';
          document.getElementById('formErrors').innerHTML = errorHtml;
          document.getElementById('formErrors').classList.remove('hidden');
          return false;
        }
        
        // If validation passes, submit the form
        document.getElementById('scheduleForm').submit();
        return true;
      }
      
      // Add real-time validation for date input
      document.addEventListener('DOMContentLoaded', function() {
        var deliveryDateInput = document.getElementById('deliveryDate');
        if (deliveryDateInput) {
          deliveryDateInput.addEventListener('change', function() {
            var dateError = document.getElementById('deliveryDateError');
            if (this.value) {
              var selectedDate = new Date(this.value);
              var today = new Date();
              today.setHours(0, 0, 0, 0);
              selectedDate.setHours(0, 0, 0, 0);
              
              if (selectedDate < today) {
                dateError.textContent = 'Delivery date cannot be in the past.';
                dateError.classList.remove('hidden');
              } else {
                dateError.classList.add('hidden');
              }
            }
          });
          
          var deliveryTimeInput = document.getElementById('deliveryTime');
          if (deliveryTimeInput) {
            deliveryTimeInput.addEventListener('change', function() {
              var timeError = document.getElementById('deliveryTimeError');
              if (this.value && deliveryDateInput.value === new Date().toISOString().split('T')[0]) {
                var now = new Date();
                var selectedDateTime = new Date(deliveryDateInput.value + 'T' + this.value);
                if (selectedDateTime < now) {
                  timeError.textContent = 'Delivery time cannot be in the past.';
                  timeError.classList.remove('hidden');
                } else {
                  timeError.classList.add('hidden');
                }
              } else {
                timeError.classList.add('hidden');
              }
            });
          }
        }
      });
      function selectRepeat(btn) {
        document.querySelectorAll('.repeat-btn').forEach(function(b) {
          b.classList.remove('bg-cyan-400', 'text-white');
          b.classList.add('bg-gray-200', 'text-gray-700');
        });
        btn.classList.remove('bg-gray-200', 'text-gray-700');
        btn.classList.add('bg-cyan-400', 'text-white');
        document.getElementById('repeatOrderInput').value = btn.getAttribute('data-value');
      }
      // Addresses from PHP
      let addresses = <?php echo json_encode($addresses); ?>;
      let currentAddress = addresses.length > 0 ? addresses[0] : {};

      function fillAddressSummary() {
        const ad = currentAddress;
        let addressText = '';
        if (ad && (ad.name || ad.contact_number || ad.street || ad.barangay || ad.city || ad.region || ad.zip_code)) {
          let parts = [];
          if (ad.name) parts.push(ad.name);
          if (ad.contact_number) parts.push(ad.contact_number);
          let addressParts = [];
          if (ad.street) addressParts.push(ad.street);
          if (ad.barangay) addressParts.push(ad.barangay);
          if (ad.city) addressParts.push(ad.city);
          if (ad.region) addressParts.push(ad.region);
          if (ad.zip_code) addressParts.push(ad.zip_code);
          if (addressParts.length > 0) {
            parts.push(addressParts.join(', '));
          }
          addressText = parts.join('\n');
        } else {
          addressText = 'No address found. Please add one.';
        }
        let addressDisplay = document.getElementById('addressDisplay');
        if (addressDisplay) {
          addressDisplay.value = addressText;
        }
      }
      function showAddressSelect() {
        document.getElementById('addressSummary').classList.add('hidden');
        document.getElementById('addressSelect').classList.remove('hidden');
        let dropdown = document.getElementById('addressDropdown');
        if (dropdown && currentAddress.address_id) {
          dropdown.value = currentAddress.address_id;
        }
        dropdown.onchange = function() {
          if (this.value === 'add_new') {
            document.getElementById('addNewAddressForm').classList.remove('hidden');
            initNewAddressMap();
          } else {
            document.getElementById('addNewAddressForm').classList.add('hidden');
            updateScheduleAddressId(this.value);
          }
        };
      }
      
      function hideAddressSelect() {
        document.getElementById('addressSelect').classList.add('hidden');
        document.getElementById('addressSummary').classList.remove('hidden');
        // Reset dropdown to current address
        let dropdown = document.getElementById('addressDropdown');
        if (dropdown && currentAddress.address_id) {
          dropdown.value = currentAddress.address_id;
        }
      }
      
      function saveSelectedAddress() {
        let dropdown = document.getElementById('addressDropdown');
        if (!dropdown) {
          alert('Address dropdown not found.');
          return;
        }
        
        let selectedValue = dropdown.value;
        
        // Check if "Add New Address" is selected
        if (selectedValue === 'add_new') {
          alert('Please fill in the new address form and click "Save Address" first.');
          return;
        }
        
        // Find the selected address from the addresses array
        // Use == for loose comparison to handle string/number conversion
        let selectedAddress = addresses.find(function(addr) {
          return String(addr.address_id) === String(selectedValue);
        });
        
        if (!selectedAddress) {
          // If not found, try to get from dropdown option text
          let selectedOption = dropdown.options[dropdown.selectedIndex];
          if (selectedOption && selectedOption.value !== 'add_new') {
            // Create a temporary address object from the option text
            // This is a fallback if the address isn't in the JavaScript array
            selectedAddress = {
              address_id: selectedValue,
              name: '',
              contact_number: '',
              street: '',
              barangay: '',
              city: '',
              region: '',
              zip_code: ''
            };
          } else {
            alert('Selected address not found. Please try again.');
            return;
          }
        }
        
        // Update current address
        currentAddress = selectedAddress;
        
        // Update the hidden address_id field
        updateScheduleAddressId(selectedValue);
        
        // Update the address display
        fillAddressSummary();
        
        // Hide address select and show summary
        hideAddressSelect();
      }
      function closeAddNewAddressForm() {
        document.getElementById('addNewAddressForm').classList.add('hidden');
        document.getElementById('addressDropdown').value = addresses.length > 0 ? addresses[0].address_id : '';
      }
      function saveNewAddress() {
        // Collect new address data
        let newAddr = {
          name: document.getElementById('newName').value.trim(),
          contact_number: document.getElementById('newContact').value.trim(),
          street: document.getElementById('newStreet').value.trim(),
          barangay: document.getElementById('newBarangay').value.trim(),
          city: document.getElementById('newCity').value.trim(),
          region: document.getElementById('newRegion').value.trim(),
          zip_code: document.getElementById('newZip').value.trim(),
          latitude: newAddressLat,
          longitude: newAddressLng
        };
        
        // Validate required fields
        if (!newAddr.name) {
          alert('Name is required.');
          return;
        }
        if (!newAddr.contact_number) {
          alert('Contact number is required.');
          return;
        }
        if (!newAddr.street || !newAddr.barangay || !newAddr.city || !newAddr.region || !newAddr.zip_code) {
          alert('Please fill in all address fields.');
          return;
        }
        
        // Send to backend via AJAX
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'shop_page.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          if (xhr.status === 200) {
            try {
              let response = JSON.parse(xhr.responseText);
              if (response.success) {
                // Add new address to addresses array
                newAddr.address_id = response.address_id;
                addresses.push(newAddr);
                
                // Update dropdown to include new address
                let dropdown = document.getElementById('addressDropdown');
                let option = document.createElement('option');
                option.value = newAddr.address_id;
                option.textContent = newAddr.name + ', ' + newAddr.contact_number + ', ' + newAddr.street + ', ' + newAddr.barangay + ', ' + newAddr.city + ', ' + newAddr.region + ' ' + newAddr.zip_code;
                dropdown.insertBefore(option, dropdown.lastElementChild); // Insert before "Add New Address" option
                
                // Select the new address
                dropdown.value = newAddr.address_id;
                currentAddress = newAddr;
                fillAddressSummary();
                updateScheduleAddressId(newAddr.address_id);
                
                // Hide the add new address form
                document.getElementById('addNewAddressForm').classList.add('hidden');
                document.getElementById('addressDropdown').value = newAddr.address_id;
                
                // Clear the form
                document.getElementById('newName').value = '';
                document.getElementById('newContact').value = '';
                document.getElementById('newStreet').value = '';
                document.getElementById('newBarangay').value = '';
                document.getElementById('newCity').value = '';
                document.getElementById('newRegion').value = '';
                document.getElementById('newZip').value = '';
                
                // Hide address select and show summary
                hideAddressSelect();
              } else {
                let errorMsg = 'Failed to save address.';
                if (response.error === 'not_logged_in') {
                  errorMsg = 'You must be logged in to save an address.';
                } else if (response.error === 'missing_name') {
                  errorMsg = 'Name is required.';
                } else if (response.error === 'missing_contact') {
                  errorMsg = 'Contact number is required.';
                } else if (response.message) {
                  errorMsg = 'Error: ' + response.message;
                }
                alert(errorMsg);
              }
            } catch (e) {
              console.error('Error parsing response:', e);
              alert('Failed to save address. Please try again.');
            }
          } else {
            alert('Failed to save address. Please try again.');
          }
        };
        xhr.onerror = function() {
          alert('Network error. Please try again.');
        };
        
        let params = 'ajax_add_address=1'
          + '&name=' + encodeURIComponent(newAddr.name)
          + '&contact_number=' + encodeURIComponent(newAddr.contact_number)
          + '&street=' + encodeURIComponent(newAddr.street)
          + '&barangay=' + encodeURIComponent(newAddr.barangay)
          + '&city=' + encodeURIComponent(newAddr.city)
          + '&region=' + encodeURIComponent(newAddr.region)
          + '&zip_code=' + encodeURIComponent(newAddr.zip_code)
          + '&latitude=' + encodeURIComponent(newAddr.latitude)
          + '&longitude=' + encodeURIComponent(newAddr.longitude);
        xhr.send(params);
      }
      let newAddressLat = 13.8602, newAddressLng = 120.7335;
      function initNewAddressMap() {
        if (window.L) {
          if (window.newAddressMapInstance) {
            window.newAddressMapInstance.remove();
          }
          window.newAddressMapInstance = L.map('newAddressMap').setView([newAddressLat, newAddressLng], 10);
          let marker = L.marker([newAddressLat, newAddressLng], {draggable:true}).addTo(window.newAddressMapInstance);
          marker.on('dragend', function(e) {
            let pos = marker.getLatLng();
            newAddressLat = pos.lat;
            newAddressLng = pos.lng;
          });
          window.newAddressMapInstance.on('click', function(e) {
            marker.setLatLng(e.latlng);
            newAddressLat = e.latlng.lat;
            newAddressLng = e.latlng.lng;
          });
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(window.newAddressMapInstance);
        }
      }
      function pickLocationOnMap() {
        // Focus map for picking location
        if (window.newAddressMapInstance) {
          window.newAddressMapInstance.invalidateSize();
        }
      }
      
      // Close success modal and remove URL parameter
      function closeScheduleSuccessModal() {
        const modal = document.getElementById('scheduleSuccessModal');
        if (modal) {
          modal.style.opacity = '0';
          modal.style.transition = 'opacity 0.3s ease-out';
          setTimeout(() => {
            modal.remove();
            // Remove 'scheduled' parameter from URL without page reload
            const url = new URL(window.location);
            url.searchParams.delete('scheduled');
            window.history.replaceState({}, '', url);
          }, 300);
        }
      }
      
      // Initialize modal behavior
      document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('scheduleSuccessModal');
        if (modal) {
          // Close on background click
          modal.addEventListener('click', function(e) {
            if (e.target === modal) {
              closeScheduleSuccessModal();
            }
          });
          
          // Close on Escape key
          document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal) {
              closeScheduleSuccessModal();
            }
          });
        }
      });
    </script>
  </body>
  </html>
